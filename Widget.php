<?php declare(strict_types = 0);

namespace Modules\EventTimeline;

use Zabbix\Core\CWidget;

require_once __DIR__ . '/includes/helpers.php';

class Widget extends CWidget {

	public function getDefaultName(): string {
		return _et('Event Timeline');
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'Interval'
					=> _et('Interval'),
				'Total'
					=> _et('Total'),
				'Event count'
					=> _et('Event count'),
				'No events'
					=> _et('No events'),
				'No target hosts.'
					=> _et('No target hosts.'),
				'Failed to retrieve events.'
					=> _et('Failed to retrieve events.'),
				'Tooltip details: grouped by host (click to sort chronologically)'
					=> _et('Tooltip details: grouped by host (click to sort chronologically)'),
				'Tooltip details: chronological (click to group by host)'
					=> _et('Tooltip details: chronological (click to group by host)'),
				'And %1$s more events'
					=> _et('And %1$s more events'),
				'Unknown host'
					=> _et('Unknown host'),
				'Incomplete data: the event limit (%1$s) was reached. Events before %2$s are not counted in this graph.'
					=> _et('Incomplete data: the event limit (%1$s) was reached. Events before %2$s are not counted in this graph.')
			]
		];
	}
}
