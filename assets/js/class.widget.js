'use strict';

class CWidgetEventTimeline extends CWidget {

	#chart = null;
	#data = null;

	// 詳細表示（Drilldownダイアログ）の1ページ表示件数。設定値（グラフのページングとは別）。
	// グラフのRuntime/Page状態には影響しない（ダイアログを閉じれば元の状態のまま）。
	#detail_page_size = CWidgetEventTimeline.DETAIL_PAGE_SIZE;

	// Runtime状態（設定値ではない）。Tooltip詳細の並び: 'host'=Host単位（既定）/ 'time'=時系列。
	// 再描画（自動更新）をまたいで維持するため、インスタンスに保持する。
	#tip_mode = 'host';

	// Runtime Filter / Count Mode（設定値ではない。設定を書き換えず、設定値を初期値として最初の応答で作る）。
	// 通常のWidget更新（Refresh）では維持し、ダッシュボードの再表示・Widget再生成で設定値から初期化し直す。
	// 形式: {severities, ack, count_mode, mode, sort, page}
	#runtime = null;

	// 自動ページ送りのタイマー（表示画面上の状態。設定値ではない）。
	#auto_timer = null;
	#auto_seconds = 0;

	// Drilldown要求の通番（古い応答が新しいダイアログを上書きしないため）。
	#detail_seq = 0;

	// Widget Source の参照（Tree Navigator / Event Navigator）。Selectionが未受信でも更新を止めず、
	// サーバーへ空の Selection として渡して「対象が選択されていません」を表示させる（全Host・全Eventへは広げない）。
	static SELECTION_PATHS = ['hostids', 'groupids', 'triggerids'];

	// 発生イベント数 / 継続イベント加算（WidgetForm::COUNT_* と同じ値）。
	static COUNT_OCCURRENCE = 0;
	static COUNT_CONTINUOUS = 1;

	// 表示モード（WidgetForm::MODE_* と同じ値）。
	static MODE_TIMELINE = 0;
	static MODE_HOST = 1;
	static MODE_GROUP = 2;
	static MODE_EVENT = 3;

	// Tooltip詳細の並びの初期値（Resetの戻り先）。
	static TIP_MODE_DEFAULT = 'host';

	isFieldsReferredDataReady() {
		const received = this.getFieldsReferredData();

		for (const path of CWidgetBase.getFieldsReferencesAccessors(this._fields).keys()) {
			if (!CWidgetEventTimeline.SELECTION_PATHS.includes(path) && !received.has(path)) {
				return false;
			}
		}

		return true;
	}

	getFieldsData() {
		const fields = super.getFieldsData();

		for (const path of CWidgetEventTimeline.SELECTION_PATHS) {
			const value = fields[path];

			// 未受信（参照のまま）または参照先が未設定は、空のSelectionとして扱う。
			if (value === null || (typeof value === 'object' && !Array.isArray(value))) {
				fields[path] = [];
			}
		}

		if (this.#runtime !== null) {
			fields.rt_active = 1;
			fields.rt_severities = this.#runtime.severities;
			fields.rt_ack = this.#runtime.ack;
			fields.rt_count_mode = this.#runtime.count_mode;
			fields.rt_mode = this.#runtime.mode;
			fields.rt_sort = this.#runtime.sort;
			fields.rt_page = this.#runtime.page;
		}

		return fields;
	}

	onStop() {
		this.#stopAutoPage();
		this.#disposeChart();
	}

	onResize() {
		if (this.#chart) {
			requestAnimationFrame(() => {
				if (this.#chart) {
					this.#chart.resize();
					// 表示されるTickと期間線の位置は幅で変わるため、やり直す。
					this.#chart.setOption({xAxis: {axisLabel: this.#axisLabelOption(this.#data)}});
					this.#chart.setOption({graphic: this.#periodGraphic(this.#data)}, {replaceMerge: ['graphic']});
				}
			});
		}
	}

	setContents(response) {
		super.setContents(response);

		this.#disposeChart();

		if (response.error) {
			this.#renderMessage(response.error, 'et-error');
			return;
		}

		if (!response.chart_data) {
			this.#renderMessage(t('No events'), 'et-empty');
			return;
		}

		this.#data = response.chart_data;
		this.#detail_page_size = this.#data.detail_page_size || CWidgetEventTimeline.DETAIL_PAGE_SIZE;

		if (this.#runtime === null) {
			// 初回のみ設定値（configured_filter / 初期モード）から runtime を作る。
			this.#runtime = {
				severities: [...this.#data.configured.severities],
				ack: [...this.#data.configured.ack],
				count_mode: this.#data.configured.count_mode,
				mode: this.#data.configured.mode,
				sort: this.#data.configured.sort,
				page: 1
			};
		}

		// 現在のページが存在しなくなった場合（件数減少）は、サーバーが最終ページへ丸めた値に合わせる。
		if (this.#data.category) {
			this.#runtime.page = this.#data.category.pager.page;
		}

		this.#syncAutoPage();

		requestAnimationFrame(() => this.#render());
	}

	#disposeChart() {
		if (this.#chart) {
			this.#chart.dispose();
			this.#chart = null;
		}
	}

	#renderMessage(text, class_name) {
		this._body.innerHTML = '';

		const div = document.createElement('div');
		div.className = class_name;
		div.textContent = text;

		this._body.appendChild(div);
	}

	// ─── Rendering ────────────────────────────────────────────────
	// 描画は Bucket 集計済みデータ（chart_data）だけを入力とし、Event 取得・集計の詳細を知らない。
	#render() {
		const data = this.#data;

		if (!data) {
			return;
		}

		this.#disposeChart();
		this._body.innerHTML = '';

		this._body.appendChild(this.#buildToolbar(data));

		if (data.truncated) {
			this._body.appendChild(this.#buildTruncationWarning(data));
		}

		if (data.empty_reason !== null) {
			const empty = document.createElement('div');
			empty.className = 'et-empty';

			const title = document.createElement('div');
			// 「対象が選択されていません」と「イベントはありません」は別の状態として表示する。モードごとに空状態の文言を変える。
			title.textContent = data.empty_reason === 'no_selection'
				? t('No target selected')
				: this.#emptyText(data.empty_reason);
			empty.appendChild(title);

			const reason = {
				no_hosts: t('No target hosts.'),
				filtered: t('All events are excluded by the current filters.')
			}[data.empty_reason];

			if (reason) {
				const sub = document.createElement('div');
				sub.className = 'et-empty-sub';
				sub.textContent = reason;
				empty.appendChild(sub);
			}

			this._body.appendChild(empty);
			this._body.appendChild(this.#buildFooter(data));

			return;
		}

		const holder = document.createElement('div');
		holder.className = 'et-chart';
		holder.style.height = data.style.graph_height + 'px';

		this._body.appendChild(holder);
		this._body.appendChild(this.#buildFooter(data));

		this.#chart = echarts.init(holder, null, {renderer: 'canvas'});
		this.#data = data;
		this.#chart.on('click', params => this.#onChartClick(params));

		if (this.#isCategory()) {
			this.#chart.setOption(this.#buildCategoryOption(data));
			return;
		}

		this.#chart.setOption(this.#buildOption(data));
		// 期間線はグリッド領域が確定してから描画する。
		this.#chart.setOption({graphic: this.#periodGraphic(data)}, {replaceMerge: ['graphic']});
	}

	#isCategory() {
		return this.#runtime.mode !== CWidgetEventTimeline.MODE_TIMELINE;
	}

	// 空状態の文言（モード別）。Timeline は従来どおり。
	#emptyText(reason) {
		if (this.#isCategory() && reason !== 'filtered') {
			return {
				[CWidgetEventTimeline.MODE_HOST]: t('No target hosts.'),
				[CWidgetEventTimeline.MODE_GROUP]: t('No target host groups.')
			}[this.#runtime.mode] ?? t('No events');
		}

		return t('No events');
	}

	// ─── Drilldown（詳細一覧） ──────────────────────────────────────
	// 棒（Severity Stack）のクリックで、その集計値を構成するEvent（Eventモードでは影響Host）の一覧を出す。
	// 詳細はクリック時点のSnapshotで、元Widgetの Runtime状態（モード・Sort・Page・Filter）は変更しない。
	static DETAIL_PAGE_SIZE = 20;
	static DETAIL_DIALOGUE_ID = 'event-timeline-detail';

	#onChartClick(params) {
		if (params?.componentType !== 'series' || !this.#data || this.#runtime === null) {
			return;
		}

		const data = this.#data;
		const severity = data.severities.filter(s => s.enabled)[params.seriesIndex];

		if (!severity) {
			return;
		}

		const ctx = {mode: this.#runtime.mode, severity: severity.id, severities: data.severities};

		if (this.#isCategory()) {
			const row = data.category?.rows?.[params.dataIndex];

			if (!row) {
				return;
			}

			ctx.key = row.id;
			ctx.label = row.label;
			ctx.start = 0;
			ctx.end = 0;
			// Event mode: 設定「イベント表示」（プロトタイプグループ／個別イベント）と、そのGroupが実際に
			// Trigger prototype由来か（Fallbackされた個別Eventか）。Drilldownのタイトル・ヘッダー・
			// 応答内容（kind）の分岐に使う（サーバー側 event_display と対応、Runtime切替はない。§1/§44）。
			ctx.eventDisplay = data.category?.event_display ?? null;
			ctx.isPrototype = !!row.is_prototype;
		}
		else {
			const bucket = data.buckets?.[params.dataIndex];

			if (!bucket) {
				return;
			}

			ctx.key = '';
			ctx.label = bucket.range;
			ctx.start = bucket.start;
			ctx.end = bucket.end;
		}

		this.#openDetail(ctx);
	}

	#openDetail(ctx) {
		const box = document.createElement('div');
		box.className = 'et-detail';

		const state = {ctx, page: 1, detail: null, error: null, loading: true};
		const seq = ++this.#detail_seq;

		overlayDialogue({
			// プロトタイプグループ表示（settings: event_display）では実Eventの一覧を返すため、
			// 個別イベント表示（Affected hosts = Host一覧）と表題を分ける。
			title: ctx.mode === CWidgetEventTimeline.MODE_EVENT && ctx.eventDisplay === 'individual'
				? t('Affected hosts')
				: t('Event details'),
			class: 'modal-popup et-detail-popup',
			content: box,
			buttons: [{title: t('Close'), cancel: true, action: () => {}}],
			dialogueid: CWidgetEventTimeline.DETAIL_DIALOGUE_ID
		});

		this.#renderDetail(box, state);

		this.#fetchDetail(ctx)
			.then(detail => {
				if (seq === this.#detail_seq) {
					state.detail = detail;
				}
			})
			.catch(error => {
				if (seq === this.#detail_seq) {
					state.error = typeof error === 'string' && error !== '' ? error : t('Failed to retrieve events.');
				}
			})
			.finally(() => {
				if (seq === this.#detail_seq) {
					state.loading = false;
					this.#renderDetail(box, state);
				}
			});
	}

	#fetchDetail(ctx) {
		const curl = new Curl('zabbix.php');
		curl.setArgument('action', `widget.${this._type}.view`);

		const body = this.getUpdateRequestData();

		body.fields = {
			...(body.fields ?? {}),
			dd_active: 1,
			dd_start: ctx.start,
			dd_end: ctx.end,
			dd_severity: ctx.severity,
			dd_key: ctx.key
		};

		return fetch(curl.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(body)
		})
			.then(response => response.json())
			.then(response => {
				if ('error' in response) {
					throw typeof response.error === 'string' ? response.error : '';
				}

				if (!response.detail) {
					throw '';
				}

				return response.detail;
			});
	}

	static #duration(seconds) {
		const units = [[86400, 'd'], [3600, 'h'], [60, 'm'], [1, 's']];
		const parts = [];
		let rest = Math.max(0, Math.floor(seconds));

		for (const [size, label] of units) {
			const value = Math.floor(rest / size);

			if (value > 0 || (label === 's' && parts.length === 0)) {
				parts.push(value + label);
			}

			rest -= value * size;

			if (parts.length === 2) {
				break;
			}
		}

		return parts.join(' ');
	}

	#renderDetail(box, state) {
		const {ctx, detail} = state;
		const sev = ctx.severities.find(s => s.id === ctx.severity);

		box.innerHTML = '';

		const el = (tag, class_name, text) => {
			const node = document.createElement(tag);

			if (class_name) {
				node.className = class_name;
			}

			if (text !== undefined) {
				node.textContent = text;
			}

			return node;
		};

		const severityCell = (severity_id, parent) => {
			const def = ctx.severities.find(s => s.id === severity_id);
			const dot = el('span', 'et-detail-dot');

			dot.style.background = def?.color ?? '#97AAB3';
			parent.appendChild(dot);
			parent.appendChild(document.createTextNode(def?.name ?? String(severity_id)));
		};

		// Header: 何をDrilldownしたか。
		const head = el('dl', 'et-detail-head');
		const addHead = (label, value, severity_id) => {
			head.appendChild(el('dt', null, label));

			const dd = el('dd');

			if (severity_id !== undefined) {
				severityCell(severity_id, dd);
			}
			else {
				dd.textContent = value;
			}

			head.appendChild(dd);
		};

		if (ctx.mode === CWidgetEventTimeline.MODE_TIMELINE) {
			addHead(t('Period'), ctx.label);
		}
		else {
			// プロトタイプグループのGroup（Trigger prototype由来）だけ、ラベルを「プロトタイプグループ」にする。
			// Fallbackされた個別Event（is_prototype=false）は、プロトタイプグループ設定下でも通常のEventとして扱う（§37）。
			const dim_label = ctx.mode === CWidgetEventTimeline.MODE_EVENT && ctx.isPrototype
				? t('Prototype group')
				: {
					[CWidgetEventTimeline.MODE_HOST]: t('Host'),
					[CWidgetEventTimeline.MODE_GROUP]: t('Host group'),
					[CWidgetEventTimeline.MODE_EVENT]: t('Event')
				}[ctx.mode];

			addHead(dim_label, ctx.label);

			if (detail?.period) {
				addHead(t('Period'), detail.period);
			}
		}

		if (sev) {
			addHead(t('Severity'), '', sev.id);
		}

		box.appendChild(head);

		if (state.loading) {
			box.appendChild(el('div', 'is-loading et-detail-loading'));
			return;
		}

		if (state.error !== null) {
			box.appendChild(el('div', 'et-detail-message et-error', state.error));
			return;
		}

		if (detail.kind === 'hosts') {
			// 個別イベント表示（従来どおり）: 影響ホスト数のみ。
			box.appendChild(el('div', 'et-detail-count', sprintf(t('Affected hosts: %1$s'), detail.total)));
		}
		else if (typeof detail.host_count === 'number') {
			// プロトタイプグループ: 影響ホスト数（棒・Popupと同じ規則で数えたHost数）と該当イベント数の両方（§25〜27, §41）。
			box.appendChild(el('div', 'et-detail-count', sprintf(t('Affected hosts: %1$s'), detail.host_count)));
			box.appendChild(el('div', 'et-detail-count', sprintf(t('Matching events: %1$s'), detail.total)));
		}
		else {
			// Timeline / Host / Host group（従来どおり）: 該当イベント数のみ。
			box.appendChild(el('div', 'et-detail-count', sprintf(t('Matching events: %1$s'), detail.total)));
		}

		// 元のEvent取得が上限に達している場合、一覧も不完全であることを明示する。
		if (detail.source_truncated) {
			box.appendChild(el('div', 'et-warning', t('This list is based on data that reached the event retrieval limit.')));
		}

		if (detail.rows_truncated) {
			box.appendChild(el('div', 'et-warning', sprintf(
				t('Only the newest %1$s of %2$s events are listed.'), detail.rows.length, detail.total
			)));
		}

		if (detail.rows.length === 0) {
			box.appendChild(el('div', 'et-detail-message', t('No matching data.')));
			return;
		}

		const size = this.#detail_page_size;
		const pages = Math.max(1, Math.ceil(detail.rows.length / size));
		state.page = Math.max(1, Math.min(pages, state.page));

		const rows = detail.rows.slice((state.page - 1) * size, state.page * size);
		const table = el('table', 'list-table et-detail-table');
		const thead = el('thead');
		const tr_head = el('tr');

		const columns = detail.kind === 'hosts'
			? [t('Host'), t('Severity'), t('Number of events')]
			: [t('Time'), t('Recovery time'), t('Duration'), t('Host'), t('Event'), t('Severity'),
				t('Acknowledgement'), t('Status')];

		for (const c of columns) {
			tr_head.appendChild(el('th', null, c));
		}

		thead.appendChild(tr_head);
		table.appendChild(thead);

		const tbody = el('tbody');

		for (const r of rows) {
			const tr = el('tr');

			if (detail.kind === 'hosts') {
				tr.appendChild(el('td', 'et-detail-wrap', r.name));
				severityCell(r.severity, tr.appendChild(el('td')));
				tr.appendChild(el('td', 'et-detail-num', String(r.count)));
			}
			else {
				tr.appendChild(el('td', 'et-detail-nowrap', r.clock));
				tr.appendChild(el('td', 'et-detail-nowrap', r.end ?? '-'));
				tr.appendChild(el('td', 'et-detail-nowrap', CWidgetEventTimeline.#duration(r.duration)));
				tr.appendChild(el('td', 'et-detail-wrap', r.hosts.map(h => h.name).join(', ')));

				const name = el('td', 'et-detail-wrap');

				if (r.triggerid !== '0') {
					const a = el('a', null, r.name);

					a.href = `tr_events.php?triggerid=${encodeURIComponent(r.triggerid)}&eventid=${encodeURIComponent(r.eventid)}`;
					a.target = '_blank';
					a.rel = 'noopener';
					name.appendChild(a);
				}
				else {
					name.textContent = r.name;
				}

				tr.appendChild(name);
				severityCell(r.severity, tr.appendChild(el('td', 'et-detail-nowrap')));
				tr.appendChild(el('td', 'et-detail-nowrap', r.acknowledged ? t('Acknowledged') : t('Unacknowledged')));
				tr.appendChild(el('td', 'et-detail-nowrap', r.ongoing ? t('Ongoing') : t('Resolved')));
			}

			tbody.appendChild(tr);
		}

		table.appendChild(tbody);

		const scroller = el('div', 'et-detail-scroll');
		scroller.appendChild(table);
		box.appendChild(scroller);

		if (pages > 1) {
			const pager = el('div', 'et-detail-pager');
			const mk = (label, page, disabled) => {
				const b = el('button', 'btn-alt', label);

				b.type = 'button';
				b.disabled = disabled;
				b.addEventListener('click', () => {
					state.page = page;
					this.#renderDetail(box, state);
				});

				return b;
			};

			pager.appendChild(mk('‹', state.page - 1, state.page <= 1));
			pager.appendChild(el('span', 'et-detail-pager-text', sprintf(t('Page %1$s / %2$s'), state.page, pages)));
			pager.appendChild(mk('›', state.page + 1, state.page >= pages));
			box.appendChild(pager);
		}
	}

	// ─── Paging ───────────────────────────────────────────────────
	// 自動ページ送り: 全ページを1→…→最終→1と巡回する。ページが1つ以下のときは何もしない。
	// 手動のページ変更では無効化せずタイマーだけをリセットし、条件変更（ページ1へ戻る）でもリセットする。
	#syncAutoPage() {
		const pager = this.#data?.category?.pager;
		const seconds = this.#data?.auto_page ?? 0;
		const active = this.#isCategory() && pager && pager.pages > 1 && seconds > 0;

		if (!active) {
			this.#stopAutoPage();
			return;
		}

		// 既に同じ間隔で動いていれば、更新（Refresh）のたびにタイマーをやり直さない。
		if (this.#auto_timer === null || this.#auto_seconds !== seconds) {
			this.#startAutoPage(seconds);
		}
	}

	#startAutoPage(seconds) {
		this.#stopAutoPage();

		this.#auto_seconds = seconds;
		this.#auto_timer = setInterval(() => {
			const pager = this.#data?.category?.pager;

			if (!pager || pager.pages <= 1) {
				return;
			}

			this.#runtime.page = this.#runtime.page >= pager.pages ? 1 : this.#runtime.page + 1;
			this._startUpdating();
		}, seconds * 1000);
	}

	#stopAutoPage() {
		if (this.#auto_timer !== null) {
			clearInterval(this.#auto_timer);
			this.#auto_timer = null;
		}
	}

	#resetAutoPage() {
		if (this.#auto_timer !== null) {
			this.#startAutoPage(this.#auto_seconds);
		}
	}

	#goPage(page) {
		this.#runtime.page = page;
		this.#resetAutoPage();
		// ボタン状態は応答で更新される。ここでは再取得のみ。
		this._startUpdating();
	}

	// グラフ下部（X軸の時刻・期間線より下）: 左に合計、右に間隔。
	#buildFooter(data) {
		const footer = document.createElement('div');
		footer.className = 'et-footer';

		if (this.#isCategory()) {
			// 次元モード: 合計・間隔は出さず、対象件数とページ情報を出す。
			const pager = data.category?.pager;
			const info = document.createElement('span');
			info.className = 'et-total';
			info.textContent = sprintf(t('Targets: %1$s (showing %2$s–%3$s)'), pager?.total ?? 0, pager?.from ?? 0,
				pager?.to ?? 0
			);

			footer.appendChild(info);

			if (pager && pager.pages > 1) {
				footer.appendChild(this.#buildPager(pager));
			}

			return footer;
		}

		const total = document.createElement('span');
		total.className = 'et-total';
		total.textContent = t('Total') + ': ' + data.total;

		const interval = document.createElement('span');
		interval.className = 'et-interval';
		interval.textContent = t('Interval') + ': ' + data.interval.label;

		footer.appendChild(total);
		footer.appendChild(interval);

		return footer;
	}

	// ページ送り: ‹ 2 / 7 ›（Top Nではなく、全件を1ページ分ずつ表示する）。
	#buildPager(pager) {
		const box = document.createElement('span');
		box.className = 'et-pager';

		const mk = (label, page, disabled) => {
			const b = document.createElement('button');
			b.type = 'button';
			b.className = 'et-btn';
			b.textContent = label;
			b.disabled = disabled;
			b.addEventListener('click', () => this.#goPage(page));

			return b;
		};

		const text = document.createElement('span');
		text.className = 'et-pager-text';
		text.textContent = sprintf(t('Page %1$s / %2$s'), pager.page, pager.pages);

		box.appendChild(mk('\u2039', pager.page - 1, pager.page <= 1));
		box.appendChild(text);
		box.appendChild(mk('\u203A', pager.page + 1, pager.page >= pager.pages));

		return box;
	}

	// Runtime操作領域（2行構成）。
	// 1行目: 集計 / 確認状態 / 詳細表示順 / 条件リセット（機能ごとのグループを横並び。操作専用）。
	// 2行目: 深刻度（Severity Filter）専用行。
	#buildToolbar(data) {
		const bar = document.createElement('div');
		bar.className = 'et-toolbar';

		const row1 = document.createElement('div');
		row1.className = 'et-controls';
		row1.appendChild(this.#buildModeGroup());
		row1.appendChild(this.#buildCountModeGroup());
		row1.appendChild(this.#buildAckGroup());

		if (this.#isCategory()) {
			row1.appendChild(this.#buildSortGroup());
		}
		else {
			row1.appendChild(this.#buildTipModeGroup());
		}

		row1.appendChild(this.#buildResetGroup(data));

		bar.appendChild(row1);
		bar.appendChild(this.#buildSeverityRow(data));

		return bar;
	}

	// Runtime の変更を反映する。表示（ボタン状態）は即時に更新し、集計は再取得（Filter → Aggregation → Rendering）する。
	// 条件（モード・集計・確認・深刻度・並び）が変わったときはページ1へ戻し、自動ページ送りのタイマーもやり直す。
	#applyRuntimeChange() {
		this.#runtime.page = 1;
		this.#resetAutoPage();

		// 表示中のデータの形（Timeline: buckets / 次元モード: category）が新しいモードと合わないときは、
		// 即時再描画せず再取得だけを行う（合わないデータで描画すると例外になり、再取得も止まる）。
		const shape_ok = this.#data
			&& (this.#runtime.mode === CWidgetEventTimeline.MODE_TIMELINE) === (this.#data.category === null);

		try {
			if (shape_ok) {
				this.#render();
			}
		}
		finally {
			this._startUpdating();
		}
	}

	static #sameSet(a, b) {
		return a.length === b.length && a.every(v => b.includes(v));
	}

	// 確認状態の配列（1=確認済み / 0=未確認）⇄ 排他的な選択（all / unack / ack）。
	static #ackMode(ack) {
		const has_ack = ack.includes(1);
		const has_unack = ack.includes(0);

		return has_ack && !has_unack ? 'ack' : (!has_ack && has_unack ? 'unack' : 'all');
	}

	static #ackFromMode(mode) {
		return mode === 'ack' ? [1] : (mode === 'unack' ? [0] : [1, 0]);
	}

	// ボタングループ。説明ラベルは表示せず、支援技術向けの aria-label のみ付ける。
	#buildGroup(label) {
		const group = document.createElement('div');
		group.className = 'et-group';
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', label);

		return group;
	}

	// 排他的な選択ボタン（選択中は文字・外観の両方で判別できる）。
	#buildChoice(group, label, selected, title, onSelect) {
		const button = document.createElement('button');

		button.type = 'button';
		button.className = 'et-btn' + (selected ? ' et-selected' : '');
		button.textContent = label;
		button.setAttribute('aria-pressed', selected ? 'true' : 'false');

		if (title) {
			button.title = title;
		}

		button.addEventListener('click', () => {
			if (!selected) {
				onSelect();
			}
		});

		group.appendChild(button);
	}

	// 表示モード: 時系列 / ホスト / ホストグループ / イベント（排他。実行時の切替は設定値を変えない）。
	#buildModeGroup() {
		const group = this.#buildGroup(t('Display mode'));

		for (const [mode, label] of [
			[CWidgetEventTimeline.MODE_TIMELINE, t('Timeline')],
			[CWidgetEventTimeline.MODE_HOST, t('Host')],
			[CWidgetEventTimeline.MODE_GROUP, t('Host group')],
			[CWidgetEventTimeline.MODE_EVENT, t('Event')]
		]) {
			this.#buildChoice(group, label, this.#runtime.mode === mode, '', () => {
				this.#runtime.mode = mode;
				this.#applyRuntimeChange();
			});
		}

		return group;
	}

	// 並び順（次元モードのみ）: 件数順 / A→Z / Z→A。
	#buildSortGroup() {
		const group = this.#buildGroup(t('Sort order'));

		for (const [sort, label] of [[0, t('By count')], [1, t('A to Z')], [2, t('Z to A')]]) {
			this.#buildChoice(group, label, this.#runtime.sort === sort, '', () => {
				this.#runtime.sort = sort;
				this.#applyRuntimeChange();
			});
		}

		return group;
	}

	// 集計: 発生数 / 継続数（Event Count Mode）。
	#buildCountModeGroup() {
		const group = this.#buildGroup(t('Aggregation'));
		const current = this.#runtime.count_mode;
		const pick = mode => () => {
			this.#runtime.count_mode = mode;
			this.#applyRuntimeChange();
		};

		this.#buildChoice(group, t('Occurred count'), current === CWidgetEventTimeline.COUNT_OCCURRENCE,
			t('Number of events that started in each bucket'), pick(CWidgetEventTimeline.COUNT_OCCURRENCE)
		);
		this.#buildChoice(group, t('Continuing count'), current === CWidgetEventTimeline.COUNT_CONTINUOUS,
			t('Number of events overlapping each bucket'), pick(CWidgetEventTimeline.COUNT_CONTINUOUS)
		);

		return group;
	}

	// 確認: 全て / 未確認 / 確認済み（取得時点のEventの現在の確認状態で判定する）。
	#buildAckGroup() {
		const group = this.#buildGroup(t('Ack status'));
		const current = CWidgetEventTimeline.#ackMode(this.#runtime.ack);

		for (const [mode, label] of [['all', t('All')], ['unack', t('Unacknowledged')],
				['ack', t('Acknowledged')]]) {
			this.#buildChoice(group, label, current === mode, '', () => {
				this.#runtime.ack = CWidgetEventTimeline.#ackFromMode(mode);
				this.#applyRuntimeChange();
			});
		}

		return group;
	}

	// 詳細: ホスト順 / 時系列（Tooltip詳細の並び。表示画面上の操作のみで、集計には影響しない）。
	#buildTipModeGroup() {
		const group = this.#buildGroup(t('Tooltip order'));

		for (const [mode, label] of [['host', t('By host')], ['time', t('Chronological')]]) {
			this.#buildChoice(group, label, this.#tip_mode === mode, '', () => {
				this.#tip_mode = mode;
				// Resetの有効/無効を更新するため、ツールバーを含めて再描画する（再取得は不要）。
				this.#render();
			});
		}

		return group;
	}

	// リセット: 集計・確認・深刻度・詳細を設定初期値へ戻す（「全ON」ではない）。設定値と同じ状態のときは無効。
	#buildResetGroup(data) {
		const configured = data.configured;
		const same = CWidgetEventTimeline.#sameSet(this.#runtime.severities, configured.severities)
			&& CWidgetEventTimeline.#ackMode(this.#runtime.ack) === CWidgetEventTimeline.#ackMode(configured.ack)
			&& this.#runtime.count_mode === configured.count_mode
			&& this.#runtime.mode === configured.mode
			&& this.#runtime.sort === configured.sort
			&& this.#runtime.page === 1
			&& this.#tip_mode === CWidgetEventTimeline.TIP_MODE_DEFAULT;

		const group = document.createElement('div');
		group.className = 'et-group';

		const button = document.createElement('button');

		button.type = 'button';
		button.className = 'et-btn et-reset';
		button.disabled = same;
		button.textContent = t('Reset conditions');
		button.title = t('Reset filters to the configured values');

		button.addEventListener('click', () => {
			this.#runtime = {
				severities: [...configured.severities],
				ack: [...configured.ack],
				count_mode: configured.count_mode,
				mode: configured.mode,
				sort: configured.sort,
				page: 1
			};
			this.#tip_mode = CWidgetEventTimeline.TIP_MODE_DEFAULT;
			this.#applyRuntimeChange();
		});

		group.appendChild(button);

		return group;
	}

	// 深刻度: Severity Filter 専用行。色付きラベルチップ（ON=Severity色で塗り / OFF=枠のみ・薄く）。
	// Hoverで正式名称と件数を表示する。件数はSeverity自身のON/OFFを除く現在条件での件数（OFFでも本来の件数を確認できる）。
	#buildSeverityRow(data) {
		const row = document.createElement('div');
		row.className = 'et-severity-row';
		row.setAttribute('role', 'group');
		row.setAttribute('aria-label', t('Severity level'));

		const labels = [t('Not classified (chip)'), t('Information (chip)'), t('Warning (chip)'),
			t('Average (chip)'), t('High (chip)'), t('Disaster (chip)')];

		for (const s of data.severities) {
			const on = this.#runtime.severities.includes(s.id);
			const count = (data.severity_counts ?? [])[s.id] ?? 0;
			const button = document.createElement('button');

			button.type = 'button';
			button.className = 'et-chip et-sev' + (on ? ' et-on' : ' et-off');
			button.style.setProperty('--et-sev-color', s.color);
			button.style.setProperty('--et-sev-text', CWidgetEventTimeline.#contrastText(s.color));
			button.textContent = labels[s.id] ?? s.name;
			button.title = s.name + '\n' + sprintf(t('%1$s events'), count);
			button.setAttribute('aria-pressed', on ? 'true' : 'false');

			button.addEventListener('click', () => {
				const list = this.#runtime.severities;

				this.#runtime.severities = list.includes(s.id) ? list.filter(v => v !== s.id) : [...list, s.id];
				this.#applyRuntimeChange();
			});

			row.appendChild(button);
		}

		return row;
	}

	// Severity色の背景に載せる文字色（明るい色には濃い文字、暗い色には白）。
	static #contrastText(hex) {
		const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);

		if (!m) {
			return '#fff';
		}

		const [r, g, b] = [m[1], m[2], m[3]].map(v => parseInt(v, 16));

		return (r * 299 + g * 587 + b * 114) / 1000 > 150 ? '#1f2c33' : '#fff';
	}

	// Event取得上限に達した場合、グラフは実際より少ない件数を示す。目立つ警告を常時表示する。
	#buildTruncationWarning(data) {
		const warn = document.createElement('div');
		warn.className = 'et-warning';
		warn.setAttribute('role', 'alert');
		warn.textContent = sprintf(
			t('Incomplete data: the event limit (%1$s) was reached. Events before %2$s are not counted in this graph.'),
			data.max_events, data.covered_from ?? ''
		);

		return warn;
	}

	// 文字色・線色は設定値。未設定ならテーマ（Light / Dark）に追従する。
	#resolveColors(style) {
		const computed = getComputedStyle(this._body);
		const text = style.text_color || computed.color;
		const line = style.line_color || CWidgetEventTimeline.#withAlpha(computed.color, 0.35);

		return {text, line, background: this.#resolveBackground()};
	}

	#resolveBackground() {
		let el = this._body;

		while (el) {
			const bg = getComputedStyle(el).backgroundColor;
			const m = bg.match(/rgba?\(([^)]+)\)/);

			if (m) {
				const parts = m[1].split(',').map(v => parseFloat(v));

				if (parts.length < 4 || parts[3] > 0) {
					return bg;
				}
			}

			el = el.parentElement;
		}

		return getComputedStyle(document.body).backgroundColor;
	}

	static #withAlpha(color, alpha) {
		const m = color.match(/rgba?\(([^)]+)\)/);

		if (!m) {
			return color;
		}

		const [r, g, b] = m[1].split(',').map(v => parseFloat(v));

		return `rgba(${r}, ${g}, ${b}, ${alpha})`;
	}

	static #escape(value) {
		return String(value).replace(/[&<>"']/g, c => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;'
		}[c]));
	}

	#buildOption(data) {
		const colors = this.#resolveColors(data.style);
		const buckets = data.buckets;

		// 積み上げ順は Severity 値の昇順（Not classified が最下段、Disaster が最上段）。
		const enabled = data.severities.filter(s => s.enabled);
		const series = enabled
			.map(s => ({
				name: s.name,
				type: 'bar',
				stack: 'severity',
				barCategoryGap: '20%',
				itemStyle: {color: s.color},
				// Hover中のSeverity領域だけを枠線で強調する（他Severityは消さない）。
				emphasis: {focus: 'none', itemStyle: {borderColor: colors.text, borderWidth: 2}},
				data: buckets.map(b => b.counts[s.id])
			}));

		// Y軸の下端は常に0。「Y軸最小値」は最大値として最低限確保するスケールで、データがそれを超える場合は
		// 固定せず自動スケール（データを切り捨てない）。
		const data_max = Math.max(0, ...buckets.map(b => enabled.reduce((sum, s) => sum + b.counts[s.id], 0)));
		const y_min_scale = data.style.y_axis_min;

		return {
			animation: false,
			backgroundColor: 'transparent',
			textStyle: {color: colors.text},
			grid: {left: 8, right: 16, top: 28, bottom: 8 + this.#periodRows(data).length * CWidgetEventTimeline.ROW_HEIGHT,
				containLabel: true},
			xAxis: {
				type: 'category',
				data: buckets.map(b => b.start),
				axisLine: {lineStyle: {color: colors.line}},
				axisTick: {alignWithLabel: true, lineStyle: {color: colors.line}},
				axisLabel: this.#axisLabelOption(data)
			},
			yAxis: {
				type: 'value',
				name: t('Event count'),
				nameTextStyle: {color: colors.text, align: 'left'},
				min: 0,
				max: y_min_scale > 0 && data_max <= y_min_scale ? y_min_scale : undefined,
				minInterval: 1,
				axisLine: {show: true, lineStyle: {color: colors.line}},
				axisTick: {show: true, lineStyle: {color: colors.line}},
				axisLabel: {color: colors.text, formatter: v => String(Math.round(v))},
				splitLine: {lineStyle: {color: colors.line, opacity: 0.6}}
			},
			tooltip: {
				trigger: 'item',
				confine: true,
				backgroundColor: colors.background,
				borderColor: colors.line,
				textStyle: {color: colors.text},
				formatter: params => this.#tooltipHtml(data, params, enabled)
			},
			series
		};
	}

	// 次元モード（By Host / By Host group / By Event）の棒グラフ。カテゴリは間引かず、長い名称は省略・回転する。
	// 完全な名称は軸ラベルのHoverとTooltipで確認できる。
	// X軸ラベルの向き。0=水平 / 45=斜め / 90=縦（0:自動 1:水平 2:斜め 3:縦）。
	// 自動は、水平のまま各ラベルが帯幅に収まる（文字幅の概算）なら水平、収まらなければ斜め。縦へは自動で切り替えない。
	#xLabelRotate(rows, band, mode) {
		if (mode === 1) {
			return 0;
		}

		if (mode === 2) {
			return 45;
		}

		if (mode === 3) {
			return 90;
		}

		const char_w = 7;
		const longest = Math.max(0, ...rows.map(r => [...String(r.label)]
			.reduce((w, c) => w + (c.charCodeAt(0) > 255 ? char_w * 2 : char_w), 0)));

		// 水平表示は帯幅に収まらないラベルが省略されるため、1ラベル最大でも帯幅で切る前提で、半分以上切れるなら斜めにする。
		return longest <= band - 6 || band >= longest * 0.6 + 6 ? 0 : 45;
	}

	#buildCategoryOption(data) {
		const colors = this.#resolveColors(data.style);
		const rows = data.category.rows;
		const by_event = this.#runtime.mode === CWidgetEventTimeline.MODE_EVENT;
		const enabled = data.severities.filter(s => s.enabled);
		const width = this.#chart.getWidth();
		const band = Math.max(width - 48, 1) / Math.max(rows.length, 1);
		const rotate = this.#xLabelRotate(rows, band, data.style.x_label);

		const series = enabled.map(s => ({
			name: s.name,
			type: 'bar',
			stack: 'severity',
			barCategoryGap: '20%',
			itemStyle: {color: s.color},
			emphasis: {focus: 'none', itemStyle: {borderColor: colors.text, borderWidth: 2}},
			data: rows.map(r => r.counts[s.id])
		}));

		const data_max = Math.max(0, ...rows.map(r => enabled.reduce((sum, s) => sum + r.counts[s.id], 0)));
		const y_min_scale = data.style.y_axis_min;

		return {
			animation: false,
			backgroundColor: 'transparent',
			textStyle: {color: colors.text},
			grid: {left: 8, right: 16, top: 28, bottom: 8, containLabel: true},
			xAxis: {
				type: 'category',
				data: rows.map(r => r.label),
				axisLine: {lineStyle: {color: colors.line}},
				axisTick: {alignWithLabel: true, lineStyle: {color: colors.line}},
				axisLabel: {
					color: colors.text,
					interval: 0,
					hideOverlap: false,
					rotate,
					width: rotate === 0 ? Math.max(band - 6, 24) : 130,
					overflow: 'truncate',
					ellipsis: '\u2026'
				},
				triggerEvent: true,
				tooltip: {show: true, formatter: p => CWidgetEventTimeline.#escape(p.value)}
			},
			yAxis: {
				type: 'value',
				name: by_event ? t('Affected hosts') : t('Event count'),
				nameTextStyle: {color: colors.text, align: 'left'},
				min: 0,
				max: y_min_scale > 0 && data_max <= y_min_scale ? y_min_scale : undefined,
				minInterval: 1,
				axisLine: {show: true, lineStyle: {color: colors.line}},
				axisTick: {show: true, lineStyle: {color: colors.line}},
				axisLabel: {color: colors.text, formatter: v => String(Math.round(v))},
				splitLine: {lineStyle: {color: colors.line, opacity: 0.6}}
			},
			tooltip: {
				trigger: 'item',
				confine: true,
				backgroundColor: colors.background,
				borderColor: colors.line,
				textStyle: {color: colors.text},
				formatter: params => this.#categoryTooltipHtml(data, params, enabled, by_event)
			},
			series
		};
	}

	// カテゴリTooltip: 名称（省略なし）、合計、Hoverしている深刻度の件数。By Event の件数は影響ホスト数。
	// Event mode（個別イベント／プロトタイプグループ共通）では、影響Host名の一覧（上限超過は「ほかN台」）を追加する。
	// プロトタイプグループのGroupでは、さらに該当イベント数（Filter後の実Event数）も示す（§20〜24）。
	#categoryTooltipHtml(data, params, enabled, by_event) {
		const p = Array.isArray(params) ? params[0] : params;

		if (p?.componentType === 'xAxis') {
			return `<div class="et-tip">${CWidgetEventTimeline.#escape(p.value)}</div>`;
		}

		const row = data.category.rows[p?.dataIndex];
		const severity = enabled[p?.seriesIndex];

		if (!row || !severity) {
			return '';
		}

		const esc = CWidgetEventTimeline.#escape;
		const total_label = by_event ? t('Affected hosts') : t('Event count');

		let html = `<div class="et-tip"><div class="et-tip-range">${esc(row.label)}</div>` +
			`<div class="et-tip-total"><b>${esc(total_label)}: ${row.total}</b></div>`;

		if (typeof row.event_count === 'number') {
			html += `<div class="et-tip-total"><b>${esc(t('Number of events'))}: ${row.event_count}</b></div>`;
		}

		html += `<div class="et-tip-row"><span class="et-tip-dot" style="background:${esc(severity.color)}"></span>` +
			`${esc(severity.name)}: ${row.counts[severity.id]}</div>`;

		if (Array.isArray(row.hosts)) {
			for (const host of row.hosts) {
				html += `<div class="et-tip-host">${esc(host)}</div>`;
			}

			if (row.hosts_more > 0) {
				html += `<div class="et-tip-more">${esc(sprintf(t('%1$s more hosts'), row.hosts_more))}</div>`;
			}
		}

		return html + '</div>';
	}

	static ROW_HEIGHT = 18;

	/**
	 * X軸ラベル。ラベルを表示するTickは幅から求めた一定間隔（step）で決め、ECharts任せの間引きは使わない。
	 * Tickには最小の単位（分・時Bucketは時刻、日・週・月Bucketは日付/月）だけを出し、
	 * それより上位の階層（月日・年）は下部の期間線（#periodGraphic）で示す。
	 */
	#axisLabelOption(data) {
		const buckets = data.buckets;
		const colors = this.#resolveColors(data.style);
		const width = this.#chart ? this.#chart.getWidth() : 0;

		const text = b => b.axis.time ?? b.axis.date;

		const label_width = Math.max(...buckets.map(b => text(b).length)) * 7 + 16;
		const plot_width = Math.max(width - 48, 1);
		const step = Math.max(1, Math.ceil(label_width * buckets.length / plot_width));

		return {
			color: colors.text,
			hideOverlap: false,
			interval: step - 1,
			lineHeight: 14,
			formatter: (value, index) => text(buckets[index])
		};
	}

	/**
	 * X軸下部に並べる期間線の行。上から、時刻Bucketでは 月日 → 年、日・週・月Bucketでは 年。
	 * 長期間表示向けに月などの階層を足せるよう、行は配列で表す。
	 */
	#periodRows(data) {
		const sub_day = data.interval.unit === 'minute' || data.interval.unit === 'hour';
		const rows = [];

		if (sub_day) {
			rows.push({key: b => b.axis.key, text: b => b.axis.date});
		}

		rows.push({key: b => b.axis.year, text: b => b.axis.year});

		return rows;
	}

	/**
	 * 期間線（グラフ内部へは延ばさず、X軸下部のみ）。値が同じ連続Bucketを1本の線にまとめ、境界で分割する。
	 * 位置はグリッド領域とBucket添字から求める（タイムゾーン変換はサーバー側で済んでいる）。
	 */
	#periodGraphic(data) {
		const colors = this.#resolveColors(data.style);
		const rows = this.#periodRows(data);
		const H = CWidgetEventTimeline.ROW_HEIGHT;
		const n = data.buckets.length;
		const rect = this.#chart.getModel().getComponent('grid', 0).coordinateSystem.getRect();
		const band = rect.width / n;
		const top = this.#chart.getHeight() - 8 - rows.length * H;
		const elements = [];

		rows.forEach((row, r) => {
			const y = top + r * H;
			let from = 0;

			for (let i = 1; i <= n; i++) {
				if (i < n && row.key(data.buckets[i]) === row.key(data.buckets[from])) {
					continue;
				}

				const x1 = rect.x + from * band;
				const x2 = rect.x + i * band;
				const label = String(row.text(data.buckets[from]));
				const line = {stroke: colors.line, lineWidth: 1};

				elements.push(
					{type: 'line', silent: true, z: 100, shape: {x1, y1: y + 2, x2, y2: y + 2}, style: line},
					// 区間の境界（先頭・末尾を含む）に短い縦線を付ける。
					{type: 'line', silent: true, z: 100, shape: {x1, y1: y + 2, x2: x1, y2: y + 8}, style: line},
					{type: 'line', silent: true, z: 100, shape: {x1: x2, y1: y + 2, x2, y2: y + 8}, style: line}
				);

				// 区間が狭くラベルが入りきらない場合は、ラベルだけ省略する（線は残す）。
				if (x2 - x1 >= label.length * 7 + 8) {
					elements.push({
						type: 'text', silent: true, z: 100,
						x: (x1 + x2) / 2, y: y + 5,
						style: {
							text: label, fill: colors.text, font: '12px sans-serif',
							textAlign: 'center', textVerticalAlign: 'top'
						}
					});
				}

				from = i;
			}
		});

		return {elements};
	}

	/**
	 * Hoverしているスタック部分（Severity）のEventだけを詳細に表示する。
	 * Total は Bucket 全体、Severity件数は集計値（詳細一覧の上限で切らない）。
	 */
	#tooltipHtml(data, params, enabled) {
		const p = Array.isArray(params) ? params[0] : params;
		const bucket = data.buckets[p?.dataIndex];
		const severity = enabled[p?.seriesIndex];

		if (!bucket || !severity) {
			return '';
		}

		const esc = CWidgetEventTimeline.#escape;
		const count = bucket.counts[severity.id];

		const continuous = data.runtime.count_mode === CWidgetEventTimeline.COUNT_CONTINUOUS;

		// 継続モードでは Bucket と重なる継続中のEventを数えていることを明示する。
		let html = `<div class="et-tip"><div class="et-tip-range">${esc(bucket.range)}</div>` +
			(continuous ? `<div class="et-tip-mode">${esc(t('Continuing events'))}</div>` : '') +
			`<div class="et-tip-total"><b>${esc(t('Total'))}: ${bucket.total}</b></div>` +
			`<div class="et-tip-row"><span class="et-tip-dot" style="background:${esc(severity.color)}"></span>` +
			`${esc(severity.name)}: ${count}</div>`;

		// 継続モード: 発生時刻 → 復旧時刻（未復旧は「継続中」）。
		const timeText = e => continuous
			? `${e.time} \u2192 ${e.end ?? t('Ongoing')}`
			: e.time;

		const events = bucket.details[severity.id] ?? [];

		if (this.#tip_mode === 'time') {
			// 時系列: Hostでまとめず発生時刻順（サーバーが時刻昇順で返す）。複数Host関連Eventは1行にHostを併記。
			for (const e of events) {
				const hosts = e.hosts.length ? e.hosts.join(', ') : t('Unknown host');

				html += `<div class="et-tip-event et-tip-flat"><span class="et-tip-time">${esc(timeText(e))}</span>` +
					`<span class="et-tip-hostname">${esc(hosts)}</span><span class="et-tip-name">${esc(e.name)}</span></div>`;
			}
		}
		else {
			// Host単位: Hostは A-Z 順、Host内は発生時刻順。複数Host関連Eventは関連Hostすべての下に表示（件数は増やさない）。
			const groups = new Map();

			for (const e of events) {
				for (const host of (e.hosts.length ? e.hosts : [t('Unknown host')])) {
					if (!groups.has(host)) {
						groups.set(host, []);
					}

					groups.get(host).push(e);
				}
			}

			for (const host of [...groups.keys()].sort((a, b) => a.localeCompare(b))) {
				html += `<div class="et-tip-host">${esc(host)}</div>`;

				for (const e of groups.get(host)) {
					html += `<div class="et-tip-event"><span class="et-tip-time">${esc(timeText(e))}</span>` +
						`<span class="et-tip-name">${esc(e.name)}</span></div>`;
				}
			}
		}

		if (count > events.length) {
			html += `<div class="et-tip-more">${esc(sprintf(t('And %1$s more events'), count - events.length))}</div>`;
		}

		return html + '</div>';
	}
}
