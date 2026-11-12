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

use CWebUser,
	Throwable;

/**
 * Small per-user file cache for calculated SLA data. Entries are keyed by user ID, user type and request
 * parameters, so users never see each other's (permission-dependent) data.
 */
class Cache {

	private const DIR_NAME = 'zbx_servicetree_cache';

	public static function remember(string $namespace, array $params, callable $callback) {
		$ttl = Config::get('cache_ttl');

		if ($ttl <= 0) {
			return $callback();
		}

		$file = self::getFile($namespace, $params);

		if ($file !== null && is_file($file) && filemtime($file) > time() - $ttl) {
			$data = @file_get_contents($file);

			if ($data !== false) {
				$value = json_decode($data, true);

				if (is_array($value) && array_key_exists('v', $value)) {
					return $value['v'];
				}
			}
		}

		$value = $callback();

		if ($file !== null) {
			try {
				$tmp = $file.'.'.getmypid().'.tmp';

				if (@file_put_contents($tmp, json_encode(['v' => $value]), LOCK_EX) !== false) {
					@rename($tmp, $file);
				}
			}
			catch (Throwable $e) {
				// Cache is best effort.
			}
		}

		return $value;
	}

	private static function getFile(string $namespace, array $params): ?string {
		$dir = rtrim(sys_get_temp_dir(), '/\\').'/'.self::DIR_NAME;

		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return null;
		}

		if (!is_writable($dir)) {
			return null;
		}

		$key = hash('sha256', json_encode([
			$namespace,
			CWebUser::$data['userid'] ?? '',
			CWebUser::$data['type'] ?? '',
			CWebUser::$data['roleid'] ?? '',
			CWebUser::$data['lang'] ?? '',
			CWebUser::$data['timezone'] ?? '',
			$params
		]));

		// Opportunistic cleanup of old entries (1% of requests).
		if (mt_rand(1, 100) === 1) {
			foreach (glob($dir.'/*.json') ?: [] as $old_file) {
				if (@filemtime($old_file) < time() - 3600) {
					@unlink($old_file);
				}
			}
		}

		return $dir.'/'.$namespace.'-'.$key.'.json';
	}
}
