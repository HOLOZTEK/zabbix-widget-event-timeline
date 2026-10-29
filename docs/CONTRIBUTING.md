# Contributing

Thank you for your interest in improving zabbix-widget-event-timeline.

## Scope

This repository is prepared for public distribution of the Event Timeline widget for Zabbix. Before submitting changes, confirm that they are compatible with the supported Zabbix and PHP versions listed in the README.

## Development notes

- Keep widget behavior compatible with Zabbix 7.0 or later and PHP 8.1 or later.
- Preserve Zabbix IDs as decimal strings; JavaScript numbers and PHP integers cannot safely represent every supported ID.
- Preserve fail-closed behavior when permissions prevent trigger normalization or expansion.
- Do not commit generated packages, local configuration, credentials, or environment-specific files.
- Update both `README.md` and `README.ja.md` when user-visible behavior or compatibility changes.
- Update `manifest.json`, the RPM spec, and the Debian changelog together when the version changes.
- Add release-facing changes to `docs/CHANGELOG.md`.
- Keep English and Japanese locale catalogs and compiled message files synchronized.
- Preserve the Apache ECharts and ZRender notices when changing the bundled JavaScript dependency.
- Keep RPM and Debian runtime file lists complete and synchronized.

## Tests

Run the PHP test suites and public-distribution checks before submitting a change:

~~~bash
php tests/run_tests.php
php tests/run_final_tests.php
./scripts/check-version-consistency.sh
./scripts/check-package-file-lists.sh
~~~

## Reporting issues

Include the Zabbix, PHP, widget, and browser versions; reproduction steps; and expected and actual behavior.

Security-sensitive reports should follow `SECURITY.md` instead of being submitted publicly.
