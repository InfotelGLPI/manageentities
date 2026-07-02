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
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Session;
use Toolbox;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class ContractDay extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    // From CommonDBTM
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        $config = Config::getInstance();
        $Cri = new Cri();

        if (Session::getCurrentInterface() == 'helpdesk'
            && $config->fields['choice_intervention'] == Config::REPORT_INTERVENTION
            && $Cri->canView()) {
            return __('Interventions reports', 'manageentities');
        } elseif (Session::getCurrentInterface() == 'central'
            && $config->fields['choice_intervention'] == Config::PERIOD_INTERVENTION) {
            return _n('Period of contract', 'Periods of contract', $nb, 'manageentities');
        }
        return _n('Period of contract', 'Periods of contract', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public function rawSearchOptions()
    {
        $config = Config::getInstance();

        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => ContractDay::getTypeName(1),
        ];

        $tab[] = [
            'id' => '1',
            'table' => $this->getTable(),
            'field' => 'name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemlink_type' => $this->getType(),
        ];

        $tab[] = [
            'id' => '2',
            'table' => $this->getTable(),
            'field' => 'begin_date',
            'name' => __('Start date'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id' => '3',
            'table' => $this->getTable(),
            'field' => 'end_date',
            'name' => __('End date'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id' => '4',
            'table' => $this->getTable(),
            'field' => 'nbday',
            'name' => __('Initial credit', 'manageentities'),
            'datatype' => 'decimal',
        ];

        //      $tab[5]['table']    = 'glpi_plugin_manageentities_critypes';
        //      $tab[5]['field']    = 'name';
        //      $tab[5]['name']     = __('Intervention type', 'manageentities');
        //      $tab[5]['datatype'] = 'dropdown';

        $tab[] = [
            'id' => '6',
            'table' => $this->getTable(),
            'field' => 'report',
            'name' => __('Postponement', 'manageentities'),
            'datatype' => 'decimal',
        ];

        $tab[] = [
            'id' => '7',
            'table' => 'glpi_contracts',
            'field' => 'name',
            'name' => __('Contract'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '8',
            'table' => 'glpi_plugin_manageentities_contractstates',
            'field' => 'name',
            'name' => __('State of contract', 'manageentities'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '9',
            'table' => $this->getTable(),
            'field' => 'id',
            'credit_remaining' => true,
            'name' => __('Credit remaining', 'manageentities'),
            'datatype' => 'specific',
        ];

        if ($config->fields['hourorday'] == Config::DAY) {
            $tab[] = [
                'id' => '10',
                'table' => $this->getTable(),
                'field' => 'contract_type',
                'name' => __('Type of service contract', 'manageentities'),
                'datatype' => 'specific',
            ];
        }

        $tab[] = [
            'id' => '30',
            'table' => $this->getTable(),
            'field' => 'id',
            'name' => __('ID'),
        ];

        if (Session::getCurrentInterface() == 'central') {
            $tab[] = [
                'id' => '80',
                'table' => 'glpi_entities',
                'field' => 'completename',
                'name' => _n('Entity', 'Entities', 1),
                'datatype' => 'dropdown',
            ];
        }

        return $tab;
    }

    /**
     * @param $field
     * @param $values
     * @param $options   array
     **/
    public static function getSpecificValueToDisplay($field, $values, $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'id':
                if (isset($options['searchopt']['credit_remaining']) && $options['searchopt']['credit_remaining']) {
                    $contractDay = new self();
                    $contract = $contractDay->find(['id' => $values['id']]);
                    $contract = reset($contract);
                    $contract['contractdays_id'] = $values['id'];
                    $resultCriDetail = CriDetail::getCriDetailData($contract);
                    $nbtheoricaldays = 0;
                    if ($resultCriDetail['resultOther']['default_criprice'] > 0) {
                        $nbtheoricaldays = $resultCriDetail['resultOther']['reste_montant'] / $resultCriDetail['resultOther']['default_criprice'];
                    }
                    return Html::formatNumber($nbtheoricaldays, false);
                }
                break;
            case 'contract_type':
                $contract = new Contract();
                return $contract->getContractType($values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Display tab for each contractDay
     * */
    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(CriDetail::class, $ong, $options);
        $this->addStandardTab(CriPrice::class, $ong, $options);
        $this->addStandardTab(InterventionSkateholder::class, $ong, $options);
        $this->addStandardTab('Document', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }


    /**
     * Add number day in contractday
     *
     * @param type $values
     */
    public function addNbDay($values)
    {
        //      if ($this->getFromDBbyTypeAndContract($values["plugin_manageentities_critypes_id"], $values["contracts_id"], $values["entities_id"])) {
        if ($this->getFromDBByCrit([
            'plugin_manageentities_critypes_id' => $values["plugin_manageentities_critypes_id"],
            'contracts_id' => $values["contracts_id"],
            'entities_id' => $values["entities_id"],
        ])) {
            $this->update([
                'id' => $this->fields['id'],
                'nbday' => $values["nbday"],
                'entities_id' => $values["entities_id"],
            ]);
        } else {
            $this->add([
                'plugin_manageentities_critypes_id' => $values["plugin_manageentities_critypes_id"],
                'contracts_id' => $values["contracts_id"],
                'nbday' => $values["nbday"],
                'entities_id' => $values["entities_id"],
            ]);
        }
    }

    /**
     * Display the contractday form
     *
     * @param $ID integer ID of the item
     * @param $options array
     *     - target filename : where to go when done.
     *     - withtemplate boolean : template or basic item
     *
     * @return boolean item found
     * */
    public function showForm($ID, $options = [])
    {
        global $CFG_GLPI;

        if (!$this->canView()) {
            return false;
        }

        $config      = Config::getInstance();
        $conso       = 0;
        $contract_id = 0;
        $contract    = new \Contract();

        if (isset($options['contract_id'])) {
            $contract_id = $options['contract_id'];
        }

        if ($ID > 0) {
            $this->check($ID, READ);
            $contract_id = $this->fields["contracts_id"];
            $contract->getFromDB($contract_id);
        } else {
            $input = ['contract_id' => $contract_id];
            $this->check(-1, UPDATE, $input);
            $contract->getFromDB($contract_id);
            $options['entities_id'] = $contract->fields['entities_id'];
        }

        $this->setSessionValues();

        if (empty($this->fields['nbday'])) {
            $this->fields['nbday'] = 0;
        }
        if (empty($this->fields['report'])) {
            $this->fields['report'] = 0;
        }

        if (isset($options['showFromPlugin']) && $options['showFromPlugin']) {
            $_SERVER['REQUEST_URI'] = $CFG_GLPI["root_doc"] . "/front/contract.form.php?id=" . $contract_id;
            Session::initNavigateListItems(ContractDay::class, $contract->getName());
            Session::addToNavigateListItems(ContractDay::class, $ID);
        }

        $restrict = [
            "`glpi_plugin_manageentities_contracts`.`entities_id`"  => $contract->fields['entities_id'],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contract->fields['id'],
        ];
        $dbu             = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract  = reset($pluginContracts) ?: [];
        $contract_type   = $pluginContract['contract_type'] ?? 0;
        $unit            = Contract::getUnitContractType($config, $contract_type);

        $is_day      = ($config->fields['hourorday'] == Config::DAY);
        $is_hour     = ($config->fields['hourorday'] == Config::HOUR);
        $unlimited   = ($contract_type == Contract::CONTRACT_TYPE_UNLIMITED);
        $show_credit = $is_day || ($is_hour && !$unlimited);
        $use_price   = ($config->fields['useprice'] == Config::PRICE);

        $contract_link = Toolbox::getItemTypeFormURL('Contract') . '?id=' . (int)$contract->fields['id'];

        ob_start();
        if ($is_day) {
            Contract::dropdownContractType("contract_type", $this->fields['contract_type'] ?? 0);
        }
        $contract_type_html = ob_get_clean();

        ob_start();
        Html::showDateField("begin_date", ['value' => $this->fields["begin_date"]]);
        $begin_date_html = ob_get_clean();

        ob_start();
        Html::showDateField("end_date", ['value' => $this->fields["end_date"]]);
        $end_date_html = ob_get_clean();

        ob_start();
        \Dropdown::show(ContractState::class, [
            'value'  => $this->fields['plugin_manageentities_contractstates_id'],
            'entity' => $this->fields["entities_id"],
        ]);
        $contractstate_html = ob_get_clean();

        $this->fields['contractdays_id'] = $this->fields['id'];
        $resultCriDetail = CriDetail::getCriDetailData($this->fields);
        foreach ($resultCriDetail['result'] as $dataCriDetail) {
            $conso += $dataCriDetail['conso'];
        }

        $conso_unit = $show_credit ? $unit : Contract::getUnitContractType($config, Contract::CONTRACT_TYPE_HOUR);

        $this->initForm($ID, $options);

        $canedit = $this->can($ID > 0 ? $ID : -1, UPDATE);

        TemplateRenderer::getInstance()->display('@manageentities/contractday_form.html.twig', [
            'item'                 => $this,
            'params'               => $options,
            'canedit'              => $canedit,
            'contract_id'          => $contract_id,
            'contract_link'        => $contract_link,
            'contract_name'        => $contract->fields['name'],
            'entities_id'          => $contract->fields['entities_id'],
            'name'                 => $this->fields['name'] ?? '',
            'comment'              => $this->fields['comment'] ?? '',
            'charged'              => (int)($this->fields['charged'] ?? 0),
            'nbday'                => Html::formatNumber($this->fields['nbday']),
            'report'               => Html::formatNumber($this->fields['report']),
            'is_day'               => $is_day,
            'show_credit'          => $show_credit,
            'use_price'            => $use_price,
            'unit'                 => $unit,
            'contract_type_html'   => $contract_type_html,
            'begin_date_html'      => $begin_date_html,
            'end_date_html'        => $end_date_html,
            'contractstate_html'   => $contractstate_html,
            'conso'                => Html::formatNumber($conso),
            'conso_unit'           => $conso_unit,
            'reste'                => Html::formatNumber($resultCriDetail['resultOther']['reste']),
            'depass'               => Html::formatNumber($resultCriDetail['resultOther']['depass']),
            'forfait'              => Html::formatNumber($resultCriDetail['resultOther']['forfait']),
            'reste_montant'        => Html::formatNumber($resultCriDetail['resultOther']['reste_montant']),
        ]);

        return true;
    }

    /**
     * Add a new contract day
     *
     * @param Contract $contract
     * @param type $options
     */
    public static function addNewContractDay(\Contract $contract, $options = [])
    {
        $contract_id = $contract->fields['id'];
        $canEdit = $contract->can($contract_id, UPDATE);
        $addButton = "";

        if (Session::haveRight('plugin_manageentities', UPDATE) && $canEdit) {
            $rand = mt_rand();

            $addButton = "<form method='post' name='contractDays_form'.$rand.'' id='contractDays_form" . $rand . "'
               action='" . Toolbox::getItemTypeFormURL(
                ContractDay::class
            ) . "?contract_id=" . $contract->fields['id'] . "'>";
            $addButton .= Html::hidden('contract_id', ['value' => $contract_id]);
            $addButton .= Html::hidden('id', ['value' => '']);
            $addButton .= Html::submit(_sx('button', 'Add'), ['name' => 'addperiod', 'class' => 'btn btn-primary']);
        }

        if (isset($options['title'])) {
            echo '<table class="tab_cadre_fixe">';
            echo '<tr><th>' . $options['title'] . '</th></tr>';
            echo '<tr class="tab_bg_1">
               <td class="center">';
            echo $addButton;
            Html::closeForm();
            echo '</td></tr></table>';
        } else {
            echo '<tr class="tab_bg_1">
               <td class="center" colspan="' . $options['colspan'] . '">';
            echo $addButton;
            Html::closeForm();
            echo '</td></tr>';
        }
    }

    public static function showForContract(\Contract $contract)
    {
        $rand      = mt_rand();
        $canView   = $contract->can($contract->fields['id'], READ);
        $canEdit   = $contract->can($contract->fields['id'], UPDATE);
        $canCreate = $contract->can($contract->fields['id'], CREATE);
        $config    = Config::getInstance();

        if (!$canView) {
            return false;
        }

        $add_button_html = '';
        if ($canCreate && Session::haveRight('plugin_manageentities', UPDATE)) {
            $add_rand         = mt_rand();
            $add_action_url   = Toolbox::getItemTypeFormURL(ContractDay::class) . '?contract_id=' . (int)$contract->fields['id'];
            $add_button_html  = "<form method='post' name='contractDays_form{$add_rand}' id='contractDays_form{$add_rand}' action='" . htmlspecialchars($add_action_url, ENT_QUOTES) . "'>";
            $add_button_html .= Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            $add_button_html .= Html::hidden('contract_id', ['value' => $contract->fields['id']]);
            $add_button_html .= Html::hidden('id', ['value' => '']);
            $add_button_html .= "<button type='submit' name='addperiod' class='btn btn-primary'><i class='ti ti-plus me-1'></i>" . _sx('button', 'Add') . "</button>";
            $add_button_html .= "</form>";
        }

        $restrict = [
            "`entities_id`"  => $contract->fields['entities_id'],
            "`contracts_id`" => $contract->fields['id'],
            'ORDER'          => '`date_signature` ASC',
        ];
        $restrict_days = [
            "`entities_id`"  => $contract->fields['entities_id'],
            "`contracts_id`" => $contract->fields['id'],
            'ORDER'          => '`begin_date` ASC, `name`',
        ];

        $dbu             = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract  = reset($pluginContracts) ?: [];

        $pluginContractDays = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contractdays", $restrict_days);

        if (!count($pluginContractDays)) {
            return;
        }

        $is_day    = ($config->fields['hourorday'] == Config::DAY);
        $is_hour   = ($config->fields['hourorday'] == Config::HOUR);
        $unlimited = ($pluginContract['contract_type'] ?? 0) == Contract::CONTRACT_TYPE_UNLIMITED;
        $show_credit = $is_day || ($is_hour && !$unlimited);

        Session::initNavigateListItems(ContractDay::class, $contract->getName());

        $entries    = [];
        $columns    = [];
        $formatters = [];

        if ($canEdit) {
            $columns['_checkbox']    = '';
            $formatters['_checkbox'] = 'raw_html';
        }
        $columns['name']    = ContractDay::getTypeName(1);
        $formatters['name'] = 'raw_html';
        if ($is_day) {
            $columns['contract_type'] = __('Type of contract', 'manageentities');
        }
        $columns['begin_date'] = __('Begin date');
        $columns['end_date']   = __('End date');
        if ($show_credit) {
            $columns['nbday'] = __('Initial credit', 'manageentities');
        }
        $columns['state'] = __('State of contract', 'manageentities');
        if ($show_credit) {
            $columns['report'] = __('Postponement', 'manageentities');
        }
        $columns['conso'] = __('Total consummated', 'manageentities');
        if ($show_credit) {
            $columns['reste']  = __('Total remaining', 'manageentities');
            $columns['depass'] = __('Total exceeding', 'manageentities');
        }
        $columns['price'] = CriPrice::getTypeName();

        foreach ($pluginContractDays as $pluginContractDay) {
            $contractDay = new ContractDay();
            $contractDay->getFromDB($pluginContractDay['id']);
            $contractDay->fields['contractdays_id'] = $contractDay->fields['id'];

            Session::addToNavigateListItems(ContractDay::class, $pluginContractDay['id']);

            $resultCriDetail = CriDetail::getCriDetailData($contractDay->fields);
            $conso = 0;
            foreach ($resultCriDetail['result'] as $dataCriDetail) {
                $conso += $dataCriDetail['conso'];
            }

            $criprice = new CriPrice();
            $price_val = '';
            if ($criprice->getFromDBByCrit([
                'plugin_manageentities_contractdays_id' => $contractDay->fields['id'],
                'is_default' => 1,
            ])) {
                $price_val = Html::formatNumber($criprice->fields["price"], false);
            }

            $entry = [
                'name'       => $contractDay->getLink(),
                'begin_date' => Html::convDate($pluginContractDay['begin_date']),
                'end_date'   => Html::convDate($pluginContractDay['end_date']),
                'state'      => \Dropdown::getDropdownName(
                    'glpi_plugin_manageentities_contractstates',
                    $pluginContractDay['plugin_manageentities_contractstates_id']
                ),
                'conso'      => Html::formatNumber($conso),
                'price'      => $price_val,
            ];
            if ($is_day) {
                $entry['contract_type'] = Contract::getContractType($contractDay->fields['contract_type']);
            }
            if ($show_credit) {
                $entry['nbday']  = Html::formatNumber($pluginContractDay['nbday']);
                $entry['report'] = Html::formatNumber($pluginContractDay['report']);
                $entry['reste']  = Html::formatNumber($resultCriDetail['resultOther']['reste']);
                $entry['depass'] = Html::formatNumber($resultCriDetail['resultOther']['depass']);
            }
            if ($canEdit) {
                ob_start();
                Html::showMassiveActionCheckBox(ContractDay::class, $pluginContractDay['id']);
                $entry['_checkbox'] = ob_get_clean();
            }

            $entries[] = $entry;
        }

        $massive_form_open   = '';
        $massive_actions_top = '';
        $massive_actions_bottom = '';
        $massive_form_close  = '';

        if ($canEdit) {
            $massiveactionparams = ['item' => ContractDay::class, 'container' => 'masscontractday' . $rand];

            ob_start();
            Html::openMassiveActionsForm('masscontractday' . $rand);
            $massive_form_open = ob_get_clean();

            ob_start();
            Html::showMassiveActions($massiveactionparams);
            $massive_actions_top = ob_get_clean();

            ob_start();
            $massiveactionparams['ontop'] = false;
            Html::showMassiveActions($massiveactionparams);
            $massive_actions_bottom = ob_get_clean();

            $massive_form_close = '</form>';
        }

        TemplateRenderer::getInstance()->display('@manageentities/contractday_list.html.twig', [
            'entries'               => $entries,
            'columns'               => $columns,
            'formatters'            => $formatters,
            'rand'                  => $rand,
            'can_edit'              => $canEdit,
            'massive_form_open'     => $massive_form_open,
            'massive_actions_top'   => $massive_actions_top,
            'massive_actions_bottom'=> $massive_actions_bottom,
            'massive_form_close'    => $massive_form_close,
            'add_button_html'       => $add_button_html,
        ]);
    }

    public static function queryOldContractDaywithInterventions($date)
    {
        $criteria = [
            'SELECT' => [
                'glpi_plugin_manageentities_cridetails.contracts_id',
                'glpi_entities.name AS entities_name',
                'glpi_plugin_manageentities_cridetails.tickets_id',
                'glpi_plugin_manageentities_cridetails.id AS cridetails_id',
                'glpi_plugin_manageentities_cridetails.date AS cridetails_date',
                'glpi_tickets.name AS tickets_name',
                'glpi_plugin_manageentities_contractdays.name',
                'glpi_plugin_manageentities_contractdays.id',
                'glpi_plugin_manageentities_contractdays.end_date',
            ],
            'FROM' => 'glpi_plugin_manageentities_cridetails',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
                'glpi_entities' => [
                    'ON' => [
                        'glpi_entities' => 'id',
                        'glpi_plugin_manageentities_cridetails' => 'entities_id',
                    ],
                ],
                'glpi_plugin_manageentities_contractdays' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_contractdays_id',
                        'glpi_plugin_manageentities_contractdays' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_tickets.is_deleted' => 0,
                'glpi_plugin_manageentities_contractdays.end_date' => ['<=', $date],
                'glpi_plugin_manageentities_cridetails.date' => ['>', $date],
            ],
        ];

        return $criteria;
    }

    public function setSessionValues()
    {
        if (isset($_SESSION['plugin_manageentities']['contractday']) && !empty($_SESSION['plugin_manageentities']['contractday'])) {
            foreach ($_SESSION['plugin_manageentities']['contractday'] as $key => $val) {
                $this->fields[$key] = $val;
            }
        }
        unset($_SESSION['plugin_manageentities']['contractday']);
    }


    public function post_updateItem($history = true)
    {
        // When a period's state changes, check if all periods of the parent GLPI contract are now closed.
        // If so, set the GLPI contract state to the configured closed GLPI state.
        if (!isset($this->input['plugin_manageentities_contractstates_id'])) {
            return;
        }

        $config = Config::getInstance();
        $closed_glpi_state_id    = (int)($config->fields['closed_glpi_state_id'] ?? 0);
        $closed_contractstate_id = (int)($config->fields['closed_contractstate_id'] ?? 0);

        if ($closed_glpi_state_id === 0 || $closed_contractstate_id === 0) {
            return;
        }

        $contracts_id = (int)$this->fields['contracts_id'];
        if ($contracts_id === 0) {
            return;
        }

        // Count periods that are NOT in a closed state
        global $DB;
        $iterator = $DB->request([
            'SELECT' => ['COUNT' => 'glpi_plugin_manageentities_contractdays.id AS cnt'],
            'FROM'   => 'glpi_plugin_manageentities_contractdays',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_contractstates' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                        'glpi_plugin_manageentities_contractstates' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_contractdays.contracts_id' => $contracts_id,
                'glpi_plugin_manageentities_contractstates.is_closed'  => 0,
            ],
        ]);

        $row = $iterator->current();
        if ($row && (int)$row['cnt'] === 0) {
            // All periods are closed — set the GLPI contract to the configured closed state
            $glpi_contract = new \Contract();
            if ($glpi_contract->getFromDB($contracts_id)
                && (int)$glpi_contract->fields['states_id'] !== $closed_glpi_state_id) {
                $glpi_contract->update([
                    'id'        => $contracts_id,
                    'states_id' => $closed_glpi_state_id,
                ]);
            }
        }
    }

    public function prepareInputForUpdate($input)
    {
        (isset($input['charged']) && $input['charged'] == true) ? $input['charged'] = 1 : $input['charged'] = 0;

        if (!$this->checkPeriod($input)) {
            return false;
        }

        if (!$this->checkMandatoryFields($input)) {
            return false;
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        (isset($input['charged']) && $input['charged'] == true) ? $input['charged'] = 1 : $input['charged'] = 0;

        if (!$this->checkPeriod($input)) {
            $_SESSION['plugin_manageentities']['contractday'] = $input;
            return false;
        }

        if (!$this->checkMandatoryFields($input)) {
            $_SESSION['plugin_manageentities']['contractday'] = $input;
            return false;
        }

        return $input;
    }

    /**
     * checkPeriod : Check if a period allready exists, to avoid 2 same periods on a contract
     *
     * @param type $input
     *
     * @return boolean
     * @global type $DB
     *
     */
    public function checkPeriod($input)
    {
        global $DB;

        $config = Config::getInstance();

        if (isset($input['end_date'])
            && isset($input['begin_date'])
            && !$config->fields['allow_same_periods']
            && $input['end_date'] != null) {
            if ($input['end_date'] != 'NULL'
                && strtotime($input['end_date']) < strtotime($input['begin_date'])) {
                Session::addMessageAfterRedirect(
                    __('End date cannot be less than begin date', 'manageentities'),
                    true,
                    ERROR
                );
                return false;
            }

            $contract = new \Contract();
            $contract->getFromDB($input['contracts_id']);

            $output = [];

            $criteria = [
                'SELECT' => [
                    'begin_date',
                    'end_date',
                ],
                'FROM' => 'glpi_plugin_manageentities_contractdays',
                'WHERE' => [
                    'entities_id' => $input['entities_id'],
                    'contracts_id' => $input['contracts_id'],
                ],
            ];

            if (isset($input['id'])) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['id' => ['<>', $input['id']]];
            }

            $iterator = $DB->request($criteria);

            if (count($iterator) > 0) {
                foreach ($iterator as $data) {
                    $output[] = $data;
                }
            }

            foreach ($output as $date) {
                if (!((strtotime($input['begin_date']) < strtotime($date['begin_date'])
                        && strtotime($input['end_date']) < strtotime($date['begin_date']))
                    || (strtotime($input['begin_date']) > strtotime($date['end_date'])
                        && (strtotime($input['end_date']) > strtotime($date['end_date'])
                            || $input['end_date'] == 'NULL')))) {
                    Session::addMessageAfterRedirect(
                        sprintf(
                            __('The contract period %s already exists', 'manageentities'),
                            Html::convDate($input['begin_date']) . ' - ' . Html::convDate($input['end_date'])
                        ),
                        true,
                        ERROR
                    );
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * checkMandatoryFields
     *
     * @param type $input
     *
     * @return boolean
     */
    public function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $config = Config::getInstance();

        $mandatory_fields = [
            'plugin_manageentities_contractstates_id' => ContractState::getTypeName(),
            'begin_date' => __('Begin date'),
        ];

        if ($config->fields['hourorday'] == Config::DAY) {
            $mandatory_fields['contract_type'] = __('Type of service contract', 'manageentities');
        }

        foreach ($input as $key => $value) {
            if (array_key_exists($key, $mandatory_fields)) {
                if (empty($value) || $value == 'NULL') {
                    $msg[] = $mandatory_fields[$key];
                    $checkKo = true;
                }
            }
        }

        if ($checkKo) {
            Session::addMessageAfterRedirect(
                sprintf(__("Mandatory fields are not filled. Please correct: %s"), implode(', ', $msg)),
                false,
                ERROR
            );
            return false;
        }

        return true;
    }

    public static function checkRemainingOpenContractDays($contracts_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'COUNT' => 'glpi_plugin_manageentities_contractdays.id AS count',
            ],
            'FROM' => 'glpi_plugin_manageentities_contractdays',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_contractstates' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                        'glpi_plugin_manageentities_contractstates' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_contractdays.contracts_id' => $contracts_id,
                'glpi_plugin_manageentities_contractstates.is_active' => 1,
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                if ($data['count'] > 0) {
                    return true;
                }
            }
        }

        return false;
    }

}
