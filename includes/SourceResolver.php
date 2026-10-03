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
	 * TriggerSelection（形式の正規化）と TriggerSelectionResolver（Canonical正規化）を通した結果だけを見る。
	 *
	 * - inactive（Trigger指定なし）: 絞り込みなし。対象Hostの期間内の全Eventを扱う（従来どおり）。
	 * - active: Resolver で実体が確認できた source Trigger（invalid 以外）の Event だけ。1件も残らない場合は
	 *           絞り込みなしへ戻さず、取得対象なし（selectors = []）とする（Fail-Openにしない）。
	 * - invalid（Trigger ID配列として解釈できない）: 取得対象なし。
	 * - Trigger filter error（1件でも継承元Templateを参照できず正規化できない Trigger を含む）: Selection 全体を
	 *   適用不能とし取得対象なし。解決できた Trigger や source Trigger だけでの部分表示はしない。
	 *   'error' に理由（TriggerSelectionResolver::REASON_PARENT_UNAVAILABLE）を返す。
	 *
	 * Host範囲（resolveTarget）には一切影響しない。選択されたTriggerのHostを対象Hostへ加えることはしない。
	 *
	 * @param array      $trigger_selection  TriggerSelection::resolve() の結果
	 * @param array|null $resolution         TriggerSelectionResolver::resolve() の結果（active のときのみ）
	 *
	 * @return array{selected: bool, selectors: array[], error: ?string}
	 *   selectors: event.get へ追加するオプション集合のリスト（結果はOR統合）。[[]] は絞り込みなし、[] は取得対象なし。
	 *   error: Trigger filter error の理由。通常は null（正常な0件とは区別する）。
	 */
	public static function resolveEventSelection(array $trigger_selection, ?array $resolution = null): array {
		switch ($trigger_selection['trigger_filter'] ?? TriggerSelection::STATE_INVALID) {
			case TriggerSelection::STATE_INACTIVE:
				return ['selected' => true, 'selectors' => [[]], 'error' => null];

			case TriggerSelection::STATE_ACTIVE:
				$error = $resolution !== null ? TriggerSelectionResolver::filterError($resolution) : null;

				if ($error !== null) {
					return ['selected' => true, 'selectors' => [], 'error' => $error];
				}

				$triggerids = $resolution !== null ? TriggerSelectionResolver::eventTriggerids($resolution) : [];
				$triggerids = array_values(array_unique(self::normalize($triggerids)));
				sort($triggerids, SORT_NUMERIC);

				return ['selected' => true,
					'selectors' => $triggerids ? [['objectids' => array_map('strval', $triggerids)]] : [],
					'error' => null
				];

			default:
				return ['selected' => true, 'selectors' => [], 'error' => null];
		}
	}

	/**
	 * @param int[] $hostids   直接指定されたHost ID
	 * @param int[] $groupids  指定されたHost group ID（サブグループを含めて展開する）
	 *
	 * @return int[]  重複のないHost ID（昇順）。閲覧権限のないHostは含まれない。
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
	 * 複数のHost ID配列を統合し、重複を除いた昇順の整数配列にする。
	 */
	public static function mergeUnique(array ...$lists): array {
		$merged = [];

		foreach ($lists as $list) {
			foreach ($list as $id) {
				$merged[(int) $id] = true;
			}
		}

		$ids = array_keys($merged);
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	private static function normalize(array $ids): array {
		return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
	}
}
