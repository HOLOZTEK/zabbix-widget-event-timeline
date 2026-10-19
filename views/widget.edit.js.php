<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

?>
window.widget_event_timeline_form = new class {

	init({form_tabs_id, error_fields}) {
		// フォームIDは Zabbix 7.0 では 'widget-dialogue-form'、8.0以降では 'widget-form'。
		this._form = document.getElementById('widget-dialogue-form') ?? document.getElementById('widget-form');
		this._tabs = document.getElementById(form_tabs_id);
		this._error_fields = error_fields;

		// タブ切替時に開いたままの Color Picker（7.0の jQuery colorpicker）を閉じる（Zabbix標準の円グラフと同じ）。
		jQuery(this._tabs).on('tabsactivate', () => {
			if (typeof jQuery.colorpicker === 'function') {
				jQuery.colorpicker('hide');
			}
		});

		this._watchErrors();

		// Zabbix 7.0: CWidgetFieldColorView は Color Picker の初期化を行わないため、ここで jQuery colorpicker を初期化する。
		// Zabbix 8.0以降: <z-color-picker> が自己初期化し、jQuery colorpicker は存在しない。
		// PHP定数 ZBX_STYLE_COLOR_PICKER は8.0で廃止されたため使わない（クラス名 'color-picker' は両バージョン共通）。
		// 保存値は「RRGGBB」または空（空 = 自動 = Zabbixテーマに追従）。
		// 「自動」チェック・Hex入力・Resetは表示用の部品で、保存されるのは隠し入力の値のみ。
		const colorpickers = [...this._form.querySelectorAll('.color-picker input')];

		if (typeof jQuery.fn.colorpicker === 'function') {
			for (const colorpicker of colorpickers) {
				$(colorpicker).colorpicker({
					appendTo: '.overlay-dialogue-body',
					use_default: false
				});
			}
		}

		for (const colorpicker of colorpickers) {
			this._enhance(colorpicker);
		}
	}

	/**
	 * 適用時のエラー表示（Zabbix標準: フォーム直前に挿入される .msg-bad）を監視し、エラーのある設定項目が属するタブを示す。
	 * 補助表示であり、検証そのものは Zabbix のサーバー側検証に任せる。上部のエラー表示は変更しない。
	 */
	_watchErrors() {
		const container = this._form.parentNode;

		if (container === null) {
			return;
		}

		const update = () => {
			try {
				this._showErrorTabs(container.querySelector(':scope > .msg-bad'));
			}
			catch (e) {
				// 補助表示の失敗で設定画面を壊さない（Zabbix標準のエラー表示はそのまま残る）。
			}
		};

		new MutationObserver(update).observe(container, {childList: true});
		update();
	}

	/**
	 * エラー文に含まれる項目名（Invalid parameter "<項目名>": ... の引用部分）から所属タブを特定する。
	 * 項目名を特定できないエラー（タブ外の共通項目など）では、タブの切替も印付けも行わない。
	 */
	_showErrorTabs(message_box) {
		for (const tab of this._tabs.querySelectorAll('.et-tab-error')) {
			tab.classList.remove('et-tab-error');
		}

		for (const mark of this._tabs.querySelectorAll('.et-tab-error-mark')) {
			mark.remove();
		}

		if (message_box === null) {
			return;
		}

		const lines = [...message_box.querySelectorAll('li')].map(li => li.textContent);

		if (lines.length === 0) {
			lines.push(message_box.textContent);
		}

		// 引用された項目名。期間のサブ項目は "Time period/From" の形になるため、先頭の項目名でも照合する。
		const quoted = new Set();

		for (const line of lines) {
			for (const match of line.matchAll(/["“「]([^"”」]+)["”」]/g)) {
				quoted.add(match[1].trim());
				quoted.add(match[1].split('/')[0].trim());
			}
		}

		const errors = this._error_fields.filter(field => field.label !== null && quoted.has(field.label));

		if (errors.length === 0) {
			return;
		}

		const panels = [...this._tabs.querySelectorAll(':scope > div[id]')];

		for (const {tab} of errors) {
			const link = this._tabs.querySelector(`a[href="#${tab}"]`);

			if (link !== null && !link.closest('li').classList.contains('et-tab-error')) {
				// 印の色は Zabbix のエラー色（テーマごとの .red）に従う。
				const mark = document.createElement('span');

				mark.className = 'et-tab-error-mark red';
				mark.textContent = '\u25CF';
				mark.title = <?= json_encode(_et('This tab contains errors.')) ?>;
				link.append(mark);
				link.closest('li').classList.add('et-tab-error');
			}
		}

		// フォーム順で最初のエラー項目のタブを開き、その入力欄へフォーカスする。
		const first = errors[0];
		const index = panels.findIndex(panel => panel.id === first.tab);

		if (index === -1) {
			return;
		}

		jQuery(this._tabs).tabs('option', 'active', index);

		const input = [...panels[index].querySelectorAll(`[name="${first.name}"], [name^="${first.name}["]`)]
			.find(element => element.type !== 'hidden' && !element.disabled && element.offsetParent !== null);

		if (input !== undefined) {
			input.focus({preventScroll: false});
		}
	}

	/**
	 * リセット時の固定の初期色（RRGGBB）。線と文字で別の色を持つ。
	 */
	_defaultColor(name) {
		return name.includes('text_color') ? '666699' : '90A4AE';
	}

	_enhance(hidden) {
		const HEX = /^#?([0-9a-fA-F]{6})$/;
		// Zabbix 8.0以降は hidden が <z-color-picker> 内部の隠し入力になる。隠し入力の値は要素の再描画時に
		// color 属性の値で上書きされるため、値の変更は picker.color 経由で行う。
		const picker = hidden.closest('z-color-picker');
		const preview = document.getElementById('lbl_' + (picker !== null ? hidden.name : hidden.id));
		const holder = picker ?? hidden.parentNode;

		// 値を確定し、Color Picker 本体からの変更と同じ change イベントを発火する。
		const setValue = value => {
			if (picker !== null) {
				picker.color = value;
				picker.dispatchEvent(new Event('change', {bubbles: true}));
			}
			else {
				hidden.value = value;
				$(hidden).trigger('change');
			}
		};

		const auto_label = document.createElement('label');
		auto_label.className = 'et-color-auto';
		const auto = document.createElement('input');
		auto.type = 'checkbox';
		auto_label.append(auto, ' ' + <?= json_encode(_et('Auto')) ?>);

		const hex = document.createElement('input');
		hex.type = 'text';
		hex.className = 'et-color-hex';
		hex.maxLength = 7;
		hex.placeholder = '#RRGGBB';
		hex.setAttribute('aria-label', <?= json_encode(_et('Hex color')) ?>);
		hex.setAttribute('autocomplete', 'off');
		hex.setAttribute('spellcheck', 'false');

		const reset = document.createElement('button');
		reset.type = 'button';
		reset.className = 'btn-alt et-color-reset';
		reset.textContent = '\u21B6';
		reset.title = <?= json_encode(_et('Reset')) ?>;
		reset.setAttribute('aria-label', <?= json_encode(_et('Reset')) ?>);

		const error = document.createElement('span');
		error.className = 'et-color-error';
		error.hidden = true;
		error.textContent = <?= json_encode(_et('Enter a 6-digit hex color such as #FF0000.')) ?>;

		let last_custom = hidden.value || this._defaultColor(hidden.name);

		const show = () => {
			const is_auto = hidden.value === '';

			auto.checked = is_auto;
			hex.disabled = is_auto;
			reset.disabled = is_auto;

			if (preview) {
				preview.disabled = is_auto;
			}

			hex.value = is_auto ? '' : '#' + hidden.value.toUpperCase();
			error.hidden = true;
			hex.classList.remove('et-color-invalid');
		};

		const commit = value => {
			setValue(value);
			show();
		};

		auto.addEventListener('change', () => {
			if (auto.checked) {
				if (hidden.value !== '') {
					last_custom = hidden.value;
				}
				commit('');
			}
			else {
				commit(last_custom);
			}
		});

		reset.addEventListener('click', () => {
			// 色指定だけを、線・文字それぞれの固定の初期色へ戻す。自動/カスタムの切替（「自動」チェック）は変更しない。
			last_custom = this._defaultColor(hidden.name);
			commit(last_custom);
		});

		hex.addEventListener('input', () => {
			const m = HEX.exec(hex.value.trim());

			if (m) {
				hex.classList.remove('et-color-invalid');
				error.hidden = true;
				last_custom = m[1].toUpperCase();
				setValue(last_custom);
			}
			else {
				// 不正値は保存させない: 隠し入力へそのまま入れ、サーバー検証でエラーにする（JS例外は出さない）。
				hex.classList.add('et-color-invalid');
				error.hidden = false;
				hidden.value = hex.value.trim() === '' ? '?' : hex.value.trim();
			}
		});

		hex.addEventListener('blur', () => {
			const m = HEX.exec(hex.value.trim());

			if (m) {
				hex.value = '#' + m[1].toUpperCase();
			}
		});

		// Color Picker側の変更をHex入力へ反映する（8.0以降は <z-color-picker> 要素が change を発火する）。
		$(picker ?? hidden).on('change', () => {
			if (hidden.value !== '' && HEX.test(hidden.value)) {
				last_custom = hidden.value;
				show();
			}
		});

		const wrap = document.createElement('div');
		wrap.className = 'et-color-controls';
		// 指示書の表示例どおり 1行: [色見本] [#RRGGBB] [↶] [自動]（狭い場合は折り返す）。
		holder.before(wrap);
		wrap.append(holder, hex, reset, auto_label, error);

		show();
	}
};
