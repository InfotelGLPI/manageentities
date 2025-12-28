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

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonITILObject;
use DbUtils;
use CommonGLPI;
use Html;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class DirectHelpdesk_Ticket extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Not billed intervention', 'Not billed interventions', $nb, 'manageentities');
    }

    /**
     * @return string
     */
    static function getIcon()
    {
        return "ti ti-file-euro";
    }

    static function countForTicket($item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_directhelpdesks_tickets',
            ["`tickets_id`" => $item->getID()]
        );
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Ticket'
            && isset($_SESSION['glpiactiveprofile']['interface'])
            && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
            if (self::countForTicket($item) > 0) {
                if ($_SESSION['glpishow_count_on_tabs']) {
                    return self::createTabEntry(
                        _n(
                            'Not billed intervention',
                            'Not billed interventions',
                            self::countForTicket($item),
                            'manageentities'
                        ),
                        self::countForTicket($item)
                    );
                }
                return self::createTabEntry(self::getTypeName(self::countForTicket($item)));
            } else {
                return self::createTabEntry(self::getTypeName(1));
            }
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() == 'Ticket' && self::countForTicket($item) > 0) {
            $self = new self();
            if ($items = $self->find(['tickets_id' => $item->getID()])) {
                echo "<table class='tab_cadre_fixe'>";
                echo "<tr class='tab_bg_1'>";
                echo "<th>" . __('Title') . "</th>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Technician') . "</th>";
                echo "<th>" . __('Duration') . "</th>";
                echo "<th>" . __('Description') . "</th>";
                echo "</tr>";
                foreach ($items as $item) {
                    $direct = new DirectHelpdesk();
                    $direct->getFromDB($item['plugin_manageentities_directhelpdesks_id']);
                    $actiontime = $direct->fields['actiontime'] ?? '';
                    echo "<tr class='tab_bg_1'>";
                    echo "<td>" . $direct->fields['name'] ?? '' . "</td>";
                    echo "<td>" . Html::convDate($direct->fields['date'] ?? '') . "</td>";
                    echo "<td>" . getUserName($direct->fields['users_id'] ?? '') . "</td>";
                    echo "<td>" . CommonITILObject::getActionTime($actiontime) . "</td>";
                    echo "<td>" . $direct->fields['comment'] ?? '' . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<div class='center alert alert-info d-flex'>";
                echo __('No results found');
                echo "</div>";
            }
        } else {
            echo "<div class='center alert alert-info d-flex'>";
            echo __('No results found');
            echo "</div>";
        }
        return true;
    }

    static function selectDirectHeldeskForTicket($entities_id)
    {
        $direct = new DirectHelpdesk();
        if ($items = $direct->find(['is_billed' => 0, 'entities_id' => $entities_id], ['date'])) {
            echo "<form method='post' action='" . $direct->getFormURL() . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_1'>";
            if (Session::getCurrentInterface() == 'central') {
                echo "<th>" . __('Select', 'manageentities') . "</th>";
            }
            echo "<th>" . __('Title') . "</th>";
            echo "<th>" . __('Date') . "</th>";
            echo "<th>" . __('Technician') . "</th>";
            echo "<th>" . __('Duration') . "</th>";
            if (Session::getCurrentInterface() == 'central') {
                echo "<th>" . __('Description') . "</th>";
            }
            echo "</tr>";

            foreach ($items as $item) {
                echo "<tr class='tab_bg_1'>";
                if (Session::getCurrentInterface() == 'central') {
                    echo "<td>";
                    $id = $item['id'];
                    Html::showCheckbox(['name' => 'select[' . $id . ']', 'value' => 0]);
                    echo "</td>";
                }
                echo "<td>" . $item['name'] . "</td>";
                echo "<td>" . Html::convDate($item['date']) . "</td>";
                echo "<td>" . getUserName($item['users_id']) . "</td>";
                echo "<td>" . CommonITILObject::getActionTime($item['actiontime']) . "</td>";
                if (Session::getCurrentInterface() == 'central') {
                    echo "<td>" . $item['comment'] . "</td>";
                }
                echo "</tr>";
            }
            if (Session::getCurrentInterface() == 'central') {
                echo "<tr><th colspan='6'>";
                echo Html::hidden('entities_id', ['value' => $entities_id]);
                echo "<div class='center'>";
                echo Html::submit(_sx('button', 'Post'), ['name' => 'create_ticket', 'class' => 'btn btn-primary me-2']
                );
                echo "</div></th></tr>";
            }
            echo "</table>";
            Html::closeForm();
        }
    }
}
