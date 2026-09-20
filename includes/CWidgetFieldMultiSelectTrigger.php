<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelect;

/**
 * Trigger を複数選ぶフィールド（ホスト / ホストグループの選択と同じ方式）。
 * Zabbix 7.0 のウィジェットフィールドには Trigger 型の保存形式がないため、Trigger ID を文字列として保存し、
 * 表示名（ホスト名: トリガー名）は表示時にAPIから解決する。参照できないTriggerは「アクセス不可」として表示する。
 */
class CWidgetFieldMultiSelectTrigger extends CWidgetFieldMultiSelect {

	public const DEFAULT_VIEW = CWidgetFieldMultiSelectTriggerView::class;

	public function __construct(string $name, ?string $label = null) {
		parent::__construct($name, $label);

		$this->inaccessible_caption = _et('Inaccessible trigger');

		$this->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	public function getValuesCaptions(): array {
		$ids = array_values(array_filter(array_map('strval', (array) $this->getValue()), 'ctype_digit'));

		if (!$ids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description'],
			'selectHosts' => ['name'],
			'triggerids' => $ids,
			'preservekeys' => true
		]);
		$triggers = is_array($triggers) ? $triggers : [];

		$captions = [];
		$inaccessible = 0;

		foreach ($ids as $id) {
			if (isset($triggers[$id])) {
				$host = $triggers[$id]['hosts'][0]['name'] ?? '';

				$captions[$id] = [
					'id' => $id,
					'name' => $triggers[$id]['description'],
					'prefix' => $host !== '' ? $host.': ' : ''
				];
			}
			else {
				$captions[$id] = [
					'id' => $id,
					'name' => $this->inaccessible_caption.(++$inaccessible > 1 ? ' ('.$inaccessible.')' : ''),
					'inaccessible' => true
				];
			}
		}

		return $captions;
	}

	public function setValuesCaptions(array $captions): self {
		return $this;
	}

	protected function getValidationRules(bool $strict = false): array {
		return ['type' => API_IDS];
	}
}
