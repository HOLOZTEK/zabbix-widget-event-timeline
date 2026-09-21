<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;
use CSeverityHelper;
use DateTimeZone;

use Modules\EventTimeline\Includes\{
	Aggregator,
	DimensionAggregator,
	BucketPlanner,
	EventFetcher,
	SourceResolver,
	TimeFormatter,
	WidgetForm
};

/**
 * データフロー: Source resolution → Event retrieval → Filtering → Aggregation → (JSで)Rendering。
 * JavaScript へは Event 配列ではなく、Bucket 集計済みデータ（chart_data）だけを渡す。
 */
class WidgetView extends CControllerDashboardWidgetView {

	// Tooltipの詳細一覧に載せる最大Event数（Bucket × Severity あたり）。
	private const DETAIL_LIMIT = 10;

	protected function doAction(): void {
		$fields = $this->fields_values;

		$time_period = $fields['time_period'] ?? [];
		$from = (int) ($time_period['from_ts'] ?? (time() - SEC_PER_DAY));
		$to = (int) ($time_period['to_ts'] ?? time());

		$max_events = max(WidgetForm::MAX_EVENTS_MIN,
			min(WidgetForm::MAX_EVENTS_MAX, (int) ($fields['max_events'] ?? WidgetForm::MAX_EVENTS_DEFAULT))
		);

		// Filter / Count Mode: 設定値（configured）が初期値。表示画面から runtime 値が届いていればそちらを使う。
		// runtime 値は設定を書き換えない（リクエストごとにJSが送る一時的な値）。
		$configured = [
			'severities' => self::ints($fields['severities'] ?? Aggregator::SEVERITIES),
			'ack' => self::ints($fields['ack_states'] ?? [WidgetForm::ACK_ACKNOWLEDGED, WidgetForm::ACK_UNACKNOWLEDGED]),
			'count_mode' => (int) ($fields['count_mode'] ?? WidgetForm::COUNT_OCCURRENCE),
			'mode' => self::mode($fields['display_mode'] ?? WidgetForm::MODE_TIMELINE),
			'sort' => self::sortKey($fields['sort_order'] ?? DimensionAggregator::SORT_COUNT)
		];

		if (!empty($fields['rt_active'])) {
			$runtime = [
				'severities' => self::ints($fields['rt_severities'] ?? []),
				'ack' => self::ints($fields['rt_ack'] ?? []),
				'count_mode' => (int) ($fields['rt_count_mode'] ?? WidgetForm::COUNT_OCCURRENCE),
				'mode' => self::mode($fields['rt_mode'] ?? WidgetForm::MODE_TIMELINE),
				'sort' => self::sortKey($fields['rt_sort'] ?? DimensionAggregator::SORT_COUNT)
			];
		}
		else {
			$runtime = $configured;
		}

		$continuous = $runtime['count_mode'] === WidgetForm::COUNT_CONTINUOUS;

		$page_size = in_array((int) ($fields['page_size'] ?? 0), WidgetForm::PAGE_SIZES, true)
			? (int) $fields['page_size']
			: WidgetForm::PAGE_SIZE_DEFAULT;
		$auto_page = in_array((int) ($fields['auto_page'] ?? 0), WidgetForm::AUTO_PAGE_INTERVALS, true)
			? (int) $fields['auto_page']
			: 0;
		$page = !empty($fields['rt_active']) ? max(1, (int) ($fields['rt_page'] ?? 1)) : 1;
		$category = null;

		// Bucket境界・軸ラベル・Tooltipはすべてログインユーザーの有効タイムゾーンで統一する
		// （Zabbix フロントエンドは "System default" 選択時もここへ有効値を設定済み）。
		$tz = new DateTimeZone(date_default_timezone_get());

		$plan = BucketPlanner::plan($from, $to, $tz);
		$now = time();

		// 1. Source config → Fixed/Widget Selection resolve → Host set resolve → Event Selection resolve
		$target = SourceResolver::resolveTarget($fields);
		$event_selection = SourceResolver::resolveEventSelection($fields);

		$events = [];
		$severity_counts = array_fill_keys(Aggregator::SEVERITIES, 0);
		$truncated = false;
		$covered_from = null;
		$empty_reason = null;

		if (!$target['selected'] || !$event_selection['selected']) {
			// 対象Hostが未選択（Widget Source で未受信/空を含む）。全Hostへ広げず、専用のEmpty Stateにする。
			$empty_reason = 'no_selection';
		}
		elseif (!$target['hostids']) {
			$empty_reason = 'no_hosts';
		}
		elseif (!$runtime['ack']) {
			// 確認状態が空（通常のUIでは発生しない）: 取得せず0件。Filter起因の表示にする。
			$empty_reason = 'filtered';
		}
		else {
			// 2. Event fetch
			// SeverityもAckも取得段階では絞らない（設定値は表示開始時のRuntime Filter初期値であり、取得条件ではない。SeverityチップのTooltipがOFFのSeverityの件数も示すため）。
			$result = EventFetcher::fetch($target['hostids'], $from, $to, Aggregator::SEVERITIES, $max_events,
				$event_selection['selectors'], $continuous, [WidgetForm::ACK_UNACKNOWLEDGED, WidgetForm::ACK_ACKNOWLEDGED]
			);

			if ($result === null) {
				$this->setResponse(new CControllerResponseData([
					'name' => $this->getInput('name', $this->widget->getDefaultName()),
					'error' => _et('Failed to retrieve events.'),
					'user' => ['debug_mode' => $this->getDebugMode()]
				]));

				return;
			}

			$truncated = $result['truncated'];
			$covered_from = $result['covered_from'];

			// 3. Severity filter → Ack filter（重複eventidの排除を含む）
			$events = Aggregator::uniqueByEventid($result['events']);
			$events = Aggregator::filterByAck($events, $runtime['ack']);

			// Severityチップ用の件数: Severity自身のON/OFFを除く現在条件（Selection・期間・確認状態・カウント方式）での件数。
			foreach ($events as $e) {
				if (isset($severity_counts[(int) $e['severity']]) && self::overlapsPeriod($e, $plan['bounds'],
						$continuous, $now)) {
					$severity_counts[(int) $e['severity']]++;
				}
			}

			$events = Aggregator::filterBySeverity($events, $runtime['severities']);

			if ($runtime['mode'] !== WidgetForm::MODE_TIMELINE) {
				// 次元集計: 表示期間に数えられるEventだけを、Event名・関連Host・Host groupと突き合わせて集計する。
				$events = array_values(array_filter($events, fn(array $e): bool => self::overlapsPeriod($e,
					$plan['bounds'], $continuous, $now
				)));

				$category = $this->buildCategory($events, $target['hostids'], $runtime, $page_size, $page);

				if ($category === null) {
					$this->setResponse(new CControllerResponseData([
						'name' => $this->getInput('name', $this->widget->getDefaultName()),
						'error' => _et('Failed to retrieve events.'),
						'user' => ['debug_mode' => $this->getDebugMode()]
					]));

					return;
				}
			}
		}

		// 4. Event Count Mode → Bucket aggregation（Timeline のみ。次元モードは buildCategory で集計済み）
		$is_timeline = $runtime['mode'] === WidgetForm::MODE_TIMELINE;
		$rows = $is_timeline ? Aggregator::aggregate($events, $plan['bounds'], $continuous, $now) : [];

		// 合計: 有効なFilterを通り、表示期間と重なるEventの件数（Bucketごとの件数の合計ではない）。
		$total = 0;

		foreach ($events as $e) {
			if (self::overlapsPeriod($e, $plan['bounds'], $continuous, $now)) {
				$total++;
			}
		}

		// 次元モードでは「対象」件数（カテゴリ数）が表示対象。空判定もカテゴリ数で行う。
		$shown = $category !== null ? $category['pager']['total'] : $total;

		// Tooltip詳細（集計とは独立。取得に失敗しても集計・描画は継続する）。
		$picked = $is_timeline
			? Aggregator::pickDetails($events, $plan['bounds'], self::DETAIL_LIMIT, $continuous, $now)
			: [];
		$event_details = $picked
			? EventFetcher::fetchDetails(array_merge(...array_map(
				static fn(array $by_sev): array => array_merge(...array_values(array_map(
					static fn(array $list): array => array_column($list, 'eventid'), $by_sev
				))),
				$picked
			)))
			: [];

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'chart_data' => $this->buildChartData($rows, $plan, $tz, $runtime, $configured, $fields, $picked,
				$event_details ?? [], $continuous, [
				'total' => $total,
				'truncated' => $truncated,
				'covered_from' => $covered_from !== null
					? (new \DateTimeImmutable('@'.$covered_from))->setTimezone($tz)->format('Y/m/d H:i')
					: null,
				'max_events' => $max_events,
				'severity_counts' => array_values($severity_counts),
				'category' => $category,
				'page_size' => $page_size,
				'auto_page' => $auto_page,
				'empty_reason' => $shown > 0 ? null
					: ($empty_reason ?? (array_sum($severity_counts) > 0 ? 'filtered' : 'no_events'))
			]),
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * 次元集計（By Host / By Host group / By Event）→ Sort → Paging。
	 *
	 * @return array|null  失敗時は null
	 */
	private function buildCategory(array $events, array $hostids, array $runtime, int $page_size, int $page): ?array {
		$info = $events
			? EventFetcher::fetchDimensionInfo(array_column($events, 'eventid'), $hostids)
			: ['names' => [], 'hosts' => [], 'host_names' => []];

		if ($info === null) {
			return null;
		}

		switch ($runtime['mode']) {
			case WidgetForm::MODE_HOST:
				$rows = DimensionAggregator::byHost($events, $info['hosts'], $info['host_names']);
				break;

			case WidgetForm::MODE_GROUP:
				$groups = $events ? EventFetcher::fetchHostGroups(array_keys($info['host_names'])) : [];

				if ($groups === null) {
					return null;
				}

				$rows = DimensionAggregator::byHostGroup($events, $info['hosts'], $groups);
				break;

			default:
				$rows = DimensionAggregator::byEvent($events, $info['hosts'], $info['names']);
		}

		$pager = DimensionAggregator::paginate(DimensionAggregator::sort($rows, $runtime['sort']), $page_size, $page);

		return ['rows' => $pager['rows'], 'pager' => array_diff_key($pager, ['rows' => 1])];
	}

	private static function mode($value): int {
		$value = (int) $value;

		return in_array($value, [WidgetForm::MODE_TIMELINE, WidgetForm::MODE_HOST, WidgetForm::MODE_GROUP,
			WidgetForm::MODE_EVENT], true) ? $value : WidgetForm::MODE_TIMELINE;
	}

	private static function sortKey($value): int {
		$value = (int) $value;

		return in_array($value, DimensionAggregator::SORTS, true) ? $value : DimensionAggregator::SORT_COUNT;
	}

	/**
	 * Eventが表示期間に数えられるか（発生モード: clockが期間内 / 継続モード: 継続期間が期間と重なる）。
	 */
	private static function overlapsPeriod(array $e, array $bounds, bool $continuous, int $now): bool {
		[$start, $end] = Aggregator::span($e, $now);

		return Aggregator::bucketRange($bounds, $continuous ? $start : (int) $e['clock'],
			$continuous ? $end : (int) $e['clock'] + 1) !== null;
	}

	/**
	 * Tooltip詳細。Severityごとに [time, name, hosts] の一覧を返す（時刻は表示用に整形済み）。
	 * 発生モード: Bucketが複数日にまたがる場合は日付付き（MM/DD HH:mm）、同一日内なら HH:mm。
	 * 継続モード: 発生時刻と復旧時刻（未復旧は null = 継続中）を日付付きで返す。
	 */
	private function buildDetails(array $by_severity, array $event_details, array $row, DateTimeZone $tz,
			bool $continuous): object {
		$start = (new \DateTimeImmutable('@'.$row['bucket_start']))->setTimezone($tz);
		$last = (new \DateTimeImmutable('@'.($row['bucket_end'] - 1)))->setTimezone($tz);
		$multi_day = $start->format('Ymd') !== $last->format('Ymd');
		$format = $continuous || $multi_day ? 'm/d H:i' : 'H:i';
		$fmt = static fn(int $ts): string => (new \DateTimeImmutable('@'.$ts))->setTimezone($tz)->format($format);

		$details = [];

		foreach ($by_severity as $severity => $list) {
			foreach ($list as $e) {
				$d = $event_details[$e['eventid']] ?? ['name' => '', 'hosts' => []];

				$item = [
					'time' => $fmt($e['clock']),
					'name' => $d['name'],
					'hosts' => $d['hosts']
				];

				if ($continuous) {
					$item['end'] = $e['end'] !== null ? $fmt((int) $e['end']) : null;
				}

				$details[$severity][] = $item;
			}
		}

		return (object) $details;
	}

	/**
	 * 描画用データ。Event配列ではなくBucket集計行と表示文字列だけを含む。
	 */
	private function buildChartData(array $rows, array $plan, DateTimeZone $tz, array $runtime, array $configured,
			array $fields, array $picked, array $event_details, bool $continuous, array $meta): array {
		$severities = $runtime['severities'];
		$unit = $plan['interval']['unit'];
		$bounds = $plan['bounds'];

		$buckets = [];

		foreach ($rows as $i => $row) {
			$counts = [];

			foreach (Aggregator::SEVERITIES as $severity) {
				$counts[] = $row[Aggregator::SEVERITY_KEYS[$severity]];
			}

			$details = $this->buildDetails($picked[$i] ?? [], $event_details, $row, $tz, $continuous);

			$buckets[] = [
				'start' => $row['bucket_start'],
				'end' => $row['bucket_end'],
				'axis' => TimeFormatter::axisParts($row['bucket_start'], $unit, $tz),
				'range' => TimeFormatter::rangeLabel($row['bucket_start'], $row['bucket_end'], $unit, $tz),
				'counts' => $counts,
				'details' => $details,
				'total' => $row['total']
			];
		}

		// 積み上げ順（Not classified が最下段、Disaster が最上段）。Zabbix標準の重要度名・色を使う。
		$severity_defs = [];

		foreach (Aggregator::SEVERITIES as $severity) {
			$color = (string) CSeverityHelper::getColor($severity);

			$severity_defs[] = [
				'id' => $severity,
				'name' => CSeverityHelper::getName($severity),
				'color' => $color !== '' ? '#'.ltrim($color, '#') : '#97AAB3',
				'enabled' => in_array($severity, $severities, true)
			];
		}

		return [
			'buckets' => $buckets,
			'severities' => $severity_defs,
			'interval' => [
				'key' => $plan['interval']['key'],
				'unit' => $plan['interval']['unit'],
				'label' => self::intervalLabel($plan['interval']['key'])
			],
			'timezone' => $tz->getName(),
			// Filter / Count Mode。configured は設定値（Resetの戻り先）、runtime は今回の集計に使った値。
			'configured' => [
				'severities' => $configured['severities'],
				'ack' => $configured['ack'],
				'count_mode' => $configured['count_mode'],
				'mode' => $configured['mode'],
				'sort' => $configured['sort']
			],
			'runtime' => [
				'severities' => $runtime['severities'],
				'ack' => $runtime['ack'],
				'count_mode' => $runtime['count_mode'],
				'mode' => $runtime['mode'],
				'sort' => $runtime['sort']
			],
			'category' => $meta['category'],
			'page_size' => $meta['page_size'],
			'auto_page' => $meta['auto_page'],
			'total' => $meta['total'],
			'severity_counts' => $meta['severity_counts'],
			'empty_reason' => $meta['empty_reason'],
			'truncated' => $meta['truncated'],
			'covered_from' => $meta['covered_from'],
			'max_events' => $meta['max_events'],
			'style' => [
				'graph_height' => max(WidgetForm::GRAPH_HEIGHT_MIN,
					min(WidgetForm::GRAPH_HEIGHT_MAX, (int) ($fields['graph_height'] ?? WidgetForm::GRAPH_HEIGHT_DEFAULT))
				),
				'y_axis_min' => max(0,
					min(WidgetForm::Y_AXIS_MIN_MAX, (int) ($fields['y_axis_min'] ?? 0))
				),
				'x_label' => max(0, min(3, (int) ($fields['x_label'] ?? 0))),
				'line_color' => self::color($fields['line_color'] ?? ''),
				'text_color' => self::color($fields['text_color'] ?? '')
			]
		];
	}

	private static function ints($values): array {
		return array_values(array_unique(array_map('intval', (array) $values)));
	}

	private static function color($value): string {
		$value = ltrim((string) $value, '#');

		return preg_match('/^[0-9A-Fa-f]{6}$/', $value) === 1 ? '#'.$value : '';
	}

	private static function intervalLabel(string $key): string {
		switch ($key) {
			case '1m':  return _et('1 minute');
			case '5m':  return _et('5 minutes');
			case '10m': return _et('10 minutes');
			case '15m': return _et('15 minutes');
			case '30m': return _et('30 minutes');
			case '1h':  return _et('1 hour');
			case '2h':  return _et('2 hours');
			case '3h':  return _et('3 hours');
			case '6h':  return _et('6 hours');
			case '12h': return _et('12 hours');
			case '1d':  return _et('1 day');
			case '7d':  return _et('7 days (weeks start on Monday)');
			case '1M':  return _et('1 month');
		}

		return $key;
	}
}
