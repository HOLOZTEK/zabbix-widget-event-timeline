%define _rpmfilename %%{NAME}-%%{VERSION}.%%{ARCH}.rpm
Name:           zabbix-widget-event-timeline
Version:        0.1.4
Release:        0
Summary:        Event Timeline widget for Zabbix dashboard
License:        Proprietary
BuildArch:      noarch
Requires:       php >= 8.1

%description
Zabbix dashboard widget that shows how many problem events occurred over time
as a stacked bar chart (by severity).

Features:
- Host / Host group and trigger selection (fixed or from Tree Navigator / Event Navigator),
  runtime Severity / acknowledgement filters, dashboard Time period support
- Automatic bucket width (at most 100 buckets, 1 minute to 1 month, calendar
  aligned in the user's time zone; the 7-day bucket's week-start day is
  configurable per widget across all 7 weekdays, new widgets default to
  Sunday and existing widgets keep Monday)
- Stacked bars per Severity with hover emphasis; tooltip with per-severity
  event details (host / time / event name; host-grouped or chronological)
- Drilldown dialog showing the detailed event list when clicking an event
  bar / stack segment
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
install -D -m 644 ${SRCDIR}/includes/DimensionAggregator.php ${STAGEDIR}/includes/DimensionAggregator.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldMultiSelectTrigger.php ${STAGEDIR}/includes/CWidgetFieldMultiSelectTrigger.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldMultiSelectTriggerView.php ${STAGEDIR}/includes/CWidgetFieldMultiSelectTriggerView.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldSelectAlwaysSaved.php ${STAGEDIR}/includes/CWidgetFieldSelectAlwaysSaved.php
install -D -m 644 ${SRCDIR}/includes/CWidgetFieldWeekStartView.php ${STAGEDIR}/includes/CWidgetFieldWeekStartView.php
install -D -m 644 ${SRCDIR}/includes/DetailSelector.php ${STAGEDIR}/includes/DetailSelector.php
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
* Mon Sep 28 2026 claude <noreply> - 0.1.4-0
- 【緊急修正】v0.1.3のRPM/DEBパッケージにincludes配下の新規クラス3ファイル
  （CWidgetFieldSelectAlwaysSaved.php, CWidgetFieldWeekStartView.php,
  DetailSelector.php）がパッケージング定義から漏れており、ダッシュボード
  表示時にFatal Errorが発生する不具合を修正。コード自体の変更はなし
  （パッケージ同梱ファイルの追加のみ）

* Sun Sep 27 2026 claude <noreply> - 0.1.3-0
- Event bar / Stack クリックで詳細一覧を表示するDrilldownダイアログを追加（プロトタイプグループ単位/個別イベント単位の切り替え、Host名一覧表示）
- 7日Bucketの週の開始曜日をWidgetごとに全7曜日から設定可能に（新規Widgetの既定は日曜、既存Widgetは月曜を維持）
- 30日前後のX軸日付ラベルを等間隔の格子で選択するよう改善
- X軸ラベルのはみ出し・下部余白・Runtime操作行の折り返しを調整
- ダッシュボードの表示期間変更後にEvent barのTooltipが反応しなくなる不具合を修正
- ページング設定の項目名をグラフ本体用/Drilldown用で明確に区別し、「詳細表示」設定を「ページング」セクションに統合
- Bucket自動選択の候補幅（1分〜1か月）に2日幅を含めない仕様を確定（1日Bucketの次は7日Bucketへ切り替わる）

* Mon Sep 21 2026 claude <noreply> - 0.1.2-0
- 表示モード（時間軸 / ホスト / ホストグループ / イベント）、並び順（件数順・A→Z・Z→A）、ページング、自動ページ送りを追加
- 入力Sourceをホスト・ホストグループ・トリガーの各フィールド（固定またはウィジェット参照）に変更
- 外観に「X軸ラベル」（自動/水平/斜め/縦）を追加。長い名称は省略表示しホバー・Tooltipで全文表示
- 日本語/英語UIの表記を整理（ja_JPに英語が残っていた不具合を修正）

* Sun Sep 20 2026 claude <noreply> - 0.1.1-0
- Monitoring target / イベントを Fixed または Widget（Tree Navigator / Event Navigator）から選択可能に
- イベントの指定をトリガー選択方式に変更（未指定時は対象ホストの全イベント）
- 表示画面上で Severity・確認状態・集計方法（発生数/継続数）・詳細表示順を切替、条件リセット
- Severityチップ（Hoverで名称・件数）、合計/間隔をグラフ下部へ移動、操作UIの説明ラベルを削除
- 設定画面を 対象 / 表示の初期状態 / データ取得 / 外観 に再構成

* Sat Sep 19 2026 claude <noreply> - 0.1.0-0
- 初回リリース。Host / Host group 指定の Problem Event を Severity 別の積み上げ棒グラフで時間Bucket集計表示
- Bucket幅の自動選択（最大100本・1分〜1か月・ユーザーTZの暦基準・週は月曜開始）
- Tooltip（Severity別Event詳細・Host単位/時系列の切替）、Hover強調
- X軸: 最小単位の目盛り＋軸下部の日付・年の期間線
- 外観: グラフ高さ、Y軸最小値、線/文字色（自動=テーマ追従、Picker/Hex指定、Reset）
- 日本語 / 英語ロケール対応
