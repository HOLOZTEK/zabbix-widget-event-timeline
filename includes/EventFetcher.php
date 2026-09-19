<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;

/**
 * Event retrieval: 対象Host集合と表示期間から Trigger 由来の Problem Event を取得する。
 *
 * - Problem Event のみ（Recovery Event は数えない）。1 eventid = 1 Event。
 * - 集計に必要な eventid / clock / severity だけを取得する（Recovery・Acknowledge 情報は取得しない）。
 * - 新しいEventから eventid 降順でページング取得し、上限に達したら打ち切る。打ち切りは呼び出し側へ
 *   truncated として明示する（無言で欠落させない）。
 */
class EventFetcher {

	private const PAGE_SIZE = 5000;

	/**
	 * @param int[] $hostids    resolved host IDs（Source resolution の結果）
	 * @param int   $time_from  期間開始（UNIX時刻、含む）
	 * @param int   $time_till  期間終了（UNIX時刻、含む）
	 * @param int[] $severities API側でも絞り込むSeverity（全Severityなら絞り込まない）
	 * @param int   $max_events 取得件数の上限
	 *
	 * @return array{events: array, truncated: bool, covered_from: ?int}|null  API失敗時は null
	 *   covered_from: 打ち切り時、取得できた最古Eventの clock（それより前は未集計）
	 */
	public static function fetch(array $hostids, int $time_from, int $time_till, array $severities,
			int $max_events): ?array {
		$events = [];
		$truncated = false;
		$till_eventid = null;

		$options = [
			'output' => ['eventid', 'clock', 'severity'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'hostids' => $hostids,
			'time_from' => $time_from,
			'time_till' => $time_till,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		if (count(array_unique($severities)) < count(Aggregator::SEVERITIES)) {
			$options['severities'] = array_values(array_unique(array_map('intval', $severities)));
		}

		while (true) {
			// 上限を1件超えて要求し、上限を超える Event が存在するかを判定する。
			$want = min(self::PAGE_SIZE, $max_events - count($events) + 1);

			$page_options = $options + ['limit' => $want];

			if ($till_eventid !== null) {
				$page_options['eventid_till'] = $till_eventid;
			}

			$page = API::Event()->get($page_options);

			if (!is_array($page)) {
				return null;
			}

			foreach ($page as $e) {
				$events[] = [
					'eventid' => (string) $e['eventid'],
					'clock' => (int) $e['clock'],
					'severity' => (int) $e['severity']
				];
			}

			if (count($events) > $max_events) {
				$events = array_slice($events, 0, $max_events);
				$truncated = true;

				break;
			}

			if (count($page) < $want) {
				break;
			}

			// 次ページ: 直前ページの最小 eventid より小さいものだけを取得する。
			$last = end($page);
			$till_eventid = (string) ((int) $last['eventid'] - 1);
		}

		return [
			'events' => $events,
			'truncated' => $truncated,
			'covered_from' => $truncated && $events ? min(array_column($events, 'clock')) : null
		];
	}
}
