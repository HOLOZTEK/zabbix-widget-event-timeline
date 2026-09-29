# Contributing

Thank you for your interest in improving zabbix-widget-event-timeline.

## Scope

This repository is prepared for public distribution of the Event Timeline widget for Zabbix. Before submitting changes, confirm that the change is compatible with the supported Zabbix and PHP versions listed in the README.

## Development Notes

- Keep widget behavior compatible with Zabbix 7.0 or later and PHP 8.1 or later.
- Do not commit generated packages, local configuration, credentials, or environment-specific files.
- Update both README files when user-visible behavior or compatibility changes.
- Update manifest.json and the RPM/DEB metadata together when the version changes.
- Add release-facing changes to docs/CHANGELOG.md.
- Keep English and Japanese locale catalogs and compiled message files synchronized.
- Preserve the Apache ECharts and ZRender notices when changing the bundled JavaScript dependency.

## Tests

Run the available PHP test suites before submitting a change:

~~~bash
php tests/run_tests.php
php tests/run_final_tests.php
~~~

## Reporting Issues

Include the Zabbix, PHP, widget, and browser versions; reproduction steps; and expected and actual behavior.

Security-sensitive reports should follow SECURITY.md instead of being submitted publicly.
