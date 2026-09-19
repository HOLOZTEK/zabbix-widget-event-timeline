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

	// Event名の検索キーワードの最大長。
	private const EVENT_NAME_MAX_LENGTH = 255;

	/**
	 * Monitoring target の解決。
	 *
	 * @return array{selected: bool, hostids: int[]}
	 *   selected: Selection（Fixed設定 / Widget Selection）が存在するか。false のとき hostids は空。
	 */
	public static function resolveTarget(array $fields): array {
		if ((int) ($fields['host_source'] ?? WidgetForm::SOURCE_FIXED) === WidgetForm::SOURCE_WIDGET) {
			$hostids = self::normalize((array) ($fields['tn_hostids'] ?? []));
			$groupids = self::normalize((array) ($fields['tn_groupids'] ?? []));
		}
		else {
			$hostids = self::normalize((array) ($fields['hostids'] ?? []));
			$groupids = self::normalize((array) ($fields['groupids'] ?? []));
		}

		if (!$hostids && !$groupids) {
			return ['selected' => false, 'hostids' => []];
		}

		return ['selected' => true, 'hostids' => self::resolve($hostids, $groupids)];
	}

	/**
	 * Event Selection の解決。
	 * - Fixed: Event名（1行1件・部分一致・複数はOR）。未指定なら絞り込みなし（全Event）。
	 * - Widget: Event Navigator が送る Event ID / Trigger ID（ORで統合）。空なら「未選択」。
	 *
	 * @return array{selected: bool, selectors: array[]}
	 *   selectors: event.get へ追加するオプション集合のリスト（結果はOR統合）。[[]] は絞り込みなし。
	 */
	public static function resolveEventSelection(array $fields): array {
		if ((int) ($fields['event_source'] ?? WidgetForm::SOURCE_FIXED) === WidgetForm::SOURCE_WIDGET) {
			$eventids = self::normalize((array) ($fields['en_eventids'] ?? []));
			$triggerids = self::normalize((array) ($fields['en_triggerids'] ?? []));
			$selectors = [];

			if ($eventids) {
				$selectors[] = ['eventids' => array_map('strval', $eventids)];
			}

			if ($triggerids) {
				$selectors[] = ['objectids' => array_map('strval', $triggerids)];
			}

			return ['selected' => (bool) $selectors, 'selectors' => $selectors];
		}

		$names = self::parseEventNames((string) ($fields['event_names'] ?? ''));

		return [
			'selected' => true,
			'selectors' => $names ? [['search' => ['name' => $names], 'searchByAny' => true]] : [[]]
		];
	}

	/**
	 * 1行1件のEvent名を配列にする（空行除去・重複排除・件数と長さの上限あり）。
	 *
	 * @return string[]
	 */
	public static function parseEventNames(string $text): array {
		$names = [];

		foreach (preg_split('/\R/u', $text) ?: [] as $line) {
			$line = trim($line);

			if ($line !== '') {
				$names[mb_substr($line, 0, self::EVENT_NAME_MAX_LENGTH)] = true;
			}
		}

		return array_slice(array_map('strval', array_keys($names)), 0, WidgetForm::EVENT_NAMES_MAX);
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
