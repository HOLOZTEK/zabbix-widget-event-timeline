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
				'No target selected'
					=> _et('No target selected'),
				'All events are excluded by the current filters.'
					=> _et('All events are excluded by the current filters.'),
				'Count mode: continuing events (click for occurred events)'
					=> _et('Count mode: continuing events (click for occurred events)'),
				'Count mode: occurred events (click for continuing events)'
					=> _et('Count mode: occurred events (click for continuing events)'),
				'Reset filters to the configured values'
					=> _et('Reset filters to the configured values'),
				'Continuing events'
					=> _et('Continuing events'),
				'Ongoing'
					=> _et('Ongoing'),
				'Acknowledged'
					=> _et('Acknowledged'),
				'Unacknowledged'
					=> _et('Unacknowledged'),
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
