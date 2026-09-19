'use strict';

class CWidgetEventTimeline extends CWidget {

	#chart = null;
	#data = null;

	onStop() {
		this.#disposeChart();
	}

	onResize() {
		if (this.#chart) {
			requestAnimationFrame(() => {
				if (this.#chart) {
					this.#chart.resize();
					// 表示されるTickは幅で変わるため、X軸ラベルの間引きと変化分判定をやり直す。
					this.#chart.setOption({xAxis: {axisLabel: this.#axisLabelOption(this.#data)}});
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

		bar.appendChild(total);
		bar.appendChild(interval);

		return bar;
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
		const series = data.severities
			.filter(s => s.enabled)
			.map(s => ({
				name: s.name,
				type: 'bar',
				stack: 'severity',
				barCategoryGap: '20%',
				itemStyle: {color: s.color},
				emphasis: {focus: 'none'},
				data: buckets.map(b => b.counts[s.id])
			}));

		return {
			animation: false,
			backgroundColor: 'transparent',
			textStyle: {color: colors.text},
			grid: {left: 8, right: 16, top: 28, bottom: 8, containLabel: true},
			xAxis: {
				type: 'category',
				name: t('Time'),
				nameLocation: 'end',
				nameTextStyle: {color: colors.text, align: 'right', verticalAlign: 'top', padding: [22, 0, 0, 0]},
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
				minInterval: 1,
				axisLine: {show: true, lineStyle: {color: colors.line}},
				axisTick: {show: true, lineStyle: {color: colors.line}},
				axisLabel: {color: colors.text, formatter: v => String(Math.round(v))},
				splitLine: {lineStyle: {color: colors.line, opacity: 0.6}}
			},
			tooltip: {
				trigger: 'axis',
				axisPointer: {type: 'shadow', shadowStyle: {color: colors.line, opacity: 0.25}},
				confine: true,
				backgroundColor: colors.background,
				borderColor: colors.line,
				textStyle: {color: colors.text},
				formatter: params => this.#tooltipHtml(data, params)
			},
			series
		};
	}

	/**
	 * X軸ラベル。ラベルを表示するTickは幅から求めた一定間隔（step）で決め、ECharts任せの間引きは使わない。
	 * こうして「実際に表示されるTick」が確定するので、各ラベルは直前に表示されたTick（index - step）と
	 * 比較し、変化した要素（時間 → 月日 → 年の階層）だけを追加する。先頭Tickは全要素を表示する。
	 */
	#axisLabelOption(data) {
		const buckets = data.buckets;
		const colors = this.#resolveColors(data.style);
		const width = this.#chart ? this.#chart.getWidth() : 0;

		const lines = (b, prev) => {
			const a = b.axis;
			const out = [];

			if (a.time !== null) {
				out.push(a.time);
			}

			if (!prev || prev.axis.key !== a.key) {
				out.push(a.date);
			}

			if (!prev || prev.axis.year !== a.year) {
				out.push(a.year);
			}

			return out;
		};

		// 全Tickを表示したと仮定した最大ラベル幅（全要素を含む最悪ケース）から間引き間隔を決める。
		const label_width = Math.max(...buckets.map(b => Math.max(...lines(b, null).map(l => l.length)))) * 7 + 16;
		const plot_width = Math.max(width - 48, 1);
		const step = Math.max(1, Math.ceil(label_width * buckets.length / plot_width));

		return {
			color: colors.text,
			hideOverlap: false,
			interval: step - 1,
			lineHeight: 14,
			formatter: (value, index) => {
				const prev = index >= step ? buckets[index - step] : null;

				return lines(buckets[index], prev).join('\n');
			}
		};
	}

	#tooltipHtml(data, params) {
		const index = Array.isArray(params) ? params[0]?.dataIndex : params?.dataIndex;
		const bucket = data.buckets[index];

		if (!bucket) {
			return '';
		}

		const esc = CWidgetEventTimeline.#escape;

		let html = `<div class="et-tip"><div class="et-tip-range">${esc(bucket.range)}</div>` +
			`<div class="et-tip-total"><b>${esc(t('Total'))}: ${bucket.total}</b></div>`;

		// 上に積まれている（重要度の高い）Severity から順に表示する。0件のSeverityは省略する。
		for (const s of [...data.severities].reverse()) {
			const count = bucket.counts[s.id];

			if (s.enabled && count > 0) {
				html += `<div class="et-tip-row"><span class="et-tip-dot" style="background:${esc(s.color)}"></span>` +
					`${esc(s.name)}: ${count}</div>`;
			}
		}

		return html + '</div>';
	}
}
