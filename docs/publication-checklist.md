# Publication Checklist

Use this checklist before publishing zabbix-widget-event-timeline from staging to GitHub.

## Source

- Confirm the staged source is based on release/v0.1.5 commit 80457a6.
- Confirm manifest.json, the RPM spec, and the Debian changelog all use version 0.1.5.
- Confirm README release links, package filenames, installation commands, and source archive commands use v0.1.5.
- Run both PHP test suites.
- Build RPM and DEB packages and compare their installed runtime files with the source tree.
- Confirm the package includes every class referenced by the runtime module.
- Confirm no private URLs, credentials, internal hostnames, or environment-specific files are present.

## Public Metadata

- Confirm the target GitHub owner is HOLOZTEK.
- Confirm the repository name is zabbix-widget-event-timeline.
- Confirm manifest.json contains the approved public author, description, and repository URL.
- Confirm Debian Maintainer and Source fields contain approved public values.
- Confirm the copyright holder and public software license.
- Confirm RPM and Debian license metadata exactly match LICENSE.
- Decide whether the generic module ID and install directory event-timeline will be retained or migrated to a HOLOZTEK-prefixed identifier.
- If the module directory changes, provide guarded migration logic that never deletes an unverified third-party directory.

## Documentation

- Confirm README.md is the English default and README.ja.md is the Japanese version.
- Confirm both language-switch links work.
- Confirm CHANGELOG, CONTRIBUTING, SECURITY, NOTICE, and THIRD_PARTY_NOTICES are present and accurate.
- Confirm the README makes no image references until sanitized screenshots are added.
- Confirm all claims match the tested v0.1.5 implementation.
- Confirm GitHub private vulnerability reporting is enabled before relying on the SECURITY link.

## Release

- Create the publication approval record with exact owner, repository, version, and full staging commit SHA.
- Publish only after the approval record contains the required publish approved statement.
- Push the approved commit and v0.1.5 tag without changing their contents.
- Create GitHub release assets from the verified staging commit.
