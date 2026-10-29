# Publication Checklist

Use this checklist before publishing zabbix-widget-event-timeline from staging to GitHub.

## Source

- Confirm the staged source is based on `release/v0.2.9` commit `8a6609812df00e9e390f7e8c60d6edd03e5ececc`.
- Confirm `manifest.json`, the RPM spec, and the Debian changelog all use version 0.2.9.
- Confirm README release links, package filenames, installation commands, and source archive commands use v0.2.9.
- Run both PHP test suites and both public-distribution check scripts.
- Build RPM and Debian packages and compare their installed runtime files with the source tree.
- Confirm the packages include every class referenced by the runtime module.
- Confirm no private URLs, credentials, internal hostnames, or environment-specific files are present.

## Public metadata

- Confirm the target GitHub owner is HOLOZTEK and the repository name is `zabbix-widget-event-timeline`.
- Confirm `manifest.json`, Debian metadata, and RPM metadata use the approved public identity and repository URL.
- Confirm `LICENSE`, RPM metadata, and Debian metadata consistently declare the MIT License.
- Retain the existing `event-timeline` module ID and install directory for upgrade compatibility.
- Confirm Apache ECharts and ZRender notices remain in `NOTICE` and `THIRD_PARTY_NOTICES.md`.

## Documentation

- Confirm `README.md` is the English default and `README.ja.md` is the Japanese version.
- Confirm both language-switch links work and the feature claims match the v0.2.9 implementation.
- Confirm CHANGELOG, CONTRIBUTING, SECURITY, LICENSE, NOTICE, and THIRD_PARTY_NOTICES are present and accurate.
- Confirm the README makes no image references until sanitized screenshots are added.
- Review the required and optional captures in `docs/screenshot-guide.md` before adding images.
- Confirm GitHub private vulnerability reporting is enabled before relying on the SECURITY link.

## Release

- Create the publication approval record with exact owner, repository, version, and full staging commit SHA.
- Publish only after the approval record contains the required approval statement.
- Push the approved commit and v0.2.9 tag without changing their contents.
- Create GitHub release assets from the verified staging commit and record their checksums.
