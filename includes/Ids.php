<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Zabbix の ID（triggerid / templateid / prototype ID / itemid / hostid / groupid / eventid）の扱い。
 *
 * Zabbix の ID は符号なし 64-bit（最大 20 桁）で、API も文字列で返す。PHP の int（符号付き 64-bit）へ変換すると
 * PHP_INT_MAX を超える ID が PHP_INT_MAX に飽和し、異なる ID が1件に衝突する。そのため ID は常に
 * 「先頭ゼロを除いた正の10進数字文字列」として保持し、比較・並べ替えも文字列のまま行う。
 *
 * 配列キーに使うと、PHP は PHP_INT_MAX 以下の数字文字列キーを int キーへ変換するが、値は失われない
 * （上限を超えるものは文字列キーのまま残る）。array_keys() の結果は keys() で文字列へ戻して使う。
 *
 * 純粋ロジック（Zabbixフレームワーク非依存）。
 */
final class Ids {

	/**
	 * ID 1件を正規化する。正の整数、または数字のみの文字列（値 > 0）であれば先頭ゼロを除いた文字列を返す。
	 * それ以外（0・負数・小数・非数値文字列・null・配列等）は null。
	 */
	public static function normalize($id): ?string {
		if (is_int($id)) {
			return $id > 0 ? (string) $id : null;
		}

		if (is_string($id) && $id !== '' && ctype_digit($id)) {
			$id = ltrim($id, '0');

			return $id !== '' ? $id : null;
		}

		return null;
	}

	/**
	 * 参照先 ID（templateid・parent_triggerid 等）を正規化する。参照なし（0・未設定・不正値）は '0'。
	 */
	public static function ref($id): string {
		return self::normalize($id) ?? '0';
	}

	/**
	 * 数値としての大小（桁数 → 辞書順）。正規化済みの ID を前提とする（int キーで渡ってきたものも文字列として比較する）。
	 */
	public static function compare($a, $b): int {
		$a = (string) $a;
		$b = (string) $b;

		return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
	}

	/**
	 * ID の列を正規化し、重複を除いて昇順にする。ID として解釈できない値は除く。
	 *
	 * @return string[]
	 */
	public static function unique(array $ids): array {
		$set = [];

		foreach ($ids as $id) {
			$id = self::normalize($id);

			if ($id !== null) {
				$set[$id] = true;
			}
		}

		return self::keys($set);
	}

	/**
	 * ID をキーとする集合（配列）のキーを、文字列の昇順で返す。
	 *
	 * @return string[]
	 */
	public static function keys(array $set): array {
		$ids = array_map('strval', array_keys($set));
		usort($ids, [self::class, 'compare']);

		return $ids;
	}

	/**
	 * ID をキーとする配列を、キーの昇順に並べ替える（キーは保つ）。
	 */
	public static function ksort(array &$map): void {
		uksort($map, [self::class, 'compare']);
	}

	/**
	 * 正規化済みの ID から 1 を引く（eventid のページング用。'1' は '0' になる）。
	 */
	public static function decrement(string $id): string {
		$digits = str_split($id);

		for ($i = count($digits) - 1; $i >= 0; $i--) {
			if ($digits[$i] !== '0') {
				$digits[$i] = (string) ((int) $digits[$i] - 1);
				break;
			}

			$digits[$i] = '9';
		}

		$result = ltrim(implode('', $digits), '0');

		return $result !== '' ? $result : '0';
	}
}
