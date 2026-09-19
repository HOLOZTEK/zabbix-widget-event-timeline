<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Bucketの表示文字列（X軸ラベル・Tooltip用の時間範囲）を、Bucket計画と同じタイムゾーンで生成する。
 * 表示文字列はサーバー側で確定させ、JavaScript側でタイムゾーン変換を行わない。
 */
class TimeFormatter {

	/**
	 * X軸ラベル。Bucketの開始時刻を示す。改行（\n）で日付と時刻を2段にする。
	 * 「今どの時間を示しているか」を誤認しないよう、期間が日をまたぐ場合は日付を、年をまたぐ場合は年も含める。
	 */
	public static function axisLabel(int $start, string $unit, bool $multi_day, bool $multi_year, DateTimeZone $tz): string {
		$d = (new DateTimeImmutable('@'.$start))->setTimezone($tz);

		if ($unit === BucketPlanner::UNIT_MONTH) {
			return $d->format('Y/m');
		}

		$date = $multi_year ? $d->format('Y/m/d') : $d->format('m/d');

		if ($unit === BucketPlanner::UNIT_DAY || $unit === BucketPlanner::UNIT_WEEK) {
			return $date;
		}

		return $multi_day ? $date."\n".$d->format('H:i') : $d->format('H:i');
	}

	/**
	 * Tooltip用の時間範囲。例: 2026/09/19 10:00 - 11:00
	 * 終端がちょうど翌日0時のときは 24:00 と表記する（12:00 - 24:00）。
	 */
	public static function rangeLabel(int $start, int $end, string $unit, DateTimeZone $tz): string {
		$s = (new DateTimeImmutable('@'.$start))->setTimezone($tz);
		$e = (new DateTimeImmutable('@'.$end))->setTimezone($tz);
		$e_last = (new DateTimeImmutable('@'.($end - 1)))->setTimezone($tz);

		switch ($unit) {
			case BucketPlanner::UNIT_MONTH:
				return $s->format('Y/m');

			case BucketPlanner::UNIT_DAY:
				return $s->format('Y/m/d');

			case BucketPlanner::UNIT_WEEK:
				return $s->format('Y/m/d').' - '.$e_last->format('Y/m/d');
		}

		if ($s->format('Y/m/d') === $e_last->format('Y/m/d')) {
			$end_time = $e->format('H:i') === '00:00' ? '24:00' : $e->format('H:i');

			return $s->format('Y/m/d H:i').' - '.$end_time;
		}

		return $s->format('Y/m/d H:i').' - '.$e->format('Y/m/d H:i');
	}

	/**
	 * 全Bucketの開始時刻が複数日/複数年にまたがるか。
	 *
	 * @return array{0: bool, 1: bool}  [multi_day, multi_year]
	 */
	public static function spans(int $first_start, int $last_start, DateTimeZone $tz): array {
		$a = (new DateTimeImmutable('@'.$first_start))->setTimezone($tz);
		$b = (new DateTimeImmutable('@'.$last_start))->setTimezone($tz);

		return [$a->format('Ymd') !== $b->format('Ymd'), $a->format('Y') !== $b->format('Y')];
	}
}
