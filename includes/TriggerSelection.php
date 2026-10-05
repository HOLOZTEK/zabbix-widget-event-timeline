<?php declare(strict_types = 0);

namespace Modules\EventTimeline\Includes;

/**
 * Trigger Selection（v0.2.0 Phase 1）: triggerids フィールドの値を正規化し、内部状態として保持するだけの
 * 純粋ロジック（BucketPlanner / DimensionAggregator / SourceResolver と同じ方針、Zabbixフレームワークに
 * 依存しない）。
 *
 * triggerids は「固定Trigger指定」でも「Navigator（Metric Navigator等）からのWidget参照」でも、
 * 論理的な正本は同じ triggerids[] 配列である（Event Timeline専用の特殊なPayloadは作らない。§6）。
 * Widget参照／不正値の区別は、CWidgetFieldMultiSelectTrigger::getValidationRules() が
 * Widget参照（_reference）時は緩い検証、固定値（配列）時は厳格な API_IDS 検証を行うことで、
 * Zabbixコア側（CControllerDashboardWidgetView::checkInput）がすでに担っている。配列の形を保った
 * まま壊れた値（非数値文字列・ネストした配列等）が来た場合はコア側の検証で丸ごとリクエストが
 * 拒否される（本Widgetの表示全体がエラーになるだけで、PHP/JSの例外にはならない）。
 *
 * そのためこのクラスに実際に渡る値は通常、空配列 [] か、正しい形式のID文字列配列のいずれかだが、
 * 直接呼び出し（単体テスト、将来のPhase以降の別経路）に備え、想定外の型を無条件に inactive へ
 * 黙って落とさない（Fail-Openにしない）。
 *
 * 3値の内部状態を区別する。
 *   - inactive: Trigger条件なし（§9.1）。固定Triggerも指定されておらず、Navigatorからの
 *               Selectionも届いていない（または届いているが0件選択。§9.2）。v0.1.5と同じ挙動。
 *   - active:   1件以上のTrigger IDを保持している（§9.3）。Phase 2 以降は TriggerSelectionResolver で
 *               Canonical Trigger へ正規化され、SourceResolver::resolveEventSelection() が
 *               実体の確認できた source Trigger で Event を絞り込む。
 *   - invalid:  受信値がTrigger ID配列として解釈できない（§9.4）。「Trigger指定なし」と無条件に
 *               同一視すると、結果的に全Eventを表示してしまうFail-Openになるため、呼び出し側
 *               （WidgetView）で明示的に区別できるようにする。
 *
 * 既知の限界: CWidgetFieldMultiSelect::setValue() が値を (array) キャストするため、Navigatorが
 * 広報する値が PHP の null や false であっても、このクラスに渡る前に空配列 [] へ畳み込まれる。
 * そのため「Navigatorが null を送った」ケースと「Navigatorが空配列（意図した0件選択）を送った」
 * ケースは、既存のWidgetフィールド基盤を再利用する以上、PHPの値としては区別できない
 * （§6で許可されている既存機構の再利用に伴う制約。完了報告の未解決事項として明記する）。
 * 一方、配列の要素がTrigger IDとして解釈できない場合（文字列・0以下の値・入れ子配列等）は、
 * このクラスの中で invalid として明示的に区別する。
 */
class TriggerSelection {

	public const STATE_INACTIVE = 'inactive';
	public const STATE_ACTIVE = 'active';
	public const STATE_INVALID = 'invalid';

	/**
	 * @param mixed $raw  triggerids フィールドの生値。通常は配列（空配列、またはID文字列の配列）。
	 *
	 * @return array{trigger_filter: string, triggerids: int[]}
	 */
	public static function resolve($raw): array {
		if ($raw === null || $raw === []) {
			return ['trigger_filter' => self::STATE_INACTIVE, 'triggerids' => []];
		}

		if (!is_array($raw)) {
			// 配列以外（文字列単体・bool等）はTrigger ID集合として解釈できない。
			return ['trigger_filter' => self::STATE_INVALID, 'triggerids' => []];
		}

		$triggerids = [];

		foreach ($raw as $id) {
			$normalized = self::normalizeId($id);

			if ($normalized === null) {
				// 要素が1つでもTrigger IDとして解釈できない場合、部分的に正しい要素だけを採用せず、
				// 全体を invalid とする（不正入力を黙って削って「指定なし」に近づけないため）。
				return ['trigger_filter' => self::STATE_INVALID, 'triggerids' => []];
			}

			$triggerids[$normalized] = true;
		}

		$ids = array_keys($triggerids);
		sort($ids, SORT_NUMERIC);

		if (!$ids) {
			// 配列ではあるが要素が無かった（空配列は is_array チェックより前の `$raw === []` で
			// 捕捉済みのため、ここに到達するのは要素が最終的に重複除去で0件になるケースは無い）。
			return ['trigger_filter' => self::STATE_INACTIVE, 'triggerids' => []];
		}

		return ['trigger_filter' => self::STATE_ACTIVE, 'triggerids' => $ids];
	}

	/**
	 * Trigger ID 1件を正規表現。整数（>0）または数字のみの文字列（>0）であれば int を返す。
	 * それ以外（負数・0・小数・非数値文字列・null・配列等）は null を返す。
	 */
	private static function normalizeId($id): ?int {
		if (is_int($id)) {
			return $id > 0 ? $id : null;
		}

		if (is_string($id) && $id !== '' && ctype_digit($id)) {
			$int = (int) $id;

			return $int > 0 ? $int : null;
		}

		return null;
	}
}
