<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use CWidgetFieldMultiSelectView;

class CWidgetFieldMultiSelectBroadcastSourceView extends CWidgetFieldMultiSelectView {

	public function __construct(CWidgetFieldMultiSelectBroadcastSource $field) {
		parent::__construct($field);
	}

	protected function getObjectName(): string {
		return 'widgets';
	}

	protected function getObjectLabels(): array {
		$field = $this->field;

		return ['object' => $field->getObjectLabel(), 'objects' => $field->getObjectsLabel()];
	}
}
