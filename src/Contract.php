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
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Manageentities\Entity;
use Html;
use MassiveAction;
use Migration;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Contract extends CommonDBTM
{

    const MANAGEMENT_NONE = 0;
    const MANAGEMENT_QUARTERLY = 1;
    const MANAGEMENT_ANNUAL = 2;

    const CONTRACT_TYPE_NULL = 0;
    //time mode
    const CONTRACT_TYPE_HOUR = 1;
    const CONTRACT_TYPE_INTERVENTION = 2;
    const CONTRACT_TYPE_UNLIMITED = 3;
    //Daily mode
    const CONTRACT_TYPE_AT = 4;
    const CONTRACT_TYPE_FORFAIT = 5;

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 1)
    {
        return __('Management type', 'manageentities');
    }

    static function getIcon()
    {
        return "ti ti-contract";
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
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

        // active_editor_suscription, cloud_client and internet_publication are managed by EditorSubscription
        foreach (['show_on_global_gantt', 'moving_management'] as $field) {
            $input[$field] = (isset($input[$field]) && ($input[$field] === "on" || $input[$field])) ? 1 : 0;
        }
        unset($input['active_editor_suscription'], $input['cloud_client'], $input['internet_publication']);

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

        // active_editor_suscription, cloud_client and internet_publication are managed by EditorSubscription
        foreach (['show_on_global_gantt', 'moving_management'] as $field) {
            $input[$field] = (isset($input[$field]) && ($input[$field] === "on" || $input[$field])) ? 1 : 0;
        }
        unset($input['active_editor_suscription'], $input['cloud_client'], $input['internet_publication']);

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
            return self::createTabEntry(__('Contract detail', 'manageentities'));
        }
        return '';
    }

    public static function postItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!($item instanceof \Contract) || empty($item->fields['id'])) {
            return;
        }

        $dbu = new DbUtils();
        $count = $dbu->countElementsInTable(
            'glpi_plugin_manageentities_contractdays',
            ['contracts_id' => $item->fields['id']]
        );
        if ($count === 0) {
            return;
        }

        $can_edit = $item->can($item->fields['id'], UPDATE);
        if (!$can_edit) {
            return;
        }

        $label = __('+ 12 months', 'manageentities');
        $title = __('Add 12 months to the initial contract period', 'manageentities');

        echo \Html::scriptBlock("
(function () {
    function attachBtn() {
        var sel = document.querySelector('select[name=\"duration\"]');
        if (!sel || document.getElementById('manageentities-add-12months')) return;

        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.id        = 'manageentities-add-12months';
        btn.className = 'btn btn-sm btn-outline-secondary ms-2';
        btn.title     = " . json_encode($title) . ";
        btn.innerHTML = '<i class=\"ti ti-calendar-plus me-1\"></i>' + " . json_encode($label) . ";

        btn.addEventListener('click', function () {
            var current = parseInt(sel.value, 10);
            if (isNaN(current) || current < 1) current = 0;
            var target = current + 12;
            if (target > 120) target = 120;

            if (window.jQuery && jQuery(sel).data('select2')) {
                // Select2 AJAX dropdown: the option list is not pre-loaded in the DOM.
                // Setting .val(target) without a matching <option> element empties the
                // selection. We must create the Option ourselves, which requires the
                // translated label. Fetch it from the same AJAX endpoint Select2 uses.
                var fieldId = sel.id;
                var cfg = window.select2_configs && window.select2_configs[fieldId];
                if (cfg && cfg.url) {
                    var postData = jQuery.extend({}, cfg.params, {
                        searchText: String(target),
                        page: 1,
                        page_limit: 200
                    });
                    jQuery.post(cfg.url, postData, function (data) {
                        var results = (data && data.results) ? data.results : [];
                        var found = null;
                        for (var i = 0; i < results.length; i++) {
                            if (parseInt(results[i].id, 10) === target) {
                                found = results[i];
                                break;
                            }
                        }
                        var labelText = found ? found.text : String(target);
                        var newOpt = new Option(labelText, target, true, true);
                        jQuery(sel).empty().append(newOpt).trigger('change');
                    }, 'json');
                }
            } else {
                sel.value = target;
                sel.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });

        var s2 = sel.parentNode.querySelector('.select2-container');
        var anchor = s2 || sel;
        anchor.parentNode.insertBefore(btn, anchor.nextSibling);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachBtn);
    } else {
        setTimeout(attachBtn, 100);
    }
})();
        ");
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (get_class($item) == 'Contract') {
            self::showForContract($item);

            if (self::canView()) {
                ContractDay::showForContract($item);
            }
        }
        return true;
    }

    function addContractByDefault($id, $entities_id)
    {
        $contracts = $this->find(['entities_id' => $entities_id]);

        if (count($contracts) > 0) {
            foreach ($contracts as $data) {
                $this->update(['is_default' => 0, 'id' => $data["id"]]);
            }
        }
        $this->update(['is_default' => 1, 'id' => $id]);
    }

    static function showForContract(\Contract $contract)
    {
        $rand    = mt_rand();
        $canView = $contract->can($contract->fields['id'], READ);
        $canEdit = $contract->can($contract->fields['id'], UPDATE);
        $config  = Config::getInstance();

        if (!$canView) {
            return false;
        }

        $restrict = [
            "`glpi_plugin_manageentities_contracts`.`entities_id`"  => $contract->fields['entities_id'],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contract->fields['id'],
        ];
        $dbu            = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract  = reset($pluginContracts) ?: [];

        $is_hour_mode  = ($config->fields['hourorday'] == Config::HOUR);
        $is_day_price  = ($config->fields['hourorday'] == Config::DAY && $config->fields['useprice'] == Config::PRICE);

        ob_start();
        Html::showDateField("date_signature", ['value' => $pluginContract['date_signature'] ?? null]);
        $date_signature_html = ob_get_clean();

        ob_start();
        Html::showDateField("date_renewal", ['value' => $pluginContract['date_renewal'] ?? null]);
        $date_renewal_html = ob_get_clean();

        $management_html  = '';
        $contract_type_html = '';
        if ($is_hour_mode) {
            ob_start();
            Contract::dropdownContractManagement("management", $pluginContract['management'] ?? 0);
            $management_html = ob_get_clean();

            ob_start();
            Contract::dropdownContractType("contract_type", $pluginContract['contract_type'] ?? 0);
            $contract_type_html = ob_get_clean();
        }

        ob_start();
        \Dropdown::showTimeStamp('duration_moving', ['value' => $pluginContract['duration_moving'] ?? null, 'addfirstminutes' => true]);
        $duration_moving_html = ob_get_clean();

        $sub              = EditorSubscription::getForEntity((int)$contract->fields['entities_id']);
        $now              = date('Y-m-d');
        $sub_end_expired  = !empty($sub['end_date'])   && substr($sub['end_date'],   0, 10) < $now;
        $sub_level_name   = !empty($sub['plugin_manageentities_subscriptionlevels_id'])
            ? \Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int)$sub['plugin_manageentities_subscriptionlevels_id'])
            : '';
        $sub_wizard_url   = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php'
            . '?entities_id=' . (int)$contract->fields['entities_id'];

        TemplateRenderer::getInstance()->display('@manageentities/contract_detail_form.html.twig', [
            'rand'                       => $rand,
            'can_edit'                   => $canEdit,
            'use_subscriptions'          => Config::useEditorSubscriptions(),
            'form_url'                   => \Toolbox::getItemTypeFormURL(Contract::class),
            'contracts_id'               => $contract->fields['id'],
            'entities_id'                => $contract->fields['entities_id'],
            'plugin_contract_id'         => $pluginContract['id'] ?? 0,
            'is_new'                     => empty($pluginContract),
            'is_hour_mode'               => $is_hour_mode,
            'is_day_price'               => $is_day_price,
            'date_signature_html'        => $date_signature_html,
            'date_renewal_html'          => $date_renewal_html,
            'management_html'            => $management_html,
            'contract_type_html'         => $contract_type_html,
            'contract_added'             => (int)($pluginContract['contract_added'] ?? 0),
            'refacturable_costs'         => (int)($pluginContract['refacturable_costs'] ?? 0),
            'show_on_global_gantt'       => (int)($pluginContract['show_on_global_gantt'] ?? 0),
            'moving_management'          => (int)($pluginContract['moving_management'] ?? 0),
            'duration_moving_html'       => $duration_moving_html,
            'internet_publication'       => (int)($sub['internet_publication'] ?? 0),
            // Publisher subscription — read-only card
            'has_subscription'           => !empty($sub),
            'sub_customer_account_id'    => $sub['customer_account_id'] ?? '',
            'sub_name'                   => $sub['name'] ?? '',
            'sub_active'                 => (int)($sub['active_editor_suscription'] ?? 0),
            'sub_cloud'                  => (int)($sub['cloud_client'] ?? 0),
            'sub_begin_date'             => $sub['begin_date'] ?? '',
            'sub_end_date'               => $sub['end_date'] ?? '',
            'sub_end_expired'            => $sub_end_expired,
            'sub_level_name'             => $sub_level_name,
            'sub_wizard_url'             => $sub_wizard_url,
        ]);
    }


    function showContracts($instID)
    {
        global $DB, $CFG_GLPI;

        $this->displayAlertforEntity($instID);

        $config    = Config::getInstance();
        $is_single = (count($instID) === 1);
        $can_edit  = $this->canCreate() && $is_single;

        // Resolve contract_states filter: user preferences → global config
        $allowed_states = [];
        $users_id       = \Session::getLoginUserID();
        $pref_id        = Preference::checkIfPreferenceExists($users_id);
        if ($pref_id > 0) {
            $pref = new Preference();
            $pref->getFromDB($pref_id);
            $decoded = json_decode($pref->fields['contract_states'] ?? '', true);
            if (is_array($decoded) && count($decoded) > 0) {
                $allowed_states = $decoded;
            }
        }
        if (empty($allowed_states)) {
            $decoded = json_decode($config->fields['contract_states'] ?? '', true);
            if (is_array($decoded) && count($decoded) > 0) {
                $allowed_states = $decoded;
            }
        }

        // Build query — filter by states via contractdays sub-query when states are configured
        $where = [
            'glpi_contracts.is_deleted'              => 0,
            $this->getTable() . '.entities_id'       => $instID,
        ];

        if (!empty($allowed_states)) {
            $states_in = implode(',', array_map('intval', $allowed_states));
            $where[]   = new QueryExpression(
                'EXISTS (SELECT 1 FROM `glpi_plugin_manageentities_contractdays`'
                . ' WHERE `glpi_plugin_manageentities_contractdays`.`contracts_id` = '
                . $DB->quoteName($this->getTable() . '.contracts_id')
                . ' AND `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id`'
                . ' IN (' . $states_in . '))'
            );
        }

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_contracts.*',
                $this->getTable() . '.contracts_id',
                $this->getTable() . '.entities_id as plugin_entities_id',
                $this->getTable() . '.management',
                $this->getTable() . '.contract_type',
                $this->getTable() . '.is_default',
                $this->getTable() . '.id as myid',
            ],
            'FROM'     => $this->getTable(),
            'LEFT JOIN' => [
                'glpi_contracts' => [
                    'ON' => [
                        $this->getTable() => 'contracts_id',
                        'glpi_contracts'  => 'id',
                    ],
                ],
                'glpi_entities' => [
                    'ON' => [
                        $this->getTable() => 'entities_id',
                        'glpi_entities'   => 'id',
                    ],
                ],
            ],
            'WHERE'   => $where,
            'ORDERBY' => [
                'glpi_entities.name',
                'glpi_contracts.name',
            ],
        ]);

        $show_management  = ($config->fields['hourorday'] == Config::HOUR);
        $show_type        = ($config->fields['hourorday'] == Config::HOUR)
                         || ($config->fields['hourorday'] == Config::DAY && $config->fields['useprice'] == Config::PRICE);

        // Build rows for the template
        $rows = [];
        $used = [];
        foreach ($iterator as $data) {
            $used[] = $data['contracts_id'];
            $label  = $data['name'];
            if ($_SESSION['glpiis_ids_visible'] || empty($data['name'])) {
                $label .= ' (' . $data['contracts_id'] . ')';
            }

            ob_start();
            if ($is_single && !$data['is_default']) {
                Html::showSimpleForm(
                    PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                    'contractbydefault',
                    __('No'),
                    ['myid' => $data['myid'], 'entities_id' => $_SESSION['glpiactive_entity']]
                );
            }
            $default_form = ob_get_clean();

            ob_start();
            if ($can_edit) {
                Html::showSimpleForm(
                    PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                    'deletecontracts',
                    _x('button', 'Delete permanently'),
                    ['id' => $data['myid']]
                );
            }
            $delete_form = ob_get_clean();

            $rows[] = [
                'contracts_id'  => $data['contracts_id'],
                'myid'          => $data['myid'],
                'label'         => $label,
                'url'           => $CFG_GLPI['root_doc'] . '/front/contract.form.php?id=' . $data['contracts_id'],
                'entity_name'   => \Dropdown::getDropdownName('glpi_entities', $data['plugin_entities_id']),
                'num'           => $data['num'],
                'state'         => \Dropdown::getDropdownName('glpi_states', $data['states_id'] ?? 0),
                'comment'       => nl2br($data['comment'] ?? ''),
                'management'    => $show_management ? self::getContractManagement($data['management']) : '',
                'contract_type' => $show_type ? self::getContractType($data['contract_type']) : '',
                'is_default'    => (bool)$data['is_default'],
                'default_label' => $is_single
                    ? ($data['is_default'] ? __('Yes') : '')
                    : \Dropdown::getYesNo($data['is_default']),
                'default_form'  => $default_form,
                'delete_form'   => $delete_form,
            ];
        }

        // Add-contract dropdown
        $add_dropdown_html = '';
        if ($can_edit) {
            ob_start();
            echo Html::hidden('entities_id', ['value' => $_SESSION['glpiactive_entity']]);
            \Dropdown::show('Contract', ['name' => 'contracts_id', 'used' => $used]);
            echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/setup.templates.php?itemtype=Contract&add=1' target='_blank'>";
            echo "<i title=\"" . _sx('button', 'Add') . "\" class=\"ti ti-square-plus ms-1\"></i>";
            echo "</a>";
            $add_dropdown_html = ob_get_clean();
        }

        TemplateRenderer::getInstance()->display('@manageentities/entity/contracts_tab.html.twig', [
            'rows'             => $rows,
            'can_edit'         => $can_edit,
            'is_single'        => $is_single,
            'show_management'  => $show_management,
            'show_type'        => $show_type,
            'add_dropdown_html'=> $add_dropdown_html,
            'entity_url'       => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
            'allowed_states'   => $allowed_states,
            'rand'             => mt_rand(),
        ]);
    }

    function displayAlertforEntity($instID)
    {
        global $DB;

        $alert = "";
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_contracts.*',
                $this->getTable() . '.contracts_id',
                $this->getTable() . '.management',
                $this->getTable() . '.contract_type',
                $this->getTable() . '.is_default',
                $this->getTable() . '.id as myid',
            ],
            'FROM' => $this->getTable(),
            'LEFT JOIN' => [
                'glpi_contracts' => [
                    'ON' => [
                        $this->getTable() => 'contracts_id',
                        'glpi_contracts' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_contracts.is_deleted' => 0,
                'NOT'       => [$this->getTable() . '.date_signature' => null],
                'glpi_contracts.entities_id' => $instID
            ],
            'ORDERBY' => [
                'glpi_contracts.begin_date',
                'glpi_contracts.name'
            ],
        ]);

        $resultCriDetail = [];
        $reste = 0;
        if (count($iterator) > 0) {

            foreach ($iterator as $data) {
                $criteriad = [
                    'SELECT' => [
                        'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
                        'glpi_plugin_manageentities_contractdays.report AS report',
                        'glpi_plugin_manageentities_contractdays.nbday AS nbday',
                    ],
                    'FROM' => 'glpi_plugin_manageentities_contractdays',
                    'LEFT JOIN' => [
                        'glpi_contracts' => [
                            'ON' => [
                                'glpi_contracts' => 'id',
                                'glpi_plugin_manageentities_contractdays' => 'contracts_id'
                            ]
                        ],
                        'glpi_plugin_manageentities_contractstates' => [
                            'ON' => [
                                'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                                'glpi_plugin_manageentities_contractstates' => 'id'
                            ]
                        ],
                    ],
                    'WHERE' => [
                        'glpi_plugin_manageentities_contractstates.is_closed' => 0,
//                        'glpi_plugin_manageentities_contractdays.end_date' => [
//                            '>',
//                            date('Y-m-d', strtotime($_SESSION['glpi_currenttime']))
//                        ],
                        'glpi_plugin_manageentities_contractdays.contracts_id' => $data["contracts_id"],
                    ]
                ];
                $iteratord = $DB->request($criteriad);

                if (count($iteratord) > 0) {
                    foreach ($iteratord as $datad) {
                        $dataContractDay['contracts_id'] = $data['contracts_id'];
                        $dataContractDay['entities_id'] = $data['entities_id'];
                        $dataContractDay['contractdays_id'] = $datad['contractdays_id'];
                        $dataContractDay['nbday'] = $datad['nbday'];
                        $dataContractDay['report'] = $datad['report'];
                        $resultCriDetail = CriDetail::getCriDetailData(
                            $dataContractDay,
                            ["contract_type_id" => $data["contract_type"]]
                        );
                        $reste += $resultCriDetail['resultOther']['reste'];
                    }
                }
            }


            if ($reste == 0) {
                $alert .= "<div class='alert alert-danger d-flex'>";
                $alert .= "<b>" . __(
                        "Please note that there are no more contracts with days available for this customer.",
                        "manageentities"
                    ) . "</b></div>";
            }
        }
        return $alert;
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
            self::MANAGEMENT_NONE => \Dropdown::EMPTY_VALUE,
            self::MANAGEMENT_QUARTERLY => __('Quarterly', 'manageentities'),
            self::MANAGEMENT_ANNUAL => __('Annual', 'manageentities')
        ];

        if (!empty($contractManagements)) {
            if ($rand == null) {
                return \Dropdown::showFromArray($name, $contractManagements, ['value' => $value]);
            } else {
                return \Dropdown::showFromArray($name, $contractManagements, ['value' => $value, 'rand' => $rand]);
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
                return \Dropdown::EMPTY_VALUE;
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
        $config = Config::getInstance();

        if ($config->fields['hourorday'] == Config::HOUR) {
            $contractTypes = [
                self::CONTRACT_TYPE_NULL => \Dropdown::EMPTY_VALUE,
                self::CONTRACT_TYPE_HOUR => __('Hourly', 'manageentities'),
                self::CONTRACT_TYPE_INTERVENTION => __('By intervention', 'manageentities'),
                self::CONTRACT_TYPE_UNLIMITED => __('Unlimited')
            ];
        } elseif ($config->fields['hourorday'] == Config::DAY && $config->fields['useprice'] == Config::PRICE) {
            $contractTypes = [
                self::CONTRACT_TYPE_NULL => \Dropdown::EMPTY_VALUE,
                self::CONTRACT_TYPE_AT => __('Technical Assistance', 'manageentities'),
                self::CONTRACT_TYPE_FORFAIT => __('Package', 'manageentities')
            ];
        }

        if (!empty($contractTypes)) {
            if ($rand == null) {
                return \Dropdown::showFromArray($name, $contractTypes, ['value' => $value]);
            } else {
                return \Dropdown::showFromArray($name, $contractTypes, ['value' => $value, 'rand' => $rand]);
            }
        } else {
            return false;
        }
    }

    /**
     * Returns the name of the type of contract
     *
     * @param type $value
     *
     * @return string
     */
    static function getContractType($value)
    {
        switch ($value) {
            case self::CONTRACT_TYPE_NULL :
                return \Dropdown::EMPTY_VALUE;
            case self::CONTRACT_TYPE_HOUR :
                return __('Hourly', 'manageentities');
            case self::CONTRACT_TYPE_INTERVENTION :
                return __('By intervention', 'manageentities');
            case self::CONTRACT_TYPE_UNLIMITED :
                return __('Unlimited');
            case self::CONTRACT_TYPE_AT :
                return __('Technical Assistance', 'manageentities');
            case self::CONTRACT_TYPE_FORFAIT :
                return __('Package', 'manageentities');
            default :
                return "";
        }
    }

    /**
     * Return the unit
     *
     * @param type $config
     * @param type $value
     *
     * @return type
     */
    static function getUnitContractType($config, $value)
    {
        if ($config->fields['hourorday'] == Config::HOUR) {
            switch ($value) {
                case self::CONTRACT_TYPE_HOUR :
                    return _n('Hour', 'Hours', 2);
                case self::CONTRACT_TYPE_INTERVENTION :
                    return _n('Intervention', 'Interventions', 2, 'manageentities');
                case self::CONTRACT_TYPE_UNLIMITED :
                    return __('Unlimited');
            }
        } else {
            return _n('Day', 'Days', 2);
        }
    }

    static function checkRemainingOpenContractDays($contracts_id)
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'COUNT' => 'glpi_plugin_manageentities_contractdays.id AS count'
            ],
            'FROM' => 'glpi_plugin_manageentities_contractdays',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_contractstates' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                        'glpi_plugin_manageentities_contractstates' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_contractdays.contracts_id' => $contracts_id,
                'glpi_plugin_manageentities_contractstates.is_active' => 1
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

    /**
     * Recompute and persist remaining_days in glpi_plugin_manageentities_contracts.
     * Called after any CriDetail add/update that could change consumption.
     */
    public static function updateRemainingDays(int $contracts_id): void
    {
        global $DB;

        if ($contracts_id <= 0) {
            return;
        }

        $remaining = self::getTotalRemainingDays($contracts_id);

        $DB->update(
            'glpi_plugin_manageentities_contracts',
            ['remaining_days' => $remaining],
            ['contracts_id'   => $contracts_id]
        );
    }

    /**
     * Sum of remaining days across all open contract periods for a given contract.
     *
     * @param int $contracts_id  GLPI contract ID
     *
     * @return float
     */
    public static function getTotalRemainingDays(int $contracts_id): float
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
                'glpi_plugin_manageentities_contractdays.nbday',
                'glpi_plugin_manageentities_contractdays.report',
                'glpi_plugin_manageentities_contractdays.contracts_id',
                'glpi_plugin_manageentities_contractdays.entities_id',
                'glpi_plugin_manageentities_contractdays.contract_type',
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
                'glpi_plugin_manageentities_contractstates.is_closed' => 0,
            ],
        ]);

        $total = 0.0;
        foreach ($iterator as $row) {
            $result = CriDetail::getCriDetailData($row);
            $total += $result['resultOther']['reste'];
        }

        return $total;
    }

    /**
     * @param string $field
     * @param array|mixed $values
     * @param array $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if (
            $field === 'contracts_id'
            && isset($options['searchopt']['remaining_days'])
            && $options['searchopt']['remaining_days']
        ) {
            $contracts_id = (int)($values['contracts_id'] ?? $values['name'] ?? 0);
            if ($contracts_id <= 0) {
                return '';
            }
            return Html::formatNumber(self::getTotalRemainingDays($contracts_id), false);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Display contents at the begining of item forms.
     *
     * @param array $params Array with "item" and "options" keys
     *
     * @return void
     */
    static public function preItemForm($params)
    {
        if (isset($params['itemtype']) && ($params['itemtype'] == 'Ticket'
                || $params['itemtype'] == 'Contract')) {
            $entities_id = $_SESSION['glpiactive_entity'];
        }

        if (isset($params['item'])) {
            $item = $params['item'];
            $options = $params['options'];
        }
        if (isset($params['item'])
            && ($item->getType() == 'Ticket' || $item->getType() == 'Contract')
            && isset($item->fields['entities_id'])) {
            $entities_id = $item->fields['entities_id'];
        }
        $out = "";

        if (isset($entities_id)
            && $_SESSION['glpiactiveprofile']['interface'] == 'central'
            && Session::haveRight('plugin_manageentities', UPDATE)) {
//            $sons = getSonsOf("glpi_entities", $entities_id);
//            if (count($sons) > 1) {
//                return false;
//            }

            $out .= '<tr><th colspan="' . (isset($options['colspan']) ? $options['colspan'] * 2 : '4') . '">';
            $contract = new Contract();
            $out .= $contract->displayAlertforEntity($entities_id);
            $out .= '</th></tr>';

            if (isset($params['item'])
                && ($item->getType() == 'Ticket')) {
                $out .= '<tr><th colspan="' . (isset($options['colspan']) ? $options['colspan'] * 2 : '4') . '">';
                $direct = new DirectHelpdesk();
                $out .= $direct->displayAlertforEntity($entities_id);
                $out .= '</th></tr>';
            }

        }


        echo $out;
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = [];

        if (self::canCreate()) {
            $sep = MassiveAction::CLASS_ACTION_SEPARATOR;
            $actions[self::class . $sep . 'update_subscription_fields']
                = "<i class='ti ti-edit'></i>" . __s('Update management fields', 'manageentities');
        }

        return $actions + parent::getSpecificMassiveActions($checkitem);
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() === 'update_subscription_fields') {
            $fields = [
                'show_on_global_gantt' => __('Show on GANTT', 'manageentities'),
                'moving_management'    => __('Movement management', 'manageentities'),
                'refacturable_costs'   => __('Refacturable costs', 'manageentities'),
            ];

            echo "<div class='d-flex flex-wrap gap-4 my-2'>";
            foreach ($fields as $name => $label) {
                echo "<div class='form-check form-switch'>";
                echo "<input type='hidden' name='{$name}' value='0'>";
                echo "<input type='checkbox' class='form-check-input' name='{$name}' value='1' id='ma_{$name}'>";
                echo "<label class='form-check-label' for='ma_{$name}'>" . htmlspecialchars($label) . "</label>";
                echo "</div>";
            }
            echo "</div>";
            echo Html::submit(__('Update'), ['name' => 'massiveaction', 'class' => 'btn btn-primary mt-2']);
            return true;
        }

        return parent::showMassiveActionsSubForm($ma);
    }

    public static function processMassiveActionsForOneItemtype(
        MassiveAction $ma,
        CommonDBTM $item,
        array $ids
    ) {
        global $DB;

        if ($ma->getAction() === 'update_subscription_fields') {
            $input = $ma->getInput();

            $allowed = [
                'show_on_global_gantt',
                'moving_management',
                'refacturable_costs',
            ];

            $update = [];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $input)) {
                    $update[$field] = (int)(bool)$input[$field];
                }
            }

            if (empty($update)) {
                $ma->itemDone($item->getType(), $ids, MassiveAction::ACTION_KO);
                return;
            }

            foreach ($ids as $id) {
                // $id is a core glpi_contracts.id; find the plugin row by contracts_id
                $row = $DB->request([
                    'SELECT' => 'id',
                    'FROM'   => self::getTable(),
                    'WHERE'  => ['contracts_id' => $id],
                ])->current();

                if (!$row) {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                    continue;
                }

                $plugin_contract = new self();
                if ($plugin_contract->update(['id' => $row['id']] + $update)) {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                } else {
                    $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                }
            }
            return;
        }

        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
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
                            `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id)',
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `is_default` tinyint NOT NULL DEFAULT '0',
                            `management` tinyint NOT NULL DEFAULT '0' COMMENT 'for the management mode (quarterly or annual or not)',
                            `contract_type` tinyint NOT NULL DEFAULT '0' COMMENT 'for the contract type (hour, intervention, unlimited or not)',
                            `date_signature` timestamp NULL DEFAULT NULL,
                            `date_renewal` timestamp NULL DEFAULT NULL,
                            `contract_added` tinyint NOT NULL DEFAULT '0',
                            `show_on_global_gantt` tinyint NOT NULL DEFAULT '0',
                            `refacturable_costs` tinyint NOT NULL DEFAULT '0',
                            `moving_management` tinyint NOT NULL DEFAULT '0',
                            `duration_moving` decimal(20,2) NOT NULL DEFAULT '0' COMMENT 'Duration of moving',
                            `remaining_days` decimal(10,2) NOT NULL DEFAULT '0.00',
                            `active_editor_suscription` tinyint NOT NULL DEFAULT '0',
                            `cloud_client` tinyint NOT NULL DEFAULT '0',
                            `internet_publication` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `unicity` (`contracts_id`,`entities_id`),
                            KEY `contracts_id` (`contracts_id`),
                            KEY `entities_id` (`entities_id`)
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
