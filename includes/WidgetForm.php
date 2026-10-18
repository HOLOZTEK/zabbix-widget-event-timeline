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
	CWidgetFieldTextBox,
	CWidgetFieldTimePeriod
};

class WidgetForm extends CWidgetForm {

	public const GRAPH_HEIGHT_MIN     = 100;
	public const GRAPH_HEIGHT_MAX     = 2000;
	public const Y_AXIS_MIN_MAX = 1000000000;

	// Drilldown（詳細一覧）の対象。dd_severity の DETAIL_SEVERITY_ALL は「Severityを限定しない」。
	public const DETAIL_SEVERITY_ALL = 6;
	// Event名（Zabbix の events.name は最大2048文字）＋プロトタイプグループの Group key の接頭辞（'n:' / 'p:'）。
	public const DETAIL_KEY_MAX_LENGTH = 2050;

	public const XLABEL_AUTO = 0;
	public const XLABEL_HORIZONTAL = 1;
	public const XLABEL_ANGLED = 2;
	public const XLABEL_VERTICAL = 3;
	public const GRAPH_HEIGHT_DEFAULT = 300;

	// Event Count Mode。
	public const COUNT_OCCURRENCE = 0;
	public const COUNT_CONTINUOUS = 1;

	// Acknowledged Filter の値（Event の acknowledged と同じ）。
	public const ACK_UNACKNOWLEDGED = 0;
	public const ACK_ACKNOWLEDGED   = 1;

	// 送信元ウィジェットのブロードキャスト型。
	public const TYPE_HOST_IDS       = '_hostids';
	public const TYPE_HOST_GROUP_IDS = '_hostgroupids';
	public const TYPE_TRIGGER_IDS    = '_triggerids';

	// 表示モード（Dimension）。
	public const MODE_TIMELINE = 0;
	public const MODE_HOST     = 1;
	public const MODE_GROUP    = 2;
	public const MODE_EVENT    = 3;

	// Event表示方式（Event modeのみ）。設定画面でのみ切り替える（Runtimeに切替UIは追加しない。§1/§44）。
	public const EVENT_DISPLAY_PROTOTYPE   = 0;
	public const EVENT_DISPLAY_INDIVIDUAL  = 1;

	// Popup（Hover Tooltip）に列挙するHost名の上限。超えた分は「ほか N台」（§21）。設定項目ではない。
	public const POPUP_HOST_LIMIT = 5;
	// Host View の Tooltip に出すインターフェースの最大数（UI Polish §16）。
	public const POPUP_INTERFACE_LIMIT = 3;

	// ページング（グラフ）。
	public const PAGE_SIZES         = [10, 20, 50];
	public const PAGE_SIZE_DEFAULT  = 20;
	public const AUTO_PAGE_INTERVALS = [0, 10, 30, 60];

	// ページング（詳細表示ダイアログ）。グラフのページングとは別設定（§29〜34）。
	public const DETAIL_PAGE_SIZES        = [10, 20, 50];
	public const DETAIL_PAGE_SIZE_DEFAULT = 20;

	public const MAX_EVENTS_MIN     = 1000;
	public const MAX_EVENTS_MAX     = 1000000;
	public const MAX_EVENTS_DEFAULT = 100000;

	/**
	 * 値の型（Item の value_type）の選択肢。現在の Zabbix が定義する ITEM_VALUE_TYPE_* 定数すべてと、
	 * Zabbix 本体の表示名・翻訳（itemValueTypeString()）。Event Timeline 側で一覧・翻訳を持たない（Phase 5 Q3）。
	 * 並びは ItemCharacteristicsFilter::VALUE_TYPE_ORDER（UI Polish §3）。8.0 では JSON が加わる。
	 *
	 * @return array<int, string>  value_type => 表示名
	 */
	public static function valueTypeOptions(): array {
		$options = [];

		foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
			if (strncmp($name, 'ITEM_VALUE_TYPE_', 16) === 0 && is_int($value)) {
				$options[$value] = itemValueTypeString($value);
			}
		}

		return ItemCharacteristicsFilter::orderOptions($options, ItemCharacteristicsFilter::VALUE_TYPE_ORDER);
	}

	/**
	 * アイテムタイプ（Item の type）の選択肢。Zabbix 本体の定義・表示名・翻訳（item_type2str()）をそのまま使う
	 * （Phase 5 Q3）。並びは ItemCharacteristicsFilter::ITEM_TYPE_ORDER（UI Polish §4）。
	 * 8.0 では Nested / Telemetry query が加わる。
	 *
	 * @return array<int, string>  type => 表示名
	 */
	public static function itemTypeOptions(): array {
		return ItemCharacteristicsFilter::orderOptions(item_type2str(),
			ItemCharacteristicsFilter::ITEM_TYPE_ORDER
		);
	}

	public function addFields(): self {
		$severities = [];

		foreach (Aggregator::SEVERITIES as $severity) {
			$severities[$severity] = CSeverityHelper::getName($severity);
		}

		return $this
			// Host Selection（「どのHostで発生したEventを見るか」）。Trigger Selectionとは独立した条件
			// （v0.2.0 §3.1）。Host / Host group の各フィールド自身が「固定指定」または「他ウィジェット
			// （Tree Navigator等）の参照」のどちらかを持つ（manifest.json の "in"）。
			->addField(
				(new CWidgetFieldMultiSelectHost('hostids', _('Hosts')))
					->setMultiple(true)
			)
			->addField(
				(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
					->setMultiple(true)
			)
			// Trigger Selection: 固定Trigger指定 または Navigator（Metric Navigator等）からのWidget参照。
			// どちらのTriggerに関するEventを見るかを示す、Host Selectionとは独立した条件（v0.2.0 §3.2/§4）。
			->addField(
				(new CWidgetFieldMultiSelectTrigger('triggerids', _et('Triggers')))
					->setMultiple(true)
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
			// Item characteristics filter（Phase 5）の初期値。未選択 = すべて（条件なし）。選択肢は Zabbix 本体の定義・翻訳。
			->addField(
				(new CWidgetFieldCheckBoxList('value_types', _et('Value type'), self::valueTypeOptions()))
					->setDefault([])
			)
			->addField(
				(new CWidgetFieldCheckBoxList('item_types', _et('Item type'), self::itemTypeOptions()))
					->setDefault([])
			)
			// Event Count Mode の初期値（表示画面から切り替え可能）。
			->addField(
				(new CWidgetFieldSelect('count_mode', _et('Aggregation method'), [
					self::COUNT_OCCURRENCE => _et('Occurred events'),
					self::COUNT_CONTINUOUS => _et('Continuing events (add to every bucket)')
				]))->setDefault(self::COUNT_OCCURRENCE)
			)
			// 表示モード・並び順の初期値（表示画面から切り替え可能）。並び順は Timeline では使わないが設定として保持する。
			->addField(
				(new CWidgetFieldSelect('display_mode', _et('Display mode'), [
					self::MODE_TIMELINE => _et('Timeline'),
					self::MODE_HOST => _et('Host'),
					self::MODE_GROUP => _et('Host group'),
					self::MODE_EVENT => _et('Event')
				]))->setDefault(self::MODE_TIMELINE)
			)
			// Event modeでのGroup化方式（設定のみで切り替え。Runtimeの条件リセットの対象外。§1/§44）。
			// 既定はプロトタイプグループ（Trigger prototype起点。Zabbixの生成関係のみを根拠にし、
			// 文字列の類推は行わない。§5〜9/§40）。個別イベント表示は従来どおりの挙動を維持する。
			->addField(
				(new CWidgetFieldSelect('event_display', _et('Event grouping'), [
					self::EVENT_DISPLAY_PROTOTYPE => _et('Prototype group'),
					self::EVENT_DISPLAY_INDIVIDUAL => _et('Individual events')
				]))->setDefault(self::EVENT_DISPLAY_PROTOTYPE)
			)
			->addField(
				(new CWidgetFieldSelect('sort_order', _et('Sort order'), [
					DimensionAggregator::SORT_COUNT => _et('By count'),
					DimensionAggregator::SORT_ASC => _et('A to Z'),
					DimensionAggregator::SORT_DESC => _et('Z to A'),
					// Event mode でのみ意味を持つ（Host / Host group では件数順と同じ並び）。
					DimensionAggregator::SORT_EVENTS => _et('Sort by event count')
				]))->setDefault(DimensionAggregator::SORT_COUNT)
			)
			// ページング（Host / Host group / Event の各モードで使う）。
			->addField(
				(new CWidgetFieldSelect('page_size', _et('Chart items per page'), array_combine(self::PAGE_SIZES,
					array_map('strval', self::PAGE_SIZES)
				)))->setDefault(self::PAGE_SIZE_DEFAULT)
			)
			->addField(
				(new CWidgetFieldSelect('auto_page', _et('Auto paging'), [
					0 => _et('Off'),
					10 => _et('10 seconds'),
					30 => _et('30 seconds'),
					60 => _et('60 seconds')
				]))->setDefault(0)
			)
			// 詳細表示（Drilldownダイアログ）のページング。グラフのページング（page_size）とは別設定（§29〜34）。
			->addField(
				(new CWidgetFieldSelect('detail_page_size', _et('Detail items per page'), array_combine(
					self::DETAIL_PAGE_SIZES, array_map('strval', self::DETAIL_PAGE_SIZES)
				)))->setDefault(self::DETAIL_PAGE_SIZE_DEFAULT)
			)
			// Runtime状態（表示画面から送られる一時的な値）。設定画面には表示せず、保存もされない。
			->addField((new CWidgetFieldCheckBox('rt_active'))->setDefault(0))
			->addField(
				(new CWidgetFieldSelect('rt_mode', '', [
					self::MODE_TIMELINE => '', self::MODE_HOST => '', self::MODE_GROUP => '', self::MODE_EVENT => ''
				]))->setDefault(self::MODE_TIMELINE)
			)
			->addField(
				(new CWidgetFieldSelect('rt_sort', '', [
					DimensionAggregator::SORT_COUNT => '', DimensionAggregator::SORT_ASC => '',
					DimensionAggregator::SORT_DESC => '', DimensionAggregator::SORT_EVENTS => ''
				]))->setDefault(DimensionAggregator::SORT_COUNT)
			)
			->addField((new CWidgetFieldIntegerBox('rt_page', '', 1, 1000000))->setDefault(1))
			// Drilldown要求（詳細一覧を返させる一時的な値。設定画面には表示せず、保存もされない）。
			->addField((new CWidgetFieldCheckBox('dd_active'))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('dd_start', '', 0, 2147483647))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('dd_end', '', 0, 2147483647))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('dd_severity', '', 0, self::DETAIL_SEVERITY_ALL))
				->setDefault(self::DETAIL_SEVERITY_ALL)
			)
			->addField((new CWidgetFieldTextBox('dd_key', ''))->setMaxLength(self::DETAIL_KEY_MAX_LENGTH))
			->addField(
				(new CWidgetFieldCheckBoxList('rt_severities', '', $severities))->setDefault([])
			)
			->addField(
				(new CWidgetFieldCheckBoxList('rt_ack', '', [
					self::ACK_ACKNOWLEDGED => '', self::ACK_UNACKNOWLEDGED => ''
				]))->setDefault([])
			)
			->addField(
				(new CWidgetFieldCheckBoxList('rt_value_types', '', self::valueTypeOptions()))->setDefault([])
			)
			->addField(
				(new CWidgetFieldCheckBoxList('rt_item_types', '', self::itemTypeOptions()))->setDefault([])
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
			// 週の開始曜日。7日Bucketの自然境界の基準になる（他のBucket幅では保持のみで集計に影響しない）。
			// 既定値: 新規Widgetは日曜、既存Widget（本項目追加前に保存済みで week_start が未設定）は
			// 従来どおり月曜（BucketPlanner::WEEK_START）。両者の区別は、このWidgetの保存済みフィールド値が
			// まったく無い（$this->values が空 = 一度も保存されたことがない）かどうかで判定する。値は
			// CWidgetFieldSelectAlwaysSaved により選択値に関わらず必ず明示的に保存されるため、一度でも
			// 保存されたWidgetはこの判定に頼らず、保存済みの値がそのまま使われる。
			->addField(
				(new CWidgetFieldSelectAlwaysSaved('week_start', _et('First day of week'), self::weekStartOptions()))
					->setDefault($this->values === [] ? BucketPlanner::WEEKDAY_SUN : BucketPlanner::WEEK_START)
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
			// Host / Host group / Event のX軸カテゴリラベルの向き。Timeline には影響しない。
			->addField(
				(new CWidgetFieldSelect('x_label', _et('X-axis labels'), [
					self::XLABEL_AUTO => _et('Auto'),
					self::XLABEL_HORIZONTAL => _et('Horizontal'),
					self::XLABEL_ANGLED => _et('Angled'),
					self::XLABEL_VERTICAL => _et('Vertical')
				]))->setDefault(self::XLABEL_AUTO)
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

	// 週の開始曜日の選択肢（日曜〜土曜の順。内部値はBucketPlannerのWEEKDAY_*定数と共通）。
	private static function weekStartOptions(): array {
		return [
			BucketPlanner::WEEKDAY_SUN => _et('Sunday'),
			BucketPlanner::WEEKDAY_MON => _et('Monday'),
			BucketPlanner::WEEKDAY_TUE => _et('Tuesday'),
			BucketPlanner::WEEKDAY_WED => _et('Wednesday'),
			BucketPlanner::WEEKDAY_THU => _et('Thursday'),
			BucketPlanner::WEEKDAY_FRI => _et('Friday'),
			BucketPlanner::WEEKDAY_SAT => _et('Saturday')
		];
	}
}
