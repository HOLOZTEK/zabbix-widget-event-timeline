<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger Prototype Scope Expander（v0.2.0 Phase 4）: TriggerPrototypeResolver が決定した Canonical Trigger prototype を、
 * Event Timeline 側の Host Scope（SourceResolver::resolveTarget の対象Host集合。Navigator 側の Source Host は含めない）へ
 * 展開し、対象Host上で「同じ Canonical prototype に由来する prototype」から生成された Discovered Trigger の ID を求める。
 * 通常 Trigger の展開（TriggerScopeExpander）とは独立し、結果は呼び出し側で通常 Trigger の展開結果と和集合にする。
 *
 * 処理:
 *   1. 対象Host群の Trigger を、生成元 prototype（triggerDiscovery.parent_triggerid）付きでまとめて取得する
 *      （通常 Trigger の展開と同じ1回の取得結果を共有する。Host数に比例した呼び出しをしない）。
 *   2. 取得した Discovered Trigger（flags = 4）の生成元 prototype（＝対象Host上の prototype）を重複なく集め、
 *      TriggerPrototypeResolver で Canonical prototype へ正規化する（親 prototype の取得はリクエスト内キャッシュを共有）。
 *   3. Canonical が選択 Canonical prototype のいずれかと一致する prototype から生成された Discovered Trigger を
 *      expanded_triggerids とする（Trigger ID 単位で重複排除）。
 *
 * 一致判定に使うのは prototype ID・templateid・triggerDiscovery の親子関係だけで、LLD macro 値・Trigger名・
 * prototype名・式・Severity は使わない。Source の Discovered Trigger が持つ macro 値（例: {#IFNAME}=eth0）で対象Host上の
 * Trigger を絞り込むことはしない（同じ prototype から生成されたすべての Discovered Trigger が対象）。
 * - Template 未適用の Host・Discovery 結果が0件の Host は、一致する Discovered Trigger がなく単に該当なし（警告しない）。
 * - Host 上で直接定義された prototype は canonical = 自身のため、同じ Host 上のその prototype の Discovered Trigger だけが一致する。
 *
 * 判定不能（indeterminate）: 対象Host上の Discovered Trigger のうち、生成元 prototype を参照できない・継承元 prototype を
 * 辿れない等で Canonical prototype を決定できないもの。一致を確定できた分だけで結果を返し、件数を返す（呼び出し側は
 * Phase 3 と同じ部分結果の警告を表示する）。本 Expander は Canonical prototype の Selection があるときだけ呼ばれるため、
 * Selection がない場合に対象Host上の LLD Trigger を判定不能として数えることはない（Phase 4 指示 Q2）。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。取得処理は呼び出し側から関数として注入する。
 */
class TriggerPrototypeScopeExpander {

	/**
	 * @param array    $resolution          TriggerPrototypeResolver::resolve() の結果（filterError() が null のもの）
	 * @param int[]    $target_hostids      Host Scope
	 * @param callable $fetch_host_triggers function(int[] $hostids): ?array
	 *                                      対象Host上の閲覧可能な Trigger を
	 *                                      triggerid => ['templateid' => int, 'flags' => int, 'prototypeid' => int] で返す
	 *                                      （prototypeid は Discovered Trigger の生成元 prototype。無ければ 0）。失敗時は null。
	 * @param TriggerInheritanceCache $cache prototype 用の継承情報キャッシュ（通常 Trigger 用とは別インスタンス）
	 *
	 * @return array|null  取得失敗時は null
	 *   - triggerids:            LLD 側の expanded_triggerids（昇順・重複なし）
	 *   - indeterminate:         判定不能だった対象Host上の Discovered Trigger 数
	 *   - indeterminate_ids:     判定不能だった Trigger ID（デバッグ用。昇順）
	 *   - discovered_count:      対象Host上で取得した Discovered Trigger 数
	 *   - host_prototype_count:  それらの生成元 prototype 数（対象Host上の prototype）
	 *   - matched_prototypeids:  選択 Canonical と一致した対象Host上の prototype（昇順）
	 *   - canonical_prototypeids: 照合に使った選択 Canonical prototype（昇順）
	 */
	public static function expand(array $resolution, array $target_hostids, callable $fetch_host_triggers,
			TriggerInheritanceCache $cache): ?array {
		$canonical = [];

		foreach ($resolution['canonical'] as $row) {
			$canonical[(int) $row['canonical_prototypeid']] = true;
		}

		$result = [
			'triggerids' => [],
			'indeterminate' => 0,
			'indeterminate_ids' => [],
			'discovered_count' => 0,
			'host_prototype_count' => 0,
			'matched_prototypeids' => [],
			'canonical_prototypeids' => self::sortedKeys($canonical)
		];

		if (!$canonical || !$target_hostids) {
			return $result;
		}

		$host_triggers = $fetch_host_triggers(array_values($target_hostids));

		if (!is_array($host_triggers)) {
			return null;
		}

		$discovered = [];

		foreach ($host_triggers as $triggerid => $row) {
			if ((int) ($row['flags'] ?? 0) === TriggerSelectionResolver::FLAG_DISCOVERY_CREATED) {
				$discovered[(int) $triggerid] = (int) ($row['prototypeid'] ?? 0);
			}
		}

		$result['discovered_count'] = count($discovered);

		if (!$discovered) {
			return $result;
		}

		$host_prototypes = TriggerPrototypeResolver::resolvePrototypes(array_values($discovered), [$cache, 'fetch']);

		if ($host_prototypes === null) {
			return null;
		}

		$result['host_prototype_count'] = count($host_prototypes);

		$matched = [];
		$matched_prototypes = [];
		$indeterminate = [];

		foreach ($discovered as $triggerid => $prototypeid) {
			$entry = $prototypeid > 0 ? ($host_prototypes[$prototypeid] ?? null) : null;

			if ($entry === null || $entry['status'] !== TriggerPrototypeResolver::STATUS_RESOLVED) {
				// 生成元 prototype が不明・参照不能、継承元を辿れない、継承関係が不正: 選択 Canonical に行き着くかを判断できない。
				$indeterminate[$triggerid] = true;
			}
			elseif (array_key_exists($entry['canonical_prototypeid'], $canonical)) {
				$matched[$triggerid] = true;
				$matched_prototypes[$prototypeid] = true;
			}
		}

		$result['triggerids'] = self::sortedKeys($matched);
		$result['indeterminate'] = count($indeterminate);
		$result['indeterminate_ids'] = self::sortedKeys($indeterminate);
		$result['matched_prototypeids'] = self::sortedKeys($matched_prototypes);

		return $result;
	}

	private static function sortedKeys(array $set): array {
		$ids = array_map('intval', array_keys($set));
		sort($ids, SORT_NUMERIC);

		return $ids;
	}
}
