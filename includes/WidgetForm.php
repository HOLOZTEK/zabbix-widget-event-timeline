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
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldSelect,
	CWidgetFieldTextArea,
	CWidgetFieldTimePeriod
};

class WidgetForm extends CWidgetForm {

	public const GRAPH_HEIGHT_MIN     = 100;
	public const GRAPH_HEIGHT_MAX     = 2000;
	public const Y_AXIS_MIN_MAX = 1000000000;
	public const GRAPH_HEIGHT_DEFAULT = 300;

	// Source（Monitoring target / Event 共通）。
	public const SOURCE_FIXED  = 0;
	public const SOURCE_WIDGET = 1;

	// Event Count Mode。
	public const COUNT_OCCURRENCE = 0;
	public const COUNT_CONTINUOUS = 1;

	// Acknowledged Filter の値（Event の acknowledged と同じ）。
	public const ACK_UNACKNOWLEDGED = 0;
	public const ACK_ACKNOWLEDGED   = 1;

	// 送信元ウィジェットのブロードキャスト型。
	public const TYPE_HOST_IDS       = '_hostids';
	public const TYPE_HOST_GROUP_IDS = '_hostgroupids';
	public const TYPE_EVENT_IDS      = '_eventids';
	public const TYPE_TRIGGER_IDS    = '_triggerids';

	public const EVENT_NAMES_MAX = 50;

	public const MAX_EVENTS_MIN     = 1000;
	public const MAX_EVENTS_MAX     = 1000000;
	public const MAX_EVENTS_DEFAULT = 100000;

	public function addFields(): self {
		$severities = [];

		foreach (Aggregator::SEVERITIES as $severity) {
			$severities[$severity] = CSeverityHelper::getName($severity);
		}

		return $this
			// Monitoring target の Source。Fixed は hostids / groupids、Widget は Tree Navigator の
			// Host / Host group Selection（tn_hostids は参照フィールド、tn_groupids は同じ送信元の
			// _hostgroupids で normalizeValues() が自動生成する）。
			->addField(
				(new CWidgetFieldSelect('host_source', _et('Monitoring target source'), [
					self::SOURCE_FIXED => _et('Fixed'),
					self::SOURCE_WIDGET => _et('Widget')
				]))->setDefault(self::SOURCE_FIXED)
			)
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
					->setMultiple(true)
			)
			->addField(
				(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
					->setMultiple(true)
			)
			->addField(
				new CWidgetFieldMultiSelectBroadcastSource('tn_hostids', _et('Tree Navigator'),
					self::TYPE_HOST_IDS, _et('Tree Navigator widget'), _et('Tree Navigator widgets')
				)
			)
			->addField(
				new CWidgetFieldMultiSelectBroadcastSource('tn_groupids', _et('Tree Navigator'),
					self::TYPE_HOST_GROUP_IDS, _et('Tree Navigator widget'), _et('Tree Navigator widgets')
				)
			)
			// Event の Source。Fixed は Event名（部分一致・複数指定はOR）、Widget は Event Navigator の
			// Event / Trigger Selection（en_eventids は参照フィールド、en_triggerids は自動生成）。
			->addField(
				(new CWidgetFieldSelect('event_source', _et('Event source'), [
					self::SOURCE_FIXED => _et('Fixed'),
					self::SOURCE_WIDGET => _et('Widget')
				]))->setDefault(self::SOURCE_FIXED)
			)
			->addField(
				new CWidgetFieldTextArea('event_names', _et('Events (event names)'))
			)
			->addField(
				new CWidgetFieldMultiSelectBroadcastSource('en_eventids', _et('Event Navigator'),
					self::TYPE_EVENT_IDS, _et('Event Navigator widget'), _et('Event Navigator widgets')
				)
			)
			->addField(
				new CWidgetFieldMultiSelectBroadcastSource('en_triggerids', _et('Event Navigator'),
					self::TYPE_TRIGGER_IDS, _et('Event Navigator widget'), _et('Event Navigator widgets')
				)
			)
			// Severity: configured_filter（表示画面の runtime_filter の初期値）。
			->addField(
				(new CWidgetFieldCheckBoxList('severities', _('Severity'), $severities))
					->setDefault(Aggregator::SEVERITIES)
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			// Acknowledged: configured_filter（既定は両方ON）。Eventの現在の確認状態で判定する。
			->addField(
				(new CWidgetFieldCheckBoxList('ack_states', _et('Acknowledgement'), [
					self::ACK_ACKNOWLEDGED => _et('Acknowledged'),
					self::ACK_UNACKNOWLEDGED => _et('Unacknowledged')
				]))
					->setDefault([self::ACK_ACKNOWLEDGED, self::ACK_UNACKNOWLEDGED])
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			// Event Count Mode の初期値（表示画面から切り替え可能）。
			->addField(
				(new CWidgetFieldSelect('count_mode', _et('Initial aggregation mode'), [
					self::COUNT_OCCURRENCE => _et('Occurred events'),
					self::COUNT_CONTINUOUS => _et('Continuing events (add to every bucket)')
				]))->setDefault(self::COUNT_OCCURRENCE)
			)
			// Runtime状態（表示画面から送られる一時的な値）。設定画面には表示せず、保存もされない。
			->addField((new CWidgetFieldCheckBox('rt_active'))->setDefault(0))
			->addField(
				(new CWidgetFieldCheckBoxList('rt_severities', '', $severities))->setDefault([])
			)
			->addField(
				(new CWidgetFieldCheckBoxList('rt_ack', '', [
					self::ACK_ACKNOWLEDGED => '', self::ACK_UNACKNOWLEDGED => ''
				]))->setDefault([])
			)
			->addField(
				(new CWidgetFieldSelect('rt_count_mode', '', [
					self::COUNT_OCCURRENCE => '', self::COUNT_CONTINUOUS => ''
				]))->setDefault(self::COUNT_OCCURRENCE)
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

	/**
	 * Widget Source の参照は、選んだ1つの送信元ウィジェットから2種類の型（Host+Host group / Event+Trigger）を
	 * 受け取る必要がある。設定画面には片方の参照フィールドだけを表示するので、もう一方の参照は同じ送信元から作る。
	 * Fixed のときは参照を残さない（未使用の参照でダッシュボードが送信元を待たないようにする）。
	 */
	protected function normalizeValues(array $values): array {
		$pairs = [
			['host_source', 'tn_hostids', self::TYPE_HOST_IDS, 'tn_groupids', self::TYPE_HOST_GROUP_IDS],
			['event_source', 'en_eventids', self::TYPE_EVENT_IDS, 'en_triggerids', self::TYPE_TRIGGER_IDS]
		];
		$key = CWidgetField::FOREIGN_REFERENCE_KEY;

		foreach ($pairs as [$source_name, $main, $main_type, $derived, $derived_type]) {
			if ((int) ($values[$source_name] ?? self::SOURCE_FIXED) !== self::SOURCE_WIDGET) {
				unset($values[$main], $values[$derived]);

				continue;
			}

			$ref = $values[$main][$key] ?? null;

			if (is_string($ref) && $ref !== '') {
				$reference = explode('.', $ref, 2)[0];
				$values[$main] = [$key => $reference.'.'.$main_type];
				$values[$derived] = [$key => $reference.'.'.$derived_type];
			}
		}

		return $values;
	}

	public function validate(bool $strict = false): array {
		$errors = parent::validate($strict);

		if ($strict) {
			$key = CWidgetField::FOREIGN_REFERENCE_KEY;

			if ((int) $this->getFieldValue('host_source') === self::SOURCE_WIDGET
					&& empty($this->getFieldValue('tn_hostids')[$key])) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _et('Tree Navigator'), _et('widget is required'));
			}

			if ((int) $this->getFieldValue('event_source') === self::SOURCE_WIDGET
					&& empty($this->getFieldValue('en_eventids')[$key])) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _et('Event Navigator'), _et('widget is required'));
			}
		}

		return $errors;
	}
}
