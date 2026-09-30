<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use Zabbix\Widgets\Fields\CWidgetFieldSelect;

/**
 * 通常の CWidgetFieldSelect は、値が既定値と一致する場合は保存時にAPIへ書き出さない
 * （CWidgetField::toApi() の仕様。保存データを小さく保つための最適化）。
 *
 * 週の開始曜日（week_start）は「値が既定値と一致する」ことと「このWidgetが本機能追加前から
 * 存在し、一度もこの項目を保存していない（＝旧Widget。月曜開始を維持すべき）」ことを区別する
 * 必要があるため、選択値に関わらず保存のたびに必ず明示的な値を書き出す。これにより、新規Widgetが
 * 既定値（日曜）のまま保存された場合でも、次回読み込み時に「週開始曜日が未設定＝旧Widget」と
 * 誤認されることがない（WidgetForm::addFields() 参照）。
 */
class CWidgetFieldSelectAlwaysSaved extends CWidgetFieldSelect {

	public const DEFAULT_VIEW = CWidgetFieldWeekStartView::class;

	public function __construct(string $name, string $label, array $values) {
		parent::__construct($name, $label, $values);

		// CWidgetFieldSelect は既定でINT32保存型を強制する（getValidationRules() が 'type' => API_INT32 を
		// 課すため、文字列の選択肢キー（本Widgetの曜日 sun/mon/...）は「整数で指定してください」で
		// 検証エラーになる）。週開始曜日の内部値は文字列enumのため、文字列保存型へ変更する
		// （'in' バリデーションは親クラスの getValidationRules() が選択肢キーから引き続き構築する）。
		$this->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	public function toApi(array &$widget_fields = []): void {
		$value = $this->getValue();

		if ($value === null) {
			return;
		}

		$widget_fields[] = [
			'type' => $this->save_type,
			'name' => $this->getName(),
			'value' => $value
		];
	}
}
