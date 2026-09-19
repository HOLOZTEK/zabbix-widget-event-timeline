<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Severity Filter と Bucket集計（描画から独立した純粋なロジック）。
 *
 * Event配列は [['clock' => int, 'severity' => int], ...] とする。将来 Active Event / By Host /
 * By Event などが加わっても、集計済みデータ（buckets）の形を保てば描画部を再利用できる。
 */
class Aggregator {

	public const SEVERITIES = [0, 1, 2, 3, 4, 5];

	// 集計データの列名（Severity値の昇順＝積み上げ順。Not classified が最下段）。
	public const SEVERITY_KEYS = [
		0 => 'not_classified',
		1 => 'information',
		2 => 'warning',
		3 => 'average',
		4 => 'high',
		5 => 'disaster'
	];

	/**
	 * 集計対象外のSeverityを除外する（描画後の非表示ではなく集計段階で除外）。
	 */
	public static function filterBySeverity(array $events, array $severities): array {
		$allowed = array_flip(array_map('intval', $severities));

		return array_values(array_filter($events, static fn(array $e): bool => isset($allowed[(int) $e['severity']])));
	}

	/**
	 * eventid をキーにした重複排除（同一eventidは1件）。
	 */
	public static function uniqueByEventid(array $events): array {
		$unique = [];

		foreach ($events as $e) {
			$unique[$e['eventid']] = $e;
		}

		return array_values($unique);
	}

	/**
	 * Tooltipの詳細一覧に載せるEventを、Bucket × Severity ごとに古い順で最大 $limit 件選ぶ。
	 * 集計（aggregate）には関与しない。件数の実数は aggregate の結果を使う。
	 *
	 * @return array<int, array<int, array>>  [bucket添字][severity] => [['eventid','clock'], ...]
	 */
	public static function pickDetails(array $events, array $bounds, int $limit): array {
		usort($events, static fn(array $a, array $b): int => [(int) $a['clock'], (int) $a['eventid']]
			<=> [(int) $b['clock'], (int) $b['eventid']]);

		$picked = [];

		foreach ($events as $e) {
			$index = BucketPlanner::locate($bounds, (int) $e['clock']);
			$severity = (int) $e['severity'];

			if ($index < 0 || !isset(self::SEVERITY_KEYS[$severity])) {
				continue;
			}

			if (count($picked[$index][$severity] ?? []) < $limit) {
				$picked[$index][$severity][] = ['eventid' => $e['eventid'], 'clock' => (int) $e['clock']];
			}
		}

		return $picked;
	}

	/**
	 * 発生イベント数モード: Eventの clock が属するBucketへ1件加算する（start <= clock < end）。
	 *
	 * @param array $events  [['clock' => int, 'severity' => int], ...]
	 * @param int[] $bounds  BucketPlanner が返した境界列
	 *
	 * @return array  bucket_start / bucket_end / 各Severity件数 / total を持つ行の配列
	 */
	public static function aggregate(array $events, array $bounds): array {
		$rows = [];

		for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
			$row = ['bucket_start' => $bounds[$i], 'bucket_end' => $bounds[$i + 1]];

			foreach (self::SEVERITY_KEYS as $key) {
				$row[$key] = 0;
			}

			$row['total'] = 0;
			$rows[] = $row;
		}

		foreach ($events as $e) {
			$index = BucketPlanner::locate($bounds, (int) $e['clock']);
			$key = self::SEVERITY_KEYS[(int) $e['severity']] ?? null;

			if ($index < 0 || $key === null) {
				continue;
			}

			$rows[$index][$key]++;
			$rows[$index]['total']++;
		}

		return $rows;
	}
}
