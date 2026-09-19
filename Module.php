<?php declare(strict_types = 0);

namespace Modules\EventTimeline;

use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		require_once __DIR__ . '/includes/helpers.php';
	}
}
