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
	Exception,
	Modules\ServiceTree\Includes\Cache,
	Modules\ServiceTree\Includes\Config,
	Modules\ServiceTree\Includes\PopupDataBuilder,
	Modules\ServiceTree\Includes\SliCalculator;

/**
 * JSON data for the popup.
 *   part=main      - KPIs, SLI per period and status timeline (fast);
 *   part=problems  - problems that affected the service within [from, to) (loaded separately, after the graph).
 */
class ServiceTreePopupData extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'part' =>			'in main,problems',
			'serviceid' =>		'required|id',
			'slaid' =>			'id',
			'period' =>			'in '.implode(',', [SliCalculator::PERIOD_SLA_DEFAULT, ZBX_SLA_PERIOD_DAILY,
				ZBX_SLA_PERIOD_WEEKLY, ZBX_SLA_PERIOD_MONTHLY, ZBX_SLA_PERIOD_QUARTERLY, ZBX_SLA_PERIOD_ANNUALLY
			]),
			'period_count' =>	'in '.implode(',', PopupDataBuilder::PERIOD_COUNTS),
			'period_index' =>	'ge 0',
			'from' =>			'ge 0',
			'to' =>				'ge 0'
		];

		$ret = $this->validateInput($fields);

		if ($ret && $this->getInput('part', 'main') === 'main' && !$this->hasInput('slaid')) {
			error(_s('Field "%1$s" is mandatory.', 'slaid'));
			$ret = false;
		}

		if ($ret && $this->getInput('part', 'main') === 'problems'
				&& (!$this->hasInput('from') || !$this->hasInput('to'))) {
			error(_s('Field "%1$s" is mandatory.', 'from/to'));
			$ret = false;
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_SERVICES_SERVICES);
	}

	protected function doAction(): void {
		try {
			$output = ['data' => $this->getInput('part', 'main') === 'problems'
				? $this->buildProblems()
				: $this->buildData()
			];
		}
		catch (Exception $e) {
			$output = ['error' => ['messages' => [$e->getMessage()]]];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}

	protected function buildData(): array {
		$params = [
			'serviceid' => $this->getInput('serviceid'),
			'slaid' => $this->getInput('slaid'),
			'period' => (int) $this->getInput('period', SliCalculator::PERIOD_SLA_DEFAULT),
			'period_count' => (int) $this->getInput('period_count', 12),
			'period_index' => $this->hasInput('period_index') ? (int) $this->getInput('period_index') : null
		];

		return Cache::remember('popup', $params, static fn(): array => PopupDataBuilder::build(
			$params['serviceid'], $params['slaid'], $params['period'], $params['period_count'],
			$params['period_index']
		));
	}

	protected function buildProblems(): array {
		$params = [
			'serviceid' => $this->getInput('serviceid'),
			'from' => (int) $this->getInput('from'),
			'to' => (int) $this->getInput('to'),
			'max_problems' => Config::get('max_problems')
		];

		return Cache::remember('problems', $params, static fn(): array => PopupDataBuilder::getProblems(
			$params['serviceid'], $params['from'], $params['to']
		));
	}
}
