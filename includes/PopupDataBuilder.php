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
	CRoleHelper,
	CSeverityHelper,
	CSlaHelper,
	CWebUser,
	Exception;

/**
 * Builds the data shown in the SLA popup: KPIs, SLI per period, status timeline and problems.
 */
class PopupDataBuilder {

	public const PERIOD_COUNTS = [6, 12, 24];

	/**
	 * @throws Exception
	 */
	public static function build(string $serviceid, string $slaid, int $period_override, int $period_count,
			?int $period_index): array {
		$services = API::Service()->get([
			'output' => ['serviceid', 'name', 'status'],
			'serviceids' => [$serviceid]
		]);

		if (!$services) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$service = $services[0];

		$slas = SliCalculator::getSlas([$slaid]);

		if (!$slas) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$sla = reset($slas);
		$timezone = SliCalculator::getTimezone($sla);

		$sli = SliCalculator::calculate($sla, $period_override, $period_count, [$serviceid]);

		if (!array_key_exists($serviceid, $sli['services']) || !$sli['periods']) {
			throw new Exception(_s('Service "%1$s" is not covered by SLA "%2$s".', $service['name'], $sla['name']));
		}

		$cells = $sli['services'][$serviceid];
		$now = time();

		$periods = [];

		foreach ($sli['periods'] as $index => $period) {
			$cell = $cells[$index];

			$periods[] = [
				'period_from' => $period['period_from'],
				'period_to' => $period['period_to'],
				'label' => SliCalculator::getPeriodLabel($sli['period'], $period['period_from'],
					$period['period_to'], $timezone
				),
				'short_label' => SliCalculator::getPeriodLabel($sli['period'], $period['period_from'],
					$period['period_to'], $timezone, true
				),
				'is_current' => $period['period_from'] <= $now && $now < $period['period_to'],
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

		$last_index = count($periods) - 1;
		$selected = $period_index === null ? $last_index : max(0, min($period_index, $last_index));
		$selected_period = $periods[$selected];

		$timeline_to = min($selected_period['period_to'], $now);

		// SLAs this service belongs to (for the SLA switch).
		$service_slas = API::Sla()->get([
			'output' => ['slaid', 'name'],
			'serviceids' => [$serviceid],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED]
		]);

		usort($service_slas, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		$period_names = CSlaHelper::getPeriodNames();

		return [
			'service' => [
				'serviceid' => $service['serviceid'],
				'name' => $service['name'],
				'status' => (int) $service['status'],
				'url' => (new \CUrl('zabbix.php'))
					->setArgument('action', 'service.list')
					->setArgument('serviceid', $service['serviceid'])
					->getUrl()
			],
			'sla' => [
				'slaid' => $sla['slaid'],
				'name' => $sla['name'],
				'slo' => (float) $sla['slo'],
				'slo_text' => (floor($sla['slo'] * 10000) / 10000).'%',
				'native_period' => (int) $sla['period'],
				'period' => $sli['period'],
				'period_name' => $period_names[$sli['period']],
				'overridden' => $sli['overridden'],
				'report_url' => CWebUser::checkAccess(CRoleHelper::UI_SERVICES_SLA_REPORT)
					? (new \CUrl('zabbix.php'))
						->setArgument('action', 'slareport.list')
						->setArgument('filter_slaid', $sla['slaid'])
						->setArgument('filter_serviceid', $service['serviceid'])
						->setArgument('filter_set', 1)
						->getUrl()
					: null
			],
			'service_slas' => $service_slas,
			'period_override' => $period_override,
			'period_names' => $period_names,
			'period_count' => $period_count,
			'period_counts' => self::PERIOD_COUNTS,
			'periods' => $periods,
			'selected' => $selected,
			'timezone' => $timezone,
			'user_timezone' => date_default_timezone_get(),
			'timeline' => self::getTimeline($serviceid, $selected_period['period_from'], $timeline_to,
				$selected_period['period_to']
			),
			'problems_from' => $selected_period['period_from'],
			'problems_to' => $timeline_to,
			'problems_per_page' => Config::get('problems_per_page'),
			'severities' => self::getSeverities()
		];
	}

	private static function duration(int $seconds): string {
		if ($seconds == 0) {
			return '0';
		}

		return convertUnitsS($seconds, ['ignore_milliseconds' => true]);
	}

	private static function getSeverities(): array {
		$severities = [
			ZBX_SEVERITY_OK => ['name' => CSeverityHelper::getName(ZBX_SEVERITY_OK), 'color' => '#59db8f']
		];

		for ($severity = TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity < TRIGGER_SEVERITY_COUNT; $severity++) {
			$severities[$severity] = [
				'name' => CSeverityHelper::getName($severity),
				'color' => '#'.CSeverityHelper::getColor($severity)
			];
		}

		return $severities;
	}

	/**
	 * Status changes of the service within the period (from service_alarms, via service.get selectStatusTimeline).
	 * Axis ticks are calculated in the browser (they change with zoom).
	 */
	private static function getTimeline(string $serviceid, int $from, int $to, int $period_to): array {
		$timeline = ['start_value' => ZBX_SEVERITY_OK, 'alarms' => []];

		if ($to > $from) {
			$services = API::Service()->get([
				'output' => ['serviceid'],
				'serviceids' => [$serviceid],
				'selectStatusTimeline' => [['period_from' => $from, 'period_to' => $to]]
			]);

			if ($services) {
				$timeline = $services[0]['status_timeline'][0];
			}
		}

		return [
			'from' => $from,
			'to' => $to,
			'period_to' => $period_to,
			'start_value' => (int) $timeline['start_value'],
			'alarms' => array_map(static fn(array $alarm): array => [
				'clock' => (int) $alarm['clock'],
				'value' => (int) $alarm['value']
			], $timeline['alarms'])
		];
	}

	/**
	 * Problems (trigger events) that match the problem tags of the service or any of its descendants and that were
	 * active at some point within [$from, $to). Newest first, at most "max_problems".
	 *
	 * Performance notes:
	 *  - every event query is bounded by time, so the events table is read through its clock index;
	 *  - services with a single problem tag (the usual case) are combined into one OR query per chunk of tags;
	 *  - problems that started before the period are taken from the (small) problem table if still active, and
	 *    from a bounded look-back window (problem_lookback_days) if they were resolved inside the period;
	 *  - hosts and recovery times are fetched only for the final list.
	 *
	 * @throws Exception
	 */
	public static function getProblems(string $serviceid, int $from, int $to): array {
		$max_problems = max(1, Config::get('max_problems'));

		$services = API::Service()->get([
			'output' => ['serviceid', 'name'],
			'selectProblemTags' => ['tag', 'operator', 'value'],
			'parentids' => [$serviceid],
			'deep_parentids' => true,
			'preservekeys' => true
		]);

		$services += API::Service()->get([
			'output' => ['serviceid', 'name'],
			'selectProblemTags' => ['tag', 'operator', 'value'],
			'serviceids' => [$serviceid],
			'preservekeys' => true
		]);

		if (!array_key_exists($serviceid, $services)) {
			throw new Exception(_('No permissions to referred object or it does not exist!'));
		}

		$services = array_filter($services, static fn(array $service): bool => (bool) $service['problem_tags']);
		$services_total = count($services);
		$services = array_slice($services, 0, max(1, Config::get('max_problem_services')), true);

		$result = [
			'problems' => [],
			'limited' => false,
			'services_limited' => $services_total > count($services),
			'max_problems' => $max_problems
		];

		if (!$services || $to <= $from) {
			return $result;
		}

		// Build tag groups: one OR group for single-tag services, one AND group per multi-tag service.
		$single_tags = [];
		$queries = [];

		foreach ($services as $service) {
			$tags = array_map([self::class, 'toEventTag'], $service['problem_tags']);

			if (count($tags) == 1) {
				$single_tags[json_encode($tags[0])] = $tags[0];
			}
			else {
				$queries[] = ['evaltype' => TAG_EVAL_TYPE_AND_OR, 'tags' => $tags];
			}
		}

		foreach (array_chunk(array_values($single_tags), max(1, Config::get('tags_per_query'))) as $chunk) {
			$queries[] = ['evaltype' => TAG_EVAL_TYPE_OR, 'tags' => $chunk];
		}

		$lookback_from = $from - max(0, Config::get('problem_lookback_days')) * SEC_PER_DAY;
		$output = ['eventid', 'objectid', 'clock', 'r_eventid', 'name', 'severity'];
		$candidates = [];

		foreach ($queries as $query) {
			$common = [
				'output' => $output,
				'selectTags' => ['tag', 'value'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'evaltype' => $query['evaltype'],
				'tags' => $query['tags'],
				'sortfield' => ['clock', 'eventid'],
				'sortorder' => ZBX_SORT_DOWN,
				'preservekeys' => true
			];

			// 1. Problems that started within the period.
			$events = API::Event()->get($common + [
				'value' => TRIGGER_VALUE_TRUE,
				'time_from' => $from,
				'time_till' => $to - 1,
				'limit' => $max_problems + 1
			]);

			if (count($events) > $max_problems) {
				$result['limited'] = true;
			}

			$candidates += $events;

			// 2. Still active problems that started before the period (problem table is small).
			$candidates += API::Problem()->get([
				'output' => $output,
				'selectTags' => ['tag', 'value'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'evaltype' => $query['evaltype'],
				'tags' => $query['tags'],
				'time_till' => $from - 1,
				'sortfield' => ['eventid'],
				'sortorder' => ZBX_SORT_DOWN,
				'limit' => $max_problems,
				'preservekeys' => true
			]);

			// 3. Problems that started shortly before the period and were resolved inside it.
			if ($lookback_from < $from) {
				$candidates += API::Event()->get($common + [
					'value' => TRIGGER_VALUE_TRUE,
					'time_from' => $lookback_from,
					'time_till' => $from - 1,
					'limit' => $max_problems
				]);
			}
		}

		if (!$candidates) {
			return $result;
		}

		// Recovery times.
		$r_eventids = array_values(array_unique(array_filter(array_column($candidates, 'r_eventid'))));
		$recovery = $r_eventids
			? API::Event()->get([
				'output' => ['eventid', 'clock'],
				'eventids' => $r_eventids,
				'preservekeys' => true
			])
			: [];

		$problems = [];

		foreach ($candidates as $eventid => $event) {
			$r_clock = ($event['r_eventid'] != 0 && array_key_exists($event['r_eventid'], $recovery))
				? (int) $recovery[$event['r_eventid']]['clock']
				: 0;

			// Keep only problems that overlap the period.
			if ($event['clock'] >= $to || ($r_clock != 0 && $r_clock < $from)) {
				continue;
			}

			$event['r_clock'] = $r_clock;
			$problems[$eventid] = $event;
		}

		uasort($problems, static fn(array $a, array $b): int =>
			[(int) $b['clock'], (int) $b['eventid']] <=> [(int) $a['clock'], (int) $a['eventid']]
		);

		if (count($problems) > $max_problems) {
			$result['limited'] = true;
			$problems = array_slice($problems, 0, $max_problems, true);
		}

		if (!$problems) {
			return $result;
		}

		// Hosts only for the final list.
		$hosts = API::Event()->get([
			'output' => ['eventid'],
			'selectHosts' => ['name'],
			'eventids' => array_keys($problems),
			'preservekeys' => true
		]);

		$can_view_problems = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
		$now = time();

		foreach ($problems as $eventid => $event) {
			$r_clock = $event['r_clock'];

			$result['problems'][] = [
				'eventid' => (string) $eventid,
				'clock' => (int) $event['clock'],
				'time' => zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']),
				'r_clock' => $r_clock,
				'r_time' => $r_clock != 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $r_clock) : '',
				'resolved' => $r_clock != 0,
				'duration' => zbx_date2age($event['clock'], $r_clock != 0 ? $r_clock : $now),
				'name' => $event['name'],
				'severity' => (int) $event['severity'],
				'hosts' => array_key_exists($eventid, $hosts) ? array_column($hosts[$eventid]['hosts'], 'name') : [],
				'services' => self::matchServices($event['tags'], $services),
				'url' => $can_view_problems
					? (new \CUrl('zabbix.php'))
						->setArgument('action', 'problem.view')
						->setArgument('filter_set', '1')
						->setArgument('triggerids', [$event['objectid']])
						->getUrl()
					: null
			];
		}

		return $result;
	}

	private static function toEventTag(array $tag): array {
		return [
			'tag' => $tag['tag'],
			'operator' => $tag['operator'] == ZBX_SERVICE_PROBLEM_TAG_OPERATOR_LIKE ? TAG_OPERATOR_LIKE : TAG_OPERATOR_EQUAL,
			'value' => $tag['value']
		];
	}

	/**
	 * Names of the services whose problem tags all match the event tags.
	 */
	private static function matchServices(array $event_tags, array $services): array {
		$names = [];

		foreach ($services as $service) {
			$matches = true;

			foreach ($service['problem_tags'] as $problem_tag) {
				$found = false;

				foreach ($event_tags as $event_tag) {
					if ($event_tag['tag'] !== $problem_tag['tag']) {
						continue;
					}

					$found = $problem_tag['operator'] == ZBX_SERVICE_PROBLEM_TAG_OPERATOR_LIKE
						? ($problem_tag['value'] === '' || mb_stripos($event_tag['value'], $problem_tag['value']) !== false)
						: $event_tag['value'] === $problem_tag['value'];

					if ($found) {
						break;
					}
				}

				if (!$found) {
					$matches = false;
					break;
				}
			}

			if ($matches) {
				$names[] = $service['name'];
			}
		}

		return $names;
	}
}
