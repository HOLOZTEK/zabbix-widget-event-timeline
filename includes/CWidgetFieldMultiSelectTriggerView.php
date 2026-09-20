<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use CWidgetFieldMultiSelectView;

class CWidgetFieldMultiSelectTriggerView extends CWidgetFieldMultiSelectView {

	public function __construct(CWidgetFieldMultiSelectTrigger $field) {
		parent::__construct($field);
	}

	protected function getObjectName(): string {
		return 'triggers';
	}

	protected function getObjectLabels(): array {
		return ['object' => _et('Trigger'), 'objects' => _et('Triggers')];
	}

	protected function getPopupParameters(): array {
		return $this->popup_parameters + [
			'srctbl' => 'triggers',
			'srcfld1' => 'triggerid',
			'real_hosts' => '1',
			'with_triggers' => '1'
		];
	}
}
