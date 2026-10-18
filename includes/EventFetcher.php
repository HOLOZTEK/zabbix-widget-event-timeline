<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;

/**
 * Event retrieval: 対象Host集合・Event Selection・表示期間から Trigger 由来の Problem Event を取得する。
 *
 * - 内部Event: ['eventid', 'clock'(発生), 'end'(復旧時刻 / 未復旧は null), 'severity', 'acknowledged']。
 *   集計（Aggregator）とは分離しており、ここでは Filter・集計を行わない。
 * - Problem Event のみ数える（Recovery Event 自体は数えない）。1 eventid = 1 Event。
 * - 新しいEventから eventid 降順でページング取得し、上限に達したら打ち切る。打ち切りは呼び出し側へ
 *   truncated として明示する（無言で欠落させない）。
 * - 継続モードでは、復旧時刻の取得と、表示期間より前に発生して期間内まで継続したEventの取得を行う。
 */
class EventFetcher {

	private const PAGE_SIZE = 5000;
	private const RECOVERY_CHUNK = 500;

	/**
	 * 取得系 API の呼び出し回数（method => 回数）。1リクエスト内の統計で、デバッグモード時の応答と性能試験の記録に使う。
	 * Event 取得（event.get）・Trigger 展開（trigger.get）・Item characteristics filter（trigger.get / item.get）の経路だけを数える。
	 */
	public static array $api_calls = [];

	private static function countCall(string $method): void {
		self::$api_calls[$method] = (self::$api_calls[$method] ?? 0) + 1;
	}

	/**
	 * @param string[] $hostids    resolved host IDs（Source resolution の結果）
	 * @param int     $time_from  期間開始（UNIX時刻、含む）
	 * @param int     $time_till  期間終了（UNIX時刻、含む）
	 * @param int[]   $severities API側でも絞り込むSeverity（全Severityなら絞り込まない）
	 * @param int     $max_events 取得件数の上限（発生期間内・期間前それぞれの走査に適用）
	 * @param array[] $selectors  Event Selection。event.get へ追加するオプション集合のリスト（結果はOR統合）。
	 *                            空配列 [[]] は絞り込みなし。
	 * @param bool    $continuous 継続モード（復旧時刻と、期間前から継続したEventも取得する）
	 * @param int[]   $ack_states API側でも絞り込む確認状態（両方なら絞り込まない）
	 *
	 * @return array{events: array, truncated: bool, covered_from: ?int}|null  API失敗時は null
	 *   covered_from: 打ち切り時、走査できた最古Eventの clock（それより前は未集計）
	 */
	public static function fetch(array $hostids, int $time_from, int $time_till, array $severities,
			int $max_events, array $selectors = [[]], bool $continuous = false, array $ack_states = [0, 1]): ?array {
		$base = [
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'hostids' => $hostids
		];

		if (count(array_unique($severities)) < count(Aggregator::SEVERITIES)) {
			$base['severities'] = array_values(array_unique(array_map('intval', $severities)));
		}

		$ack_states = array_values(array_unique(array_map('intval', $ack_states)));

		if (count($ack_states) === 1) {
			$base['acknowledged'] = $ack_states[0] === 1;
		}

		$by_id = [];
		$truncated = false;
		$covered_from = null;

		foreach ($selectors as $selector) {
			$result = self::scan($base + $selector + [], ['time_from' => $time_from, 'time_till' => $time_till],
				$max_events, $continuous, null
			);

			if ($result === null) {
				return null;
			}

			foreach ($result['events'] as $e) {
				$by_id[$e['eventid']] = $e;
			}

			if ($result['truncated']) {
				$truncated = true;
				$covered_from = self::minClock($covered_from, $result['covered_from']);
			}

			if ($continuous) {
				// 表示期間より前に発生し、期間開始時点で継続していたEvent。
				$pre = self::scan($base + $selector, ['time_till' => $time_from - 1], $max_events, true, $time_from);

				if ($pre === null) {
					return null;
				}

				foreach ($pre['events'] as $e) {
					$by_id[$e['eventid']] = $e;
				}

				if ($pre['truncated']) {
					$truncated = true;
					$covered_from = self::minClock($covered_from, $pre['covered_from']);
				}
			}
		}

		$events = array_values($by_id);

		return ['events' => $events, 'truncated' => $truncated, 'covered_from' => $truncated ? $covered_from : null];
	}

	private static function minClock(?int $a, ?int $b): ?int {
		return $a === null ? $b : ($b === null ? $a : min($a, $b));
	}

	/**
	 * 1つの条件でEventを新しい順に取得する。
	 *
	 * @param array    $options      event.get の条件（期間以外）
	 * @param array    $range        time_from / time_till
	 * @param bool     $with_end     復旧時刻も取得する
	 * @param int|null $active_from  指定時は「この時刻以降まで継続していた（未復旧または復旧がこの時刻以降）」Eventだけを残す。
	 *                               走査件数は $max_events で打ち切る。
	 *
	 * @return array{events: array, truncated: bool, covered_from: ?int}|null
	 */
	private static function scan(array $options, array $range, int $max_events, bool $with_end,
			?int $active_from): ?array {
		$options = $options + $range + [
			'output' => ['eventid', 'clock', 'severity', 'acknowledged', 'r_eventid'],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		];

		$events = [];
		$scanned = 0;
		$truncated = false;
		$till_eventid = null;
		$oldest = null;

		while (true) {
			// 上限を1件超えて要求し、上限を超える Event が存在するかを判定する。
			$want = min(self::PAGE_SIZE, $max_events - $scanned + 1);

			$page_options = $options + ['limit' => $want];

			if ($till_eventid !== null) {
				$page_options['eventid_till'] = $till_eventid;
			}

			self::countCall('event.get');
			$page = API::Event()->get($page_options);

			if (!is_array($page)) {
				return null;
			}

			$batch = [];

			foreach ($page as $e) {
				if ($scanned >= $max_events) {
					$truncated = true;

					break;
				}

				$scanned++;
				$oldest = (int) $e['clock'];

				$batch[] = [
					'eventid' => (string) $e['eventid'],
					'clock' => (int) $e['clock'],
					'end' => null,
					'severity' => (int) $e['severity'],
					'acknowledged' => (int) $e['acknowledged'],
					'r_eventid' => (string) ($e['r_eventid'] ?? '0')
				];
			}

			if ($with_end && $batch) {
				$recovered = self::recoveryClocks(array_column($batch, 'r_eventid'));

				if ($recovered === null) {
					return null;
				}

				foreach ($batch as &$e) {
					$e['end'] = $recovered[$e['r_eventid']] ?? null;
				}

				unset($e);
			}

			foreach ($batch as $e) {
				// 未復旧（r_eventid = 0）または復旧が期間開始以降のEventだけが、期間前から継続している。
				if ($active_from !== null && $e['end'] !== null && $e['end'] < $active_from) {
					continue;
				}

				unset($e['r_eventid']);
				$events[] = $e;
			}

			if ($truncated || count($page) < $want) {
				break;
			}

			// 次ページ: 直前ページの最小 eventid より小さいものだけを取得する。
			$last = end($page);
			// eventid は PHP の int に収まらないことがあるため、文字列のまま 1 を引く（issue #34）。
			$till_eventid = Ids::decrement(Ids::ref($last['eventid']));
		}

		return [
			'events' => $events,
			'truncated' => $truncated,
			'covered_from' => $truncated ? $oldest : null
		];
	}

	/**
	 * 復旧Eventの発生時刻を取得する。
	 *
	 * @param string[] $r_eventids  Problem Event の r_eventid（"0" は未復旧）
	 *
	 * @return array<string, int>|null  r_eventid => 復旧時刻。失敗時は null
	 */
	private static function recoveryClocks(array $r_eventids): ?array {
		$ids = array_values(array_unique(array_filter($r_eventids, static fn(string $id): bool => $id !== '0')));
		$clocks = [];

		foreach (array_chunk($ids, self::RECOVERY_CHUNK) as $chunk) {
			self::countCall('event.get');
			$rows = API::Event()->get([
				'output' => ['eventid', 'clock'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$clocks[(string) $row['eventid']] = (int) $row['clock'];
			}
		}

		return $clocks;
	}

	/**
	 * Tooltip用の詳細（Event名・関連Host名）を eventid 指定でまとめて取得する。
	 * Timeline生成時に1度だけ呼び、Hover時の追加Requestは行わない。集計値には影響しない。
	 * 1つのEventが複数Hostに関連する場合は、関連Hostをすべて返す。
	 *
	 * @param string[] $eventids
	 *
	 * @return array<string, array{name: string, hosts: string[]}>|null  失敗時は null
	 */
	public static function fetchDetails(array $eventids): ?array {
		$details = [];

		foreach (array_chunk(array_values(array_unique($eventids)), 500) as $chunk) {
			$rows = API::Event()->get([
				'output' => ['eventid', 'name'],
				'selectHosts' => ['hostid', 'name'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$details[(string) $row['eventid']] = [
					'name' => (string) $row['name'],
					'hosts' => array_values(array_map(
						static fn(array $h): string => (string) $h['name'],
						$row['hosts'] ?? []
					))
				];
			}
		}

		return $details;
	}

	/**
	 * Drilldown用の行情報。指定eventidだけを対象に、Event名・関連Host・Trigger ID・復旧時刻を取得する。
	 * $target_hostids が null のときは関連Hostをすべて返し、指定時は対象Host集合との交差だけを返す。
	 *
	 * @param string[]      $eventids
	 * @param string[]|null $target_hostids
	 *
	 * @return array<string, array{name: string, triggerid: string, hosts: array<int, array{id: string, name: string}>,
	 *         end: ?int}>|null  失敗時は null
	 */
	public static function fetchRows(array $eventids, ?array $target_hostids): ?array {
		$target = $target_hostids === null ? null : array_flip(array_map('strval', $target_hostids));
		$rows = [];
		$r_eventids = [];

		foreach (array_chunk(array_values(array_unique($eventids)), 500) as $chunk) {
			$result = API::Event()->get([
				'output' => ['eventid', 'name', 'objectid', 'r_eventid'],
				'selectHosts' => ['hostid', 'name'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($result)) {
				return null;
			}

			foreach ($result as $row) {
				$hosts = [];

				foreach ($row['hosts'] ?? [] as $h) {
					if ($target === null || isset($target[(string) $h['hostid']])) {
						$hosts[] = ['id' => (string) $h['hostid'], 'name' => (string) $h['name']];
					}
				}

				$rows[(string) $row['eventid']] = [
					'name' => (string) $row['name'],
					'triggerid' => (string) ($row['objectid'] ?? '0'),
					'hosts' => $hosts,
					'end' => null
				];
				$r_eventids[(string) $row['eventid']] = (string) ($row['r_eventid'] ?? '0');
			}
		}

		$recovered = self::recoveryClocks(array_values($r_eventids));

		if ($recovered === null) {
			return null;
		}

		foreach ($r_eventids as $eventid => $r_eventid) {
			$rows[$eventid]['end'] = $recovered[$r_eventid] ?? null;
		}

		return $rows;
	}

	/**
	 * 次元集計（By Host / By Host group / By Event）用に、Event名と関連Host（ID・名前）を取得する。
	 * 関連Hostは対象Host集合（$target_hostids）との交差だけを返す。
	 * triggers（eventid => triggerid）は、プロトタイプグループ表示（fetchTriggerParents()）の入力にする
	 * ためのもので、追加のAPI呼び出しは発生しない（event.get の output に objectid を足すだけ）。
	 *
	 * @param string[] $eventids
	 * @param string[] $target_hostids
	 *
	 * @return array{names: array<string, string>, hosts: array<string, string[]>, host_names: array<string, string>,
	 *         triggers: array<string, string>}|null
	 */
	public static function fetchDimensionInfo(array $eventids, array $target_hostids): ?array {
		$target = array_flip(array_map('strval', $target_hostids));
		$names = [];
		$hosts = [];
		$host_names = [];
		$triggers = [];

		foreach (array_chunk(array_values(array_unique($eventids)), 500) as $chunk) {
			$rows = API::Event()->get([
				'output' => ['eventid', 'name', 'objectid'],
				'selectHosts' => ['hostid', 'name'],
				'eventids' => $chunk,
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$eventid = (string) $row['eventid'];
				$names[$eventid] = (string) $row['name'];
				$triggers[$eventid] = (string) ($row['objectid'] ?? '0');
				$hosts[$eventid] = [];

				foreach ($row['hosts'] ?? [] as $h) {
					if (isset($target[(string) $h['hostid']])) {
						$hosts[$eventid][] = (string) $h['hostid'];
						$host_names[(string) $h['hostid']] = (string) $h['name'];
					}
				}
			}
		}

		return ['names' => $names, 'hosts' => $hosts, 'host_names' => $host_names, 'triggers' => $triggers];
	}

	/**
	 * プロトタイプグループ表示用: 指定Trigger群のうちLLD（Discovery）由来のものについて、生成元の Trigger prototype ID
	 * （対象Host上の prototype。triggerDiscovery.parent_triggerid）を返す。通常Trigger（Prototype由来でない）は現れない。
	 * prototype の表示名と Canonical prototype への正規化は呼び出し側（TriggerPrototypeResolver ＋ prototype 用キャッシュ）で行う。
	 *
	 * Event件数分のAPI呼び出しにはならないよう、triggerid をまとめて trigger.get する（呼び出し側で同一Renderingにつき1回）。
	 * API失敗時は null（Phase 6 §6: 生成元 prototype が分からないまま Event 名で Group 化しない。呼び出し側はエラーにする）。
	 *
	 * @param string[] $triggerids
	 *
	 * @return array<string, string>|null  Discovery由来のtriggerid => 生成元Trigger prototype ID。API失敗時は null
	 */
	public static function fetchTriggerParents(array $triggerids): ?array {
		$ids = array_values(array_unique(array_filter(array_map('strval', $triggerids),
			static fn(string $id): bool => $id !== '0'
		)));
		$parent = [];

		foreach (array_chunk($ids, 500) as $chunk) {
			self::countCall('trigger.get');
			$rows = API::Trigger()->get([
				'output' => ['triggerid'],
				'selectTriggerDiscovery' => ['parent_triggerid'],
				'triggerids' => $chunk
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$discovery = $row['triggerDiscovery'] ?? null;

				if (is_array($discovery) && isset($discovery['parent_triggerid'])) {
					$parent[(string) $row['triggerid']] = (string) $discovery['parent_triggerid'];
				}
			}
		}

		return $parent;
	}

	/**
	 * TriggerPrototypeResolver 用: Trigger prototype の継承情報（templateid / flags）と表示名（description。{#MACRO} は
	 * 未解決のまま）。Host 上の prototype も Template 上の prototype も対象にする。閲覧権限のない prototype は返らない。
	 *
	 * @param string[] $prototypeids
	 *
	 * @return array<string, array{templateid: string, flags: int, description: string}>|null  API失敗時は null
	 */
	public static function fetchPrototypeInheritance(array $prototypeids): ?array {
		$result = [];

		foreach (array_chunk(array_values(array_map('strval', $prototypeids)), 500) as $chunk) {
			self::countCall('triggerprototype.get');
			$rows = API::TriggerPrototype()->get([
				'output' => ['triggerid', 'templateid', 'flags', 'description'],
				'triggerids' => $chunk
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$result[(string) $row['triggerid']] = [
					'templateid' => Ids::ref($row['templateid']),
					'flags' => (int) $row['flags'],
					'description' => (string) $row['description']
				];
			}
		}

		return $result;
	}

	/**
	 * TriggerSelectionResolver 用: Triggerの継承情報（templateid / flags）。Host上のTriggerもテンプレート上の
	 * Triggerも対象にする（trigger.get は templated 未指定で両方を返す）。閲覧権限のないTriggerは返らない。
	 *
	 * @param string[] $triggerids
	 *
	 * Phase 4: LLD（Discovery）由来の Trigger は生成元 Trigger prototype（triggerDiscovery.parent_triggerid）も同じ呼び出しで
	 * 取得し、prototypeid として返す（Source の Discovered Trigger → prototype の解決に追加の呼び出しをしない）。
	 *
	 * @return array<string, array{templateid: string, flags: int, prototypeid: string}>|null  API失敗時は null
	 */
	public static function fetchTriggerInheritance(array $triggerids): ?array {
		$result = [];

		foreach (array_chunk(array_values(array_map('strval', $triggerids)), 500) as $chunk) {
			self::countCall('trigger.get');
			$rows = API::Trigger()->get([
				'output' => ['triggerid', 'templateid', 'flags'],
				'selectTriggerDiscovery' => ['parent_triggerid'],
				'triggerids' => $chunk
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$result[(string) $row['triggerid']] = self::inheritanceRow($row);
			}
		}

		return $result;
	}

	/**
	 * trigger.get の1行を継承情報の行へ変換する（prototypeid は triggerDiscovery を取得したときだけ。無ければ '0'）。
	 * ID は10進文字列のまま保つ（issue #34）。flags は列挙値なので int。
	 */
	private static function inheritanceRow(array $row): array {
		$result = [
			'templateid' => Ids::ref($row['templateid']),
			'flags' => (int) $row['flags']
		];

		if (array_key_exists('triggerDiscovery', $row)) {
			$discovery = $row['triggerDiscovery'];
			$result['prototypeid'] = is_array($discovery) && isset($discovery['parent_triggerid'])
				? Ids::ref($discovery['parent_triggerid'])
				: '0';
		}

		return $result;
	}

	/**
	 * TriggerScopeExpander 用: 対象Host群の上にある Trigger の継承情報（templateid / flags）を、Host をまとめて取得する
	 * （Host 1台ごとの呼び出しはしない）。複数Hostの Item を参照する Trigger は、参照先のいずれかが対象Hostなら返る。
	 * 閲覧権限のない Trigger は返らない。Trigger prototype は trigger.get の対象外。
	 *
	 * Phase 4: $with_prototype のとき（LLD の Canonical prototype が選択されているとき）は、Discovered Trigger の生成元
	 * prototype（triggerDiscovery.parent_triggerid）も同じ呼び出しで取得し prototypeid として返す。通常 Trigger の展開と
	 * LLD の展開はこの1回の取得結果を共有する。
	 *
	 * Phase 5: $with_functions のとき（Item characteristics filter が有効なとき）は、Trigger が直接参照する Item
	 * （functions の itemid）も同じ呼び出しで取得し itemids として返す。
	 *
	 * @param string[] $hostids
	 * @param bool  $with_prototype
	 * @param bool  $with_functions
	 *
	 * @return array<string, array{templateid: string, flags: int, prototypeid?: string, itemids?: string[]}>|null
	 *         API失敗時は null
	 */
	public static function fetchHostTriggers(array $hostids, bool $with_prototype = false,
			bool $with_functions = false): ?array {
		$result = [];

		foreach (array_chunk(array_values(array_unique(array_map('strval', $hostids))), 500) as $chunk) {
			self::countCall('trigger.get');
			$options = [
				'output' => ['triggerid', 'templateid', 'flags'],
				'hostids' => $chunk
			];

			if ($with_prototype) {
				$options['selectTriggerDiscovery'] = ['parent_triggerid'];
			}

			if ($with_functions) {
				$options['selectFunctions'] = ['itemid'];
			}

			$rows = API::Trigger()->get($options);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$result[(string) $row['triggerid']] = self::inheritanceRow($row);

				if ($with_functions) {
					$result[(string) $row['triggerid']]['itemids'] = self::functionItemids($row);
				}
			}
		}

		return $result;
	}

	/**
	 * Item characteristics filter（Phase 5）用: Trigger が直接参照する Item（trigger.get の functions = Zabbix が保持する
	 * Trigger → Item の構造情報。式の文字列解析はしない）。Trigger をまとめて取得する（Trigger 1件ごとの呼び出しはしない）。
	 * 閲覧権限のない Trigger は返らない。
	 *
	 * @param string[] $triggerids
	 *
	 * @return array<string, string[]>|null  triggerid => itemid[]。API失敗時は null
	 */
	public static function fetchTriggerItems(array $triggerids): ?array {
		$result = [];

		foreach (array_chunk(array_values(array_map('strval', $triggerids)), 500) as $chunk) {
			self::countCall('trigger.get');
			$rows = API::Trigger()->get([
				'output' => ['triggerid'],
				'selectFunctions' => ['itemid'],
				'triggerids' => $chunk
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$result[(string) $row['triggerid']] = self::functionItemids($row);
			}
		}

		return $result;
	}

	private static function functionItemids(array $row): array {
		$ids = [];

		foreach ($row['functions'] ?? [] as $function) {
			if (isset($function['itemid'])) {
				$ids[] = $function['itemid'];
			}
		}

		return Ids::unique($ids);
	}

	/**
	 * Item characteristics filter（Phase 5）用: Item の metadata。ログインユーザーの通常の権限で取得する（権限を迂回しない）。
	 * Item の status（Disabled 等）では絞り込まない。Web monitoring の Item も対象にする。
	 * Item をまとめて取得する（Item 1件ごとの呼び出しはしない）。閲覧できない・存在しない Item は返らない。
	 *
	 * @param string[] $itemids
	 *
	 * @return array<string, array{type: int, value_type: int, name: string, key: string, hostid: string,
	 *         host: string}>|null  API失敗時は null
	 */
	public static function fetchItemMetadata(array $itemids): ?array {
		$result = [];

		foreach (array_chunk(array_values(array_map('strval', $itemids)), 1000) as $chunk) {
			self::countCall('item.get');
			$rows = API::Item()->get([
				'output' => ['itemid', 'type', 'value_type', 'name', 'name_resolved', 'key_', 'hostid'],
				'selectHosts' => ['hostid', 'name'],
				'itemids' => $chunk,
				'webitems' => true
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				$host = $row['hosts'][0] ?? null;
				$result[(string) $row['itemid']] = [
					'type' => (int) $row['type'],
					'value_type' => (int) $row['value_type'],
					'name' => (string) (($row['name_resolved'] ?? '') !== '' ? $row['name_resolved'] : $row['name']),
					'key' => (string) $row['key_'],
					'hostid' => (string) $row['hostid'],
					'host' => is_array($host) ? (string) $host['name'] : ''
				];
			}
		}

		return $result;
	}

	/**
	 * Hostごとの所属Host group。
	 *
	 * @param string[] $hostids
	 *
	 * @return array<string, array<string, string>>|null  hostid => [groupid => group名]。失敗時は null
	 */
	public static function fetchHostGroups(array $hostids): ?array {
		$map = [];

		foreach (array_chunk(array_values(array_unique($hostids)), 500) as $chunk) {
			$rows = API::Host()->get([
				'output' => ['hostid'],
				'selectHostGroups' => ['groupid', 'name'],
				'hostids' => $chunk
			]);

			if (!is_array($rows)) {
				return null;
			}

			foreach ($rows as $row) {
				foreach ($row['hostgroups'] ?? [] as $g) {
					$map[(string) $row['hostid']][(string) $g['groupid']] = (string) $g['name'];
				}
			}
		}

		return $map;
	}
}
