<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

?>
window.widget_event_timeline_form = new class {

	init() {
		this._form = document.getElementById('widget-dialogue-form');

		// CWidgetFieldColorView は Color Picker の初期化を行わないため、ここで初期化する。
		// 保存値は「RRGGBB」または空（空 = 自動 = Zabbixテーマに追従）。
		// 「自動」チェック・Hex入力・Resetは表示用の部品で、保存されるのは隠し入力の値のみ。
		for (const colorpicker of this._form.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input')) {
			$(colorpicker).colorpicker({
				appendTo: '.overlay-dialogue-body',
				use_default: false
			});

			this._enhance(colorpicker);
		}
	}

	/**
	 * 現在のテーマでの「自動」の見た目に相当する色（RRGGBB）。線と文字で別の色になる。
	 * 文字 = 本文色、線 = 本文色を背景へ35%重ねた色（グラフの自動色と同じ考え方）。
	 */
	_defaultColor(name) {
		const rgb = value => (value.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
		const hex = c => c.map(v => Math.round(v).toString(16).padStart(2, '0')).join('').toUpperCase();

		const fg = rgb(getComputedStyle(this._form).color);

		if (fg.length < 3) {
			return null;
		}

		if (name.includes('text_color')) {
			return hex(fg);
		}

		let bg = [255, 255, 255];

		for (let el = this._form; el; el = el.parentElement) {
			const m = getComputedStyle(el).backgroundColor.match(/rgba?\(([^)]+)\)/);

			if (m) {
				const parts = m[1].split(',').map(Number);

				if (parts.length < 4 || parts[3] > 0) {
					bg = parts.slice(0, 3);
					break;
				}
			}
		}

		return hex(fg.map((v, i) => v * 0.35 + bg[i] * 0.65));
	}

	_enhance(hidden) {
		const HEX = /^#?([0-9a-fA-F]{6})$/;
		const DEFAULT_CUSTOM = '888888';
		const preview = document.getElementById('lbl_' + hidden.id);
		const holder = hidden.parentNode;

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

		let last_custom = hidden.value || DEFAULT_CUSTOM;

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
			hidden.value = value;
			$(hidden).trigger('change');
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
			// 色指定だけを、現在のテーマでの自動色に相当する色（線・文字で別々）へ戻す。自動/カスタムの切替（「自動」チェック）は変更しない。
			last_custom = this._defaultColor(hidden.name) ?? DEFAULT_CUSTOM;
			commit(last_custom);
		});

		hex.addEventListener('input', () => {
			const m = HEX.exec(hex.value.trim());

			if (m) {
				hex.classList.remove('et-color-invalid');
				error.hidden = true;
				last_custom = m[1].toUpperCase();
				hidden.value = last_custom;
				$(hidden).trigger('change');
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

		// Color Picker側の変更をHex入力へ反映する。
		$(hidden).on('change', () => {
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
