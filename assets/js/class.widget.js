'use strict';

class CWidgetEventTimeline extends CWidget {

	#chart = null;
	#data = null;

	// Runtime状態（設定値ではない）。Tooltip詳細の並び: 'host'=Host単位（既定）/ 'time'=時系列。
	// 再描画（自動更新）をまたいで維持するため、インスタンスに保持する。
	#tip_mode = 'host';

	// Runtime Filter / Count Mode（設定値ではない。設定を書き換えず、設定値を初期値として最初の応答で作る）。
	// 通常のWidget更新（Refresh）では維持し、ダッシュボードの再表示・Widget再生成で設定値から初期化し直す。
	// 形式: {severities: number[], ack: number[], count_mode: number}
	#runtime = null;

	// Widget Source の参照（Tree Navigator / Event Navigator）。Selectionが未受信でも更新を止めず、
	// サーバーへ空の Selection として渡して「対象が選択されていません」を表示させる（全Host・全Eventへは広げない）。
	static SELECTION_PATHS = ['tn_hostids', 'tn_groupids', 'en_eventids', 'en_triggerids'];

	// 発生イベント数 / 継続イベント加算（WidgetForm::COUNT_* と同じ値）。
	static COUNT_OCCURRENCE = 0;
	static COUNT_CONTINUOUS = 1;

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
		}

		return fields;
	}

	onStop() {
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

		if (this.#runtime === null) {
			// 初回のみ設定値（configured_filter / 初期モード）から runtime を作る。
			this.#runtime = {
				severities: [...this.#data.configured.severities],
				ack: [...this.#data.configured.ack],
				count_mode: this.#data.configured.count_mode
			};
		}

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

		if (data.total === 0) {
			const empty = document.createElement('div');
			empty.className = 'et-empty';

			const title = document.createElement('div');
			// 「対象が選択されていません」と「イベントはありません」は別の状態として表示する。
			title.textContent = data.empty_reason === 'no_selection' ? t('No target selected') : t('No events');
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

			return;
		}

		const holder = document.createElement('div');
		holder.className = 'et-chart';
		holder.style.height = data.style.graph_height + 'px';

		this._body.appendChild(holder);

		this.#chart = echarts.init(holder, null, {renderer: 'canvas'});
		this.#data = data;
		this.#chart.setOption(this.#buildOption(data));
		// 期間線はグリッド領域が確定してから描画する。
		this.#chart.setOption({graphic: this.#periodGraphic(data)}, {replaceMerge: ['graphic']});
	}

	// Runtime操作領域（2行構成）。
	// 1行目: 集計 / 確認 / 詳細 / リセット（機能ごとのグループを横並び）と、右端の合計・間隔。
	// 2行目: 深刻度（Severity Filter）専用行。
	#buildToolbar(data) {
		const bar = document.createElement('div');
		bar.className = 'et-toolbar';

		const row1 = document.createElement('div');
		row1.className = 'et-controls';
		row1.appendChild(this.#buildCountModeGroup());
		row1.appendChild(this.#buildAckGroup());
		row1.appendChild(this.#buildTipModeGroup());
		row1.appendChild(this.#buildResetGroup(data));

		const info = document.createElement('div');
		info.className = 'et-info';

		const total = document.createElement('span');
		total.className = 'et-total';
		total.textContent = t('Total') + ': ' + data.total;

		const interval = document.createElement('span');
		interval.className = 'et-interval';
		interval.textContent = t('Interval') + ': ' + data.interval.label;

		info.appendChild(total);
		info.appendChild(interval);
		row1.appendChild(info);

		bar.appendChild(row1);
		bar.appendChild(this.#buildSeverityRow(data));

		return bar;
	}

	// Runtime の変更を反映する。表示（ボタン状態）は即時に更新し、集計は再取得（Filter → Aggregation → Rendering）する。
	#applyRuntimeChange() {
		if (this.#data) {
			this.#render();
		}

		this._startUpdating();
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

	// ラベル付きグループ: 「ラベル [ボタン][ボタン]…」。
	#buildGroup(label) {
		const group = document.createElement('div');
		group.className = 'et-group';
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', label);

		const caption = document.createElement('span');
		caption.className = 'et-group-label';
		caption.textContent = label;
		group.appendChild(caption);

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
				count_mode: configured.count_mode
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

		const caption = document.createElement('span');
		caption.className = 'et-group-label';
		caption.textContent = t('Severity level');
		row.appendChild(caption);

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
