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
				'Display mode'
					=> _et('Display mode'),
				'Timeline'
					=> _et('Timeline'),
				'Host'
					=> _et('Host'),
				'Host group'
					=> _et('Host group'),
				'Event'
					=> _et('Event'),
				'Sort order'
					=> _et('Sort order'),
				'By count'
					=> _et('By count'),
				'A to Z'
					=> _et('A to Z'),
				'Z to A'
					=> _et('Z to A'),
				'Affected hosts'
					=> _et('Affected hosts'),
				'No target host groups.'
					=> _et('No target host groups.'),
				'Targets: %1$s (showing %2$s–%3$s)'
					=> _et('Targets: %1$s (showing %2$s–%3$s)'),
				'Page %1$s / %2$s'
					=> _et('Page %1$s / %2$s'),
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
				'Aggregation'
					=> _et('Aggregation'),
				'Occurred count'
					=> _et('Occurred count'),
				'Continuing count'
					=> _et('Continuing count'),
				'Number of events that started in each bucket'
					=> _et('Number of events that started in each bucket'),
				'Number of events overlapping each bucket'
					=> _et('Number of events overlapping each bucket'),
				'Ack status'
					=> _et('Ack status'),
				'All'
					=> _et('All'),
				'Tooltip order'
					=> _et('Tooltip order'),
				'By host'
					=> _et('By host'),
				'Chronological'
					=> _et('Chronological'),
				'Reset conditions'
					=> _et('Reset conditions'),
				'Severity level'
					=> _et('Severity level'),
				'Not classified (chip)'
					=> _et('Not classified (chip)'),
				'Information (chip)'
					=> _et('Information (chip)'),
				'Warning (chip)'
					=> _et('Warning (chip)'),
				'Average (chip)'
					=> _et('Average (chip)'),
				'High (chip)'
					=> _et('High (chip)'),
				'Disaster (chip)'
					=> _et('Disaster (chip)'),
				'%1$s events'
					=> _et('%1$s events'),
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
