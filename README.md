# Event Timeline Widget for Zabbix

固定したHost / Host groupのProblem Eventを、ダッシュボードの表示期間に合わせた時間Bucketで集計し、Severity別の積み上げ棒グラフで表示するウィジェットです。

> **v0.1.0（第一段階）スコープ**: 固定選択のHost / Host groupに対する発生イベント数の時系列表示のみ。Navigator連携、Event選択、動的フィルタ、By Host / By Event表示は対象外です（構造は拡張可能にしてあります）。

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
- **Tooltip**: 時間範囲、Total、Severity別件数
- **取得上限**: 設定画面で変更可能（既定100,000件）。上限に達した場合はグラフ上部に警告を表示し、集計対象外となる期間を明示
- **外観設定**: グラフ高さ、線の色、文字色（未設定時はZabbixテーマに追従。Light/Dark対応）
- **空状態 / エラー表示**: 「No events」・対象Hostなし・取得失敗
- 日本語 / English ロケール対応

## 制約事項

- Event取得はページング（5,000件単位）で、上限件数に達すると古いEventから集計対象外になります（警告表示あり）。
- 約8年を超える期間では1 monthでもBucket数が100を超える場合があります（Zabbixの Time period 上限は730日のため通常は発生しません）。
- Host指定は監視有効/無効を区別しません。

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
