<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger Scope Expander（v0.2.0 Phase 3）: TriggerSelectionResolver が決定した Canonical Trigger を、
 * Event Timeline 側の Host Scope（固定Host / Host group / Navigator 等から受信した Host を SourceResolver::resolveTarget
 * で解決した対象Host集合）へ展開し、対象Host上に存在する「同じ論理Trigger」の実体 Trigger ID を求める独立コンポーネント。
 *
 * 処理:
 *   1. 対象Host群の Trigger（triggerid / templateid / flags）をまとめて取得する（Host数に比例した呼び出しをしない）。
 *   2. 取得した各 Trigger を、Selection と同じ TriggerSelectionResolver の論理で Canonical Trigger へ正規化する。
 *      親Trigger の取得はリクエスト内キャッシュ（TriggerInheritanceCache）を共有し、Selection の解決で取得済みの
 *      継承チェーンを再取得しない。
 *   3. Canonical が選択 Canonical のいずれかと一致する Trigger を expanded_triggerids とする（Trigger ID 単位で重複排除）。
 *
 * 一致判定に使うのは Trigger ID・templateid・flags だけで、Trigger名・式・Severity 等による類似Triggerへの投影はしない。
 * - Template 由来（type = template）: 対象Host上で同じ最上位 Template Trigger に行き着く Trigger。
 *   Template 未適用の Host には一致する Trigger がなく、単に該当なし（エラーにしない）。
 * - Host ローカル（type = exact）: canonical = 自分自身のため、その Trigger 自身が対象Host上にある（所有Host が Host Scope 内）
 *   ときだけ一致する。他Host上の同名Triggerへは展開されない。
 *   （Template 上の Trigger そのものが選択された場合も継承元がなく exact と判定されるが、canonical = その Template Trigger
 *   となるため、対象Host上でそれを継承する Trigger が同じ規則で一致する。）
 * - LLD 由来（type = discovered）: 本 Expander では扱わない。Phase 4 以降、選択された Discovered Trigger は
 *   TriggerPrototypeResolver / TriggerPrototypeScopeExpander が生成元 Trigger prototype 単位で展開する。対象Host上の
 *   Discovered Trigger も本 Expander の照合・判定不能の対象にしない（prototype 側の経路で判定する）。
 *
 * 判定不能（indeterminate）: 対象Host上の Trigger のうち、継承元 Template を参照できない（parent_unavailable）等で
 * Canonical を決定できず、選択 Canonical と一致するかを判断できないもの。一致を確定できた Trigger だけで結果を返し、
 * 判定不能があったことを件数で返す（呼び出し側は部分的な結果である旨を警告する。Phase 3 指示 Q1 = B）。
 * 判定不能な Trigger を名前で推測して加えたり、Trigger条件なしへ Fallback したりはしない。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。Trigger の取得は呼び出し側から関数として注入する。
 */
class TriggerScopeExpander {

	/**
	 * @param array    $resolution          TriggerSelectionResolver::resolve() の結果（filterError() が null のもの）
	 * @param string[] $target_hostids      Host Scope（SourceResolver::resolveTarget の hostids）
	 * @param callable $fetch_host_triggers function(string[] $hostids): ?array
	 *                                      対象Host上の閲覧可能な Trigger を triggerid => ['templateid' => string, 'flags' => int]
	 *                                      で返す。取得失敗時は null。
	 * @param TriggerInheritanceCache $cache Selection の解決と共有する継承情報キャッシュ
	 *
	 * @return array|null  取得失敗時は null
	 *   - triggerids:           expanded_triggerids（昇順・重複なし）
	 *   - indeterminate:        判定不能だった対象Host上の Trigger 数
	 *   - indeterminate_ids:    判定不能だった Trigger ID（デバッグ用。昇順）
	 *   - host_trigger_count:   対象Host上で取得した Trigger 数
	 *   - canonical_triggerids: 照合に使った選択 Canonical（昇順）
	 */
	public static function expand(array $resolution, array $target_hostids, callable $fetch_host_triggers,
			TriggerInheritanceCache $cache): ?array {
		$canonical = [];

		foreach ($resolution['entries'] as $entry) {
			if ($entry['status'] === TriggerSelectionResolver::STATUS_RESOLVED) {
				$canonical[$entry['canonical_triggerid']] = true;
			}
		}

		$result = [
			'triggerids' => [],
			'indeterminate' => 0,
			'indeterminate_ids' => [],
			'host_trigger_count' => 0,
			'canonical_triggerids' => self::sortedKeys($canonical)
		];

		if (!$canonical || !$target_hostids) {
			return $result;
		}

		$host_triggers = $fetch_host_triggers(array_values($target_hostids));

		if (!is_array($host_triggers)) {
			return null;
		}

		$result['host_trigger_count'] = count($host_triggers);

		if (!$host_triggers) {
			return $result;
		}

		// 対象Host上の Trigger 自体は取得済み。Resolver が再取得しないようキャッシュへ載せる。
		$cache->seed($host_triggers);

		$host_resolution = TriggerSelectionResolver::resolve(array_keys($host_triggers), [$cache, 'fetch']);

		if ($host_resolution === null) {
			return null;
		}

		// Phase 6 §15: 選択 Canonical がすべて対象Host上の Host ローカル Trigger（継承元なし）なら、継承元を持つ
		// Trigger（判定不能になるのはこれだけ）は選択 Canonical に行き着かない（Host 上の Trigger は継承されない）。
		// その場合は判定不能として数えない。Canonical の所有者を確認できない場合（対象Host外・Template 上の Trigger）は
		// 従来どおり判定不能として数える（過大計上は残るが fail-open にはしない）。
		$host_local_only = true;

		foreach ($canonical as $cid => $unused) {
			$cid = (string) $cid;

			if (!array_key_exists($cid, $host_triggers) || Ids::ref($host_triggers[$cid]['templateid'] ?? '0') !== '0') {
				$host_local_only = false;
				break;
			}
		}

		$matched = [];
		$indeterminate = [];

		foreach ($host_resolution['entries'] as $entry) {
			$id = $entry['source_triggerid'];

			if ($entry['status'] === TriggerSelectionResolver::STATUS_RESOLVED) {
				if (array_key_exists($entry['canonical_triggerid'], $canonical)) {
					$matched[$id] = true;
				}
			}
			elseif ($entry['type'] === TriggerSelectionResolver::TYPE_DISCOVERED) {
				// LLD 由来は prototype 側の経路（TriggerPrototypeScopeExpander）で照合する。
				continue;
			}
			elseif ($host_local_only) {
				continue;
			}
			elseif ($entry['reason'] !== TriggerSelectionResolver::REASON_NOT_FOUND) {
				// parent_unavailable / cycle / depth_exceeded: 選択 Canonical に行き着くかを判断できない。
				$indeterminate[$id] = true;
			}
		}

		$result['triggerids'] = self::sortedKeys($matched);
		$result['indeterminate'] = count($indeterminate);
		$result['indeterminate_ids'] = self::sortedKeys($indeterminate);

		return $result;
	}

	private static function sortedKeys(array $set): array {
		return Ids::keys($set);
	}
}
