<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use Zabbix\Widgets\Fields\CWidgetFieldMultiSelect;

/**
 * 指定したブロードキャスト型を送出しているウィジェット（Tree Navigator / Event Navigator）を1つ選ぶ専用フィールド。
 * preventDefault() + acceptWidget() により、手動のオブジェクト選択は出さず「送信元ウィジェットを選ぶ」だけのUIになる。
 *
 * 保存値は参照 {_reference: "<widget>.<type>"}。表示時は Zabbix コアがブロードキャストされたID配列へ
 * 差し替えて送信してくる（IDリスト以外は受け付けない）。
 */
class CWidgetFieldMultiSelectBroadcastSource extends CWidgetFieldMultiSelect {

	public const DEFAULT_VIEW = CWidgetFieldMultiSelectBroadcastSourceView::class;

	private string $object_label;
	private string $objects_label;

	public function __construct(string $name, ?string $label, string $in_type, string $object_label,
			string $objects_label) {
		parent::__construct($name, $label);

		$this->object_label = $object_label;
		$this->objects_label = $objects_label;

		$this
			->setMultiple(false)
			->setInType($in_type)
			->preventDefault()
			->acceptWidget();
	}

	public function getObjectLabel(): string {
		return $this->object_label;
	}

	public function getObjectsLabel(): string {
		return $this->objects_label;
	}

	protected function getValidationRules(bool $strict = false): array {
		$value = $this->getValue();

		if (is_array($value) && array_key_exists(self::FOREIGN_REFERENCE_KEY, $value)) {
			return parent::getValidationRules($strict);
		}

		// ブロードキャストで受け取ったID配列（文字列/整数）。
		return ['type' => API_IDS];
	}
}
