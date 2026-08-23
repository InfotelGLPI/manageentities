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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\CriPrice;

Html::header_nocache();

// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    throw new AccessDeniedHttpException();
}

switch ($_POST['action']) {
    case 'loadPrice':
        // Entity scope (anti-IDOR): showSelectPriceDropdown() discloses the CRI pricing
        // grid of the requested entity. Enforce the caller may access that entity, the
        // same guard the sibling dropdown endpoints already apply.
        $entities_id = (int) ($_POST['entities_id'] ?? 0);
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
        $criprice = new CriPrice();
        $criprice->showSelectPriceDropdown((int) ($_POST['critypes_id'] ?? 0), $entities_id);
        break;
}
