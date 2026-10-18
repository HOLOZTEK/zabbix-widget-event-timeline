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
	 * Acknowledged Filter: Eventの確認状態（acknowledged: 1=確認済み / 0=未確認）が $states に含まれるものだけを残す。
	 * 判定は取得時点のEventの現在の状態（過去時点の確認状態は再構成しない）。
	 */
	public static function filterByAck(array $events, array $states): array {
		$allowed = array_flip(array_map('intval', $states));

		return array_values(array_filter($events,
			static fn(array $e): bool => isset($allowed[(int) ($e['acknowledged'] ?? 0)])
		));
	}

	/**
	 * Eventが占める時間区間 [start, end)。end が null（未復旧）のときは $now まで継続中として扱う。
	 * 復旧が発生と同時刻のEventも最低1秒の長さを持たせ、継続モードが発生モードの結果を必ず含むようにする。
	 *
	 * @return int[]  [start, end]
	 */
	public static function span(array $e, int $now): array {
		$start = (int) $e['clock'];
		$end = ($e['end'] ?? null) === null ? $now : (int) $e['end'];

		return [$start, max($end, $start + 1)];
	}

	/**
	 * 区間 [start, end) と重なるBucketの添字範囲。重なり判定は start < bucket_end AND end > bucket_start。
	 * 重なるBucketがなければ null。
	 *
	 * @return int[]|null  [first, last]
	 */
	public static function bucketRange(array $bounds, int $start, int $end): ?array {
		$n = count($bounds) - 1;

		if ($n < 1 || $end <= $bounds[0] || $start >= $bounds[$n]) {
			return null;
		}

		$first = $start < $bounds[0] ? 0 : BucketPlanner::locate($bounds, $start);
		$last = $end - 1 >= $bounds[$n] ? $n - 1 : BucketPlanner::locate($bounds, $end - 1);

		return $first < 0 || $last < $first ? null : [$first, $last];
	}

	/**
	 * Tooltipの詳細一覧に載せるEventを、Bucket × Severity ごとに古い順で最大 $limit 件選ぶ。
	 * 集計（aggregate）には関与しない。件数の実数は aggregate の結果を使う。
	 * 継続モードでは、Bucketと重なる継続中のEventも対象になる。
	 *
	 * @return array<int, array<int, array>>  [bucket添字][severity] => [['eventid','clock','end'], ...]
	 */
	public static function pickDetails(array $events, array $bounds, int $limit, bool $continuous = false,
			int $now = 0): array {
		usort($events, static fn(array $a, array $b): int => (int) $a['clock'] <=> (int) $b['clock']
			?: Ids::compare(Ids::ref($a['eventid']), Ids::ref($b['eventid'])));

		$picked = [];

		foreach ($events as $e) {
			$severity = (int) $e['severity'];

			if (!isset(self::SEVERITY_KEYS[$severity])) {
				continue;
			}

			if ($continuous) {
				[$start, $end] = self::span($e, $now);
				$range = self::bucketRange($bounds, $start, $end);
			}
			else {
				$index = BucketPlanner::locate($bounds, (int) $e['clock']);
				$range = $index < 0 ? null : [$index, $index];
			}

			if ($range === null) {
				continue;
			}

			for ($i = $range[0]; $i <= $range[1]; $i++) {
				if (count($picked[$i][$severity] ?? []) < $limit) {
					$picked[$i][$severity][] = [
						'eventid' => $e['eventid'],
						'clock' => (int) $e['clock'],
						'end' => $e['end'] ?? null
					];
				}
			}
		}

		return $picked;
	}

	/**
	 * Bucket集計。
	 * - 発生イベント数モード: Eventの clock が属するBucketへ1件加算する（start <= clock < end）。
	 * - 継続イベント加算モード: Eventの継続期間と重なるすべてのBucketへ1件ずつ加算する
	 *   （start < bucket_end AND end > bucket_start。未復旧は $now まで。将来のBucketへは加算しない）。
	 *   同一Eventは1つのBucketで1回だけ数える。
	 *
	 * @param array $events  [['clock' => int, 'end' => ?int, 'severity' => int], ...]
	 * @param int[] $bounds  BucketPlanner が返した境界列
	 *
	 * @return array  bucket_start / bucket_end / 各Severity件数 / total を持つ行の配列
	 */
	public static function aggregate(array $events, array $bounds, bool $continuous = false, int $now = 0): array {
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
			$key = self::SEVERITY_KEYS[(int) $e['severity']] ?? null;

			if ($key === null) {
				continue;
			}

			if ($continuous) {
				[$start, $end] = self::span($e, $now);
				$range = self::bucketRange($bounds, $start, $end);
			}
			else {
				$index = BucketPlanner::locate($bounds, (int) $e['clock']);
				$range = $index < 0 ? null : [$index, $index];
			}

			if ($range === null) {
				continue;
			}

			for ($i = $range[0]; $i <= $range[1]; $i++) {
				$rows[$i][$key]++;
				$rows[$i]['total']++;
			}
		}

		return $rows;
	}
}
