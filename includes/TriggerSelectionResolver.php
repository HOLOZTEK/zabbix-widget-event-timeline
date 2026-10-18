<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger Selection Resolver（v0.2.0 Phase 2）: 選択されたTrigger（source triggerids。Host上の実体）を、
 * Zabbixのテンプレート継承チェーン（trigger.templateid = 親Trigger）を最上位まで辿って、Hostに依存しない
 * 論理定義（Canonical Trigger）へ正規化する独立コンポーネント。
 *
 * 入力は source triggerids[]、出力は Canonical Trigger の集合（と、各sourceの解決結果）。
 * 固定Trigger指定でも Navigator（Metric Navigator等）からのWidget参照でも、同じこの Resolver を通す。
 * Phase 3 以降は、この結果を TriggerScopeExpander が対象Host上の Trigger へ展開して Event 抽出に使う
 * （source Trigger ID をそのまま Event 条件には使わない）。
 *
 * 同一性の判定に使うのは Trigger ID と templateid（継承の親子関係）、flags（通常/Discovery由来）だけである。
 * Trigger名・表示名・Severity・Event名・式文字列は一切参照しない（同名でも別テンプレートなら別Canonical）。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。Triggerの取得は呼び出し側から関数として注入する
 * （WidgetView では API::Trigger()->get、単体テストでは固定データ）。
 *
 * 解決結果（entries の1要素）:
 *   - source_triggerid:    入力されたTrigger ID
 *   - canonical_triggerid: 正規化後のTrigger ID（決定できない場合は null）
 *   - type:   TYPE_TEMPLATE   … テンプレート由来。canonical = 継承チェーン最上位のテンプレートTrigger
 *             TYPE_EXACT      … Host固有（継承なし）。canonical = source（他Hostへ名前で対応付けない）
 *             TYPE_DISCOVERED … LLD（Discovery）由来。本Phaseでは正規化しない（canonical = null）
 *             null            … source自体を取得できない等で種別不明
 *   - status: STATUS_RESOLVED   … canonical を決定できた
 *             STATUS_UNRESOLVED … source は存在するが canonical を決定できない（LLD由来、継承チェーンの途中が
 *                                 閲覧不可で辿れない＝parent_unavailable。後者は filterError() により
 *                                 Selection 全体を適用不能（fail-closed）とする）
 *             STATUS_INVALID    … source が存在しない／閲覧権限がない／継承チェーンが循環・深すぎる
 *   - inheritance_chain:   source から canonical 方向へ辿ったTrigger IDの列（先頭 = source）
 *   - reason:              unresolved / invalid の理由（resolved は null）
 */
class TriggerSelectionResolver {

	public const TYPE_TEMPLATE = 'template';
	public const TYPE_EXACT = 'exact';
	public const TYPE_DISCOVERED = 'discovered';

	public const STATUS_RESOLVED = 'resolved';
	public const STATUS_UNRESOLVED = 'unresolved';
	public const STATUS_INVALID = 'invalid';

	public const REASON_NOT_FOUND = 'not_found';
	public const REASON_PARENT_UNAVAILABLE = 'parent_unavailable';
	public const REASON_DISCOVERED = 'discovered';
	public const REASON_CYCLE = 'cycle';
	public const REASON_DEPTH_EXCEEDED = 'depth_exceeded';

	// Zabbix の Trigger.flags（ZBX_FLAG_DISCOVERY_CREATED）。
	public const FLAG_DISCOVERY_CREATED = 4;

	// 継承チェーンの最大段数（source を含まない親の数）。テンプレートのネストは実運用で数段程度のため十分大きく取り、
	// 循環検出（visited）とは別に、想定外のデータで無限ループしないための上限とする。
	public const MAX_DEPTH = 64;

	/** resolve() の呼び出し回数（統計・デバッグ用。呼び出し側がリクエストごとに 0 へ戻す）。 */
	public static int $calls = 0;

	/**
	 * @param string[] $source_triggerids  正規化済み（TriggerSelection::resolve の active）の source Trigger ID
	 * @param callable $fetch  function(string[] $triggerids): ?array
	 *                         閲覧可能なTriggerだけを triggerid => ['templateid' => string, 'flags' => int] で返す。
	 *                         取得失敗時は null（呼び出し全体を失敗として null を返す）。
	 *
	 * @return array{entries: array[], canonical: array[]}|null  取得失敗時は null
	 *   canonical: canonical_triggerid 単位に重複排除した Canonical Trigger の一覧（canonical_triggerid 昇順）。
	 *              各要素は ['canonical_triggerid' => string, 'type' => string, 'source_triggerids' => string[]]。
	 *   ID はすべて10進文字列（Ids。PHP_INT_MAX を超える ID も衝突させない）。
	 */
	public static function resolve(array $source_triggerids, callable $fetch): ?array {
		self::$calls++;
		$sources = Ids::unique($source_triggerids);

		if (!$sources) {
			return ['entries' => [], 'canonical' => []];
		}

		// 取得済みTrigger（閲覧可能なもの）と、取得を試みたが返らなかったID。
		$known = [];
		$missing = [];

		$pending = $sources;

		// 段ごとに未取得の親をまとめて取得する（API呼び出し回数 = 継承の段数 + 1 程度）。
		for ($level = 0; $pending && $level <= self::MAX_DEPTH + 1; $level++) {
			$rows = $fetch($pending);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($pending as $id) {
				if (array_key_exists($id, $rows)) {
					$known[$id] = [
						'templateid' => Ids::ref($rows[$id]['templateid'] ?? '0'),
						'flags' => (int) ($rows[$id]['flags'] ?? 0)
					];
				}
				else {
					$missing[$id] = true;
				}
			}

			$next = [];

			foreach ($pending as $id) {
				$parent = $known[$id]['templateid'] ?? '0';

				if ($parent !== '0' && !array_key_exists($parent, $known) && !array_key_exists($parent, $missing)) {
					$next[$parent] = true;
				}
			}

			$pending = Ids::keys($next);
		}

		$entries = [];
		$canonical = [];

		foreach ($sources as $source) {
			$entry = self::walk($source, $known);
			$entries[] = $entry;

			if ($entry['status'] === self::STATUS_RESOLVED) {
				$cid = $entry['canonical_triggerid'];

				if (!array_key_exists($cid, $canonical)) {
					$canonical[$cid] = ['canonical_triggerid' => $cid, 'type' => $entry['type'], 'source_triggerids' => []];
				}

				$canonical[$cid]['source_triggerids'][] = $source;
			}
		}

		Ids::ksort($canonical);

		return ['entries' => $entries, 'canonical' => array_values($canonical)];
	}

	/**
	 * 取得済みデータだけを使って、1つの source の継承チェーンを辿る（APIは呼ばない）。
	 */
	private static function walk(string $source, array $known): array {
		$entry = [
			'source_triggerid' => $source,
			'canonical_triggerid' => null,
			'type' => null,
			'status' => self::STATUS_INVALID,
			'inheritance_chain' => [$source],
			'reason' => null
		];

		if (!array_key_exists($source, $known)) {
			// 削除済み・存在しない・閲覧権限がない（APIからは区別できない）。
			$entry['reason'] = self::REASON_NOT_FOUND;

			return $entry;
		}

		if ($known[$source]['flags'] === self::FLAG_DISCOVERY_CREATED) {
			// LLD由来のTriggerは本Phaseでは正規化しない（Prototype単位の扱いは後続Phase）。名前での代替判定もしない。
			$entry['type'] = self::TYPE_DISCOVERED;
			$entry['status'] = self::STATUS_UNRESOLVED;
			$entry['reason'] = self::REASON_DISCOVERED;

			return $entry;
		}

		if ($known[$source]['templateid'] === '0') {
			// 継承元のないTrigger: Host固有（またはテンプレート上の定義そのもの）。canonical = source。
			$entry['canonical_triggerid'] = $source;
			$entry['type'] = self::TYPE_EXACT;
			$entry['status'] = self::STATUS_RESOLVED;

			return $entry;
		}

		$entry['type'] = self::TYPE_TEMPLATE;
		$visited = [$source => true];
		$current = $source;

		for ($depth = 0; ; $depth++) {
			$parent = $known[$current]['templateid'];

			if ($parent === '0') {
				// 最上位（継承元なし）に到達。
				$entry['canonical_triggerid'] = $current;
				$entry['status'] = self::STATUS_RESOLVED;

				return $entry;
			}

			if ($depth >= self::MAX_DEPTH) {
				$entry['reason'] = self::REASON_DEPTH_EXCEEDED;

				return $entry;
			}

			if (array_key_exists($parent, $visited)) {
				$entry['inheritance_chain'][] = $parent;
				$entry['reason'] = self::REASON_CYCLE;

				return $entry;
			}

			if (!array_key_exists($parent, $known)) {
				// 親が閲覧できない（テンプレートグループへの権限がない等）か、削除されている。
				// 途中までの最上位を canonical と見なすと、権限によって同じTriggerが別Canonicalになるため決定しない。
				$entry['inheritance_chain'][] = $parent;
				$entry['status'] = self::STATUS_UNRESOLVED;
				$entry['reason'] = self::REASON_PARENT_UNAVAILABLE;

				return $entry;
			}

			$entry['inheritance_chain'][] = $parent;
			$visited[$parent] = true;
			$current = $parent;
		}
	}

	/**
	 * Trigger Selection 全体を適用できない状態（Trigger filter error）か。
	 *
	 * 1件でも parent_unavailable（Trigger自体は参照できるが継承元Templateを参照できず canonical を確定できない）が
	 * 含まれる場合、Selection 全体を展開不能とする（Phase 2 追加修正 §4/§5）。解決できた Trigger だけ、あるいは
	 * source Trigger だけで代替して部分的な結果を表示しない（利用者は全Triggerを条件として指定しているため）。
	 * Template参照権限は Template 由来 Trigger の正規化の前提条件であり、権限を迂回して取得することはしない。
	 *
	 * @return string|null  エラーの理由（REASON_PARENT_UNAVAILABLE）。エラーでなければ null。
	 */
	public static function filterError(array $resolution): ?string {
		foreach ($resolution['entries'] as $entry) {
			if ($entry['reason'] === self::REASON_PARENT_UNAVAILABLE) {
				return self::REASON_PARENT_UNAVAILABLE;
			}
		}

		return null;
	}
}
