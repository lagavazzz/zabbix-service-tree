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

namespace Modules\ServiceTree;

use APP,
	CMenuItem,
	CRoleHelper,
	CWebUser,
	Zabbix\Core\CModule;

class Module extends CModule {

	/**
	 * Adds "Service tree" to the Services menu, right after the built-in "Services" item.
	 */
	public function init(): void {
		if (!CWebUser::isLoggedIn() || !CWebUser::checkAccess(CRoleHelper::UI_SERVICES_SERVICES)) {
			return;
		}

		$services_menu = APP::Component()->get('menu.main')->find(_('Services'));

		if ($services_menu === null || !$services_menu->hasSubMenu()) {
			return;
		}

		$services_menu->getSubMenu()->insertAfter(_('Services'),
			(new CMenuItem(_('Service tree')))->setAction('servicetree.list')
		);
	}
}
