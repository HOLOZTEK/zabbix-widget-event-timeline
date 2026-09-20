<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\EventTimeline\Includes\CWidgetFieldMultiSelectBroadcastSourceView;

(new CWidgetFormView($data))
	// Monitoring target: Source（Fixed / Widget）。表示する入力は widget.edit.js.php が Source に合わせて切り替える。
	->addField(
		new CWidgetFieldSelectView($data['fields']['host_source'])
	)
	->addField(
		(new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))->addRowClass('js-row-host-fixed')
	)
	->addField(
		(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))->addRowClass('js-row-host-fixed')
	)
	->addField(
		(new CWidgetFieldMultiSelectBroadcastSourceView($data['fields']['tn_hostids']))
			->addRowClass('js-row-host-widget')
	)
	// Event: Source（Fixed / Widget）。
	->addField(
		new CWidgetFieldSelectView($data['fields']['event_source'])
	)
	->addField(
		(new CWidgetFieldTextAreaView($data['fields']['event_names']))->addRowClass('js-row-event-fixed')
	)
	->addField(
		(new CWidgetFieldMultiSelectBroadcastSourceView($data['fields']['en_eventids']))
			->addRowClass('js-row-event-widget')
	)
	// Filterの初期状態: 表示画面のRuntime Filterの初期値（Event取得条件ではない）。
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_et('Initial filter state')))
			->addField(
				new CWidgetFieldCheckBoxListView($data['fields']['severities'])
			)
			->addField(
				new CWidgetFieldCheckBoxListView($data['fields']['ack_states'])
			)
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['count_mode'])
	)
	->addField(
		new CWidgetFieldIntegerBoxView($data['fields']['max_events'])
	)
	->addField(
		new CWidgetFieldTimePeriodView($data['fields']['time_period'])
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_et('Appearance')))
			->addField(
				new CWidgetFieldIntegerBoxView($data['fields']['graph_height'])
			)
			->addField(
				new CWidgetFieldIntegerBoxView($data['fields']['y_axis_min'])
			)
			->addField(
				new CWidgetFieldColorView($data['fields']['line_color'])
			)
			->addField(
				new CWidgetFieldColorView($data['fields']['text_color'])
			)
	)
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_event_timeline_form.init();')
	->show();
