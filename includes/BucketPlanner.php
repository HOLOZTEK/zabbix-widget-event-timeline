<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 時間Bucketの計画（Bucket幅の選択と自然境界の生成）。
 *
 * - Zabbix / API に依存しない純粋なロジック（CLI 単体テスト可能）。
 * - 日・週・月は固定秒数ではなく、指定タイムゾーンの現地暦で扱う
 *   （1日 = 現地00:00→翌日00:00。DSTで23/25時間の日も1Bucket）。
 * - Bucket幅は「自然境界へ揃えた結果、表示期間と重なるBucket数が MAX_BUCKETS 以下となる最小幅」を採用する。
 *   単純な「期間÷100」ではなく実際に境界を生成して数える（先頭・末尾の不完全Bucketで1本増えるため）。
 */
class BucketPlanner {

	public const MAX_BUCKETS = 100;

	// 週の開始曜日（ISO-8601 の曜日番号: 1=月曜 … 7=日曜）。将来変更する場合はここだけ。
	public const WEEK_START = 1;

	public const UNIT_MINUTE = 'minute';
	public const UNIT_HOUR   = 'hour';
	public const UNIT_DAY    = 'day';
	public const UNIT_WEEK   = 'week';
	public const UNIT_MONTH  = 'month';

	// [キー, 単位, 個数]。2日Bucketは自然境界（偶数アンカー）を定義できないため候補に含めない。
	public const CANDIDATES = [
		['1m',  self::UNIT_MINUTE, 1],
		['5m',  self::UNIT_MINUTE, 5],
		['10m', self::UNIT_MINUTE, 10],
		['15m', self::UNIT_MINUTE, 15],
		['30m', self::UNIT_MINUTE, 30],
		['1h',  self::UNIT_HOUR,   1],
		['2h',  self::UNIT_HOUR,   2],
		['3h',  self::UNIT_HOUR,   3],
		['6h',  self::UNIT_HOUR,   6],
		['12h', self::UNIT_HOUR,   12],
		['1d',  self::UNIT_DAY,    1],
		['7d',  self::UNIT_WEEK,   1],
		['1M',  self::UNIT_MONTH,  1]
	];

	/**
	 * @param int          $from  表示期間の開始（UNIX時刻）
	 * @param int          $to    表示期間の終了（UNIX時刻、この時刻を含む）
	 * @param DateTimeZone $tz    Bucket境界を決めるタイムゾーン
	 * @param int          $max   Bucket数の上限
	 *
	 * @return array {
	 *     interval: array{key: string, unit: string, count: int},
	 *     bounds: int[]   N+1個の境界。Bucket i = [bounds[i], bounds[i+1])
	 * }
	 */
	public static function plan(int $from, int $to, DateTimeZone $tz, int $max = self::MAX_BUCKETS): array {
		if ($to < $from) {
			$to = $from;
		}

		$last = count(self::CANDIDATES) - 1;

		foreach (self::CANDIDATES as $i => [$key, $unit, $n]) {
			// 最後の候補は上限を超えていても採用する（これ以上粗くできない）。
			$bounds = self::generate($from, $to, $unit, $n, $tz, $i === $last ? PHP_INT_MAX : $max);

			if ($bounds !== null) {
				return [
					'interval' => ['key' => $key, 'unit' => $unit, 'count' => $n],
					'bounds' => $bounds
				];
			}
		}

		// 到達しない（最後の候補は必ず採用される）。
		throw new \LogicException('No bucket candidate selected.');
	}

	/**
	 * 期間を覆う境界列を生成する。Bucket数が $max を超える場合は null（早期打ち切り）。
	 */
	public static function generate(int $from, int $to, string $unit, int $n, DateTimeZone $tz, int $max): ?array {
		$cur = self::floor($from, $unit, $n, $tz);
		$bounds = [];

		// $to を含むBucketまで生成する（最後の境界は $to より後になる）。
		while ($cur <= $to) {
			$bounds[] = $cur;

			if (count($bounds) > $max) {
				return null;
			}

			$cur = self::next($cur, $unit, $n, $tz);
		}

		$bounds[] = $cur;

		return $bounds;
	}

	/**
	 * $ts を含むBucketの開始時刻（自然境界）。
	 */
	public static function floor(int $ts, string $unit, int $n, DateTimeZone $tz): int {
		switch ($unit) {
			case self::UNIT_MINUTE:
				return self::floorWall($ts, $n * 60, $tz);

			case self::UNIT_HOUR:
				return self::floorWall($ts, $n * 3600, $tz);

			case self::UNIT_DAY:
				return self::localMidnight($ts, $tz)->getTimestamp();

			case self::UNIT_WEEK:
				$d = self::localMidnight($ts, $tz);
				$back = ((int) $d->format('N') - self::WEEK_START + 7) % 7;

				return $back > 0
					? self::midnightOf($d->modify('-'.$back.' day'))->getTimestamp()
					: $d->getTimestamp();

			case self::UNIT_MONTH:
				$d = self::localMidnight($ts, $tz);

				return self::midnightOf($d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1))
					->getTimestamp();
		}

		throw new \InvalidArgumentException('Unknown unit: '.$unit);
	}

	/**
	 * 開始時刻 $start のBucketの次のBucket開始時刻。
	 */
	public static function next(int $start, string $unit, int $n, DateTimeZone $tz): int {
		switch ($unit) {
			case self::UNIT_MINUTE:
			case self::UNIT_HOUR:
				$step = $n * ($unit === self::UNIT_MINUTE ? 60 : 3600);
				$next = self::floorWall($start + $step, $step, $tz);

				// DSTの巻き戻し時でも必ず前進させる。
				return $next > $start ? $next : $start + $step;

			case self::UNIT_DAY:
				return self::midnightOf(self::localMidnight($start, $tz)->modify('+'.$n.' day'))->getTimestamp();

			case self::UNIT_WEEK:
				return self::midnightOf(self::localMidnight($start, $tz)->modify('+'.(7 * $n).' day'))
					->getTimestamp();

			case self::UNIT_MONTH:
				return self::midnightOf(
					self::localMidnight($start, $tz)->modify('first day of +'.$n.' month')
				)->getTimestamp();
		}

		throw new \InvalidArgumentException('Unknown unit: '.$unit);
	}

	/**
	 * $ts を含むBucketの添字。範囲外は -1。
	 * 判定は start <= ts < end（二分探索）。
	 */
	public static function locate(array $bounds, int $ts): int {
		$last = count($bounds) - 1;

		if ($last < 1 || $ts < $bounds[0] || $ts >= $bounds[$last]) {
			return -1;
		}

		$lo = 0;
		$hi = $last - 1;

		while ($lo < $hi) {
			$mid = intdiv($lo + $hi + 1, 2);

			if ($bounds[$mid] <= $ts) {
				$lo = $mid;
			}
			else {
				$hi = $mid - 1;
			}
		}

		return $lo;
	}

	// 現地の壁時計で $step 秒の倍数へ切り下げる（時・分単位。現地0時基準）。
	private static function floorWall(int $ts, int $step, DateTimeZone $tz): int {
		$offset = $tz->getOffset(new DateTimeImmutable('@'.$ts));
		$wall = $ts + $offset;
		$floored = $wall - ((($wall % $step) + $step) % $step);
		$guess = $floored - $offset;

		// 切り下げ後の時刻でオフセットが変わる場合（DST境界）は再計算する。
		$offset2 = $tz->getOffset(new DateTimeImmutable('@'.$guess));

		return $offset2 === $offset ? $guess : $floored - $offset2;
	}

	private static function localMidnight(int $ts, DateTimeZone $tz): DateTimeImmutable {
		return self::midnightOf((new DateTimeImmutable('@'.$ts))->setTimezone($tz));
	}

	private static function midnightOf(DateTimeImmutable $d): DateTimeImmutable {
		return $d->setTime(0, 0, 0);
	}
}
