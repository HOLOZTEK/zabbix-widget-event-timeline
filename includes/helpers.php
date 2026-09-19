<?php declare(strict_types = 0);

if (!function_exists('_et')) {
	bindtextdomain('event-timeline', __DIR__ . '/../locale');
	bind_textdomain_codeset('event-timeline', 'UTF-8');

	function _et(string $string): string {
		return dgettext('event-timeline', $string);
	}
}
