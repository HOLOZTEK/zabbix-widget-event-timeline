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

require_once __DIR__.'/stubs.php';

use Modules\EventTimeline\Includes\Aggregator;
use Modules\EventTimeline\Includes\BucketPlanner;
use Modules\EventTimeline\Includes\DetailSelector as DS;
use Modules\EventTimeline\Includes\DimensionAggregator as D;
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
check('event fixed empty', SourceResolver::resolveEventSelection([]), ['selected' => true, 'selectors' => [[]]]);

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

echo "passed: $pass, failed: $fail\n";
exit($fail === 0 ? 0 : 1);
