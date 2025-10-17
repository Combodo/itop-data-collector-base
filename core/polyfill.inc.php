<?php

/**
 * @see https://www.php.net/manual/en/function.array-is-list.php
 * Make this function available even for (PHP 8 < 8.1.0)
 */
if (!function_exists("array_is_list")) {
	function array_is_list(array $array): bool
	{
		$i = 0;
		foreach ($array as $k => $v) {
			if ($k !== $i++) {
				return false;
			}
		}

		return true;
	}
}
