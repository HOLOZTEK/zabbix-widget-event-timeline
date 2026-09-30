<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use CWidgetFieldSelectView;

/**
 * 週の開始曜日フィールドの表示。7日Bucketが選ばれたときにだけ集計へ影響することを
 * ヘルプアイコンで補足する（他のBucket幅では設定として保持されるが集計には影響しない）。
 */
class CWidgetFieldWeekStartView extends CWidgetFieldSelectView {

	public function __construct(CWidgetFieldSelectAlwaysSaved $field) {
		parent::__construct($field);

		$this->setFieldHint(
			makeHelpIcon(_et('Applies only when the 7-day bucket is used (automatically or by resizing the widget).'))
		);
	}
}
