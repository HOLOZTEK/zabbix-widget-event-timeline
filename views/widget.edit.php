<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\EventTimeline\Includes\{CWidgetFieldMultiSelectBroadcastSourceView, CWidgetFieldMultiSelectTriggerView};

(new CWidgetFormView($data))
	// 対象は必須の設定なので、折りたたみにせず常に表示する。
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
		(new CWidgetFieldMultiSelectBroadcastSourceView($data['fields']['tn_hostids']))->addRowClass('js-row-host-widget')
	)
	->addField(
		new CWidgetFieldSelectView($data['fields']['event_source'])
	)
	->addField(
		(new CWidgetFieldMultiSelectTriggerView($data['fields']['triggerids']))->addRowClass('js-row-event-fixed')
	)
	->addField(
		(new CWidgetFieldMultiSelectBroadcastSourceView($data['fields']['en_triggerids']))->addRowClass('js-row-event-widget')
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_et('Initial display state')))
			->addField(
				new CWidgetFieldSelectView($data['fields']['display_mode'])
			)
			->addField(
				new CWidgetFieldSelectView($data['fields']['sort_order'])
			)
			->addField(
				new CWidgetFieldSelectView($data['fields']['count_mode'])
			)
			->addField(
				new CWidgetFieldCheckBoxListView($data['fields']['severities'])
			)
			->addField(
				new CWidgetFieldCheckBoxListView($data['fields']['ack_states'])
			)
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_et('Paging')))
			->addField(
				new CWidgetFieldSelectView($data['fields']['page_size'])
			)
			->addField(
				new CWidgetFieldSelectView($data['fields']['auto_page'])
			)
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_et('Data retrieval')))
			->addField(
				new CWidgetFieldIntegerBoxView($data['fields']['max_events'])
			)
			->addField(
				new CWidgetFieldTimePeriodView($data['fields']['time_period'])
			)
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
