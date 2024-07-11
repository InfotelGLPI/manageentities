<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

if (Session::haveRight("plugin_manageentities", UPDATE)) {
    $direct = new PluginManageentitiesDirecthelpdesk();

    if (isset($_POST["add"])) {
        $direct->add($_POST);
        Html::back();

    } elseif (isset($_POST["update"])) {
        $direct->check($_POST["id"], UPDATE);
        $direct->update($_POST);
        Html::back();

    } elseif (isset($_POST["delete"])) {
        $direct->delete($_POST, 1);
        Html::back();
    }  else {

        $direct->checkGlobal(READ);

        if (Session::getCurrentInterface() == 'central') {
            Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", "pluginmanageentitiesgeneratecri");
        } else {
            if (Plugin::isPluginActive('servicecatalog')) {
                PluginServicecatalogMain::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
            } else {
                Html::helpHeader(__('Entities portal', 'manageentities'));
            }
        }
        $direct->display($_GET);

        if (Session::getCurrentInterface() == 'central') {
            Html::footer();
        } else {
            Html::helpFooter();
        }
    }

} else {
    Html::header(__('Setup'), '', "config", "plugins");
    echo "<div class='alert alert-important alert-warning d-flex'>";
    echo "<b>" . __("You don't have permission to perform this action.") . "</b></div>";
    Html::footer();
}
