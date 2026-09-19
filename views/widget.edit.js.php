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
		// 未設定（空）= Zabbix テーマに追従、を「デフォルトを使用」として扱う。
		for (const colorpicker of this._form.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input')) {
			$(colorpicker).colorpicker({
				appendTo: '.overlay-dialogue-body',
				use_default: true
			});
		}
	}
};
