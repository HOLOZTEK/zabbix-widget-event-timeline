<?php declare(strict_types = 0);

// SourceResolver::parseEventNames が参照する定数だけを持つスタブ（Zabbix本体なしでテストを実行するため）。
namespace Modules\EventTimeline\Includes;

class WidgetForm {
	public const SOURCE_FIXED = 0;
	public const SOURCE_WIDGET = 1;
	public const EVENT_NAMES_MAX = 50;
}
