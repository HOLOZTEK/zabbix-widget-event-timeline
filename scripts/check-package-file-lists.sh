#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TMPDIR_CHECK=$(mktemp -d)
trap 'rm -rf "$TMPDIR_CHECK"' EXIT HUP INT TERM

find "$ROOT/actions" "$ROOT/assets" "$ROOT/includes" "$ROOT/locale" "$ROOT/views" -type f \
	| sed "s#^$ROOT/##" > "$TMPDIR_CHECK/runtime"
for file in LICENSE NOTICE README.md THIRD_PARTY_NOTICES.md manifest.json Module.php Widget.php; do
	printf '%s\n' "$file" >> "$TMPDIR_CHECK/runtime"
done
sort -u "$TMPDIR_CHECK/runtime" -o "$TMPDIR_CHECK/runtime"

awk 'NF && $1 !~ /^#/ {print $1}' "$ROOT/debian/zabbix-widget-event-timeline.install" \
	| sort -u > "$TMPDIR_CHECK/debian"

sed -n 's#.*SRCDIR}/\([^[:space:]]*\).*#\1#p' \
	"$ROOT/packaging/rpm/zabbix-widget-event-timeline.spec" \
	| sort -u > "$TMPDIR_CHECK/rpm"

for source in runtime debian rpm; do
	while IFS= read -r file; do
		if [ ! -f "$ROOT/$file" ]; then
			echo "$source references a missing file: $file" >&2
			exit 1
		fi
	done < "$TMPDIR_CHECK/$source"
done

if ! cmp -s "$TMPDIR_CHECK/runtime" "$TMPDIR_CHECK/debian"; then
	echo "Debian package file list differs from the expected runtime file list:" >&2
	diff -u "$TMPDIR_CHECK/runtime" "$TMPDIR_CHECK/debian" >&2 || true
	exit 1
fi

if ! cmp -s "$TMPDIR_CHECK/runtime" "$TMPDIR_CHECK/rpm"; then
	echo "RPM package file list differs from the expected runtime file list:" >&2
	diff -u "$TMPDIR_CHECK/runtime" "$TMPDIR_CHECK/rpm" >&2 || true
	exit 1
fi

echo "RPM and Debian package file lists contain the complete runtime set and required public documents."
