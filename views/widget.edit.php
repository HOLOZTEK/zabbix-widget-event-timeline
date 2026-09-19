<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectHostView($data['fields']['hostids'])
	)
	->addField(
		new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
	)
	->addField(
		new CWidgetFieldCheckBoxListView($data['fields']['severities'])
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
				new CWidgetFieldColorView($data['fields']['line_color'])
			)
			->addField(
				new CWidgetFieldColorView($data['fields']['text_color'])
			)
	)
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_event_timeline_form.init();')
	->show();
