<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

use API;

/**
 * Source resolution: 設定（Host / Host group）から対象Host ID集合を決定する。
 *
 * 後続処理（Event取得）はここが返す「resolved host IDs」だけを受け取る。将来 Navigator（Widget参照）
 * ソースを加える場合は、この段の入力を差し替えるだけで後続処理を再利用できる。
 */
class SourceResolver {

	/**
	 * @param int[] $hostids   直接指定されたHost ID
	 * @param int[] $groupids  指定されたHost group ID（サブグループを含めて展開する）
	 *
	 * @return int[]  重複のないHost ID（昇順）。閲覧権限のないHostは含まれない。
	 */
	public static function resolve(array $hostids, array $groupids): array {
		$from_hosts = [];
		$from_groups = [];

		$hostids = self::normalize($hostids);
		$groupids = self::normalize($groupids);

		if ($hostids) {
			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'hostids' => $hostids,
				'preservekeys' => true
			]);

			if (is_array($hosts)) {
				$from_hosts = array_keys($hosts);
			}
		}

		if ($groupids) {
			// サブグループ（Parent/Child）に所属するHostも含める。
			$all_groupids = getSubGroups($groupids);

			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $all_groupids,
				'preservekeys' => true
			]);

			if (is_array($hosts)) {
				$from_groups = array_keys($hosts);
			}
		}

		return self::mergeUnique($from_hosts, $from_groups);
	}

	/**
	 * 複数のHost ID配列を統合し、重複を除いた昇順の整数配列にする。
	 */
	public static function mergeUnique(array ...$lists): array {
		$merged = [];

		foreach ($lists as $list) {
			foreach ($list as $id) {
				$merged[(int) $id] = true;
			}
		}

		$ids = array_keys($merged);
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	private static function normalize(array $ids): array {
		return array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
	}
}
