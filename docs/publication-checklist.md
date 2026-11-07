# Publication Checklist

Use this checklist before publishing zabbix-widget-event-timeline from staging to GitHub.

## Source

- Confirm the staged `v1.0.1` tag points to the exact approved staging commit recorded in the release issue.
- Confirm `manifest.json`, the RPM spec, and the Debian changelog all use version 1.0.1.
- Confirm README release links, package filenames, installation commands, and source archive commands use v1.0.1.
- Run both PHP test suites and both public-distribution check scripts.
- Confirm the official source ZIP matches the v1.0.1 tag exactly.
- Compare the RPM and Debian installed runtime files with the official source ZIP.
- Confirm the packages include every class referenced by the runtime module.
- Confirm no private URLs, credentials, internal hostnames, or environment-specific files are present.

## Release assets

Attach all of the following files to the staged and public releases. Record their SHA-256 values in the attached `SHA256SUMS` file and in the release issue.

- `zabbix-widget-event-timeline-1.0.1.noarch.rpm`
- `zabbix-widget-event-timeline_1.0.1_all.deb`
- `zabbix-widget-event-timeline-v1.0.1.zip`
- `SHA256SUMS`

## Public metadata

- Confirm the target GitHub owner is HOLOZTEK and the repository name is `zabbix-widget-event-timeline`.
- Confirm `manifest.json`, Debian metadata, and RPM metadata use the approved public identity and repository URL.
- Confirm `LICENSE`, RPM metadata, and Debian metadata consistently declare the MIT License.
- Retain the existing `event-timeline` module ID and install directory for upgrade compatibility.
- Confirm Apache ECharts and ZRender notices remain in `NOTICE` and `THIRD_PARTY_NOTICES.md`.

## Documentation

- Confirm `README.md` contains synchronized Japanese and English descriptions.
- Confirm the feature claims match the v1.0.1 implementation and documented v0.2.9 compatibility.
- Confirm CHANGELOG, CONTRIBUTING, SECURITY, LICENSE, NOTICE, and THIRD_PARTY_NOTICES are present and accurate.
- Confirm every README image reference resolves to a reviewed, sanitized capture in `docs/images/`.
- Review the required and optional captures in `docs/screenshot-guide.md` before adding images.
- Confirm GitHub private vulnerability reporting is enabled before relying on the SECURITY link.

## Release

- Create the publication approval record with exact owner, repository, version, and full staging commit SHA.
- Publish only after the approval record contains the required approval statement.
- Push the approved commit and v1.0.1 tag without changing their contents.
- Create GitHub release assets from the verified staging commit and record their checksums.
