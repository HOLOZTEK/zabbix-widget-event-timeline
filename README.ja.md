# zabbix-widget-event-timeline

[English](README.md) | 日本語

## 概要

Event Timeline は、Zabbix の Problem Event をダッシュボードの表示期間に合わせた時間 Bucket で集計し、Severity 別の積み上げ棒グラフで表示するダッシュボードウィジェットです。

タイムラインに加えて、ホスト、ホストグループ、イベント（トリガー / トリガープロトタイプ）単位の集計と、イベント一覧のドリルダウンを備えます。対象とトリガーは固定指定のほか、Tree Navigator、Event Navigator、Metric Navigator などの互換ウィジェットから受け取れます。

Zabbix の権限は迂回しません。トリガー選択を安全に解決できない場合は fail-closed とし、条件なしの全イベント表示には切り替えません。

## スクリーンショット

タイムライン表示では、ダッシュボードの表示期間に合わせたイベント件数、Severity、実行時フィルターを一画面で確認できます。画像を選択すると原寸で表示できます。

[![Event Timeline のダッシュボード表示](docs/images/event-timeline-dashboard-overview.png)](docs/images/event-timeline-dashboard-overview.png)

### 集計ビュー

| ホスト単位 | ホストグループ単位 |
| --- | --- |
| [![ホスト単位の集計](docs/images/event-timeline-by-host.png)](docs/images/event-timeline-by-host.png) | [![ホストグループ単位の集計](docs/images/event-timeline-by-host-group.png)](docs/images/event-timeline-by-host-group.png) |

イベント表示では、トリガーまたはトリガープロトタイプ単位で影響ホスト数とイベント発生数を比較できます。

[![イベント単位の集計](docs/images/event-timeline-by-event.png)](docs/images/event-timeline-by-event.png)

### 詳細表示

| ツールチップ | ドリルダウン |
| --- | --- |
| Bucket と Severity に含まれるイベントをホスト単位または時系列で確認できます。 | 棒グラフからイベント一覧を開き、状態、確認状況、復旧情報、一致したアイテムを確認できます。 |
| [![イベントのツールチップ](docs/images/event-timeline-tooltip.png)](docs/images/event-timeline-tooltip.png) | [![イベントのドリルダウン](docs/images/event-timeline-drilldown.png)](docs/images/event-timeline-drilldown.png) |

### Widget 連携と実行時操作

| Navigator 連携 | LLD トリガー展開 |
| --- | --- |
| Tree Navigator のホスト・ホストグループ選択を表示対象に利用できます。 | 選択したトリガープロトタイプを、対象ホスト上の Discovered Trigger へ展開できます。 |
| [![Tree Navigator との連携](docs/images/event-timeline-navigator-integration.png)](docs/images/event-timeline-navigator-integration.png) | [![LLD トリガーの展開](docs/images/event-timeline-lld-expansion.png)](docs/images/event-timeline-lld-expansion.png) |

| 実行時フィルター | 自動ページ切替 |
| --- | --- |
| Severity、確認状態、値の型、アイテムタイプ、イベント数の数え方を一時的に変更できます。 | ホスト、ホストグループ、イベント表示をページ分割し、設定した間隔で自動切替できます。 |
| [![実行時フィルター](docs/images/event-timeline-runtime-filters.png)](docs/images/event-timeline-runtime-filters.png) | [![自動ページ切替](docs/images/event-timeline-auto-paging.png)](docs/images/event-timeline-auto-paging.png) |

<details>
<summary><strong>設定画面</strong></summary>

対象、表示、フィルター、期間、外観をタブごとに設定します。

| 対象 | 表示 |
| --- | --- |
| [![対象の設定](docs/images/event-timeline-settings-targets.png)](docs/images/event-timeline-settings-targets.png) | [![表示の設定](docs/images/event-timeline-settings-display.png)](docs/images/event-timeline-settings-display.png) |

| フィルター | 期間 |
| --- | --- |
| [![フィルターの設定](docs/images/event-timeline-settings-filters.png)](docs/images/event-timeline-settings-filters.png) | [![期間の設定](docs/images/event-timeline-settings-time-period.png)](docs/images/event-timeline-settings-time-period.png) |

[![外観の設定](docs/images/event-timeline-settings-appearance.png)](docs/images/event-timeline-settings-appearance.png)

</details>

<details>
<summary><strong>状態表示とテーマ</strong></summary>

| イベントなし | 取得上限の警告 |
| --- | --- |
| [![イベントなしの表示](docs/images/event-timeline-empty-state.png)](docs/images/event-timeline-empty-state.png) | [![イベント取得上限の警告](docs/images/event-timeline-event-limit-warning.png)](docs/images/event-timeline-event-limit-warning.png) |

[![ダークテーマ](docs/images/event-timeline-dark-theme.png)](docs/images/event-timeline-dark-theme.png)

</details>

## 動作要件

| 項目 | 要件 |
| --- | --- |
| Zabbix | 7.0.x（7.0.31 で動作確認）、8.0.0rc3 で動作確認。Zabbix 8.0 正式版は未確認です。 |
| PHP | 8.1 以上（8.3 / 8.4 で動作確認） |
| パッケージ対象 OS | RHEL 互換 9 / 10（RPM）、Ubuntu 22.04 / 24.04（DEB） |
| 権限 | 対象ホストの読み取り権限。Template 由来のトリガーでは、継承チェーン上のすべての Template の参照権限も必要です。 |

## 機能

- **対象指定**: ホストとホストグループ（サブグループを含む）の和集合。重複ホストは1回だけ集計します。
- **表示期間**: ダッシュボードの Time period に追従するほか、ウィジェット固有の期間も指定できます。
- **対象イベント**: トリガー由来の Problem Event（抑制中を含む）。1 eventid を1件とし、Severity はイベント発生時点の値です。
- **表示モード**: タイムライン、ホスト、ホストグループ、イベント。イベント表示はトリガーまたは Canonical トリガープロトタイプで集計できます。
- **実行時フィルター**: Severity、確認状態、値の型、アイテムタイプ。「条件リセット」で設定時の初期値へ戻します。
- **イベント数の数え方**: 「発生」は開始時刻が属する Bucket のみ、「継続」は継続期間と重なるすべての Bucket に加算します。
- **自動 Bucket 幅**: `1m, 5m, 10m, 15m, 30m, 1h, 2h, 3h, 6h, 12h, 1d, 7d, 1month` から、自然境界で100本以下になる最小幅を選びます。
- **現地暦への対応**: 日、週、月はログインユーザーの実効タイムゾーンに従います。週開始曜日は全7曜日から選べ、DST の日も暦上の1 Bucket として扱います。
- **積み上げ棒グラフ**: Not classified から Disaster の順で、Zabbix 標準の Severity 色を使用します。
- **ツールチップとドリルダウン**: 発生時刻、イベント名、ホスト、確認状況、状態、復旧情報、一致したアイテムを表示します。
- **ページング**: ホスト、ホストグループ、イベント表示で手動・自動ページ切替を利用できます。
- **取得上限**: 既定100,000件。上限到達時は集計対象外となる期間を警告に表示します。
- **外観**: グラフ高さ、Y軸最小値、X軸ラベル、線色、文字色を設定でき、Light / Dark テーマに対応します。
- 日本語 / English ロケール対応。

Bucket 境界は `start <= clock < end` です。X軸は最小時間単位を目盛りに表示し、日付と年を軸下の期間線で示します。

## Widget 連携・実行時フィルター・集計方法

Monitoring target と Event は、それぞれ独立して Fixed または Widget を選択します。適用条件は `Monitoring target AND Event selection` です。

- Tree Navigator からホストとホストグループを受け取れます。サブグループを含み、ホストは重複排除します。
- Triggers = Fixed は標準の選択 UI を使います。Triggers = Widget は Event Navigator、Metric Navigator などから受け取れます。
- トリガー未選択時は、対象ホストの期間内の全イベントを表示します。
- Widget から選択が届いていない場合は全ホスト・全イベントへ広げず、「対象が選択されていません」を表示します。
- 実行時の変更は保存設定を書き換えません。通常の Refresh では保持され、ダッシュボード再表示またはウィジェット再作成で設定値へ戻ります。
- 継続イベントは `start < bucket_end AND end > bucket_start` で判定します。未復旧イベントは現在時刻または期間終端までとし、未来の Bucket には加算しません。

## トリガーの対象ホスト展開

トリガー選択とホスト選択を組み合わせると、選択したトリガーと同じ Template トリガーに由来する、対象ホスト上のトリガーのイベントを表示します。

- 一致判定にはトリガー ID と継承元（`templateid`）だけを使い、名前、条件式、Severity は使いません。多段継承は最上位の Template トリガーまで解決します。
- 複数選択は OR で結合し、重複を排除します。
- 対象ホストに Template が適用されていない場合は一致なしとなるだけで、エラーにはしません。
- 展開結果が0件でも条件なしの全イベント表示には切り替えません。
- ホストへ直接作成したローカルトリガーは他ホストへ展開しません。自身のホストが対象の場合だけ使用します。
- 対象ホスト上のトリガーは一括取得し、ホスト数に応じて API 呼び出し回数が増える構造にはしていません。
- 選択したトリガー自体を解決できない場合は、選択全体を適用せず権限警告を表示します。
- 選択を解決できても対象ホスト上の一部の継承元を辿れない場合は、照合できた結果と部分結果の警告を表示します。

複数ホストのアイテムを参照するトリガーは正式対応外です。1つのトリガーとして扱い、参照先ホストのいずれかが対象に含まれると一致します。

## LLD トリガーの対象ホスト展開

Discovered Trigger を選択すると、生成元トリガープロトタイプと Template 継承を Canonical トリガープロトタイプまで解決し、同じ Canonical に由来する対象ホスト上の Discovered Trigger を表示します。

- `{#IFNAME}` などの LLD マクロ値では絞り込みません。同じプロトタイプから生成されたすべてのトリガーが対象です。
- 判定にはプロトタイプ ID、継承関係、生成元の関係だけを使い、名前、条件式、Severity、マクロ値から推測しません。
- ホストローカルのプロトタイプは、同じホスト上の自身から生成されたトリガーだけに一致します。
- 通常トリガーと LLD トリガーは同時に選択でき、結果を OR で結合して重複排除します。
- Lost のトリガーは Zabbix に存在する間は対象です。削除済みトリガーとその過去イベントは取得できず、名前から推測しません。
- 生成元プロトタイプと継承元プロトタイプの参照権限が必要で、解決は fail-closed です。
- イベント表示では Canonical トリガープロトタイプ単位で集計します。継承元を解決できない場合はホスト単位に分割される可能性があり、警告を表示します。

Zabbix 8.0 のネストした LLD は 8.0.0rc3 で確認しています。外側の LLD がホスト・検出行ごとに生成したプロトタイプを Canonical とするため、展開と集計は同じホスト・外側の検出行に限定されます。

## アイテム条件による絞り込み

トリガーが直接参照するアイテムの「値の型」と「アイテムタイプ」でイベントを絞り込めます。

- 同じ軸の複数選択は OR、値の型とアイテムタイプは AND です。両方を指定した場合は同じアイテムが両条件を満たす必要があります。
- 参照アイテムの1件以上が一致すればトリガーが一致します。複数アイテムが一致してもイベントは重複しません。
- 関係の判定には Zabbix の構造情報（`trigger.get` の functions）だけを使い、条件式、名前、アイテムキーを解析しません。
- 通常トリガーと LLD トリガーに対応し、LLD は実際に生成されたアイテムの型で判定します。
- 依存アイテムは「依存アイテム」として扱い、マスターアイテムのタイプへ遡りません。
- 対象ホスト外のアイテムを含む全参照アイテムを判定します。無効アイテムも過去イベントの判定対象です。
- 条件適用中のドリルダウンでは、一致したアイテムのホスト、名前、キー、値の型、アイテムタイプを表示します。
- 判定には表示時点の Zabbix 構成を使い、イベント発生時点の構成は再現しません。
- アイテム情報を安全に取得できない場合は、確認できた一致だけを表示して部分結果の警告を出します。正常な0件では警告しません。

## 表示とツールチップ

- **タイムライン**: Y軸は現在の実行時フィルター下のイベント件数です。
- **ホスト / ホストグループ**: 1本の棒が1ホストまたは1グループで、Severity を積み上げます。件数順、A–Z、Z–A で並べ替えられます。
- **イベント**: 1本の棒が1トリガーまたはプロトタイプグループで、高さは影響ホスト数です。影響ホスト数順、イベント発生数順、A–Z、Z–A を選べます。
- **ツールチップ**: Bucket 範囲、合計、Hover した Severity、Bucket × Severity あたり最大10件の詳細を表示し、残りを「ほか N イベント」とまとめます。
- **詳細**: 棒をクリックするとページング付きのイベント一覧を開きます。イベント名から利用可能な Zabbix 標準イベントメニューを開けます。
- **空状態**: イベント0件でも軸、Grid、フッターを残し、状態を中央に表示します。
- **自動ページ切替**: 1間隔後に開始し、Hover 中やページ非表示時は停止します。操作後は次の間隔から再開します。

## 設定画面

設定画面は5タブ構成です。Type、Name、Refresh interval、Show header は Zabbix 標準ウィジェットと同様にタブ外にあります。

| タブ | 項目 |
| --- | --- |
| 対象 | Hosts、Host groups、Triggers、Event retrieval limit |
| 表示 | Display mode、Event grouping、Sort order、Aggregation method、Chart items per page、Auto paging、Detail items per page |
| フィルター | Severity、Acknowledgement、Value type、Item type |
| 期間 | Time period、First day of week |
| 外観 | Graph height、Y-axis minimum、X-axis labels、Line color、Text color |

設定画面は「対象」タブで開き、タブ移動中も未保存値を保持します。入力エラーがあるタブへ印を付け、最初のエラータブを開いて項目へフォーカスします。Severity と確認状態は最低1件の選択が必要です。値の型とアイテムタイプの「すべて」は、条件なしを表す排他的な選択肢です。

## トリガー連携の権限

Template 由来のトリガーを利用するユーザーには、継承チェーン上のすべての Template を参照できる権限が必要です。Event Timeline は Zabbix の権限を迂回しません。

- 選択したトリガーを1件でも解決できない場合は、選択全体を利用不可とし、解決できた一部だけでは絞り込みません。
- 権限警告は、正常なイベント0件の表示とは区別されます。
- LLD トリガーでは生成元トリガープロトタイプと継承元プロトタイプへの参照権限も必要です。
- ホストローカルのトリガーでは、この解決処理のための Template 参照権限は不要です。
- 選択を解除するか解決可能なトリガーへ変更すると、ページ再読み込みなしで通常表示へ戻ります。

## 制約事項

- イベントは5,000件単位で取得します。取得上限に達すると古いイベントから対象外になります。継続モードでは表示期間より前に始まったイベントも上限に含みます。
- 約8年を超える期間では1か月 Bucket でも100本を超える場合があります。通常、Zabbix の表示期間上限は730日です。
- 監視の有効 / 無効を区別しません。
- 削除済みのトリガーとアイテムは取得できず、過去イベントも表示しません。
- アイテムタイプの判定で、依存アイテムのマスターへは遡りません。
- 複数ホストのアイテムを参照するトリガーとトリガープロトタイプは正式対応外です。
- Zabbix 8.0 のネストした LLD は、前述のとおり同一ホスト・外側の検出行に限定されます。
- Template 選択と無関係でも、継承元を解決できない対象ホスト上のトリガーを安全側で PARTIAL と数える場合があります。fail-open を避けるための挙動です。
- PARTIAL 警告は本体に表示しますが、ドリルダウンダイアログでは再表示しません。
- 実用上の最小幅は約216pxです。約400px未満ではツールバーが折り返し、グラフをウィジェット内で縦スクロールします。
- Zabbix 8.0 正式版は未確認で、8.0.0rc3 まで動作確認済みです。
- 解決結果などのデバッグ情報は Zabbix デバッグモードのユーザーだけに返し、`console.debug` へ出力します。画面には表示しません。
- Zabbix 8.0 以降は `trigger.get` の `selectDiscoveryData`、7.0 は `selectTriggerDiscovery` を使用します。

## インストール

RPM / DEB パッケージ、または GitHub Release に添付したソース ZIP からインストールします。正式なソースアーカイブは `zabbix-widget-event-timeline-v1.0.0.zip` です。自動生成される「Source code」アーカイブとは展開後のディレクトリ名が異なります。

### RPM パッケージ

```bash
rpm -Uvh zabbix-widget-event-timeline-1.0.0.noarch.rpm
```

### DEB パッケージ

```bash
dpkg -i zabbix-widget-event-timeline_1.0.0_all.deb
```

パッケージは `/usr/share/zabbix-widget-event-timeline/` にファイルを置き、`/usr/share/zabbix/ui/modules` があれば `event-timeline` をその下へ、なければ `/usr/share/zabbix/modules` へコピーします。両方がない場合は警告し、モジュールをコピーしません。パッケージに `tests/` は含みません。

### 手動インストール

```bash
unzip zabbix-widget-event-timeline-v1.0.0.zip
# このディレクトリが存在する場合はこちらを優先
cp -r zabbix-widget-event-timeline-v1.0.0 /usr/share/zabbix/ui/modules/event-timeline
# 上記が存在しない場合
cp -r zabbix-widget-event-timeline-v1.0.0 /usr/share/zabbix/modules/event-timeline
```

`tests/` は実行時には不要です。

### インストール後の操作

1. PHP の実行環境を再読み込みします。例: RHEL 互換の PHP-FPM 構成では `systemctl reload php-fpm`、Ubuntu の Apache 構成では `systemctl reload apache2`。
2. Zabbix の「管理 → 一般設定 → モジュール」で「ディレクトリスキャン」を実行し、初回のみ Event Timeline を有効にします。
3. ダッシュボードへ Event Timeline ウィジェットを追加します。

## アップデート

```bash
# RPM
rpm -Uvh zabbix-widget-event-timeline-<version>.noarch.rpm
# DEB
dpkg -i zabbix-widget-event-timeline_<version>_all.deb
```

パッケージ更新ではモジュールディレクトリを新しい版に置き換えます。手動更新では削除済みファイルが残らないよう、既存の `event-timeline` を削除してから新しい版をコピーしてください。更新後は PHP を再読み込みします。モジュールの再スキャン・再有効化は不要です。モジュール ID と設定保存形式は変わらないため、既存設定の移行作業も不要です。

## アンインストール

```bash
# RPM
rpm -e zabbix-widget-event-timeline
# DEB
dpkg -r zabbix-widget-event-timeline
```

事前にダッシュボードからウィジェットを削除するか、モジュールを無効にしてください。パッケージ削除ではモジュールディレクトリも削除します。手動インストールではモジュールを無効にして `event-timeline` を削除します。その後 PHP を再読み込みし、「ディレクトリスキャン」でモジュール登録を削除します。

## テスト

```bash
php tests/run_tests.php
php tests/run_final_tests.php
```

Bucket 選択と境界、DST、全7曜日の週開始設定、Severity 集計などの純粋ロジックを検証します。`tests/` はソース ZIP にだけ含まれます。

## 今後の拡張候補

- Zabbix 8.0 正式版での動作確認。
- ネストした LLD トリガーを、Template 上のトリガープロトタイプ単位で照合・集計すること。
- ドリルダウンダイアログで PARTIAL 警告を再表示すること。

## ライセンス

MIT License。`LICENSE` を参照してください。

Copyright (c) 2026 ttake-55<br>
HOLOZTEK by ttake-55

同梱の Apache ECharts（`assets/js/echarts.min.js`、ZRender を含む）は Apache License 2.0 です。`NOTICE` と `THIRD_PARTY_NOTICES.md` を参照してください。
