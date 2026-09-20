<?php declare(strict_types = 0);

/**
 * 依存なしの単体テスト。実行: php tests/run_tests.php
 * Zabbix / API には依存しない（BucketPlanner / Aggregator / TimeFormatter / SourceResolver::mergeUnique）。
 */

require_once __DIR__.'/../includes/BucketPlanner.php';
require_once __DIR__.'/../includes/Aggregator.php';
require_once __DIR__.'/../includes/TimeFormatter.php';
require_once __DIR__.'/../includes/SourceResolver.php';

require_once __DIR__.'/stubs.php';

use Modules\EventTimeline\Includes\Aggregator;
use Modules\EventTimeline\Includes\BucketPlanner;
use Modules\EventTimeline\Includes\SourceResolver;
use Modules\EventTimeline\Includes\TimeFormatter;

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
check('event fixed', SourceResolver::resolveEventSelection(['triggerids' => ['5', '5', '7']]),
	['selected' => true, 'selectors' => [['objectids' => ['5', '7']]]]);
check('event fixed empty', SourceResolver::resolveEventSelection([])['selected'], false);
check('event widget', SourceResolver::resolveEventSelection(['event_source' => 1, 'en_triggerids' => [9], 'triggerids' => [1]]),
	['selected' => true, 'selectors' => [['objectids' => ['9']]]]);
check('event widget empty', SourceResolver::resolveEventSelection(['event_source' => 1, 'triggerids' => [1]])['selected'], false);

echo "passed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
