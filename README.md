# zabbix-widget-event-timeline

English | [日本語](README.ja.md)

## Overview

Event Timeline is a Zabbix dashboard widget for finding when problem events occurred, where they originated, and which trigger families are involved.

It provides a severity-stacked timeline and three comparison views: Host, Host group, and Event. Dashboard operators can change the view, aggregation, acknowledgement state, severity, value type, item type, and sort order without editing the saved widget configuration.

[Latest release](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/tag/v0.2.9) | [RPM](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.2.9/zabbix-widget-event-timeline-0.2.9.noarch.rpm) | [DEB](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.2.9/zabbix-widget-event-timeline_0.2.9_all.deb) | [Source](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.2.9/zabbix-widget-event-timeline-0.2.9.tar.gz)

## What You Can Analyze

| View | Chart value | Typical use |
| --- | --- | --- |
| Timeline | Problem events per time bucket, stacked by severity | Find bursts, quiet periods, and changes over time. |
| Host | Event count per host | Identify hosts producing the most events. |
| Host group | Event count per host group | Compare operational areas or service groups. |
| Event | Affected host count per trigger or trigger-prototype group | Find widely distributed problems; sort by event count to find repeated events on fewer hosts. |

Clicking a bar segment opens a drilldown list. Hover tooltips provide the full category name, counts, severity breakdown, and view-specific context such as host interfaces, host-group population, affected hosts, latest occurrence, and matching-item counts.

## Main Features

| Function | Description |
| --- | --- |
| Severity timeline | Uses Zabbix severity colors in a stacked bar chart with calendar-aligned automatic time buckets. |
| Four display modes | Switches at runtime between Timeline, Host, Host group, and Event without changing saved settings. |
| Dashboard integration | Accepts time period, host IDs, host group IDs, and trigger IDs from compatible widgets. |
| Fixed or linked targets | Hosts, host groups, and triggers can be configured directly or received from Tree Navigator, Metric Navigator, Event Navigator, or another compatible source. |
| Trigger expansion | Maps a selected template-derived trigger to corresponding triggers on the Event Timeline target hosts using Zabbix inheritance IDs, never names or expressions. |
| LLD trigger expansion | Maps discovered triggers through their canonical trigger prototype and includes discovered triggers from the same prototype family on target hosts. |
| Item-characteristic filters | Filters by the value type and item type of items directly referenced by each trigger. |
| Runtime filters | Changes severity, acknowledgement status, value type, and item type from the dashboard toolbar. |
| Two count modes | Counts newly occurred events or events continuing through each bucket. |
| Sorting and paging | Sorts category views and supports manual or automatic paging. |
| Tooltips and drilldown | Shows aggregated context on hover and an event-detail table on click. |
| Five-tab settings | Organizes configuration into Targets, Display, Filters, Time period, and Appearance. |
| Operational feedback | Distinguishes normal empty results, active filters, incomplete results, retrieval limits, and unresolvable trigger selections. |
| Theme and locale support | Follows Zabbix light/dark themes and includes English and Japanese locale files. |

## Targets and Widget Integration

Event Timeline accepts these Zabbix dashboard input types:

- time period (_timeperiod)
- host IDs (_hostids)
- host group IDs (_hostgroupids)
- trigger IDs (_triggerids)

Hosts and host groups form the monitoring scope. Descendant host groups are included and duplicate hosts are counted once. Trigger selection is an additional condition, so the effective scope is:

~~~text
(Hosts OR hosts in Host groups) AND selected Triggers
~~~

Each input can be fixed in the settings or received from another widget. When an input is configured as a widget reference but no selection has arrived, Event Timeline shows a no-target state instead of widening the query.

If no trigger is selected, all problem events for the target hosts in the selected period are eligible.

## Trigger Expansion Across Hosts

A trigger selected on one host can be used to analyze corresponding triggers on other target hosts.

For a template-derived trigger, Event Timeline follows the Zabbix inheritance chain to the top-level canonical template trigger. It then selects triggers on the target hosts that derive from the same canonical trigger. Multi-level template inheritance is supported.

Matching is based only on trigger IDs and inheritance metadata:

- similar names, expressions, or severities are never treated as a match
- several selected triggers are combined with OR and deduplicated
- a target host without the corresponding template contributes no matching trigger
- a host-local trigger is not expanded to other hosts
- an empty expansion remains empty and never falls back to unfiltered events

The logged-in user must be able to read every inherited template needed to resolve the selection. If a selected trigger cannot be resolved, the selection fails closed: Event Timeline displays a permission warning and does not show unfiltered or partially filtered events.

## LLD and Trigger-Prototype Groups

For a discovered trigger, Event Timeline follows this relationship:

~~~text
Discovered trigger
→ parent trigger prototype on the host
→ inherited template prototypes
→ top-level canonical trigger prototype
~~~

It then includes discovered triggers on the target hosts that derive from the same canonical prototype.

LLD macro values are not used for matching. For example, selecting a discovered interface trigger for eth0 can include ens18, ens19, or bond0 triggers on other target hosts when all were generated from the same canonical prototype.

In Event view, Prototype group mode combines events from the same canonical trigger prototype across hosts. Individual events can be selected instead from the widget settings.

## Filters and Count Modes

### Runtime filters

The dashboard toolbar controls:

- Severity: any combination of the six Zabbix severity levels
- Acknowledgement: All, Unacknowledged, or Acknowledged
- Value type: one or more Zabbix item value types
- Item type: one or more Zabbix item types

Runtime changes are temporary. They survive normal widget refreshes but do not overwrite the saved configuration. Reset conditions restores the configured initial state.

### Item-characteristic matching

Value type and item type are evaluated against items directly referenced by a trigger.

- multiple values within the same filter are OR conditions
- Value type and Item type are combined with AND
- the same referenced item must satisfy both filters
- one matching item is enough for the trigger to match
- several matching items never duplicate an event
- dependent items are classified as Dependent item; the master item type is not followed
- trigger expressions, names, and item-key text are never parsed to guess a match

When an item filter is active, drilldown rows can expand Matching items to show the host, item name, key, value type, and item type used for the match.

Classification uses the current Zabbix configuration, not the configuration that existed when a historical event occurred.

### Event count modes

- Occurred counts an event only in the bucket containing its start time.
- Continuing counts an event in every bucket overlapped by its active period. Unresolved events continue to the current time or the selected-period end and are never counted into future buckets.

## Sorting and Paging

Host and Host group views provide By count, A to Z, and Z to A.

Event view provides:

- Affected hosts: most affected hosts first
- Event count: most matching events first, useful for repeated events on a smaller host set
- A to Z
- Z to A

In Event view, bar height always represents affected hosts. Event count changes the ordering only; the event count remains available in the tooltip.

Category views show 10, 20, or 50 chart items per page and can advance automatically every 10, 30, or 60 seconds. Drilldown paging is configured separately.

## Automatic Time Buckets

Timeline mode selects the smallest calendar-aligned interval that normally produces 100 buckets or fewer:

~~~text
1m, 5m, 10m, 15m, 30m, 1h, 2h, 3h, 6h, 12h, 1d, 7d, 1 month
~~~

Day, week, and month boundaries use the logged-in user's effective time zone. Daylight-saving transitions remain one calendar bucket. The first day of a seven-day bucket can be configured independently for each widget.

## Settings

The configuration form has five tabs. Standard Zabbix fields such as Type, Name, Refresh interval, and Show header remain outside the tabs.

| Tab | Fields |
| --- | --- |
| Targets | Hosts, Host groups, Triggers, Event retrieval limit |
| Display | Display mode, Event grouping, Sort order, Aggregation method, Chart items per page, Auto paging, Detail items per page |
| Filters | Severity, Acknowledgement, Value type, Item type |
| Time period | Dashboard / Widget / Custom period, First day of week |
| Appearance | Graph height, Y-axis minimum, X-axis label direction, Line color, Text color |

The form opens on Targets. Unsaved values are retained while switching tabs. Validation marks tabs containing an invalid field and opens the first affected tab.

At least one Severity and one Acknowledgement value are required when applying settings. Value type and Item type provide an All option; All means that no item-characteristic restriction is stored.

## Empty States and Warnings

Event Timeline distinguishes different operational states:

| State | Behavior |
| --- | --- |
| No events in the target period | Keeps the axes, grid, and footer visible and shows a normal empty message. |
| No events match current conditions | Shows the active narrowing conditions and suggests changing or resetting them. |
| No linked target selected | Does not broaden the query; shows No target selected. |
| Selected triggers cannot be resolved | Fails closed and asks the user to check access to inherited templates. |
| Partial trigger/prototype matching | Shows matched results with a warning that the result or grouping may be incomplete. |
| Item metadata cannot be confirmed | Shows only confirmed matches with an incomplete-result warning. |
| Event retrieval limit reached | Keeps the visible result and identifies the older period excluded from aggregation. |

## Requirements

| Component | Requirement |
| --- | --- |
| Zabbix | 7.0 or later; verified with 7.0.31 and 8.0rc1 |
| PHP | 8.1 or later; verified with PHP 8.3 and 8.4 |
| Browser | A browser supported by the Zabbix frontend |

## Installation

### RPM

~~~bash
rpm -Uvh zabbix-widget-event-timeline-0.2.9.noarch.rpm
~~~

### DEB

~~~bash
apt install ./zabbix-widget-event-timeline_0.2.9_all.deb
~~~

The package selects /usr/share/zabbix/ui/modules when available and otherwise uses /usr/share/zabbix/modules. After installation, rescan modules from Administration -> General -> Modules and enable Event Timeline.

### From source

RPM or DEB installation is recommended. To install the release archive manually:

~~~bash
curl -L -o zabbix-widget-event-timeline-0.2.9.tar.gz \
  https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.2.9/zabbix-widget-event-timeline-0.2.9.tar.gz
tar -xzf zabbix-widget-event-timeline-0.2.9.tar.gz
install -d /usr/share/zabbix/ui/modules/event-timeline
cd zabbix-widget-event-timeline-0.2.9
cp -a manifest.json Module.php Widget.php actions assets includes locale views \
  /usr/share/zabbix/ui/modules/event-timeline/
~~~

Use /usr/share/zabbix/modules/event-timeline instead when the installation uses the legacy module path.

Reload the PHP execution environment when required by the deployment, rescan Zabbix modules, and enable Event Timeline.

## Known Limitations

- Events are fetched in pages of 5,000. When the configured retrieval limit is reached, older events are excluded and a warning is shown.
- Historical events of deleted triggers or deleted items cannot be classified from the current Zabbix configuration and are not reconstructed from names.
- Nested LLD introduced in Zabbix 8.0 has not been verified with real data.
- Triggers or trigger prototypes referencing items from several hosts are not officially supported; a trigger is considered in scope when any referenced host is targeted.
- Host selection does not distinguish between monitored and unmonitored hosts.
- The drilldown dialog does not repeat the partial-result warning shown in the main widget.
- The practical minimum widget width is approximately 216 pixels. Below approximately 400 pixels, toolbar controls wrap and the chart uses vertical scrolling inside the widget.

## Tests

~~~bash
php tests/run_tests.php
php tests/run_final_tests.php
~~~

The tests cover bucket selection and boundaries, calendar/DST behavior, severity aggregation, trigger selection and canonicalization, target-host expansion, LLD prototype expansion, item-characteristic filtering, category aggregation, and large decimal IDs.

## Documentation

- [Changelog](docs/CHANGELOG.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Security policy](docs/SECURITY.md)
- [Screenshot guide](docs/screenshot-guide.md)
- [Third-party notices](THIRD_PARTY_NOTICES.md)
- [License](LICENSE)

## Bundled Dependency

assets/js/echarts.min.js is Apache ECharts 6.1.0 and includes ZRender. Both are distributed under the Apache License 2.0. See [NOTICE](NOTICE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Maintainer

Developed and maintained by [HOLOZTEK](https://github.com/HOLOZTEK).

## License

This project is released under the [MIT License](LICENSE).

## License

This project is licensed under the [MIT License](LICENSE).
