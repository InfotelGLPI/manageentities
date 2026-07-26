<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Manageentities\DirectHelpdesk_Ticket;

Html::header_nocache();
Session::checkLoginUser();

if (isset($_GET['action']) && $_GET['action'] == 'createticket') {
    // Enforce entity scope: without this, a helpdesk user could iterate entities_id
    // and read another entity's direct-helpdesk entries (names, technicians, comments).
    $entities_id = (int) ($_GET['entities_id'] ?? -1);
    if (!Session::haveAccessToEntity($entities_id)) {
        throw new AccessDeniedHttpException();
    }

    Html::popHeader(__('Create a ticket'), $_SERVER['PHP_SELF']);

    DirectHelpdesk_Ticket::selectDirectHeldeskForTicket($entities_id);

    Html::popFooter();
} else {
    if (Session::getCurrentInterface() == 'central') {
        DirectHelpdesk::loadModal();
    }
}

