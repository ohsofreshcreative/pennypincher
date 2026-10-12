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
