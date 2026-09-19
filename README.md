# Event Timeline Widget for Zabbix

固定したHost / Host groupのProblem Eventを、ダッシュボードの表示期間に合わせた時間Bucketで集計し、Severity別の積み上げ棒グラフで表示するウィジェットです。

> **第2フェーズ（開発中・v0.2.0）**: Monitoring target / Event を Fixed または Widget（Tree Navigator / Event Navigator 連携）から選択でき、表示画面上で Severity・確認状態・イベントカウント方式を動的に切り替えられます。By Host / By Event 表示、Sort、Paging は対象外です。

## 機能

- **対象指定**: Host と Host group（サブグループを含む）の和集合。重複Hostは1回だけ集計
- **表示期間**: ダッシュボードの Time period に追従（ウィジェット個別の期間指定も可）
- **対象Event**: トリガー由来のProblem Event（1 eventid = 1件、抑制中のEventも含む）。Severity は `event.severity`（Event発生時点の値）
- **Severityフィルタ**: Severityごとに集計対象をON/OFF（既定は全ON）。表示後の非表示ではなく集計段階で除外
- **自動Bucket幅**: 候補 `1m, 5m, 10m, 15m, 30m, 1h, 2h, 3h, 6h, 12h, 1d, 7d, 1month` のうち、自然境界に揃えて生成したBucket数が100以下となる最小幅を採用
  - 日・週・月は現地暦で扱う（DSTの日も1Bucket）。7dは月曜0:00開始、1monthは暦月
  - Bucket境界とEvent所属は `start <= clock < end`
  - タイムゾーンはログインユーザーの実効TZ（Bucket境界・軸・Tooltipで共通）
- **積み上げ棒グラフ**: Not classified → Disaster の順に下から積み上げ、Zabbix標準のSeverity色を使用
- **X軸ラベル**: 時間 → 月日 → 年の階層で、前回表示したTickから変化した要素のみ追加表示（先頭Tickは全要素。日・週Bucketは時間行を省略、月Bucketは月と年）
- **Tooltip**: 時間範囲、Total、Hoverした Severity の件数と、そのSeverityのEvent詳細（Host単位にまとめ、発生時刻・Event名。詳細は Bucket×Severity あたり最大10件、超過分は「ほか N イベント」）。複数Hostに関連するEventは関連Hostすべての下に表示（件数は1回のみ）
  - 詳細の並びは表示画面のツールバーのアイコンで「Host単位（既定）」/「時系列」を切替（実行時のみ・保存されない）。Hoverしたスタックは枠線で強調
- **取得上限**: 設定画面で変更可能（既定100,000件）。上限に達した場合はグラフ上部に警告を表示し、集計対象外となる期間を明示
- **外観設定**: グラフ高さ、Y軸最小値（軸の最大値の最低保証。0/空=自動、データがそれを超えれば自動拡張。軸下端は常に0）、線の色・文字色（「自動」= Zabbixテーマ追従。カスタムはカラーピッカーまたは #RRGGBB 入力。「デフォルトを使用」で自動へ戻す。Light/Dark対応）
- **X軸**: 目盛りは最小単位のみ表示し、月日・年は軸下部の期間線（日付/年の切れ目で分割）で表示
- **空状態 / エラー表示**: 「No events」・対象Hostなし・取得失敗
- 日本語 / English ロケール対応

## 第2フェーズの機能

- **Source**: Monitoring target と Event は独立して Fixed / Widget を選択（4通りの組み合わせ）。条件は `Monitoring target AND Event Selection`
  - Monitoring target = Widget: Tree Navigator の Host / Host group 選択（Groupはサブグループ含む・Hostは重複排除）
  - Event = Widget: Event Navigator の Event / Trigger 選択。Event = Fixed: Event名（1行1件・部分一致・OR。空なら絞り込みなし）
  - Widget Source で選択が届いていない場合は全Host・全Eventへ広げず「対象が選択されていません」を表示し、選択の到着・変更で再取得
- **Severity Filter / Acknowledged Filter**: 設定値が実行時の初期値。表示画面のツールバーでON/OFF（Severityチップは凡例を兼ねる）。OFFは集計から除外され、合計は現在のフィルター下で再計算。確認状態は取得時点の現在値（過去時点の再構成なし）
- **Filter Reset**: 設定値へ戻す（全ONではない）。実行時状態が設定値と同じ間は無効
- **Event Count Mode**: 発生イベント数（Eventのclockが属するBucketのみ）/ 継続イベント加算（継続期間と重なるすべてのBucketへ加算。`start < bucket_end AND end > bucket_start`。未復旧は現在時刻・期間終端まで、未来のBucketには加算しない）
- 実行時の状態は設定を書き換えず、通常のRefreshでは保持され、ダッシュボード再表示・ウィジェット再作成で設定値に戻る

## 制約事項

- Event取得はページング（5,000件単位）で、上限件数に達すると古いEventから集計対象外になります（警告表示あり）。
- 約8年を超える期間では1 monthでもBucket数が100を超える場合があります（Zabbixの Time period 上限は730日のため通常は発生しません）。
- Host指定は監視有効/無効を区別しません。
- 継続イベント加算モードの復旧時刻はEventの復旧Event（r_eventid）から取得します。取得上限は期間前に発生したEventの取得分も含みます。

## 動作環境

| 項目 | 要件 |
|------|------|
| Zabbix | 7.0 以上（8.0beta1で動作確認） |
| PHP | 8.1 以上（8.3 / 8.4で動作確認） |

## インストール（開発中・手動）

```bash
cp -r zabbix-widget-event-timeline /usr/share/zabbix/modules/event-timeline
```

Zabbix 管理 → モジュール →「ディレクトリのスキャン」→ Event Timeline を有効化。

## テスト

```bash
php tests/run_tests.php
```

Bucket選択・境界（09:59:59 / 10:00:00 / 10:59:59 / 11:00:00）・DST・月曜開始週・Severity集計などの純粋ロジックを検証します。

## ライセンス

Proprietary（`LICENSE` 参照）。同梱の Apache ECharts は Apache License 2.0（`NOTICE` / `THIRD_PARTY_NOTICES.md` 参照）。
