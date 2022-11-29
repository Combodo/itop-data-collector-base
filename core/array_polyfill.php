<?php

if (!function_exists("array_is_list")) {
	function array_is_list(array $array): bool {
		$i = 0;
		foreach ($array as $k => $v) {
			if ($k !== $i++) {
				return false;
			}
		}

		return true;
	}
}

