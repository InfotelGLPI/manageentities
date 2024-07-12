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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}
class PluginManageentitiesDirecthelpdesk_Ticket extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Not billed interventions', 'manageentities');
    }

    /**
     * @return string
     */
    static function getIcon()
    {
        return "ti ti-file-euro";
    }

    static function countForTicket($item) {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable('glpi_plugin_manageentities_directhelpdesks_tickets',
            ["`tickets_id`" => $item->getID()]);
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

        if ($item->getType() == 'Ticket' && self::countForTicket($item) > 0) {
            return self::getTypeName(1);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

//TODO
        if ($item->getType() == 'Ticket' && self::countForTicket($item) > 0) {

            $self = new self();
            if ($items = $self->find(['tickets_id' => $item->getID()])) {

                echo "<table class='tab_cadre_fixe'>";
                echo "<tr class='tab_bg_1'>";
                echo "<th>".__('Title')."</th>";
                echo "<th>".__('Date')."</th>";
                echo "<th>".__('Technician')."</th>";
                echo "<th>".__('Duration')."</th>";
                echo "<th>".__('Description')."</th>";
                echo "</tr>";
                foreach ($items as $item) {
                    $direct = new PluginManageentitiesDirecthelpdesk();
                    $direct->getFromDB($item['plugin_manageentities_directhelpdesks_id']);
                    $actiontime = $direct->fields['actiontime'];
                    echo "<tr class='tab_bg_1'>";
                    echo "<td>".$direct->fields['name']."</td>";
                    echo "<td>".Html::convDate($direct->fields['date'])."</td>";
                    echo "<td>".getUserName($direct->fields['users_id'])."</td>";
                    echo "<td>".CommonITILObject::getActionTime($actiontime)."</td>";
                    echo "<td>".$direct->fields['comment']."</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        }
        return true;
    }
}
