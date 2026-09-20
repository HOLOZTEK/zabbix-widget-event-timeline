<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Dimension Aggregation / Sort / Paging（描画から独立した純粋なロジック）。
 *
 * 第3フェーズの表示モードは「Dimension × Measure × Stack」で表す。Stack は常に Severity。
 *   By Host       : Dimension=Host        Measure=Event数（Host内で1 eventid = 1件）
 *   By Host group : Dimension=Host group  Measure=Event数（Group内で1 eventid = 1件）
 *   By Event      : Dimension=Event名     Measure=影響Host数（Event名 × Host のユニーク数）
 *
 * 入力の $events は Filter（Severity / Ack）と期間判定を通過済みのEvent。
 * $event_hosts は eventid => Host ID[]（対象Host集合との交差済み）。
 *
 * 出力行: ['id' => string, 'label' => string, 'counts' => int[6]（Severity値の昇順）, 'total' => int]
 */
class DimensionAggregator {

	public const SORT_COUNT = 0;
	public const SORT_ASC   = 1;
	public const SORT_DESC  = 2;

	public const SORTS = [self::SORT_COUNT, self::SORT_ASC, self::SORT_DESC];

	private static function emptyCounts(): array {
		return array_fill(0, count(Aggregator::SEVERITIES), 0);
	}

	/**
	 * By Host: 各Hostについて、そのHostに関連するEventを重複なく数える。
	 * 複数Hostに関連するEventは、各Hostで1件ずつ数える。
	 *
	 * @param array<int|string, string> $host_names  hostid => 表示名（カテゴリの母集団）
	 */
	public static function byHost(array $events, array $event_hosts, array $host_names): array {
		$seen = [];
		$rows = [];

		foreach ($events as $e) {
			$severity = (int) $e['severity'];

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				if (!isset($host_names[$hostid]) || isset($seen[$hostid][$e['eventid']])) {
					continue;
				}

				$seen[$hostid][$e['eventid']] = true;
				$rows[$hostid] ??= ['id' => (string) $hostid, 'label' => (string) $host_names[$hostid],
					'counts' => self::emptyCounts(), 'total' => 0];
				$rows[$hostid]['counts'][$severity]++;
				$rows[$hostid]['total']++;
			}
		}

		return array_values($rows);
	}

	/**
	 * By Host group: 各Groupについて、Groupに属するHostに関連するEventを重複なく数える（Group内で1 eventid = 1件）。
	 * HostがGroupを複数持つ場合、Eventは複数Groupに現れる。
	 *
	 * @param array<int|string, array<int|string, string>> $host_groups  hostid => [groupid => group名]
	 */
	public static function byHostGroup(array $events, array $event_hosts, array $host_groups): array {
		$seen = [];
		$rows = [];

		foreach ($events as $e) {
			$severity = (int) $e['severity'];

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				foreach ($host_groups[$hostid] ?? [] as $groupid => $group_name) {
					if (isset($seen[$groupid][$e['eventid']])) {
						continue;
					}

					$seen[$groupid][$e['eventid']] = true;
					$rows[$groupid] ??= ['id' => (string) $groupid, 'label' => (string) $group_name,
						'counts' => self::emptyCounts(), 'total' => 0];
					$rows[$groupid]['counts'][$severity]++;
					$rows[$groupid]['total']++;
				}
			}
		}

		return array_values($rows);
	}

	/**
	 * By Event: Event名ごとの影響Host数（Event名 × Host のユニーク数）。eventid 単位では数えない。
	 * 同じEvent名 × Hostに複数のSeverityがある場合は、現在のFilterを通ったEventのうち最も高いSeverityへ1回だけ割り当てる。
	 *
	 * @param array<string, string> $event_names  eventid => Event名
	 */
	public static function byEvent(array $events, array $event_hosts, array $event_names): array {
		$best = [];

		foreach ($events as $e) {
			$name = (string) ($event_names[$e['eventid']] ?? '');

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				$severity = (int) $e['severity'];

				if (!isset($best[$name][$hostid]) || $best[$name][$hostid] < $severity) {
					$best[$name][$hostid] = $severity;
				}
			}
		}

		$rows = [];

		foreach ($best as $name => $hosts) {
			$row = ['id' => (string) $name, 'label' => (string) $name, 'counts' => self::emptyCounts(), 'total' => 0];

			foreach ($hosts as $severity) {
				$row['counts'][$severity]++;
				$row['total']++;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 並び替え。件数順=件数の降順（同数はA→Z）、A→Z / Z→A は大文字小文字を区別しない自然順。
	 */
	public static function sort(array $rows, int $mode): array {
		$natural = static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label'])
			?: strcmp($a['label'], $b['label']);

		switch ($mode) {
			case self::SORT_ASC:
				usort($rows, $natural);
				break;

			case self::SORT_DESC:
				usort($rows, static fn(array $a, array $b): int => $natural($b, $a));
				break;

			default:
				usort($rows, static fn(array $a, array $b): int => $b['total'] <=> $a['total'] ?: $natural($a, $b));
		}

		return $rows;
	}

	/**
	 * ページング。範囲外のページは有効な範囲（1〜最終ページ）へ丸める。Top N ではなく全件を分割する。
	 *
	 * @return array{rows: array, page: int, pages: int, total: int, from: int, to: int}
	 */
	public static function paginate(array $rows, int $page_size, int $page): array {
		$total = count($rows);
		$page_size = max(1, $page_size);
		$pages = max(1, (int) ceil($total / $page_size));
		$page = max(1, min($pages, $page));
		$offset = ($page - 1) * $page_size;
		$slice = array_slice($rows, $offset, $page_size);

		return [
			'rows' => $slice,
			'page' => $page,
			'pages' => $pages,
			'total' => $total,
			'from' => $slice ? $offset + 1 : 0,
			'to' => $offset + count($slice)
		];
	}
}
