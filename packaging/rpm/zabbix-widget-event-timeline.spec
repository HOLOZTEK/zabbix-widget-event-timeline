%define _rpmfilename %%{NAME}-%%{VERSION}.%%{ARCH}.rpm
Name:           zabbix-widget-event-timeline
Version:        0.1.0
Release:        0
Summary:        Event Timeline widget for Zabbix dashboard
License:        Proprietary
BuildArch:      noarch
Requires:       php >= 8.1

%description
Zabbix dashboard widget that shows how many problem events occurred over time
as a stacked bar chart (by severity).

Features:
- Host / Host group selection, Severity filter, dashboard Time period support
- Automatic bucket width (at most 100 buckets, 1 minute to 1 month, calendar
  aligned in the user's time zone, weeks start on Monday)
- Stacked bars per Severity with hover emphasis; tooltip with per-severity
  event details (host / time / event name; host-grouped or chronological)
- X axis: smallest-unit tick labels plus date/year period lines below the axis
- Y axis minimum scale setting, line/text colors (Auto follows the Zabbix theme,
  custom via color picker or hex input)
- Japanese and English locale support

%prep
# nothing

%install
SRCDIR=%{_sourcedir}/zabbix-widget-event-timeline
STAGEDIR=%{buildroot}/usr/share/zabbix-widget-event-timeline

install -D -m 644 ${SRCDIR}/actions/WidgetView.php ${STAGEDIR}/actions/WidgetView.php
install -D -m 644 ${SRCDIR}/assets/css/widget.css ${STAGEDIR}/assets/css/widget.css
install -D -m 644 ${SRCDIR}/assets/js/class.widget.js ${STAGEDIR}/assets/js/class.widget.js
install -D -m 644 ${SRCDIR}/assets/js/echarts.min.js ${STAGEDIR}/assets/js/echarts.min.js
install -D -m 644 ${SRCDIR}/includes/Aggregator.php ${STAGEDIR}/includes/Aggregator.php
install -D -m 644 ${SRCDIR}/includes/BucketPlanner.php ${STAGEDIR}/includes/BucketPlanner.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldMultiSelectBroadcastSource.php ${STAGEDIR}/includes/CWidgetFieldMultiSelectBroadcastSource.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldMultiSelectBroadcastSourceView.php ${STAGEDIR}/includes/CWidgetFieldMultiSelectBroadcastSourceView.php
install -D -m 644 ${SRCDIR}/includes/EventFetcher.php ${STAGEDIR}/includes/EventFetcher.php
install -D -m 644 ${SRCDIR}/includes/helpers.php ${STAGEDIR}/includes/helpers.php
install -D -m 644 ${SRCDIR}/includes/SourceResolver.php ${STAGEDIR}/includes/SourceResolver.php
install -D -m 644 ${SRCDIR}/includes/TimeFormatter.php ${STAGEDIR}/includes/TimeFormatter.php
install -D -m 644 ${SRCDIR}/includes/WidgetForm.php ${STAGEDIR}/includes/WidgetForm.php
install -D -m 644 ${SRCDIR}/LICENSE ${STAGEDIR}/LICENSE
install -D -m 644 ${SRCDIR}/locale/en_US/LC_MESSAGES/event-timeline.mo ${STAGEDIR}/locale/en_US/LC_MESSAGES/event-timeline.mo
install -D -m 644 ${SRCDIR}/locale/en_US/LC_MESSAGES/event-timeline.po ${STAGEDIR}/locale/en_US/LC_MESSAGES/event-timeline.po
install -D -m 644 ${SRCDIR}/locale/ja_JP/LC_MESSAGES/event-timeline.mo ${STAGEDIR}/locale/ja_JP/LC_MESSAGES/event-timeline.mo
install -D -m 644 ${SRCDIR}/locale/ja_JP/LC_MESSAGES/event-timeline.po ${STAGEDIR}/locale/ja_JP/LC_MESSAGES/event-timeline.po
install -D -m 644 ${SRCDIR}/manifest.json ${STAGEDIR}/manifest.json
install -D -m 644 ${SRCDIR}/Module.php ${STAGEDIR}/Module.php
install -D -m 644 ${SRCDIR}/NOTICE ${STAGEDIR}/NOTICE
install -D -m 644 ${SRCDIR}/README.md ${STAGEDIR}/README.md
install -D -m 644 ${SRCDIR}/THIRD_PARTY_NOTICES.md ${STAGEDIR}/THIRD_PARTY_NOTICES.md
install -D -m 644 ${SRCDIR}/views/widget.edit.js.php ${STAGEDIR}/views/widget.edit.js.php
install -D -m 644 ${SRCDIR}/views/widget.edit.php ${STAGEDIR}/views/widget.edit.php
install -D -m 644 ${SRCDIR}/views/widget.view.php ${STAGEDIR}/views/widget.view.php
install -D -m 644 ${SRCDIR}/Widget.php ${STAGEDIR}/Widget.php

%files
/usr/share/zabbix-widget-event-timeline/

%post
if [ -d /usr/share/zabbix/ui/modules ]; then
    ZBXMODDIR=/usr/share/zabbix/ui/modules
elif [ -d /usr/share/zabbix/modules ]; then
    ZBXMODDIR=/usr/share/zabbix/modules
else
    echo "Warning: Zabbix modules directory not found. Skipping module installation." >&2
    exit 0
fi

SRCSTAGE=/usr/share/zabbix-widget-event-timeline
MODDIR=${ZBXMODDIR}/event-timeline

rm -rf "${MODDIR}"
mkdir -p "${MODDIR}"
cp -rp "${SRCSTAGE}/." "${MODDIR}/"

%preun
if [ $1 -eq 0 ]; then
    rm -rf /usr/share/zabbix/ui/modules/event-timeline 2>/dev/null || true
    rm -rf /usr/share/zabbix/modules/event-timeline 2>/dev/null || true
fi

%changelog
* Sat Sep 19 2026 claude <noreply> - 0.1.0-0
- 初回リリース。Host / Host group 指定の Problem Event を Severity 別の積み上げ棒グラフで時間Bucket集計表示
- Bucket幅の自動選択（最大100本・1分〜1か月・ユーザーTZの暦基準・週は月曜開始）
- Tooltip（Severity別Event詳細・Host単位/時系列の切替）、Hover強調
- X軸: 最小単位の目盛り＋軸下部の日付・年の期間線
- 外観: グラフ高さ、Y軸最小値、線/文字色（自動=テーマ追従、Picker/Hex指定、Reset）
- 日本語 / 英語ロケール対応
