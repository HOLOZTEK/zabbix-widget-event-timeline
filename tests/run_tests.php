<?php declare(strict_types = 0);

/**
 * 依存なしの単体テスト。実行: php tests/run_tests.php
 * Zabbix / API には依存しない（BucketPlanner / Aggregator / TimeFormatter / SourceResolver::mergeUnique）。
 */

require_once __DIR__.'/../includes/BucketPlanner.php';
require_once __DIR__.'/../includes/Aggregator.php';
require_once __DIR__.'/../includes/TimeFormatter.php';
require_once __DIR__.'/../includes/DimensionAggregator.php';
require_once __DIR__.'/../includes/DetailSelector.php';
require_once __DIR__.'/../includes/SourceResolver.php';
require_once __DIR__.'/../includes/TriggerSelection.php';
require_once __DIR__.'/../includes/TriggerSelectionResolver.php';
require_once __DIR__.'/../includes/TriggerInheritanceCache.php';
require_once __DIR__.'/../includes/TriggerScopeExpander.php';

require_once __DIR__.'/stubs.php';

use Modules\EventTimeline\Includes\Aggregator;
use Modules\EventTimeline\Includes\BucketPlanner;
use Modules\EventTimeline\Includes\DetailSelector as DS;
use Modules\EventTimeline\Includes\DimensionAggregator as D;
use Modules\EventTimeline\Includes\SourceResolver;
use Modules\EventTimeline\Includes\TimeFormatter;
use Modules\EventTimeline\Includes\TriggerSelection as TS;
use Modules\EventTimeline\Includes\TriggerSelectionResolver as TR;
use Modules\EventTimeline\Includes\TriggerInheritanceCache as TC;
use Modules\EventTimeline\Includes\TriggerScopeExpander as TX;

$pass = 0;
$fail = 0;

function check(string $name, $actual, $expected): void {
	global $pass, $fail;

	if ($actual === $expected) {
		$pass++;
		return;
	}

	$fail++;
	echo "FAIL: $name\n  expected: ".json_encode($expected)."\n  actual:   ".json_encode($actual)."\n";
}

function ts(string $s, DateTimeZone $tz): int {
	return (new DateTimeImmutable($s, $tz))->getTimestamp();
}

$jst = new DateTimeZone('Asia/Tokyo');
$ny = new DateTimeZone('America/New_York');
$utc = new DateTimeZone('UTC');

// ---- 1. 境界（start <= clock < end）: 1時間Bucket、09:59:59 / 10:00:00 / 10:59:59 / 11:00:00 ----
$from = ts('2026-09-19 09:00:00', $jst);
$to = ts('2026-09-19 12:00:00', $jst);

// 1時間Bucketを直接生成して境界を検証
$bounds = BucketPlanner::generate($from, $to, BucketPlanner::UNIT_HOUR, 1, $jst, 1000);
$events = [];

foreach (['09:59:59', '10:00:00', '10:59:59', '11:00:00'] as $i => $t) {
	$events[] = ['eventid' => (string) $i, 'clock' => ts('2026-09-19 '.$t, $jst), 'severity' => 4];
}

$rows = Aggregator::aggregate($events, $bounds);
$by = [];

foreach ($rows as $r) {
	$by[gmdate('H', $r['bucket_start'] + 9 * 3600)] = $r['high'];
}

check('boundary 09-10', $by['09'], 1);
check('boundary 10-11', $by['10'], 2);
check('boundary 11-12', $by['11'], 1);
check('boundary 12-13 (to inclusive bucket)', $by['12'] ?? 0, 0);
check('boundary total', array_sum(array_column($rows, 'total')), 4);
check('boundary each event once', array_sum(array_column($rows, 'high')), 4);

// ---- 2. 指示書§12 の例 ----
$bounds = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-19 11:59:59', $jst),
	BucketPlanner::UNIT_HOUR, 1, $jst, 1000
);
$rows = Aggregator::aggregate([
	['eventid' => 'a', 'clock' => ts('2026-09-19 10:15:00', $jst), 'severity' => 3],
	['eventid' => 'b', 'clock' => ts('2026-09-19 10:37:00', $jst), 'severity' => 3],
	['eventid' => 'c', 'clock' => ts('2026-09-19 11:02:00', $jst), 'severity' => 3]
], $bounds);
check('example counts', array_column($rows, 'total'), [2, 1]);

// ---- 3. Bucket幅の選択（表示期間 → 幅）と本数上限 ----
$now = ts('2026-09-19 15:07:30', $jst);
$cases = [
	'1h' => 3600, '24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400, '90d' => 90 * 86400,
	'365d' => 365 * 86400
];
$expected_keys = ['1h' => '1m', '24h' => '15m', '7d' => '2h', '30d' => '12h', '90d' => '1d', '365d' => '7d'];

foreach ($cases as $name => $sec) {
	$p = BucketPlanner::plan($now - $sec, $now, $jst);
	$n = count($p['bounds']) - 1;
	check("plan $name count<=100", $n <= 100, true);
	check("plan $name interval", $p['interval']['key'], $expected_keys[$name]);
}

// 単純な 期間÷100 では101本になるケース: 10:30〜(10:30+100h) は1h幅だと101本 → 2hを選ぶ
$p = BucketPlanner::plan(ts('2026-09-19 10:30:00', $utc), ts('2026-09-19 10:30:00', $utc) + 100 * 3600 - 1, $utc);
check('non-aligned start avoids 101 buckets', $p['interval']['key'], '2h');
check('non-aligned start count', count($p['bounds']) - 1 <= 100, true);

// 前後に1本ずつ増える: ちょうど100本で収まる期間は採用される
$p = BucketPlanner::plan(ts('2026-09-19 00:00:00', $utc), ts('2026-09-19 00:00:00', $utc) + 100 * 3600 - 1, $utc);
check('exactly 100 buckets kept at 1h', [$p['interval']['key'], count($p['bounds']) - 1], ['1h', 100]);

// 最粗（1か月）でも100を超える期間はそのまま採用（間引かない）
$p = BucketPlanner::plan(ts('2010-01-01 00:00:00', $utc), ts('2026-09-19 00:00:00', $utc), $utc);
check('very long: monthly', $p['interval']['key'], '1M');

// ---- 4. 自然境界 ----
$b = BucketPlanner::generate(ts('2026-09-19 10:03:00', $jst), ts('2026-09-19 10:16:00', $jst),
	BucketPlanner::UNIT_MINUTE, 5, $jst, 1000
);
check('5m bounds', array_map(fn($x) => gmdate('H:i', $x + 9 * 3600), $b), ['10:00', '10:05', '10:10', '10:15', '10:20']);

// 日: 現地 00:00-24:00
$b = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-20 10:00:00', $jst),
	BucketPlanner::UNIT_DAY, 1, $jst, 1000
);
check('1d bounds', array_map(fn($x) => gmdate('m-d H:i', $x + 9 * 3600), $b), ['09-19 00:00', '09-20 00:00', '09-21 00:00']);

// 12時間は 00:00/12:00 の壁時計
$b = BucketPlanner::generate(ts('2026-09-19 05:00:00', $jst), ts('2026-09-19 20:00:00', $jst),
	BucketPlanner::UNIT_HOUR, 12, $jst, 1000
);
check('12h bounds', array_map(fn($x) => gmdate('H:i', $x + 9 * 3600), $b), ['00:00', '12:00', '00:00']);

// 週: 月曜開始（2026-09-19 は土曜。同週の月曜は 09-14）
$b = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-28 10:00:00', $jst),
	BucketPlanner::UNIT_WEEK, 1, $jst, 1000
);
check('week bounds (Mon start)', array_map(fn($x) => gmdate('D m-d', $x + 9 * 3600), $b),
	['Mon 09-14', 'Mon 09-21', 'Mon 09-28', 'Mon 10-05']
);

// 週: 日曜（N=7）の日付は前の月曜へ
$b = BucketPlanner::generate(ts('2026-09-20 12:00:00', $jst), ts('2026-09-20 12:00:00', $jst),
	BucketPlanner::UNIT_WEEK, 1, $jst, 1000
);
check('sunday belongs to previous Monday week', gmdate('D m-d', $b[0] + 9 * 3600), 'Mon 09-14');

// ---- 4b. 週の開始曜日（第3フェーズ: First day of week） ----
// 2026-09-19（土）を含む期間を、7曜日すべてを開始曜日として境界生成し、
// 最初の境界日が「その週開始曜日として直近（当日含む）の日付」になることを確認する。
// 09-13=日, 09-14=月, 09-15=火, 09-16=水, 09-17=木, 09-18=金, 09-19=土。
$week_start_expected_first = [
	BucketPlanner::WEEKDAY_SUN => 'Sun 09-13',
	BucketPlanner::WEEKDAY_MON => 'Mon 09-14',
	BucketPlanner::WEEKDAY_TUE => 'Tue 09-15',
	BucketPlanner::WEEKDAY_WED => 'Wed 09-16',
	BucketPlanner::WEEKDAY_THU => 'Thu 09-17',
	BucketPlanner::WEEKDAY_FRI => 'Fri 09-18',
	BucketPlanner::WEEKDAY_SAT => 'Sat 09-19'
];

foreach ($week_start_expected_first as $ws => $expected) {
	$b = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-28 10:00:00', $jst),
		BucketPlanner::UNIT_WEEK, 1, $jst, 1000, $ws
	);
	check("week_start=$ws first boundary", gmdate('D m-d', $b[0] + 9 * 3600), $expected);

	// 境界間隔は常に7日（自然境界のずれ・重複計上なし）。
	$spans_ok = true;
	for ($i = 1; $i < count($b); $i++) {
		$spans_ok = $spans_ok && intdiv($b[$i] - $b[$i - 1], 86400) === 7;
	}
	check("week_start=$ws all spans are 7 days", $spans_ok, true);
}

// 同一時刻でも開始曜日が異なれば、日曜開始と月曜開始とでBucket境界（延いては割当先週）が変わる
// （start <= clock < end の判定はlocate()側で共通のため、ここではfloor()の境界そのものを比較する）。
$sun_bounds = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-19 10:00:00', $jst),
	BucketPlanner::UNIT_WEEK, 1, $jst, 1000, BucketPlanner::WEEKDAY_SUN
);
$mon_bounds = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-19 10:00:00', $jst),
	BucketPlanner::UNIT_WEEK, 1, $jst, 1000, BucketPlanner::WEEKDAY_MON
);
check('week_start changes bucket boundary for same instant', $sun_bounds[0] !== $mon_bounds[0], true);

// 週開始曜日を指定しない場合は既定（月曜、後方互換）のまま。
$default_bounds = BucketPlanner::generate(ts('2026-09-19 10:00:00', $jst), ts('2026-09-19 10:00:00', $jst),
	BucketPlanner::UNIT_WEEK, 1, $jst, 1000
);
check('week_start default equals explicit Monday', $default_bounds, $mon_bounds);

// DSTをまたぐ週境界（America/New_York, 日曜開始）でも境界は単調増加し、2週分正しく生成される。
$b = BucketPlanner::generate(ts('2026-10-25 12:00:00', $ny), ts('2026-11-08 12:00:00', $ny),
	BucketPlanner::UNIT_WEEK, 1, $ny, 1000, BucketPlanner::WEEKDAY_SUN
);
check('NY week bounds across DST: 3 weekly buckets', count($b) - 1, 3);
$mono = true;
for ($i = 1; $i < count($b); $i++) {
	$mono = $mono && $b[$i] > $b[$i - 1];
}
check('NY week bounds across DST monotonic', $mono, true);

// UTC でも同様に週開始曜日どおりの境界になること（タイムゾーンによらないロジックの確認）。
// 2026-09-19 00:00 UTC は土曜（既存コメントのJST日付と同じ暦日で確認）。
$b = BucketPlanner::generate(ts('2026-09-19 10:00:00', $utc), ts('2026-09-19 10:00:00', $utc),
	BucketPlanner::UNIT_WEEK, 1, $utc, 1000, BucketPlanner::WEEKDAY_SUN
);
check('UTC week_start=Sunday boundary', gmdate('D m-d', $b[0]), 'Sun 09-13');

// plan() 経由でも week_start がBucket生成へ伝播すること（7日Bucketが選ばれる期間で確認。
// 既存の候補選択テスト（365d → '7d'）と同じ期間長を使う）。
$p = BucketPlanner::plan(ts('2026-09-19 15:07:30', $jst) - 365 * 86400, ts('2026-09-19 15:07:30', $jst), $jst,
	BucketPlanner::WEEKDAY_SUN
);
check('plan() propagates week_start (7d bucket, Sunday start)', $p['interval']['key'], '7d');
check('plan() week_start=Sunday first boundary is a Sunday',
	(int) (new DateTimeImmutable('@'.$p['bounds'][0]))->setTimezone($jst)->format('N'), 7
);

// 月: 月初〜翌月月初（うるう年2月・31日月）
$b = BucketPlanner::generate(ts('2028-01-15 00:00:00', $jst), ts('2028-03-10 00:00:00', $jst),
	BucketPlanner::UNIT_MONTH, 1, $jst, 1000
);
check('month bounds', array_map(fn($x) => gmdate('Y-m-d', $x + 9 * 3600), $b), ['2028-01-01', '2028-02-01', '2028-03-01', '2028-04-01']);
check('leap Feb has 29 days', intdiv($b[2] - $b[1], 86400), 29);

// ---- 5. DST: 1日は常に1Bucket（23時間・25時間の日） ----
$b = BucketPlanner::generate(ts('2026-03-07 12:00:00', $ny), ts('2026-03-10 12:00:00', $ny),
	BucketPlanner::UNIT_DAY, 1, $ny, 1000
);
check('NY spring-forward: 4 daily buckets', count($b) - 1, 4);
check('NY spring-forward day is 23h', intdiv($b[2] - $b[1], 3600), 23);
$b = BucketPlanner::generate(ts('2026-10-31 12:00:00', $ny), ts('2026-11-03 12:00:00', $ny),
	BucketPlanner::UNIT_DAY, 1, $ny, 1000
);
check('NY fall-back day is 25h', intdiv($b[2] - $b[1], 3600), 25);

// DSTをまたぐ1時間Bucketでも単調増加・重複なし
$b = BucketPlanner::generate(ts('2026-11-01 00:00:00', $ny), ts('2026-11-01 06:00:00', $ny),
	BucketPlanner::UNIT_HOUR, 1, $ny, 1000
);
$mono = true;

for ($i = 1; $i < count($b); $i++) {
	$mono = $mono && $b[$i] > $b[$i - 1];
}

check('hourly across DST monotonic', $mono, true);

// ---- 6. locate ----
$b = [100, 200, 300, 400];
check('locate below', BucketPlanner::locate($b, 99), -1);
check('locate start', BucketPlanner::locate($b, 100), 0);
check('locate end-1', BucketPlanner::locate($b, 199), 0);
check('locate boundary', BucketPlanner::locate($b, 200), 1);
check('locate last', BucketPlanner::locate($b, 399), 2);
check('locate end (exclusive)', BucketPlanner::locate($b, 400), -1);

// ---- 7. Severity Filter / 集計 ----
$events = [];

foreach ([0, 1, 2, 3, 4, 5, 5, 4] as $i => $sev) {
	$events[] = ['eventid' => (string) $i, 'clock' => 1000 + $i, 'severity' => $sev];
}

$f = Aggregator::filterBySeverity($events, [5]);
check('filter disaster only', count($f), 2);
$f = Aggregator::filterBySeverity($events, [4, 5]);
check('filter high and above', count($f), 4);
$f = Aggregator::filterBySeverity($events, []);
check('filter none', count($f), 0);
$rows = Aggregator::aggregate($f, [0, 5000]);
check('empty aggregate total', $rows[0]['total'], 0);
$rows = Aggregator::aggregate($events, [1000, 2000]);
check('aggregate per severity', [$rows[0]['not_classified'], $rows[0]['information'], $rows[0]['warning'],
	$rows[0]['average'], $rows[0]['high'], $rows[0]['disaster'], $rows[0]['total']], [1, 1, 1, 1, 2, 2, 8]
);
check('unique by eventid', count(Aggregator::uniqueByEventid([
	['eventid' => '1', 'clock' => 1, 'severity' => 1], ['eventid' => '1', 'clock' => 1, 'severity' => 1],
	['eventid' => '2', 'clock' => 1, 'severity' => 1]
])), 2);

// ---- 8. Host集合の重複排除（Host直接指定 + Host group所属） ----
check('mergeUnique', SourceResolver::mergeUnique([3, 1, '2', 3], [2, 4, 1], []), [1, 2, 3, 4]);
check('mergeUnique empty', SourceResolver::mergeUnique([], []), []);

// ---- 9. 表示文字列 ----
$s = ts('2026-09-19 10:00:00', $jst);
check('range 1h', TimeFormatter::rangeLabel($s, $s + 3600, BucketPlanner::UNIT_HOUR, $jst), '2026/09/19 10:00 - 11:00');
$s = ts('2026-09-19 12:00:00', $jst);
check('range to 24:00', TimeFormatter::rangeLabel($s, $s + 43200, BucketPlanner::UNIT_HOUR, $jst), '2026/09/19 12:00 - 24:00');
check('range day', TimeFormatter::rangeLabel(ts('2026-09-19 00:00:00', $jst), ts('2026-09-20 00:00:00', $jst), BucketPlanner::UNIT_DAY, $jst), '2026/09/19');
check('range week', TimeFormatter::rangeLabel(ts('2026-09-14 00:00:00', $jst), ts('2026-09-21 00:00:00', $jst), BucketPlanner::UNIT_WEEK, $jst), '2026/09/14 - 2026/09/20');
check('range month', TimeFormatter::rangeLabel(ts('2026-09-01 00:00:00', $jst), ts('2026-10-01 00:00:00', $jst), BucketPlanner::UNIT_MONTH, $jst), '2026/09');
check('axis parts hour', TimeFormatter::axisParts(ts('2026-09-19 10:05:00', $jst), 'minute', $jst), ['time' => '10:05', 'date' => '09/19', 'year' => '2026', 'key' => '20260919']);
check('axis parts day', TimeFormatter::axisParts(ts('2026-09-19 00:00:00', $jst), 'day', $jst), ['time' => null, 'date' => '09/19', 'year' => '2026', 'key' => '20260919']);
check('axis parts week', TimeFormatter::axisParts(ts('2026-09-14 00:00:00', $jst), 'week', $jst)['time'], null);
check('axis parts month', TimeFormatter::axisParts(ts('2026-09-01 00:00:00', $jst), 'month', $jst), ['time' => null, 'date' => '09', 'year' => '2026', 'key' => '202609']);

// pickDetails: Bucket×Severityごとに古い順で最大N件。集計値(aggregate)には影響しない。
$pd = [];
for ($i = 0; $i < 15; $i++) {
	$pd[] = ['eventid' => (string) (100 + $i), 'clock' => 1000 + (14 - $i), 'severity' => 3];
}
$pd[] = ['eventid' => '200', 'clock' => 1500, 'severity' => 5];
$pd[] = ['eventid' => '201', 'clock' => 6000, 'severity' => 3];
$picked = Aggregator::pickDetails($pd, [0, 5000], 10);
check('pickDetails limit 10', count($picked[0][3]), 10);
check('pickDetails oldest first', $picked[0][3][0]['clock'], 1000);
check('pickDetails per severity', count($picked[0][5]), 1);
check('pickDetails out of range skipped', count($picked), 1);
$agg = Aggregator::aggregate($pd, [0, 5000]);
check('aggregate unaffected by details', [$agg[0]['average'], $agg[0]['disaster'], $agg[0]['total']], [15, 1, 16]);

// --- 第2フェーズ: Ack Filter / 継続イベント加算 ---
$h = 3600;
$T0 = ts('2026-09-19 10:00:00', $jst);

$ev = [
	['eventid' => '1', 'clock' => 1, 'severity' => 3, 'acknowledged' => 1],
	['eventid' => '2', 'clock' => 2, 'severity' => 3, 'acknowledged' => 0],
	['eventid' => '3', 'clock' => 3, 'severity' => 3]
];
check('ack both', count(Aggregator::filterByAck($ev, [1, 0])), 3);
check('ack only', array_column(Aggregator::filterByAck($ev, [1]), 'eventid'), ['1']);
check('unack only (missing = 0)', array_column(Aggregator::filterByAck($ev, [0]), 'eventid'), ['2', '3']);
check('ack none', Aggregator::filterByAck($ev, []), []);

check('span unresolved', Aggregator::span(['clock' => 100, 'end' => null], 500), [100, 500]);
check('span resolved', Aggregator::span(['clock' => 100, 'end' => 300], 500), [100, 300]);
check('span zero length', Aggregator::span(['clock' => 100, 'end' => 100], 500), [100, 101]);

// 1時間Bucket 10:00-15:00 の例: 10:15 発生 → 13:20 復旧
$bounds = [];
for ($i = 0; $i <= 5; $i++) {
	$bounds[] = $T0 + $i * $h;
}
$e1 = ['eventid' => '1', 'clock' => $T0 + 900, 'end' => $T0 + 3 * $h + 1200, 'severity' => 4];
$occ = Aggregator::aggregate([$e1], $bounds);
check('occurrence: only start bucket', array_column($occ, 'total'), [1, 0, 0, 0, 0]);
$con = Aggregator::aggregate([$e1], $bounds, true, $T0 + 5 * $h);
check('continuous: 10:15-13:20', array_column($con, 'total'), [1, 1, 1, 1, 0]);
check('continuous: severity column', array_column($con, 'high'), [1, 1, 1, 1, 0]);

// 境界: Bucket開始ちょうどで発生 / Bucket終了ちょうどで復旧
$e2 = ['eventid' => '2', 'clock' => $T0 + $h, 'end' => $T0 + 2 * $h, 'severity' => 3];
check('continuous: exact bucket', array_column(Aggregator::aggregate([$e2], $bounds, true, $T0 + 5 * $h), 'total'), [0, 1, 0, 0, 0]);
// 期間前に発生・期間内で復旧
$e3 = ['eventid' => '3', 'clock' => $T0 - 5 * $h, 'end' => $T0 + $h + 1, 'severity' => 3];
check('continuous: pre-period recovering', array_column(Aggregator::aggregate([$e3], $bounds, true, $T0 + 5 * $h), 'total'), [1, 1, 0, 0, 0]);
check('occurrence: pre-period excluded', array_sum(array_column(Aggregator::aggregate([$e3], $bounds), 'total')), 0);
// 期間前に発生・未復旧: 期間末（now）まで。nowより先のBucketには加算しない
$e4 = ['eventid' => '4', 'clock' => $T0 - $h, 'end' => null, 'severity' => 5];
check('continuous: unresolved to now', array_column(Aggregator::aggregate([$e4], $bounds, true, $T0 + 2 * $h + 10), 'total'), [1, 1, 1, 0, 0]);
check('continuous: unresolved to period end', array_column(Aggregator::aggregate([$e4], $bounds, true, $T0 + 9 * $h), 'total'), [1, 1, 1, 1, 1]);
// 期間後に発生したEventは加算しない / 期間前に復旧済みは加算しない
$e5 = ['eventid' => '5', 'clock' => $T0 + 6 * $h, 'end' => null, 'severity' => 3];
$e6 = ['eventid' => '6', 'clock' => $T0 - 3 * $h, 'end' => $T0, 'severity' => 3];
check('continuous: outside excluded', array_sum(array_column(Aggregator::aggregate([$e5, $e6], $bounds, true, $T0 + 9 * $h), 'total')), 0);
// 継続 >= 発生（各Bucketで1Eventは1回のみ）
check('continuous: once per bucket', max(array_column(Aggregator::aggregate([$e1], $bounds, true, $T0 + 5 * $h), 'total')), 1);
// pickDetails 継続モード
$pk = Aggregator::pickDetails([$e1], $bounds, 10, true, $T0 + 5 * $h);
check('pickDetails continuous buckets', array_keys($pk), [0, 1, 2, 3]);
check('bucketRange none', Aggregator::bucketRange($bounds, $T0 + 6 * $h, $T0 + 7 * $h), null);

// resolveEventSelection
// （v0.2.0 Phase 2 以降は TriggerSelection + TriggerSelectionResolver の結果を受け取る。詳細は末尾の TR セクション）
$fetch_57 = static fn(array $ids): array => array_intersect_key([5 => ['templateid' => 0, 'flags' => 0],
	7 => ['templateid' => 0, 'flags' => 0]], array_flip($ids));
$ts_57 = TS::resolve(['5', '5', '7']);
$r_57 = TR::resolve($ts_57['triggerids'], $fetch_57);
check('event fixed', SourceResolver::resolveEventSelection($ts_57, $r_57,
	TX::expand($r_57, [9], static fn(array $h): array => $fetch_57([5, 7]), new TC($fetch_57))),
	['selected' => true, 'selectors' => [['objectids' => ['5', '7']]], 'error' => null, 'partial' => false]);
check('event fixed empty', SourceResolver::resolveEventSelection(TS::resolve([])),
	['selected' => true, 'selectors' => [[]], 'error' => null, 'partial' => false]);

// --- 第3フェーズ: 次元集計 / Sort / Paging ---
$ev = static fn(string $id, int $sev): array => ['eventid' => $id, 'clock' => 0, 'end' => null, 'severity' => $sev];
$c = static fn(array $rows): array => array_map(static fn(array $r): array => [$r['label'], $r['total']], $rows);

// By Host: 複数Hostに関連するEventは各Hostで1件、Host内は重複なし
$evs = [$ev('1', 4), $ev('2', 2), $ev('3', 4), $ev('1', 4)];
$eh = ['1' => ['10', '11'], '2' => ['10'], '3' => ['11', '11']];
$hn = ['10' => 'hostA', '11' => 'hostB'];
$r = D::byHost($evs, $eh, $hn);
check('byHost totals', $c($r), [['hostA', 2], ['hostB', 2]]);
check('byHost severity', $r[0]['counts'], [0, 0, 1, 0, 1, 0]);
check('byHost non-target host ignored', $c(D::byHost([$ev('9', 1)], ['9' => ['99']], $hn)), []);

// By Host group: Group内で1 eventid = 1件、複数Groupに現れる
$hg = ['10' => ['g1' => 'G1', 'g2' => 'G2'], '11' => ['g1' => 'G1']];
$r = D::byHostGroup([$ev('1', 4), $ev('2', 2)], ['1' => ['10', '11'], '2' => ['10']], $hg);
check('byHostGroup dedupe', $c($r), [['G1', 2], ['G2', 2]]);
$r = D::byHostGroup([$ev('1', 4)], ['1' => ['10', '11']], $hg);
check('byHostGroup one event two groups', $c($r), [['G1', 1], ['G2', 1]]);

// By Event: Event名 × Host のユニーク数、同一Hostの繰り返しは1、複数Severityは最高
$names = ['1' => 'CPU', '2' => 'CPU', '3' => 'CPU', '4' => 'Disk'];
$r = D::byEvent([$ev('1', 2), $ev('2', 4), $ev('3', 2), $ev('4', 3)],
	['1' => ['10'], '2' => ['10'], '3' => ['11'], '4' => ['10']], $names);
check('byEvent unique host', $c($r), [['CPU', 2], ['Disk', 1]]);
check('byEvent highest severity', $r[0]['counts'], [0, 0, 1, 0, 1, 0]);
check('byEvent same host repeated', $c(D::byEvent([$ev('1', 3), $ev('2', 3)], ['1' => ['10'], '2' => ['10']], $names)),
	[['CPU', 1]]);
check('byEvent empty', D::byEvent([], [], []), []);

// Sort
$rows = [['label' => 'b10', 'total' => 1], ['label' => 'B2', 'total' => 5], ['label' => 'a', 'total' => 5]];
check('sort count', array_column(D::sort($rows, D::SORT_COUNT), 'label'), ['a', 'B2', 'b10']);
check('sort asc natural', array_column(D::sort($rows, D::SORT_ASC), 'label'), ['a', 'B2', 'b10']);
check('sort desc', array_column(D::sort($rows, D::SORT_DESC), 'label'), ['b10', 'B2', 'a']);

// Paging
$many = array_map(static fn(int $i): array => ['label' => "h$i", 'total' => $i], range(1, 25));
$p = D::paginate($many, 10, 2);
check('page 2', [$p['page'], $p['pages'], $p['from'], $p['to'], $p['total'], count($p['rows'])], [2, 3, 11, 20, 25, 10]);
$p = D::paginate($many, 10, 9);
check('page clamp', [$p['page'], $p['from'], $p['to'], count($p['rows'])], [3, 21, 25, 5]);
$p = D::paginate([], 20, 3);
check('page empty', [$p['page'], $p['pages'], $p['from'], $p['to']], [1, 1, 0, 0]);
$p = D::paginate(array_slice($many, 0, 20), 20, 1);
check('page single', $p['pages'], 1);

// Drilldown（DetailSelector）: 詳細の件数が集計値（棒）と一致すること
$dev = static fn(string $id, int $clock, ?int $end, int $sev): array => ['eventid' => $id, 'clock' => $clock,
	'end' => $end, 'severity' => $sev, 'acknowledged' => 0];
$bounds = [100, 200, 300];
$devs = [$dev('1', 100, 150, 3), $dev('2', 199, null, 3), $dev('3', 200, 250, 4), $dev('4', 50, 120, 3),
	$dev('5', 300, null, 3)];

$sel = static fn(array $r): array => array_column($r, 'eventid');
check('detail timeline occurrence', $sel(DS::timeline($devs, 100, 200, false, 400, null)), ['1', '2']);
check('detail timeline occurrence severity', $sel(DS::timeline($devs, 100, 200, false, 400, 4)), []);
check('detail timeline continuous overlap', $sel(DS::timeline($devs, 100, 200, true, 400, null)), ['1', '2', '4']);
check('detail timeline continuous ongoing', $sel(DS::timeline($devs, 200, 300, true, 400, 3)), ['2']);

// 棒の値と一致（Aggregator::aggregate の各Bucket × Severity）
foreach ([false, true] as $cont) {
	$agg = Aggregator::aggregate($devs, $bounds, $cont, 400);

	foreach ($agg as $i => $row) {
		foreach (Aggregator::SEVERITY_KEYS as $sv => $key) {
			check("detail = bar (cont=".(int) $cont.", bucket $i, sev $sv)",
				count(DS::timeline($devs, $bounds[$i], $bounds[$i + 1], $cont, 400, $sv)), $row[$key]);
		}
	}
}

$eh = ['1' => ['10'], '2' => ['10', '11'], '3' => ['11'], '4' => ['10']];
$hg = ['10' => ['g1' => 'G1', 'g2' => 'G2'], '11' => ['g1' => 'G1']];
$devs2 = [$dev('1', 1, null, 3), $dev('2', 2, null, 3), $dev('3', 3, null, 4), $dev('4', 4, null, 3)];
$hn = ['10' => 'A', '11' => 'B'];
$agg_host = D::byHost($devs2, $eh, $hn);
foreach ($agg_host as $row) {
	foreach ([3, 4] as $sv) {
		check("detail host = bar {$row['id']} sev $sv", count(DS::byHost($devs2, $eh, $row['id'], $sv)), $row['counts'][$sv]);
	}
}
check('detail host event ids', $sel(DS::byHost($devs2, $eh, '11', null)), ['2', '3']);

foreach (D::byHostGroup($devs2, $eh, $hg) as $row) {
	foreach ([3, 4] as $sv) {
		check("detail group = bar {$row['id']} sev $sv", count(DS::byHostGroup($devs2, $eh, $hg, $row['id'], $sv)),
			$row['counts'][$sv]);
	}
}
check('detail group unique eventid', $sel(DS::byHostGroup($devs2, $eh, $hg, 'g1', null)), ['1', '2', '3', '4']);

$en = ['1' => 'CPU', '2' => 'CPU', '3' => 'CPU', '4' => 'Disk'];
foreach (D::byEvent($devs2, $eh, $en) as $row) {
	foreach ([3, 4] as $sv) {
		check("detail event = bar {$row['id']} sev $sv", count(DS::byEvent($devs2, $eh, $en, $row['id'], $sv)),
			$row['counts'][$sv]);
	}
}
$r = DS::byEvent($devs2, $eh, $en, 'CPU', null);
check('detail event hosts', array_map(static fn(array $h): array => [$h['hostid'], $h['severity'], $h['count']], $r),
	[['10', 3, 2], ['11', 4, 2]]);
check('detail event severity filter', array_column(DS::byEvent($devs2, $eh, $en, 'CPU', 4), 'hostid'), ['11']);
check('detail event unknown', DS::byEvent($devs2, $eh, $en, 'none', null), []);

// --- 第4フェーズ追加修正: プロトタイプグループ（Event mode）---

// eventGroupKey: Trigger prototype由来なら 'p:'+Prototype ID、そうでなければ 'n:'+Event名にFallback
// （Prototypeが解決できない場合も、$event_proto[eventid]がnullの場合も同じくFallback。§5〜9/§37）。
$proto_names = ['100' => 'CPU load on {#FSNAME}'];
check('eventGroupKey resolved prototype', D::eventGroupKey('1', ['1' => '100'], ['1' => 'CPU load /'], $proto_names),
	'p:100');
check('eventGroupKey unresolved prototype falls back to name',
	D::eventGroupKey('2', ['2' => '999'], ['2' => 'Disk full'], $proto_names), 'n:Disk full');
check('eventGroupKey no prototype falls back to name',
	D::eventGroupKey('3', ['3' => null], ['3' => 'Disk full'], $proto_names), 'n:Disk full');

// byEventGroup: 同一Prototype起点の複数Trigger（=別eventid・別Host）は1つのGroupへ収束する（§5〜9）。
$eh100 = ['1' => ['10'], '2' => ['11']];
$names100 = ['1' => 'CPU load on /', '2' => 'CPU load on /var'];
$r = D::byEventGroup([$ev('1', 3), $ev('2', 4)], $eh100, $names100, ['1' => '100', '2' => '100'], $proto_names);
check('byEventGroup same prototype collapses to one group', count($r), 1);
check('byEventGroup same prototype label', $r[0]['label'], $proto_names['100']);
check('byEventGroup same prototype host total', $r[0]['total'], 2);
check('byEventGroup same prototype is_prototype flag', $r[0]['is_prototype'], true);
check('byEventGroup same prototype event_count', $r[0]['event_count'], 2);

// 異なるPrototypeは別Groupのまま（文字列が似ていてもマージしない）。
$proto_names2 = ['100' => 'CPU load', '200' => 'Memory usage'];
$r = D::byEventGroup([$ev('1', 3), $ev('2', 3)], $eh100, $names100, ['1' => '100', '2' => '200'], $proto_names2);
check('byEventGroup different prototypes stay separate', count($r), 2);
check('byEventGroup different prototypes labels', array_column($r, 'label'), ['CPU load', 'Memory usage']);

// Prototypeを解決できない（通常Trigger）Eventは、Event名単位（byEventと同じ粒度）へ個別にFallbackする。
// 特別な警告フラグ等は持たない = is_prototype: false になるだけ（§37）。
$names3 = ['1' => 'Zabbix agent is not available', '2' => 'Zabbix agent is not available'];
$r = D::byEventGroup([$ev('1', 3), $ev('2', 3)], ['1' => ['10'], '2' => ['11']], $names3,
	['1' => null, '2' => null], []);
check('byEventGroup fallback groups by event name', count($r), 1);
check('byEventGroup fallback label', $r[0]['label'], 'Zabbix agent is not available');
check('byEventGroup fallback is_prototype false', $r[0]['is_prototype'], false);

// 同一Host・同一Prototypeの繰り返しEventは、影響Host数としては1（byEventと同じ考え方）。
// event_count（該当イベント数）はHost単位に集約せず、実Event数のまま個別に数える。
$eh4 = ['1' => ['10'], '2' => ['10'], '3' => ['10']];
$r = D::byEventGroup([$ev('1', 3), $ev('2', 3), $ev('3', 4)], $eh4, [],
	['1' => '100', '2' => '100', '3' => '100'], ['100' => 'CPU load']);
check('byEventGroup same host repeated: host counted once', $r[0]['total'], 1);
// severity 3,3,4 → 同一Hostへの割り当ては最も高いもの（4）が1回だけ（byEvent等と同じ考え方）。
check('byEventGroup same host repeated: highest severity kept', $r[0]['counts'], [0, 0, 0, 0, 1, 0]);
check('byEventGroup same host repeated: event_count keeps all events', $r[0]['event_count'], 3);

check('byEventGroup empty', D::byEventGroup([], [], [], [], []), []);

// Drilldown（プロトタイプグループ）: 実Eventの一覧（Host単位に集約しない）。
// 影響Host数（row['counts']。Host単位の最高Severityで数える）とは異なり、
// Drilldownの一覧は実Event単位のSeverityで絞り込む（§25〜27で採用した設計。両者の値は一致しない場合がある。
// 一致させるのは影響Host数（host_count）の方で、集計と同じbyEventGroup()から直接読むことで保証する）。
$evg5 = ['1' => '100', '2' => '100', '3' => '100', '4' => null];
$proto_names5 = ['100' => 'CPU load'];

check('detail event-group prototype eventids', $sel(DS::byEventGroup($devs2, $evg5, $en, $proto_names5, 'p:100', null)),
	['1', '2', '3']);
check('detail event-group fallback eventids', $sel(DS::byEventGroup($devs2, $evg5, $en, $proto_names5, 'n:Disk', null)),
	['4']);
check('detail event-group severity filter',
	array_column(DS::byEventGroup($devs2, $evg5, $en, $proto_names5, 'p:100', 4), 'eventid'), ['3']);
check('detail event-group unknown key', DS::byEventGroup($devs2, $evg5, $en, $proto_names5, 'none', null), []);

// Popup（Hover Tooltip）用Host名一覧: 上限内はそのまま、超過分は件数のみ（A→Zソート。§21〜24）。
$hn3 = ['1' => 'web01', '2' => 'web02', '3' => 'web03'];
check('hostNameList within limit', D::hostNameList(['3', '1', '2'], $hn3, 5),
	['hosts' => ['web01', 'web02', 'web03'], 'hosts_more' => 0]);

$hn12 = [];
foreach (range(1, 12) as $i) {
	$hn12[(string) $i] = sprintf('host%02d', $i);
}
$list = D::hostNameList(array_reverse(array_keys($hn12)), $hn12, 5);
check('hostNameList truncates to limit and sorts A-Z',
	$list['hosts'], ['host01', 'host02', 'host03', 'host04', 'host05']);
check('hostNameList overflow count', $list['hosts_more'], 7);

// --- 第5フェーズ: Severity集計の追加試験（指示書§6）---
// 同一カテゴリに Host A（Warning・High）/ Host B（Warning）のイベント計3件が存在する場合を検証する。
// Host Aは表示対象イベントのうち最高SeverityであるHighへ1回だけ計上し、
// WarningとHighへ二重計上してはならない（§6.2〜§6.4）。
const SEV_WARNING = 2;
const SEV_HIGH = 4;

$sev_events = [
	$ev('1', SEV_WARNING), // Host A / Event 1
	$ev('2', SEV_HIGH),    // Host A / Event 2
	$ev('3', SEV_WARNING)  // Host B / Event 3
];
$sev_hosts = ['1' => ['A'], '2' => ['A'], '3' => ['B']];
$sev_names = ['1' => 'Event X', '2' => 'Event X', '3' => 'Event X'];

// 全Severity: 影響ホスト数2・該当イベント数3・Warning1台/High1台（Host Aの二重計上なし）。
$r = D::byEvent($sev_events, $sev_hosts, $sev_names);
check('§6.2 all severities: affected host count', $r[0]['total'], 2);
check('§6.2 all severities: severity breakdown (Host A→High only)', $r[0]['counts'], [0, 0, 1, 0, 1, 0]);
check('§6.2 all severities: matching event count', count($sev_events), 3);

// Warningのみ: Highイベントが対象外となり、Host AはWarningへ計上される。
$f = Aggregator::filterBySeverity($sev_events, [SEV_WARNING]);
$r = D::byEvent($f, $sev_hosts, $sev_names);
check('§6.3 warning only: affected host count', $r[0]['total'], 2);
check('§6.3 warning only: severity breakdown', $r[0]['counts'], [0, 0, 2, 0, 0, 0]);
check('§6.3 warning only: matching event count', count($f), 2);

// Highのみ: Host Bのイベントが対象外となり、影響ホストはHost Aのみ。
$f = Aggregator::filterBySeverity($sev_events, [SEV_HIGH]);
$r = D::byEvent($f, $sev_hosts, $sev_names);
check('§6.3 high only: affected host count', $r[0]['total'], 1);
check('§6.3 high only: severity breakdown', $r[0]['counts'], [0, 0, 0, 0, 1, 0]);
check('§6.3 high only: matching event count', count($f), 1);

// Prototype groupでも同じ原則（Hostのユニーク化・最高Severityの割当）が成立することを確認する（§6.5）。
$proto_ids6 = ['1' => 'P1', '2' => 'P1', '3' => 'P1'];
$proto_names6 = ['P1' => 'Prototype X'];
$r = D::byEventGroup($sev_events, $sev_hosts, $sev_names, $proto_ids6, $proto_names6);
check('§6.5 prototype group: affected host count', $r[0]['total'], 2);
check('§6.5 prototype group: severity breakdown (Host A→High only)', $r[0]['counts'], [0, 0, 1, 0, 1, 0]);
check('§6.5 prototype group: matching event count (event_count)', $r[0]['event_count'], 3);

// ---- TriggerSelection（v0.2.0 Phase 1: §9.1〜§9.4 / §14.2〜§14.9 相当のPure-logic検証） ----

// §9.1 Trigger条件なし: 未参照・固定未指定のいずれも null として渡る想定（フィールド既定値 []）。
check('TS §9.1 no condition (null)', TS::resolve(null), ['trigger_filter' => TS::STATE_INACTIVE, 'triggerids' => []]);
check('TS §9.1 no condition (empty array)', TS::resolve([]),
	['trigger_filter' => TS::STATE_INACTIVE, 'triggerids' => []]
);

// §14.2 固定Trigger 1件。
check('TS §14.2 single fixed trigger', TS::resolve(['12345']),
	['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [12345]]
);

// §14.3 固定Trigger複数（3件以上）・重複除去・順序に意味を持たせない（昇順で正規化）。
check('TS §14.3 multiple triggers, dedup, order-independent', TS::resolve(['12347', '12345', '12346', '12345']),
	['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [12345, 12346, 12347]]
);

// §14.4 Navigator broadcastは triggerids[] がそのまま届く想定（int/文字列いずれの要素も受理）。
check('TS §14.4 navigator broadcast (int elements)', TS::resolve([12345, 12346]),
	['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [12345, 12346]]
);

// §14.5/§14.6 Selection変更・解除の追従（呼び出し側は都度 resolve するだけで良いことを確認）。
check('TS §14.5 A -> B', TS::resolve(['1']), ['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [1]]);
check('TS §14.5 A -> A+B', TS::resolve(['1', '2']), ['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [1, 2]]);
check('TS §14.5 A+B -> B', TS::resolve(['2']), ['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [2]]);
check('TS §14.6 A -> none', TS::resolve([]), ['trigger_filter' => TS::STATE_INACTIVE, 'triggerids' => []]);

// §14.9 不正入力: 文字列単体・不正型。
check('TS §14.9 invalid: non-array scalar (string)', TS::resolve('12345'),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: non-array scalar (bool)', TS::resolve(true),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);

// §14.9 不正入力: 配列内の要素が不正（文字列混入・0以下・小数・null混入・入れ子配列）。
check('TS §14.9 invalid: non-numeric element in array', TS::resolve(['12345', 'abc']),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: zero is not a valid trigger id', TS::resolve(['0']),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: negative id', TS::resolve([-5]),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: float element', TS::resolve([12345.5]),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: null element in array', TS::resolve([null]),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);
check('TS §14.9 invalid: nested array element (malformed payload)', TS::resolve([['itemid' => '1']]),
	['trigger_filter' => TS::STATE_INVALID, 'triggerids' => []]
);

// §14.9 存在しないTrigger ID: フォーマットは正しいが実在しないIDも、形式上はactiveとして保持する
// （実在確認はPure-logicの責務外。§9.3どおりPhase 1ではEvent抽出に反映しないため、
// 「指定なし」と無条件に混同しない＝triggeridsが空ではなくactiveであることだけを確認する）。
check('TS §14.9 nonexistent trigger id is still a format-valid active selection', TS::resolve(['999999999']),
	['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [999999999]]
);

// §14.7/§14.8 Host Selectionとの独立性: TriggerSelectionの正規化はtriggerids配列のみに依存し、
// hostids/groupidsの値には一切触れない（resolve()のシグネチャ自体が参照しないことが保証）。
// Host A由来のTrigger XをMetric Navigatorが選択していても、Tree NavigatorのHost B/C/Dとは
// 独立に保持できることを確認する（triggeridsの解決にhostidsが影響しない）。
check('TS §14.8 cross-host trigger held independently of host selection', TS::resolve(['123']),
	['trigger_filter' => TS::STATE_ACTIVE, 'triggerids' => [123]]
);


// ---- TriggerSelectionResolver（v0.2.0 Phase 2: Canonical Trigger 正規化。指示書§17） ----
// 実環境（172.16.32.170, Zabbix 7.0）の継承関係を模した固定データ。Trigger名は Resolver に渡さない
// （名前は判定に使わないため、データとしても持たせない。コメントにだけ記す）。
$tr_db = [
	// ICMP Ping テンプレート "ICMP Ping: Unavailable by ICMP ping"（23176）と、それを継承する Host A/B/C の実体。
	23176 => ['templateid' => 0, 'flags' => 0],
	25143 => ['templateid' => 23176, 'flags' => 0],	// Host A (ns01)
	25171 => ['templateid' => 23176, 'flags' => 0],	// Host B (ntp)
	25184 => ['templateid' => 23176, 'flags' => 0],	// Host C (172.16.0.253)
	// 同名 "Linux: Load average is too high" が2つの別テンプレートに存在する。
	22381 => ['templateid' => 0, 'flags' => 0],	// Linux by Zabbix agent
	22425 => ['templateid' => 0, 'flags' => 0],	// Linux by Zabbix agent active
	25091 => ['templateid' => 22381, 'flags' => 0],	// Host A の Load average（agent）
	25131 => ['templateid' => 22381, 'flags' => 0],	// Host B の Load average（agent）
	22395 => ['templateid' => 22425, 'flags' => 0],	// Host D の Load average（agent active）
	// 多段継承: Base(100) ← Mid(200) ← Host(300)、Base(100) ← Mid(200) ← Upper(250) ← Host(350)
	100 => ['templateid' => 0, 'flags' => 0],
	200 => ['templateid' => 100, 'flags' => 0],
	250 => ['templateid' => 200, 'flags' => 0],
	300 => ['templateid' => 200, 'flags' => 0],
	350 => ['templateid' => 250, 'flags' => 0],
	// Host固有Trigger（継承なし）
	400 => ['templateid' => 0, 'flags' => 0],
	// LLD（Discovery）由来
	500 => ['templateid' => 0, 'flags' => 4],
	// 継承チェーン途中が閲覧不可（601 は返らない）
	600 => ['templateid' => 601, 'flags' => 0],
	// 循環（不正データ）
	700 => ['templateid' => 701, 'flags' => 0],
	701 => ['templateid' => 700, 'flags' => 0]
];
$tr_calls = [];
$tr_fetch = static function (array $ids) use ($tr_db, &$tr_calls): array {
	$tr_calls[] = $ids;

	return array_intersect_key($tr_db, array_flip($ids));
};
// Phase 3: 対象Host上の Trigger（hostid => triggerid[]）。$tr_db と同じ継承関係を持つ実体を各Hostに置く。
// Host 1 = A, 2 = B, 3 = C, 4 = D（agent active / 継承チェーン途中が閲覧不可のTriggerを持つ）, 5 = E（Template未適用）。
$tx_hosts = [
	1 => [25143, 25091, 300, 400, 500],
	2 => [25171, 25131, 350],
	3 => [25184],
	4 => [22395, 600],
	5 => []
];
$tx_host_calls = [];
$tx_fetch_hosts = static function (array $hostids) use ($tx_hosts, $tr_db, &$tx_host_calls): array {
	$tx_host_calls[] = $hostids;
	$ids = [];

	foreach ($hostids as $h) {
		foreach ($tx_hosts[$h] ?? [] as $id) {
			$ids[$id] = true;
		}
	}

	return array_intersect_key($tr_db, $ids);
};
// Selection → Resolver → Expander → Event Selection（WidgetView と同じ順序。キャッシュも共有する）。
$tx_es = static function (array $ids, array $hostids) use ($tr_fetch, $tx_fetch_hosts): array {
	$ts = TS::resolve($ids);
	$cache = new TC($tr_fetch);
	$r = $ts['trigger_filter'] === TS::STATE_ACTIVE ? TR::resolve($ts['triggerids'], [$cache, 'fetch']) : null;
	$x = $r !== null && TR::filterError($r) === null ? TX::expand($r, $hostids, $tx_fetch_hosts, $cache) : null;

	return SourceResolver::resolveEventSelection($ts, $r, $x);
};
$tr_canon = static fn(array $r): array => array_map(
	static fn(array $c): array => [$c['canonical_triggerid'], $c['type'], $c['source_triggerids']], $r['canonical']
);
$tr_entry = static fn(array $r, int $i): array => [$r['entries'][$i]['canonical_triggerid'], $r['entries'][$i]['type'],
	$r['entries'][$i]['status'], $r['entries'][$i]['inheritance_chain'], $r['entries'][$i]['reason']];

// §17.1 単一テンプレート: Host A/B/C の同一テンプレートTriggerは同じ canonical になる。
foreach ([25143, 25171, 25184] as $sid) {
	$r = TR::resolve([$sid], $tr_fetch);
	check("TR §17.1 single template: $sid -> 23176", $tr_entry($r, 0),
		[23176, TR::TYPE_TEMPLATE, TR::STATUS_RESOLVED, [$sid, 23176], null]);
}

// §17.2 Host binding禁止: Resolver の出力・Event Selection に Host 情報は含まれない（Host範囲は resolveTarget のみ）。
$r = TR::resolve([25143], $tr_fetch);
check('TR §17.2 resolution has no host keys', array_keys($r), ['entries', 'canonical']);
check('TR §17.2 entry has no host keys', array_keys($r['entries'][0]),
	['source_triggerid', 'canonical_triggerid', 'type', 'status', 'inheritance_chain', 'reason']);
$es = $tx_es(['25143'], [1]);
check('TR §17.2 event selection carries only objectids (no hostids)', array_keys($es['selectors'][0]), ['objectids']);

// §17.3 多段継承: 2段（Host→Mid→Base）・3段（Host→Upper→Mid→Base）とも最上位の Base が canonical。
$r = TR::resolve([300], $tr_fetch);
check('TR §17.3 two-level', $tr_entry($r, 0), [100, TR::TYPE_TEMPLATE, TR::STATUS_RESOLVED, [300, 200, 100], null]);
$r = TR::resolve([350], $tr_fetch);
check('TR §17.3 three-level', $tr_entry($r, 0),
	[100, TR::TYPE_TEMPLATE, TR::STATUS_RESOLVED, [350, 250, 200, 100], null]);
// 中間テンプレートのTriggerそのものを選んでも同じ最上位へ。
$r = TR::resolve([250], $tr_fetch);
check('TR §17.3 middle template trigger', $tr_entry($r, 0),
	[100, TR::TYPE_TEMPLATE, TR::STATUS_RESOLVED, [250, 200, 100], null]);
// API呼び出しは段ごとにまとめる（source 1回 + 親3段 = 4回）。
$tr_calls = [];
TR::resolve([350, 300], $tr_fetch);
check('TR §17.3 fetch batched per level', $tr_calls, [[300, 350], [200, 250], [100]]);

// §17.4 同名・別テンプレート: "Load average is too high"（agent / agent active）は別 canonical のまま。
$r = TR::resolve([25091, 22395], $tr_fetch);
check('TR §17.4 same name, different templates stay separate', $tr_canon($r),
	[[22381, TR::TYPE_TEMPLATE, [25091]], [22425, TR::TYPE_TEMPLATE, [22395]]]);

// §17.5 重複排除: 同一テンプレート由来の複数Host Triggerは canonical 1件へ。source の追跡は保持する。
$r = TR::resolve([25184, 25143, 25171, 25143], $tr_fetch);
check('TR §17.5 duplicates collapse to one canonical (sources kept)', $tr_canon($r),
	[[23176, TR::TYPE_TEMPLATE, [25143, 25171, 25184]]]);
check('TR §17.5 entries per unique source', array_column($r['entries'], 'source_triggerid'), [25143, 25171, 25184]);

// §17.6 Host固有Trigger: canonical = source、type = exact（他Hostへ名前で対応付けない）。
$r = TR::resolve([400], $tr_fetch);
check('TR §17.6 host-local', $tr_entry($r, 0), [400, TR::TYPE_EXACT, TR::STATUS_RESOLVED, [400], null]);

// §17.7 固定指定（文字列ID）と Navigator（int ID）は同じ Resolver 経路・同じ canonical。
$fixed = TS::resolve(['25143', '22395']);
$nav = TS::resolve([22395, 25143]);
check('TR §17.7 fixed vs navigator: same normalized input', $fixed, $nav);
check('TR §17.7 fixed vs navigator: same canonical', TR::resolve($fixed['triggerids'], $tr_fetch),
	TR::resolve($nav['triggerids'], $tr_fetch));

// §17.8 不正入力: 存在しない・権限なし・チェーン途中閲覧不可・循環・深すぎるチェーン。いずれもエラー・無限ループにならず、
// 全Event表示（絞り込みなし [[]]）へFallbackしない。
$r = TR::resolve([999], $tr_fetch);
check('TR §17.8 not found', $tr_entry($r, 0), [null, null, TR::STATUS_INVALID, [999], TR::REASON_NOT_FOUND]);
check('TR §17.8 not found: no canonical', $r['canonical'], []);
check('TR §17.8 not found: event selection is empty (no fallback to all)',
	SourceResolver::resolveEventSelection(TS::resolve(['999']), $r), ['selected' => true, 'selectors' => [], 'error' => null, 'partial' => false]);
$r = TR::resolve([600], $tr_fetch);
check('TR §17.8 broken chain (parent unavailable)', $tr_entry($r, 0),
	[null, TR::TYPE_TEMPLATE, TR::STATUS_UNRESOLVED, [600, 601], TR::REASON_PARENT_UNAVAILABLE]);
check('TR §17.8 broken chain -> fail-closed (no source fallback)',
	SourceResolver::resolveEventSelection(TS::resolve(['600']), $r),
	['selected' => true, 'selectors' => [], 'error' => TR::REASON_PARENT_UNAVAILABLE, 'partial' => false]);
$r = TR::resolve([700], $tr_fetch);
check('TR §17.8 cycle terminates', $tr_entry($r, 0),
	[null, TR::TYPE_TEMPLATE, TR::STATUS_INVALID, [700, 701, 700], TR::REASON_CYCLE]);
$deep = [1000 => ['templateid' => 0, 'flags' => 0]];
for ($i = 1; $i <= TR::MAX_DEPTH + 5; $i++) {
	$deep[1000 + $i] = ['templateid' => 1000 + $i - 1, 'flags' => 0];
}
$deep_fetch = static fn(array $ids): array => array_intersect_key($deep, array_flip($ids));
$r = TR::resolve([1000 + TR::MAX_DEPTH + 5], $deep_fetch);
check('TR §17.8 too deep chain stops', [$r['entries'][0]['status'], $r['entries'][0]['reason']],
	[TR::STATUS_INVALID, TR::REASON_DEPTH_EXCEEDED]);
$r = TR::resolve([1000 + TR::MAX_DEPTH], $deep_fetch);
check('TR §17.8 chain at MAX_DEPTH still resolves', [$r['entries'][0]['canonical_triggerid'],
	count($r['entries'][0]['inheritance_chain'])], [1000, TR::MAX_DEPTH + 1]);
check('TR §17.8 API failure -> null', TR::resolve([25143], static fn(array $ids) => null), null);
check('TR §17.8 invalid format -> empty event selection',
	SourceResolver::resolveEventSelection(TS::resolve(['abc'])), ['selected' => true, 'selectors' => [], 'error' => null, 'partial' => false]);
// 有効と無効の混在: 有効な source だけで絞り込む（無効分で全体を絞り込みなしにはしない）。
$ts_mix = TS::resolve(['25143', '999']);
$r = TR::resolve($ts_mix['triggerids'], $tr_fetch);
check('TR §17.8 mixed valid/invalid: canonical from valid only', $tr_canon($r),
	[[23176, TR::TYPE_TEMPLATE, [25143]]]);
check('TR §17.8 mixed valid/invalid: event selection uses valid source only',
	$tx_es(['25143', '999'], [1]), ['selected' => true, 'selectors' => [['objectids' => ['25143']]], 'error' => null, 'partial' => false]);

// §17.9 Load average + ICMP ping: canonical 2件、Event Selection は両 source の OR（1つの objectids 集合）。
$ts_two = TS::resolve(['25091', '25143']);
$r = TR::resolve($ts_two['triggerids'], $tr_fetch);
check('TR §17.9 two canonicals', $tr_canon($r),
	[[22381, TR::TYPE_TEMPLATE, [25091]], [23176, TR::TYPE_TEMPLATE, [25143]]]);
check('TR §17.9 OR kept in event selection', $tx_es(['25091', '25143'], [1]),
	['selected' => true, 'selectors' => [['objectids' => ['25091', '25143']]], 'error' => null, 'partial' => false]);

// LLD（Discovery）由来: 正規化しない（canonical = null, type = discovered）。Event 抽出は従来どおり source で行う。
$r = TR::resolve([500], $tr_fetch);
check('TR LLD discovered not normalized', $tr_entry($r, 0),
	[null, TR::TYPE_DISCOVERED, TR::STATUS_UNRESOLVED, [500], TR::REASON_DISCOVERED]);
check('TR LLD event selection keeps source', $tx_es(['500'], [1]),
	['selected' => true, 'selectors' => [['objectids' => ['500']]], 'error' => null, 'partial' => false]);
check('TR empty input', TR::resolve([], $tr_fetch), ['entries' => [], 'canonical' => []]);


// ---- Phase 2 追加修正: 継承元Template参照権限不足（parent_unavailable）の fail-closed（追加修正指示書§4〜§9） ----
// 601 を参照できない継承チェーン（600 → 601 → ?）を「Triggerは参照できるがTemplateを参照できない」状態として使う。
// 対象Hostは source の所有Host（A/B）とし、Phase 2 の source 直接方式と同じ結果になることも併せて確認する（§22）。
$sel = static fn(array $ids): array => $tx_es($ids, [1, 2]);
check('PERM filterError: none for resolved', TR::filterError(TR::resolve([25143, 400], $tr_fetch)), null);
check('PERM filterError: parent_unavailable detected', TR::filterError(TR::resolve([25143, 600], $tr_fetch)),
	TR::REASON_PARENT_UNAVAILABLE);
check('PERM filterError: LLD alone is not a permission error', TR::filterError(TR::resolve([500], $tr_fetch)), null);
check('PERM filterError: not_found alone is not a permission error', TR::filterError(TR::resolve([999], $tr_fetch)),
	null);
// Case A: すべて resolved → 正常（OR）
check('PERM Case A all resolved', $sel(['25143', '25171']),
	['selected' => true, 'selectors' => [['objectids' => ['25143', '25171']]], 'error' => null, 'partial' => false]);
// Case B: resolved + exact → 正常（Phase 3: Template由来は対象Host A/B の両方へ展開、exact は所有Host A のものだけ）
check('PERM Case B resolved + exact', $sel(['25143', '400']),
	['selected' => true, 'selectors' => [['objectids' => ['400', '25143', '25171']]], 'error' => null, 'partial' => false]);
// Case C: resolved + parent_unavailable → Selection全体エラー（解決できた分だけを使わない）
check('PERM Case C resolved + parent_unavailable', $sel(['25143', '600', '25171']),
	['selected' => true, 'selectors' => [], 'error' => TR::REASON_PARENT_UNAVAILABLE, 'partial' => false]);
// Case D: parent_unavailable のみ → エラー
check('PERM Case D parent_unavailable only', $sel(['600']),
	['selected' => true, 'selectors' => [], 'error' => TR::REASON_PARENT_UNAVAILABLE, 'partial' => false]);
// Case E: Selectionなし → 従来どおり絞り込みなし（fail-closedにしない）
check('PERM Case E no selection', $sel([]), ['selected' => true, 'selectors' => [[]], 'error' => null, 'partial' => false]);
// Hostローカル（exact）だけならTemplate権限は不要で正常
check('PERM host-local only', $sel(['400']), ['selected' => true, 'selectors' => [['objectids' => ['400']]], 'error' => null, 'partial' => false]);
// LLD + parent_unavailable の混在もエラー（LLDを名前等で推測して救済しない）
check('PERM LLD + parent_unavailable', $sel(['500', '600']),
	['selected' => true, 'selectors' => [], 'error' => TR::REASON_PARENT_UNAVAILABLE, 'partial' => false]);
// Selection変更・解除で前回のエラー状態を持ち越さない（リクエストごとに再解決するだけ）
check('PERM error -> change to resolvable', $sel(['25143']),
	['selected' => true, 'selectors' => [['objectids' => ['25143', '25171']]], 'error' => null, 'partial' => false]);
check('PERM error -> cleared', $sel([]), ['selected' => true, 'selectors' => [[]], 'error' => null, 'partial' => false]);

// ---- v0.2.0 Phase 3: Canonical Trigger の Host Scope への展開（TriggerScopeExpander） ----
$tx = static function (array $ids, array $hostids) use ($tr_fetch, $tx_fetch_hosts): ?array {
	$cache = new TC($tr_fetch);
	$r = TR::resolve(TS::resolve($ids)['triggerids'], [$cache, 'fetch']);

	return TX::expand($r, $hostids, $tx_fetch_hosts, $cache);
};
$tx_ids = static fn(array $ids, array $hostids): array => $tx($ids, $hostids)['triggerids'];
$objectids = static fn(array $es): array => $es['selectors'][0]['objectids'] ?? [];

// §21 基本Host横断: Host A 上の ICMP（25143）を選択、対象 Host = B/C → B/C の実体だけ。A は含めない。
check('P3 §21 cross-host: A selected, scope B/C', $tx_ids(['25143'], [2, 3]), [25171, 25184]);
check('P3 §21 canonical is the template trigger', $tx(['25143'], [2, 3])['canonical_triggerids'], [23176]);
// §22 Navigator Host = 対象Host: source 直接方式と同じ結果。
check('P3 §22 same host as source', $tx_ids(['25143'], [1]), [25143]);
check('P3 §22 same host as source (multi)', $tx_ids(['25091', '25143'], [1]), [25091, 25143]);
// §23 Template 未適用 Host のみ: 0件・警告なし・全Event へ Fallback しない。
check('P3 §23 template not linked -> empty', $tx_es(['25143'], [5]),
	['selected' => true, 'selectors' => [], 'error' => null, 'partial' => false]);
// §24 混在（B/C は Template あり、E はなし）: B/C だけ。E を理由に警告しない。
check('P3 §24 mixed scope', $tx_es(['25143'], [2, 3, 5]),
	['selected' => true, 'selectors' => [['objectids' => ['25171', '25184']]], 'error' => null, 'partial' => false]);
// §25 複数 Canonical の OR: Load(agent) + ICMP を A で選択、対象 B/C → B の Load・B/C の ICMP。
check('P3 §25 OR of canonicals', $tx_ids(['25091', '25143'], [2, 3]), [25131, 25171, 25184]);
// 同名・別Template（agent active の Load average, Host D）は展開に含めない（名前で対応付けない）。
check('P3 §25 same name other template not projected', $tx_ids(['25091'], [2, 3, 4]), [25131]);
// 同じ実体が複数経路（A と B の ICMP を両方選択 = 同一 canonical）から解決されても重複しない。
check('P3 §25 duplicate canonical collapses', $tx_ids(['25143', '25171'], [1, 2, 3]), [25143, 25171, 25184]);
// §26 Host ローカル exact（Host A の 400）
check('P3 §26 exact: scope A', $tx_ids(['400'], [1]), [400]);
check('P3 §26 exact: scope B/C -> empty', $tx_es(['400'], [2, 3]),
	['selected' => true, 'selectors' => [], 'error' => null, 'partial' => false]);
check('P3 §26 exact: scope A/B/C -> A only', $tx_ids(['400'], [1, 2, 3]), [400]);
// §27 多段 Template 継承: Host A の 300（Host→Mid→Base）を選択し、B の 350（Host→Upper→Mid→Base）へ展開する。
check('P3 §27 multi-level: A(2 levels) -> B(3 levels)', $tx_ids(['300'], [2]), [350]);
check('P3 §27 multi-level: scope A/B', $tx_ids(['350'], [1, 2]), [300, 350]);
// 中間 Template の Trigger（250 = Upper）は最上位 Base（100）へ正規化され、Base 由来の全Host Triggerへ展開される。
check('P3 §27 middle template trigger expands by top canonical', $tx_ids(['250'], [1, 2]), [300, 350]);
// Template 上の Trigger そのもの（継承元なし = exact 判定）を選んでも、それを継承する Host Trigger へ展開される。
check('P3 template-level trigger selected directly', $tx_ids(['23176'], [1, 2, 3, 5]), [25143, 25171, 25184]);
// §12 / Q1=B: 対象Host上に判定不能な Trigger（Host D の 600: 継承元閲覧不可）があれば、確定できた一致だけで取得し partial。
check('P3 §28 indeterminate on target -> partial', $tx_es(['25143'], [2, 4]),
	['selected' => true, 'selectors' => [['objectids' => ['25171']]], 'error' => null, 'partial' => true]);
check('P3 §28 indeterminate counted', [$tx(['25143'], [2, 4])['indeterminate'], $tx(['25143'], [2, 4])['indeterminate_ids']],
	[1, [600]]);
// 一致0件でも判定不能があれば partial（0件だが警告する）。全Eventへは戻さない。
check('P3 §28 indeterminate only -> empty + partial', $tx_es(['25143'], [4]),
	['selected' => true, 'selectors' => [], 'error' => null, 'partial' => true]);
// Selection 自体が parent_unavailable の場合は Phase 2 どおり fail-closed（展開しない）。
check('P3 §28 selection-level fail-closed kept', $tx_es(['25143', '600'], [1, 2, 3, 4]),
	['selected' => true, 'selectors' => [], 'error' => TR::REASON_PARENT_UNAVAILABLE, 'partial' => false]);
// §10 Selection なし: 絞り込みなし（展開しない）。
check('P3 §10 no selection', $tx_es([], [1, 2]), ['selected' => true, 'selectors' => [[]], 'error' => null, 'partial' => false]);
// §14 LLD 由来（Q2=A）: 自分自身が対象Host上にあるときだけ。他Hostへ展開しない。
check('P3 §14 LLD: scope contains owner', $tx_ids(['500'], [1, 2]), [500]);
check('P3 §14 LLD: scope without owner -> empty', $tx_ids(['500'], [2, 3]), []);
// 対象Host なし: 何も取得しない（API も呼ばない）。
$tx_host_calls = [];
check('P3 empty scope', $tx_ids(['25143'], []), []);
check('P3 empty scope: no host trigger fetch', $tx_host_calls, []);
// §29 Selection 変更の追従（リクエストごとに再展開するだけで前回の結果を持ち越さない）。
check('P3 §29 A -> B', [$tx_ids(['25143'], [2, 3]), $tx_ids(['25091'], [2, 3])], [[25171, 25184], [25131]]);
check('P3 §29 A -> A+B -> B', [$tx_ids(['25143'], [2]), $tx_ids(['25143', '25091'], [2]), $tx_ids(['25091'], [2])],
	[[25171], [25131, 25171], [25131]]);
check('P3 §29 host B -> C -> B+C', [$tx_ids(['25143'], [2]), $tx_ids(['25143'], [3]), $tx_ids(['25143'], [2, 3])],
	[[25171], [25184], [25171, 25184]]);
// §16 Batch: 対象Host上の Trigger は Host 数に関係なく1回でまとめて取得し、Selection 解決で取得済みの
// 継承チェーン（23176 等）は再取得しない。
$tr_calls = [];
$tx_host_calls = [];
$cache = new TC($tr_fetch);
$r = TR::resolve([25143], [$cache, 'fetch']);
$x = TX::expand($r, [1, 2, 3, 4, 5], $tx_fetch_hosts, $cache);
check('P3 §16 host triggers fetched once for all hosts', $tx_host_calls, [[1, 2, 3, 4, 5]]);
// Selection: [25143] → [23176]。展開: 対象Host Trigger は取得済み、親は未取得の 200・250・22381・22425・601 と 100 だけ（23176 は再取得しない）。
check('P3 §16 inheritance fetched per level, cached', $tr_calls, [[25143], [23176], [200, 250, 601, 22381, 22425], [100]]);
check('P3 §16 cache call count', $cache->fetch_calls, 4);
check('P3 §16 result', $x['triggerids'], [25143, 25171, 25184]);
// API 失敗は null（呼び出し側で取得失敗表示。部分結果にしない）。
$cache = new TC($tr_fetch);
check('P3 host trigger fetch failure -> null', TX::expand(TR::resolve([25143], [$cache, 'fetch']), [1],
	static fn(array $h) => null, $cache), null);
$cache = new TC(static fn(array $ids) => null);
check('P3 inheritance fetch failure -> null', TX::expand(['entries' => [['source_triggerid' => 25143,
	'canonical_triggerid' => 23176, 'type' => TR::TYPE_TEMPLATE, 'status' => TR::STATUS_RESOLVED,
	'inheritance_chain' => [25143, 23176], 'reason' => null]], 'canonical' => []], [1], $tx_fetch_hosts, $cache), null);
// 展開結果に Host 情報を持ち込まない（Event 条件は objectids のみ。Host 範囲は event.get の hostids が別途担う）。
check('P3 selectors carry only objectids', array_keys($tx_es(['25143'], [2])['selectors'][0]), ['objectids']);

echo "passed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
