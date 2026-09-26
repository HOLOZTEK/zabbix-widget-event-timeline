<?php declare(strict_types = 0);

/**
 * 最終試験: 週境界でのイベント集計（試験A） / タイムゾーンとBucket自動選択（試験B）。
 *
 * 実行: php tests/run_final_tests.php
 *
 * - Zabbix / API に依存しない（BucketPlanner / Aggregator のみ）。
 * - 時刻は全て固定した絶対時刻を使用し、実行時の現在時刻に依存しない。
 * - 各ケースの期待値と実測値を証跡として標準出力へ出す。
 */

require_once __DIR__.'/../includes/BucketPlanner.php';
require_once __DIR__.'/../includes/Aggregator.php';

use Modules\EventTimeline\Includes\Aggregator;
use Modules\EventTimeline\Includes\BucketPlanner as BP;

$pass = 0;
$fail = 0;
$skip = 0;

function check(string $name, $actual, $expected): void {
	global $pass, $fail;

	if ($actual === $expected) {
		$pass++;
		echo "  PASS  $name\n";

		return;
	}

	$fail++;
	echo "  FAIL  $name\n";
	echo "          expected: ".json_encode($expected, JSON_UNESCAPED_UNICODE)."\n";
	echo "          actual:   ".json_encode($actual, JSON_UNESCAPED_UNICODE)."\n";
}

function skipped(string $name, string $reason): void {
	global $skip;

	$skip++;
	echo "  SKIP  $name\n";
	echo "          reason: $reason\n";
}

function note(string $line): void {
	echo "        | $line\n";
}

function section(string $title): void {
	echo "\n=== $title ===\n";
}

function ts(string $s, DateTimeZone $tz): int {
	return (new DateTimeImmutable($s, $tz))->getTimestamp();
}

/** 証跡表示用（曜日・タイムゾーン略称つき）。 */
function show(int $t, DateTimeZone $tz): string {
	return (new DateTimeImmutable('@'.$t))->setTimezone($tz)->format('Y-m-d H:i:s T (D)');
}

/** 比較用（安定した文字列表現）。 */
function at(int $t, DateTimeZone $tz): string {
	return (new DateTimeImmutable('@'.$t))->setTimezone($tz)->format('Y-m-d H:i:s');
}

/** $clock が属するBucketの開始時刻（現地表記）。範囲外は 'OUT'。 */
function bucketOf(array $bounds, int $clock, DateTimeZone $tz): string {
	$i = BP::locate($bounds, $clock);

	return $i < 0 ? 'OUT' : at($bounds[$i], $tz);
}

/** 候補幅ごとの生成Bucket数（$cap を超えるものは '>'.$cap）。 */
function candidateCounts(int $from, int $to, DateTimeZone $tz, string $ws, int $cap = 200): array {
	$out = [];

	foreach (BP::CANDIDATES as [$key, $unit, $n]) {
		$b = BP::generate($from, $to, $unit, $n, $tz, $cap, $ws);
		$out[$key] = $b === null ? '>'.$cap : count($b) - 1;
	}

	return $out;
}

$jst = new DateTimeZone('Asia/Tokyo');
$utc = new DateTimeZone('UTC');
$ny  = new DateTimeZone('America/New_York');

// =====================================================================================
// 試験A: 週境界でのイベント集計
// =====================================================================================

// 共通の表示期間: 2026-03-01 00:00:00 JST 〜 2026-08-27 23:59:59 JST（180日 → 7日Bucketが自動選択される）。
$A_FROM = ts('2026-03-01 00:00:00', $jst);
$A_TO   = ts('2026-08-27 23:59:59', $jst);

$plan_sun = BP::plan($A_FROM, $A_TO, $jst, BP::WEEKDAY_SUN);
$plan_wed = BP::plan($A_FROM, $A_TO, $jst, BP::WEEKDAY_WED);

$bs = $plan_sun['bounds'];
$bw = $plan_wed['bounds'];

section('A-1. 週開始曜日の変更（日曜開始 vs 水曜開始 / Asia/Tokyo）');

note('表示期間 : '.show($A_FROM, $jst).' 〜 '.show($A_TO, $jst));
note('日曜開始 : interval='.$plan_sun['interval']['key'].' Bucket数='.(count($bs) - 1)
	.' 先頭='.show($bs[0], $jst).' 末尾境界='.show($bs[count($bs) - 1], $jst));
note('水曜開始 : interval='.$plan_wed['interval']['key'].' Bucket数='.(count($bw) - 1)
	.' 先頭='.show($bw[0], $jst).' 末尾境界='.show($bw[count($bw) - 1], $jst));

check('A-1.0 7日Bucketが自動選択される（日曜開始）', $plan_sun['interval']['key'], '7d');
check('A-1.0 7日Bucketが自動選択される（水曜開始）', $plan_wed['interval']['key'], '7d');

// (1) 設定した曜日の現地00:00がBucketの開始時刻になる。
$bad_sun = 0;
$bad_wed = 0;

foreach ($bs as $b) {
	$d = (new DateTimeImmutable('@'.$b))->setTimezone($jst);

	if ($d->format('N') !== '7' || $d->format('H:i:s') !== '00:00:00') {
		$bad_sun++;
	}
}

foreach ($bw as $b) {
	$d = (new DateTimeImmutable('@'.$b))->setTimezone($jst);

	if ($d->format('N') !== '3' || $d->format('H:i:s') !== '00:00:00') {
		$bad_wed++;
	}
}

check('A-1.1 日曜開始: 全境界が「日曜の現地00:00」', $bad_sun, 0);
check('A-1.1 水曜開始: 全境界が「水曜の現地00:00」', $bad_wed, 0);
check('A-1.1 日曜開始: 先頭Bucket開始時刻', at($bs[0], $jst), '2026-03-01 00:00:00');
check('A-1.1 水曜開始: 先頭Bucket開始時刻', at($bw[0], $jst), '2026-02-25 00:00:00');

// (2) 翌週の同曜日00:00が終了時刻になる。
$bad_len_sun = 0;
$bad_len_wed = 0;

for ($i = 0, $n = count($bs) - 1; $i < $n; $i++) {
	if ($bs[$i + 1] !== $bs[$i] + 7 * 86400) {
		$bad_len_sun++;
	}
}

for ($i = 0, $n = count($bw) - 1; $i < $n; $i++) {
	if ($bw[$i + 1] !== $bw[$i] + 7 * 86400) {
		$bad_len_wed++;
	}
}

check('A-1.2 日曜開始: 各Bucketの終了=翌週同曜日00:00（JSTはDSTなしのため7日=604800秒）', $bad_len_sun, 0);
check('A-1.2 水曜開始: 各Bucketの終了=翌週同曜日00:00', $bad_len_wed, 0);
check('A-1.2 日曜開始: 先頭Bucketの終了時刻', at($bs[1], $jst), '2026-03-08 00:00:00');
check('A-1.2 水曜開始: 先頭Bucketの終了時刻', at($bw[1], $jst), '2026-03-04 00:00:00');

// (3) 週開始曜日の変更に応じてイベントの所属Bucketが変わる。
$probe = ts('2026-03-04 00:00:00', $jst);   // 水曜 00:00
note('判定用イベント: '.show($probe, $jst));
note('  日曜開始での所属Bucket開始 = '.bucketOf($bs, $probe, $jst).' / index='.BP::locate($bs, $probe));
note('  水曜開始での所属Bucket開始 = '.bucketOf($bw, $probe, $jst).' / index='.BP::locate($bw, $probe));

check('A-1.3 日曜開始での所属Bucket', bucketOf($bs, $probe, $jst), '2026-03-01 00:00:00');
check('A-1.3 水曜開始での所属Bucket', bucketOf($bw, $probe, $jst), '2026-03-04 00:00:00');
check('A-1.3 週開始曜日の変更で所属Bucketが変化する', bucketOf($bs, $probe, $jst) !== bucketOf($bw, $probe, $jst),
	true);

// (4) 変更前後で、同一の表示期間内に含まれるイベントの総数が変化しない。
$a1_events = [];
$seed = 0;

for ($d = 0; $d < 180; $d += 3) {
	$seed++;
	$clock = $A_FROM + $d * 86400 + ($seed % 24) * 3600 + ($seed % 7) * 600;
	$a1_events[] = ['eventid' => (string) (1000 + $seed), 'clock' => $clock, 'end' => null,
		'severity' => $seed % 6];
}

$rows_sun = Aggregator::aggregate($a1_events, $bs);
$rows_wed = Aggregator::aggregate($a1_events, $bw);
$tot_sun = array_sum(array_column($rows_sun, 'total'));
$tot_wed = array_sum(array_column($rows_wed, 'total'));

note('生成イベント数 = '.count($a1_events).' / 日曜開始の集計総数 = '.$tot_sun.' / 水曜開始の集計総数 = '.$tot_wed);

check('A-1.4 イベント総数が週開始曜日で変化しない', [$tot_sun, $tot_wed], [count($a1_events), count($a1_events)]);
check('A-1.4 全イベントがいずれかのBucketへ計上される（欠落なし）', $tot_sun, count($a1_events));

// 共通範囲のイベントIDが一致すること（表示期間の端で集計対象が異なる場合の確認方法）。
$ids_sun = [];
$ids_wed = [];

foreach ($a1_events as $e) {
	if (BP::locate($bs, $e['clock']) >= 0) {
		$ids_sun[] = $e['eventid'];
	}

	if (BP::locate($bw, $e['clock']) >= 0) {
		$ids_wed[] = $e['eventid'];
	}
}

check('A-1.4 集計対象のイベントID集合が一致する', $ids_sun, $ids_wed);

section('A-2. 境界時刻での所属判定（start <= clock < end）');

// 日曜開始: 当週 = 2026-03-08 00:00:00 〜 2026-03-15 00:00:00
// 水曜開始: 当週 = 2026-03-11 00:00:00 〜 2026-03-18 00:00:00
$cases = [
	['sun', $bs, '2026-03-08 00:00:00', '2026-03-15 00:00:00', '2026-03-01 00:00:00'],
	['wed', $bw, '2026-03-11 00:00:00', '2026-03-18 00:00:00', '2026-03-04 00:00:00']
];

foreach ($cases as [$label, $bounds, $week_start_at, $next_week_at, $prev_week_at]) {
	$w0 = ts($week_start_at, $jst);
	$w1 = ts($next_week_at, $jst);

	$events = [
		'E1 週開始の1秒前' => [$w0 - 1, $prev_week_at],
		'E2 週開始ちょうど00:00' => [$w0, $week_start_at],
		'E3 翌週開始の1秒前' => [$w1 - 1, $week_start_at],
		'E4 翌週開始ちょうど00:00' => [$w1, $next_week_at]
	];

	note(strtoupper($label).'開始 / 当週 = '.$week_start_at.' 〜 '.$next_week_at);

	$agg_events = [];
	$i = 0;

	foreach ($events as $name => [$clock, $expected_bucket]) {
		note('  '.$name.' : '.show($clock, $jst).' → Bucket開始 '.bucketOf($bounds, $clock, $jst)
			.' (期待 '.$expected_bucket.')');
		check('A-2 '.$label.' '.$name, bucketOf($bounds, $clock, $jst), $expected_bucket);

		$agg_events[] = ['eventid' => $label.$i++, 'clock' => $clock, 'end' => null, 'severity' => 4];
	}

	// 二重計上・欠落がないこと（合計が4件ちょうど）。
	$rows = Aggregator::aggregate($agg_events, $bounds);
	check('A-2 '.$label.' 境界イベントの二重計上・欠落なし（合計4件）',
		array_sum(array_column($rows, 'total')), 4);
}

section('A-3. 週をまたぐ継続イベント');

$now = ts('2026-04-17 12:00:00', $jst);

// X: 週境界の前に発生し翌週に復旧 / Y: 発生後に復旧せず継続中。
// 「発生前のBucket」が表示期間内に実在するよう、期間中盤の日付を使用する。
$x_start = ts('2026-04-03 10:00:00', $jst);   // 日曜開始の週 03-29〜04-05 / 水曜開始の週 04-01〜04-08
$x_end   = ts('2026-04-07 10:00:00', $jst);   // 日曜開始の週 04-05〜04-12 / 水曜開始の週 04-01〜04-08
$y_start = ts('2026-04-03 10:00:00', $jst);

$cont_events = [
	['eventid' => 'X', 'clock' => $x_start, 'end' => $x_end, 'severity' => 4],
	['eventid' => 'Y', 'clock' => $y_start, 'end' => null, 'severity' => 3]
];

note('X: 発生 '.show($x_start, $jst).' / 復旧 '.show($x_end, $jst));
note('Y: 発生 '.show($y_start, $jst).' / 未復旧（now = '.show($now, $jst).'）');

$occ = Aggregator::aggregate($cont_events, $bs, false);
$con = Aggregator::aggregate($cont_events, $bs, true, $now);

/** 指定した現地開始時刻のBucketの件数。該当Bucketが存在しなければ 'MISSING'。 */
$tot = static function (array $rows, string $at_local) use ($jst) {
	foreach ($rows as $r) {
		if (at($r['bucket_start'], $jst) === $at_local) {
			return $r['total'];
		}
	}

	return 'MISSING';
};

$weeks = ['2026-03-22 00:00:00', '2026-03-29 00:00:00', '2026-04-05 00:00:00', '2026-04-12 00:00:00',
	'2026-04-19 00:00:00'];

foreach ($weeks as $w) {
	note('日曜開始 週 '.$w.' : 発生数='.$tot($occ, $w).' 継続数='.$tot($con, $w));
}

// 発生数モード: 発生時刻のBucketにのみ計上される。
check('A-3 発生数: 03-29週に2件（X,Yとも発生）', $tot($occ, '2026-03-29 00:00:00'), 2);
check('A-3 発生数: 04-05週は0件（復旧しただけのBucketへは計上しない）',
	$tot($occ, '2026-04-05 00:00:00'), 0);
check('A-3 発生数: 04-12週は0件', $tot($occ, '2026-04-12 00:00:00'), 0);

// 継続数モード: 重なる各Bucketへ1回ずつ計上される。
check('A-3 継続数: 03-29週に2件（X,Yとも継続）', $tot($con, '2026-03-29 00:00:00'), 2);
check('A-3 継続数: 04-05週に2件（Xは04-07復旧・Yは継続中）', $tot($con, '2026-04-05 00:00:00'), 2);
check('A-3 継続数: 04-12週に1件（Yのみ。nowは04-17）', $tot($con, '2026-04-12 00:00:00'), 1);
check('A-3 継続数: 04-19週は0件（now以降へは計上しない）', $tot($con, '2026-04-19 00:00:00'), 0);

// 発生前のBucketへ計上されないこと（表示期間内に実在する発生前Bucketで確認）。
check('A-3 発生数: 発生前の03-22週は0件', $tot($occ, '2026-03-22 00:00:00'), 0);
check('A-3 継続数: 発生前の03-22週は0件', $tot($con, '2026-03-22 00:00:00'), 0);
check('A-3 継続数: さらに前の03-01週も0件', $tot($con, '2026-03-01 00:00:00'), 0);

// 同一Bucket内での重複計上がないこと（1Bucketあたり最大でもイベント数）。
$dup = 0;

foreach ($con as $r) {
	if ($r['total'] > count($cont_events)) {
		$dup++;
	}
}

check('A-3 同一Bucket内での重複計上なし', $dup, 0);

// 継続数は発生数を必ず含む（各Bucketで 継続数 >= 発生数）。
$violate = 0;

foreach ($occ as $i => $r) {
	if ($con[$i]['total'] < $r['total']) {
		$violate++;
	}
}

check('A-3 全Bucketで 継続数 >= 発生数', $violate, 0);

// 週開始曜日を変えてもイベントの発生・復旧時刻そのものは変化しない。
$occ_w = Aggregator::aggregate($cont_events, $bw, false);
$con_w = Aggregator::aggregate($cont_events, $bw, true, $now);

check('A-3 週開始曜日を変えてもイベントのclock/endは不変',
	[$cont_events[0]['clock'], $cont_events[0]['end'], $cont_events[1]['clock'], $cont_events[1]['end']],
	[$x_start, $x_end, $y_start, null]);
check('A-3 水曜開始でも発生数の総数は同じ（2件）', array_sum(array_column($occ_w, 'total')), 2);

foreach (['2026-03-25 00:00:00', '2026-04-01 00:00:00', '2026-04-08 00:00:00', '2026-04-15 00:00:00',
		'2026-04-22 00:00:00'] as $w) {
	note('水曜開始 週 '.$w.' : 発生数='.$tot($occ_w, $w).' 継続数='.$tot($con_w, $w));
}

check('A-3 水曜開始 発生数: 04-01週に2件', $tot($occ_w, '2026-04-01 00:00:00'), 2);
check('A-3 水曜開始 継続数: 03-25週は0件（発生前）', $tot($con_w, '2026-03-25 00:00:00'), 0);
check('A-3 水曜開始 継続数: 04-01週に2件（X発生04-03・復旧04-07とも同一週内）',
	$tot($con_w, '2026-04-01 00:00:00'), 2);
check('A-3 水曜開始 継続数: 04-08週に1件（Xは復旧済みのためYのみ）',
	$tot($con_w, '2026-04-08 00:00:00'), 1);
check('A-3 水曜開始 継続数: 04-15週に1件（Yのみ、nowは04-17）',
	$tot($con_w, '2026-04-15 00:00:00'), 1);
check('A-3 水曜開始 継続数: 04-22週は0件（now以降）', $tot($con_w, '2026-04-22 00:00:00'), 0);

// =====================================================================================
// 試験B: タイムゾーンとBucket自動選択
// =====================================================================================

section('B-1. タイムゾーンの反映（UTC / Asia/Tokyo / America/New_York）');

// 同一の絶対時刻のイベント（2026-03-03 12:00:00 UTC）。
$abs = ts('2026-03-03 12:00:00', $utc);

note('イベントの絶対時刻 = '.$abs.' ('.show($abs, $utc).')');
note('  UTC表記      : '.show($abs, $utc));
note('  Asia/Tokyo   : '.show($abs, $jst));
note('  America/NY   : '.show($abs, $ny));

$tz_expect = [
	'UTC'              => [$utc, '2026-03-01 00:00:00', '2026-03-08 00:00:00'],
	'Asia/Tokyo'       => [$jst, '2026-03-01 00:00:00', '2026-03-08 00:00:00'],
	'America/New_York' => [$ny,  '2026-03-01 00:00:00', '2026-03-08 00:00:00']
];

$starts_utc = [];

foreach ($tz_expect as $name => [$tz, $exp_start, $exp_end]) {
	$from = ts('2026-01-01 00:00:00', $tz);
	$to   = ts('2026-06-30 00:00:00', $tz);
	$plan = BP::plan($from, $to, $tz, BP::WEEKDAY_SUN);
	$i = BP::locate($plan['bounds'], $abs);
	$start = $plan['bounds'][$i];
	$end = $plan['bounds'][$i + 1];
	$starts_utc[$name] = $start;

	note($name.' : interval='.$plan['interval']['key'].' 所属Bucket '.at($start, $tz).' 〜 '.at($end, $tz)
		.' (UTC絶対値 '.$start.')');

	check('B-1 '.$name.': 週境界は現地の日曜00:00', at($start, $tz), $exp_start);
	check('B-1 '.$name.': 翌週境界も現地00:00', at($end, $tz), $exp_end);

	$d = (new DateTimeImmutable('@'.$start))->setTimezone($tz);
	check('B-1 '.$name.': 境界の曜日が日曜', $d->format('N'), '7');
}

check('B-1 イベントの絶対時刻はタイムゾーンによらず不変', $abs, ts('2026-03-03 12:00:00', $utc));
check('B-1 週境界の絶対時刻はタイムゾーンごとに異なる',
	count(array_unique(array_values($starts_utc))), 3);
note('週境界の絶対時刻: UTC='.$starts_utc['UTC'].' / JST='.$starts_utc['Asia/Tokyo']
	.' / NY='.$starts_utc['America/New_York']);
check('B-1 JSTの週境界はUTCの9時間前（現地00:00が早く訪れる）',
	$starts_utc['UTC'] - $starts_utc['Asia/Tokyo'], 9 * 3600);
check('B-1 NYの週境界はUTCの5時間後（EST=UTC-5）',
	$starts_utc['America/New_York'] - $starts_utc['UTC'], 5 * 3600);

section('B-2. DST切替週（America/New_York 2026）');

// 2026年の米国DST: 開始 3/8(第2日曜) 02:00、終了 11/1(第1日曜) 02:00。
$dst_cases = [
	['DST開始・日曜開始', BP::WEEKDAY_SUN, '2026-03-10 12:00:00', '2026-03-08 00:00:00', '2026-03-15 00:00:00', 167],
	['DST終了・日曜開始', BP::WEEKDAY_SUN, '2026-11-03 12:00:00', '2026-11-01 00:00:00', '2026-11-08 00:00:00', 169],
	['DST開始・水曜開始', BP::WEEKDAY_WED, '2026-03-08 12:00:00', '2026-03-04 00:00:00', '2026-03-11 00:00:00', 167],
	['DST終了・水曜開始', BP::WEEKDAY_WED, '2026-11-01 12:00:00', '2026-10-28 00:00:00', '2026-11-04 00:00:00', 169]
];

foreach ($dst_cases as [$label, $ws, $probe_at, $exp_start, $exp_end, $exp_hours]) {
	$from = ts('2026-01-01 00:00:00', $ny);
	$to   = ts('2026-12-30 00:00:00', $ny);
	$plan = BP::plan($from, $to, $ny, $ws);

	check('B-2 '.$label.': 7日Bucketが選択される', $plan['interval']['key'], '7d');

	$p = ts($probe_at, $ny);
	$i = BP::locate($plan['bounds'], $p);
	$start = $plan['bounds'][$i];
	$end = $plan['bounds'][$i + 1];
	$hours = intdiv($end - $start, 3600);

	note($label.' : '.show($start, $ny).' 〜 '.show($end, $ny).' = '.$hours.'時間 ('.($end - $start).'秒)');

	check('B-2 '.$label.': Bucket開始', at($start, $ny), $exp_start);
	check('B-2 '.$label.': Bucket終了', at($end, $ny), $exp_end);
	check('B-2 '.$label.': 実経過時間', $hours, $exp_hours);
	check('B-2 '.$label.': 固定168時間の区切りではない', $end - $start === 168 * 3600, false);
	check('B-2 '.$label.': 暦上は7日間で1Bucket',
		(new DateTimeImmutable('@'.$start))->setTimezone($ny)->modify('+7 day')->getTimestamp(), $end);
}

// DST切替時刻の前後にイベントを配置し、欠落・重複がないことを確認する。
$from_dst = ts('2026-03-01 00:00:00', $ny);
$to_dst   = ts('2026-06-30 00:00:00', $ny);
$plan_dst = BP::plan($from_dst, $to_dst, $ny, BP::WEEKDAY_SUN);

$dst_events = [
	['eventid' => 'D1', 'clock' => ts('2026-03-08 01:59:59', $ny), 'end' => null, 'severity' => 4],
	['eventid' => 'D2', 'clock' => ts('2026-03-08 03:00:00', $ny), 'end' => null, 'severity' => 4],
	['eventid' => 'D3', 'clock' => ts('2026-03-08 03:00:01', $ny), 'end' => null, 'severity' => 4]
];

foreach ($dst_events as $e) {
	note('DST開始前後 '.$e['eventid'].' : '.show($e['clock'], $ny).' → Bucket '
		.bucketOf($plan_dst['bounds'], $e['clock'], $ny));
}

$rows_dst = Aggregator::aggregate($dst_events, $plan_dst['bounds']);
check('B-2 DST開始前後のイベントが欠落・重複なく集計される',
	array_sum(array_column($rows_dst, 'total')), 3);
check('B-2 DST開始前後のイベントは同一週Bucketに属する',
	[bucketOf($plan_dst['bounds'], $dst_events[0]['clock'], $ny),
		bucketOf($plan_dst['bounds'], $dst_events[2]['clock'], $ny)],
	['2026-03-08 00:00:00', '2026-03-08 00:00:00']);

// DST終了日は現地01:30が2回訪れる（EDT/EST）。絶対時刻は異なるが同一週Bucketに属する。
$from_end = ts('2026-10-01 00:00:00', $ny);
$to_end   = ts('2026-12-30 00:00:00', $ny);
$plan_end = BP::plan($from_end, $to_end, $ny, BP::WEEKDAY_SUN);

$edt = ts('2026-11-01 01:30:00', $ny);          // 先に来る方（PHPはEDTを選ぶ）
$est = $edt + 3600;                              // 1時間後＝繰り返された01:30（EST）

note('DST終了日の重複時刻 : '.show($edt, $ny).' / '.show($est, $ny));

$amb_events = [
	['eventid' => 'R1', 'clock' => $edt, 'end' => null, 'severity' => 4],
	['eventid' => 'R2', 'clock' => $est, 'end' => null, 'severity' => 4]
];

$rows_amb = Aggregator::aggregate($amb_events, $plan_end['bounds']);
check('B-2 DST終了日の重複時刻も欠落・重複なく集計される',
	array_sum(array_column($rows_amb, 'total')), 2);
check('B-2 DST終了日の重複時刻は同一週Bucketに属する',
	[bucketOf($plan_end['bounds'], $edt, $ny), bucketOf($plan_end['bounds'], $est, $ny)],
	['2026-11-01 00:00:00', '2026-11-01 00:00:00']);

section('B-3. Bucket自動選択');

// 表示期間の長さ別に、候補幅ごとの生成本数と採用結果を出す。
$auto_cases = [
	['1時間',   '2026-04-01 00:00:00', '2026-04-01 01:00:00', '1m'],
	['3時間',   '2026-04-01 00:00:00', '2026-04-01 03:00:00', '5m'],
	['1日',     '2026-04-01 00:00:00', '2026-04-02 00:00:00', '15m'],
	['7日',     '2026-04-01 00:00:00', '2026-04-08 00:00:00', '2h'],
	['30日',    '2026-04-01 00:00:00', '2026-05-01 00:00:00', '12h'],
	['60日',    '2026-04-01 00:00:00', '2026-05-31 00:00:00', '1d'],
	['180日',   '2026-03-01 00:00:00', '2026-08-28 00:00:00', '7d'],
	['2年',     '2025-01-01 00:00:00', '2026-12-31 00:00:00', '1M']
];

foreach ($auto_cases as [$label, $from_at, $to_at, $expected_key]) {
	$from = ts($from_at, $jst);
	$to   = ts($to_at, $jst);
	$plan = BP::plan($from, $to, $jst, BP::WEEKDAY_SUN);
	$counts = candidateCounts($from, $to, $jst, BP::WEEKDAY_SUN);
	$n = count($plan['bounds']) - 1;

	$parts = [];

	foreach ($counts as $k => $v) {
		$parts[] = $k.'='.$v;
	}

	note($label.' : 採用='.$plan['interval']['key'].' Bucket数='.$n);
	note('    候補別本数: '.implode(' ', $parts));

	check('B-3.1 '.$label.': 採用Bucket数が100本以下', $n <= BP::MAX_BUCKETS, true);
	check('B-3.2 '.$label.': 採用された候補幅', $plan['interval']['key'], $expected_key);

	// 採用候補より小さい候補は、いずれも100本を超えていること。
	$over = true;

	foreach (BP::CANDIDATES as [$k, $unit, $cnt]) {
		if ($k === $plan['interval']['key']) {
			break;
		}

		if (BP::generate($from, $to, $unit, $cnt, $jst, BP::MAX_BUCKETS, BP::WEEKDAY_SUN) !== null) {
			$over = false;
		}
	}

	check('B-3.2 '.$label.': より小さい候補は全て100本超（最初に収まる候補が選ばれる）', $over, true);
}

// (3) 空のBucketも本数判定に含まれる。
$from_empty = ts('2026-04-01 00:00:00', $jst);
$to_empty   = ts('2026-05-01 00:00:00', $jst);
$plan_empty = BP::plan($from_empty, $to_empty, $jst, BP::WEEKDAY_SUN);
$rows_empty = Aggregator::aggregate([['eventid' => 'S', 'clock' => $from_empty + 100, 'end' => null,
	'severity' => 4]], $plan_empty['bounds']);
$empty_rows = 0;

foreach ($rows_empty as $r) {
	if ($r['total'] === 0) {
		$empty_rows++;
	}
}

note('30日期間・イベント1件 : Bucket数='.count($rows_empty).' / うち空Bucket='.$empty_rows);
check('B-3.3 イベント0件のBucketも本数に含まれる', count($rows_empty), count($plan_empty['bounds']) - 1);
check('B-3.3 空Bucketが本数判定から除外されていない', $empty_rows, count($rows_empty) - 1);

// (4) 週開始曜日の違いによる自然境界の変化が判定に反映される。
// 日曜00:00開始・700日間 → 日曜開始は7dでちょうど100本、水曜開始は境界が4日手前へずれ101本となり1Mへ落ちる。
$w_from = ts('2026-03-01 00:00:00', $jst);            // 日曜
$w_to   = $w_from + 700 * 86400 - 1;
$plan_ws_sun = BP::plan($w_from, $w_to, $jst, BP::WEEKDAY_SUN);
$plan_ws_wed = BP::plan($w_from, $w_to, $jst, BP::WEEKDAY_WED);
$cnt_7d_sun = count(BP::generate($w_from, $w_to, BP::UNIT_WEEK, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_SUN)) - 1;
$cnt_7d_wed = count(BP::generate($w_from, $w_to, BP::UNIT_WEEK, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_WED)) - 1;

note('700日期間（'.at($w_from, $jst).' 〜 '.at($w_to, $jst).'）');
note('  7d候補の生成本数: 日曜開始='.$cnt_7d_sun.' / 水曜開始='.$cnt_7d_wed);
note('  採用: 日曜開始='.$plan_ws_sun['interval']['key'].' / 水曜開始='.$plan_ws_wed['interval']['key']);

check('B-3.4 日曜開始: 7d候補は100本', $cnt_7d_sun, 100);
check('B-3.4 水曜開始: 7d候補は101本（自然境界が4日手前へずれる）', $cnt_7d_wed, 101);
check('B-3.4 日曜開始では7dが採用される', $plan_ws_sun['interval']['key'], '7d');
check('B-3.4 水曜開始では7dが不採用となり次の候補が採用される', $plan_ws_wed['interval']['key'], '1M');

// (5) DSTによる23時間・25時間の日を固定秒数で処理しない。
$d_from = ts('2026-03-01 00:00:00', $ny);
$d_to   = ts('2026-04-15 00:00:00', $ny);
$day_bounds = BP::generate($d_from, $d_to, BP::UNIT_DAY, 1, $ny, PHP_INT_MAX, BP::WEEKDAY_SUN);
$dst_day_len = null;
$std_day_len = null;

for ($i = 0, $n = count($day_bounds) - 1; $i < $n; $i++) {
	$len = $day_bounds[$i + 1] - $day_bounds[$i];

	if (at($day_bounds[$i], $ny) === '2026-03-08 00:00:00') {
		$dst_day_len = $len;
	}

	if (at($day_bounds[$i], $ny) === '2026-03-09 00:00:00') {
		$std_day_len = $len;
	}
}

$n_from = ts('2026-10-15 00:00:00', $ny);
$n_to   = ts('2026-11-15 00:00:00', $ny);
$day_bounds2 = BP::generate($n_from, $n_to, BP::UNIT_DAY, 1, $ny, PHP_INT_MAX, BP::WEEKDAY_SUN);
$dst_day_len2 = null;

for ($i = 0, $n = count($day_bounds2) - 1; $i < $n; $i++) {
	if (at($day_bounds2[$i], $ny) === '2026-11-01 00:00:00') {
		$dst_day_len2 = $day_bounds2[$i + 1] - $day_bounds2[$i];
	}
}

note('NY 2026-03-08（DST開始日）の長さ = '.$dst_day_len.'秒 ('.intdiv((int) $dst_day_len, 3600).'時間)');
note('NY 2026-03-09（通常日）の長さ     = '.$std_day_len.'秒 ('.intdiv((int) $std_day_len, 3600).'時間)');
note('NY 2026-11-01（DST終了日）の長さ = '.$dst_day_len2.'秒 ('.intdiv((int) $dst_day_len2, 3600).'時間)');

check('B-3.5 DST開始日の1日Bucketは23時間', $dst_day_len, 23 * 3600);
check('B-3.5 DST終了日の1日Bucketは25時間', $dst_day_len2, 25 * 3600);
check('B-3.5 通常日の1日Bucketは24時間', $std_day_len, 24 * 3600);
check('B-3.5 固定86400秒で処理していない', $dst_day_len === 86400 || $dst_day_len2 === 86400, false);

// (6) 日・週・月の各候補が現地暦に従って生成される（月は28/30/31日で可変）。
$m_from = ts('2026-01-01 00:00:00', $jst);
$m_to   = ts('2026-12-31 00:00:00', $jst);
$m_bounds = BP::generate($m_from, $m_to, BP::UNIT_MONTH, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_SUN);
$m_days = [];

for ($i = 0, $n = count($m_bounds) - 1; $i < $n; $i++) {
	$m_days[] = intdiv($m_bounds[$i + 1] - $m_bounds[$i], 86400);
}

note('2026年の月Bucket日数: '.implode(',', $m_days));
check('B-3.6 月Bucketは現地暦に従う（1〜12月の日数）', $m_days,
	[31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]);

$day_first = at($day_bounds[0], $ny);
check('B-3.6 日Bucketは現地00:00基準', substr($day_first, 11), '00:00:00');
check('B-3.6 週Bucketは現地00:00基準', substr(at($bs[0], $jst), 11), '00:00:00');

skipped('B-3.6 2日Bucket候補',
	'実装の候補一覧（BucketPlanner::CANDIDATES）に2日幅の候補が存在しないため試験対象外。'
	.'2日幅は自然境界（どの日を起点に2日区切りとするか）を暦上定義できないことを理由に意図的に除外されている'
	.'（BucketPlanner.php の CANDIDATES 定義コメント参照）。指示書B-3(6)の「2日」候補との差異として報告する。');

// 表示期間の両端に部分的なBucketが含まれるケース。
$p_from = ts('2026-04-01 10:33:20', $jst);
$p_to   = ts('2026-04-01 20:15:40', $jst);
$plan_p = BP::plan($p_from, $p_to, $jst, BP::WEEKDAY_SUN);
$pb = $plan_p['bounds'];

note('両端が部分Bucketになる期間 : '.at($p_from, $jst).' 〜 '.at($p_to, $jst));
note('  採用='.$plan_p['interval']['key'].' 先頭境界='.at($pb[0], $jst)
	.' 末尾境界='.at($pb[count($pb) - 1], $jst).' Bucket数='.(count($pb) - 1));

check('B-3 部分Bucket: 先頭境界は表示期間開始より前', $pb[0] < $p_from, true);
check('B-3 部分Bucket: 末尾境界は表示期間終了より後', $pb[count($pb) - 1] > $p_to, true);

// 「表示期間÷Bucket幅」による単純判定に戻っていないことの確認。
// 期間=ちょうど100時間だが、開始が時境界に揃っていないため1h候補は101本となり不採用になる。
$nd_from = ts('2026-04-01 00:30:00', $jst);
$nd_to   = $nd_from + 100 * 3600;
$cnt_1h = count(BP::generate($nd_from, $nd_to, BP::UNIT_HOUR, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_SUN)) - 1;
$plan_nd = BP::plan($nd_from, $nd_to, $jst, BP::WEEKDAY_SUN);

note('期間=ちょうど100時間（開始 '.at($nd_from, $jst).'）');
note('  単純計算（期間÷1時間）= 100 本 / 実際に境界生成した1h候補 = '.$cnt_1h.' 本');
note('  採用 = '.$plan_nd['interval']['key'].' （'.(count($plan_nd['bounds']) - 1).' 本）');

check('B-3 単純な期間÷幅では100本だが実生成では101本', $cnt_1h, 101);
check('B-3 そのため1h候補は不採用となり2hが採用される', $plan_nd['interval']['key'], '2h');

section('B-4. 100本の境界試験');

// ちょうど100本: 1分Bucket × 100本（00:00:00 〜 01:39:59）。
$b4_from = ts('2026-04-01 00:00:00', $jst);
$b4_to_100 = ts('2026-04-01 01:39:59', $jst);
$b4_to_101 = ts('2026-04-01 01:40:00', $jst);

$cnt100 = count(BP::generate($b4_from, $b4_to_100, BP::UNIT_MINUTE, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_SUN)) - 1;
$cnt101 = count(BP::generate($b4_from, $b4_to_101, BP::UNIT_MINUTE, 1, $jst, PHP_INT_MAX, BP::WEEKDAY_SUN)) - 1;

$plan100 = BP::plan($b4_from, $b4_to_100, $jst, BP::WEEKDAY_SUN);
$plan101 = BP::plan($b4_from, $b4_to_101, $jst, BP::WEEKDAY_SUN);

note('100本ケース : '.at($b4_from, $jst).' 〜 '.at($b4_to_100, $jst)
	.' / 1m候補='.$cnt100.'本 → 採用='.$plan100['interval']['key']
	.'（'.(count($plan100['bounds']) - 1).'本）');
note('101本ケース : '.at($b4_from, $jst).' 〜 '.at($b4_to_101, $jst)
	.' / 1m候補='.$cnt101.'本 → 採用='.$plan101['interval']['key']
	.'（'.(count($plan101['bounds']) - 1).'本）');

check('B-4 1m候補がちょうど100本になる期間', $cnt100, 100);
check('B-4 100本のときは当該候補を採用する', $plan100['interval']['key'], '1m');
check('B-4 採用時のBucket数は100本', count($plan100['bounds']) - 1, 100);

check('B-4 1m候補が101本になる期間', $cnt101, 101);
check('B-4 101本のときは次の候補幅を評価する', $plan101['interval']['key'], '5m');
check('B-4 次候補採用時のBucket数は100本以下', count($plan101['bounds']) - 1 <= BP::MAX_BUCKETS, true);

// 週開始曜日の変更によりBucket数が100本と101本の間で変化する条件（B-3.4と同じ700日ケース）。
check('B-4 週開始曜日の変更で100本↔101本が切り替わる', [$cnt_7d_sun, $cnt_7d_wed], [100, 101]);
check('B-4 その結果、採用される候補幅も切り替わる',
	[$plan_ws_sun['interval']['key'], $plan_ws_wed['interval']['key']], ['7d', '1M']);

echo "\n";
echo "==================================================\n";
echo "passed: $pass, failed: $fail, skipped: $skip\n";
echo "==================================================\n";

exit($fail === 0 ? 0 : 1);
