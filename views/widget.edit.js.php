<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

?>
window.widget_event_timeline_form = new class {

	init() {
		// フォームIDは Zabbix 7.0 では 'widget-dialogue-form'、8.0以降では 'widget-form'。
		this._form = document.getElementById('widget-dialogue-form') ?? document.getElementById('widget-form');

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
