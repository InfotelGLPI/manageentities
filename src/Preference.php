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

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Migration;
use Session;

/**
 * class plugin_manageentities_preference
 * Load and store the preference configuration from the database
 */
class Preference extends CommonDBTM
{
    public static function checkIfPreferenceExists($users_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'id',
            ],
            'FROM' => 'glpi_plugin_manageentities_preferences',
            'WHERE' => [
                'users_id' => $users_id,
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                return $data["id"];
            }
        }
        return 0;
    }

    public static function addDefaultPreference($users_id)
    {
        $self = new self();
        $input["users_id"] = $users_id;
        $input["show_on_load"] = 0;

        return $self->add($input);
    }

    public static function checkPreferenceValue($users_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'show_on_load',
            ],
            'FROM' => 'glpi_plugin_manageentities_preferences',
            'WHERE' => [
                'users_id' => $users_id,
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                return $data["show_on_load"];
            }
        }
        return 0;
    }

    /**
     * Tabs of the client management dashboard hidden by default: until the user saves a choice
     * of their own (column left to NULL, or no preference row yet).
     *
     * @return int[]
     */
    public static function getDefaultHiddenDashboardTabs(): array
    {
        return [Entity::TAB_CONTRACTS, Entity::TAB_DOCUMENTS];
    }

    /**
     * Tabs of the client management dashboard the user chose not to display.
     *
     * @return int[]
     */
    public static function getHiddenDashboardTabs(int $users_id): array
    {
        /** @var array<int, int[]> $cache */
        static $cache = [];

        if (!isset($cache[$users_id])) {
            $self = new self();
            $hidden = null;
            if ($self->getFromDBByCrit(['users_id' => $users_id])) {
                $hidden = $self->fields['hidden_dashboard_tabs'] ?? null;
            }
            if ($hidden === null) {
                $cache[$users_id] = self::getDefaultHiddenDashboardTabs();
            } else {
                $decoded = json_decode($hidden, true);
                $cache[$users_id] = is_array($decoded) ? array_map('intval', $decoded) : [];
            }
        }

        return $cache[$users_id];
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Preference'
            && isset($_SESSION["glpiactiveprofile"]["interface"])
            && $_SESSION["glpiactiveprofile"]["interface"] != "helpdesk") {
            return self::createTabEntry(__('Entities portal', 'manageentities'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
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

    public static function showPreferencesForm($target, $ID)
    {
        $data = plugin_version_manageentities();
        $self = new self();
        $self->getFromDB($ID);

        $contractstate = new ContractState();
        $states = [];
        foreach ($contractstate->find() as $key => $val) {
            $states[$key] = $val['name'];
        }
        $states_decoded  = json_decode($self->fields["contract_states"] ?? '', true);
        $states_selected = is_array($states_decoded) ? $states_decoded : [];

        $users             = BusinessContact::getBusinessUsers();
        $business_decoded  = json_decode($self->fields["business_id"] ?? '', true);
        $business_selected = is_array($business_decoded) ? $business_decoded : [];

        $plugin_company = new Company();
        $company = [];
        foreach ($plugin_company->find() as $row) {
            $company[$row['id']] = $row['name'];
        }
        $companies_decoded  = json_decode($self->fields['companies_id'] ?? '', true);
        $companies_selected = is_array($companies_decoded) ? $companies_decoded : [];

        $dashboard_tabs = Entity::getDashboardTabLabels();
        $tabs_selected  = array_values(array_diff(
            array_keys($dashboard_tabs),
            self::getHiddenDashboardTabs((int) $self->fields['users_id']),
        ));

        TemplateRenderer::getInstance()->display('@manageentities/preference_form.html.twig', [
            'dashboard_tabs'     => $dashboard_tabs,
            'tabs_selected'      => $tabs_selected,
            'form_url'           => $target,
            'plugin_title'       => $data['name'],
            'id'                 => $ID,
            'show_on_load'       => (int) ($self->fields['show_on_load'] ?? 0),
            'states'             => $states,
            'states_selected'    => $states_selected,
            'users'              => $users,
            'business_selected'  => $business_selected,
            'companies'          => $company,
            'companies_selected' => $companies_selected,
        ]);
    }

    public function prepareInputForUpdate($input)
    {
        // front/preference.form.php reloads the row and refuses any id that is not the
        // caller's own, but it then hands the whole of $_POST over, and users_id is a real
        // column of glpi_plugin_manageentities_preferences: a posted users_id would hand the
        // caller's own preference row over to somebody else, or silently take over theirs.
        // The owner follows from who is logged in, it is never an input of the form.
        unset($input['users_id']);

        // Clearing a list used to store the string 'NULL', and the empty hidden value the core
        // posts in front of every multiple select ended up stored as [""]. Only the lists the
        // update actually carries are touched.
        foreach (['contract_states', 'business_id', 'companies_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = BusinessContact::encodeIdList($input[$field]);
            }
        }

        // The form lists the tabs to display, the row stores the ones to hide: a tab added by a
        // later version then shows up instead of being hidden. An empty list is stored as []
        // and not NULL, which stands for the defaults.
        if (array_key_exists('dashboard_tabs', $input)) {
            $displayed = array_map('intval', array_filter((array) $input['dashboard_tabs'], 'is_numeric'));
            $input['hidden_dashboard_tabs'] = json_encode(array_values(array_diff(
                array_keys(Entity::getDashboardTabLabels()),
                $displayed,
            )));
        }
        unset($input['dashboard_tabs']);

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
                            `hidden_dashboard_tabs` text DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `users_id` (`users_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // 4.2.20: choice of the tabs of the client management dashboard. The users already
        // having a preference row keep seeing every tab, as before the upgrade: only the new
        // ones get the defaults, which hide the contracts and the documents.
        if (!$DB->fieldExists($table, 'hidden_dashboard_tabs')) {
            $migration->addField($table, 'hidden_dashboard_tabs', 'text', ['after' => 'companies_id']);
            $migration->migrationOneTable($table);
            $DB->update($table, ['hidden_dashboard_tabs' => '[]'], ['hidden_dashboard_tabs' => null]);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
