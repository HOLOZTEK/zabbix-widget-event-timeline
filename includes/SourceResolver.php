<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;

/**
 * Source resolution: 設定（Fixed / Widget）から Monitoring target（対象Host ID集合）と
 * Event Selection（Eventの絞り込み条件）を決定する。Filter・集計はここでは行わない。
 *
 * 後続処理（Event取得）が受け取るのは「resolved host IDs」と「Event Selection」だけ。
 * Widget Source で Selection がまだ届いていない・空のときは「未選択」とし、絶対に全Host・全Eventへ広げない。
 */
class SourceResolver {

	/**
	 * Monitoring target の解決。固定指定でもWidget参照でも、解決済みの hostids / groupids だけを見る（Source種別は持ち込まない）。
	 *
	 * @return array{selected: bool, hostids: int[]}
	 *   selected: Selection（Fixed設定 / Widget Selection）が存在するか。false のとき hostids は空。
	 */
	public static function resolveTarget(array $fields): array {
		$hostids = self::normalize((array) ($fields['hostids'] ?? []));
		$groupids = self::normalize((array) ($fields['groupids'] ?? []));

		if (!$hostids && !$groupids) {
			return ['selected' => false, 'hostids' => []];
		}

		return ['selected' => true, 'hostids' => self::resolve($hostids, $groupids)];
	}

	/**
	 * Event Selection の解決。イベント＝Trigger として、固定指定でも Navigator 参照でも、
	 * TriggerSelection（形式の正規化）→ TriggerSelectionResolver（Canonical正規化）→ TriggerScopeExpander
	 * （Host Scope への展開）を通した結果だけを見る（v0.2.0 Phase 3。Source Trigger ID をそのまま Event 条件に使わない）。
	 *
	 * - inactive（Trigger指定なし）: 絞り込みなし。対象Hostの期間内の全Eventを扱う（従来どおり）。
	 * - active: 対象Host上へ展開した expanded_triggerids の Event だけ。1件も一致しない場合は絞り込みなしへ戻さず、
	 *           取得対象なし（selectors = []）とする（正常な0件。Fail-Openにしない）。
	 *           展開時に判定不能な Trigger があった場合は 'partial' = true（一致を確定できた分だけで取得し、警告する）。
	 * - invalid（Trigger ID配列として解釈できない）: 取得対象なし。
	 * - Trigger filter error（選択Triggerに1件でも継承元Templateを参照できず正規化できないものを含む）: Selection 全体を
	 *   適用不能とし取得対象なし。'error' に理由（TriggerSelectionResolver::REASON_PARENT_UNAVAILABLE）を返す。
	 *   v0.2.0 Phase 4: 選択された LLD（Discovery）由来の Trigger の生成元 prototype／Canonical prototype を決定できない場合も
	 *   同じく適用不能とする（'error' は TriggerPrototypeResolver の理由）。
	 *
	 * Host範囲（resolveTarget）には一切影響しない。選択されたTriggerのHostを対象Hostへ加えることはしない。
	 *
	 * @param array      $trigger_selection  TriggerSelection::resolve() の結果
	 * @param array|null $resolution         TriggerSelectionResolver::resolve() の結果（active のときのみ）
	 * @param array|null $expansion          展開結果（active かつ error なしのとき。通常 Trigger と LLD の和集合。
	 *                                       triggerids / indeterminate を参照する）
	 * @param array|null $prototype_resolution TriggerPrototypeResolver::resolve() の結果（LLD 由来の選択があるときのみ）
	 *
	 * @return array{selected: bool, selectors: array[], error: ?string, partial: bool}
	 *   selectors: event.get へ追加するオプション集合のリスト（結果はOR統合）。[[]] は絞り込みなし、[] は取得対象なし。
	 *   error: Trigger filter error の理由。通常は null（正常な0件とは区別する）。
	 *   partial: 対象Host上に判定不能な Trigger があり、結果が不完全な可能性がある。
	 */
	public static function resolveEventSelection(array $trigger_selection, ?array $resolution = null,
			?array $expansion = null, ?array $prototype_resolution = null): array {
		switch ($trigger_selection['trigger_filter'] ?? TriggerSelection::STATE_INVALID) {
			case TriggerSelection::STATE_INACTIVE:
				return ['selected' => true, 'selectors' => [[]], 'error' => null, 'partial' => false];

			case TriggerSelection::STATE_ACTIVE:
				$error = $resolution !== null ? TriggerSelectionResolver::filterError($resolution) : null;

				if ($error === null && $prototype_resolution !== null) {
					$error = TriggerPrototypeResolver::filterError($prototype_resolution);
				}

				if ($error !== null) {
					return ['selected' => true, 'selectors' => [], 'error' => $error, 'partial' => false];
				}

				$triggerids = self::normalize($expansion['triggerids'] ?? []);

				return ['selected' => true,
					'selectors' => $triggerids ? [['objectids' => $triggerids]] : [],
					'error' => null,
					'partial' => ($expansion['indeterminate'] ?? 0) > 0
				];

			default:
				return ['selected' => true, 'selectors' => [], 'error' => null, 'partial' => false];
		}
	}

	/**
	 * @param string[] $hostids   直接指定されたHost ID
	 * @param string[] $groupids  指定されたHost group ID（サブグループを含めて展開する）
	 *
	 * @return string[]  重複のないHost ID（10進文字列・昇順）。閲覧権限のないHostは含まれない。
	 */
	public static function resolve(array $hostids, array $groupids): array {
		$from_hosts = [];
		$from_groups = [];

		$hostids = self::normalize($hostids);
		$groupids = self::normalize($groupids);

		if ($hostids) {
			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'hostids' => $hostids,
				'preservekeys' => true
			]);

			if (is_array($hosts)) {
				$from_hosts = array_keys($hosts);
			}
		}

		if ($groupids) {
			// サブグループ（Parent/Child）に所属するHostも含める。
			$all_groupids = getSubGroups($groupids);

			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $all_groupids,
				'preservekeys' => true
			]);

			if (is_array($hosts)) {
				$from_groups = array_keys($hosts);
			}
		}

		return self::mergeUnique($from_hosts, $from_groups);
	}

	/**
	 * 複数のHost ID配列を統合し、重複を除いた昇順の ID 配列（10進文字列。issue #34）にする。
	 */
	public static function mergeUnique(array ...$lists): array {
		return Ids::unique(array_merge(...$lists));
	}

	/**
	 * @return string[]  正の ID だけ（10進文字列・重複なし・昇順）
	 */
	private static function normalize(array $ids): array {
		return Ids::unique($ids);
	}
}
