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

use API,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CProfile,
	CRoleHelper,
	CSlaHelper,
	CTagHelper,
	Modules\ServiceTree\Includes\SliCalculator;

class ServiceTreeList extends CController {

	private const PROFILE_PREFIX = 'web.servicetree.filter.';

	/**
	 * Maximum nesting depth rendered (protects against unexpected data).
	 */
	private const MAX_DEPTH = 64;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_name' =>	'string',
			'filter_status' =>	'in '.implode(',', [SERVICE_STATUS_ANY, SERVICE_STATUS_OK, SERVICE_STATUS_PROBLEM]),
			'filter_slaid' =>	'id',
			'filter_period' =>	'in '.implode(',', [SliCalculator::PERIOD_SLA_DEFAULT, ZBX_SLA_PERIOD_DAILY,
				ZBX_SLA_PERIOD_WEEKLY, ZBX_SLA_PERIOD_MONTHLY, ZBX_SLA_PERIOD_QUARTERLY, ZBX_SLA_PERIOD_ANNUALLY
			]),
			'filter_set' =>		'in 1',
			'filter_rst' =>		'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_SERVICES_SERVICES);
	}

	protected function doAction(): void {
		$this->updateProfile();

		$filter = [
			'name' => CProfile::get(self::PROFILE_PREFIX.'name', ''),
			'status' => (int) CProfile::get(self::PROFILE_PREFIX.'status', SERVICE_STATUS_ANY),
			'slaid' => (string) CProfile::get(self::PROFILE_PREFIX.'slaid', 0),
			'period' => (int) CProfile::get(self::PROFILE_PREFIX.'period', SliCalculator::PERIOD_SLA_DEFAULT)
		];

		$services = API::Service()->get([
			'output' => ['serviceid', 'name', 'status', 'sortorder'],
			'selectParents' => ['serviceid'],
			'selectChildren' => ['serviceid'],
			'selectTags' => ['tag', 'value'],
			'selectProblemEvents' => ['eventid', 'severity', 'name'],
			'preservekeys' => true
		]);

		// SLA data itself is loaded asynchronously (servicetree.list.sla); here only the names for the filter.
		$all_slas = API::Sla()->get([
			'output' => ['slaid', 'name'],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'preservekeys' => true
		]);

		uasort($all_slas, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		if ($filter['slaid'] != 0 && !array_key_exists($filter['slaid'], $all_slas)) {
			$filter['slaid'] = '0';
			CProfile::delete(self::PROFILE_PREFIX.'slaid');
		}

		$rows = $this->buildRows($services, $filter);

		$tags = [];

		foreach ($rows as $row) {
			$tags[$row['serviceid']] = [
				'serviceid' => $row['serviceid'],
				'tags' => $services[$row['serviceid']]['tags']
			];
		}

		$data = [
			'filter' => $filter,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'active', 1),
			'slas' => array_map(static fn(array $sla): array => [
				'slaid' => $sla['slaid'],
				'name' => $sla['name']
			], $all_slas),
			'period_names' => CSlaHelper::getPeriodNames(),
			'rows' => $rows,
			'services' => $services,
			'tags' => $tags ? CTagHelper::getTagsHtml(array_values($tags), ZBX_TAG_OBJECT_SERVICE) : [],
			'is_filtered' => $filter['name'] !== '' || $filter['status'] != SERVICE_STATUS_ANY,
			'can_monitor_problems' => $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Service tree'));
		$this->setResponse($response);
	}

	private function updateProfile(): void {
		if ($this->hasInput('filter_set')) {
			CProfile::update(self::PROFILE_PREFIX.'name', trim($this->getInput('filter_name', '')), PROFILE_TYPE_STR);
			CProfile::update(self::PROFILE_PREFIX.'status', $this->getInput('filter_status', SERVICE_STATUS_ANY),
				PROFILE_TYPE_INT
			);
			CProfile::update(self::PROFILE_PREFIX.'slaid', $this->getInput('filter_slaid', 0), PROFILE_TYPE_ID);
			CProfile::update(self::PROFILE_PREFIX.'period',
				$this->getInput('filter_period', SliCalculator::PERIOD_SLA_DEFAULT), PROFILE_TYPE_INT
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			foreach (['name', 'status', 'slaid', 'period'] as $key) {
				CProfile::delete(self::PROFILE_PREFIX.$key);
			}
		}
	}

	/**
	 * Flatten the service graph into ordered tree rows. A service with several parents is shown under each of them.
	 *
	 * @return array  List of ['rowid', 'parent_rowid', 'serviceid', 'depth', 'has_children', 'children_count']
	 */
	private function buildRows(array $services, array $filter): array {
		$children = [];
		$roots = [];

		foreach ($services as $serviceid => $service) {
			$parentids = array_filter(array_column($service['parents'], 'serviceid'),
				static fn($parentid): bool => array_key_exists($parentid, $services)
			);

			if (!$parentids) {
				$roots[] = $serviceid;
			}

			foreach ($parentids as $parentid) {
				$children[$parentid][] = $serviceid;
			}
		}

		$sort = static function(array &$serviceids) use ($services): void {
			usort($serviceids, static function($a, $b) use ($services): int {
				return [(int) $services[$a]['sortorder'], mb_strtolower($services[$a]['name'])]
					<=> [(int) $services[$b]['sortorder'], mb_strtolower($services[$b]['name'])];
			});
		};

		$sort($roots);

		foreach ($children as &$child_serviceids) {
			$sort($child_serviceids);
		}
		unset($child_serviceids);

		// Filtering: keep matching services together with all their ancestors.
		$visible = null;

		if ($filter['name'] !== '' || $filter['status'] != SERVICE_STATUS_ANY) {
			$parents_of = [];

			foreach ($children as $parentid => $child_serviceids) {
				foreach ($child_serviceids as $child_serviceid) {
					$parents_of[$child_serviceid][] = $parentid;
				}
			}

			$visible = [];

			foreach ($services as $serviceid => $service) {
				if ($filter['name'] !== '' && mb_stripos($service['name'], $filter['name']) === false) {
					continue;
				}

				if ($filter['status'] == SERVICE_STATUS_OK && $service['status'] != ZBX_SEVERITY_OK) {
					continue;
				}

				if ($filter['status'] == SERVICE_STATUS_PROBLEM && $service['status'] == ZBX_SEVERITY_OK) {
					continue;
				}

				$stack = [$serviceid];

				while ($stack) {
					$id = array_pop($stack);

					if (array_key_exists($id, $visible)) {
						continue;
					}

					$visible[$id] = true;

					foreach ($parents_of[$id] ?? [] as $parentid) {
						$stack[] = $parentid;
					}
				}
			}
		}

		$rows = [];

		$walk = function(string $serviceid, string $parent_rowid, int $depth, array $path) use (&$walk, &$rows,
				$children, $visible): void {
			$rowid = $parent_rowid === '' ? $serviceid : $parent_rowid.'_'.$serviceid;

			$visible_children = array_values(array_filter($children[$serviceid] ?? [],
				static fn($childid): bool => $visible === null || array_key_exists($childid, $visible)
			));

			$rows[] = [
				'rowid' => $rowid,
				'parent_rowid' => $parent_rowid,
				'serviceid' => $serviceid,
				'depth' => $depth,
				'has_children' => (bool) $visible_children,
				'children_count' => count($children[$serviceid] ?? [])
			];

			if ($depth >= self::MAX_DEPTH) {
				return;
			}

			$path[$serviceid] = true;

			foreach ($visible_children as $childid) {
				if (!array_key_exists($childid, $path)) {
					$walk((string) $childid, $rowid, $depth + 1, $path);
				}
			}
		};

		foreach ($roots as $serviceid) {
			if ($visible === null || array_key_exists($serviceid, $visible)) {
				$walk((string) $serviceid, '', 0, []);
			}
		}

		return $rows;
	}
}
