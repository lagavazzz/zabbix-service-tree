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

use Modules\ServiceTree\Includes\SliCalculator;

$this->addJsFile('layout.mode.js');
$this->includeJsFile('servicetree.list.js.php');

$this->enableLayoutModes();
$web_layout_mode = $this->getLayoutMode();

$filter = null;

if ($web_layout_mode == ZBX_LAYOUT_NORMAL) {
	$sla_select = (new CSelect('filter_slaid'))
		->setId('filter-slaid')
		->setFocusableElementId('filter-slaid-focusable')
		->setValue($data['filter']['slaid'])
		->addOption(new CSelectOption(0, _('First matching SLA (by name)')));

	foreach ($data['slas'] as $sla) {
		$sla_select->addOption(new CSelectOption($sla['slaid'], $sla['name']));
	}

	$period_radio = (new CRadioButtonList('filter_period', $data['filter']['period']))
		->addValue(_('SLA default'), SliCalculator::PERIOD_SLA_DEFAULT)
		->setModern(true);

	foreach ($data['period_names'] as $period => $name) {
		$period_radio->addValue($name, $period);
	}

	$filter = (new CFilter())
		->addVar('action', 'servicetree.list')
		->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'servicetree.list'))
		->setProfile('web.servicetree.filter')
		->setActiveTab($data['active_tab'])
		->addFilterTab(_('Filter'), [
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Name'), 'filter_name'),
					new CFormField(
						(new CTextBox('filter_name', $data['filter']['name']))
							->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
					)
				])
				->addItem([
					new CLabel(_('Status')),
					new CFormField(
						(new CRadioButtonList('filter_status', $data['filter']['status']))
							->addValue(_('Any'), SERVICE_STATUS_ANY)
							->addValue(_('OK'), SERVICE_STATUS_OK)
							->addValue(_('Problem'), SERVICE_STATUS_PROBLEM)
							->setModern(true)
					)
				]),
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('SLA'), $sla_select->getFocusableElementId()),
					new CFormField($sla_select)
				])
				->addItem([
					new CLabel(_('Reporting period')),
					new CFormField($period_radio)
				])
		]);
}

// Table.
$table = (new CTableInfo())
	->setId('servicetree-table')
	->addClass('servicetree-table')
	->setHeader([
		_('Name'),
		(new CColHeader(_('Status')))->addClass(ZBX_STYLE_CELL_WIDTH),
		_('Root cause'),
		_('SLA'),
		_('Period'),
		(new CColHeader(_('SLO')))->addClass('servicetree-num'),
		(new CColHeader([
			_('SLA bar'),
			(new CSpan())->addClass('servicetree-scale-label js-scale-label')
		]))->addClass('servicetree-bar-col'),
		(new CColHeader(_('SLI')))->addClass('servicetree-num'),
		(new CColHeader(_('Downtime')))->addClass('servicetree-num'),
		(new CColHeader(_('Error budget')))->addClass('servicetree-num'),
		(new CColHeader(_('Tags')))->addClass(ZBX_STYLE_COLUMN_TAGS)
	]);

foreach ($data['rows'] as $row) {
	$service = $data['services'][$row['serviceid']];
	$status = (int) $service['status'];

	// Name column with tree toggle.
	$name_col = (new CCol([
		$row['has_children']
			? (new CButtonIcon(ZBX_ICON_CHEVRON_DOWN, _('Collapse')))
				->addClass('servicetree-toggle js-toggle')
				->setAttribute('aria-expanded', 'true')
			: (new CSpan())->addClass('servicetree-toggle-placeholder'),
		(new CLink($service['name'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'service.list')
				->setArgument('serviceid', $row['serviceid'])
		))->addClass('servicetree-name'),
		$row['children_count'] > 0 ? (new CSpan($row['children_count']))->addClass('servicetree-count') : null
	]))
		->addClass('servicetree-name-col')
		->addStyle('--servicetree-depth: '.$row['depth'].';');

	$root_cause = [];

	foreach (array_slice($service['problem_events'], 0, 3) as $problem_event) {
		if ($root_cause) {
			$root_cause[] = ', ';
		}

		$root_cause[] = (new CSpan($problem_event['name']))
			->addClass('servicetree-cause')
			->setAttribute('data-severity', $problem_event['severity']);
	}

	if (count($service['problem_events']) > 3) {
		$root_cause[] = (new CSpan(' +'.(count($service['problem_events']) - 3)))->addClass(ZBX_STYLE_GREY);
	}

	$root_cause = (new CCol($root_cause))->addClass('servicetree-cause-col');

	// SLA cells are filled asynchronously by view.loadSla() (servicetree.list.sla).
	$sla_cells = [
		(new CCol())->addClass('js-sla-name')->addClass(ZBX_STYLE_NOWRAP),
		(new CCol())->addClass('js-sla-period')->addClass(ZBX_STYLE_NOWRAP),
		(new CCol())->addClass('servicetree-num js-sla-slo'),
		(new CCol((new CDiv())->addClass('servicetree-bar-loading')))->addClass('servicetree-bar-col js-sla-bar-cell'),
		(new CCol())->addClass('servicetree-num js-sla-sli'),
		(new CCol())->addClass('servicetree-num js-sla-downtime'),
		(new CCol())->addClass('servicetree-num js-sla-budget')
	];

	$table->addRow(
		(new CRow(array_merge([
			$name_col,
			(new CCol(CSeverityHelper::getName($status)))->addClass(CSeverityHelper::getStyle($status)),
			$root_cause
		], $sla_cells, [
			(new CDiv($data['tags'][$row['serviceid']] ?? []))->addClass(ZBX_STYLE_TAGS_WRAPPER)
		])))
			->setAttribute('data-rowid', $row['rowid'])
			->setAttribute('data-parent-rowid', $row['parent_rowid'])
			->setAttribute('data-depth', $row['depth'])
			->setAttribute('data-serviceid', $row['serviceid'])
	);
}

$controls = (new CTag('nav', true,
	(new CList())
		->addItem(
			(new CRadioButtonList('servicetree_scale', 'full'))
				->addValue(_('Full scale'), 'full', 'servicetree-scale-full')
				->addValue(_('Zoom'), 'zoom', 'servicetree-scale-zoom')
				->setModern(true)
				->disableAutocomplete()
		)
		->addItem((new CSimpleButton(_('Expand all')))->addClass(ZBX_STYLE_BTN_ALT)->addClass('js-expand-all'))
		->addItem((new CSimpleButton(_('Collapse all')))->addClass(ZBX_STYLE_BTN_ALT)->addClass('js-collapse-all'))
		->addItem(get_icon('kioskmode', ['mode' => $web_layout_mode]))
))->setAttribute('aria-label', _('Content controls'));

(new CHtmlPage())
	->setTitle(_('Service tree'))
	->setWebLayoutMode($web_layout_mode)
	->setControls($controls)
	->addItem($filter)
	->addItem($table)
	->addItem(
		// Start loading SLA data immediately, in parallel with the rest of the page.
		new CScriptTag('window.servicetree_sla_request = fetch('.json_encode(
			(new CUrl('zabbix.php'))
				->setArgument('action', 'servicetree.list.sla')
				->setArgument('period', $data['filter']['period'])
				->setArgument('slaid', $data['filter']['slaid'] != 0 ? $data['filter']['slaid'] : null)
				->getUrl()
		).').then(response => response.json());')
	)
	->addItem(
		(new CDiv([
			(new CSpan())->addClass('servicetree-legend-box servicetree-bar-ok'), _('Uptime'),
			(new CSpan())->addClass('servicetree-legend-box servicetree-bar-problem'), _('Downtime'),
			(new CSpan())->addClass('servicetree-legend-box servicetree-bar-excluded'), _('Excluded downtime'),
			(new CSpan())->addClass('servicetree-legend-slo'), _('SLO'),
			(new CSpan(_('Bars show the current reporting period. Click a bar to open the status graph and problems.')))
				->addClass(ZBX_STYLE_GREY)
		]))->addClass('servicetree-legend')
	)
	->show();

(new CScriptTag('
	view.init('.json_encode([
		'is_filtered' => $data['is_filtered'],
		'period' => $data['filter']['period'],
		'slaid' => $data['filter']['slaid'],
		'strings' => [
			'Uptime' => _('Uptime'),
			'Downtime' => _('Downtime'),
			'Excluded downtime' => _('Excluded downtime'),
			'Overridden by filter' => _('Overridden by filter'),
			'Click' => _('Click to open status graph and problems'),
			'Loading failed' => _('Failed to load SLA data.')
		]
	]).');
'))
	->setOnDocumentReady()
	->show();
