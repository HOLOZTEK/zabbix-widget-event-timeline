# Changelog

All notable changes to this project are documented in this file.

## v1.0.1 - 2026-11-07

- Verified operation with the Zabbix 8.0.0 final release while retaining the documented nested-LLD limitations.
- Fixed the Japanese widget type and default header name to display as `イベントタイムライン`.
- Updated the public installation examples and package metadata to version 1.0.1.

## v1.0.0 - 2026-10-31

- Declared the first stable release with no functional or saved-configuration changes from v0.2.9.
- Expanded the bilingual README with supported environments, package installation, upgrade, uninstallation, limitations, and future-work guidance.
- Verified Zabbix 7.0.31 and Zabbix 8.0.0rc3 behavior and documented the nested-LLD limitations observed on Zabbix 8.0.
- Published RPM, Debian, and official source ZIP artifacts for the release.

## v0.2.9 - 2026-10-28

- Prepared the project for public distribution under the MIT License and the HOLOZTEK identity.
- Reorganized the widget settings into Targets, Display, Filters, Time period, and Appearance tabs.
- Moved the event retrieval limit to Targets and made at least one severity and acknowledgement state mandatory.
- Added explicit All choices for value-type and item-type filters and corrected the Filters tab layout.
- Rewrote the public documentation to describe the complete v0.2.x feature set.

## v0.2.7 - 2026-10-19

- Stabilized value-type and item-type ordering.
- Added help tooltips to runtime controls and category charts.
- Improved empty-result states while retaining the axes, grid, and footer.
- Completed integration fixes and Zabbix 8.0 compatibility work.

## v0.2.6 - 2026-10-18

- Preserved Zabbix IDs as decimal strings, avoiding collisions for IDs greater than `PHP_INT_MAX`.

## v0.2.5 - 2026-10-18

- Added LLD trigger-prototype normalization and expansion across target hosts.
- Added event filtering by the value type and item type of directly referenced items.
- Added Event-view sorting by affected hosts, event count, or name.

## v0.2.4 - 2026-10-17

- Expanded a selected template trigger to corresponding inherited triggers on all target hosts.
- Added fail-closed and partial-result behavior when trigger matching is unavailable or incomplete.

## v0.2.3 - 2026-10-17

- Added canonical template-trigger normalization across multi-level inheritance.
- Added permission-safe failure behavior and debug-only resolution details.

## v0.2.2 - 2026-10-03

- Added Zabbix 8.0 compatibility for the settings form and color picker while retaining Zabbix 7.0 behavior.

## v0.2.1 - 2026-10-02

- Allowed RPM installation when either `php` or `php-common` 8.1 or later provides the PHP runtime.

## v0.2.0 - 2026-10-02

- Added fixed or widget-linked trigger selection, independently resolved from host selection.
- Renamed the configuration field from Events to Triggers.

## v0.1.5 - 2026-09-29

- Adopted the release-branch workflow and aligned release metadata without functional changes from v0.1.4.

## v0.1.4 - 2026-09-28

- Added three missing runtime classes to the RPM and Debian package definitions.

## v0.1.3 - 2026-09-27

- Added event drilldown, configurable week boundaries, and timeline layout and tooltip fixes.

## v0.1.2 - 2026-09-21

- Added Timeline, Host, Host group, and Event views, sorting, paging, linked inputs, and X-axis label controls.

## v0.1.1 - 2026-09-20

- Added linked targets, runtime controls, Occurred and Continuing count modes, and settings reorganization.

## v0.1.0 - 2026-09-19

- Initial release with severity-stacked problem-event timelines, automatic buckets, tooltips, theming, and English/Japanese locales.
