# Publication Checklist

Use this checklist before publishing zabbix-widget-event-timeline from staging to GitHub.

## Source

- Confirm the staged source contains tag `v1.0.0` commit `8b23d15e5447b191919e96b406997a51a046f814`.
- Confirm `manifest.json`, the RPM spec, and the Debian changelog all use version 1.0.0.
- Confirm README release links, package filenames, installation commands, and source archive commands use v1.0.0.
- Run both PHP test suites and both public-distribution check scripts.
- Confirm the official source ZIP matches the v1.0.0 tag exactly.
- Compare the RPM and Debian installed runtime files with the official source ZIP.
- Confirm the packages include every class referenced by the runtime module.
- Confirm no private URLs, credentials, internal hostnames, or environment-specific files are present.

## Release assets

| File | SHA-256 |
| --- | --- |
| `zabbix-widget-event-timeline-1.0.0.noarch.rpm` | `9339483f82a8a055ebc6ac17a4e657dce73dade93c43c6396847abe168c7d9c2` |
| `zabbix-widget-event-timeline_1.0.0_all.deb` | `a6b12e52e5c573c81fe0dfabed7a966584800b5397c551e5916796a6ad4581dd` |
| `zabbix-widget-event-timeline-v1.0.0.zip` | `125a60539ec61bdd5143fe0d6fb185a833b10116ce528aa8cd16fe33728bb325` |

## Public metadata

- Confirm the target GitHub owner is HOLOZTEK and the repository name is `zabbix-widget-event-timeline`.
- Confirm `manifest.json`, Debian metadata, and RPM metadata use the approved public identity and repository URL.
- Confirm `LICENSE`, RPM metadata, and Debian metadata consistently declare the MIT License.
- Retain the existing `event-timeline` module ID and install directory for upgrade compatibility.
- Confirm Apache ECharts and ZRender notices remain in `NOTICE` and `THIRD_PARTY_NOTICES.md`.

## Documentation

- Confirm `README.md` contains synchronized Japanese and English descriptions.
- Confirm the feature claims match the v1.0.0 implementation and documented v0.2.9 compatibility.
- Confirm CHANGELOG, CONTRIBUTING, SECURITY, LICENSE, NOTICE, and THIRD_PARTY_NOTICES are present and accurate.
- Confirm the README makes no image references until sanitized screenshots are added.
- Review the required and optional captures in `docs/screenshot-guide.md` before adding images.
- Confirm GitHub private vulnerability reporting is enabled before relying on the SECURITY link.

## Release

- Create the publication approval record with exact owner, repository, version, and full staging commit SHA.
- Publish only after the approval record contains the required approval statement.
- Push the approved commit and v1.0.0 tag without changing their contents.
- Create GitHub release assets from the verified staging commit and record their checksums.
