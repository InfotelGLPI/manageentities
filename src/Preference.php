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

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use Html;
use Migration;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * class plugin_manageentities_preference
 * Load and store the preference configuration from the database
 */
class Preference extends CommonDBTM
{

    static function checkIfPreferenceExists($users_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'id'
            ],
            'FROM' => 'glpi_plugin_manageentities_preferences',
            'WHERE' => [
                'users_id' => $users_id
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                return $data["id"];
            }
        }
        return 0;
    }

    static function addDefaultPreference($users_id)
    {
        $self = new self();
        $input["users_id"] = $users_id;
        $input["show_on_load"] = 0;

        return $self->add($input);
    }

    static function checkPreferenceValue($users_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'show_on_load'
            ],
            'FROM' => 'glpi_plugin_manageentities_preferences',
            'WHERE' => [
                'users_id' => $users_id
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                return $data["show_on_load"];
            }
        }
        return 0;
    }

    static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Preference'
            && isset($_SESSION["glpiactiveprofile"]["interface"])
            && $_SESSION["glpiactiveprofile"]["interface"] != "helpdesk") {
            return self::createTabEntry(__('Entities portal', 'manageentities'));
        }
        return '';
    }


    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if (get_class($item) == 'Preference') {
            $pref_ID = self::checkIfPreferenceExists(Session::getLoginUserID());
            if (!$pref_ID) {
                $pref_ID = self::addDefaultPreference(Session::getLoginUserID());
            }

            self::showPreferencesForm(PLUGIN_MANAGEENTITIES_WEBDIR . "/front/preference.form.php", $pref_ID);
        }
        return true;
    }

    static function showPreferencesForm($target, $ID)
    {
        global $DB;

        $data = plugin_version_manageentities();
        $self = new self();
        $self->getFromDB($ID);
        echo "<form action='" . $target . "' method='post'>";
        echo "<div class='center'>";

        echo "<table class='tab_cadre_fixe' cellpadding='5'>";
        echo "<tr><th colspan='2'>" . $data['name'] . " - " . $data['version'] . "</th></tr>";

        echo "<tr class='tab_bg_1 center'><td>" . __(
                'Launch the plugin Entities portal with GLPI launching',
                'manageentities'
            ) . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("show_on_load", $self->fields["show_on_load"]);
        echo "</td></tr>";

        $contractstate = new ContractState();
        $contractstates = $contractstate->find();
        $states = [];
        foreach ($contractstates as $key => $val) {
            $states[$key] = $val['name'];
        }
        echo "<tr class='tab_bg_1 center'><td>" . __(
                'Status list contract for the general monitoring',
                'manageentities'
            ) . "</td>";
        echo "<td>";
        if ($self->fields["contract_states"] == null) {
            \Dropdown::showFromArray("contract_states", $states, [
                'multiple' => true,
                'width' => 200,
                'value' => $self->fields["contract_states"]
            ]);
        } else {
            \Dropdown::showFromArray("contract_states", $states, [
                'multiple' => true,
                'width' => 200,
                'values' => json_decode($self->fields["contract_states"], true)
            ]);
        }
        echo "</td></tr>";

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_manageentities_businesscontacts.id as users_id',
                'glpi_users.*',
                'glpi_users.realname',
                'glpi_users.firstname',
            ],
            'FROM' => 'glpi_plugin_manageentities_businesscontacts',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_plugin_manageentities_businesscontacts' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'GROUPBY' => 'glpi_plugin_manageentities_businesscontacts.users_id',
        ]);

        $users = [];
        foreach ($iterator as $data) {
            $users[$data['id']] = $data['realname'] . " " . $data['firstname'];
        }
        echo "<tr class='tab_bg_1 center'><td>" . __(
                'Default list of Business for the general monitoring',
                'manageentities'
            ) . "</td>";
        echo "<td>";
        if ($self->fields["business_id"] == null) {
            \Dropdown::showFromArray("business_id", $users, [
                'multiple' => true,
                'width' => 200,
                'value' => $self->fields["business_id"]
            ]);
        } else {
            \Dropdown::showFromArray("business_id", $users, [
                'multiple' => true,
                'width' => 200,
                'values' => json_decode($self->fields["business_id"], true)
            ]);
        }
        echo "</td></tr>";
        echo "<tr class='tab_bg_1 center'><td>" . __(
                'Default list of companies for the general monitoring',
                'manageentities'
            ) . "</td>";
        echo "<td>";
        $plugin_company = new Company();
        $result = $plugin_company->find();

        $company = [];
        foreach ($result as $data) {
            $company[$data['id']] = $data['name'];
        }
        if ($self->fields['companies_id'] == null) {
            \Dropdown::showFromArray("companies_id", $company, [
                'multiple' => true,
                'width' => 200,
                'value' => $self->fields["companies_id"]
            ]);
        } else {
            \Dropdown::showFromArray("companies_id", $company, [
                'multiple' => true,
                'width' => 200,
                'values' => json_decode($self->fields["companies_id"], true)
            ]);
        }
        echo "</td></tr>";


        echo "<tr class='tab_bg_1 center'><td colspan='2'>";
        echo Html::submit(
            _sx('button', 'Post'),
            ['name' => 'update_user_preferences_manageentities', 'class' => 'btn btn-primary']
        );
        echo Html::hidden('id', ['value' => $ID]);
        echo "</td></tr>";
        echo "<tr class='tab_bg_1 center'><td colspan='2'>";
        echo __(
            'Warning : If there are more than one plugin which be loaded at startup, then only the first will be used',
            'manageentities'
        );
        echo "</td></tr>";
        echo "</table>";

        echo "</div>";
        Html::closeForm();
    }

    function prepareInputForUpdate($input)
    {
        if (isset($input['contract_states'])) {
            $input['contract_states'] = json_encode($input['contract_states']);
        } else {
            $input['contract_states'] = 'NULL';
        }
        if (isset($input['business_id'])) {
            $input['business_id'] = json_encode($input['business_id']);
        } else {
            $input['business_id'] = 'NULL';
        }
        if (isset($input['companies_id'])) {
            $input['companies_id'] = json_encode($input['companies_id']);
        } else {
            $input['companies_id'] = 'NULL';
        }
        return $input;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `show_on_load` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `contract_states` text DEFAULT NULL,
                            `business_id` text DEFAULT NULL,
                            `companies_id` text DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `users_id` (`users_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }


    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
