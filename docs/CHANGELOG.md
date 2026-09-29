# Changelog

All notable changes to this project are documented in this file.

## v0.1.5 - 2026-09-29

- Updated the release metadata from v0.1.4 to v0.1.5.
- Carried forward the documentation updates for configurable week boundaries and the preferred Zabbix frontend module path.
- No functional widget or package-content changes were introduced after v0.1.4.

## v0.1.4 - 2026-09-28

- Added the missing CWidgetFieldSelectAlwaysSaved.php, CWidgetFieldWeekStartView.php, and DetailSelector.php files to the RPM and DEB package definitions.
- Corrected a package failure that prevented dashboards containing Event Timeline from loading.

## v0.1.3 - 2026-09-27

- Added a drilldown dialog for event bar segments.
- Added a configurable first day of the week for seven-day buckets.
- Improved date label spacing, runtime-control wrapping, tooltip behavior after time-period changes, and paging labels.
- Confirmed that automatic bucket widths intentionally move directly from one day to seven days.

## v0.1.2 - 2026-09-21

- Added Timeline, Host, Host group, and Event display modes.
- Added category sorting, paging, automatic paging, and configurable X-axis label direction.
- Added fixed or widget-based host, host group, and trigger inputs.
- Completed Japanese and English user-interface labels for the new controls.

## v0.1.1 - 2026-09-20

- Added fixed and widget-linked monitoring targets and event selections.
- Added runtime severity and acknowledgement filters.
- Added Occurred and Continuing count modes, tooltip ordering, and condition reset.
- Reorganized the settings form and moved summary information below the chart.

## v0.1.0 - 2026-09-19

- Initial release.
- Added severity-stacked problem-event counts over automatically selected time buckets.
- Added event-detail tooltips, period labels, Y-axis scaling, theme-aware colors, and English/Japanese locale support.
