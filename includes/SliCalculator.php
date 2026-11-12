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

use API,
	CSla,
	CTimezoneHelper,
	ReflectionMethod,
	Throwable;

/**
 * SLI calculation helper.
 *
 * Uses the public sla.getsli API whenever the SLA's own reporting period is requested. When the user overrides
 * the reporting period (e.g. shows a monthly SLA in weekly buckets), the same internal calculation that sla.getsli
 * uses (CSla::getReportingPeriods() and CSla::calculateSli()) is executed with a modified period, so schedules,
 * excluded downtimes, time zones and service creation time are handled exactly like in the core.
 */
class SliCalculator {

	public const PERIOD_SLA_DEFAULT = -1;

	/**
	 * Get enabled SLAs with everything needed for SLI calculation.
	 *
	 * @param array|null $slaids
	 *
	 * @return array  SLAs indexed by slaid and sorted by name.
	 */
	public static function getSlas(?array $slaids = null): array {
		$slas = API::Sla()->get([
			'output' => ['slaid', 'name', 'period', 'slo', 'timezone', 'effective_date'],
			'selectSchedule' => ['period_from', 'period_to'],
			'selectExcludedDowntimes' => ['name', 'period_from', 'period_to'],
			'slaids' => $slaids,
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'preservekeys' => true
		]);

		uasort($slas, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return $slas;
	}

	/**
	 * Calculate SLI for the last $periods reporting periods (the last one is the current, ongoing period).
	 *
	 * @param array      $sla              SLA as returned by getSlas().
	 * @param int        $period_override  PERIOD_SLA_DEFAULT or one of ZBX_SLA_PERIOD_*.
	 * @param int        $periods          Number of reporting periods.
	 * @param array|null $serviceids       Limit to these services (null = all services of the SLA).
	 *
	 * @return array [
	 *     'period' => (int) period type actually used,
	 *     'overridden' => (bool),
	 *     'periods' => [['period_from' => int, 'period_to' => int], ...],
	 *     'services' => [serviceid => [period_index => cell, ...], ...]
	 * ]
	 *   where cell = ['sli' => float, 'uptime' => int, 'downtime' => int, 'excluded' => int, 'error_budget' => int,
	 *                 'excluded_downtimes' => array]
	 */
	public static function calculate(array $sla, int $period_override, int $periods, ?array $serviceids = null): array {
		$period = (int) $sla['period'];
		$result = null;
		$overridden = false;

		if ($period_override != self::PERIOD_SLA_DEFAULT && $period_override != $period) {
			try {
				$result = self::calculateWithPeriod($sla, $period_override, $periods, $serviceids);
				$period = $period_override;
				$overridden = true;
			}
			catch (Throwable $e) {
				// Core internals changed - fall back to the SLA's own period.
				$result = null;
			}
		}

		if ($result === null) {
			$result = API::Sla()->getSli([
				'slaid' => $sla['slaid'],
				'periods' => $periods,
				'serviceids' => $serviceids
			]);
		}

		return self::normalize($sla, $result) + [
			'period' => $period,
			'overridden' => $overridden
		];
	}

	private static function calculateWithPeriod(array $sla, int $period, int $periods, ?array $serviceids): array {
		$sla_modified = ['period' => $period] + $sla;

		$reporting_periods = self::callCore('getReportingPeriods', $sla_modified, [
			'period_from' => null,
			'period_to' => null,
			'periods' => $periods
		]);

		if (!$reporting_periods) {
			return ['periods' => [], 'serviceids' => [], 'sli' => []];
		}

		$db_services = API::Service()->get([
			'output' => ['created_at'],
			'selectStatusTimeline' => $reporting_periods,
			'slaids' => $sla['slaid'],
			'serviceids' => $serviceids,
			'preservekeys' => true
		]);

		return [
			'periods' => $reporting_periods,
			'serviceids' => array_keys($db_services),
			'sli' => self::callCore('calculateSli', $sla_modified, $reporting_periods, array_values($db_services))
		];
	}

	private static function callCore(string $method, ...$args) {
		$reflection = new ReflectionMethod(CSla::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invoke(null, ...$args);
	}

	/**
	 * Re-index raw sla.getsli output by service ID and drop periods that end before the SLA effective date.
	 */
	private static function normalize(array $sla, array $raw): array {
		$keep = [];

		foreach ($raw['periods'] as $index => $period) {
			if ($period['period_to'] > $sla['effective_date']) {
				$keep[$index] = $period;
			}
		}

		$services = [];

		foreach ($raw['serviceids'] as $service_index => $serviceid) {
			foreach ($keep as $period_index => $period) {
				$cell = $raw['sli'][$period_index][$service_index] ?? null;

				if ($cell === null) {
					continue;
				}

				$excluded = 0;

				foreach ($cell['excluded_downtimes'] as $excluded_downtime) {
					$excluded += $excluded_downtime['period_to'] - $excluded_downtime['period_from'];
				}

				$services[$serviceid][] = [
					'sli' => (float) $cell['sli'],
					'uptime' => (int) $cell['uptime'],
					'downtime' => (int) $cell['downtime'],
					'excluded' => $excluded,
					'error_budget' => (int) $cell['error_budget'],
					'excluded_downtimes' => $cell['excluded_downtimes']
				];
			}
		}

		return [
			'periods' => array_values($keep),
			'services' => $services
		];
	}

	public static function getTimezone(array $sla): string {
		return $sla['timezone'] !== ZBX_DEFAULT_TIMEZONE ? $sla['timezone'] : CTimezoneHelper::getSystemTimezone();
	}

	/**
	 * Human readable label of a reporting period, formatted like in the SLA report.
	 */
	public static function getPeriodLabel(int $period, int $period_from, int $period_to, string $timezone,
			bool $short = false): string {
		$fmt = static fn(string $const, string $default): string => defined($const) ? constant($const) : $default;

		switch ($period) {
			case ZBX_SLA_PERIOD_DAILY:
				return zbx_date2str($short ? 'm-d' : $fmt('ZBX_SLA_PERIOD_DATE_FORMAT_DAILY', 'Y-m-d'), $period_from,
					$timezone
				);

			case ZBX_SLA_PERIOD_WEEKLY:
				return $short
					? zbx_date2str('m-d', $period_from, $timezone)
					: zbx_date2str($fmt('ZBX_SLA_PERIOD_DATE_FORMAT_WEEKLY_FROM', 'Y-m-d'), $period_from, $timezone).
						' – '.zbx_date2str($fmt('ZBX_SLA_PERIOD_DATE_FORMAT_WEEKLY_TO', 'm-d'), $period_to - 1,
							$timezone
						);

			case ZBX_SLA_PERIOD_MONTHLY:
				return zbx_date2str($short ? 'M Y' : $fmt('ZBX_SLA_PERIOD_DATE_FORMAT_MONTHLY', 'Y-m'), $period_from,
					$timezone
				);

			case ZBX_SLA_PERIOD_QUARTERLY:
				return $short
					? zbx_date2str('Y', $period_from, $timezone).' Q'.
						(intdiv((int) zbx_date2str('n', $period_from, $timezone) - 1, 3) + 1)
					: zbx_date2str($fmt('ZBX_SLA_PERIOD_DATE_FORMAT_QUARTERLY_FROM', 'Y-m'), $period_from, $timezone).
						' – '.zbx_date2str($fmt('ZBX_SLA_PERIOD_DATE_FORMAT_QUARTERLY_TO', 'm'), $period_to - 1,
							$timezone
						);

			case ZBX_SLA_PERIOD_ANNUALLY:
			default:
				return zbx_date2str($fmt('ZBX_SLA_PERIOD_DATE_FORMAT_ANNUALLY', 'Y'), $period_from, $timezone);
		}
	}
}
