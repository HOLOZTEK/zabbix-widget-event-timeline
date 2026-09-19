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
	 * X軸ラベルの構成要素。Bucketの開始時刻を「時間 → 月日 → 年」の階層に分けて返す。
	 * どの要素を表示するか（前回表示したTickからの変化分のみ）は、実際に表示されるTickが確定する
	 * 描画側で判定する。タイムゾーン変換はここで済ませ、JavaScript側では行わない。
	 *
	 * @return array{time: ?string, date: string, year: string, key: string}
	 *         time: 時間行（日・週・月Bucketでは null）、date: 月日行（月Bucketでは月）、
	 *         year: 年行、key: 月日行が変わったかを判定するキー
	 */
	public static function axisParts(int $start, string $unit, DateTimeZone $tz): array {
		$d = (new DateTimeImmutable('@'.$start))->setTimezone($tz);

		if ($unit === BucketPlanner::UNIT_MONTH) {
			return ['time' => null, 'date' => $d->format('m'), 'year' => $d->format('Y'), 'key' => $d->format('Ym')];
		}

		$is_time_unit = $unit === BucketPlanner::UNIT_MINUTE || $unit === BucketPlanner::UNIT_HOUR;

		return [
			'time' => $is_time_unit ? $d->format('H:i') : null,
			'date' => $d->format('m/d'),
			'year' => $d->format('Y'),
			'key' => $d->format('Ymd')
		];
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
