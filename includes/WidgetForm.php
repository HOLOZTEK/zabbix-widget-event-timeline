<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use CSeverityHelper;
use CWidgetsData;

use Zabbix\Widgets\{
	CWidgetField,
	CWidgetForm
};

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBoxList,
	CWidgetFieldColor,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldTimePeriod
};

class WidgetForm extends CWidgetForm {

	public const GRAPH_HEIGHT_MIN     = 100;
	public const GRAPH_HEIGHT_MAX     = 2000;
	public const Y_AXIS_MIN_MAX = 1000000000;
	public const GRAPH_HEIGHT_DEFAULT = 300;

	public const MAX_EVENTS_MIN     = 1000;
	public const MAX_EVENTS_MAX     = 1000000;
	public const MAX_EVENTS_DEFAULT = 100000;

	public function addFields(): self {
		$severities = [];

		foreach (Aggregator::SEVERITIES as $severity) {
			$severities[$severity] = CSeverityHelper::getName($severity);
		}

		return $this
			// Source: 第一段階は固定指定のみ（Widget参照は第二段階）。
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
					->setMultiple(true)
			)
			->addField(
				(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
					->setMultiple(true)
			)
			// Severity: 個別にON/OFF（初期値は全ON）。集計段階の静的Filterとして使用する。
			->addField(
				(new CWidgetFieldCheckBoxList('severities', _('Severity'), $severities))
					->setDefault(Aggregator::SEVERITIES)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				(new CWidgetFieldIntegerBox('max_events', _et('Maximum events to retrieve'),
					self::MAX_EVENTS_MIN, self::MAX_EVENTS_MAX
				))->setDefault(self::MAX_EVENTS_DEFAULT)
			)
			// Appearance
			->addField(
				(new CWidgetFieldIntegerBox('graph_height', _et('Graph height'),
					self::GRAPH_HEIGHT_MIN, self::GRAPH_HEIGHT_MAX
				))->setDefault(self::GRAPH_HEIGHT_DEFAULT)
			)
			// 0 / 空 = Auto。Y軸の下端は常に0で、これは最大値として最低限確保するスケール（固定最大値ではない）。
			->addField(
				(new CWidgetFieldIntegerBox('y_axis_min', _et('Y-axis minimum'), 0, self::Y_AXIS_MIN_MAX))
					->setDefault(0)
			)
			// 空 = 使用中のZabbixテーマに追従（固定の黒・白を使わない）。
			->addField(
				new CWidgetFieldColor('line_color', _et('Line color'))
			)
			->addField(
				new CWidgetFieldColor('text_color', _et('Text color'))
			)
			->addField(
				(new CWidgetFieldTimePeriod('time_period', _('Time period')))
					->setDefault([
						CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
							CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
						)
					])
					->setDefaultPeriod(['from' => 'now-24h', 'to' => 'now'])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			);
	}
}
