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
	public static function byEvent(array $events, array $event_hosts, array $event_names): array {
		$best = [];
		$seen_events = [];

		foreach ($events as $e) {
			$name = (string) ($event_names[$e['eventid']] ?? '');
			$seen_events[$name][$e['eventid']] = true;

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
				'event_count' => count($seen_events[$name] ?? []), 'hostids' => array_keys($hosts)];

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
	 * 'p:'+Group化に使う Trigger prototype ID、そうでない（通常Trigger、またはPrototype情報が解決できない）場合は
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
	 * 該当Event数（event_count）を集計する。Prototype情報が無い/解決できないEventは、Event名単位の
	 * Group（byEventと同じ粒度）へ個別にFallbackする（§37〜39: 特別な警告表示はせず、通常のEventとして扱う）。
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
			array $proto_names): array {
		$best = [];
		$seen_events = [];
		$labels = [];

		foreach ($events as $e) {
			$eventid = $e['eventid'];
			$key = self::eventGroupKey($eventid, $event_proto, $event_names, $proto_names);
			$labels[$key] ??= self::eventGroupLabel($key, $proto_names);
			$seen_events[$key][$eventid] = true;
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
				'hostids' => array_keys($hosts)];

			foreach ($hosts as $severity) {
				$row['counts'][$severity]++;
				$row['total']++;
			}

			$rows[] = $row;
		}

		return $rows;
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
