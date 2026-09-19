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
			// 特定のRGB値を書き戻さず、Custom指定そのものを解除する。
			commit('');
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
		// 指示書の表示例どおり 1行: [自動] [色見本] [#RRGGBB] [↶]（狭い場合は折り返す）。
		holder.before(wrap);
		wrap.append(auto_label, holder, hex, reset, error);

		show();
	}
};
