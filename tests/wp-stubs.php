<?php

/**
 * Minimalne zamienniki funkcji WordPressa dla testów jednostkowych uruchamianych bez WordPressa.
 */
if (! function_exists('apply_filters')) {
	function apply_filters($hook, $value, ...$args)
	{
		return $value;
	}
}

if (! function_exists('get_date_from_gmt')) {
	function get_date_from_gmt($date, $format = 'Y-m-d H:i:s')
	{
		return gmdate($format, strtotime($date . ' UTC'));
	}
}

if (! function_exists('wp_json_encode')) {
	function wp_json_encode($data, $flags = 0, $depth = 512)
	{
		return json_encode($data, $flags, $depth);
	}
}
