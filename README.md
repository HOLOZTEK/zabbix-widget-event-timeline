# zabbix-widget-event-timeline

English | [日本語](README.ja.md)

## Overview

Event Timeline is a Zabbix dashboard widget for exploring problem events as severity-stacked bar charts.

It can show events over time or group them by host, host group, or event. Operators can change the aggregation method, acknowledgement filter, severity filter, grouping mode, and sort order directly from the dashboard without changing the saved widget configuration.

[Latest release](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/tag/v0.1.5) | [RPM](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.noarch.rpm) | [DEB](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline_0.1.5_all.deb) | [Source](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.tar.gz)

## Why Event Timeline?

Zabbix problem lists are effective for investigating individual events, but they do not always make event concentration and recurring patterns easy to see.

Event Timeline aggregates problem events into calendar-aligned time buckets and uses the standard Zabbix severity colors. The same data can also be viewed by host, host group, event prototype group, or individual event, allowing a dashboard to move from an overview to a more focused comparison.

## Features

| Function | Description |
| --- | --- |
| Four display modes | Switch between Timeline, Host, Host group, and Event views. |
| Flexible sources | Select hosts, host groups, and triggers directly or receive them from compatible dashboard widgets. |
| Runtime filtering | Filter by severity and acknowledgement status without overwriting the saved widget settings. |
| Two aggregation methods | Count events in the bucket where they occurred, or count continuing events in every bucket they overlap. |
| Automatic time buckets | Selects a calendar-aligned bucket width from one minute to one month while normally keeping the chart at 100 buckets or fewer. |
| Configurable week boundary | Select any weekday as the first day of the week for seven-day buckets. |
| Category sorting and paging | Sort category views by count, A to Z, or Z to A, with manual or automatic paging. |
| Tooltips and drilldown | Inspect event details in a tooltip and open a detailed list by clicking a bar segment. |
| Event grouping | Group discovered events by trigger prototype or display individual events. |
| Theme-aware appearance | Follow the active Zabbix theme or set custom line and text colors. |
| Localized interface | Includes English and Japanese locale files. |

## Dashboard Controls

The widget toolbar provides runtime controls for:

- display mode: Timeline, Host, Host group, or Event
- aggregation: Occurred or Continuing
- acknowledgement status: All, Unacknowledged, or Acknowledged
- sort order in category modes: By count, A to Z, or Z to A
- tooltip order in Timeline mode: By host or Chronological
- severity filtering using the six Zabbix severity levels
- resetting runtime conditions to the configured initial values

Runtime changes are kept while the widget refreshes, but they do not overwrite its saved configuration.

## Settings

| Setting | Description |
| --- | --- |
| Hosts | Select one or more hosts directly or receive host IDs from another widget. |
| Host groups | Select one or more host groups directly or receive host group IDs from another widget. Descendant groups are included. |
| Events | Select one or more triggers directly or receive trigger IDs from another widget. An empty fixed selection includes all events for the target hosts. |
| Display mode | Sets the initial Timeline, Host, Host group, or Event view. |
| Event grouping | Groups discovered events by prototype or shows individual events. |
| Sort order | Sets the initial category sort order. |
| Aggregation method | Sets Occurred events or Continuing events as the initial count mode. |
| Severity | Sets the initial enabled severity levels. |
| Acknowledgement | Sets the initial acknowledged and unacknowledged filter state. |
| Chart items per page | Sets the category count per page to 10, 20, or 50. |
| Auto paging | Disables automatic paging or advances every 10, 30, or 60 seconds. |
| Detail items per page | Sets the drilldown row count per page to 10, 20, or 50. |
| Maximum events to retrieve | Sets the event retrieval limit from 1,000 to 1,000,000. |
| Time period | Uses the dashboard period by default or accepts a widget-specific period. |
| First day of week | Sets the boundary used by seven-day buckets. |
| Graph height | Sets the chart height from 100 to 2,000 pixels. |
| Y-axis minimum | Sets the minimum guaranteed upper scale; zero uses automatic scaling. |
| X-axis labels | Sets Auto, Horizontal, Angled, or Vertical category labels. |
| Line color / Text color | Uses the active theme automatically or accepts a custom hexadecimal color. |

## Linked Widget Inputs

Event Timeline accepts the following Zabbix dashboard data types:

- time period (_timeperiod)
- host IDs (_hostids)
- host group IDs (_hostgroupids)
- trigger IDs (_triggerids)

Hosts and host groups can be linked from Tree Navigator or another compatible widget. Triggers can be linked from Event Navigator or another compatible widget. When a field is configured to use a widget source but no selection has arrived, Event Timeline shows a no-target state instead of widening the query to all hosts or events.

## Requirements

- Zabbix 7.0 or later
- PHP 8.1 or later
- A browser supported by the Zabbix frontend

## Installation

### RPM package

~~~bash
rpm -Uvh zabbix-widget-event-timeline-0.1.5.noarch.rpm
~~~

### DEB package

~~~bash
apt install ./zabbix-widget-event-timeline_0.1.5_all.deb
~~~

The packages place the widget in the active Zabbix frontend module directory. After installation, rescan and enable Event Timeline from Administration -> Modules.

### From source

RPM or DEB installation is recommended. For a source installation:

~~~bash
curl -L -o zabbix-widget-event-timeline-0.1.5.tar.gz \
  https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.tar.gz
tar -xzf zabbix-widget-event-timeline-0.1.5.tar.gz
install -d /usr/share/zabbix/ui/modules/event-timeline
cd zabbix-widget-event-timeline-0.1.5
cp -a manifest.json Module.php Widget.php actions assets includes locale views \
  /usr/share/zabbix/ui/modules/event-timeline/
~~~

Use /usr/share/zabbix/modules/event-timeline instead when the Zabbix installation uses the legacy module path. Rescan and enable the module from Administration -> Modules after copying the files.

## Operational Notes

- Event retrieval is paged in blocks of 5,000. If the configured retrieval limit is reached, older events are excluded and the widget displays a warning.
- Continuing-event mode obtains the recovery time from the recovery event. Unresolved events continue to the current time or the end of the selected period.
- A problem event related to multiple hosts is counted once in the total while its details may appear under each related host.
- The event acknowledgement filter uses the current acknowledgement state; it does not reconstruct the historical state at the event time.
- Host selection does not distinguish between monitored and unmonitored hosts.

## Documentation

- [Changelog](docs/CHANGELOG.md)
- [Version consistency check](scripts/check-version-consistency.sh)
- [Package file-list check](scripts/check-package-file-lists.sh)
- [Contributing](docs/CONTRIBUTING.md)
- [Security policy](docs/SECURITY.md)
- [Third-party notices](THIRD_PARTY_NOTICES.md)
- [License](LICENSE)

## Repository Layout

- Runtime module: repository root, actions/, assets/, includes/, locale/, and views/
- RPM packaging: packaging/rpm/
- Debian packaging: debian/
- Tests: tests/
- Public documentation: docs/

## Bundled Dependency

assets/js/echarts.min.js is a minified build of Apache ECharts 6.1.0 and includes ZRender. Both are distributed under the Apache License 2.0. See [NOTICE](NOTICE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Maintainer

Developed and maintained by [HOLOZTEK](https://github.com/HOLOZTEK).

## License

This repository currently uses the proprietary license in [LICENSE](LICENSE). Apache ECharts and ZRender remain subject to their respective third-party license notices.
