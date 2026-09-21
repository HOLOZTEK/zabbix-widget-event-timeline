<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Drilldown（詳細一覧）の対象選定（描画・API取得から独立した純粋なロジック）。
 *
 * 入力の $events は Filter（Severity / Ack）を通過済みで、次元モードでは表示期間の判定も済んだEvent
 * （グラフの集計と同じ母集団）。集計側（Aggregator / DimensionAggregator）と同じ規則で絞り込み、
 * 「棒の値 = 詳細の件数」が成り立つようにする。$severity が null のときはSeverityを限定しない。
 */
class DetailSelector {

	/**
	 * 時間軸: Bucket [$start, $end) に数えられるEvent。
	 * 発生数: start <= clock < end / 継続数: 継続期間が区間と重なる（未復旧は $now まで）。
	 */
	public static function timeline(array $events, int $start, int $end, bool $continuous, int $now,
			?int $severity): array {
		$picked = [];

		foreach ($events as $e) {
			if (!self::matchSeverity($e, $severity)) {
				continue;
			}

			if ($continuous) {
				[$s, $t] = Aggregator::span($e, $now);
				$hit = $s < $end && $t > $start;
			}
			else {
				$hit = (int) $e['clock'] >= $start && (int) $e['clock'] < $end;
			}

			if ($hit) {
				$picked[] = $e;
			}
		}

		return $picked;
	}

	/**
	 * By Host: そのHostに関連するEvent（1 eventid = 1件）。
	 *
	 * @param array<string, string[]> $event_hosts  eventid => Host ID[]
	 */
	public static function byHost(array $events, array $event_hosts, string $hostid, ?int $severity): array {
		return array_values(array_filter($events, static fn(array $e): bool => self::matchSeverity($e, $severity)
			&& in_array($hostid, array_map('strval', $event_hosts[$e['eventid']] ?? []), true)
		));
	}

	/**
	 * By Host group: Groupに属するHostに関連するEvent（Group内で1 eventid = 1件）。
	 *
	 * @param array<string, array<string|int, string>> $host_groups  hostid => [groupid => group名]
	 */
	public static function byHostGroup(array $events, array $event_hosts, array $host_groups, string $groupid,
			?int $severity): array {
		return array_values(array_filter($events, static function (array $e) use ($event_hosts, $host_groups, $groupid,
				$severity): bool {
			if (!self::matchSeverity($e, $severity)) {
				return false;
			}

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				if (isset($host_groups[$hostid][$groupid])) {
					return true;
				}
			}

			return false;
		}));
	}

	/**
	 * By Event: Event名に該当するHostの一覧（Event名 × Host のユニーク数と一致）。
	 * Hostの Severity は By Event 集計と同じく、現在のFilterを通ったEventのうち最も高いもの。
	 * $severity 指定時は、そのSeverityが採用されたHostだけ。count はそのHostでの該当Event数（eventid単位）。
	 *
	 * @return array<int, array{hostid: string, severity: int, count: int, eventids: string[]}>
	 */
	public static function byEvent(array $events, array $event_hosts, array $event_names, string $name,
			?int $severity): array {
		$hosts = [];

		foreach ($events as $e) {
			if ((string) ($event_names[$e['eventid']] ?? '') !== $name) {
				continue;
			}

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				$hostid = (string) $hostid;
				$hosts[$hostid] ??= ['hostid' => $hostid, 'severity' => (int) $e['severity'], 'count' => 0,
					'eventids' => []];
				$hosts[$hostid]['severity'] = max($hosts[$hostid]['severity'], (int) $e['severity']);
				$hosts[$hostid]['count']++;
				$hosts[$hostid]['eventids'][] = (string) $e['eventid'];
			}
		}

		if ($severity !== null) {
			$hosts = array_filter($hosts, static fn(array $h): bool => $h['severity'] === $severity);
		}

		return array_values($hosts);
	}

	private static function matchSeverity(array $e, ?int $severity): bool {
		return $severity === null || (int) $e['severity'] === $severity;
	}
}
