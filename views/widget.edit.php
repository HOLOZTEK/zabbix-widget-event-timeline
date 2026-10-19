<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\EventTimeline\Includes\CWidgetFieldMultiSelectTriggerView;
use Modules\EventTimeline\Includes\CWidgetFieldWeekStartView;

$form = new CWidgetFormView($data);

/*
 * 設定画面はタブ構成（対象 / 表示 / フィルター / データ / 外観）。
 * タイプ・名前・リフレッシュ間隔・ヘッダーを表示は Zabbix 標準 Widget（グラフ・円グラフ）と同じくタブの外に置く。
 * 各タブの中身は Zabbix 標準と同じ CFormGrid で、Accordion は使わない。
 * 非選択タブは非表示になるだけで DOM は残るため、タブを切り替えても未適用の入力値は失われない。
 */
$tabs = [
	'targets' => [
		'label' => _et('Targets'),
		'fields' => [
			new CWidgetFieldMultiSelectHostView($data['fields']['hostids']),
			new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']),
			new CWidgetFieldMultiSelectTriggerView($data['fields']['triggerids'])
		]
	],
	'display' => [
		'label' => _et('Display'),
		'fields' => [
			new CWidgetFieldSelectView($data['fields']['display_mode']),
			new CWidgetFieldSelectView($data['fields']['event_display']),
			new CWidgetFieldSelectView($data['fields']['sort_order']),
			new CWidgetFieldSelectView($data['fields']['count_mode']),
			// ページング相当の設定。独立したタブや見出しにはせず、区切り線だけで分ける。
			null,
			new CWidgetFieldSelectView($data['fields']['page_size']),
			new CWidgetFieldSelectView($data['fields']['auto_page']),
			new CWidgetFieldSelectView($data['fields']['detail_page_size'])
		]
	],
	'filters' => [
		'label' => _et('Filters'),
		'fields' => [
			new CWidgetFieldCheckBoxListView($data['fields']['severities']),
			new CWidgetFieldCheckBoxListView($data['fields']['ack_states']),
			(new CWidgetFieldCheckBoxListView($data['fields']['value_types']))
				->setColumns(2)
				->setFieldHint(makeHelpIcon(_et('If nothing is selected, all are included.'))),
			(new CWidgetFieldCheckBoxListView($data['fields']['item_types']))
				->setColumns(2)
				->setFieldHint(makeHelpIcon(_et('If nothing is selected, all are included.')))
		]
	],
	'data' => [
		'label' => _et('Data'),
		'fields' => [
			new CWidgetFieldIntegerBoxView($data['fields']['max_events']),
			new CWidgetFieldTimePeriodView($data['fields']['time_period']),
			new CWidgetFieldWeekStartView($data['fields']['week_start'])
		]
	],
	'appearance' => [
		'label' => _et('Appearance'),
		'fields' => [
			new CWidgetFieldIntegerBoxView($data['fields']['graph_height']),
			new CWidgetFieldIntegerBoxView($data['fields']['y_axis_min']),
			new CWidgetFieldSelectView($data['fields']['x_label']),
			null,
			new CWidgetFieldColorView($data['fields']['line_color']),
			new CWidgetFieldColorView($data['fields']['text_color'])
		]
	]
];

$form_tabs = (new CTabView(['id' => 'et_config_tabs']))->addClass('et-config-tabs');

// エラー文（Invalid parameter "<項目名>": ...）から所属タブを特定するための対応表。フォーム順に並べる。
$error_fields = [];

foreach ($tabs as $tab_id => $tab) {
	$form_grid = new CFormGrid();

	foreach ($tab['fields'] as $field_view) {
		if ($field_view === null) {
			$form_grid->addItem((new CDiv())->addClass('et-form-separator'));

			continue;
		}

		$form->registerField($field_view);

		foreach ($field_view->getViewCollection() as ['label' => $label, 'view' => $view, 'class' => $class]) {
			$form_grid->addItem([$label, (new CFormField($view))->addClass($class)]);
		}

		$name = $field_view->getName();
		$error_fields[] = ['label' => $data['fields'][$name]->getLabel(), 'name' => $name, 'tab' => 'et_tab_'.$tab_id];
	}

	$form_tabs->addTab('et_tab_'.$tab_id, $tab['label'], $form_grid);
}

// 設定画面を開いたときは常に先頭タブ（対象）から表示する。
$form_tabs->setSelected(0);

$form
	->addItem($form_tabs)
	->addJavaScript($form_tabs->makeJavascript())
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_event_timeline_form.init('.json_encode([
		'form_tabs_id' => $form_tabs->getId(),
		'error_fields' => $error_fields
	], JSON_THROW_ON_ERROR).');')
	->show();
