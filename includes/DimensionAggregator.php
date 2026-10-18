<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Dimension Aggregation / Sort / Paging（描画から独立した純粋なロジック）。
 *
 * 第3フェーズの表示モードは「Dimension × Measure × Stack」で表す。Stack は常に Severity。
 *   By Host       : Dimension=Host        Measure=Event数（Host内で1 eventid = 1件）
 *   By Host group : Dimension=Host group  Measure=Event数（Group内で1 eventid = 1件）
 *   By Event      : Dimension=Event名     Measure=影響Host数（Event名 × Host のユニーク数）
 *                   行には該当Event数（event_count）も持たせる（並び替え SORT_EVENTS と Tooltip 用。Measure は変えない）
 *
 * 入力の $events は Filter（Severity / Ack）と期間判定を通過済みのEvent。
 * $event_hosts は eventid => Host ID[]（対象Host集合との交差済み）。
 *
 * 出力行: ['id' => string, 'label' => string, 'counts' => int[6]（Severity値の昇順）, 'total' => int]
 */
class DimensionAggregator {

	public const SORT_COUNT = 0;
	public const SORT_ASC   = 1;
	public const SORT_DESC  = 2;
	// イベント数順（v0.2.0 Phase 5 §50）: Event mode で、Measure（影響Host数）を変えずに該当Event数の降順へ並べる。
	// event_count を持たない行（Host / Host group。Measure がEvent数）では件数順と同じ並びになる。
	public const SORT_EVENTS = 3;

	public const SORTS = [self::SORT_COUNT, self::SORT_ASC, self::SORT_DESC, self::SORT_EVENTS];

	private static function emptyCounts(): array {
		return array_fill(0, count(Aggregator::SEVERITIES), 0);
	}

	/**
	 * By Host: 各Hostについて、そのHostに関連するEventを重複なく数える。
	 * 複数Hostに関連するEventは、各Hostで1件ずつ数える。
	 *
	 * @param array<int|string, string> $host_names  hostid => 表示名（カテゴリの母集団）
	 */
	public static function byHost(array $events, array $event_hosts, array $host_names): array {
		$seen = [];
		$rows = [];

		foreach ($events as $e) {
			$severity = (int) $e['severity'];

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				if (!isset($host_names[$hostid]) || isset($seen[$hostid][$e['eventid']])) {
					continue;
				}

				$seen[$hostid][$e['eventid']] = true;
				$rows[$hostid] ??= ['id' => (string) $hostid, 'label' => (string) $host_names[$hostid],
					'counts' => self::emptyCounts(), 'total' => 0];
				$rows[$hostid]['counts'][$severity]++;
				$rows[$hostid]['total']++;
			}
		}

		return array_values($rows);
	}

	/**
	 * By Host group: 各Groupについて、Groupに属するHostに関連するEventを重複なく数える（Group内で1 eventid = 1件）。
	 * HostがGroupを複数持つ場合、Eventは複数Groupに現れる。
	 *
	 * @param array<int|string, array<int|string, string>> $host_groups  hostid => [groupid => group名]
	 */
	public static function byHostGroup(array $events, array $event_hosts, array $host_groups): array {
		$seen = [];
		$rows = [];

		foreach ($events as $e) {
			$severity = (int) $e['severity'];

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				foreach ($host_groups[$hostid] ?? [] as $groupid => $group_name) {
					if (isset($seen[$groupid][$e['eventid']])) {
						continue;
					}

					$seen[$groupid][$e['eventid']] = true;
					$rows[$groupid] ??= ['id' => (string) $groupid, 'label' => (string) $group_name,
						'counts' => self::emptyCounts(), 'total' => 0];
					$rows[$groupid]['counts'][$severity]++;
					$rows[$groupid]['total']++;
				}
			}
		}

		return array_values($rows);
	}

	/**
	 * By Event: Event名ごとの影響Host数（Event名 × Host のユニーク数）。eventid 単位では数えない。
	 * 該当Event数は event_count として別に返す（Measure の total は影響Host数のまま）。
	 * 同じEvent名 × Hostに複数のSeverityがある場合は、現在のFilterを通ったEventのうち最も高いSeverityへ1回だけ割り当てる。
	 *
	 * @param array<string, string> $event_names  eventid => Event名
	 */
	public static function byEvent(array $events, array $event_hosts, array $event_names,
			array $event_triggers = []): array {
		$best = [];
		$seen_events = [];
		$extra = [];

		foreach ($events as $e) {
			$name = (string) ($event_names[$e['eventid']] ?? '');
			$seen_events[$name][$e['eventid']] = true;
			self::collectExtra($extra[$name], $e, $event_triggers);

			foreach (array_unique($event_hosts[$e['eventid']] ?? []) as $hostid) {
				$severity = (int) $e['severity'];

				if (!isset($best[$name][$hostid]) || $best[$name][$hostid] < $severity) {
					$best[$name][$hostid] = $severity;
				}
			}
		}

		$rows = [];

		foreach ($best as $name => $hosts) {
			$row = ['id' => (string) $name, 'label' => (string) $name, 'counts' => self::emptyCounts(), 'total' => 0,
				'event_count' => count($seen_events[$name] ?? []), 'hostids' => array_keys($hosts)] + $extra[$name];

			foreach ($hosts as $severity) {
				$row['counts'][$severity]++;
				$row['total']++;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * By Event（プロトタイプグループ）のGroup key。Trigger prototype由来（Discovery）であれば
	 * 'p:'+Group化に使う Trigger prototype ID、そうでない（通常Trigger）場合は
	 * 'n:'+Event名にFallbackする。文字列の類推（正規表現・ポート番号除去・桁無視・類似度等）は一切行わず、
	 * Zabbixの実際の生成関係・継承関係（$event_proto。WidgetView::resolveEventPrototypes() の結果）だけを根拠にする。
	 * v0.2.0 Phase 4 以降、$event_proto の prototype ID は Template 継承元の Canonical Trigger prototype（Host をまたいで
	 * 同じ Group になる）。Canonical を解決できない Event だけ、生成元（Host上）の prototype ID のまま分離される。
	 * 集計（byEventGroup）とDrilldown選定（DetailSelector::byEventGroup）が同じ規則で選ぶよう、共通で使う。
	 *
	 * @param array<string, ?string> $event_proto  eventid => Group化に使う Trigger prototype ID（解決できなければ null）
	 * @param array<string, string>  $event_names  eventid => Event名
	 * @param array<string, string>  $proto_names  Trigger prototype ID => 表示名（解決できたものだけ）
	 */
	public static function eventGroupKey(string $eventid, array $event_proto, array $event_names,
			array $proto_names): string {
		$proto = $event_proto[$eventid] ?? null;

		return $proto !== null && isset($proto_names[$proto])
			? 'p:'.$proto
			: 'n:'.(string) ($event_names[$eventid] ?? '');
	}

	private static function eventGroupLabel(string $key, array $proto_names): string {
		if (strncmp($key, 'p:', 2) === 0) {
			$id = substr($key, 2);

			return $proto_names[$id] ?? $id;
		}

		return substr($key, 2);
	}

	/**
	 * By Event（プロトタイプグループ）: Trigger prototype由来のEventを生成元Prototype単位でGroup化し、
	 * 影響Host数（Measure。Prototype × Host のユニーク数。byEvent と同じ考え方）と、そのGroupに属する
	 * 該当Event数（event_count）を集計する。通常 Trigger（Prototype由来でない）のEventは、Event名単位の
	 * Group（byEventと同じ粒度）として扱う（§37〜39: 特別な警告表示はせず、通常のEventとして扱う）。
	 * Prototype由来で Canonical を解決できない Event は、prototypeMap() が生成元 prototype の単位で分離する。
	 * Severityは byEvent と同じく、Host×Groupにつき現在のFilterを通ったEventのうち最も高いものを1回だけ割り当てる
	 * （Stack合計 = 影響Host数を常に保証する）。
	 *
	 * @param array<string, ?string> $event_proto  eventid => 生成元Trigger prototype ID（解決できなければ null）
	 * @param array<string, string>  $proto_names  Trigger prototype ID => 表示名
	 *
	 * @return array<int, array{id: string, label: string, is_prototype: bool, counts: int[], total: int,
	 *         event_count: int, hostids: string[]}>
	 */
	public static function byEventGroup(array $events, array $event_hosts, array $event_names, array $event_proto,
			array $proto_names, array $event_triggers = []): array {
		$best = [];
		$seen_events = [];
		$labels = [];
		$extra = [];

		foreach ($events as $e) {
			$eventid = $e['eventid'];
			$key = self::eventGroupKey($eventid, $event_proto, $event_names, $proto_names);
			$labels[$key] ??= self::eventGroupLabel($key, $proto_names);
			$seen_events[$key][$eventid] = true;
			self::collectExtra($extra[$key], $e, $event_triggers);
			$severity = (int) $e['severity'];

			foreach (array_unique($event_hosts[$eventid] ?? []) as $hostid) {
				if (!isset($best[$key][$hostid]) || $best[$key][$hostid] < $severity) {
					$best[$key][$hostid] = $severity;
				}
			}
		}

		$rows = [];

		foreach ($best as $key => $hosts) {
			$row = ['id' => $key, 'label' => $labels[$key], 'is_prototype' => strncmp($key, 'p:', 2) === 0,
				'counts' => self::emptyCounts(), 'total' => 0, 'event_count' => count($seen_events[$key] ?? []),
				'hostids' => array_keys($hosts)] + $extra[$key];

			foreach ($hosts as $severity) {
				$row['counts'][$severity]++;
				$row['total']++;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * プロトタイプグループ（Phase 6 §6）: Event ごとの Group 化に使う Trigger prototype ID を決める
	 * （WidgetView::resolveEventPrototypes() の判定部分。API を呼ばない純粋ロジック）。
	 *
	 *   - 生成元 prototype の Canonical を解決できた: Canonical prototype の単位（Host をまたいで1つの Group）
	 *   - 解決できない（継承元を参照できない・循環・生成元 prototype 自体を参照できない）: 生成元 prototype の単位で
	 *     分離し partial = true。Event 名による統合（'n:'）へは落とさない。生成元 prototype の表示名を取得できない
	 *     場合は、その Group に属する Event のうち eventid が最小の Event 名を表示名にする
	 *   - 通常 Trigger（生成元 prototype なし）: null（Event 名単位。従来どおり）
	 *
	 * @param array<string, string> $triggers     eventid => triggerid
	 * @param array<string, string> $parent       Discovered triggerid => 生成元 Trigger prototype ID
	 * @param array<string, array>  $resolved     生成元 prototype ID => TriggerPrototypeResolver::resolvePrototypes() の要素
	 * @param array<string, string> $proto_names  Trigger prototype ID => 表示名（参照できたものだけ）
	 * @param array<string, string> $event_names  eventid => Event名
	 *
	 * @return array{map: array<string, ?string>, names: array<string, string>, partial: bool}
	 */
	public static function prototypeMap(array $triggers, array $parent, array $resolved, array $proto_names,
			array $event_names): array {
		$group = [];
		$partial_protos = [];

		foreach ($resolved as $id => $entry) {
			$id = (string) $id;

			if ($entry['status'] === TriggerPrototypeResolver::STATUS_RESOLVED
					&& isset($proto_names[(string) $entry['canonical_prototypeid']])) {
				$group[$id] = (string) $entry['canonical_prototypeid'];
			}
			else {
				$group[$id] = $id;
				$partial_protos[$id] = true;
			}
		}

		$map = [];
		$partial = false;
		$fallback_names = [];
		$eventids = array_map('strval', array_keys($triggers));
		usort($eventids, [Ids::class, 'compare']);

		foreach ($eventids as $eventid) {
			$proto = $parent[(string) $triggers[$eventid]] ?? null;

			if ($proto === null) {
				$map[$eventid] = null;
				continue;
			}

			// resolvePrototypes() へ渡していない生成元 prototype は、未解決として同じく分離する。
			$key = $group[$proto] ?? $proto;
			$map[$eventid] = $key;

			if (!isset($group[$proto]) || isset($partial_protos[$proto])) {
				$partial = true;
			}

			if (!isset($proto_names[$key]) && !isset($fallback_names[$key])) {
				$fallback_names[$key] = (string) ($event_names[$eventid] ?? '');
			}
		}

		return ['map' => $map, 'names' => $proto_names + $fallback_names, 'partial' => $partial];
	}

	/**
	 * Popup（Hover Tooltip）用のHost名一覧。Host ID集合をA→Z（自然順・大文字小文字を区別しない）で並べ、
	 * 上限（$limit）を超えた分は件数だけ返す（§21〜24）。Event mode（個別イベント／プロトタイプグループ共通）
	 * のカテゴリ行に対して、Rendering直前に呼ぶ。
	 *
	 * @param string[]              $hostids
	 * @param array<string, string> $host_names  hostid => 表示名
	 *
	 * @return array{hosts: string[], hosts_more: int}
	 */
	public static function hostNameList(array $hostids, array $host_names, int $limit): array {
		$names = [];

		foreach ($hostids as $hostid) {
			$names[] = (string) ($host_names[$hostid] ?? $hostid);
		}

		sort($names, SORT_NATURAL | SORT_FLAG_CASE);

		return ['hosts' => array_slice($names, 0, $limit), 'hosts_more' => max(0, count($names) - $limit)];
	}

	/**
	 * Event 行の Tooltip 用の付帯情報（UI Polish §19）: 最新発生時刻（last_clock）と、$event_triggers
	 * （eventid => triggerid）が渡されたときは行に含まれる Trigger（triggerids）。集計値・棒高には使わない。
	 */
	private static function collectExtra(?array &$extra, array $e, array $event_triggers): void {
		$extra ??= ['last_clock' => 0];
		$extra['last_clock'] = max($extra['last_clock'], (int) ($e['clock'] ?? 0));

		if ($event_triggers) {
			$extra['triggerids'] ??= [];

			if (isset($event_triggers[$e['eventid']])) {
				$extra['triggerids'][$event_triggers[$e['eventid']]] = true;
			}
		}
	}

	/**
	 * Host group 行の Tooltip 用の母数（UI Polish §18 / Q3）。どちらも同じ母集団（現在の対象 Host のうち閲覧可能なもの）で、
	 * Host が直接所属する Group だけを数える（サブグループの合算はしない）。
	 *   target_hosts   : Group に直接所属する対象 Host の数（$host_groups に現れる Host = 対象 Host）
	 *   affected_hosts : そのうち集計対象の Event がある Host の数
	 * 集計（byHostGroup）とは独立した参考値で、件数・棒高・並び順には使わない。
	 *
	 * @param array<string, array<string, string>> $host_groups  対象 Host の hostid => [groupid => group名]
	 *
	 * @return array<string, array{target_hosts: int, affected_hosts: int}>  groupid => 母数
	 */
	public static function groupHostCounts(array $events, array $event_hosts, array $host_groups): array {
		$affected = [];

		foreach ($events as $e) {
			foreach ($event_hosts[$e['eventid']] ?? [] as $hostid) {
				$affected[(string) $hostid] = true;
			}
		}

		$counts = [];

		foreach ($host_groups as $hostid => $groups) {
			foreach ($groups as $groupid => $name) {
				$counts[(string) $groupid] ??= ['target_hosts' => 0, 'affected_hosts' => 0];
				$counts[(string) $groupid]['target_hosts']++;

				if (isset($affected[(string) $hostid])) {
					$counts[(string) $groupid]['affected_hosts']++;
				}
			}
		}

		return $counts;
	}

	/**
	 * Host 行の Tooltip 用インターフェース一覧（UI Polish §16）。main インターフェースを先に、種別（Agent / SNMP /
	 * IPMI / JMX）の順で並べ、$limit 件まで。接続先は useip に従い IP または DNS（推測した IP は出さない）。
	 * 接続先が空のインターフェースは出さない。
	 *
	 * @param array[] $interfaces  host.get の interfaces（type, main, useip, ip, dns, port）
	 * @param array<int, string> $type_names  type => 表示名
	 *
	 * @return array{list: array<int, array{type: string, address: string, port: string, main: bool}>, more: int}
	 */
	public static function interfaceList(array $interfaces, array $type_names, int $limit): array {
		$list = [];

		foreach ($interfaces as $i) {
			$ip = (string) ($i['ip'] ?? '');
			$dns = (string) ($i['dns'] ?? '');
			// 接続に使う側を優先し、空ならもう一方（IP が無ければ DNS）。
			$address = (int) ($i['useip'] ?? 1) === 1 ? ($ip !== '' ? $ip : $dns) : ($dns !== '' ? $dns : $ip);

			if ($address === '') {
				continue;
			}

			$list[] = [
				'type' => $type_names[(int) $i['type']] ?? (string) $i['type'],
				'address' => $address,
				'port' => (string) ($i['port'] ?? ''),
				'main' => (int) ($i['main'] ?? 0) === 1,
				'order' => (int) $i['type']
			];
		}

		usort($list, static fn(array $a, array $b): int => $b['main'] <=> $a['main'] ?: $a['order'] <=> $b['order']
			?: strnatcasecmp($a['address'], $b['address']));

		$list = array_map(static fn(array $i): array => array_diff_key($i, ['order' => 1]), $list);

		return ['list' => array_slice($list, 0, $limit), 'more' => max(0, count($list) - $limit)];
	}

	/**
	 * 並び替え。件数順=件数の降順（同数はA→Z）、A→Z / Z→A は大文字小文字を区別しない自然順。
	 * イベント数順=該当Event数（event_count。無い行は total）の降順、同数は件数の降順、さらに同数はA→Z。
	 */
	public static function sort(array $rows, int $mode): array {
		$natural = static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label'])
			?: strcmp($a['label'], $b['label']);

		switch ($mode) {
			case self::SORT_ASC:
				usort($rows, $natural);
				break;

			case self::SORT_DESC:
				usort($rows, static fn(array $a, array $b): int => $natural($b, $a));
				break;

			case self::SORT_EVENTS:
				usort($rows, static fn(array $a, array $b): int =>
					($b['event_count'] ?? $b['total']) <=> ($a['event_count'] ?? $a['total'])
						?: $b['total'] <=> $a['total'] ?: $natural($a, $b)
				);
				break;

			default:
				usort($rows, static fn(array $a, array $b): int => $b['total'] <=> $a['total'] ?: $natural($a, $b));
		}

		return $rows;
	}

	/**
	 * ページング。範囲外のページは有効な範囲（1〜最終ページ）へ丸める。Top N ではなく全件を分割する。
	 *
	 * @return array{rows: array, page: int, pages: int, total: int, from: int, to: int}
	 */
	public static function paginate(array $rows, int $page_size, int $page): array {
		$total = count($rows);
		$page_size = max(1, $page_size);
		$pages = max(1, (int) ceil($total / $page_size));
		$page = max(1, min($pages, $page));
		$offset = ($page - 1) * $page_size;
		$slice = array_slice($rows, $offset, $page_size);

		return [
			'rows' => $slice,
			'page' => $page,
			'pages' => $pages,
			'total' => $total,
			'from' => $slice ? $offset + 1 : 0,
			'to' => $offset + count($slice)
		];
	}
}
