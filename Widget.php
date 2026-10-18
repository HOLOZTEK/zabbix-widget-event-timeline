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
				'Event details'
					=> _et('Event details'),
				'Period'
					=> _et('Period'),
				'Severity'
					=> _et('Severity'),
				'Time'
					=> _et('Time'),
				'Recovery time'
					=> _et('Recovery time'),
				'Duration'
					=> _et('Duration'),
				'Acknowledgement'
					=> _et('Acknowledgement'),
				'Status'
					=> _et('Status'),
				'Resolved'
					=> _et('Resolved'),
				'Number of events'
					=> _et('Number of events'),
				'Matching events: %1$s'
					=> _et('Matching events: %1$s'),
				'Affected hosts: %1$s'
					=> _et('Affected hosts: %1$s'),
				'This list is based on data that reached the event retrieval limit.'
					=> _et('This list is based on data that reached the event retrieval limit.'),
				'Only the newest %1$s of %2$s events are listed.'
					=> _et('Only the newest %1$s of %2$s events are listed.'),
				'No matching data.'
					=> _et('No matching data.'),
				'Close'
					=> _et('Close'),
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
				'Prototype group'
					=> _et('Prototype group'),
				'Sort order'
					=> _et('Sort order'),
				'By count'
					=> _et('By count'),
				'A to Z'
					=> _et('A to Z'),
				'Z to A'
					=> _et('Z to A'),
				'Sort by affected hosts'
					=> _et('Sort by affected hosts'),
				'Sort by event count'
					=> _et('Sort by event count'),
				'A-Z'
					=> _et('A-Z'),
				'Z-A'
					=> _et('Z-A'),
				'Affected hosts'
					=> _et('Affected hosts'),
				'Targets: %1$s (showing %2$s–%3$s)'
					=> _et('Targets: %1$s (showing %2$s–%3$s)'),
				'Page %1$s / %2$s'
					=> _et('Page %1$s / %2$s'),
				'No target selected'
					=> _et('No target selected'),
				'Count mode: continuing events (click for occurred events)'
					=> _et('Count mode: continuing events (click for occurred events)'),
				'Count mode: occurred events (click for continuing events)'
					=> _et('Count mode: occurred events (click for continuing events)'),
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
				'The selected triggers cannot be resolved.'
					=> _et('The selected triggers cannot be resolved.'),
				'Check access permissions for the inherited templates.'
					=> _et('Check access permissions for the inherited templates.'),
				'Some triggers on the target hosts could not be matched with the selected triggers. The result may be incomplete.'
					=> _et('Some triggers on the target hosts could not be matched with the selected triggers. The result may be incomplete.'),
				'Some events could not be grouped by their inherited trigger prototype. Some prototype groups may be split by host.'
					=> _et('Some events could not be grouped by their inherited trigger prototype. Some prototype groups may be split by host.'),
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
				'%1$s more hosts'
					=> _et('%1$s more hosts'),
				'Incomplete data: the event limit (%1$s) was reached. Events before %2$s are not counted in this graph.'
					=> _et('Incomplete data: the event limit (%1$s) was reached. Events before %2$s are not counted in this graph.'),
				'Value type'
					=> _et('Value type'),
				'Item type'
					=> _et('Item type'),
				'All (item filter)'
					=> _et('All (item filter)'),
				'%1$s selected'
					=> _et('%1$s selected'),
				'Apply'
					=> _et('Apply'),
				'Item filter'
					=> _et('Item filter'),
				'Matching items (%1$s)'
					=> _et('Matching items (%1$s)'),
				'Item name'
					=> _et('Item name'),
				'Item key'
					=> _et('Item key'),
				'Some item information could not be confirmed. The result may be incomplete.'
					=> _et('Some item information could not be confirmed. The result may be incomplete.'),
				'Triggers whose referenced items could not be checked are not shown.'
					=> _et('Triggers whose referenced items could not be checked are not shown.'),
				'%1$s more interfaces'
					=> _et('%1$s more interfaces'),
				'Active conditions: %1$s'
					=> _et('Active conditions: %1$s'),
				'Affected hosts by severity'
					=> _et('Affected hosts by severity'),
				'Aggregates events by host group.'
					=> _et('Aggregates events by host group.'),
				'Aggregates events by host.'
					=> _et('Aggregates events by host.'),
				'Aggregates events by trigger / event type.'
					=> _et('Aggregates events by trigger / event type.'),
				'Change the filters or display conditions.'
					=> _et('Change the filters or display conditions.'),
				'Click to show or hide.'
					=> _et('Click to show or hide.'),
				'Counts events for each target, including events continuing in the displayed period.'
					=> _et('Counts events for each target, including events continuing in the displayed period.'),
				'Counts events in each time bucket, including events that are still continuing.'
					=> _et('Counts events in each time bucket, including events that are still continuing.'),
				'Counts events that newly occurred in each time bucket.'
					=> _et('Counts events that newly occurred in each time bucket.'),
				'Counts events that occurred in the displayed period for each target.'
					=> _et('Counts events that occurred in the displayed period for each target.'),
				'Events by severity'
					=> _et('Events by severity'),
				'Filters events by the item type of the items referenced by the trigger. When several are selected, items matching any of them are used.'
					=> _et('Filters events by the item type of the items referenced by the trigger. When several are selected, items matching any of them are used.'),
				'Filters events by the value type of the items referenced by the trigger. When several are selected, items matching any of them are used.'
					=> _et('Filters events by the value type of the items referenced by the trigger. When several are selected, items matching any of them are used.'),
				'Groups the events shown in the details by host. The aggregated graph does not change.'
					=> _et('Groups the events shown in the details by host. The aggregated graph does not change.'),
				'Host name'
					=> _et('Host name'),
				'Interfaces'
					=> _et('Interfaces'),
				'Last occurred'
					=> _et('Last occurred'),
				'Matching items'
					=> _et('Matching items'),
				'No events in the target period'
					=> _et('No events in the target period'),
				'No events match the current conditions'
					=> _et('No events match the current conditions'),
				'Restores the filters and display conditions to the initial state set in the widget configuration.'
					=> _et('Restores the filters and display conditions to the initial state set in the widget configuration.'),
				'Shows all events regardless of acknowledgement status.'
					=> _et('Shows all events regardless of acknowledgement status.'),
				'Shows only acknowledged events.'
					=> _et('Shows only acknowledged events.'),
				'Shows only unacknowledged events.'
					=> _et('Shows only unacknowledged events.'),
				'Shows the events shown in the details in order of occurrence. The aggregated graph does not change.'
					=> _et('Shows the events shown in the details in order of occurrence. The aggregated graph does not change.'),
				'Shows when events occurred along the time axis.'
					=> _et('Shows when events occurred along the time axis.'),
				'Sorts by name in ascending order.'
					=> _et('Sorts by name in ascending order.'),
				'Sorts by name in descending order.'
					=> _et('Sorts by name in descending order.'),
				'Sorts events by name in ascending order.'
					=> _et('Sorts events by name in ascending order.'),
				'Sorts events by name in descending order.'
					=> _et('Sorts events by name in descending order.'),
				'Sorts events by the number of affected hosts, highest first. The bar height also shows the number of affected hosts.'
					=> _et('Sorts events by the number of affected hosts, highest first. The bar height also shows the number of affected hosts.'),
				'Sorts events by the number of occurrences, highest first. The bar height still shows the number of affected hosts.'
					=> _et('Sorts events by the number of occurrences, highest first. The bar height still shows the number of affected hosts.'),
				'Sorts targets by event count, highest first.'
					=> _et('Sorts targets by event count, highest first.'),
				'Target hosts (current scope)'
					=> _et('Target hosts (current scope)'),
				'Trigger'
					=> _et('Trigger'),
				'Use "Reset conditions" to restore the initial state.'
					=> _et('Use "Reset conditions" to restore the initial state.'),
				'Visible name'
					=> _et('Visible name'),
				'When both value type and item type are set, the same item must match both.'
					=> _et('When both value type and item type are set, the same item must match both.')
			]
		];
	}
}
