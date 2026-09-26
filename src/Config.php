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
use ContactType;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Migration;
use Session;
use Ticket;
use Toolbox;

class Config extends CommonDBTM
{
    private static $instance;

    public const DAY = 0;
    public const HOUR = 1;
    public const NOPRICE = 0;
    public const PRICE = 1;
    public const REPORT_INTERVENTION = 0;
    public const PERIOD_INTERVENTION = 1;

    public static function getTypeName($nb = 0)
    {
        return __('Setup');
    }

    public static function getIcon()
    {
        return "ti ti-settings";
    }

    public static function canView(): bool
    {
        return Session::haveRight('plugin_manageentities', READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('plugin_manageentities', UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === __CLASS__) {
            return [
                1 => self::createTabEntry(self::getTypeName(1)),
            ];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === __CLASS__ && $tabnum === 1) {
            $item->showOptionsForm();
        }
        return true;
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(__CLASS__, $ong, $options);
        $this->addStandardTab(CriDetail::class, $ong, $options);
        $this->addStandardTab(Company::class, $ong, $options);
        $this->addStandardTab(CheckSchema::class, $ong, $options);
        return $ong;
    }

    public function showForm($id, $options = [])
    {
        $this->getFromDB(1);
        $this->showOptionsForm();
        return true;
    }

    public function showOptionsForm()
    {
        $this->getFromDB(1);

        $contractstate  = new ContractState();
        $contractstates = $contractstate->find();
        $states = [];
        foreach ($contractstates as $key => $val) {
            $states[$key] = $val['name'];
        }
        $decoded = json_decode($this->fields['contract_states'] ?? '', true);
        $states_selected = is_array($decoded) ? $decoded : [];

        $users   = BusinessContact::getBusinessUsers();
        $decoded = json_decode($this->fields['business_id'] ?? '', true);
        $business_selected = is_array($decoded) ? $decoded : [];

        TemplateRenderer::getInstance()->display(
            '@manageentities/config_options_form.html.twig',
            [
                'form_url'                  => Toolbox::getItemTypeFormURL(Config::class),
                'config'                    => $this->fields,
                'hourorday_types'           => self::getConfigType(),
                'hourorday_modes'           => ['day' => self::DAY, 'hour' => self::HOUR],
                'choice_intervention_types' => self::getChoiceInterventionTypes(),
                'contract_types'            => Contract::getContractTypes(),
                'states'                    => $states,
                'states_selected'           => $states_selected,
                'users'                     => $users,
                'business_selected'         => $business_selected,
                'glpi_state_condition'      => (new \Contract())->getStateVisibilityCriteria(),
                'change_event_js'           => CriDetail::CHANGE_EVENT_JS,
            ],
        );
    }

    public function prepareInputForUpdate($input)
    {
        // The two lists of the follow-up defaults are stored as JSON. Only encode them when the
        // form actually posts them - the CRI tab saves the same row without these fields - and
        // drop the empty value of the hidden input the core emits before a multiple select,
        // so that clearing the selection stores NULL rather than [""].
        foreach (['contract_states', 'business_id'] as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $input[$field] = BusinessContact::encodeIdList($input[$field]);
        }

        return $input;
    }

    public function post_updateItem($history = true)
    {
        // These settings drive how task durations are converted into consumption: the stored
        // remaining days of every contract are stale as soon as one of them changes.
        if (array_intersect(['hourorday', 'hourbyday', 'needvalidationforcri'], $this->updates)) {
            Contract::updateAllRemainingDays();
        }
    }

    public function isCommentCri()
    {
        $config = new Config();
        $config->GetFromDB(1);
        return $config->fields['comment'];
    }

    public static function getConfigType()
    {
        return ([
            self::DAY => _x('periodicity', 'Daily'),
            self::HOUR => __('Hourly', 'manageentities'),
        ]);
    }

    /**
     * Client side views offered by the "choice_intervention" setting.
     *
     * @return array<int, string>
     */
    public static function getChoiceInterventionTypes(): array
    {
        return [
            self::REPORT_INTERVENTION => _n('Intervention report', 'Intervention reports', 2, 'manageentities'),
            self::PERIOD_INTERVENTION => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
        ];
    }

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            $temp = new Config();
            $temp->getFromDB('1');
            self::$instance = $temp;
        }

        return self::$instance;
    }

    /**
     * Whether publisher (editor) subscriptions are enabled in the plugin configuration.
     * Defaults to true when the field is missing (e.g. before the upgrade migration ran).
     */
    public static function useEditorSubscriptions(): bool
    {
        return (bool) (self::getInstance()->fields['use_editorsubscriptions'] ?? 1);
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
                            `backup` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `documentcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id)',
                            `useprice` tinyint NOT NULL DEFAULT '1' COMMENT 'DEFAULT for yes',
                            `hourorday` tinyint NOT NULL DEFAULT '0' COMMENT 'DEFAULT for day',
                            `hourbyday` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'if hourorday == 0 then must be different of 0',
                            `needvalidationforcri` tinyint NOT NULL DEFAULT '0' COMMENT 'only CRI with validated ticket are taking into account for consumption calculation',
                            `use_publictask` tinyint NOT NULL DEFAULT '0' COMMENT 'DEFAULT for no',
                            `allow_same_periods` tinyint NOT NULL DEFAULT '0' COMMENT 'allow interventions on the same interval of dates',
                            `use_editorsubscriptions` tinyint NOT NULL DEFAULT '1' COMMENT 'display and manage publisher (editor) subscriptions',
                            `contract_states` text DEFAULT NULL,
                            `business_id` text DEFAULT NULL,
                            `choice_intervention` int {$default_key_sign} DEFAULT NULL,
                            `comment` tinyint NOT NULL DEFAULT '1' COMMENT 'display comments in the CRI',
                            `non_accomplished_tasks` tinyint NOT NULL DEFAULT '0',
                            `get_pdf_cri` tinyint NOT NULL DEFAULT '0',
                            `ticket_state` int {$default_key_sign} NOT NULL DEFAULT '3',
                            `default_duration` varchar(255) DEFAULT NULL,
                            `default_time_am` varchar(255) DEFAULT NULL,
                            `default_time_pm` varchar(255) DEFAULT NULL,
                            `disable_date_header` tinyint NOT NULL DEFAULT '0',
                            `closed_contractstate_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — state applied to contract periods when closing',
                            `closed_glpi_state_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_states (id) — GLPI contract state that triggers period closure and is set when all periods are closed',
                            `wizard_contractstate_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — DEFAULT intervention state in wizard',
                            `wizard_contract_type` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — DEFAULT intervention type in wizard',
                            `wizard_critype_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — DEFAULT CriType for rate in wizard',
                            `wizard_documentcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id) — DEFAULT document category in wizard',
                            `wizard_contacttypes_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contacttypes (id) — DEFAULT contact type in wizard',
                            `wizard_default_entities_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — parent entity pre-selected and locked in wizard step 1',
                            `wizard_archive_entities_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — entity used to archive customers',
                            PRIMARY KEY  (`id`),
                            KEY `documentcategories_id` (`documentcategories_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);

            $DB->insert(
                $table,
                ['id' => 1,
                    'backup' => 0,
                    'documentcategories_id' => 0,
                    'hourorday' => 0,
                    'hourbyday' => 8,
                    'needvalidationforcri' => 0],
            );
        }

        // Upgrade: add publisher subscriptions toggle on existing installations
        if (!$DB->fieldExists($table, 'use_editorsubscriptions')) {
            $migration->addField(
                $table,
                'use_editorsubscriptions',
                'bool',
                ['value' => 1, 'after' => 'allow_same_periods'],
            );
            $migration->migrationOneTable($table);
        }
    }


    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }

}
