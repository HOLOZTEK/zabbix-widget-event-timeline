#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

manifest_version=$(php -r '$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $data["version"];' "$ROOT/manifest.json")
rpm_version=$(sed -n 's/^Version:[[:space:]]*//p' "$ROOT/packaging/rpm/zabbix-widget-event-timeline.spec" | head -n 1)
debian_version=$(sed -n '1s/^zabbix-widget-event-timeline (\([^)]*\)).*/\1/p' "$ROOT/debian/changelog")

if [ -z "$manifest_version" ] || [ -z "$rpm_version" ] || [ -z "$debian_version" ]; then
	echo "Unable to read one or more release versions." >&2
	exit 1
fi

if [ "$manifest_version" != "$rpm_version" ] || [ "$manifest_version" != "$debian_version" ]; then
	echo "Version mismatch: manifest=$manifest_version rpm=$rpm_version debian=$debian_version" >&2
	exit 1
fi

echo "Version metadata is consistent: $manifest_version"
