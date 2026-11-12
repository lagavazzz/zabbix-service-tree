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

namespace Modules\ServiceTree\Actions;

use CController,
	CControllerResponseData,
	CRoleHelper,
	CSlaHelper,
	Exception,
	Modules\ServiceTree\Includes\Cache,
	Modules\ServiceTree\Includes\SliCalculator;

/**
 * JSON: SLI of the current reporting period for every service of every (or the selected) SLA.
 * Loaded asynchronously by the tree page and cached per user for "cache_ttl" seconds.
 */
class ServiceTreeListSla extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'slaid' =>		'id',
			'period' =>		'in '.implode(',', [SliCalculator::PERIOD_SLA_DEFAULT, ZBX_SLA_PERIOD_DAILY,
				ZBX_SLA_PERIOD_WEEKLY, ZBX_SLA_PERIOD_MONTHLY, ZBX_SLA_PERIOD_QUARTERLY, ZBX_SLA_PERIOD_ANNUALLY
			])
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => array_column(get_and_clear_messages(), 'message')]
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_SERVICES_SERVICES);
	}

	protected function doAction(): void {
		$params = [
			'slaid' => (string) $this->getInput('slaid', 0),
			'period' => (int) $this->getInput('period', SliCalculator::PERIOD_SLA_DEFAULT)
		];

		try {
			$output = ['data' => Cache::remember('tree', $params, static fn(): array => self::build($params))];
		}
		catch (Exception $e) {
			$output = ['error' => ['messages' => [$e->getMessage()]]];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}

	private static function build(array $params): array {
		$slas = SliCalculator::getSlas($params['slaid'] != 0 ? [$params['slaid']] : null);
		$period_names = CSlaHelper::getPeriodNames();
		$services = [];

		foreach ($slas as $slaid => $sla) {
			$sli = SliCalculator::calculate($sla, $params['period'], 1);

			if (!$sli['periods']) {
				continue;
			}

			$period = $sli['periods'][count($sli['periods']) - 1];
			$period_label = SliCalculator::getPeriodLabel($sli['period'], $period['period_from'],
				$period['period_to'], SliCalculator::getTimezone($sla)
			);
			$slo = (float) $sla['slo'];

			foreach ($sli['services'] as $serviceid => $cells) {
				$cell = $cells[count($cells) - 1];

				$services[$serviceid][] = [
					'slaid' => (string) $slaid,
					'name' => $sla['name'],
					'slo' => $slo,
					'slo_text' => (floor($slo * 10000) / 10000).'%',
					'period_name' => $period_names[$sli['period']],
					'overridden' => $sli['overridden'],
					'period_label' => $period_label,
					'sli' => $cell['sli'],
					'sli_text' => $cell['sli'] == -1 ? _('N/A') : (floor($cell['sli'] * 10000) / 10000).'%',
					'uptime' => $cell['uptime'],
					'downtime' => $cell['downtime'],
					'excluded' => $cell['excluded'],
					'error_budget' => $cell['error_budget'],
					'uptime_text' => self::duration($cell['uptime']),
					'downtime_text' => self::duration($cell['downtime']),
					'excluded_text' => self::duration($cell['excluded']),
					'error_budget_text' => self::duration($cell['error_budget'])
				];
			}
		}

		return ['services' => $services];
	}

	private static function duration(int $seconds): string {
		return $seconds == 0 ? '0' : convertUnitsS($seconds, ['ignore_milliseconds' => true]);
	}
}
