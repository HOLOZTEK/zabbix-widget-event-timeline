<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger の継承情報（templateid / flags）のリクエスト内キャッシュ（v0.2.0 Phase 3）。
 *
 * TriggerSelectionResolver の fetch として渡し、同じ Widget Refresh の中で Selection の解決と
 * 対象Host上の Trigger の正規化（TriggerScopeExpander）が同じ Trigger を何度も API 取得しないようにする。
 * 取得を試みて返らなかった（存在しない・閲覧できない）IDも記録し、再取得しない。
 *
 * Phase 4 では Trigger prototype 用にも別インスタンスを作って使う（TriggerPrototypeResolver の fetch。取得関数は
 * triggerprototype.get）。通常 Trigger 用と prototype 用でインスタンスを分け、キャッシュを混同しない。
 * 行には任意で prototypeid（Discovered Trigger の生成元 prototype）と description（prototype の表示名）を保持できる。
 */
class TriggerInheritanceCache {

	/** @var callable function(int[] $triggerids): ?array */
	private $fetcher;

	private array $rows = [];
	private array $missing = [];

	/** 注入された取得関数の呼び出し回数（統計・デバッグ用）。 */
	public int $fetch_calls = 0;

	public function __construct(callable $fetcher) {
		$this->fetcher = $fetcher;
	}

	/**
	 * 別経路（対象Host上の Trigger 一覧等）で取得済みの行を登録する。
	 *
	 * @param array<int, array{templateid: int, flags: int, prototypeid?: int, description?: string}> $rows
	 */
	public function seed(array $rows): void {
		foreach ($rows as $id => $row) {
			$cached = [
				'templateid' => (int) ($row['templateid'] ?? 0),
				'flags' => (int) ($row['flags'] ?? 0)
			];

			if (array_key_exists('prototypeid', $row)) {
				$cached['prototypeid'] = (int) $row['prototypeid'];
			}
			elseif (array_key_exists('prototypeid', $this->rows[(int) $id] ?? [])) {
				// 生成元 prototype 付きで取得済みの行を、付いていない行で上書きして情報を失わない。
				$cached['prototypeid'] = $this->rows[(int) $id]['prototypeid'];
			}

			if (array_key_exists('description', $row)) {
				$cached['description'] = (string) $row['description'];
			}

			$this->rows[(int) $id] = $cached;
			unset($this->missing[(int) $id]);
		}
	}

	/**
	 * @param int[] $triggerids
	 *
	 * @return array<int, array{templateid: int, flags: int}>|null  閲覧可能なものだけ。取得失敗時は null
	 */
	public function fetch(array $triggerids): ?array {
		$need = [];

		foreach ($triggerids as $id) {
			$id = (int) $id;

			if (!array_key_exists($id, $this->rows) && !array_key_exists($id, $this->missing)) {
				$need[$id] = true;
			}
		}

		if ($need) {
			$need = array_keys($need);
			sort($need, SORT_NUMERIC);

			$this->fetch_calls++;
			$rows = ($this->fetcher)($need);

			if (!is_array($rows)) {
				return null;
			}

			$this->seed($rows);

			foreach ($need as $id) {
				if (!array_key_exists($id, $this->rows)) {
					$this->missing[$id] = true;
				}
			}
		}

		$result = [];

		foreach ($triggerids as $id) {
			$id = (int) $id;

			if (array_key_exists($id, $this->rows)) {
				$result[$id] = $this->rows[$id];
			}
		}

		return $result;
	}
}
