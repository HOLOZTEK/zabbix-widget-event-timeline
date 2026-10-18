%define _rpmfilename %%{NAME}-%%{VERSION}.%%{ARCH}.rpm
Name:           zabbix-widget-event-timeline
Version:        0.2.5
Release:        0
Summary:        Event Timeline widget for Zabbix dashboard
License:        Proprietary
BuildArch:      noarch
Requires:       (php >= 8.1 or php-common >= 8.1)

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
install -D -m 644 ${SRCDIR}/includes/Ids.php ${STAGEDIR}/includes/Ids.php
install -D -m 644 ${SRCDIR}/includes/SourceResolver.php ${STAGEDIR}/includes/SourceResolver.php
install -D -m 644 ${SRCDIR}/includes/TimeFormatter.php ${STAGEDIR}/includes/TimeFormatter.php
install -D -m 644 ${SRCDIR}/includes/TriggerInheritanceCache.php ${STAGEDIR}/includes/TriggerInheritanceCache.php
install -D -m 644 ${SRCDIR}/includes/TriggerScopeExpander.php ${STAGEDIR}/includes/TriggerScopeExpander.php
install -D -m 644 ${SRCDIR}/includes/TriggerPrototypeResolver.php ${STAGEDIR}/includes/TriggerPrototypeResolver.php
install -D -m 644 ${SRCDIR}/includes/TriggerPrototypeScopeExpander.php ${STAGEDIR}/includes/TriggerPrototypeScopeExpander.php
install -D -m 644 ${SRCDIR}/includes/ItemCharacteristicsFilter.php ${STAGEDIR}/includes/ItemCharacteristicsFilter.php
install -D -m 644 ${SRCDIR}/includes/ItemMetadataCache.php ${STAGEDIR}/includes/ItemMetadataCache.php
install -D -m 644 ${SRCDIR}/includes/TriggerSelection.php ${STAGEDIR}/includes/TriggerSelection.php
install -D -m 644 ${SRCDIR}/includes/TriggerSelectionResolver.php ${STAGEDIR}/includes/TriggerSelectionResolver.php
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
* Sun Oct 18 2026 claude <noreply> - 0.2.5-0
- LLD / トリガープロトタイプ（Phase 4）: Discovered トリガーをプロトタイプ（parent_triggerid）から
  Template 継承元の最上位プロトタイプへ正規化し、対象Host上の同じプロトタイプ由来のトリガーへ展開する。
  正規化できないものは分離して部分結果の警告を表示する。
- Item 特性フィルタ（Phase 5）: Item の値の型・アイテムタイプで Event を絞り込む。
- Event View の並び順を 影響ホスト数順（既定）/ イベント発生数順 / A-Z / Z-A に変更。
  棒の高さは影響ホスト数のまま。Host / Host group 表示の並び順は従来どおり。

* Sat Oct 17 2026 claude <noreply> - 0.2.4-0
- トリガーの対象Host展開（Phase 3）: 選択されたトリガーを正規化した最上位の定義を、
  Event Timeline の対象Host（固定Host / Host group / 受信Host）へ展開し、対象Host上の
  同じTemplateトリガーに由来するトリガーのイベントを表示する（選択元のHostには束縛しない）。
- Hostローカルのトリガーは所属Hostが対象Hostに含まれるときだけ使用し、LLD由来のトリガーは
  選択されたトリガー自身のみ使用する。展開結果が0件の場合は全イベント表示へ切り替えない。
- 対象Host上の一部のトリガーを照合できない場合は、照合できた分を表示して部分結果の警告を表示する。

* Sat Oct 17 2026 claude <noreply> - 0.2.3-0
- トリガー選択の正規化（Phase 2）: 選択されたトリガーをTemplateの継承元（templateid）を
  たどって最上位の定義へ正規化する（多段継承対応・循環検出・最大64段）。Hostローカルの
  トリガーはそのまま扱い、LLD由来のトリガーは正規化しない。名前・式による同一判定はしない。
- 継承元Templateを参照する権限がなく正規化できないトリガーが1件でもある場合は、選択全体を
  適用できないものとして扱い、全イベント表示や部分結果へフォールバックせず警告を表示する。
- 正規化結果のデバッグ情報は Zabbix のデバッグモードが有効なユーザーにのみ出力する。

* Sat Oct 03 2026 claude <noreply> - 0.2.2-0
- Zabbix 8.0（8.0.0rc1以降）対応: ウィジェット設定画面がHTTP 500（8.0で廃止された
  PHP定数 ZBX_STYLE_COLOR_PICKER / クラス CColor の参照）やJSエラー（jQuery colorpicker の廃止、
  設定フォームIDの widget-dialogue-form → widget-form 変更）で動作しない不具合を修正。
  Zabbix 7.0 では従来の処理を使い、8.0以降は <z-color-picker> / CColorPicker と
  新しいフォームIDを使う。Zabbix 7.0 系での動作は従来と同一。

* Fri Oct 02 2026 claude <noreply> - 0.2.1-0
- RPMのPHP依存を「php >= 8.1」から「(php >= 8.1 or php-common >= 8.1)」に変更。
  phpメタパッケージを伴わない環境（PHP 8.3→8.4への切替時など、php8.4-common等のみが
  入っている状態）で依存性の欠如エラーになる問題を修正。最小PHPバージョン(8.1)は変更なし

* Fri Oct 02 2026 claude <noreply> - 0.2.0-0
- Trigger Selection受信基盤を追加（v0.2.0 Phase 1）。固定Trigger指定に加え、
  Metric Navigator(v0.4.2以降)等が送信する_triggerids(triggerids[])を受信可能に。
  Host Selectionとは独立に解決（TriggerSelection::resolve()）。Phase 1では受信状態の
  可視化のみで、Eventの抽出・集計結果はv0.1.5と同一
- 設定画面のフィールド名を「イベント」から「トリガー」に変更
- RPM/DEBのファイル一覧にincludes/TriggerSelection.phpを追加

* Tue Sep 29 2026 claude <noreply> - 0.1.5-0
- リリース運用をコンパクトなリリースブランチ方式へ移行（release/v0.1.5からmainへPR、
  レビュー・試験後にマージし、以降はmainへタグ・Releaseを作成する運用に変更）。
  機能・パッケージ内容の変更はなし（v0.1.4からの版数統一のみ）
- README/spec説明文のv0.1.4実装内容への追随（週開始曜日設定・ui/modules優先分岐の案内等）を反映

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
