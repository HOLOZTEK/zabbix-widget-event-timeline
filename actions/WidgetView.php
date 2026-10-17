<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Actions;

use CControllerDashboardWidgetView;
use CControllerResponseData;
use CSeverityHelper;
use DateTimeZone;

use Modules\EventTimeline\Includes\{
	Aggregator,
	DetailSelector,
	DimensionAggregator,
	BucketPlanner,
	EventFetcher,
	SourceResolver,
	TimeFormatter,
	TriggerInheritanceCache,
	TriggerPrototypeResolver,
	TriggerPrototypeScopeExpander,
	TriggerScopeExpander,
	TriggerSelection,
	TriggerSelectionResolver,
	WidgetForm
};

/**
 * データフロー: Source resolution → Event retrieval → Filtering → Aggregation → (JSで)Rendering。
 * JavaScript へは Event 配列ではなく、Bucket 集計済みデータ（chart_data）だけを渡す。
 */
class WidgetView extends CControllerDashboardWidgetView {

	// Tooltipの詳細一覧に載せる最大Event数（Bucket × Severity あたり）。
	private const DETAIL_LIMIT = 10;

	// Drilldown（詳細一覧）で返す最大行数。超えた場合は新しい順に切り、切り詰めを明示する。
	private const DRILLDOWN_MAX_ROWS = 5000;

	// Trigger prototype の継承情報のリクエスト内キャッシュ（v0.2.0 Phase 4）。Trigger Selection の LLD 展開と
	// プロトタイプグループ表示の Canonical 化で共有する（通常 Trigger 用のキャッシュとは別インスタンス）。
	private ?TriggerInheritanceCache $prototype_cache = null;

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
		// 詳細表示（Drilldownダイアログ）のページング。グラフのページング（page_size）とは別設定（§29〜34）。
		$detail_page_size = in_array((int) ($fields['detail_page_size'] ?? 0), WidgetForm::DETAIL_PAGE_SIZES, true)
			? (int) $fields['detail_page_size']
			: WidgetForm::DETAIL_PAGE_SIZE_DEFAULT;
		$page = !empty($fields['rt_active']) ? max(1, (int) ($fields['rt_page'] ?? 1)) : 1;
		$category = null;
		$detail = null;

		// Bucket境界・軸ラベル・Tooltipはすべてログインユーザーの有効タイムゾーンで統一する
		// （Zabbix フロントエンドは "System default" 選択時もここへ有効値を設定済み）。
		$tz = new DateTimeZone(date_default_timezone_get());

		// week_start が未設定（本項目追加前に保存された既存Widget）は、従来どおり月曜開始を維持する
		// （BucketPlanner::WEEK_START）。
		$week_start = array_key_exists($fields['week_start'] ?? '', BucketPlanner::WEEKDAY_ISO)
			? $fields['week_start']
			: BucketPlanner::WEEK_START;

		$plan = BucketPlanner::plan($from, $to, $tz, $week_start);
		$now = time();

		// 1. Source config → Fixed/Widget Selection resolve → Host set resolve → Event Selection resolve
		$target = SourceResolver::resolveTarget($fields);

		// Trigger Selection（v0.2.0）: Phase 1 で triggerids の形式を正規化（inactive/active/invalid）し、
		// Phase 2 で TriggerSelectionResolver によりテンプレート継承を辿って Canonical Trigger へ正規化し、
		// Phase 3 で TriggerScopeExpander により Canonical を Host Scope（$target）上の Trigger へ展開して Event 抽出に使う。
		// Phase 4 で LLD（Discovery）由来の Trigger は TriggerPrototypeResolver により生成元 Trigger prototype の
		// Canonical prototype へ正規化し、TriggerPrototypeScopeExpander で Host Scope 上の Discovered Trigger へ展開する
		// （通常 Trigger の展開結果と和集合）。
		// 固定指定・Navigator 参照のどちらも同じ経路を通る。Source Trigger ID をそのまま Event 条件には使わない。
		// 選択TriggerのHostを対象Hostへ加えることはしない（Host範囲は resolveTarget だけが決める）。
		$started = microtime(true);
		EventFetcher::$api_calls = [];
		TriggerSelectionResolver::$calls = 0;
		TriggerPrototypeResolver::$calls = 0;
		$inheritance = new TriggerInheritanceCache([EventFetcher::class, 'fetchTriggerInheritance']);
		$this->prototype_cache = new TriggerInheritanceCache([EventFetcher::class, 'fetchPrototypeInheritance']);

		$trigger_selection = TriggerSelection::resolve($fields['triggerids'] ?? []);
		$trigger_resolution = null;
		$prototype_resolution = null;
		$trigger_expansion = null;

		if ($trigger_selection['trigger_filter'] === TriggerSelection::STATE_ACTIVE) {
			$trigger_resolution = TriggerSelectionResolver::resolve($trigger_selection['triggerids'],
				[$inheritance, 'fetch']
			);
			$failed = $trigger_resolution === null;

			if (!$failed) {
				$prototype_resolution = $this->resolveSourcePrototypes($trigger_resolution, $inheritance);
				$failed = $prototype_resolution === false;
			}

			// Selection 自体が fail-closed（filterError）の場合と、対象Hostがない場合は展開しない。
			if (!$failed && self::selectionError($trigger_resolution, $prototype_resolution) === null
					&& $target['hostids']) {
				$trigger_expansion = $this->expandSelection($trigger_resolution, $prototype_resolution,
					$target['hostids'], $inheritance
				);

				$failed = $trigger_expansion === null;
			}

			if ($failed) {
				$this->setResponse(new CControllerResponseData([
					'name' => $this->getInput('name', $this->widget->getDefaultName()),
					'error' => _et('Failed to retrieve events.'),
					'user' => ['debug_mode' => $this->getDebugMode()]
				]));

				return;
			}
		}

		$trigger_ms = (int) round((microtime(true) - $started) * 1000);

		$event_selection = SourceResolver::resolveEventSelection($trigger_selection, $trigger_resolution,
			$trigger_expansion, $prototype_resolution ?: null
		);

		// Trigger filter error（継承元Templateを参照できず正規化できないTriggerを含む）: Selection 全体を適用不能とし、
		// Event を取得しない（fail-closed）。正常な0件（no_events）とは別の empty_reason で利用者へ警告する。
		// 展開時の判定不能（partial）: 一致を確定できた Trigger の Event だけを表示し、不完全な可能性を警告する。
		$trigger_selection['trigger_filter_error'] = $event_selection['error'];
		$trigger_selection['trigger_filter_partial'] = $event_selection['partial'];
		$trigger_selection['resolution'] = $trigger_resolution;
		$trigger_selection['prototype_resolution'] = $prototype_resolution ?: null;
		$trigger_selection['expansion'] = $trigger_expansion;

		// 解決結果（source / canonical / type / chain / status）・展開結果・API統計はデバッグ専用。Zabbix のユーザー設定で
		// デバッグモードが有効なときだけ応答へ含め、JS 側で console.debug に出す（正式UIには表示しない）。
		$debug = $this->getDebugMode() == GROUP_DEBUG_MODE_ENABLED;

		$events = [];
		$severity_counts = array_fill_keys(Aggregator::SEVERITIES, 0);
		$truncated = false;
		$covered_from = null;
		$empty_reason = null;
		$event_ms = null;

		if (!$target['selected'] || !$event_selection['selected']) {
			// 対象Hostが未選択（Widget Source で未受信/空を含む）。全Hostへ広げず、専用のEmpty Stateにする。
			$empty_reason = 'no_selection';
		}
		elseif ($event_selection['error'] !== null) {
			$empty_reason = 'trigger_unresolvable';
		}
		elseif (!$target['hostids']) {
			$empty_reason = 'no_hosts';
		}
		elseif (!$event_selection['selectors']) {
			// Trigger が指定されているが、対象Host上に一致するTriggerが1件もない（Template未適用のHostだけ・Hostローカル
			// Triggerの所有HostがHost Scope外・削除済み・権限なし・不正値）。絞り込みなし（全Event）へ戻さず、正常な0件として扱う。
			$empty_reason = 'no_events';
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

			$event_ms = (int) round((microtime(true) - $started) * 1000) - $trigger_ms;
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

			if (!empty($fields['dd_active'])) {
				// Drilldown: 集計と同じFilter済みEventから、クリックされた棒（Severity Stack）の構成要素だけを返す。
				$detail = $this->buildDetail($events, $target['hostids'], $runtime, $fields, $plan, $tz, $continuous,
					$now, $from, $to
				);

				if ($detail === null) {
					$this->setResponse(new CControllerResponseData([
						'name' => $this->getInput('name', $this->widget->getDefaultName()),
						'error' => _et('Failed to retrieve events.'),
						'user' => ['debug_mode' => $this->getDebugMode()]
					]));

					return;
				}

				$detail['source_truncated'] = $truncated;
				$detail['covered_from'] = $covered_from !== null
					? (new \DateTimeImmutable('@'.$covered_from))->setTimezone($tz)->format('Y/m/d H:i')
					: null;
			}

			if ($detail === null && $runtime['mode'] !== WidgetForm::MODE_TIMELINE) {
				// 次元集計: 表示期間に数えられるEventだけを、Event名・関連Host・Host groupと突き合わせて集計する。
				$events = array_values(array_filter($events, fn(array $e): bool => self::overlapsPeriod($e,
					$plan['bounds'], $continuous, $now
				)));

				$category = $this->buildCategory($events, $target['hostids'], $runtime, $fields, $page_size, $page);

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

		if (!empty($fields['dd_active'])) {
			// 対象未選択・0件などの場合も、エラーにせず空の詳細を返す。
			$this->setResponse(new CControllerResponseData([
				'name' => $this->getInput('name', $this->widget->getDefaultName()),
				'detail' => $detail ?? ['kind' => 'events', 'rows' => [], 'total' => 0, 'rows_truncated' => false,
					'period' => null, 'source_truncated' => $truncated, 'covered_from' => null],
				'trigger_selection' => $debug
					? $this->debugTriggerSelection($trigger_selection, $started, $trigger_ms, $event_ms, $inheritance)
					: null,
				'user' => ['debug_mode' => $this->getDebugMode()]
			]));

			return;
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
			'trigger_selection' => $debug
					? $this->debugTriggerSelection($trigger_selection, $started, $trigger_ms, $event_ms, $inheritance)
					: null,
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
				'detail_page_size' => $detail_page_size,
				'trigger_partial' => $event_selection['partial'],
				'empty_reason' => $shown > 0 ? null
					: ($empty_reason ?? (array_sum($severity_counts) > 0 ? 'filtered' : 'no_events'))
			], $week_start),
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * 次元集計（By Host / By Host group / By Event）→ Sort → Paging。
	 *
	 * @return array|null  失敗時は null
	 */
	/**
	 * デバッグモード用: Trigger Selection の解決・展開結果と、Trigger 展開 / Event 取得の所要時間・API 呼び出し回数。
	 */
	private function debugTriggerSelection(array $trigger_selection, float $started, int $trigger_ms,
			?int $event_ms, TriggerInheritanceCache $inheritance): array {
		$trigger_selection['stats'] = [
			'trigger_ms' => $trigger_ms,
			'event_ms' => $event_ms,
			'total_ms' => (int) round((microtime(true) - $started) * 1000),
			'api_calls' => EventFetcher::$api_calls,
			'resolver_calls' => TriggerSelectionResolver::$calls,
			'inheritance_fetches' => $inheritance->fetch_calls,
			'prototype_resolver_calls' => TriggerPrototypeResolver::$calls,
			'prototype_fetches' => $this->prototype_cache !== null ? $this->prototype_cache->fetch_calls : 0,
			'expanded_triggers' => $trigger_selection['expansion'] !== null
				? count($trigger_selection['expansion']['triggerids'])
				: null,
			'expanded_lld_triggers' => ($trigger_selection['expansion']['lld'] ?? null) !== null
				? count($trigger_selection['expansion']['lld']['triggerids'])
				: null,
			'matched_prototypes' => ($trigger_selection['expansion']['lld'] ?? null) !== null
				? count($trigger_selection['expansion']['lld']['matched_prototypeids'])
				: null
		];

		return $trigger_selection;
	}

	/**
	 * Phase 4: Selection のうち LLD（Discovery）由来の Trigger を、生成元 Trigger prototype から Canonical Trigger prototype へ
	 * 正規化する。生成元 prototype は TriggerSelectionResolver が取得した行（trigger.get の triggerDiscovery）を使う。
	 *
	 * @return array|false|null  TriggerPrototypeResolver::resolve() の結果。LLD 由来の Trigger がなければ null、
	 *                           取得失敗時は false
	 */
	private function resolveSourcePrototypes(array $trigger_resolution, TriggerInheritanceCache $inheritance) {
		$discovered = [];

		foreach ($trigger_resolution['entries'] as $entry) {
			if ($entry['type'] === TriggerSelectionResolver::TYPE_DISCOVERED) {
				$discovered[] = $entry['source_triggerid'];
			}
		}

		if (!$discovered) {
			return null;
		}

		// 解決時に取得済み（キャッシュ済み）のため、ここで API は呼ばれない。
		$rows = $inheritance->fetch($discovered);

		if ($rows === null) {
			return false;
		}

		$sources = [];

		foreach ($discovered as $triggerid) {
			$sources[$triggerid] = (int) ($rows[$triggerid]['prototypeid'] ?? 0);
		}

		return TriggerPrototypeResolver::resolve($sources, [$this->prototype_cache, 'fetch']) ?? false;
	}

	/**
	 * Selection 全体を適用できない（fail-closed）理由。通常 Trigger 側（継承元 Template を参照できない）を優先し、
	 * 次に LLD 側（生成元 prototype / 継承元 prototype を参照できない）を見る。
	 */
	private static function selectionError(array $trigger_resolution, $prototype_resolution): ?string {
		$error = TriggerSelectionResolver::filterError($trigger_resolution);

		if ($error === null && is_array($prototype_resolution)) {
			$error = TriggerPrototypeResolver::filterError($prototype_resolution);
		}

		return $error;
	}

	/**
	 * 通常 Trigger（Phase 3）と LLD（Phase 4）の展開を行い、expanded_triggerids を和集合にする。
	 * 対象Host上の Trigger は1回だけ取得して両方の展開で共有する（LLD の Selection があるときだけ生成元 prototype も取得）。
	 *
	 * @return array|null  ['triggerids', 'indeterminate', 'normal', 'lld']。取得失敗時は null
	 */
	private function expandSelection(array $trigger_resolution, $prototype_resolution, array $hostids,
			TriggerInheritanceCache $inheritance): ?array {
		$with_prototype = is_array($prototype_resolution) && $prototype_resolution['canonical'];
		$host_triggers = false;
		$fetch_host_triggers = static function (array $ids) use (&$host_triggers, $with_prototype): ?array {
			if ($host_triggers === false) {
				$host_triggers = EventFetcher::fetchHostTriggers($ids, $with_prototype);
			}

			return $host_triggers;
		};

		$normal = TriggerScopeExpander::expand($trigger_resolution, $hostids, $fetch_host_triggers, $inheritance);

		if ($normal === null) {
			return null;
		}

		$lld = null;

		if ($with_prototype) {
			$lld = TriggerPrototypeScopeExpander::expand($prototype_resolution, $hostids, $fetch_host_triggers,
				$this->prototype_cache
			);

			if ($lld === null) {
				return null;
			}
		}

		$triggerids = array_values(array_unique(array_merge($normal['triggerids'], $lld['triggerids'] ?? [])));
		sort($triggerids, SORT_NUMERIC);

		return [
			'triggerids' => $triggerids,
			'indeterminate' => $normal['indeterminate'] + ($lld['indeterminate'] ?? 0),
			'normal' => $normal,
			'lld' => $lld
		];
	}

	private function buildCategory(array $events, array $hostids, array $runtime, array $fields, int $page_size,
			int $page): ?array {
		$info = $events
			? EventFetcher::fetchDimensionInfo(array_column($events, 'eventid'), $hostids)
			: ['names' => [], 'hosts' => [], 'host_names' => [], 'triggers' => []];

		if ($info === null) {
			return null;
		}

		$event_display = null;
		$prototype_partial = false;

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
				$event_display = self::eventDisplay($fields);

				if ($event_display === WidgetForm::EVENT_DISPLAY_INDIVIDUAL) {
					$rows = DimensionAggregator::byEvent($events, $info['hosts'], $info['names']);
				}
				else {
					$event_proto = $this->resolveEventPrototypes($events, $info['triggers']);
					$rows = DimensionAggregator::byEventGroup($events, $info['hosts'], $info['names'],
						$event_proto['map'], $event_proto['names']
					);
					$prototype_partial = $event_proto['partial'];
				}

				// Popup（Hover Tooltip）用のHost名一覧。Timeline / Host / Host group は対象外（§35）。
				foreach ($rows as &$row) {
					$list = DimensionAggregator::hostNameList($row['hostids'] ?? [], $info['host_names'],
						WidgetForm::POPUP_HOST_LIMIT
					);
					$row['hosts'] = $list['hosts'];
					$row['hosts_more'] = $list['hosts_more'];
					unset($row['hostids']);
				}

				unset($row);
		}

		$pager = DimensionAggregator::paginate(DimensionAggregator::sort($rows, $runtime['sort']), $page_size, $page);

		return [
			'rows' => $pager['rows'],
			'pager' => array_diff_key($pager, ['rows' => 1]),
			'event_display' => $event_display === null ? null
				: ($event_display === WidgetForm::EVENT_DISPLAY_INDIVIDUAL ? 'individual' : 'prototype'),
			// Canonical Trigger prototype を解決できず、生成元 prototype の単位で分離した Group がある（Phase 4 Q1）。
			'prototype_partial' => $prototype_partial
		];
	}

	/**
	 * 設定「イベント表示」（event_display）の値を検証する。Event modeのみで使う、設定専用の値
	 * （Runtimeの切替UI・条件リセットの対象外。§1/§44）。
	 */
	private static function eventDisplay(array $fields): int {
		$value = (int) ($fields['event_display'] ?? WidgetForm::EVENT_DISPLAY_PROTOTYPE);

		return $value === WidgetForm::EVENT_DISPLAY_INDIVIDUAL
			? WidgetForm::EVENT_DISPLAY_INDIVIDUAL
			: WidgetForm::EVENT_DISPLAY_PROTOTYPE;
	}

	/**
	 * プロトタイプグループ表示のための、Event単位のTrigger prototype解決。$triggers（eventid => triggerid）を
	 * まとめて取得することで、Event件数分のAPI呼び出しにはならない（1回のRenderにつき trigger.get 1回＋
	 * triggerprototype.get を継承の段数分。prototype 用のリクエスト内キャッシュを Trigger Selection の LLD 展開と共有する）。
	 *
	 * v0.2.0 Phase 4（Q1 = B）: Group 化の単位を、生成元（Host上）の prototype ではなく Template 継承元の
	 * Canonical Trigger prototype とする。同じ Canonical prototype に由来する Event は Host をまたいで1つの Group になり、
	 * 表示名が同じでも Canonical が異なれば別 Group になる（名前による推測・統合はしない）。Host ローカルの prototype は
	 * canonical = 自身のため他Hostとは統合されない。Event 集合そのものは変えない（Grouping だけ）。
	 * 権限等で Canonical を解決できない prototype の Event は、生成元 prototype の単位で分離したまま Group 化し、
	 * partial = true を返す（Prototype group 表示が部分的である旨を警告する）。
	 *
	 * @param array<string, string> $triggers  eventid => triggerid（EventFetcher::fetchDimensionInfo() の結果）
	 *
	 * @return array{map: array<string, ?string>, names: array<string, string>, partial: bool}
	 *   map: eventid => Group 化に使う Trigger prototype ID（解決できなければ null）。names: Trigger prototype ID => 表示名。
	 *   partial: Canonical prototype を解決できず生成元 prototype の単位で分離した Event がある。
	 */
	private function resolveEventPrototypes(array $events, array $triggers): array {
		if (!$events) {
			return ['map' => [], 'names' => [], 'partial' => false];
		}

		$parent = EventFetcher::fetchTriggerParents(array_values($triggers));
		$cache = $this->prototype_cache
			?? new TriggerInheritanceCache([EventFetcher::class, 'fetchPrototypeInheritance']);
		$resolved = $parent ? TriggerPrototypeResolver::resolvePrototypes(array_values($parent), [$cache, 'fetch']) : [];

		if ($resolved === null) {
			// 失敗しても個別Event表示へFallbackするだけなので、Widget全体は止めない（従来どおり）。
			$resolved = [];
		}

		$rows = $resolved ? ($cache->fetch(array_merge(array_keys($resolved), array_filter(array_column(
			$resolved, 'canonical_prototypeid'
		)))) ?? []) : [];
		$names = [];

		foreach ($rows as $id => $row) {
			if (array_key_exists('description', $row)) {
				$names[(string) $id] = $row['description'];
			}
		}

		$group = [];
		$partial_protos = [];

		foreach ($resolved as $id => $entry) {
			if ($entry['status'] === TriggerPrototypeResolver::STATUS_RESOLVED) {
				$group[(string) $id] = (string) $entry['canonical_prototypeid'];
			}
			elseif (isset($names[(string) $id])) {
				// 生成元 prototype は参照できるが Canonical を解決できない: 生成元 prototype の単位で分離する。
				$group[(string) $id] = (string) $id;
				$partial_protos[(string) $id] = true;
			}
		}

		$map = [];
		$partial = false;

		foreach ($triggers as $eventid => $triggerid) {
			$proto = $parent[$triggerid] ?? null;
			$map[$eventid] = $proto !== null ? ($group[$proto] ?? null) : null;

			if ($proto !== null && isset($partial_protos[$proto])) {
				$partial = true;
			}
		}

		return ['map' => $map, 'names' => $names, 'partial' => $partial];
	}

	/**
	 * Drilldown: クリックされた棒（Severity Stack）を構成するEvent（またはEventモードでは影響Host）の一覧。
	 * 対象の選定は DetailSelector（集計と同じ規則）。Event名・Host・復旧時刻は選ばれたEventだけをAPIで補う。
	 *
	 * @return array|null  失敗時は null
	 */
	private function buildDetail(array $events, array $hostids, array $runtime, array $fields, array $plan,
			DateTimeZone $tz, bool $continuous, int $now, int $from, int $to): ?array {
		$fmt = static fn(int $ts): string => (new \DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y/m/d H:i:s');
		$severity = (int) ($fields['dd_severity'] ?? WidgetForm::DETAIL_SEVERITY_ALL);
		$severity = $severity === WidgetForm::DETAIL_SEVERITY_ALL ? null : $severity;
		$key = (string) ($fields['dd_key'] ?? '');
		$mode = $runtime['mode'];
		$period = (new \DateTimeImmutable('@'.$from))->setTimezone($tz)->format('Y/m/d H:i').' - '
			.(new \DateTimeImmutable('@'.$to))->setTimezone($tz)->format('Y/m/d H:i');

		$host_count = null;

		if ($mode === WidgetForm::MODE_TIMELINE) {
			$picked = DetailSelector::timeline($events, (int) ($fields['dd_start'] ?? 0),
				(int) ($fields['dd_end'] ?? 0), $continuous, $now, $severity
			);
			$info = null;
		}
		else {
			// 次元モードの母集団は、グラフと同じく表示期間に数えられるEvent。
			$events = array_values(array_filter($events, fn(array $e): bool => self::overlapsPeriod($e,
				$plan['bounds'], $continuous, $now
			)));
			$info = $events
				? EventFetcher::fetchDimensionInfo(array_column($events, 'eventid'), $hostids)
				: ['names' => [], 'hosts' => [], 'host_names' => [], 'triggers' => []];

			if ($info === null) {
				return null;
			}

			if ($mode === WidgetForm::MODE_EVENT && self::eventDisplay($fields) === WidgetForm::EVENT_DISPLAY_INDIVIDUAL) {
				$hosts = DetailSelector::byEvent($events, $info['hosts'], $info['names'], $key, $severity);
				$rows = [];

				foreach ($hosts as $h) {
					$rows[] = [
						'id' => $h['hostid'],
						'name' => (string) ($info['host_names'][$h['hostid']] ?? $h['hostid']),
						'severity' => $h['severity'],
						'count' => $h['count']
					];
				}

				usort($rows, static fn(array $a, array $b): int => $b['count'] <=> $a['count']
					?: strnatcasecmp($a['name'], $b['name']));

				return ['kind' => 'hosts', 'rows' => $rows, 'total' => count($rows), 'rows_truncated' => false,
					'period' => $period];
			}

			if ($mode === WidgetForm::MODE_EVENT) {
				// プロトタイプグループ: Group（Prototype、またはFallback先のEvent名）に属する実Eventの一覧を返す
				// （個別イベント表示のHost一覧とは異なり、Host単位に集約しない。§25〜27）。
				$event_proto = $this->resolveEventPrototypes($events, $info['triggers']);

				// 影響Host数（該当Severityの棒の値と一致させる。§41）は、集計と同じ byEventGroup() から
				// 該当Group行を引いて求める（Drilldown選定 DetailSelector::byEventGroup とは独立した経路だが、
				// どちらも同じ DimensionAggregator::eventGroupKey() を使うため、選定対象は必ず一致する）。
				$group_rows = DimensionAggregator::byEventGroup($events, $info['hosts'], $info['names'],
					$event_proto['map'], $event_proto['names']
				);

				foreach ($group_rows as $row) {
					if ($row['id'] === $key) {
						$host_count = $severity === null ? $row['total'] : $row['counts'][$severity];
						break;
					}
				}

				$picked = DetailSelector::byEventGroup($events, $event_proto['map'], $info['names'],
					$event_proto['names'], $key, $severity
				);
			}
			elseif ($mode === WidgetForm::MODE_GROUP) {
				$groups = $events ? EventFetcher::fetchHostGroups(array_keys($info['host_names'])) : [];

				if ($groups === null) {
					return null;
				}

				$picked = DetailSelector::byHostGroup($events, $info['hosts'], $groups, $key, $severity);
			}
			else {
				$picked = DetailSelector::byHost($events, $info['hosts'], $key, $severity);
			}
		}

		usort($picked, static fn(array $a, array $b): int => [(int) $b['clock'], (int) $b['eventid']]
			<=> [(int) $a['clock'], (int) $a['eventid']]);

		$total = count($picked);
		$shown = array_slice($picked, 0, self::DRILLDOWN_MAX_ROWS);
		$extra = $shown
			? EventFetcher::fetchRows(array_column($shown, 'eventid'), $mode === WidgetForm::MODE_TIMELINE ? null : $hostids)
			: [];

		if ($extra === null) {
			return null;
		}

		$rows = [];

		foreach ($shown as $e) {
			$x = $extra[$e['eventid']] ?? ['name' => '', 'triggerid' => '0', 'hosts' => [], 'end' => null];
			$clock = (int) $e['clock'];

			$rows[] = [
				'eventid' => (string) $e['eventid'],
				'triggerid' => $x['triggerid'],
				'clock' => $fmt($clock),
				'end' => $x['end'] !== null ? $fmt((int) $x['end']) : null,
				'duration' => max(0, ($x['end'] ?? $now) - $clock),
				'name' => $x['name'],
				'hosts' => $x['hosts'],
				'severity' => (int) $e['severity'],
				'acknowledged' => (int) $e['acknowledged'] === 1,
				'ongoing' => $x['end'] === null
			];
		}

		$result = ['kind' => 'events', 'rows' => $rows, 'total' => $total, 'rows_truncated' => $total > count($rows),
			'period' => $mode === WidgetForm::MODE_TIMELINE ? null : $period];

		if ($host_count !== null) {
			// プロトタイプグループのみ: 影響ホスト数（総Event数=$total とは別に、Popup/棒と同じ規則で数えたHost数）。
			$result['host_count'] = $host_count;
		}

		return $result;
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
			array $fields, array $picked, array $event_details, bool $continuous, array $meta,
			string $week_start): array {
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
				'count' => $plan['interval']['count'],
				'label' => self::intervalLabel($plan['interval']['key'], $week_start)
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
			'detail_page_size' => $meta['detail_page_size'],
			'total' => $meta['total'],
			'severity_counts' => $meta['severity_counts'],
			'empty_reason' => $meta['empty_reason'],
			'trigger_partial' => $meta['trigger_partial'],
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

	private static function intervalLabel(string $key, string $week_start = BucketPlanner::WEEK_START): string {
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
			case '7d':  return self::weekStartLabel($week_start);
			case '1M':  return _et('1 month');
		}

		return $key;
	}

	// 7日Bucketの間隔説明を、設定された週の開始曜日に応じて出し分ける。
	private static function weekStartLabel(string $week_start): string {
		switch ($week_start) {
			case BucketPlanner::WEEKDAY_SUN: return _et('7 days (week starts Sunday)');
			case BucketPlanner::WEEKDAY_MON: return _et('7 days (week starts Monday)');
			case BucketPlanner::WEEKDAY_TUE: return _et('7 days (week starts Tuesday)');
			case BucketPlanner::WEEKDAY_WED: return _et('7 days (week starts Wednesday)');
			case BucketPlanner::WEEKDAY_THU: return _et('7 days (week starts Thursday)');
			case BucketPlanner::WEEKDAY_FRI: return _et('7 days (week starts Friday)');
			case BucketPlanner::WEEKDAY_SAT: return _et('7 days (week starts Saturday)');
		}

		return _et('7 days (week starts Monday)');
	}
}
