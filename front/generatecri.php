<?php
/*
 -------------------------------------------------------------------------
 Stockview plugin for GLPI
 Copyright (C) 2013 by the Stockview Development Team.
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Stockview.

 Stockview is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Stockview is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Stockview. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
*/

include('../../../inc/includes.php');

Session::checkLoginUser();

if (Session::getCurrentInterface() == 'central') {
   Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", "pluginmanageentitiesgeneratecri");
} else {
   Html::helpHeader(__('Entities portal', 'manageentities'));
}
if (Session::haveRightsOr("ticket", [CREATE])) {
   $generatecri = new PluginManageentitiesGenerateCRI();
   $generatecri->showWizard($ticket = new Ticket(), $_SESSION['glpiactive_entity']);

   Html::footer();
} else {
   Html::displayRightError();
}
