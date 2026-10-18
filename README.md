# Event Timeline Widget for Zabbix

固定したHost / Host groupのProblem Eventを、ダッシュボードの表示期間に合わせた時間Bucketで集計し、Severity別の積み上げ棒グラフで表示するウィジェットです。

> **v0.1.4**: Event bar / Stackクリックで詳細一覧を表示するDrilldown、7日Bucketの週開始曜日のWidgetごとの設定（新規Widgetは日曜、既存Widgetは月曜を維持）を追加しました。対象ホスト・ホストグループ・トリガーは固定またはウィジェット参照（Tree Navigator / Event Navigator 連携）で指定できます。

## 機能

- **対象指定**: Host と Host group（サブグループを含む）の和集合。重複Hostは1回だけ集計
- **表示期間**: ダッシュボードの Time period に追従（ウィジェット個別の期間指定も可）
- **対象Event**: トリガー由来のProblem Event（1 eventid = 1件、抑制中のEventも含む）。Severity は `event.severity`（Event発生時点の値）
- **Severityフィルタ**: Severityごとに集計対象をON/OFF（既定は全ON）。表示後の非表示ではなく集計段階で除外
- **自動Bucket幅**: 候補 `1m, 5m, 10m, 15m, 30m, 1h, 2h, 3h, 6h, 12h, 1d, 7d, 1month` のうち、自然境界に揃えて生成したBucket数が100以下となる最小幅を採用
  - 日・週・月は現地暦で扱う（DSTの日も1Bucket）。7dの週開始曜日はWidgetごとに全7曜日から設定可能（新規Widgetの既定は日曜、既存Widgetは月曜を維持）、1monthは暦月
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
  - トリガー（Triggers。旧称Event） = Fixed: Trigger をホスト等と同じ選択UIで指定。Triggers = Widget: Event Navigator / Metric Navigator等の Trigger 選択を参照。トリガー未指定なら対象Hostの期間内の全Eventを表示（対象Hostが未選択のときは「対象が選択されていません」）
  - Widget Source で選択が届いていない場合は全Host・全Eventへ広げず「対象が選択されていません」を表示し、選択の到着・変更で再取得
- **Severity Filter / Acknowledged Filter**: 設定値が実行時の初期値。表示画面のツールバーでON/OFF（Severityチップは凡例を兼ねる）。OFFは集計から除外され、合計は現在のフィルター下で再計算。確認状態は取得時点の現在値（過去時点の再構成なし）
- **Filter Reset**: 設定値へ戻す（全ONではない）。実行時状態が設定値と同じ間は無効
- **Event Count Mode**: 発生イベント数（Eventのclockが属するBucketのみ）/ 継続イベント加算（継続期間と重なるすべてのBucketへ加算。`start < bucket_end AND end > bucket_start`。未復旧は現在時刻・期間終端まで、未来のBucketには加算しない）
- 実行時の状態は設定を書き換えず、通常のRefreshでは保持され、ダッシュボード再表示・ウィジェット再作成で設定値に戻る

## 第3フェーズの機能（トリガーの対象Host展開）

トリガー選択（Triggers）とホスト選択（Monitoring target）を組み合わせると、**選択したトリガーと同じTemplateトリガーに由来する、対象Host上のトリガー**のイベントを表示します。

- 例: Host A の「Template T / Trigger X」を選択し、Event Timeline の対象が Host B・C の場合、Host B・C 上の「Template T / Trigger X」に由来するイベントを表示します。
- 選択元（Event Navigator / Metric Navigator 等）で選ばれていたHostには固定されません。**表示範囲を決めるのは Event Timeline 側のホスト条件**（固定Host / Host group（サブグループ含む）/ Tree Navigator等から受信したHost）です。
- 一致の判定はトリガーID・継承元（templateid）だけで行い、トリガー名・条件式・Severity が似ているだけのトリガーは対象にしません。多段継承（Template → Template → Host）でも最上位のTemplateトリガーで照合します。
- 複数のトリガーを選択した場合は、それぞれの展開結果の和集合（OR、重複なし）です。
- 対象Hostに該当Templateが適用されていない場合、そのHostには一致するトリガーがなく、イベントが表示されないだけです（エラー・警告にはしません）。
- 展開の結果、一致するトリガーが0件の場合は「イベントなし」です。トリガー条件なしの全イベント表示へは切り替えません。
- トリガー未選択の場合は従来どおり、対象Hostの期間内の全イベントを表示します。
- **Hostへ直接作成されたローカルトリガー**は他Hostへ展開しません。そのトリガー自身の所属Hostが対象Hostに含まれるときだけ使用します（他Hostの同名トリガーは対象外）。
- 展開にもTemplate参照権限が必要です（次節）。選択したトリガー自体を正規化できない場合は、従来どおり選択全体を適用せず警告を表示します。
- **部分結果の警告**: 選択したトリガーは正規化できたものの、対象Host上の一部のトリガーについて継承元Templateを参照できず照合できなかった場合は、照合できたトリガーのイベントだけを表示し、グラフ上部に「対象ホスト上に、選択されたトリガーと照合できないトリガーがあります。結果が不完全な可能性があります。」を表示します。
- 対象Host上のトリガーはHost数に比例しない一括取得で照合します（Host数に応じてAPI呼び出し回数が増える構造にはしていません）。

**対象外・既知の挙動**

- **LLD（ディスカバリ）由来のトリガー**は第4フェーズで対象Host展開に対応しました（次節）。
- **複数Hostのアイテムを参照するトリガー**は正式対応外です。そのようなトリガーは1つのトリガーとして扱われ、参照先Hostのいずれかが対象Hostに含まれると一致します（例: Host A と B を参照するトリガーは、対象が Host B だけでも表示されます）。

### Phase 3: expanding triggers to target hosts

When a trigger selection is combined with a host selection, the widget shows events of **the triggers on the target hosts that derive from the same template trigger as the selected trigger**.

- Example: if "Template T / Trigger X" on host A is selected and the Event Timeline targets hosts B and C, events of "Template T / Trigger X" on hosts B and C are shown.
- The widget is not bound to the host selected in the source widget (Event Navigator, Metric Navigator, etc.). **The Event Timeline host condition defines the scope** (fixed hosts, host groups including subgroups, or hosts received from Tree Navigator etc.).
- Matching uses only trigger IDs and inheritance (templateid). Triggers that merely have a similar name, expression or severity are not matched. Multi-level inheritance is resolved to the top-level template trigger.
- Multiple selected triggers are combined with OR and deduplicated.
- A target host without the template simply has no matching trigger (no error or warning).
- If no trigger matches after expansion, the result is "No events"; it never falls back to unfiltered events.
- Without a trigger selection, all events of the target hosts are shown as before.
- **Host-local triggers** are not expanded to other hosts. They are used only when their own host is in the target hosts.
- Template access permission is also required for expansion (see below). If a selected trigger itself cannot be resolved, the whole selection is rejected with a warning as before.
- **Partial result warning**: if the selected triggers are resolved but some triggers on the target hosts cannot be matched because their inherited templates are not accessible, only the matched triggers' events are shown with the warning "Some triggers on the target hosts could not be matched with the selected triggers. The result may be incomplete."
- Triggers on the target hosts are fetched in bulk; the number of API calls does not grow with the number of hosts.

**Out of scope / known behavior**

- **LLD (discovered) triggers** are expanded since Phase 4 (see below).
- **Triggers referencing items of multiple hosts** are not officially supported. Such a trigger is treated as one trigger and matches when any of its hosts is in the target hosts.

## 第4フェーズの機能（LLD / トリガープロトタイプの対象Host展開）

LLD（ディスカバリ）で生成されたトリガー（Discovered Trigger）を選択した場合は、**そのトリガーの生成元トリガープロトタイプと同じ Canonical トリガープロトタイプに由来する、対象Host上のすべての Discovered Trigger** のイベントを表示します。

- 照合の経路: Discovered Trigger → 生成元トリガープロトタイプ（Host上）→ Template 継承（多段も可）→ Canonical トリガープロトタイプ（継承元のない最上位）→ Event Timeline 側の対象Host上で、同じ Canonical に由来するプロトタイプから生成された Discovered Trigger。
- 例: Host A の「Interface eth0 down」（Template T のプロトタイプ「Interface {#IFNAME} down」由来）を選択し、対象が Host B の場合、Host B 上の同じプロトタイプ由来のトリガー（ens18 / ens19 / bond0 など）すべてのイベントを表示します。
- **LLDマクロ値（{#IFNAME} 等）では絞り込みません**。選択した eth0 と同じ値のトリガーだけでなく、同じプロトタイプから生成されたすべてのトリガーが対象です。
- 一致の判定はプロトタイプID・継承元（templateid）・生成元の関係だけで行います。トリガー名・プロトタイプ名・条件式・Severity・LLDマクロ値は使いません。**同名でも別Templateのプロトタイプは別物**として扱います。
- 対象Hostに該当Templateが適用されていない、またはディスカバリ結果が0件の場合は、該当なし（イベントなし）になるだけで、エラー・警告にはしません。
- **Hostへ直接作成されたプロトタイプ**（Hostローカル）は、そのプロトタイプ自身が Canonical です。同じHost上のそのプロトタイプ由来のトリガーだけが一致し、他Hostの同名プロトタイプとは対応付けません。
- 通常トリガーとLLDトリガーを同時に選択した場合は、それぞれの展開結果の和集合（OR、重複なし）です。
- **Lost（ディスカバリで検出されなくなり、削除待ち）のトリガー**は、Zabbix上に存在する間は通常どおり対象です。
- **削除済みのトリガー**は Zabbix から取得できないため対象外です（削除前に発生したイベントも表示しません。名前等から推測して補うことはしません）。削除済みトリガーを選択していた場合（古い選択の受信など）は、存在しない選択として扱い「イベントなし」になります。
- **権限（fail-closed）**: 選択した Discovered Trigger の生成元プロトタイプ、または継承元のプロトタイプ（Template）を参照できない場合は、第3フェーズと同じく選択全体を適用せず、権限不足の警告を表示します。
- **部分結果の警告**: 選択は正規化できたものの、対象Host上の一部の Discovered Trigger について継承元を辿れず照合できない場合は、照合できたトリガーのイベントだけを表示し、第3フェーズと同じ部分結果の警告を表示します。ただし、Host ローカルのプロトタイプだけを選択している場合、Template由来のプロトタイプは一致し得ないため警告しません。
- **イベント表示（プロトタイプグループ）**: 表示モード「イベント」のプロトタイプグループは Canonical トリガープロトタイプ単位で集計します（同じ Template プロトタイプ由来なら Host が違っても1グループ）。継承元を辿れないプロトタイプは、生成元（Host上）のプロトタイプ単位で分けて表示し、「一部のイベントは継承元のトリガープロトタイプを特定できないため、プロトタイプグループがホストごとに分かれて表示されている可能性があります。」を表示します（イベントの集合は変わりません）。
- 対象Host上のトリガーとプロトタイプは一括取得・段ごとの一括解決で照合します（Host数・トリガー数に比例してAPI呼び出し回数が増える構造にはしていません）。

**対象外・既知の挙動**

- **複数Hostのアイテムを参照するトリガープロトタイプ**は正式対応外です。生成されたトリガーは1つのトリガーとして扱われ、第3フェーズの複数Host参照トリガーと同じく、参照先Hostのいずれかが対象Hostに含まれると一致します。
- Zabbix 8.0 のネストしたLLD（ディスカバリで生成されたプロトタイプ）は**未検証**です（対応済みではありません）。Zabbix 8.0 正式版での実データ確認を v1.0.0 公開前の確認項目としています。

### Phase 4: expanding LLD triggers (trigger prototypes) to target hosts

When a discovered (LLD) trigger is selected, the widget shows events of **all discovered triggers on the target hosts whose trigger prototype derives from the same canonical trigger prototype**.

- Path: discovered trigger → parent trigger prototype (on the host) → template inheritance (multi-level) → canonical trigger prototype (top-level, no parent) → discovered triggers on the Event Timeline target hosts created from prototypes with the same canonical prototype.
- Example: if "Interface eth0 down" on host A (from prototype "Interface {#IFNAME} down" of template T) is selected and the target is host B, events of all triggers on host B created from that prototype (ens18, ens19, bond0, ...) are shown.
- **LLD macro values ({#IFNAME} etc.) are not used for filtering.** All triggers created from the same prototype are included, not only those with the same value as the selected one.
- Matching uses only prototype IDs, inheritance (templateid) and the discovery relationship. Trigger names, prototype names, expressions, severities and LLD macro values are never used. **Prototypes with the same name on different templates are different.**
- A target host without the template, or with no discovered entities, simply has no match (no error or warning).
- **Host-local prototypes** (created directly on a host) are their own canonical prototype. Only triggers from that prototype on the same host match; same-name prototypes on other hosts are not mapped.
- Normal and LLD triggers can be selected together; the result is the union (OR, deduplicated).
- **Lost triggers** (no longer discovered, pending deletion) are included while they still exist in Zabbix.
- **Deleted triggers** cannot be retrieved from Zabbix and are not included (their past events are not shown either; nothing is inferred from names). A selection of a deleted trigger (e.g. a stale broadcast) is treated as not found and results in "No events".
- **Permissions (fail-closed)**: if the parent prototype of a selected discovered trigger, or an inherited prototype (template), cannot be accessed, the whole selection is rejected with the permission warning, as in Phase 3.
- **Partial result warning**: if the selection is resolved but some discovered triggers on the target hosts cannot be matched because their inheritance cannot be traced, only matched triggers are shown with the Phase 3 partial result warning. When only host-local prototypes are selected, template-derived prototypes can never match, so no warning is shown.
- **Event mode (prototype groups)**: prototype groups are aggregated per canonical trigger prototype (one group across hosts for the same template prototype). Prototypes whose inheritance cannot be traced are grouped per their own (host) prototype, with the warning "Some events could not be grouped by their inherited trigger prototype. Some prototype groups may be split by host." (the set of events is unchanged).
- Triggers and prototypes on the target hosts are fetched in bulk and resolved level by level; the number of API calls does not grow with the number of hosts or triggers.

**Out of scope / known behavior**

- **Trigger prototypes referencing items of multiple hosts** are not officially supported. The generated trigger is treated as one trigger and, like Phase 3 multi-host triggers, matches when any of its hosts is in the target hosts.
- Nested LLD in Zabbix 8.0 (prototypes created by discovery) is **not verified** (not supported yet). Verification with real data on the Zabbix 8.0 final release is planned before v1.0.0.

## 第5フェーズの機能（アイテム条件によるイベントの絞り込み）

トリガーが**直接参照するアイテム**の「値の型（Value type）」と「アイテムタイプ（Item type）」で、表示するイベントを絞り込めます。

- 表示画面のツールバーに「値の型」「アイテムタイプ」のドロップダウンがあり、それぞれ複数選択できます。未選択（すべて）の場合は条件なしです。
- 初期値はWidget設定の「値の型」「アイテムタイプ」で指定します。表示画面で変更した条件は一時的なもので、「条件リセット」で設定値に戻ります。
- 選択肢と表示名は Zabbix 本体の定義・翻訳をそのまま使います。Zabbix 8.0 では値の型に JSON、アイテムタイプに Nested（ネストされた）/ Telemetry query が加わります（7.0 では表示されません）。トリガーで一般的に使わない型も選択肢から除外しません。

**判定方式**

- 同じ軸で複数選択した場合は **OR**（例: 値の型 = ログ または テキスト）。
- 値の型とアイテムタイプの両方を指定した場合は **AND** で、**同じアイテムが両方の条件を満たす**必要があります。例えば、ログ型の Zabbix トラッパーアイテムと、数値型の SNMP トラップアイテムを参照するトリガーは、「値の型 = ログ AND アイテムタイプ = SNMPトラップ」には一致しません。
- トリガーが参照するアイテムのうち1件以上が条件を満たせば、そのトリガーのイベントを表示します。複数のアイテムが一致してもイベントは重複しません。
- トリガーとアイテムの関係は Zabbix の構造情報（trigger.get の functions）だけで判定します。条件式・トリガー名・アイテムキーの文字列からは推測しません。
- トリガー選択（第3・4フェーズ）がある場合はその展開結果に、ない場合は対象Host上のトリガーに対して適用します。通常トリガー・LLDトリガーの両方が対象で、LLDトリガーは**実際に生成されたアイテム**の型で判定します。
- **依存アイテム**: トリガーが依存アイテムを直接参照する場合、アイテムタイプは「依存アイテム」として判定します。マスターアイテムのタイプへ自動的に遡りません（マスターが SNMP トラップでも「SNMPトラップ」には一致しません）。
- **複数Hostのアイテムを参照するトリガー**: 参照するすべてのアイテム（対象Host以外のHostのアイテムを含む）を判定に使います。
- **無効なアイテム**: アイテムの状態（有効/無効）は条件にしません。無効化されたアイテムを参照するトリガーの過去のイベントも、型が一致すれば表示します。
- 条件を指定しない場合はトリガー・アイテムの追加取得を行わず、従来と同じイベントを表示します。条件を指定した場合も、トリガーとアイテムの情報は一括取得（アイテムは1,000件単位）で、Host数・トリガー数に比例してAPI呼び出し回数が増える構造にはしていません。

**詳細表示（ドリルダウン）**

- 条件を指定している場合、各イベント行の下に「一致したアイテム (n)」を表示し、展開すると一致したアイテムの Host・アイテム名・アイテムキー・値の型・アイテムタイプを確認できます。

**大量イベントの発生源の絞り込み（例）**

```text
値の型 = ログ → 表示モード「ホスト」（件数順）で発生元Hostを特定 → 表示モード「イベント」（イベント発生数順）で原因トリガーを特定
```

- 表示モード「イベント」の並び順は「影響ホスト数順」「イベント発生数順」「A-Z」「Z-A」です（既定は影響ホスト数順）。
  - 影響ホスト数順: 影響ホスト数の多い順（従来の「件数順」と同じ並び）。
  - イベント発生数順: 該当イベント数の多い順（同数は影響ホスト数の多い順、さらに名前順）。1台のHostで大量に発生しているトリガーを見つけるときに使います。
  - どの並び順でも、グラフの棒の高さは**影響ホスト数**です（並び順だけが変わります）。イベント数はツールチップの「該当イベント数」で確認できます。
- 表示モード「ホスト」「ホストグループ」の並び順は従来どおり「件数順」「A→Z」「Z→A」です（グラフの値がイベント数のため、イベント発生数順は件数順と同じ並びになり、ボタンは表示しません）。
- 設定画面の「並び順」では「イベント発生数順」を選べます（表示モード「イベント」以外では件数順として動作します）。

または「アイテムタイプ = SNMPトラップ」で、SNMPトラップ由来のイベントだけに絞り込めます。

**現在の構成に基づく判定と部分結果の警告**

- 判定には、表示時点の Zabbix の構成（トリガーが参照するアイテムと、その現在の型）を使います。過去のイベント発生時点の構成ではありません。
- 削除済みのアイテムなど、現在の Zabbix からアイテム情報を安全に取得できない場合は、完全な分類ができません。この場合、一致を確認できたトリガーのイベントだけを表示し、「一部のアイテム情報を確認できないため、結果が不完全な可能性があります。」を表示します。条件に一致するイベントが単に0件の場合（正常な0件）は警告を表示しません。
- 補足: Zabbix の権限モデルでは、参照先Hostの権限がないアイテムを参照するトリガーは、トリガー自体がユーザーから見えなくなります（実環境で確認済み）。そのため通常の権限設定でこの警告が表示されることはほとんどありませんが、取得失敗等に備えて判定不能を一致・不一致のどちらにもしない防御的な実装にしています。

**対象外・既知の挙動**

- Zabbix 8.0 のネストしたLLD（ディスカバリで生成されたプロトタイプ）由来のトリガー・アイテムでの判定は**未検証**です。

### Phase 5: filtering events by item characteristics

Events can be filtered by the **value type** and **item type** of the items **directly referenced** by each trigger.

- The toolbar has "Value type" and "Item type" drop-downs; each allows multiple selection. Nothing selected (All) means no condition.
- Initial values are set in the widget configuration ("Value type", "Item type"). Changes made in the view are temporary; "Reset" restores the configured values.
- Options and labels are taken from Zabbix itself (definitions and translations). Zabbix 8.0 adds JSON (value type) and Nested / Telemetry query (item type); they are not shown on 7.0. Types rarely used in triggers are not excluded.

**Filter logic**

- Multiple selections on the same axis are combined with **OR** (e.g. value type = Log or Text).
- Value type and item type are combined with **AND**, and **the same item must satisfy both**. For example, a trigger referencing a Log Zabbix trapper item and a numeric SNMP trap item does not match "value type = Log AND item type = SNMP trap".
- A trigger matches if at least one of its referenced items matches. Events are never duplicated when several items match.
- The trigger–item relationship is taken only from Zabbix structural data (trigger.get functions). Expressions, trigger names and item keys are never parsed or guessed from.
- With a trigger selection (Phases 3/4) the filter is applied to the expanded triggers; without one, to the triggers on the target hosts. Both normal and LLD triggers are supported; LLD triggers are judged by their **discovered items**.
- **Dependent items**: if a trigger directly references a dependent item, its item type is "Dependent item". The master item's type is not followed (a dependent item whose master is an SNMP trap item does not match "SNMP trap").
- **Triggers referencing items of multiple hosts**: all referenced items (including items on hosts outside the target hosts) are evaluated.
- **Disabled items**: item status is not a condition. Past events of triggers referencing disabled items are shown if the type matches.
- Without conditions no additional trigger/item queries are made and the result is the same as before. With conditions, triggers and items are fetched in bulk (items in chunks of 1,000); the number of API calls does not grow with the number of hosts or triggers.

**Drilldown**

- When a condition is active, each event row in the details shows "Matching items (n)"; expand it to see the host, item name, item key, value type and item type of the matched items.

**Burst analysis example**

```text
Value type = Log → Display mode "Host" (by count) to find the source host → Display mode "Event" (Event count) to find the trigger
```

- In display mode "Event", the sort orders are "Affected hosts", "Event count", "A-Z" and "Z-A" (default: Affected hosts).
  - Affected hosts: by the number of affected hosts, descending (same order as the former "By count").
  - Event count: by the number of matching events, descending (ties by affected hosts, then by name). Use it to find a trigger that fires many events on a single host.
  - With every sort order the bar height is the **number of affected hosts**; only the order changes. The event count is shown as "Events" in the tooltip.
- Display modes "Host" and "Host group" keep "By count", "A to Z" and "Z to A" (the chart value already is the event count, so "Event count" would give the same order as "By count"; the button is not shown).
- The "Sort order" setting also offers "Event count" (it behaves as "By count" outside display mode "Event").

Or use "Item type = SNMP trap" to narrow down to events from SNMP trap items.

**Current configuration and partial result warning**

- Classification uses the current Zabbix configuration (the items currently referenced by the trigger and their current types), not the configuration at the time the events occurred.
- If item metadata cannot be safely retrieved from the current Zabbix (e.g. deleted items), classification may be incomplete. Only events of triggers confirmed to match are shown, with the warning "Some item information could not be confirmed. The result may be incomplete." A normal empty result (no matching events) shows no warning.
- Note: in the Zabbix permission model, a trigger referencing an item on a host the user cannot access is itself hidden from the user (verified in a real environment). This warning therefore rarely appears with normal permission settings; the implementation is defensive and never treats an undeterminable trigger as matching or non-matching.

**Out of scope / known behavior**

- Triggers and items from nested LLD in Zabbix 8.0 (prototypes created by discovery) are **not verified**.

## トリガー連携の権限

Event TimelineでTemplate由来のトリガーを使用する場合、ログインユーザーには、そのトリガーの継承元Template（継承チェーン上のすべてのTemplate）を参照できる権限が必要です。これは不具合の回避策ではなく製品仕様です。Event TimelineはZabbixの権限を迂回してTemplateを参照することはありません。

- 継承元Templateを参照できない場合、Event Timelineはトリガーを正規化できないため、トリガー条件を適用せずに全イベントを表示することはありません。
- 選択したトリガーのうち1件でも正規化できない場合は、選択全体を適用できないものとして扱います。正規化できたトリガーだけで絞り込んだ不完全な部分結果も表示しません。
- この場合、Widgetに権限不足を示す警告「選択されたトリガーを展開できません。継承元テンプレートの参照権限を確認してください。」が表示されます（該当イベントが0件の通常表示とは区別されます）。
- LLD由来のトリガーでは、生成元のトリガープロトタイプと、その継承元Template上のプロトタイプを参照できる権限が必要です（第4フェーズ）。
- Template参照権限が必要なのはTemplate由来のトリガーの場合だけです。Hostへ直接作成されたローカルトリガーでは、Template参照権限は必要ありません。
- トリガー選択を解除する、または正規化できるトリガーへ変更すると、ページを再読み込みしなくても通常の表示に戻ります。

### Permissions for trigger integration

When template-inherited triggers are used with Event Timeline, the logged-in user must have permission to access the templates in the trigger inheritance chain. This is a product requirement, not a workaround; Event Timeline never bypasses Zabbix permissions to read templates.

- If an inherited template cannot be accessed, Event Timeline cannot resolve the trigger to its canonical definition. It does not fall back to displaying unfiltered event results.
- If any of the selected triggers cannot be resolved, the whole selection is treated as unusable. Partial results filtered by only the resolvable triggers are not displayed.
- A permission warning is shown in the widget in this case: "The selected triggers cannot be resolved. Check access permissions for the inherited templates." (distinct from a normal result with no events).
- For LLD (discovered) triggers, access to the parent trigger prototype and its inherited prototypes on templates is required (Phase 4).
- Template access is required only for template-inherited triggers. Triggers created directly on a host do not require template access for this resolution step.
- Clearing the trigger selection or changing it to resolvable triggers restores normal display without reloading the page.

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
# /usr/share/zabbix/ui/modules が存在する環境（Zabbix 7.0以降の標準構成）はこちらを優先
cp -r zabbix-widget-event-timeline /usr/share/zabbix/ui/modules/event-timeline
# 上記が存在しない環境
cp -r zabbix-widget-event-timeline /usr/share/zabbix/modules/event-timeline
```

RPM/DEBパッケージのインストールスクリプトも同様に、`ui/modules`が存在すればそちらを優先し、なければ`modules`へ配置します。

Zabbix 管理 → モジュール →「ディレクトリのスキャン」→ Event Timeline を有効化。

## テスト

```bash
php tests/run_tests.php
```

Bucket選択・境界（09:59:59 / 10:00:00 / 10:59:59 / 11:00:00）・DST・週開始曜日（全7曜日）・Severity集計などの純粋ロジックを検証します。週境界・タイムゾーン・Bucket自動選択の追加試験は `php tests/run_final_tests.php` を参照。

## ライセンス

Proprietary（`LICENSE` 参照）。同梱の Apache ECharts は Apache License 2.0（`NOTICE` / `THIRD_PARTY_NOTICES.md` 参照）。
