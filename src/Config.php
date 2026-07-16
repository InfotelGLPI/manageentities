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

use Ajax;
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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Config extends CommonDBTM
{
    private static $instance;

    public const DAY = 0;
    public const HOUR = 1;
    public const NOPRICE = 0;
    public const PRICE = 1;
    public const REPORT_INTERVENTION = 0;
    public const PERIOD_INTERVENTION = 1;

    static function getTypeName($nb = 0)
    {
        return __('Setup');
    }

    public static function getIcon()
    {
        return "ti ti-settings";
    }

    static function canView(): bool
    {
        return Session::haveRight('plugin_manageentities', READ);
    }

    static function canCreate(): bool
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
        global $DB;

        $this->getFromDB(1);

        $contractstate  = new ContractState();
        $contractstates = $contractstate->find();
        $states = [];
        foreach ($contractstates as $key => $val) {
            $states[$key] = $val['name'];
        }
        $decoded = json_decode($this->fields['contract_states'] ?? '', true);
        $states_selected = is_array($decoded) ? $decoded : [];

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_manageentities_businesscontacts.id as users_id',
                'glpi_users.id',
                'glpi_users.realname',
                'glpi_users.firstname',
            ],
            'FROM'      => 'glpi_plugin_manageentities_businesscontacts',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_plugin_manageentities_businesscontacts' => 'users_id',
                        'glpi_users'                                  => 'id',
                    ],
                ],
            ],
            'GROUPBY' => 'glpi_plugin_manageentities_businesscontacts.users_id',
        ]);
        $users = [];
        foreach ($iterator as $data) {
            $users[$data['id']] = $data['realname'] . ' ' . $data['firstname'];
        }
        $decoded = json_decode($this->fields['business_id'] ?? '', true);
        $business_selected = is_array($decoded) ? $decoded : [];

        ob_start();
        $rand_hourorday = \Dropdown::showFromArray(
            'hourorday',
            self::getConfigType(),
            ['value' => $this->fields['hourorday'], 'display' => true]
        );
        Ajax::updateItem(
            'title_show_hourorday',
            PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/linkactions.php',
            ['hourorday' => $this->fields['hourorday'], 'action' => 'title_show_hourorday'],
            "dropdown_hourorday$rand_hourorday"
        );
        Ajax::updateItem(
            'value_show_hourorday',
            PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/linkactions.php',
            ['hourorday' => $this->fields['hourorday'], 'action' => 'value_show_hourorday'],
            "dropdown_hourorday$rand_hourorday"
        );
        Ajax::updateItemOnSelectEvent(
            "dropdown_hourorday$rand_hourorday",
            'title_show_hourorday',
            PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/linkactions.php',
            ['hourorday' => '__VALUE__', 'action' => 'title_show_hourorday']
        );
        Ajax::updateItemOnSelectEvent(
            "dropdown_hourorday$rand_hourorday",
            'value_show_hourorday',
            PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/linkactions.php',
            ['hourorday' => '__VALUE__', 'action' => 'value_show_hourorday']
        );
        $hourorday_html = ob_get_clean();

        ob_start();
        \Dropdown::show('DocumentCategory', [
            'name'  => 'documentcategories_id',
            'value' => $this->fields['documentcategories_id'],
        ]);
        $documentcategory_html = ob_get_clean();

        ob_start();
        self::dropdownConfigChoiceIntervention('choice_intervention', $this->fields['choice_intervention']);
        $choice_intervention_html = ob_get_clean();

        ob_start();
        \Dropdown::showFromArray('contract_states', $states, [
            'multiple' => true,
            'width'    => 200,
            'values'   => $states_selected,
        ]);
        $contract_states_html = ob_get_clean();

        ob_start();
        \Dropdown::showFromArray('business_id', $users, [
            'multiple' => true,
            'width'    => 200,
            'values'   => $business_selected,
        ]);
        $business_html = ob_get_clean();

        ob_start();
        \Dropdown::show(ContractState::class, [
            'name'  => 'closed_contractstate_id',
            'value' => $this->fields['closed_contractstate_id'] ?? 0,
        ]);
        $closed_contractstate_html = ob_get_clean();

        $glpi_contract = new \Contract();
        $visibility_criteria = $glpi_contract->getStateVisibilityCriteria();
        ob_start();
        \Dropdown::show('State', [
            'name'      => 'closed_glpi_state_id',
            'value'     => $this->fields['closed_glpi_state_id'] ?? 0,
            'condition' => $visibility_criteria,
        ]);
        $closed_glpi_state_html = ob_get_clean();

        ob_start(); \Dropdown::showYesNo('backup', $this->fields['backup']); $backup_html = ob_get_clean();
        ob_start(); \Dropdown::showYesNo('useprice', $this->fields['useprice']); $useprice_html = ob_get_clean();
        ob_start(); \Dropdown::showYesNo('use_publictask', $this->fields['use_publictask']); $use_publictask_html = ob_get_clean();
        ob_start(); \Dropdown::showYesNo('allow_same_periods', $this->fields['allow_same_periods']); $allow_same_periods_html = ob_get_clean();
        ob_start(); \Dropdown::showYesNo('use_editorsubscriptions', $this->fields['use_editorsubscriptions'] ?? 1); $use_editorsubscriptions_html = ob_get_clean();
        ob_start(); \Dropdown::showYesNo('comment', $this->fields['comment']); $comment_html = ob_get_clean();

        ob_start();
        \Dropdown::show(ContractState::class, [
            'name'  => 'wizard_contractstate_id',
            'value' => $this->fields['wizard_contractstate_id'] ?? 0,
        ]);
        $wizard_contractstate_html = ob_get_clean();

        ob_start();
        Contract::dropdownContractType('wizard_contract_type', (int)($this->fields['wizard_contract_type'] ?? 0));
        $wizard_contract_type_html = ob_get_clean();

        ob_start();
        \Dropdown::show(CriType::class, [
            'name'  => 'wizard_critype_id',
            'value' => $this->fields['wizard_critype_id'] ?? 0,
        ]);
        $wizard_critype_html = ob_get_clean();

        ob_start();
        \Dropdown::show('DocumentCategory', [
            'name'  => 'wizard_documentcategories_id',
            'value' => $this->fields['wizard_documentcategories_id'] ?? 0,
        ]);
        $wizard_documentcategory_html = ob_get_clean();

        ob_start();
        ContactType::dropdown([
            'name'  => 'wizard_contacttypes_id',
            'value' => $this->fields['wizard_contacttypes_id'] ?? 0,
        ]);
        $wizard_contacttype_html = ob_get_clean();

        $wizard_default_entities_id = (int)($this->fields['wizard_default_entities_id'] ?? 0);
        ob_start();
        \Dropdown::show(\Entity::class, [
            'name'  => 'wizard_default_entities_id',
            'value' => $wizard_default_entities_id,
        ]);
        $wizard_default_entity_html = ob_get_clean();

        $wizard_archive_entities_id = (int)($this->fields['wizard_archive_entities_id'] ?? 0);
        ob_start();
        \Dropdown::show(\Entity::class, [
            'name'  => 'wizard_archive_entities_id',
            'value' => $wizard_archive_entities_id,
        ]);
        $wizard_archive_entity_html = ob_get_clean();

        TemplateRenderer::getInstance()->display(
            '@manageentities/config_options_form.html.twig',
            [
                'form_url'                      => Toolbox::getItemTypeFormURL(Config::class),
                'hourorday_html'                => $hourorday_html,
                'documentcategory_html'         => $documentcategory_html,
                'choice_intervention_html'      => $choice_intervention_html,
                'contract_states_html'          => $contract_states_html,
                'business_html'                 => $business_html,
                'backup_html'                   => $backup_html,
                'useprice_html'                 => $useprice_html,
                'use_publictask_html'           => $use_publictask_html,
                'allow_same_periods_html'       => $allow_same_periods_html,
                'use_editorsubscriptions_html'  => $use_editorsubscriptions_html,
                'comment_html'                  => $comment_html,
                'closed_contractstate_html'     => $closed_contractstate_html,
                'closed_glpi_state_html'        => $closed_glpi_state_html,
                'wizard_contractstate_html'     => $wizard_contractstate_html,
                'wizard_contract_type_html'     => $wizard_contract_type_html,
                'wizard_critype_html'           => $wizard_critype_html,
                'wizard_documentcategory_html'  => $wizard_documentcategory_html,
                'wizard_contacttype_html'       => $wizard_contacttype_html,
                'wizard_default_entity_html'    => $wizard_default_entity_html,
                'wizard_archive_entity_html'    => $wizard_archive_entity_html,
            ]
        );
    }

    public function showConfigForm()
    {
        global $DB, $CFG_GLPI;
        echo "<form name='form' method='post' action='"
            . Toolbox::getItemTypeFormURL(Config::class) . "'>";

        echo "<div class='center'><table class='tab_cadre_fixe'  cellspacing='2' cellpadding='2'>";
        echo "<tr><th colspan='2'>" . __('Options', 'manageentities') . "</th></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Save reports in glpi', 'manageentities') . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("backup", $this->fields["backup"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Rubric by default for reports', 'manageentities') . "</td>";
        echo "<td>";
        \Dropdown::show('DocumentCategory', [
            'name' => "documentcategories_id",
            'value' => $this->fields["documentcategories_id"],
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Use of price', 'manageentities') . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("useprice", $this->fields["useprice"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Configuration daily or hourly', 'manageentities') . "</td>";
        echo "<td>";
        $rand = \Dropdown::showFromArray('hourorday', self::getConfigType(), ['value' => $this->fields["hourorday"]]);

        echo "<tr class='tab_bg_1 top'>";
        echo "<td><span id='title_show_hourorday'></span></td>";
        echo "<td><span id='value_show_hourorday'></span></td>";
        echo "</tr>";

        //js for load configuration
        Ajax::updateItem(
            "title_show_hourorday",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/linkactions.php",
            ['hourorday' => $this->fields["hourorday"], 'action' => 'title_show_hourorday'],
            "dropdown_hourorday$rand"
        );
        Ajax::updateItem(
            "value_show_hourorday",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/linkactions.php",
            ['hourorday' => $this->fields["hourorday"], 'action' => 'value_show_hourorday'],
            "dropdown_hourorday$rand"
        );
        //js for change configuration
        Ajax::updateItemOnSelectEvent(
            "dropdown_hourorday$rand",
            "title_show_hourorday",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/linkactions.php",
            ['hourorday' => '__VALUE__', 'action' => 'title_show_hourorday']
        );
        Ajax::updateItemOnSelectEvent(
            "dropdown_hourorday$rand",
            "value_show_hourorday",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/linkactions.php",
            ['hourorday' => '__VALUE__', 'action' => 'value_show_hourorday']
        );
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __(
            'Only public task are visible on intervention report',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("use_publictask", $this->fields["use_publictask"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __(
            'Allow periods on the same interval of dates',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("allow_same_periods", $this->fields["allow_same_periods"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Configuring the client side view', 'manageentities') . "</td>";
        echo "<td>";
        self::dropdownConfigChoiceIntervention("choice_intervention", $this->fields["choice_intervention"]);
        echo "</td></tr>";

        $contractstate = new ContractState();
        $contractstates = $contractstate->find();
        $states = [];
        foreach ($contractstates as $key => $val) {
            $states[$key] = $val['name'];
        }

        echo "<tr class='tab_bg_1 top'><td>" . __(
            'List of default statuses for general monitoring',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        if ($this->fields["contract_states"] == null) {
            \Dropdown::showFromArray(
                "contract_states",
                $states,
                ['multiple' => true, 'width' => 200, 'value' => $this->fields["contract_states"]]
            );
        } else {
            \Dropdown::showFromArray(
                "contract_states",
                $states,
                ['multiple' => true, 'width' => 200, 'values' => json_decode($this->fields["contract_states"], true)]
            );
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
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'GROUPBY' => 'glpi_plugin_manageentities_businesscontacts.users_id',
        ]);

        $users = [];
        foreach ($iterator as $data) {
            $users[$data['id']] = $data['realname'] . " " . $data['firstname'];
        }
        echo "<tr class='tab_bg_1 top'><td>" . __(
            'Default Business list for general monitoring',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        if ($this->fields["business_id"] == null) {
            \Dropdown::showFromArray(
                    "business_id",
                $users,
                ['multiple' => true, 'width' => 200, 'value' => $this->fields["business_id"]]
            );
        } else {
            \Dropdown::showFromArray(
                "business_id",
                $users,
                ['multiple' => true, 'width' => 200, 'values' => json_decode($this->fields["business_id"], true)]
            );
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __(
            'Display comments from the company in the CRI',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("comment", $this->fields["comment"]);
        echo "</td></tr>";

        echo "<tr><th colspan='2'>" . __('CRI generation form', 'manageentities') . "</th></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __(
            'Use Non-accomplished tasks informations',
            'manageentities'
        ) . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("non_accomplished_tasks", $this->fields["non_accomplished_tasks"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Display PDF', 'manageentities') . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("get_pdf_cri", $this->fields["get_pdf_cri"]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('State of ticket created', 'manageentities') . "</td>";
        echo "<td>";
        $status = Ticket::getAllStatusArray();
        \Dropdown::showFromArray("ticket_state", $status, ["value" => $this->fields["ticket_state"]]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Default duration', 'manageentities') . "</td>";
        echo "<td>";
        $rand = \Dropdown::showTimeStamp("default_duration", [
            'value' => $this->fields["default_duration"],
            'min' => 0,
            'max' => 50 * HOUR_TIMESTAMP,
            'emptylabel' => __('Specify an end date'),
        ]);
        echo "<br><div id='date_end$rand'></div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Default time AM', 'manageentities') . "</td>";
        echo "<td>";
        $rand = \Dropdown::showTimeStamp("default_time_am", [
            'value' => $this->fields["default_time_am"],
            'min' => 0,
            'emptylabel' => "0h",
            'max' => 23.5 * HOUR_TIMESTAMP,
            'step' => MINUTE_TIMESTAMP * 30,
        ]);
        echo "<br><div id='date_end$rand'></div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Default time PM', 'manageentities') . "</td>";
        echo "<td>";
        $rand = \Dropdown::showTimeStamp("default_time_pm", [
            'value' => $this->fields["default_time_pm"],
            'min' => 0,
            'emptylabel' => "0h",
            'max' => 23.5 * HOUR_TIMESTAMP,
            'step' => MINUTE_TIMESTAMP * 30,
        ]);
        echo "<br><div id='date_end$rand'></div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1 top'><td>" . __('Disable creation date in header of PDF', 'manageentities') . "</td>";
        echo "<td>";
        \Dropdown::showYesNo("disable_date_header", $this->fields["disable_date_header"]);
        echo "</td></tr>";

        echo Html::hidden('id', ['value' => 1]);
        echo "<tr class='tab_bg_1 center'><td colspan='2'>
            <span style=\"font-weight:bold; color:red\">" . __(
            'Warning: changing the configuration daily or hourly impacts the types of contract',
            'manageentities'
        ) . "</td></span></tr>";
        echo "<tr class='tab_bg_2 center'><td colspan='2'>";
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update_config', 'class' => 'btn btn-primary']);
        echo "</td></tr>";

        echo "</table></div>";
        Html::closeForm();
    }

    public function prepareInputForUpdate($input)
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
        return $input;
    }

    public function isCommentCri()
    {
        $config = new Config();
        $config->GetFromDB(1);
        return $config->fields['comment'];
    }

    public function getConfigType()
    {
        return ([
            self::DAY => _x('periodicity', 'Daily'),
            self::HOUR => __('Hourly', 'manageentities'),
        ]);
    }

    public function dropdownConfigChoiceIntervention($name, $value = 0)
    {
        $configTypes = [
            self::REPORT_INTERVENTION => _n('Intervention report', 'Intervention reports', 2, 'manageentities'),
            self::PERIOD_INTERVENTION => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
        ];

        if (!empty($configTypes)) {
            return \Dropdown::showFromArray($name, $configTypes, ['value' => $value]);
        } else {
            return false;
        }
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
        return (bool)(self::getInstance()->fields['use_editorsubscriptions'] ?? 1);
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
                    'needvalidationforcri' => 0]
            );
        }

        // Upgrade: add publisher subscriptions toggle on existing installations
        if (!$DB->fieldExists($table, 'use_editorsubscriptions')) {
            $migration->addField(
                $table,
                'use_editorsubscriptions',
                'bool',
                ['value' => 1, 'after' => 'allow_same_periods']
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
