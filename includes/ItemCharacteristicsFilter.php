<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Item characteristics filter（v0.2.0 Phase 5）: Trigger が直接参照する Item の値の型（value_type）と
 * アイテムタイプ（type）で、候補 Trigger を Event 取得前に絞り込む。
 *
 * 判定規則:
 *   - 同じ軸の複数選択は OR、2軸間は AND。空の軸は「すべて」（条件なし）。
 *   - 一致判定は Item 単位: (item.value_type ∈ 値の型) AND (item.type ∈ アイテムタイプ)。別々の Item がそれぞれ
 *     片方の条件だけを満たしても Trigger は一致しない（Phase 5 §7）。
 *   - Trigger は、直接参照する Item のうち1件以上が一致すれば対象（複数 Item が一致しても Trigger は1件）。
 *   - Trigger → Item の関連は Zabbix の構造情報（trigger.get の functions）だけを使い、式・名前・key_ からは推測しない。
 *   - Dependent item は自身の type（Dependent item）で判定し、master item へ遡らない（§16）。
 *   - Item の status（Disabled 等）は条件にしない（§18）。参照先 Host が Host Scope 外でも判定材料にする（§17）。
 *
 * 判定不能（indeterminate、§19）: 一致する Item を確認できず、かつ参照 Item の一部（または Trigger → Item の関連）を
 * 取得できない Trigger。一致扱いにも不一致扱いにもせず件数を返し、呼び出し側が PARTIAL 警告を表示する。
 * 一致する Item を1件でも確認できた Trigger は、他の Item が不明でも一致が確定しているため判定不能に数えない。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。
 */
class ItemCharacteristicsFilter {

	/**
	 * 値の型の表示順（UI Polish §3）。Zabbix の定数名で指定し、現在の Zabbix に定義がない定数は飛ばす
	 * （JSON は 8.0 以降のみ）。ここにない値の型は末尾に Zabbix の定義順で続ける。
	 */
	public const VALUE_TYPE_ORDER = [
		'ITEM_VALUE_TYPE_FLOAT', 'ITEM_VALUE_TYPE_UINT64', 'ITEM_VALUE_TYPE_STR', 'ITEM_VALUE_TYPE_TEXT',
		'ITEM_VALUE_TYPE_LOG', 'ITEM_VALUE_TYPE_BINARY', 'ITEM_VALUE_TYPE_JSON'
	];

	/**
	 * アイテムタイプの表示順（UI Polish §4）。見出し・区切りは付けない。扱いは VALUE_TYPE_ORDER と同じ
	 * （Nested / Telemetry query は 8.0 以降のみ）。
	 */
	public const ITEM_TYPE_ORDER = [
		'ITEM_TYPE_ZABBIX', 'ITEM_TYPE_ZABBIX_ACTIVE', 'ITEM_TYPE_SNMP', 'ITEM_TYPE_SNMPTRAP', 'ITEM_TYPE_TRAPPER',
		'ITEM_TYPE_INTERNAL', 'ITEM_TYPE_SIMPLE', 'ITEM_TYPE_EXTERNAL', 'ITEM_TYPE_DB_MONITOR', 'ITEM_TYPE_HTTPAGENT',
		'ITEM_TYPE_IPMI', 'ITEM_TYPE_SSH', 'ITEM_TYPE_TELNET', 'ITEM_TYPE_JMX', 'ITEM_TYPE_HTTPTEST',
		'ITEM_TYPE_CALCULATED', 'ITEM_TYPE_DEPENDENT', 'ITEM_TYPE_SCRIPT', 'ITEM_TYPE_BROWSER', 'ITEM_TYPE_NESTED',
		'ITEM_TYPE_TELEMETRY_QUERY'
	];

	/**
	 * 選択値を、現在の Zabbix が定義する値（$defined）に含まれるものだけに正規化する（昇順・重複なし）。
	 * 定義済みの値をすべて選択した場合は条件なしと同じため、空配列（すべて）にする。
	 *
	 * @param mixed $values   選択値（フォーム・Runtime から届いた値）
	 * @param int[] $defined  現在の Zabbix が定義する値
	 *
	 * @return int[]
	 */
	public static function normalize($values, array $defined): array {
		$defined = array_flip(array_map('intval', $defined));
		$result = [];

		foreach (is_array($values) ? $values : [] as $value) {
			if (is_numeric($value) && array_key_exists((int) $value, $defined)) {
				$result[(int) $value] = true;
			}
		}

		if (count($result) === count($defined)) {
			return [];
		}

		$result = array_keys($result);
		sort($result, SORT_NUMERIC);

		return $result;
	}

	public static function isActive(array $value_types, array $item_types): bool {
		return $value_types !== [] || $item_types !== [];
	}

	/**
	 * Item 1件が条件を満たすか（同一 Item で両軸を評価する）。
	 */
	public static function itemMatches(array $item, array $value_types, array $item_types): bool {
		return ($value_types === [] || in_array((int) $item['value_type'], $value_types, true))
			&& ($item_types === [] || in_array((int) $item['type'], $item_types, true));
	}

	/**
	 * 候補から関連取得が必要な参照 Item ID を集める（昇順・重複なし）。
	 *
	 * @param array<string, string[]|null> $relations  triggerid => 直接参照する itemid（関連を取得できなければ null / 未設定）
	 *
	 * @return string[]
	 */
	public static function referencedItemids(array $candidates, array $relations): array {
		$ids = [];

		foreach (Ids::unique($candidates) as $triggerid) {
			foreach ($relations[$triggerid] ?? [] as $itemid) {
				$ids[] = $itemid;
			}
		}

		return Ids::unique($ids);
	}

	/**
	 * @param string[]                     $candidates   候補 Trigger ID（Trigger Selection の展開結果、または Host Scope 上の Trigger）
	 * @param array<string, string[]|null> $relations    triggerid => 直接参照する itemid
	 * @param array<string, array>         $items        itemid => ['type' => int, 'value_type' => int, ...]（取得できたものだけ）
	 * @param int[]                        $value_types  normalize() 済み（空 = すべて）
	 * @param int[]                        $item_types   normalize() 済み（空 = すべて）
	 *
	 * @return array
	 *   - triggerids:        一致した Trigger ID（昇順・重複なし）
	 *   - matched:           triggerid => 一致した itemid（昇順）
	 *   - indeterminate:     判定不能だった Trigger 数
	 *   - indeterminate_ids: 判定不能だった Trigger ID（デバッグ用。昇順）
	 *   - candidate_count:   候補 Trigger 数
	 *   - item_count:        参照 Item 数（重複なし）
	 *   - missing_items:     取得できなかった参照 Item 数
	 */
	public static function apply(array $candidates, array $relations, array $items, array $value_types,
			array $item_types): array {
		$candidates = Ids::unique($candidates);

		$matched = [];
		$indeterminate = [];
		$referenced = [];
		$missing = [];

		foreach ($candidates as $triggerid) {
			$itemids = $relations[$triggerid] ?? null;

			if (!is_array($itemids) || !$itemids) {
				// Trigger → Item の関連を取得できない（Trigger が参照する Item は必ず1件以上ある）。
				$indeterminate[$triggerid] = true;
				continue;
			}

			$hit = [];
			$unknown = false;

			foreach (Ids::unique($itemids) as $itemid) {
				$referenced[$itemid] = true;

				if (!array_key_exists($itemid, $items)) {
					$missing[$itemid] = true;
					$unknown = true;
				}
				elseif (self::itemMatches($items[$itemid], $value_types, $item_types)) {
					$hit[] = $itemid;
				}
			}

			if ($hit) {
				$matched[$triggerid] = $hit;
			}
			elseif ($unknown) {
				$indeterminate[$triggerid] = true;
			}
		}

		$ids = Ids::keys($matched);
		$indeterminate_ids = Ids::keys($indeterminate);

		return [
			'triggerids' => $ids,
			'matched' => $matched,
			'indeterminate' => count($indeterminate_ids),
			'indeterminate_ids' => $indeterminate_ids,
			'candidate_count' => count($candidates),
			'item_count' => count($referenced),
			'missing_items' => count($missing)
		];
	}

	/**
	 * 選択肢を定数名の一覧の順に並べ替える。未定義の定数・選択肢にない値は飛ばし、一覧にない選択肢は末尾に
	 * 元の順で残す（表示順だけを変え、選択肢の集合は変えない）。
	 *
	 * @param array<int, string> $options
	 * @param string[]           $order    定数名
	 *
	 * @return array<int, string>
	 */
	public static function orderOptions(array $options, array $order): array {
		$ordered = [];

		foreach ($order as $name) {
			if (defined($name) && array_key_exists(constant($name), $options)) {
				$ordered[constant($name)] = $options[constant($name)];
			}
		}

		return $ordered + $options;
	}
}
