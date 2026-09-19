<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;

/**
 * Event retrieval: 対象Host集合・Event Selection・表示期間から Trigger 由来の Problem Event を取得する。
 *
 * - 内部Event: ['eventid', 'clock'(発生), 'end'(復旧時刻 / 未復旧は null), 'severity', 'acknowledged']。
 *   集計（Aggregator）とは分離しており、ここでは Filter・集計を行わない。
 * - Problem Event のみ数える（Recovery Event 自体は数えない）。1 eventid = 1 Event。
 * - 新しいEventから eventid 降順でページング取得し、上限に達したら打ち切る。打ち切りは呼び出し側へ
 *   truncated として明示する（無言で欠落させない）。
 * - 継続モードでは、復旧時刻の取得と、表示期間より前に発生して期間内まで継続したEventの取得を行う。
 */
class EventFetcher {

	private const PAGE_SIZE = 5000;
	private const RECOVERY_CHUNK = 500;

	/**
	 * @param int[]   $hostids    resolved host IDs（Source resolution の結果）
	 * @param int     $time_from  期間開始（UNIX時刻、含む）
	 * @param int     $time_till  期間終了（UNIX時刻、含む）
	 * @param int[]   $severities API側でも絞り込むSeverity（全Severityなら絞り込まない）
	 * @param int     $max_events 取得件数の上限（発生期間内・期間前それぞれの走査に適用）
	 * @param array[] $selectors  Event Selection。event.get へ追加するオプション集合のリスト（結果はOR統合）。
	 *                            空配列 [[]] は絞り込みなし。
	 * @param bool    $continuous 継続モード（復旧時刻と、期間前から継続したEventも取得する）
	 * @param int[]   $ack_states API側でも絞り込む確認状態（両方なら絞り込まない）
	 *
	 * @return array{events: array, truncated: bool, covered_from: ?int}|null  API失敗時は null
	 *   covered_from: 打ち切り時、走査できた最古Eventの clock（それより前は未集計）
	 */
	public static function fetch(array $hostids, int $time_from, int $time_till, array $severities,
			int $max_events, array $selectors = [[]], bool $continuous = false, array $ack_states = [0, 1]): ?array {
		$base = [
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'hostids' => $hostids
		];

		if (count(array_unique($severities)) < count(Aggregator::SEVERITIES)) {
			$base['severities'] = array_values(array_unique(array_map('intval', $severities)));
		}

		$ack_states = array_values(array_unique(array_map('intval', $ack_states)));

		if (count($ack_states) === 1) {
			$base['acknowledged'] = $ack_states[0] === 1;
		}

		$by_id = [];
		$truncated = false;
		$covered_from = null;

		foreach ($selectors as $selector) {
			$result = self::scan($base + $selector + [], ['time_from' => $time_from, 'time_till' => $time_till],
				$max_events, $continuous, null
			);

			if ($result === null) {
				return null;
			}

			foreach ($result['events'] as $e) {
				$by_id[$e['eventid']] = $e;
			}

			if ($result['truncated']) {
				$truncated = true;
				$covered_from = self::minClock($covered_from, $result['covered_from']);
			}

			if ($continuous) {
				// 表示期間より前に発生し、期間開始時点で継続していたEvent。
				$pre = self::scan($base + $selector, ['time_till' => $time_from - 1], $max_events, true, $time_from);

				if ($pre === null) {
					return null;
				}

				foreach ($pre['events'] as $e) {
					$by_id[$e['eventid']] = $e;
				}

				if ($pre['truncated']) {
					$truncated = true;
					$covered_from = self::minClock($covered_from, $pre['covered_from']);
				}
			}
		}

		$events = array_values($by_id);

		return ['events' => $events, 'truncated' => $truncated, 'covered_from' => $truncated ? $covered_from : null];
	}

	private static function minClock(?int $a, ?int $b): ?int {
		return $a === null ? $b : ($b === null ? $a : min($a, $b));
	}

	/**
	 * 1つの条件でEventを新しい順に取得する。
	 *
	 * @param array    $options      event.get の条件（期間以外）
	 * @param array    $range        time_from / time_till
	 * @param bool     $with_end     復旧時刻も取得する
	 * @param int|null $active_from  指定時は「この時刻以降まで継続していた（未復旧または復旧がこの時刻以降）」Eventだけを残す。
	 *                               走査件数は $max_events で打ち切る。
	 *
	 * @return array{events: array, truncated: bool, covered_from: ?int}|null
	 */
	private static function scan(array $options, array $range, int $max_events, bool $with_end,
			?int $active_from): ?array {
		$options = $options + $range + [
			'output' => ['eventid', 'clock', 'severity', 'acknowledged', 'r_eventid'],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		$events = [];
		$scanned = 0;
		$truncated = false;
		$till_eventid = null;
		$oldest = null;

		while (true) {
			// 上限を1件超えて要求し、上限を超える Event が存在するかを判定する。
			$want = min(self::PAGE_SIZE, $max_events - $scanned + 1);

			$page_options = $options + ['limit' => $want];

			if ($till_eventid !== null) {
				$page_options['eventid_till'] = $till_eventid;
			}

			$page = API::Event()->get($page_options);

			if (!is_array($page)) {
				return null;
			}

			$batch = [];

			foreach ($page as $e) {
				if ($scanned >= $max_events) {
					$truncated = true;

					break;
				}

				$scanned++;
				$oldest = (int) $e['clock'];

				$batch[] = [
					'eventid' => (string) $e['eventid'],
					'clock' => (int) $e['clock'],
					'end' => null,
					'severity' => (int) $e['severity'],
					'acknowledged' => (int) $e['acknowledged'],
					'r_eventid' => (string) ($e['r_eventid'] ?? '0')
				];
			}

			if ($with_end && $batch) {
				$recovered = self::recoveryClocks(array_column($batch, 'r_eventid'));

				if ($recovered === null) {
					return null;
				}

				foreach ($batch as &$e) {
					$e['end'] = $recovered[$e['r_eventid']] ?? null;
				}

				unset($e);
			}

			foreach ($batch as $e) {
				// 未復旧（r_eventid = 0）または復旧が期間開始以降のEventだけが、期間前から継続している。
				if ($active_from !== null && $e['end'] !== null && $e['end'] < $active_from) {
					continue;
				}

				unset($e['r_eventid']);
				$events[] = $e;
			}

			if ($truncated || count($page) < $want) {
				break;
			}

			// 次ページ: 直前ページの最小 eventid より小さいものだけを取得する。
			$last = end($page);
			$till_eventid = (string) ((int) $last['eventid'] - 1);
		}

		return [
			'events' => $events,
			'truncated' => $truncated,
			'covered_from' => $truncated ? $oldest : null
		];
	}

	/**
	 * 復旧Eventの発生時刻を取得する。
	 *
	 * @param string[] $r_eventids  Problem Event の r_eventid（"0" は未復旧）
	 *
	 * @return array<string, int>|null  r_eventid => 復旧時刻。失敗時は null
	 */
	private static function recoveryClocks(array $r_eventids): ?array {
		$ids = array_values(array_unique(array_filter($r_eventids, static fn(string $id): bool => $id !== '0')));
		$clocks = [];

		foreach (array_chunk($ids, self::RECOVERY_CHUNK) as $chunk) {
			$rows = API::Event()->get([
				'output' => ['eventid', 'clock'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$clocks[(string) $row['eventid']] = (int) $row['clock'];
			}
		}

		return $clocks;
	}

	/**
	 * Tooltip用の詳細（Event名・関連Host名）を eventid 指定でまとめて取得する。
	 * Timeline生成時に1度だけ呼び、Hover時の追加Requestは行わない。集計値には影響しない。
	 * 1つのEventが複数Hostに関連する場合は、関連Hostをすべて返す。
	 *
	 * @param string[] $eventids
	 *
	 * @return array<string, array{name: string, hosts: string[]}>|null  失敗時は null
	 */
	public static function fetchDetails(array $eventids): ?array {
		$details = [];

		foreach (array_chunk(array_values(array_unique($eventids)), 500) as $chunk) {
			$rows = API::Event()->get([
				'output' => ['eventid', 'name'],
				'selectHosts' => ['hostid', 'name'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$details[(string) $row['eventid']] = [
					'name' => (string) $row['name'],
					'hosts' => array_values(array_map(
						static fn(array $h): string => (string) $h['name'],
						$row['hosts'] ?? []
					))
				];
			}
		}

		return $details;
	}
}
