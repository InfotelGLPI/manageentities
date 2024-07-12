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

    if (isset($_POST["create_ticket"])) {
        Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", "pluginmanageentitiesdirecthelpdesk");
        $options['entities_id'] = $_POST['entities_id'];
        $direct = new PluginManageentitiesDirecthelpdesk();
        $options['content'] = "";
        $options['_created_from_directhelpdesk'] = true;
        if ($items = $direct->find(['is_billed' => 0, 'entities_id' => $_POST['entities_id']])) {
            $sum = 0;
            foreach ($items as $item) {

                $inputd['id'] = $item['id'];
                $inputd['is_billed'] = 1;
                $direct->update($inputd);

                $actiontime = $item['actiontime'];
                $sum += $actiontime;
                $options['name'] = __('New intervention', 'manageentities')." : ".CommonITILObject::getActionTime($sum);
                $options['content'] .= Html::convDate($item['date'])." : ".$item['name']." - ".getUserName($item['users_id'])." (".CommonITILObject::getActionTime($actiontime).")<br>";
            }
        }
        $ticket = new Ticket();
        $ticket->showForm(0, $options);
        Html::footer();
    } elseif (isset($_POST["add"])) {
        $inter = $direct->add($_POST);
        if (isset($_POST["tickets_id"])) {
            $ticket = new PluginManageentitiesDirecthelpdesk_Ticket();
            $input['plugin_manageentities_directhelpdesks_id'] = $inter;
            $input['tickets_id'] = $_POST["tickets_id"];
            $ticket->add($input);
        }
        Html::back();
    } elseif (isset($_POST["update"])) {
        $direct->check($_POST["id"], UPDATE);
        $direct->update($_POST);
        Html::back();
    } elseif (isset($_POST["delete"])) {
        $direct->delete($_POST, 1);
        Html::back();
    } else {
        $direct->checkGlobal(READ);

        if (Session::getCurrentInterface() == 'central') {
            Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", "pluginmanageentitiesdirecthelpdesk");
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
