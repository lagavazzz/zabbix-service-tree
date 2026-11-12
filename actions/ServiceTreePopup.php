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

use CControllerResponseData,
	Exception;

/**
 * Opens the SLA popup (overlay dialogue). The popup renders client-side from the same data as servicetree.popup.data.
 */
class ServiceTreePopup extends ServiceTreePopupData {

	protected function doAction(): void {
		$data = [
			'serviceid' => $this->getInput('serviceid'),
			'slaid' => $this->getInput('slaid'),
			'data' => null,
			'error' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		try {
			$data['data'] = $this->buildData();
		}
		catch (Exception $e) {
			$data['error'] = $e->getMessage();
		}

		$this->setResponse(new CControllerResponseData($data));
	}
}
