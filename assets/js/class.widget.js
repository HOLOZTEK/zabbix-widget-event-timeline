'use strict';

class CWidgetEventTimeline extends CWidget {

	#chart = null;
	#data = null;

	// Runtime状態（設定値ではない）。Tooltip詳細の並び: 'host'=Host単位（既定）/ 'time'=時系列。
	// 再描画（自動更新）をまたいで維持するため、インスタンスに保持する。
	#tip_mode = 'host';

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
			title.textContent = t('No events');
			empty.appendChild(title);

			if (data.empty_reason === 'no_hosts') {
				const sub = document.createElement('div');
				sub.className = 'et-empty-sub';
				sub.textContent = t('No target hosts.');
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

	#buildToolbar(data) {
		const bar = document.createElement('div');
		bar.className = 'et-toolbar';

		const interval = document.createElement('span');
		interval.className = 'et-interval';
		interval.textContent = t('Interval') + ': ' + data.interval.label;

		const total = document.createElement('span');
		total.className = 'et-total';
		total.textContent = t('Total') + ': ' + data.total;

		// Runtime操作領域。今後の操作（Severity Filter 等）もここへ並べる。
		const controls = document.createElement('div');
		controls.className = 'et-controls';
		controls.appendChild(this.#buildTipModeButton());
		controls.appendChild(interval);

		bar.appendChild(total);
		bar.appendChild(controls);

		return bar;
	}

	// Tooltip詳細の並び順切替（Tooltip内に操作UIを置くとMouse移動で閉じるため、表示画面上に置く）。
	#buildTipModeButton() {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'et-tipmode';

		const update = () => {
			const by_host = this.#tip_mode === 'host';
			const label = by_host
				? t('Tooltip details: grouped by host (click to sort chronologically)')
				: t('Tooltip details: chronological (click to group by host)');

			button.title = label;
			button.setAttribute('aria-label', label);
			button.dataset.mode = this.#tip_mode;
			// 左: Host単位（見出し＋インデント行）/ 右: 時系列（時刻順の平坦な行）を示すアイコン。
			button.innerHTML = by_host
				? '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><g fill="currentColor">' +
					'<rect x="1" y="2" width="8" height="2"/><rect x="4" y="6" width="11" height="2"/>' +
					'<rect x="1" y="10" width="8" height="2"/><rect x="4" y="14" width="11" height="1.5"/></g></svg>'
				: '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><g fill="currentColor">' +
					'<rect x="1" y="2" width="14" height="2"/><rect x="1" y="7" width="14" height="2"/>' +
					'<rect x="1" y="12" width="14" height="2"/></g></svg>';
		};

		button.addEventListener('click', () => {
			this.#tip_mode = this.#tip_mode === 'host' ? 'time' : 'host';
			update();
		});

		update();

		return button;
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

		let html = `<div class="et-tip"><div class="et-tip-range">${esc(bucket.range)}</div>` +
			`<div class="et-tip-total"><b>${esc(t('Total'))}: ${bucket.total}</b></div>` +
			`<div class="et-tip-row"><span class="et-tip-dot" style="background:${esc(severity.color)}"></span>` +
			`${esc(severity.name)}: ${count}</div>`;

		const events = bucket.details[severity.id] ?? [];

		if (this.#tip_mode === 'time') {
			// 時系列: Hostでまとめず発生時刻順（サーバーが時刻昇順で返す）。複数Host関連Eventは1行にHostを併記。
			for (const e of events) {
				const hosts = e.hosts.length ? e.hosts.join(', ') : t('Unknown host');

				html += `<div class="et-tip-event et-tip-flat"><span class="et-tip-time">${esc(e.time)}</span>` +
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
					html += `<div class="et-tip-event"><span class="et-tip-time">${esc(e.time)}</span>` +
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
