<?php declare(strict_types = 0);
/*
** Service tree module for Zabbix.
** Copyright (C) 2026 Service tree module contributors
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**
** SPDX-License-Identifier: AGPL-3.0-only
*/

namespace Modules\ServiceTree\Includes;

use APP,
	Throwable;

/**
 * Module options from manifest.json "config" (can be changed without touching the code).
 */
class Config {

	public const DEFAULTS = [
		// Seconds to keep calculated SLA data per user; 0 disables the cache.
		'cache_ttl' => 60,
		// How far back (days) to look for problems that started before a period but ended inside it.
		'problem_lookback_days' => 7,
		// Max. number of problems returned to the popup (1-1000).
		'max_problems' => 50,
		// Problems per page in the popup.
		'problems_per_page' => 20,
		// Max. number of (descendant) services whose problem tags are queried.
		'max_problem_services' => 1000,
		// Problem tags combined into one event query.
		'tags_per_query' => 100
	];

	/**
	 * Limits applied to configured values.
	 */
	private const RANGES = [
		'cache_ttl' => [0, 3600],
		'problem_lookback_days' => [0, 365],
		'max_problems' => [1, 1000],
		'problems_per_page' => [1, 1000],
		'max_problem_services' => [1, 100000],
		'tags_per_query' => [1, 1000]
	];

	private static ?array $manifest_config = null;

	/**
	 * Option value. Order: "config" in manifest.json read from disk (so edits apply immediately, without
	 * re-enabling the module) -> module config stored in the database -> built-in default.
	 */
	public static function get(string $name): int {
		$value = self::getManifestConfig()[$name] ?? null;

		if (!is_numeric($value)) {
			try {
				$module = APP::ModuleManager()->getModule('service-tree');
				$value = $module !== null ? $module->getOption($name) : null;
			}
			catch (Throwable $e) {
				$value = null;
			}
		}

		if (!is_numeric($value)) {
			$value = self::DEFAULTS[$name];
		}

		[$min, $max] = self::RANGES[$name];

		return max($min, min($max, (int) $value));
	}

	private static function getManifestConfig(): array {
		if (self::$manifest_config === null) {
			self::$manifest_config = [];

			$file = dirname(__DIR__).'/manifest.json';

			if (is_readable($file)) {
				$manifest = json_decode((string) file_get_contents($file), true);

				if (is_array($manifest) && array_key_exists('config', $manifest) && is_array($manifest['config'])) {
					self::$manifest_config = $manifest['config'];
				}
			}
		}

		return self::$manifest_config;
	}
}
