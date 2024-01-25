<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2017 by the Manageentities Development Team.

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
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginManageentitiesContractpoint extends CommonDBTM
{

    const MANAGEMENT_NONE = 0;
    const MANAGEMENT_QUARTERLY = 1;
    const MANAGEMENT_ANNUAL = 2;

    const CONTRACT_TYPE_NULL = 0;
    //time mode
    const CONTRACT_POINTS = 1;
    const CONTRACT_UNLIMITED = 3;
    //Daily mode
    const CONTRACT_TYPE_AT = 4;
    const CONTRACT_TYPE_FORFAIT = 5;

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 1)
    {
        return __('Contract details', 'manageentities');
    }

    static function canView()
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate()
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    function canUpdateItem()
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    function prepareInputForAdd($input)
    {
        if (isset($input['date_renewal'])
            && empty($input['date_renewal'])) {
            $input['date_renewal'] = 'NULL';
        }
        if (isset($input['date_signature'])
            && empty($input['date_signature'])) {
            $input['date_signature'] = 'NULL';
        }

        if (isset($input['contract_added'])
            && ($input['contract_added'] === "on"
                || ($input['contract_added'] && $input['contract_added'] != 0))) {
            $input['contract_added'] = 1;
        } else {
            $input['contract_added'] = 0;
        }
        if (isset($input['refacturable_costs'])
            && ($input['refacturable_costs'] === "on"
                || ($input['refacturable_costs'] && $input['refacturable_costs'] != 0))) {
            $input['refacturable_costs'] = 1;
        } else {
            $input['refacturable_costs'] = 0;
        }
        if (isset($input['initial_credit'])) {
            $input['current_credit'] = $input['initial_credit'];
        }
        return $input;
    }

    function prepareInputForUpdate($input)
    {
        if (isset($input['date_renewal'])
            && empty($input['date_renewal'])) {
            $input['date_renewal'] = 'NULL';
        }
        if (isset($input['date_signature'])
            && empty($input['date_signature'])) {
            $input['date_signature'] = 'NULL';
        }

        if (isset($input['contract_added'])
            && ($input['contract_added'] === "on"
                || ($input['contract_added'] && $input['contract_added'] != 0))) {
            $input['contract_added'] = 1;
        } else {
            $input['contract_added'] = 0;
        }
        if (isset($input['refacturable_costs'])
            && ($input['refacturable_costs'] === "on"
                || ($input['refacturable_costs'] && $input['refacturable_costs'] != 0))) {
            $input['refacturable_costs'] = 1;
        } else {
            $input['refacturable_costs'] = 0;
        }
        return $input;
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Contract'
            && !isset($withtemplate) || empty($withtemplate)) {
            $dbu = new DbUtils();
            $restrict = [
                "`entities_id`" => $item->fields['entities_id'],
                "`contracts_id`" => $item->fields['id']
            ];
            $pluginContractDays = $dbu->countElementsInTable("glpi_plugin_manageentities_contractdays", $restrict);
            if ($_SESSION['glpishow_count_on_tabs']) {
                return self::createTabEntry(__('Contract detail', 'manageentities'), $pluginContractDays);
            }
            return __('Contract detail', 'manageentities');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (get_class($item) == 'Contract') {
            $self = new self();
            $self->showForContract($item);

//         if (self::canView()) {
//            PluginManageentitiesContractBilling::showForContract($item);
//         }
        }
        return true;
    }

    function addContractByDefault($id, $entities_id)
    {
        global $DB;

        $query = "SELECT *
        FROM `" . $this->getTable() . "`
        WHERE `entities_id` IN (" . $entities_id . ") ";
        $result = $DB->query($query);
        $number = $DB->numrows($result);

        if ($number) {
            while ($data = $DB->fetchArray($result)) {
                $query_nodefault = "UPDATE `" . $this->getTable() . "`
            SET `is_default` = 0 WHERE `id` = " . $data["id"];
                $DB->query($query_nodefault);
            }
        }

        $query_default = "UPDATE `" . $this->getTable() . "`
        SET `is_default` = 1 WHERE `id` = $id";
        $DB->query($query_default);
    }

    function showForContract(Contract $contract)
    {
        $rand = mt_rand();
        $canView = $contract->can($contract->fields['id'], READ);
        $canEdit = $contract->can($contract->fields['id'], UPDATE);
        $entity = new Entity();
        $entity->getFromDB(0);
        if (!$canView) {
            return false;
        }

        $restrict = [
//         "`glpi_plugin_manageentities_contracts`.`entities_id`"  => $contract->fields['entities_id'],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contract->fields['id']
        ];
        $dbu = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract = reset($pluginContracts);
        $this->getEmpty();
        $saved = self::getDefaultValues();
        $this->restoreSavedValues($saved);
        $this->getFromDBByCrit(['contracts_id' => $contract->getID()]);

        if ($canEdit) {
            echo "<form method='post' name='contract_form$rand' id='contract_form$rand'
               action='" . Toolbox::getItemTypeFormURL('PluginManageentitiesContractpoint') . "'>";
        }

        echo "<div align='spaced'><table class='tab_cadre_fixe center'>";
        echo Html::hidden('contracts_id', ['value' => $contract->fields['id']]);
        echo "<tr><th colspan='4'>" . PluginManageentitiesContract::getTypeName(0) . "</th></tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Contract mode', 'manageentities') . "</td>";
        echo "<td colspan='1'>";
        self::dropdownContractType('contract_type', $this->fields['contract_type']);
        echo "</td>";
        echo "<td>";
        echo "</td>";
        echo "<td>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Cancelled contract', 'manageentities') . "</td>";

        echo "<td colspan='1'>";
        Dropdown::showYesNo('contract_cancelled', $this->fields['contract_cancelled']);
        echo "</td>";
        echo "<td>";
        echo "</td>";
        echo "<td>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Contact mail', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Html::input('contact_email', ['value' => $this->fields['contact_email']]) . "</td>";
        echo "<td>";
        echo "</td>";
        echo "<td>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Initial credit', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Html::input('initial_credit', ['value' => $this->fields['initial_credit']]
            ) . "  " . __('points', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . __('Renewal number', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . $this->fields['renewal_number'] . "</td>";

        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Current credit', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . $this->fields['current_credit'] . "  " . __('points', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . __('Credit consumed', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Html::input('current_credit', ['value' => $this->fields['current_credit']]
            ) . "  " . __('points', 'manageentities') . "</td>";

        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Contract renewal threshold', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Html::input('threshold', ['value' => $this->fields['threshold']]) . "  " . __(
                'points',
                'manageentities'
            ) . "</td>";
        echo "<td colspan='1'>" . "</td>";
        echo "<td colspan='1'>" . "</td>";

        echo "</tr>";

        echo "<tr>";
        echo "<td colspan='1'>" . __('Number of minutes in a slice by default', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Dropdown::showNumber(
                'minutes_slice',
                ['max' => 480, 'display' => false, 'value' => $this->fields['minutes_slice']]
            ) . "</td>";
        echo "<td colspan='1'>" . __('Number of points per slice by default', 'manageentities') . "</td>";
        echo "<td colspan='1'>" . Dropdown::showNumber(
                'points_slice',
                ['max' => 1500, 'display' => false, 'value' => $this->fields['points_slice']]
            ) . "</td>";

        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Logo to show in report', 'manageentities') . "</td>";
        echo "<td colspan='3'>" . Html::file(
                [
                    'name' => 'picture_logo',
                    'value' => $this->fields["picture_logo"],
                    'onlyimages' => true,
                    'display' => false
                ]
            ) . "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td colspan='1'>" . __('Text in report footer', 'manageentities') . "</td>";
        echo "<td colspan='3'>" . Html::textarea(
                ['name' => 'footer', 'value' => $this->fields["footer"], 'display' => false]
            ) . "</td>";

        echo "</tr>";
        echo "<tr>";
        $params['canedit'] = true;
        $params['formfooter'] = null;
        $this->showFormButtons($params);
        echo "</tr>";

        echo "</table></div>";
        if ($canEdit) {
            Html::closeForm();
        }
        if ($this->getID() != 0) {
            $this->showReportGeneratorForm($contract);

            $mapping = new PluginManageentitiesMappingCategorySlice();
            $mapping->showForm(0, ['plugin_manageentities_contractpoints_id' => $this->getID()]);
            $mapping->showFromContract($this->getID(), $_GET);
        }
    }

    /**
     * @param $contract Contract
     * @return void
     */
    private function showReportGeneratorForm($contract)
    {
        echo "<form method='post' name='report_form' id='report_form' style='margin: 1rem 0px'
               action='" . Toolbox::getItemTypeFormURL('PluginManageentitiesContractpoint') . "'>";
        echo "<table class='tab_cadre_fixe center'><tbody>";
        echo "<tr><th colspan='4'>" . __('Rebill a month', 'manageentities') . "</th></tr>";
        $months = Toolbox::getMonthsOfYearArray();
        echo "<tr><td>";
        echo "<div><label for='month' style='margin-right: 4px'>" . __('Month', 'manageentities') . "</label>";
        Dropdown::showFromArray('month', $months);
        echo "</div></td>";

        echo "<td>
                <div>
                    <label for='year' style='margin-right: 4px'>" . __('Year', 'manageentities') . "</label>";
        Dropdown::showNumber('year', [
            'max' => date('Y'),
            'min' => date('Y', strtotime($contract->fields['begin_date'])),
            'value' => date('Y')
        ]);
        echo "</div>
            </td>";

        echo "<td><div><label for='billing' style='margin-right: 4px'>" . __(
                "Update contract's points",
                'manageentities'
            ) . "</label>";
        echo "<input type='checkbox' name='billing' checked='checked'/></td>";

        echo "<td>";
        echo Html::hidden('id', ['value' => $contract->getID()]);
        echo Html::submit(
            "<i class='fas fa-envelope'></i>&nbsp;" . __('Send bill', 'manageentities'),
            ['name' => 'generate_bill']
        );
        echo "</td></tr>";
        echo "</tbody></table>";
        Html::closeForm();
    }


    function showContracts($instID)
    {
        global $DB, $CFG_GLPI;

        PluginManageentitiesEntity::showManageentitiesHeader(__('Associated assistance contracts', 'manageentities'));

        $entitiesID = "'" . implode("', '", $instID) . "'";
        $config = PluginManageentitiesConfig::getInstance();

        $query = "SELECT `glpi_contracts`.*,
                       `" . $this->getTable() . "`.`contracts_id`,
                       `" . $this->getTable() . "`.`management`,
                       `" . $this->getTable() . "`.`contract_type`,
                       `" . $this->getTable() . "`.`is_default`,
                       `" . $this->getTable() . "`.`id` as myid
        FROM `" . $this->getTable() . "`, `glpi_contracts`
        WHERE `" . $this->getTable() . "`.`contracts_id` = `glpi_contracts`.`id`
        AND `" . $this->getTable() . "`.`entities_id` IN (" . $entitiesID . ")
        ORDER BY `glpi_contracts`.`begin_date`, `glpi_contracts`.`name`";
        $result = $DB->query($query);
        $number = $DB->numrows($result);

        if ($number) {
            echo "<form method='post' action=\"./entity.php\">";
            echo "<div align='center'><table class='tab_cadre_me center'>";
            echo "<tr><th>" . __('Name') . "</th>";
            echo "<th>" . _x('phone', 'Number') . "</th>";
            echo "<th>" . __('Comments') . "</th>";
            if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
                echo "<th>" . __('Mode of management', 'manageentities') . "</th>";
                echo "<th>" . __('Type of service contract', 'manageentities') . "</th>";
            } elseif ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
                echo "<th>" . __('Type of service contract', 'manageentities') . "</th>";
            }
            echo "<th>" . __('Used by default', 'manageentities') . "</th>";
            if ($this->canCreate() && sizeof($instID) == 1) {
                echo "<th>&nbsp;</th>";
            }
            echo "</tr>";

            $used = [];

            while ($data = $DB->fetchArray($result)) {
                $used[] = $data["contracts_id"];

                echo "<tr class='" . ($data["is_deleted"] == '1' ? "_2" : "") . "'>";
                echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.form.php?id=" . $data["contracts_id"] . "\">" . $data["name"] . "";
                if ($_SESSION["glpiis_ids_visible"] || empty($data["name"])) {
                    echo " (" . $data["contracts_id"] . ")";
                }
                echo "</a></td>";
                echo "<td class='center'>" . $data["num"] . "</td>";
                echo "<td class='center'>" . nl2br($data["comment"]) . "</td>";
                if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
                    echo "<td class='center'>" . self::getContractManagement($data["management"]) . "</td>";
                    echo "<td class='center'>" . self::getContractType($data['contract_type']) . "</td>";
                } elseif ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
                    echo "<td class='center'></td>";
                    //               echo "<td class='center'>".self::getContractType($data['contract_type'])."</td>";
                }
                echo "<td class='center'>";
                if (sizeof($instID) == 1) {
                    if ($data["is_default"]) {
                        echo __('Yes');
                    } else {
                        Html::showSimpleForm(
                            $CFG_GLPI['root_doc'] . '/plugins/manageentities/front/entity.php',
                            'contractbydefault',
                            __('No'),
                            ['myid' => $data["myid"], 'entities_id' => $_SESSION["glpiactive_entity"]]
                        );
                    }
                } else {
                    echo Dropdown::getYesNo($data["is_default"]);
                }
                echo "</td>";
                if ($this->canCreate() && sizeof($instID) == 1) {
                    echo "<td class='center' class='tab_bg_2'>";

                    Html::showSimpleForm(
                        $CFG_GLPI['root_doc'] . '/plugins/manageentities/front/entity.php',
                        'deletecontracts',
                        _x('button', 'Delete permanently'),
                        ['id' => $data["myid"]]
                    );
                    echo "</td>";
                }
                echo "</tr>";
            }

            if ($this->canCreate() && sizeof($instID) == 1) {
                if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
                    echo "<tr class='tab_bg_1'><td colspan='6' class='center'>";
                } elseif ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
                    echo "<tr class='tab_bg_1'><td colspan='5' class='center'>";
                } else {
                    echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
                }
                echo "<input type='hidden' name='entities_id' value='" . $_SESSION["glpiactive_entity"] . "'>";
                Dropdown::show('Contract', [
                    'name' => "contracts_id",
                    'used' => $used
                ]);
                echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/setup.templates.php?itemtype=Contract&add=1' target='_blank'><i title=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i></a>";
                echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class='submit'></td>";
                echo "</tr>";
            }
            echo "</table></div>";
            Html::closeForm();
        } else {
            echo "<form method='post' action=\"./entity.php\">";
            echo "<div align='center'><table class='tab_cadrehov center'>";
            echo "<tr><th colspan='3'>" . __('Associated assistance contracts', 'manageentities') . ":</th></tr>";
            echo "<tr><th>" . __('Name') . "</th>";
            echo "<th>" . _x('phone', 'Number') . "</th>";
            echo "<th>" . __('Comments') . "</th>";

            echo "</tr>";
            if ($this->canCreate()) {
                echo "<tr class='tab_bg_1'><td class='center'>";
                echo "<input type='hidden' name='entities_id' value=" . $_SESSION["glpiactive_entity"] . ">";
                Dropdown::show('Contract', ['name' => "contracts_id"]);
                echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/setup.templates.php?itemtype=Contract&add=1' target='_blank'>
            <i title=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i></a>";
                echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"" . _sx(
                        'button',
                        'Add'
                    ) . "\" class='submit'>";
                echo "</td><td></td>";
                echo "</tr>";
            }
            echo "</table></div>";
            Html::closeForm();
        }
    }

    /**
     * Dropdown list contract management
     *
     * @param type $name
     * @param type $value
     * @param type $rand
     *
     * @return boolean
     */
    static function dropdownContractManagement($name, $value = 0, $rand = null)
    {
        $contractManagements = [
            self::MANAGEMENT_NONE => Dropdown::EMPTY_VALUE,
            self::MANAGEMENT_QUARTERLY => __('Quarterly', 'manageentities'),
            self::MANAGEMENT_ANNUAL => __('Annual', 'manageentities')
        ];

        if (!empty($contractManagements)) {
            if ($rand == null) {
                return Dropdown::showFromArray($name, $contractManagements, ['value' => $value]);
            } else {
                return Dropdown::showFromArray($name, $contractManagements, ['value' => $value, 'rand' => $rand]);
            }
        } else {
            return false;
        }
    }

    /**
     * Return the name of contract management
     *
     * @param type $value
     *
     * @return string
     */
    static function getContractManagement($value)
    {
        switch ($value) {
            case self::MANAGEMENT_NONE :
                return Dropdown::EMPTY_VALUE;
            case self::MANAGEMENT_QUARTERLY :
                return __('Quarterly', 'manageentities');
            case self::MANAGEMENT_ANNUAL :
                return __('Annual', 'manageentities');
            default :
                return "";
        }
    }

    /**
     * dropdown list of the types of contract
     *
     * @param type $name
     * @param type $value
     * @param type $rand
     * @param type $on_change
     *
     * @return boolean
     */
    static function dropdownContractType($name, $value = 0, $rand = null)
    {
        $config = PluginManageentitiesConfig::getInstance();


        $contractTypes = self::get_contract_type();


        if (!empty($contractTypes)) {
            if ($rand == null) {
                return Dropdown::showFromArray($name, $contractTypes, ['value' => $value]);
            } else {
                return Dropdown::showFromArray($name, $contractTypes, ['value' => $value, 'rand' => $rand]);
            }
        } else {
            return false;
        }
    }


    static function checkRemainingOpenContractDays($contracts_id)
    {
        global $DB;

        $query = "SELECT count(*) as count
                FROM `glpi_plugin_manageentities_contractdays`
                LEFT JOIN `glpi_plugin_manageentities_contractstates`
                    ON (`glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id` = `glpi_plugin_manageentities_contractstates`.`id`)
                WHERE `glpi_plugin_manageentities_contractdays`.`contracts_id` = " . $contracts_id . " 
                AND `glpi_plugin_manageentities_contractstates`.`is_active` = 1";

        $result = $DB->query($query);
        while ($data = $DB->fetchArray($result)) {
            if ($data['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    static function get_contract_type($type = 0)
    {
        $list_types = [];
        $list_types[self::CONTRACT_POINTS] = __('Points', 'manageentities');
        $list_types[self::CONTRACT_UNLIMITED] = __('Unlimited', 'manageentities');

        if ($type == 0) {
            return $list_types;
        } else {
            return $list_types[$type];
        }
    }

    static function getDefaultValues()
    {
        return [
            'renewal_number' => 0,
            'initial_credit' => 0,
            'current_credit' => 0,
            'credit_consumed' => 0,
            'contact_email' => '',
            'contract_cancelled' => 1,
            'contract_type' => 1,
        ];
    }

    function getEmpty()
    {
        parent::getEmpty();
        $this->fields['current_credit'] = 0;
    }

    /**
     * Displaying message solution if the ticket is link to a JIRA ticket
     *
     * @param $params
     *
     * @return bool
     */
    static function messageTask($params)
    {
        if (isset($params['item'])) {
            $item = $params['item'];
            $cridetail = new PluginManageentitiesCriDetail();
            $contractpoints = new PluginManageentitiesContractpoint();
            if ($item->getType() == TicketTask::getType()) {
                if ($_POST['parenttype'] == Ticket::getType() && !$cridetail->getFromDBByCrit(
                        ['tickets_id' => $_POST['tickets_id']]
                    )) {
                    $text = __("No contract link to this ticket", 'manageentities');
                    echo "<tr class='tab_bg_1 warning'><td colspan='4'><i class='fas fa-exclamation-triangle fa-2x'></i> $text</td></tr>";
                } elseif ($_POST['parenttype'] == Ticket::getType() &&
                    $cridetail->getFromDBByCrit(['tickets_id' => $_POST['tickets_id']]) &&
                    $contractpoints->getFromDBByCrit(['contracts_id' => $cridetail->fields['contracts_id']]) &&
                    $contractpoints->fields['contract_cancelled'] == 1 &&
                    $contractpoints->fields['current_credit'] < $contractpoints->fields['threshold']) {
                    $text = sprintf(
                        __("The contract is cancelled and the current credit is under %s", 'manageentities'),
                        $contractpoints->fields['threshold']
                    );
                    echo "<tr class='tab_bg_1 warning'><td colspan='4'><i class='fas fa-exclamation-triangle fa-2x'></i> $text</td></tr>";
                }
            }
        }
        return true;
    }

    /**
     * @param $name
     *
     * @return array
     */
    static function cronInfo($name)
    {
        switch ($name) {
            case 'AutoReport':
                return [
                    'description' => __('Auto intervention report', 'manageentities')
                ]; // Optional
                break;
        }
        return [];
    }

    /**
     * Cron action on review : alert group supervisors if a review is planned
     *
     * @param CronTask $task for log, display information if NULL? (default NULL)
     *
     * @return void
     **/
    static function cronAutoReport($task = null)
    {
        global $CFG_GLPI;
        if (!$CFG_GLPI["notifications_mailing"]) {
            return 0;
        }

        $config = PluginManageentitiesConfig::getInstance();
        if ($config->fields['hourorday'] != PluginManageentitiesConfig::POINTS) {
            return true;
        }

        $old_memory = ini_set("memory_limit", "-1");
        $old_execution = ini_set("max_execution_time", "0");

        $begin_date = date('Y-m-d', strtotime('first day of last month'));
        $end_date = date('Y-m-d', strtotime('last day of last month')) . " 23:59:59";
        $month_number = date('n', strtotime('last day of last month'));
        $year = date('Y', strtotime('last day of last month'));

        $cron_status = 0;
        $contract = new PluginManageentitiesContractpoint();
        $entity = new Entity();
        $contracts = $contract->find();
        $task = new TicketTask();
        $ticket = new Ticket();
        $contractGlpi = new Contract();
        foreach ($contracts as $data) {
            if ($data['contract_cancelled'] == 1) {
                continue;
            }
            if (!$contractGlpi->getFromDB($data['contracts_id'])) {
                continue;
            }

            $obj = new PluginManageentitiesContractpoint();
            $obj->getEmpty();
            $obj->fields = $data;

            self::generateReport($obj, $month_number, $year, true);
        }

        //SEND mail for task without contract

        $tasks = $task->find([
            'taskcategories_id' => $config->fields['category_outOfContract'],
            ['date' => ['>=', $begin_date]],
            ['date' => ['<=', $end_date]]
        ]);
        $entityReport = [];
        $tick = new Ticket();
        foreach ($tasks as $t) {
            $tick->getFromDB($t['tickets_id']);
            $entityReport[$tick->fields['entities_id']][$t['tickets_id']][] = $t;
        }

        $taskCategories = array();

        foreach ($entityReport as $entityNum => $TicketsbyEntity) {
            $pdf = self::pdfSetup();

            $entity->getFromDB($entityNum);

            $html = self::reportHeader($entity, $month_number, $year);

            $footer = $config->getField('footer');

            $totalTime = 0;
            foreach ($TicketsbyEntity as $tickets_id => $tasks) {
                $ticket->getFromDB($tickets_id);
                $html .= self::reportTicket(
                    $ticket,
                    $tasks,
                    $taskCategories,
                    $begin_date,
                    $end_date,
                    $totalTime
                );
            }
            $html .= self::reportEndTickets();

            $html .= "
      <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
                . __("Total time counted :", 'manageentities') . " " . $totalTime . " " . _n('minute', 'minutes', 2) . " <br> <br>
      
           
              
              </div>
        ";
            $html .= "     
         <div id=\"footer\" style=\" text-align: center;padding-bottom: 20px; font-weight: bold;\">"
                . nl2br($footer) . " <br> <br>
      
           
              
              </div>
        ";
            $pdf->setPrintFooter(false);
            $pdf->writeHTML($html, true, true, true, false, 'center');

            $fileName = self::reportGenerateFileName($entity, $month_number, $year);

            $pdf->Output(GLPI_DOC_DIR . "/_tmp/" . $fileName, 'F');
            $document = new Document();
            $doc_id = $document->add(['_filename' => [$fileName], 'entities_id' => $entity->getID()]);

            $mmail = new GLPIMailer();
            $mmail->AddCustomHeader("Auto-Submitted: auto-generated");
            // For exchange
            $mmail->AddCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
            $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"], false);
            $months = Toolbox::getMonthsOfYearArray();
            $subject = sprintf(__("Billinf of %s", 'manageentities'), $months[$month_number] . ' ' . $year);

            $config = PluginManageentitiesConfig::getInstance();
            $user_email = $config->fields['email_billing_destination'];

            if (!empty($user_email)) {
                $mmail->AddAddress($user_email, $user_email);
                $mmail->Subject = $subject;
                $mmail->Body = sprintf(
                    __("Please find attached billing of %s", 'manageentities'),
                    $months[$month_number] . ' ' . $year
                );
                $mmail->MessageID = "GLPI-" . Ticket::getType() . "-" . $ticket->getID() . "." . time() . "." . rand(
                    ) . "@" . php_uname('n');

                $document->getFromDB($doc_id);
                $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
                $mmail->addAttachment(
                    $path,
                    $document->fields['filename']
                );
                if ($mmail->Send()) {
                    Session::addMessageAfterRedirect(__('The email has been sent', 'manageentities'), false, INFO);
                } else {
                    Session::addMessageAfterRedirect(
                        __('Error sending email with document', 'manageentities') . "<br/>" . $mmail->ErrorInfo,
                        false,
                        ERROR
                    );
                    Toolbox::logInFile(
                        'mail_manageentities',
                        __('Error sending email with document', 'manageentities') . "<br/>" . $mmail->ErrorInfo,
                        true
                    );
                }
            } else {
                Session::addMessageAfterRedirect(
                    __('Error sending email with document : No email', 'manageentities'),
                    false,
                    ERROR
                );
                Toolbox::logInFile(
                    'mail_manageentities',
                    __('Error sending email with document : No email', 'manageentities'),
                    true
                );
            }
        }

        ini_set("memory_limit", $old_memory);
        ini_set("max_execution_time", $old_execution);

        return $cron_status;
    }

    /**
     * For a point contract, generate a report for a given month and send it by mail to the contract's registered contact
     * @param $contract PluginManageentitiesContractpoint Contract for the report
     * @param $month string month, n format
     * @param $year string year, Y format
     * @param $billing boolean if true, will update $contract's available points
     * @return void
     */
    public static function generateReport($contract, $month, $year, $billing)
    {
        global $CFG_GLPI;
        $config = PluginManageentitiesConfig::getInstance();
        $baseContract = new Contract();
        $criDetail = new PluginManageentitiesCriDetail();
        $entity = new Entity();
        $ticket = new Ticket();
        $task = new TicketTask();

        $taskCategories = array();

        $date = mktime(0, 0, 0, $month, 1, $year);
        $begin_date = date('Y-m-d', $date);
        $endOfTheMonth = date('t', $date);
        $end_date = date('Y-m-d h:i:s', mktime(23, 59, 59, $month, $endOfTheMonth, $year));

        // basic contract infos
        $baseContract->getFromDB($contract->fields['contracts_id']);
        // entity associated with the contract
        $entity->getFromDB($baseContract->getEntityID());
        // interventions linked to the contract
        $criDetails = $criDetail->find(['contracts_id' => $contract->getEntityID()]);

        $pdf = self::pdfSetup();

        $html = self::reportHeader($entity, $month, $year, $contract);

        $footer = $config->fields['footer'];
        if (!empty($contract->fields['footer'])) {
            $footer = $contract->fields['footer'];
        }

        $contractPoints = 0;
        $totalTime = 0;

        foreach ($criDetails as $cri) {
            if ($ticket->getFromDB($cri['tickets_id'])) {
                $tasks = $task->find([
                    'tickets_id' => $cri['tickets_id'],
                    ['date' => ['>=', $begin_date]],
                    ['date' => ['<=', $end_date]]
                ]);
                $html .= self::reportTicket(
                    $ticket,
                    $tasks,
                    $taskCategories,
                    $begin_date,
                    $end_date,
                    $totalTime,
                    $contract,
                    $contractPoints
                );
            }
        }
        $html .= self::reportEndTickets();

        $renewal_number = 0;
        $rest = $contract->fields['current_credit'] - $contractPoints;
        if ($rest <= $contract->fields['threshold'] &&
            $contract->fields['contract_cancelled'] == 0 &&
            $contract->fields['contract_type'] == self::CONTRACT_POINTS &&
            $contract->fields['initial_credit'] > 0
        ) {
            while ($rest <= 0) {
                $rest += $contract->fields['initial_credit'];
                $renewal_number++;
            }
        }
        $info['id'] = $contract->fields['id'];
        $info['current_credit'] = $rest;
        $info['renewal_number'] = $contract->fields['renewal_number'] + $renewal_number;
        $info['credit_consumed'] = $contract->fields['credit_consumed'] + $contractPoints;

        if ($billing) {
            $contract->update($info);
        }

        $html .= "
      <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
            . __("Total points counted :", 'manageentities') . " " . $contractPoints . " <br> <br>"
            . __("Points remaining :", 'manageentities') . " " . $rest . " <br> <br>"
            . __("Number of renewal :", 'manageentities') . " " . $renewal_number . " <br> <br>
      
           
              
              </div>
        ";
        $html .= "     
         <div id=\"footer\" style=\" text-align: center;padding-bottom: 20px; font-weight: bold;\">"
            . nl2br($footer) . " <br> <br>
      
           
              
              </div>
        ";

        $pdf->setPrintFooter(false);
        $pdf->writeHTML($html, true, true, true, false, 'center');

        $fileName = self::reportGenerateFileName($entity, $month, $year);

        $pdf->Output(GLPI_DOC_DIR . "/_tmp/" . $fileName, 'F');
        $document = new Document();
        $doc_id = $document->add(['_filename' => [$fileName], 'entities_id' => $entity->getID()]);

        $mmail = new GLPIMailer();
        $mmail->AddCustomHeader("Auto-Submitted: auto-generated");
        // For exchange
        $mmail->AddCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
        $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"], false);
        $months = Toolbox::getMonthsOfYearArray();
        $subject = sprintf(__("Billinf of %s", 'manageentities'), $months[$month] . ' ' . $year);

        $user_email = $contract->fields['contact_email'];

        if (!empty($user_email)) {
            $mmail->AddAddress($user_email, $user_email);
            $mmail->Subject = $subject;
            $mmail->Body = sprintf(
                __("Please find attached billing of %s", 'manageentities'),
                $months[$month] . ' ' . $year
            );
            $mmail->MessageID = "GLPI-" . Ticket::getType() . "-" . $ticket->getID() . "." . time() . "." . rand(
                ) . "@" . php_uname('n');

            $document->getFromDB($doc_id);
            $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
            $mmail->addAttachment(
                $path,
                $document->fields['filename']
            );
            if ($mmail->Send()) {
                Session::addMessageAfterRedirect(__('The email has been sent', 'manageentities'), false, INFO);
            } else {
                Session::addMessageAfterRedirect(
                    __('Error sending email with document', 'manageentities') . "<br/>" . $mmail->ErrorInfo,
                    false,
                    ERROR
                );
            }
        } else {
            Session::addMessageAfterRedirect(
                __(
                    'Error sending email with document : No email',
                    'manageentities'
                ) . " " . $contract->fields['contracts_id'],
                false,
                ERROR
            );
        }

        if ($renewal_number > 0 && !empty($config->fields['email_billing_destination'])) {
            $user_email = $config->fields['email_billing_destination'];
            $mmail->AddAddress($user_email, $user_email);
            $mmail->Subject = $subject;
            $mmail->Body = sprintf(
                __("Please find attached billing of %s", 'manageentities'),
                $months[$month] . ' ' . $year
            );
            $mmail->MessageID = "GLPI-" . Ticket::getType() . "-" . $ticket->getID() . "." . time() . "." . rand(
                ) . "@" . php_uname('n');

            $document->getFromDB($doc_id);
            $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
            $mmail->addAttachment(
                $path,
                $document->fields['filename']
            );
            if ($mmail->Send()) {
                Session::addMessageAfterRedirect(__('The email has been sent', 'manageentities'), false, INFO);
            } else {
                Session::addMessageAfterRedirect(
                    __('Error sending email with document for renewal', 'manageentities') . "<br/>" . $mmail->ErrorInfo,
                    false,
                    ERROR
                );
            }
        } elseif ($renewal_number > 0) {
            Session::addMessageAfterRedirect(
                __('Error sending email with document : No email', 'manageentities'),
                false,
                ERROR
            );
            Toolbox::logInFile(
                'mail_manageentities',
                __('Error sending email with document : No email', 'manageentities'),
                true
            );
        }
    }

    /**
     * Create a basic PDF object
     * @return GLPIPDF
     */
    private static function pdfSetup()
    {
        $pdf = new GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('GLPI');
        $pdf->SetAuthor('GLPI');
        $pdf->SetTitle("");
        $pdf->SetHeaderData('', '', "", '');

        // font
        $font = 'helvetica';
        $fontsize = 10;
        if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
            $font = $_SESSION['glpipdffont'];
        }
        $pdf->setHeaderFont([$font, 'B', $fontsize]);
        $pdf->setFooterFont([$font, 'B', $fontsize]);
        $pdf->SetFont($font, '', $fontsize);

        // margins
        $pdf->SetMargins(10, 15, 10);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);

        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Create a header for the report and start the table for the tickets (for reportTicket)
     * @param $entity Entity
     * @param $month mixed format n, 1-12, month of the report
     * @param $year mixed format Y, XXXX, year of the report
     * @param $contract PluginManageentitiesContractpoint|null contract to which the report is linked
     * @return string HTML, contain <header> followed by a <table> and its <thead>, end with an open <tbody>
     */
    private static function reportHeader($entity, $month, $year, $contract = null)
    {
        $config = new PluginManageentitiesConfig();

        $img = $config->fields['picture_logo'];
        if ($contract) {
            if (!empty($contract->fields['picture_logo'])) {
                $img = $contract->fields['picture_logo'];
            }
        }

        $months = Toolbox::getMonthsOfYearArray();
        $html = " <header >
            <div style=\" text-align: left;\">";
        $dataimg = "";
        if (is_file(GLPI_PICTURE_DIR . '/' . $img)) {
            $dataimg = base64_encode(file_get_contents(GLPI_PICTURE_DIR . '/' . $img));
        }

        $html .= '<img width="200px" height="100px" src="@' . $dataimg . '">';
        $html .= "    </div>
    
        </header>
        <br>
        <div id=\"client\" style=\" text-align: right;padding-bottom: 20px\">
        " . $entity->getFriendlyName() . "<br>
         " . $entity->getField('address') . "<br>
         " . $entity->getField('postcode') . " " . $entity->getField('town') . "<br><br>

      
        </div>
           <table style=\"margin:auto;  \">
             
                  <tr style=\" text-align: center;font-weight: bold; font-size: 12px \">
                        <td colspan=\"2\" style=\"border: 1px solid black;\">" . __(
                "Statement of intervention tickets",
                "manageentities"
            ) . "</td>
                        
                        <td style=\" border: 1px solid black;\">" . $months[$month] . " " . $year . "</td>
                    </tr>
            
            </table>
            <br>";

        if ($contract) {
            $html .= "
            <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
                . __("Point balance :", 'manageentities') . " " . $contract->fields['current_credit'] . " <br>
              </div>";
        }

        $html .= "<table style=\"margin:auto;\">
             <thead >
                  <tr>
                        <td style=\"border: 1px solid black;\">" . __('Date') . "</td>
                        <td colspan=\"5\" style=\"border: 1px solid black;\">" . __(
                'Client request, action and solve',
                'manageentities'
            ) . "</td>
                        <td style=\"border: 1px solid black;\">" . __('Time', 'manageentities') . "</td>
                        <td style=\"border: 1px solid black;\">" . __('Category') . "</td>";
        if ($contract) {
            $html .= "<td style=\"border: 1px solid black;\">" . __('Points', 'manageentities') . "</td>";
        }
        $html .= "</tr>
            </thead>
          <tbody>";

        return $html;
    }

    /**
     * create lines for all tasks of the given ticket
     * @param $ticket Ticket
     * @param $tasks array result from Task->find()
     * @param $taskCategories array initialise as empty array and do not touch until all tickets have been passed through this function
     * @param $begin_date string date format 'Y-m-d', will be used to look if a solution to $ticket was found in the wanted time period
     * @param $end_date string date format 'Y-m-d h:i:s', will be used to look if a solution to $ticket was found in the wanted time period
     * @param $totalTime int will add the time spent on all $tasks to it
     * @param $contract PluginManageentitiesContractpoint|null if defined, will show the number of points spent on each tickets
     * @param $contractPoints int if defined and $contract is too, will add the point spent on all $tasks to it
     * @return string html string containing a <tr></tr>
     */
    private static function reportTicket(
        $ticket,
        $tasks,
        &$taskCategories,
        $begin_date,
        $end_date,
        &$totalTime,
        $contract = null,
        &$contractPoints = null
    ) {
        $solution = new ITILSolution();
        $html = '';
        $is_sol = false;
        $solution->getEmpty();
        if (!in_array($ticket->fields['status'], Ticket::getNotSolvedStatusArray())) {
            $is_sol = $solution->getFromDBByCrit([
                'itemtype' => Ticket::getType(),
                'items_id' => $ticket->getID(),
                'status' => CommonITILValidation::ACCEPTED,
                ['date_creation' => ['>=', $begin_date]],
                ['date_creation' => ['<=', $end_date]]
            ]);
        }
        $count = ((count($tasks) ?? 0) + (($is_sol == true) ? 1 : 0));
        $colspan = $contract ? 8 : 7;

        if ($count != 0) {
            $count++;
            $html .= "<tr>";
            $html .= "<td rowspan=\"$count\" style=\"border: 1px solid black;text-align: center; vertical-align: middle;\">";
            $html .= Html::convDate($ticket->fields['date']);
            $html .= "<br>";
            $html .= sprintf(__('Ticket n° %s', 'manageentities'), "<br> " . $ticket->getID());
            $html .= "</td>";
            $html .= "<td colspan=\"$colspan\" style=\"border: 1px solid black;\">";
            $html .= nl2br(Html::clean($ticket->fields['content']));
            $html .= "</td>";
            $html .= "</tr>";
        }

        foreach ($tasks as $t) {
            $totalTime += ($t['actiontime'] / 60);

            $taskCategory = new TaskCategory();
            // if category wasn't used yet, load it and store it to avoid having to do it again
            if (!array_key_exists($t['taskcategories_id'], $taskCategories)) {
                $taskCategories[$t['taskcategories_id']] = array();
                $categoryFound = $taskCategory->getFromDB($t['taskcategories_id']);
                if ($categoryFound) {
                    $taskCategories[$t['taskcategories_id']]['category'] = $taskCategory;
                }
                if ($contract) {
                    $mappingCategorySlice = new PluginManageentitiesMappingCategorySlice();
                    $sliceFound = $mappingCategorySlice->getFromDBByCrit([
                        'plugin_manageentities_contractpoints_id' => $contract->getID(),
                        'taskcategories_id' => $t['taskcategories_id']
                    ]);
                    if ($sliceFound) {
                        $taskCategories[$t['taskcategories_id']]['slice'] = $mappingCategorySlice;
                    }
                }
            }

            if ($contract) {
                // calculate quantity of points used for the current task
                $points = $contract->fields['points_slice'];
                $minute_slice = $contract->fields['minutes_slice'];
                if (array_key_exists('slice', $taskCategories[$t['taskcategories_id']])) {
                    $points = $taskCategories[$t['taskcategories_id']]['slice']->fields['points_slice'];
                    $minute_slice = $taskCategories[$t['taskcategories_id']]['slice']->fields['minutes_slice'];
                }
                if ($minute_slice != 0) {
                    $number = ceil((float)$t['actiontime'] / 60 / (float)$minute_slice);
                    $points = $points * $number;
                }
                $contractPoints += $points;
            }

            $html .= "<tr>";
            $html .= "<td  style=\"border: 1px solid black;text-align: center\">";
            $html .= Html::convDate($t['date']);
            $html .= "</td>";

            $html .= "<td colspan=\"4\" style=\"border: 1px solid black;\">";
            $html .= nl2br(Html::clean($t['content']));
            $html .= "</td>";

            $html .= "<td  style=\"border: 1px solid black;\">";
            $html .= ($t['actiontime'] / 60) . " " . _n('minute', 'minutes', 2);
            $html .= "</td>";

            $html .= "<td  style=\"border: 1px solid black;\">";
            if (array_key_exists('category', $taskCategories[$t['taskcategories_id']])) {
                $html .= $taskCategories[$t['taskcategories_id']]['category']->fields['name'];
            }
            $html .= "</td>";

            if ($contract) {
                $html .= "<td  style=\"border: 1px solid black;\">";
                $html .= $points;
                $html .= "</td>";
            }

            $html .= "</tr>";
        }

        if ($solution->getID() != 0 && $solution->getID() != -1 && $solution->getID() != '') {
            $html .= "<tr>";
            $html .= "<td colspan=\"$colspan\" style=\"border: 1px solid black;\">";
            $html .= nl2br(Html::clean($solution->fields['content']));
            $html .= "</td>";
            $html .= "</tr>";
        }

        return $html;
    }

    /**
     * Call after looping on tickets with reportTicket to close <tbody> and <table>
     * @return string HTML
     */
    private static function reportEndTickets()
    {
        return "</tbody>
            </table>
            <br>";
    }

    /**
     * Define file name based on entity name, then remove all characters that might cause an error on save
     * @param $entity Entity
     * @param $month mixed datetime format n, 1 to 12
     * @param $year mixed datetime format Y, XXXX
     * @return false|string|null
     */
    private static function reportGenerateFileName($entity, $month, $year)
    {
        $months = Toolbox::getMonthsOfYearArray();
        $fileName = $entity->getFriendlyName() . "-" . $months[$month] . $year . ".pdf";
        $fileName = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $fileName);
        return mb_ereg_replace("([\.]{2,})", '', $fileName);
    }

}
