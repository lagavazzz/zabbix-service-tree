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
** SLA popup: KPIs, "State over period" / "SLI per period" graphs (drag to zoom) and the paged list of problems.
** Problems are loaded with a separate request after the graph is shown.
** All dynamic text is inserted with textContent to avoid HTML injection.
*/

class ServiceTreePopup {

	static SVG_NS = 'http://www.w3.org/2000/svg';
	static STORAGE_VIEW = 'servicetree.popup.view';
	static DRAG_THRESHOLD = 4;

	constructor(container, data, {strings}) {
		this.container = container;
		this.t = key => strings[key] ?? key;
		this.view = ServiceTreePopup._load(ServiceTreePopup.STORAGE_VIEW, 'state');
		this.loading = false;
		this.problems = null;
		this.problems_key = null;
		this.problems_request = null;

		this._setData(data);
		this.render();
	}

	_setData(data) {
		this.data = data;
		this.zoom_state = null;		// {from, to} clock range of the state chart
		this.zoom_sli = null;		// {start, end} period indexes of the SLI chart
		this.page = 1;
	}

	// --- Data ---------------------------------------------------------------------------------------------------

	_url(params) {
		const url = new URL('zabbix.php', location.href);

		url.searchParams.set('action', 'servicetree.popup.data');

		for (const [key, value] of Object.entries(params)) {
			if (value !== null && value !== undefined) {
				url.searchParams.set(key, value);
			}
		}

		return url;
	}

	reload(changes = {}) {
		if (this.loading) {
			return;
		}

		const url = this._url({
			part: 'main',
			serviceid: this.data.service.serviceid,
			slaid: this.data.sla.slaid,
			period: this.data.period_override,
			period_count: this.data.period_count,
			period_index: this.data.selected,
			...changes
		});

		this.loading = true;
		this.container.classList.add('is-loading', 'is-loading-fadein');

		fetch(url)
			.then(response => response.json())
			.then(response => {
				if ('error' in response) {
					throw {messages: response.error.messages};
				}

				this._setData(response.data);
				this.render();
			})
			.catch(exception => this._showError(exception))
			.finally(() => {
				this.loading = false;
				this.container.classList.remove('is-loading', 'is-loading-fadein');
			});
	}

	/**
	 * Time range of the problem list: the selected period, or the range selected with the mouse (zoom).
	 */
	_problemsRange() {
		const d = this.data;
		const now = Math.floor(Date.now() / 1000);

		if (this.view === 'state' && this.zoom_state !== null) {
			return {
				from: Math.max(this.zoom_state.from, d.problems_from),
				to: Math.max(Math.min(this.zoom_state.to, d.problems_to), d.problems_from + 1),
				zoomed: true
			};
		}

		if (this.view === 'sli' && this.zoom_sli !== null) {
			const from = d.periods[this.zoom_sli.start].period_from;

			return {
				from,
				to: Math.max(Math.min(d.periods[this.zoom_sli.end].period_to, now), from + 1),
				zoomed: true
			};
		}

		return {from: d.problems_from, to: d.problems_to, zoomed: false};
	}

	_loadProblems() {
		const d = this.data;
		const range = this._problemsRange();
		const key = `${d.service.serviceid}:${range.from}:${range.to}`;

		if (key === this.problems_key) {
			this._renderProblemsSection();

			return;
		}

		this.problems_key = key;
		this.problems = null;
		this.page = 1;
		this._renderProblemsSection();

		if (this.problems_request !== null) {
			this.problems_request.abort();
		}

		const controller = new AbortController();
		this.problems_request = controller;

		fetch(this._url({part: 'problems', serviceid: d.service.serviceid, from: range.from, to: range.to}),
			{signal: controller.signal}
		)
			.then(response => response.json())
			.then(response => {
				if ('error' in response) {
					throw {messages: response.error.messages};
				}

				if (key === this.problems_key) {
					this.problems = response.data;
					this._renderProblemsSection();
				}
			})
			.catch(exception => {
				if (exception?.name !== 'AbortError' && key === this.problems_key) {
					this.problems = {problems: [], limited: false, error: exception.messages ?? [String(exception)]};
					this._renderProblemsSection();
				}
			})
			.finally(() => {
				if (this.problems_request === controller) {
					this.problems_request = null;
				}
			});
	}

	_showError(exception) {
		const messages = exception.messages ?? [String(exception)];
		const box = typeof makeMessageBox === 'function'
			? makeMessageBox('bad', messages, null, true)[0]
			: ServiceTreePopup._el('div', {class: 'msg-bad'}, messages.join('\n'));

		this.container.querySelector('.servicetree-popup-messages')?.replaceChildren(box);
	}

	// --- Rendering ----------------------------------------------------------------------------------------------

	render() {
		const d = this.data;
		const period = d.periods[d.selected];
		const el = ServiceTreePopup._el;

		this.chart_title = el('div', {class: 'servicetree-section-title servicetree-chart-title'});
		this.chart = el('div', {class: 'servicetree-chart'});
		this.problems_title = el('div', {class: 'servicetree-section-title servicetree-chart-title'});
		this.problems_section = el('div', {class: 'servicetree-problems-section'});

		this.container.replaceChildren(
			el('div', {class: 'servicetree-popup-messages'}),
			this._renderTopBar(),
			this._renderKpis(period),
			this._renderGraphControls(),
			this.chart_title,
			this.chart,
			el('div', {class: 'servicetree-hint'}, this.view === 'sli' ? this.t('Hint SLI') : this.t('Hint state')),
			this.problems_title,
			this.problems_section
		);

		this._renderChartSection();
		this._loadProblems();
	}

	_renderChartSection() {
		const d = this.data;
		const period = d.periods[d.selected];
		const el = ServiceTreePopup._el;

		const zoomed = this.view === 'sli' ? this.zoom_sli !== null : this.zoom_state !== null;

		ServiceTreePopup._replace(this.chart_title,
			el('span', {}, this.view === 'sli'
				? `${this.t('SLI per period')} · ${d.sla.period_name} · ${this.t('SLO')} ${d.sla.slo_text}`
				: `${this.t('Service state over period')} · ${period.label}`
			),
			zoomed ? this._zoomLabel() : null,
			zoomed ? this._button(this.t('Reset zoom'), () => this._resetZoom(), 'servicetree-reset-zoom') : null
		);

		ServiceTreePopup._replace(this.chart,this.view === 'sli' ? this._renderSliChart() : this._renderStateChart());
	}

	_zoomLabel() {
		const el = ServiceTreePopup._el;

		if (this.view === 'sli') {
			const periods = this.data.periods;

			return el('span', {class: 'servicetree-zoom-label'},
				`${this.t('Zoom')}: ${periods[this.zoom_sli.start].short_label} – ${periods[this.zoom_sli.end].short_label}`
			);
		}

		const format = this._dateFormatter(this.zoom_state.to - this.zoom_state.from, true);

		return el('span', {class: 'servicetree-zoom-label'},
			`${this.t('Zoom')}: ${format(this.zoom_state.from)} – ${format(this.zoom_state.to)}`
		);
	}

	_resetZoom() {
		if (this.view === 'sli') {
			this.zoom_sli = null;
		}
		else {
			this.zoom_state = null;
		}

		this._loadProblems();
		this._renderChartSection();
	}

	_button(label, action, cls = '') {
		const button = ServiceTreePopup._el('button', {type: 'button', class: `btn-link ${cls}`}, label);
		button.addEventListener('click', action);

		return button;
	}

	_renderTopBar() {
		const d = this.data;
		const el = ServiceTreePopup._el;

		const sla_select = el('select', {class: 'servicetree-select', 'aria-label': this.t('SLA')});

		for (const sla of d.service_slas) {
			const option = el('option', {value: sla.slaid}, sla.name);
			option.selected = sla.slaid == d.sla.slaid;
			sla_select.append(option);
		}

		sla_select.addEventListener('change', () => this.reload({slaid: sla_select.value, period_index: null}));

		const period_values = [[-1, this.t('SLA default')],
			...Object.entries(d.period_names).map(([value, name]) => [Number(value), name])
		];

		const links = el('span', {class: 'servicetree-links'},
			el('a', {href: d.service.url, target: '_blank'}, this.t('Open service')),
			d.sla.report_url !== null ? el('a', {href: d.sla.report_url, target: '_blank'}, this.t('Open SLA report')) : null
		);

		return el('div', {class: 'servicetree-toolbar'},
			el('span', {class: 'servicetree-toolbar-group'},
				el('label', {}, this.t('SLA')), sla_select
			),
			el('span', {class: 'servicetree-toolbar-group'},
				el('label', {}, this.t('Reporting period')),
				this._segmented(period_values, d.period_override,
					value => this.reload({period: value, period_index: null})
				)
			),
			links
		);
	}

	_renderKpis(period) {
		const d = this.data;
		const el = ServiceTreePopup._el;

		const sli_class = period.sli == -1 ? 'grey' : (period.sli >= d.sla.slo ? 'green' : 'red');

		const tile = (label, value, cls = '') => el('div', {class: 'servicetree-kpi'},
			el('span', {class: 'servicetree-kpi-label'}, label),
			el('span', {class: `servicetree-kpi-value ${cls}`}, value)
		);

		return el('div', {},
			el('div', {class: 'servicetree-period-caption'},
				el('strong', {}, `${d.sla.name} · ${period.label}`),
				period.is_current ? el('span', {class: 'servicetree-badge'}, this.t('current')) : null,
				d.sla.overridden ? el('span', {class: 'servicetree-note'}, `${d.sla.period_name}: ${this.t('Overridden')}`) : null
			),
			el('div', {class: 'servicetree-kpis'},
				tile(this.t('SLI'), period.sli_text, sli_class),
				tile(this.t('SLO'), d.sla.slo_text),
				tile(this.t('Uptime'), period.uptime_text),
				tile(this.t('Downtime'), period.downtime_text, period.downtime > 0 ? 'red' : ''),
				tile(this.t('Excluded downtime'), period.excluded_text, 'grey'),
				tile(this.t('Error budget'), period.error_budget_text, period.error_budget < 0 ? 'red' : '')
			)
		);
	}

	_renderGraphControls() {
		const d = this.data;
		const el = ServiceTreePopup._el;

		const period_select = el('select', {class: 'servicetree-select', 'aria-label': this.t('Period')});

		d.periods.forEach((period, index) => {
			const option = el('option', {value: index}, period.label + (period.is_current ? ' *' : ''));
			option.selected = index === d.selected;
			period_select.append(option);
		});

		period_select.addEventListener('change', () => this.reload({period_index: period_select.value}));

		return el('div', {class: 'servicetree-toolbar'},
			el('span', {class: 'servicetree-toolbar-group'},
				el('label', {}, this.t('Graph')),
				this._segmented([['state', this.t('State over period')], ['sli', this.t('SLI per period')]], this.view,
					value => {
						this.view = value;
						ServiceTreePopup._save(ServiceTreePopup.STORAGE_VIEW, value);
						this.zoom_state = null;
						this.zoom_sli = null;
						this.render();
					}
				)
			),
			this.view === 'sli'
				? el('span', {class: 'servicetree-toolbar-group'},
					el('label', {}, this.t('Show')),
					this._segmented(d.period_counts.map(count => [count, String(count)]), d.period_count,
						value => this.reload({period_count: value, period_index: null})
					),
					el('span', {class: 'grey'}, this.t('periods'))
				)
				: null,
			el('span', {class: 'servicetree-toolbar-group'},
				el('label', {}, this.t('Period')), period_select
			)
		);
	}

	_segmented(values, current, on_change) {
		const el = ServiceTreePopup._el;
		const group = el('span', {class: 'servicetree-segmented', role: 'group'});

		for (const [value, label] of values) {
			const button = el('button', {type: 'button', 'aria-pressed': String(value == current)}, label);

			if (value == current) {
				button.classList.add('is-selected');
			}

			button.addEventListener('click', () => {
				if (value != current) {
					on_change(value);
				}
			});

			group.append(button);
		}

		return group;
	}

	// --- State chart --------------------------------------------------------------------------------------------

	_renderStateChart() {
		const d = this.data;
		const tl = d.timeline;
		const s = ServiceTreePopup._svg;

		const W = 1000, H = 230, L = 96, R = 12, T = 10;
		const plot_h = 140, band_y = T + plot_h + 10, band_h = 14;
		const plot_w = W - L - R;
		const levels = [-1, 0, 1, 2, 3, 4, 5];

		const view_from = this.zoom_state?.from ?? tl.from;
		const view_to = this.zoom_state?.to ?? tl.period_to;
		const span = Math.max(1, view_to - view_from);

		const x = clock => L + (clock - view_from) / span * plot_w;
		const y = value => T + plot_h - (levels.indexOf(value) / (levels.length - 1)) * plot_h;
		const color = value => d.severities[value]?.color ?? '#97aab3';

		const svg = s('svg', {viewBox: `0 0 ${W} ${H}`, class: 'servicetree-svg servicetree-zoomable', role: 'img'});

		for (const level of levels) {
			svg.append(
				s('line', {x1: L, x2: W - R, y1: y(level), y2: y(level), class: 'servicetree-grid'}),
				s('text', {x: L - 8, y: y(level) + 4, 'text-anchor': 'end', class: 'servicetree-axis'},
					d.severities[level].name
				)
			);
		}

		// Segments [from, to, value], clipped to the visible window.
		const segments = [];
		let clock = tl.from;
		let value = tl.start_value;

		const push = (from, to, v) => {
			from = Math.max(from, view_from);
			to = Math.min(to, view_to);

			if (to > from) {
				segments.push([from, to, v]);
			}
		};

		for (const alarm of tl.alarms) {
			if (alarm.clock > clock) {
				push(clock, alarm.clock, value);
			}

			clock = Math.max(clock, alarm.clock);
			value = alarm.value;
		}

		if (tl.to > clock) {
			push(clock, tl.to, value);
		}

		// Merge runs of segments narrower than one pixel (keeping the worst state) - keeps long timelines fast.
		const merged = [];
		const min_width = span / plot_w;

		for (const segment of segments) {
			const last = merged[merged.length - 1];

			if (last !== undefined && last[1] - last[0] < min_width && segment[1] - segment[0] < min_width) {
				last[1] = segment[1];
				last[2] = Math.max(last[2], segment[2]);
			}
			else {
				merged.push([...segment]);
			}
		}

		if (merged.length > 0) {
			let path = '';

			merged.forEach(([from, to, v], i) => {
				path += `${i === 0 ? 'M' : 'L'}${x(from).toFixed(1)},${y(v)}L${x(to).toFixed(1)},${y(v)}`;
			});

			const base = y(-1);

			svg.append(
				s('path', {
					d: `${path}L${x(merged[merged.length - 1][1]).toFixed(1)},${base}L${x(merged[0][0]).toFixed(1)},${base}Z`,
					class: 'servicetree-area'
				}),
				s('path', {d: path, class: 'servicetree-line'})
			);
		}

		svg.append(s('text', {x: L - 8, y: band_y + band_h - 3, 'text-anchor': 'end', class: 'servicetree-axis'},
			this.t('State')
		));

		const format_full = this._dateFormatter(0, true);

		for (const [from, to, v] of merged) {
			const rect = s('rect', {
				x: x(from), y: band_y, width: Math.max(1, x(to) - x(from)), height: band_h, fill: color(v)
			});

			rect.append(s('title', {}, `${d.severities[v].name}: ${ServiceTreePopup._formatDuration(to - from)}\n`
				+ `${format_full(from)} – ${format_full(to)}`
			));
			svg.append(rect);
		}

		// Remaining (future) part of the current period.
		if (tl.to < view_to) {
			const from = Math.max(tl.to, view_from);

			svg.append(s('rect', {
				x: x(from), y: T, width: x(view_to) - x(from), height: band_y + band_h - T,
				class: 'servicetree-future'
			}));
		}

		// Time axis in the SLA time zone.
		const tick_count = 7;
		const format = this._dateFormatter(span);

		for (let i = 0; i < tick_count; i++) {
			const tick = view_from + span * i / (tick_count - 1);

			svg.append(s('text', {
				x: x(tick), y: H - 6, class: 'servicetree-axis',
				'text-anchor': i === 0 ? 'start' : (i === tick_count - 1 ? 'end' : 'middle')
			}, format(tick)));
		}

		this._attachZoom(svg, {
			left: L, right: W - R, top: T, bottom: band_y + band_h,
			onZoom: (x1, x2) => {
				const from = Math.round(view_from + (x1 - L) / plot_w * span);
				const to = Math.round(view_from + (x2 - L) / plot_w * span);

				if (to - from >= 60) {
					this.zoom_state = {from, to};
					this._renderChartSection();
					this._loadProblems();
				}
			}
		});

		return svg;
	}

	_dateFormatter(span, full = false, timezone = this.data.timezone) {
		const options = full || span <= 7 * 86400
			? (full || span > 86400
				? {month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false}
				: {hour: '2-digit', minute: '2-digit', hour12: false})
			: (span <= 400 * 86400
				? {month: 'short', day: '2-digit'}
				: {month: 'short', year: 'numeric'});

		let formatter;

		try {
			formatter = new Intl.DateTimeFormat(undefined, {...options, timeZone: timezone});
		}
		catch (e) {
			formatter = new Intl.DateTimeFormat(undefined, options);
		}

		return clock => formatter.format(new Date(clock * 1000));
	}

	// --- SLI chart ----------------------------------------------------------------------------------------------

	_renderSliChart() {
		const d = this.data;
		const s = ServiceTreePopup._svg;

		const W = 1000, H = 250, L = 64, R = 12, T = 16, B = 44;
		const plot_w = W - L - R, plot_h = H - T - B;

		const start = this.zoom_sli?.start ?? 0;
		const end = this.zoom_sli?.end ?? d.periods.length - 1;
		const periods = d.periods.slice(start, end + 1);
		const valid = periods.filter(p => p.sli != -1).map(p => p.sli);

		const {min: y_min, step} = ServiceTreePopup.niceAxis(Math.min(d.sla.slo, ...valid));
		const y = value => T + plot_h - (Math.max(value, y_min) - y_min) / (100 - y_min) * plot_h;

		const svg = s('svg', {viewBox: `0 0 ${W} ${H}`, class: 'servicetree-svg servicetree-zoomable', role: 'img'});

		for (let value = y_min; value <= 100.0000001; value += step) {
			svg.append(
				s('line', {x1: L, x2: W - R, y1: y(value), y2: y(value), class: 'servicetree-grid'}),
				s('text', {x: L - 6, y: y(value) + 4, 'text-anchor': 'end', class: 'servicetree-axis'},
					`${Math.round(value * 1000) / 1000}%`
				)
			);
		}

		const column_w = plot_w / periods.length;
		const bar_w = Math.min(64, column_w * 0.64);
		const show_every = Math.ceil(periods.length / 12);

		periods.forEach((period, i) => {
			const index = start + i;
			const cx = L + column_w * i + column_w / 2;
			const g = s('g', {class: 'servicetree-column', tabindex: 0, role: 'button', 'data-index': index});

			g.append(s('rect', {
				x: L + column_w * i + 1, y: T - 8, width: column_w - 2, height: plot_h + B + 2,
				class: index === d.selected ? 'servicetree-column-bg is-selected' : 'servicetree-column-bg'
			}));

			if (period.sli != -1) {
				const breach = period.sli < d.sla.slo;

				g.append(
					s('rect', {x: cx - bar_w / 2, y: T, width: bar_w, height: y(period.sli) - T,
						class: breach ? 'servicetree-col-rest is-breach' : 'servicetree-col-rest'
					}),
					s('rect', {x: cx - bar_w / 2, y: y(period.sli), width: bar_w, height: T + plot_h - y(period.sli),
						class: breach ? 'servicetree-col-bad' : 'servicetree-col-ok'
					})
				);
			}

			if (i % show_every === 0 || index === d.selected) {
				g.append(
					s('text', {x: cx, y: H - 24, 'text-anchor': 'middle', class: 'servicetree-axis'},
						period.short_label + (period.is_current ? '*' : '')
					),
					s('text', {x: cx, y: H - 8, 'text-anchor': 'middle',
						class: period.sli == -1
							? 'servicetree-axis'
							: (period.sli < d.sla.slo ? 'servicetree-value-bad' : 'servicetree-value')
					}, period.sli_text)
				);
			}

			g.append(s('title', {}, `${period.label}\n${this.t('SLI')}: ${period.sli_text}\n`
				+ `${this.t('Uptime')}: ${period.uptime_text}\n${this.t('Downtime')}: ${period.downtime_text}\n`
				+ `${this.t('Error budget')}: ${period.error_budget_text}`
			));

			g.addEventListener('keydown', e => {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					this.reload({period_index: index});
				}
			});

			svg.append(g);
		});

		const slo_y = y(d.sla.slo);

		svg.append(
			s('line', {x1: L, x2: W - R, y1: slo_y, y2: slo_y, class: 'servicetree-slo-line'}),
			s('text', {x: W - R - 4, y: slo_y - 5, 'text-anchor': 'end', class: 'servicetree-slo-label'},
				`${this.t('SLO')} ${d.sla.slo_text}`
			)
		);

		const column_at = px => start + Math.max(0, Math.min(periods.length - 1, Math.floor((px - L) / column_w)));

		this._attachZoom(svg, {
			left: L, right: W - R, top: T - 8, bottom: H,
			onClick: px => this.reload({period_index: column_at(px)}),
			onZoom: (x1, x2) => {
				const first = column_at(x1);
				const last = column_at(x2);

				if (last > first) {
					this.zoom_sli = {start: first, end: last};
					this._renderChartSection();
					this._loadProblems();
				}
			}
		});

		return svg;
	}

	// --- Mouse selection zoom -----------------------------------------------------------------------------------

	/**
	 * Drag horizontally inside the plot to zoom; double-click resets. A click without dragging calls onClick.
	 */
	_attachZoom(svg, {left, right, top, bottom, onZoom, onClick = null}) {
		const s = ServiceTreePopup._svg;
		const selection = s('rect', {class: 'servicetree-zoom-selection', y: top, height: bottom - top, x: 0, width: 0,
			visibility: 'hidden'
		});

		svg.append(selection);

		const to_svg_x = event => {
			const matrix = svg.getScreenCTM();

			if (matrix === null) {
				return left;
			}

			const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());

			return Math.max(left, Math.min(right, point.x));
		};

		let start_x = null;
		let start_client_x = 0;

		const on_move = event => {
			if (start_x === null) {
				return;
			}

			const current = to_svg_x(event);

			selection.setAttribute('x', Math.min(start_x, current));
			selection.setAttribute('width', Math.abs(current - start_x));
			selection.setAttribute('visibility',
				Math.abs(event.clientX - start_client_x) >= ServiceTreePopup.DRAG_THRESHOLD ? 'visible' : 'hidden'
			);
		};

		const on_up = event => {
			document.removeEventListener('mousemove', on_move);
			document.removeEventListener('mouseup', on_up);

			if (start_x === null) {
				return;
			}

			const end_x = to_svg_x(event);
			const dragged = Math.abs(event.clientX - start_client_x) >= ServiceTreePopup.DRAG_THRESHOLD;

			selection.setAttribute('visibility', 'hidden');

			const from_x = Math.min(start_x, end_x);
			const to_x = Math.max(start_x, end_x);

			start_x = null;

			if (dragged) {
				onZoom(from_x, to_x);
			}
			else if (onClick !== null) {
				onClick(end_x);
			}
		};

		svg.addEventListener('mousedown', event => {
			if (event.button !== 0) {
				return;
			}

			const point_x = to_svg_x(event);
			const matrix = svg.getScreenCTM();
			const point_y = matrix !== null
				? new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse()).y
				: top;

			if (point_y < top || point_y > bottom) {
				return;
			}

			event.preventDefault();
			start_x = point_x;
			start_client_x = event.clientX;

			document.addEventListener('mousemove', on_move);
			document.addEventListener('mouseup', on_up);
		});

		svg.addEventListener('dblclick', event => {
			event.preventDefault();

			if (this.zoom_state !== null || this.zoom_sli !== null) {
				this._resetZoom();
			}
		});
	}

	// --- Problems -----------------------------------------------------------------------------------------------

	_renderProblemsSection() {
		const d = this.data;
		const el = ServiceTreePopup._el;
		const period = d.periods[d.selected];

		const range = this._problemsRange();
		// Same time zone as the problem times in the table (user's time zone).
		const range_format = this._dateFormatter(0, true, d.user_timezone ?? d.timezone);

		ServiceTreePopup._replace(this.problems_title,
			el('span', {}, `${this.t('Problems')} · ${range.zoomed
				? `${range_format(range.from)} – ${range_format(range.to)}`
				: period.label
			}`),
			range.zoomed ? el('span', {class: 'servicetree-zoom-label'}, this.t('Filtered by zoom')) : null
		);

		if (this.problems === null) {
			ServiceTreePopup._replace(this.problems_section,
				el('div', {class: 'servicetree-problems-loading is-loading'})
			);

			return;
		}

		if (this.problems.error) {
			ServiceTreePopup._replace(this.problems_section, el('div', {class: 'red'}, this.problems.error.join('\n')));

			return;
		}

		const problems = this.problems.problems;

		if (problems.length === 0) {
			ServiceTreePopup._replace(this.problems_section,el('div', {class: 'servicetree-empty'}, this.t('No problems')));

			return;
		}

		const per_page = Math.max(1, d.problems_per_page || 20);
		const pages = Math.ceil(problems.length / per_page);

		this.page = Math.min(Math.max(1, this.page), pages);

		const first = (this.page - 1) * per_page;
		const page_items = problems.slice(first, first + per_page);

		const table = el('table', {class: 'list-table servicetree-problems'},
			el('thead', {}, el('tr', {},
				...['Time', 'Recovery time', 'Status', 'Service', 'Host', 'Problem', 'Severity', 'Duration']
					.map(key => el('th', {}, this.t(key)))
			))
		);

		const tbody = el('tbody');

		for (const problem of page_items) {
			const severity = d.severities[problem.severity];

			tbody.append(el('tr', {},
				el('td', {class: 'nowrap'}, problem.time),
				el('td', {class: 'nowrap'}, problem.r_time),
				el('td', {class: problem.resolved ? 'green' : 'red'},
					problem.resolved ? this.t('RESOLVED') : this.t('PROBLEM')
				),
				el('td', {}, problem.services.join(', ')),
				el('td', {}, problem.hosts.join(', ')),
				el('td', {}, problem.url !== null
					? el('a', {href: problem.url, target: '_blank'}, problem.name)
					: problem.name
				),
				el('td', {class: 'servicetree-severity', style: `background-color: ${severity.color}`}, severity.name),
				el('td', {class: 'nowrap'}, problem.duration)
			));
		}

		table.append(tbody);

		ServiceTreePopup._replace(this.problems_section,
			el('div', {class: 'servicetree-problems-wrap'}, table),
			this._renderPager(problems.length, pages, first, page_items.length)
		);
	}

	_renderPager(total, pages, first, count) {
		const el = ServiceTreePopup._el;

		let info = this.t('Displaying %1$s to %2$s of %3$s found')
			.replace('%1$s', String(first + 1))
			.replace('%2$s', String(first + count))
			.replace('%3$s', String(total));

		if (this.problems.limited) {
			info += ` (${this.t('latest %1$s shown').replace('%1$s', String(this.problems.max_problems))})`;
		}

		const nav = el('span', {class: 'servicetree-pager-buttons'});

		if (pages > 1) {
			const go = page => () => {
				this.page = page;
				this._renderProblemsSection();
			};

			const add = (label, page, disabled = false, current = false) => {
				const button = el('button', {type: 'button', class: current ? 'is-selected' : ''}, label);

				button.disabled = disabled;

				if (!disabled && !current) {
					button.addEventListener('click', go(page));
				}

				nav.append(button);
			};

			add('‹', this.page - 1, this.page === 1);

			for (let page = 1; page <= pages; page++) {
				add(String(page), page, false, page === this.page);
			}

			add('›', this.page + 1, this.page === pages);
		}

		return el('div', {class: 'servicetree-pager'}, el('span', {class: 'grey'}, info), nav);
	}

	// --- Helpers ------------------------------------------------------------------------------------------------

	/**
	 * Percentage axis that ends at 100% and starts at a "nice" value below $lowest, with 2-6 grid steps.
	 */
	static niceAxis(lowest) {
		const range = Math.max(100 - lowest, 0.004) * 1.2;
		const steps = [0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.25, 0.5, 1, 2, 5, 10, 20, 25];
		const step = steps.find(candidate => candidate * 5 >= range) ?? 25;
		const min = Math.max(0, Math.round((100 - Math.ceil(range / step - 1e-9) * step) * 1000) / 1000);

		return {min, step};
	}

	static _replace(node, ...children) {
		node.replaceChildren(...children.filter(child => child !== null && child !== undefined));
	}

	static _el(tag, attributes = {}, ...children) {
		const node = document.createElement(tag);

		for (const [name, value] of Object.entries(attributes)) {
			node.setAttribute(name, value);
		}

		for (const child of children) {
			if (child !== null && child !== undefined) {
				node.append(child instanceof Node ? child : String(child));
			}
		}

		return node;
	}

	static _svg(tag, attributes = {}, text = null) {
		const node = document.createElementNS(ServiceTreePopup.SVG_NS, tag);

		for (const [name, value] of Object.entries(attributes)) {
			node.setAttribute(name, value);
		}

		if (text !== null) {
			node.textContent = text;
		}

		return node;
	}

	static _formatDuration(seconds) {
		const units = [['d', 86400], ['h', 3600], ['m', 60], ['s', 1]];
		const parts = [];

		for (const [unit, size] of units) {
			if (seconds >= size && parts.length < 3) {
				parts.push(`${Math.floor(seconds / size)}${unit}`);
				seconds %= size;
			}
		}

		return parts.length > 0 ? parts.join(' ') : '0s';
	}

	static _load(key, fallback) {
		try {
			const value = localStorage.getItem(key);

			return value !== null ? JSON.parse(value) : fallback;
		}
		catch (e) {
			return fallback;
		}
	}

	static _save(key, value) {
		try {
			localStorage.setItem(key, JSON.stringify(value));
		}
		catch (e) {
			// Storage unavailable.
		}
	}
}
