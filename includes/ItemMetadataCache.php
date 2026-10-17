<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Item characteristics filter（v0.2.0 Phase 5）用のリクエスト内キャッシュ。
 *
 *   - triggerid → 直接参照する itemid[]（Trigger → Item の関連。trigger.get の functions）
 *   - itemid → Item metadata（type / value_type / name / key_ / Host）
 *
 * Phase 2〜4 の継承情報キャッシュ（TriggerInheritanceCache）とは責務もキーも別にする。同じ Widget Refresh の中で
 * 同じ Trigger / Item を再取得しない。取得を試みて返らなかった（存在しない・閲覧できない）IDも記録して再取得しない。
 *
 * 取得関数は注入する（Zabbix API 非依存で単体試験できるようにする）。いずれも閲覧可能なものだけを返し、
 * 取得に失敗した場合は null を返す。Item metadata の取得失敗はキャッシュ全体の失敗にせず、取得できなかった Item を
 * 「参照不能」として扱う（呼び出し側は判定不能 = PARTIAL にする。§19 / Q2）。
 */
class ItemMetadataCache {

	/** @var callable function(int[] $triggerids): ?array  triggerid => int[] itemids */
	private $relation_fetcher;

	/** @var callable function(int[] $itemids): ?array  itemid => metadata */
	private $item_fetcher;

	private array $relations = [];
	private array $relation_missing = [];
	private array $items = [];
	private array $item_missing = [];

	/** 注入された取得関数の呼び出し回数（統計・デバッグ用）。 */
	public int $relation_fetch_calls = 0;
	public int $item_fetch_calls = 0;

	/** Item metadata の取得に失敗した（API エラー）ことがあるか。 */
	public bool $item_fetch_failed = false;

	public function __construct(callable $relation_fetcher, callable $item_fetcher) {
		$this->relation_fetcher = $relation_fetcher;
		$this->item_fetcher = $item_fetcher;
	}

	/**
	 * 別経路（対象Host上の Trigger 一覧）で取得済みの Trigger → Item の関連を登録する。
	 *
	 * @param array<int, array{itemids?: int[]}> $rows  triggerid => 行（itemids を持つ行だけ登録する）
	 */
	public function seedRelations(array $rows): void {
		foreach ($rows as $triggerid => $row) {
			if (is_array($row) && array_key_exists('itemids', $row) && is_array($row['itemids'])) {
				$this->relations[(int) $triggerid] = array_values(array_map('intval', $row['itemids']));
				unset($this->relation_missing[(int) $triggerid]);
			}
		}
	}

	/**
	 * @param int[] $triggerids
	 *
	 * @return array<int, int[]>  関連を取得できた Trigger だけ（取得失敗分は含まない = 呼び出し側で判定不能）
	 */
	public function relations(array $triggerids): array {
		$need = $this->need($triggerids, $this->relations, $this->relation_missing);

		if ($need) {
			$this->relation_fetch_calls++;
			$rows = ($this->relation_fetcher)($need);

			if (is_array($rows)) {
				foreach ($rows as $triggerid => $itemids) {
					$this->relations[(int) $triggerid] = array_values(array_map('intval', (array) $itemids));
				}
			}

			foreach ($need as $id) {
				if (!array_key_exists($id, $this->relations)) {
					$this->relation_missing[$id] = true;
				}
			}
		}

		return $this->pick($triggerids, $this->relations);
	}

	/**
	 * @param int[] $itemids
	 *
	 * @return array<int, array>  取得できた Item だけ
	 */
	public function items(array $itemids): array {
		$need = $this->need($itemids, $this->items, $this->item_missing);

		if ($need) {
			$this->item_fetch_calls++;
			$rows = ($this->item_fetcher)($need);

			if (is_array($rows)) {
				foreach ($rows as $itemid => $row) {
					$this->items[(int) $itemid] = $row;
				}
			}
			else {
				$this->item_fetch_failed = true;
			}

			foreach ($need as $id) {
				if (!array_key_exists($id, $this->items)) {
					$this->item_missing[$id] = true;
				}
			}
		}

		return $this->pick($itemids, $this->items);
	}

	private function need(array $ids, array $have, array $missing): array {
		$need = [];

		foreach ($ids as $id) {
			$id = (int) $id;

			if (!array_key_exists($id, $have) && !array_key_exists($id, $missing)) {
				$need[$id] = true;
			}
		}

		$need = array_keys($need);
		sort($need, SORT_NUMERIC);

		return $need;
	}

	private function pick(array $ids, array $have): array {
		$result = [];

		foreach ($ids as $id) {
			$id = (int) $id;

			if (array_key_exists($id, $have)) {
				$result[$id] = $have[$id];
			}
		}

		return $result;
	}
}
