# zabbix-widget-event-timeline

[English](README.md) | 日本語

## 概要

Event Timeline は、Zabbix の障害イベントを深刻度別の積み上げ棒グラフで確認するためのダッシュボードウィジェットです。

イベントを時間軸で表示するだけでなく、ホスト、ホストグループ、イベント単位でも集計できます。保存済みのウィジェット設定を書き換えることなく、集計方法、確認状態、深刻度、表示モード、並び順をダッシュボード上で切り替えられます。

[最新リリース](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/tag/v0.1.5) | [RPM](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.noarch.rpm) | [DEB](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline_0.1.5_all.deb) | [Source](https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.tar.gz)

## Event Timeline の用途

Zabbix の障害一覧は個別イベントの調査に適していますが、障害が集中した時間帯や繰り返し発生する傾向を把握しにくい場合があります。

Event Timeline は、障害イベントを暦に沿った時間Bucketへ集計し、Zabbix標準の深刻度色で表示します。同じデータをホスト、ホストグループ、イベントのプロトタイプグループ、個別イベントでも表示でき、概要から比較・詳細確認へ移れます。

## 機能

| 機能 | 説明 |
| --- | --- |
| 4種類の表示モード | 時間軸、ホスト、ホストグループ、イベントを切り替えます。 |
| 柔軟な対象指定 | ホスト、ホストグループ、トリガーを固定指定するか、対応する他ウィジェットから受け取ります。 |
| 実行時フィルター | 保存済み設定を上書きせず、深刻度と確認状態を切り替えます。 |
| 2種類の集計方法 | 発生したBucketだけで数えるか、継続期間と重なるすべてのBucketで数えるかを選択します。 |
| Bucket幅の自動選択 | 1分から1か月までの暦境界に揃ったBucketから、通常100本以下となる幅を選択します。 |
| 週境界の設定 | 7日Bucketの開始曜日を全曜日から選択できます。 |
| 並び順とページング | カテゴリ表示を件数順、A→Z、Z→Aで並べ、手動または自動でページ送りします。 |
| TooltipとDrilldown | Tooltipで概要を確認し、棒のクリックでイベント詳細一覧を開きます。 |
| イベントのグループ化 | ディスカバリ由来のイベントをトリガープロトタイプ単位でまとめるか、個別表示します。 |
| テーマ対応 | Zabbixテーマへ自動追従するか、線色と文字色を個別指定します。 |
| 多言語UI | 日本語と英語のロケールを同梱しています。 |

## ダッシュボード上の操作

ウィジェットのツールバーから次の項目を変更できます。

- 表示モード：時間軸、ホスト、ホストグループ、イベント
- 集計方法：発生数、継続数
- 確認状態：全て、未確認、確認済み
- カテゴリ表示の並び順：件数順、A→Z、Z→A
- 時間軸Tooltipの並び：ホスト順、時系列
- Zabbixの6段階の深刻度フィルター
- 実行時の条件を設定画面で指定した初期値へ戻す条件リセット

実行時の変更は通常のウィジェット更新中は維持されますが、保存済み設定は変更しません。

## 設定項目

| 項目 | 説明 |
| --- | --- |
| ホスト | ホストを固定指定するか、他ウィジェットからホストIDを受け取ります。 |
| ホストグループ | ホストグループを固定指定するか、他ウィジェットからグループIDを受け取ります。下位グループも対象です。 |
| イベント | トリガーを固定指定するか、他ウィジェットからトリガーIDを受け取ります。固定指定が空の場合は対象ホストの全イベントを扱います。 |
| 表示モード | 時間軸、ホスト、ホストグループ、イベントの初期表示を指定します。 |
| イベント表示 | プロトタイプグループまたは個別イベントを選択します。 |
| 並び順 | カテゴリ表示の初期並び順を指定します。 |
| 集計方法 | 発生イベント数または継続イベント数を初期値にします。 |
| 深刻度 | 初期状態で有効にする深刻度を指定します。 |
| 確認状態 | 確認済み／未確認フィルターの初期状態を指定します。 |
| グラフの1ページ表示件数 | カテゴリ表示の1ページを10、20、50件から選択します。 |
| 自動ページ送り | 無効、10秒、30秒、60秒から選択します。 |
| 詳細一覧の1ページ表示件数 | Drilldown一覧の1ページを10、20、50件から選択します。 |
| イベント取得上限数 | 1,000～1,000,000件の範囲で指定します。 |
| 表示期間 | 既定ではダッシュボードの表示期間を使用し、ウィジェット固有期間も指定できます。 |
| 週の開始曜日 | 7日Bucketで使用する週境界を指定します。 |
| グラフの高さ | 100～2,000ピクセルで指定します。 |
| Y軸最小値 | Y軸上限の最低保証値を設定します。0では自動調整します。 |
| X軸ラベル | 自動、水平、斜め、縦から選択します。 |
| 線の色／文字の色 | Zabbixテーマへ自動追従するか、16進カラーコードを指定します。 |

## 他ウィジェットからの入力

Event Timeline は次のZabbixダッシュボードデータ型を受け取ります。

- 表示期間（_timeperiod）
- ホストID（_hostids）
- ホストグループID（_hostgroupids）
- トリガーID（_triggerids）

ホストとホストグループは Tree Navigator などから、トリガーは Event Navigator などから連携できます。入力欄をウィジェット参照に設定した状態で選択値が届いていない場合、全ホスト・全イベントへ検索範囲を広げず、対象未選択状態を表示します。

## 動作要件

- Zabbix 7.0 以上
- PHP 8.1 以上
- Zabbixフロントエンドがサポートするブラウザー

## インストール

### RPMパッケージ

~~~bash
rpm -Uvh zabbix-widget-event-timeline-0.1.5.noarch.rpm
~~~

### DEBパッケージ

~~~bash
apt install ./zabbix-widget-event-timeline_0.1.5_all.deb
~~~

パッケージは有効なZabbixフロントエンドのモジュールディレクトリへウィジェットを配置します。インストール後、管理 -> モジュールから再スキャンし、Event Timelineを有効化してください。

### ソースから配置

通常はRPMまたはDEBの使用を推奨します。ソースから配置する場合は次のように実行します。

~~~bash
curl -L -o zabbix-widget-event-timeline-0.1.5.tar.gz \
  https://github.com/HOLOZTEK/zabbix-widget-event-timeline/releases/download/v0.1.5/zabbix-widget-event-timeline-0.1.5.tar.gz
tar -xzf zabbix-widget-event-timeline-0.1.5.tar.gz
install -d /usr/share/zabbix/ui/modules/event-timeline
cd zabbix-widget-event-timeline-0.1.5
cp -a manifest.json Module.php Widget.php actions assets includes locale views \
  /usr/share/zabbix/ui/modules/event-timeline/
~~~

従来のモジュールパスを使用する環境では、代わりに /usr/share/zabbix/modules/event-timeline へ配置してください。配置後、管理 -> モジュールから再スキャンして有効化します。

## 運用上の注意

- イベントは5,000件単位で取得します。設定した取得上限に達した場合、古いイベントは集計対象外となり、警告を表示します。
- 継続数モードの復旧時刻は復旧イベントから取得します。未復旧イベントは現在時刻または選択期間の終端まで継続します。
- 複数ホストに関連するイベントは合計では1件として数えますが、詳細は関連する各ホストに表示される場合があります。
- 確認状態フィルターは現在の確認状態を使用し、イベント発生時点の過去状態は再構成しません。
- ホスト指定では監視有効／無効を区別しません。

## ドキュメント

- [変更履歴](docs/CHANGELOG.md)
- [バージョン整合チェック](scripts/check-version-consistency.sh)
- [パッケージ同梱ファイルチェック](scripts/check-package-file-lists.sh)
- [コントリビューションガイド](docs/CONTRIBUTING.md)
- [セキュリティポリシー](docs/SECURITY.md)
- [第三者ソフトウェア通知](THIRD_PARTY_NOTICES.md)
- [ライセンス](LICENSE)

## リポジトリ構成

- 実行モジュール：リポジトリ直下、actions/、assets/、includes/、locale/、views/
- RPMパッケージ：packaging/rpm/
- Debianパッケージ：debian/
- テスト：tests/
- 公開ドキュメント：docs/

## 同梱ライブラリ

assets/js/echarts.min.js はApache ECharts 6.1.0の圧縮済みビルドで、ZRenderを含みます。いずれもApache License 2.0で配布されています。詳細は [NOTICE](NOTICE) と [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) を参照してください。

## メンテナ

このプロジェクトは [HOLOZTEK](https://github.com/HOLOZTEK) が開発・保守しています。

## ライセンス

現時点のリポジトリには [LICENSE](LICENSE) 記載のプロプライエタリライセンスが適用されています。Apache EChartsおよびZRenderには、それぞれの第三者ライセンス通知が適用されます。
