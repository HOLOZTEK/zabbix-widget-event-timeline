<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if (!empty($data['chart_data'])) {
	$view->setVar('chart_data', $data['chart_data']);
}
if (isset($data['trigger_selection'])) {
	// デバッグ専用（v0.2.0 Phase 2 §19）: Trigger Selection の source → canonical 解決結果。
	// 正式UIには表示せず、開発者ツール（Network の応答 / Console の debug ログ）でだけ確認できる。
	$view->setVar('trigger_selection', $data['trigger_selection']);
}
if (isset($data['detail'])) {
	$view->setVar('detail', $data['detail']);
}
if (!empty($data['error'])) {
	$view->setVar('error', $data['error']);
}

$view->addItem(new CDiv())->show();
