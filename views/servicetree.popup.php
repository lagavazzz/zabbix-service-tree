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
**
** @var CView $this
** @var array $data
*/

if ($data['error'] !== null) {
	$output = [
		'header' => _('Service SLA'),
		'error' => [
			'messages' => [$data['error']]
		]
	];
}
else {
	$output = [
		'header' => $data['data']['service']['name'],
		'body' => (new CDiv())
			->setId('servicetree-popup-body')
			->addClass('servicetree-popup-body')
			->toString(),
		'buttons' => [],
		'script_inline' => 'new ServiceTreePopup(document.getElementById("servicetree-popup-body"), '.
			json_encode($data['data']).', '.json_encode([
				'strings' => [
					'SLA' => _('SLA'),
					'SLI' => _('SLI'),
					'SLO' => _('SLO'),
					'Uptime' => _('Uptime'),
					'Downtime' => _('Downtime'),
					'Excluded downtime' => _('Excluded downtime'),
					'Error budget' => _('Error budget'),
					'Graph' => _('Graph'),
					'State over period' => _('State over period'),
					'SLI per period' => _('SLI per period'),
					'Show' => _('Show'),
					'periods' => _('periods'),
					'Period' => _('Period'),
					'SLA default' => _('SLA default'),
					'Reporting period' => _('Reporting period'),
					'Service state over period' => _('Service state over period'),
					'State' => _('State'),
					'Problems' => _('Problems that affected the service (incl. child services)'),
					'Time' => _('Time'),
					'Recovery time' => _('Recovery time'),
					'Status' => _('Status'),
					'Service' => _('Service'),
					'Host' => _('Host'),
					'Problem' => _('Problem'),
					'Severity' => _('Severity'),
					'Duration' => _('Duration'),
					'PROBLEM' => _('PROBLEM'),
					'RESOLVED' => _('RESOLVED'),
					'No problems' => _('No problems found in this period.'),
					'Hint SLI' => _('Click a column to select the period. Drag across columns to zoom, double-click to reset.'),
					'Hint state' => _('Drag across the graph to zoom in, double-click to reset.'),
					'Reset zoom' => _('Reset zoom'),
					'Zoom' => _('Zoom'),
					'Filtered by zoom' => _('selected with the mouse'),
					'Displaying %1$s to %2$s of %3$s found' => _('Displaying %1$s to %2$s of %3$s found'),
					'latest %1$s shown' => _('only the latest %1$s problems are shown'),
					'current' => _('current period, in progress'),
					'Open SLA report' => _('Open in SLA report'),
					'Open service' => _('Open service'),
					'Overridden' => _('period overridden – calculated with the SLA schedule and excluded downtimes'),
					'Status changes' => _('Status changes'),
					'N/A' => _('N/A')
				]
			]).');',
		'dialogue_class' => 'modal-popup-large servicetree-popup'
	];
}

if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

echo json_encode($output);
