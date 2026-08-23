<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

#[\AllowDynamicProperties]
class CommonGLPI {}

#[\AllowDynamicProperties]
class CommonDBTM extends CommonGLPI
{
    /** @var array<string, mixed> */
    public $fields = [];
}

#[\AllowDynamicProperties]
class CommonDropdown extends CommonDBTM {}

#[\AllowDynamicProperties]
class CommonDBChild extends CommonDBTM {}

#[\AllowDynamicProperties]
class CommonDBRelation extends CommonDBTM {}

#[\AllowDynamicProperties]
class NotificationTarget extends CommonDBChild {}

#[\AllowDynamicProperties]
class Dropdown extends CommonDBTM {}

#[\AllowDynamicProperties]
class Profile extends CommonDBTM {}

#[\AllowDynamicProperties]
class TCPDF {}

// Provided by the optional "datainjection" plugin, absent from the scan container.
interface PluginDatainjectionInjectionInterface {}
