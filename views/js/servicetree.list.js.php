<?php
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
*/
?>

<script>
	const view = new class {

		STORAGE_STATE = 'servicetree.expanded';
		STORAGE_SCALE = 'servicetree.scale';
		DEFAULT_EXPANDED_DEPTH = 2;

		init({is_filtered, period, slaid, strings}) {
			this.table = document.getElementById('servicetree-table');
			this.is_filtered = is_filtered;
			this.period = period;
			this.slaid = slaid;
			this.strings = strings;

			if (this.table === null) {
				return;
			}

			this.rows = [...this.table.querySelectorAll('tr[data-rowid]')];
			this.rows_by_id = new Map(this.rows.map(row => [row.dataset.rowid, row]));
			this.children = new Map();

			for (const row of this.rows) {
				const parent = row.dataset.parentRowid;

				if (!this.children.has(parent)) {
					this.children.set(parent, []);
				}

				this.children.get(parent).push(row);
			}

			this.state = this.is_filtered ? {} : this._load(this.STORAGE_STATE, {});

			for (const row of this.rows) {
				if (row.querySelector('.js-toggle') !== null) {
					this._setExpanded(row, this._isExpanded(row), false);
				}
			}

			this._applyVisibility();

			this.scale = this._load(this.STORAGE_SCALE, 'full');
			const scale_input = document.querySelector(`[name="servicetree_scale"][value="${this.scale}"]`);

			if (scale_input !== null) {
				scale_input.checked = true;
			}

			this._registerEvents();
			this.loadSla();
		}

		/**
		 * SLA columns are loaded after the tree is shown, so the page is usable immediately.
		 */
		/**
		 * SLA columns are loaded after the tree is shown, so the page is usable immediately. The request itself is
		 * started by an inline script right after the table markup (window.servicetree_sla_request), i.e. while the
		 * rest of the page is still loading.
		 */
		loadSla() {
			const request = window.servicetree_sla_request
				?? fetch(view.slaUrl(this.period, this.slaid)).then(response => response.json());

			request
				.then(response => {
					if ('error' in response) {
						throw new Error(response.error.messages.join('\n'));
					}

					this._fillSla(response.data.services);
				})
				.catch(exception => {
					console.error(exception);

					for (const cell of this.table.querySelectorAll('.js-sla-bar-cell')) {
						cell.textContent = this.strings['Loading failed'];
						cell.classList.add('grey');
					}
				});
		}

		slaUrl(period, slaid) {
			const url = new URL('zabbix.php', location.href);

			url.searchParams.set('action', 'servicetree.list.sla');
			url.searchParams.set('period', period);

			if (slaid != 0) {
				url.searchParams.set('slaid', slaid);
			}

			return url;
		}

		_fillSla(services) {
			// Column order: 0 name, 1 status, 2 root cause, 3 SLA, 4 period, 5 SLO, 6 bar, 7 SLI, 8 downtime, 9 budget.
			const bar_template = document.createElement('div');

			bar_template.className = 'servicetree-bar js-sla-bar';
			bar_template.setAttribute('role', 'button');
			bar_template.tabIndex = 0;
			bar_template.innerHTML = '<span class="servicetree-bar-ok"></span><span class="servicetree-bar-excluded">'
				+ '</span><span class="servicetree-bar-problem"></span><span class="servicetree-bar-slo"></span>';

			const empty = document.createElement('span');
			empty.className = 'grey';
			empty.textContent = '–';

			for (const row of this.rows) {
				const slas = services[row.dataset.serviceid];
				const cells = row.cells;

				if (slas === undefined || slas.length === 0) {
					cells[3].replaceChildren(empty.cloneNode(true));
					cells[6].replaceChildren();
					continue;
				}

				const sla = this.slaid != 0 ? (slas.find(s => s.slaid == this.slaid) || slas[0]) : slas[0];

				cells[3].textContent = sla.name;

				if (slas.length > 1) {
					const more = document.createElement('span');

					more.className = 'grey';
					more.textContent = ` +${slas.length - 1}`;
					more.title = slas.filter(s => s !== sla).map(s => s.name).join(', ');
					cells[3].append(more);
				}

				if (sla.overridden) {
					const span = document.createElement('span');

					span.className = 'servicetree-overridden';
					span.textContent = sla.period_name;
					span.title = this.strings['Overridden by filter'];
					cells[4].replaceChildren(span);
				}
				else {
					cells[4].textContent = sla.period_name;
				}

				cells[5].textContent = sla.slo_text;

				const bar = bar_template.cloneNode(true);

				bar.dataset.serviceid = row.dataset.serviceid;
				bar.dataset.slaid = sla.slaid;
				bar.dataset.uptime = sla.uptime;
				bar.dataset.downtime = sla.downtime;
				bar.dataset.excluded = sla.excluded;
				bar.dataset.slo = sla.slo;
				bar.title = `${sla.period_label}\n${this.strings['Uptime']}: ${sla.uptime_text}\n`
					+ `${this.strings['Downtime']}: ${sla.downtime_text}\n`
					+ `${this.strings['Excluded downtime']}: ${sla.excluded_text}\n${this.strings['Click']}`;
				cells[6].replaceChildren(bar);

				cells[7].textContent = sla.sli_text;
				cells[7].className = `servicetree-num js-sla-sli ${sla.sli == -1 ? 'grey' : (sla.sli >= sla.slo ? 'green' : 'red')}`;

				cells[8].textContent = sla.downtime_text;
				cells[8].classList.toggle('grey', sla.downtime == 0);

				cells[9].textContent = sla.error_budget_text;
				cells[9].classList.toggle('grey', sla.error_budget >= 0);
				cells[9].classList.toggle('red', sla.error_budget < 0);
			}

			this._renderBars();
		}

		_registerEvents() {
			this.table.addEventListener('click', e => {
				const toggle = e.target.closest('.js-toggle');

				if (toggle !== null) {
					const row = toggle.closest('tr');
					this._setExpanded(row, !this._isExpanded(row));
					this._applyVisibility();

					return;
				}

				const bar = e.target.closest('.js-sla-bar');

				if (bar !== null) {
					this._openPopup(bar);
				}
			});

			this.table.addEventListener('keydown', e => {
				const bar = e.target.closest('.js-sla-bar');

				if (bar !== null && (e.key === 'Enter' || e.key === ' ')) {
					e.preventDefault();
					this._openPopup(bar);
				}
			});

			document.querySelector('.js-expand-all')?.addEventListener('click', () => this._setAll(true));
			document.querySelector('.js-collapse-all')?.addEventListener('click', () => this._setAll(false));

			for (const input of document.querySelectorAll('[name="servicetree_scale"]')) {
				input.addEventListener('change', () => {
					this.scale = input.value;
					this._save(this.STORAGE_SCALE, this.scale);
					this._renderBars();
				});
			}
		}

		_openPopup(bar) {
			PopUp('servicetree.popup', {
				serviceid: bar.dataset.serviceid,
				slaid: bar.dataset.slaid,
				period: this.period
			}, {
				dialogueid: 'servicetree-popup',
				dialogue_class: 'modal-popup-large servicetree-popup',
				trigger_element: bar
			});
		}

		_isExpanded(row) {
			const rowid = row.dataset.rowid;

			if (rowid in this.state) {
				return this.state[rowid];
			}

			return this.is_filtered || Number(row.dataset.depth) < this.DEFAULT_EXPANDED_DEPTH;
		}

		_setExpanded(row, expanded, persist = true) {
			const toggle = row.querySelector('.js-toggle');

			if (toggle === null) {
				return;
			}

			toggle.classList.toggle('zi-chevron-down', expanded);
			toggle.classList.toggle('zi-chevron-right', !expanded);
			toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			toggle.title = expanded ? <?= json_encode(_('Collapse')) ?> : <?= json_encode(_('Expand')) ?>;
			row.dataset.expanded = expanded ? '1' : '0';

			if (persist) {
				this.state[row.dataset.rowid] = expanded;

				if (!this.is_filtered) {
					this._save(this.STORAGE_STATE, this.state);
				}
			}
		}

		_setAll(expanded) {
			for (const row of this.rows) {
				this._setExpanded(row, expanded);
			}

			this._applyVisibility();
		}

		_applyVisibility() {
			const walk = (parent_rowid, visible) => {
				for (const row of this.children.get(parent_rowid) || []) {
					row.style.display = visible ? '' : 'none';
					walk(row.dataset.rowid, visible && row.dataset.expanded !== '0');
				}
			};

			walk('', true);
		}

		/**
		 * Bars are drawn as 100% of the elapsed (scheduled) time of the current period: uptime + excluded + downtime.
		 * In zoom mode the axis starts at a common minimum so that small downtimes become visible.
		 */
		_renderBars() {
			const bars = [...this.table.querySelectorAll('.js-sla-bar')].map(bar => {
				const uptime = Number(bar.dataset.uptime);
				const downtime = Number(bar.dataset.downtime);
				const excluded = Number(bar.dataset.excluded);
				const total = uptime + downtime + excluded;

				return {
					bar, total,
					ok: total > 0 ? uptime / total * 100 : 0,
					excluded: total > 0 ? excluded / total * 100 : 0,
					problem: total > 0 ? downtime / total * 100 : 0,
					slo: Number(bar.dataset.slo)
				};
			});

			let axis_min = 0;

			if (this.scale === 'zoom' && bars.length > 0) {
				const lowest = Math.min(...bars.filter(b => b.total > 0).map(b => Math.min(b.ok + b.excluded, b.slo)),
					100
				);
				axis_min = ServiceTreePopup.niceAxis(lowest).min;
			}

			const label = this.table.querySelector('.js-scale-label');

			if (label !== null) {
				label.textContent = this.scale === 'zoom' ? ` (${axis_min}–100%)` : ' (0–100%)';
			}

			const to_width = value => Math.max(0, (value - axis_min) / (100 - axis_min) * 100);

			for (const b of bars) {
				const ok_end = to_width(b.ok);
				const excluded_end = to_width(b.ok + b.excluded);

				const [ok, excluded, problem, slo] = b.bar.children;

				b.bar.classList.toggle('servicetree-bar-empty', b.total === 0);
				ok.style.width = `${ok_end}%`;
				excluded.style.width = `${excluded_end - ok_end}%`;
				problem.style.width = b.total > 0 ? `${100 - excluded_end}%` : '0';

				const slo_pos = to_width(b.slo);

				slo.style.left = `${slo_pos}%`;
				slo.style.display = b.slo >= axis_min ? '' : 'none';
			}
		}

		_load(key, fallback) {
			try {
				const value = localStorage.getItem(key);

				return value !== null ? JSON.parse(value) : fallback;
			}
			catch (e) {
				return fallback;
			}
		}

		_save(key, value) {
			try {
				localStorage.setItem(key, JSON.stringify(value));
			}
			catch (e) {
				// Storage unavailable - state is kept for this page view only.
			}
		}
	};
</script>
