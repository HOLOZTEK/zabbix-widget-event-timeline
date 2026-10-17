<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger Prototype Resolver（v0.2.0 Phase 4）: LLD（Discovery）由来の Trigger を、生成元の Trigger prototype と、
 * その Template 継承チェーン（triggerprototype.templateid = 親 prototype）を最上位まで辿った Canonical Trigger prototype へ
 * 正規化する独立コンポーネント。通常 Trigger 用の TriggerSelectionResolver とは処理経路を分ける（LLD の分岐を通常の
 * Resolver へ混在させない）。
 *
 *   Discovered Trigger（flags = 4）
 *     → 生成元 Trigger prototype（trigger.get の triggerDiscovery.parent_triggerid。Host 上の prototype）
 *     → Template 継承元の prototype …
 *     → Canonical Trigger prototype（継承元のない最上位の prototype）
 *
 * 同一性の判定に使うのは prototype ID と templateid（継承の親子関係）だけである。Trigger名・prototype名・Event名・
 * Severity・式文字列・LLD macro 値は一切参照しない（同名でも別 Template の prototype なら別 Canonical）。
 * Host 上で直接定義された prototype（継承元なし）は canonical = その prototype 自身となり、他Hostの prototype とは一致しない。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。prototype の取得は呼び出し側から関数として注入する
 * （WidgetView では API::TriggerPrototype()->get を TriggerInheritanceCache 越しに、単体テストでは固定データ）。
 *
 * 解決結果（entries の1要素）:
 *   - source_triggerid:      入力された Discovered Trigger ID
 *   - source_prototypeid:    生成元の Trigger prototype ID（構造情報から取得できない場合は null）
 *   - canonical_prototypeid: 正規化後の Trigger prototype ID（決定できない場合は null）
 *   - type:   TYPE_TEMPLATE … Template 由来。canonical = 継承チェーン最上位の prototype
 *             TYPE_EXACT    … 継承元なし（Host 上で直接定義、または Template 上の定義そのもの）。canonical = source prototype
 *             null          … 決定できない
 *   - status: STATUS_RESOLVED / STATUS_UNRESOLVED（prototype を参照できない・継承元を辿れない）/
 *             STATUS_INVALID（継承関係が循環・深すぎる）
 *   - inheritance_chain:     source prototype から canonical 方向へ辿った prototype ID の列（先頭 = source prototype）
 *   - reason:                unresolved / invalid の理由（resolved は null）
 */
class TriggerPrototypeResolver {

	public const TYPE_TEMPLATE = 'template';
	public const TYPE_EXACT = 'exact';

	public const STATUS_RESOLVED = 'resolved';
	public const STATUS_UNRESOLVED = 'unresolved';
	public const STATUS_INVALID = 'invalid';

	// 生成元 prototype を構造情報から特定できない（triggerDiscovery が無い、または prototype 自体を参照できない）。
	public const REASON_PROTOTYPE_UNAVAILABLE = 'prototype_unavailable';
	// prototype は参照できるが、継承元の prototype（Template 側）を参照できない。
	public const REASON_PARENT_UNAVAILABLE = 'parent_unavailable';
	public const REASON_CYCLE = 'cycle';
	public const REASON_DEPTH_EXCEEDED = 'depth_exceeded';

	// 継承チェーンの最大段数。TriggerSelectionResolver（Phase 2）と同じ上限に統一する。
	public const MAX_DEPTH = TriggerSelectionResolver::MAX_DEPTH;

	/** resolve() / resolvePrototypes() の呼び出し回数（統計・デバッグ用。呼び出し側がリクエストごとに 0 へ戻す）。 */
	public static int $calls = 0;

	/**
	 * Source の Discovered Trigger を Canonical Trigger prototype へ正規化する。
	 *
	 * @param array<int, int> $sources  source Discovered Trigger ID => 生成元 Trigger prototype ID（不明なら 0）
	 * @param callable        $fetch    function(int[] $prototypeids): ?array
	 *                                  閲覧可能な prototype だけを prototypeid => ['templateid' => int, ...] で返す。
	 *                                  取得失敗時は null（呼び出し全体を失敗として null を返す）。
	 *
	 * @return array{entries: array[], canonical: array[]}|null  取得失敗時は null
	 *   canonical: canonical_prototypeid 単位に重複排除した一覧（昇順）。
	 *              各要素は ['canonical_prototypeid' => int, 'type' => string, 'source_triggerids' => int[]]。
	 */
	public static function resolve(array $sources, callable $fetch): ?array {
		$normalized = [];

		foreach ($sources as $triggerid => $prototypeid) {
			$normalized[(int) $triggerid] = (int) $prototypeid;
		}

		ksort($normalized, SORT_NUMERIC);

		$prototypes = self::resolvePrototypes(array_values(array_filter($normalized)), $fetch);

		if ($prototypes === null) {
			return null;
		}

		$entries = [];
		$canonical = [];

		foreach ($normalized as $triggerid => $prototypeid) {
			$entry = ['source_triggerid' => $triggerid];

			if ($prototypeid === 0) {
				$entry += [
					'source_prototypeid' => null,
					'canonical_prototypeid' => null,
					'type' => null,
					'status' => self::STATUS_UNRESOLVED,
					'inheritance_chain' => [],
					'reason' => self::REASON_PROTOTYPE_UNAVAILABLE
				];
			}
			else {
				$entry += ['source_prototypeid' => $prototypeid] + $prototypes[$prototypeid];
			}

			$entries[] = $entry;

			if ($entry['status'] === self::STATUS_RESOLVED) {
				$cid = $entry['canonical_prototypeid'];

				if (!array_key_exists($cid, $canonical)) {
					$canonical[$cid] = ['canonical_prototypeid' => $cid, 'type' => $entry['type'],
						'source_triggerids' => []
					];
				}

				$canonical[$cid]['source_triggerids'][] = $triggerid;
			}
		}

		ksort($canonical, SORT_NUMERIC);

		return ['entries' => $entries, 'canonical' => array_values($canonical)];
	}

	/**
	 * Trigger prototype の集合を Canonical Trigger prototype へ正規化する（対象Host上の prototype の照合・
	 * プロトタイプグループ表示で使う）。段ごとに未取得の親をまとめて取得する（API 呼び出し回数 = 継承の段数 + 1 程度）。
	 *
	 * @param int[]    $prototypeids
	 * @param callable $fetch  resolve() と同じ
	 *
	 * @return array<int, array>|null  prototypeid => ['canonical_prototypeid', 'type', 'status', 'inheritance_chain',
	 *                                 'reason']。取得失敗時は null
	 */
	public static function resolvePrototypes(array $prototypeids, callable $fetch): ?array {
		self::$calls++;
		$ids = [];

		foreach ($prototypeids as $id) {
			if ((int) $id > 0) {
				$ids[(int) $id] = true;
			}
		}

		$ids = array_keys($ids);
		sort($ids, SORT_NUMERIC);

		$known = [];
		$missing = [];
		$pending = $ids;

		for ($level = 0; $pending && $level <= self::MAX_DEPTH + 1; $level++) {
			$rows = $fetch($pending);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($pending as $id) {
				if (array_key_exists($id, $rows)) {
					$known[$id] = (int) ($rows[$id]['templateid'] ?? 0);
				}
				else {
					$missing[$id] = true;
				}
			}

			$next = [];

			foreach ($pending as $id) {
				$parent = $known[$id] ?? 0;

				if ($parent > 0 && !array_key_exists($parent, $known) && !array_key_exists($parent, $missing)) {
					$next[$parent] = true;
				}
			}

			$pending = array_keys($next);
			sort($pending, SORT_NUMERIC);
		}

		$result = [];

		foreach ($ids as $id) {
			$result[$id] = self::walk($id, $known);
		}

		return $result;
	}

	/**
	 * 取得済みデータだけを使って、1つの prototype の継承チェーンを辿る（APIは呼ばない）。
	 *
	 * @param array<int, int> $known  prototypeid => templateid（閲覧可能なものだけ）
	 */
	private static function walk(int $prototypeid, array $known): array {
		$entry = [
			'canonical_prototypeid' => null,
			'type' => null,
			'status' => self::STATUS_UNRESOLVED,
			'inheritance_chain' => [$prototypeid],
			'reason' => null
		];

		if (!array_key_exists($prototypeid, $known)) {
			// 削除済み・閲覧権限がない（APIからは区別できない）。名前等で代替の prototype を推測しない。
			$entry['reason'] = self::REASON_PROTOTYPE_UNAVAILABLE;

			return $entry;
		}

		$entry['type'] = $known[$prototypeid] === 0 ? self::TYPE_EXACT : self::TYPE_TEMPLATE;
		$visited = [$prototypeid => true];
		$current = $prototypeid;

		for ($depth = 0; ; $depth++) {
			$parent = $known[$current];

			if ($parent === 0) {
				$entry['canonical_prototypeid'] = $current;
				$entry['status'] = self::STATUS_RESOLVED;

				return $entry;
			}

			if ($depth >= self::MAX_DEPTH) {
				$entry['type'] = null;
				$entry['status'] = self::STATUS_INVALID;
				$entry['reason'] = self::REASON_DEPTH_EXCEEDED;

				return $entry;
			}

			if (array_key_exists($parent, $visited)) {
				$entry['inheritance_chain'][] = $parent;
				$entry['type'] = null;
				$entry['status'] = self::STATUS_INVALID;
				$entry['reason'] = self::REASON_CYCLE;

				return $entry;
			}

			if (!array_key_exists($parent, $known)) {
				// 継承元の prototype（Template 側）を参照できない。途中までの最上位を canonical と見なすと、
				// 権限によって同じ prototype が別 Canonical になるため決定しない。
				$entry['inheritance_chain'][] = $parent;
				$entry['reason'] = self::REASON_PARENT_UNAVAILABLE;

				return $entry;
			}

			$entry['inheritance_chain'][] = $parent;
			$visited[$parent] = true;
			$current = $parent;
		}
	}

	/**
	 * Source 側の fail-closed（Phase 4 §19）: 選択された Discovered Trigger のうち1件でも Canonical prototype を
	 * 決定できないもの（生成元 prototype を参照できない・継承元を辿れない・継承関係が不正）がある場合、Selection 全体を
	 * 適用不能とする。Source Trigger 自身だけで代替したり、名前で prototype を推測したり、Trigger 条件を外したりしない。
	 *
	 * @return string|null  エラーの理由。エラーでなければ null。
	 */
	public static function filterError(array $resolution): ?string {
		foreach ($resolution['entries'] as $entry) {
			if ($entry['status'] !== self::STATUS_RESOLVED) {
				return $entry['reason'];
			}
		}

		return null;
	}
}
