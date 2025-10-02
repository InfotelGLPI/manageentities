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
use Html;
use Session;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class BusinessContact extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    function showBusiness($instID)
    {
        global $DB, $CFG_GLPI;

        $iterator = $DB->request([
            'SELECT' => [
                $this->getTable() . '.id as users_id',
                $this->getTable() . '.is_default',
                'glpi_users.*',
                'glpi_useremails.email',
            ],
            'FROM' => $this->getTable(),
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $this->getTable() => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ],
                'glpi_useremails' => [
                    'ON' => [
                        'glpi_useremails' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                $this->getTable() . '.entities_id' => $instID
            ],
            'GROUPBY' => $this->getTable() . '.users_id',
            'ORDERBY' => 'glpi_users.name',
        ]);

        echo "<br>";
        if (count($iterator) > 0) {
            echo "<form method='post' action=\"./entity.php\">";
            echo "<div class='center'><table class='tab_cadre_me center'>";
            echo "<tr><th colspan='6'>";
            echo "<h3><div class='alert alert-secondary' role='alert'>";
            echo _n('Associated commercial', 'Associated business', 2, 'manageentities');
            echo "</div></h3>";
            echo "</th></tr>";

            echo "<tr><th>" . __('Name') . "</th>";
            echo "<th>" . __('Phone') . "</th>";
            echo "<th>" . __('Phone') . " 2</th>";
            echo "<th>" . __('Mobile phone') . "</th>";
            echo "<th>" . __('Email address') . "</th>";
            if ($this->canCreate() && sizeof($instID) == 1) {
                echo "<th>&nbsp;</th>";
            }
            echo "</tr>";

            foreach ($iterator as $data) {
                $ID = $data["users_id"];
                echo "<tr class='tab_bg_1'>";
                echo "<td class='left'><a href='" . $CFG_GLPI["root_doc"] . "/front/user.form.php?id=" . $data["id"] . "'>" . $data["realname"] . " " . $data["firstname"] . "</a></td>";
                echo "<td class='center'>" . $data["phone"] . "</td>";
                echo "<td class='center'>" . $data["phone2"] . "</td>";
                echo "<td class='center'>" . $data["mobile"] . "</td>";
                echo "<td class='center'><a href='mailto:" . $data["email"] . "'>" . $data["email"] . "</a></td>";

                if ($this->canCreate() && sizeof($instID) == 1) {
                    echo "<td class='center' class='tab_bg_2'>";
                    Html::showSimpleForm(
                        PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                        'deletebusiness',
                        _x('button', 'Delete permanently'),
                        ['id' => $ID],
                        'ti ti-circle-x'
                    );
                    echo "</td>";
                }
                echo "</tr>";
            }

            if ($this->canCreate() && sizeof($instID) == 1) {
                echo "<tr class='tab_bg_1'><td colspan='5' class='center'>";
                echo Html::hidden('entities_id', ['value' => $_SESSION["glpiactive_entity"]]);
                $rand = User::dropdown(['right' => 'interface']);
                echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/user.form.php' target='_blank'>";
                echo "<i title=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class=\"ti ti-square-plus\" style='cursor:pointer; margin-left:2px;'></i>";
                echo "</a>";
                echo "</td><td class='center'>";
                echo Html::submit(_x('button', 'Add'), ['name' => 'addbusiness', 'class' => 'btn btn-primary']);
                echo "</td>";
                echo "</tr>";
            }
            echo "</table></div>";
            Html::closeForm();
        } else {
            if ($this->canCreate() && sizeof($instID) == 1) {
                echo "<form method='post' action=\"./entity.php\">";
                echo "<div class='center'><table class='tab_cadre_me center'>";
                echo "<tr><th colspan='2'>";
                echo "<h3><div class='alert alert-secondary' role='alert'>";
                echo _n('Associated commercial', 'Associated business', 2, 'manageentities');
                echo "</div></h3>";
                echo "</th></tr>";

                echo "<tr><td class='tab_bg_2 center'>";
                echo Html::hidden('entities_id', ['value' => $_SESSION["glpiactive_entity"]]);
                $rand = User::dropdown(['right' => 'interface']);
                echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/user.form.php' target='_blank'>";
                echo "<i title=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class=\"ti ti-square-plus\" style='cursor:pointer; margin-left:2px;'></i>";
                echo "</a>";
                echo "</td><td class='center tab_bg_2'>";
                echo Html::submit(_x('button', 'Add'), ['name' => 'addbusiness', 'class' => 'btn btn-primary']);
                echo "</td></tr>";

                echo "</table></div>";
                Html::closeForm();
            }
        }
    }
}
