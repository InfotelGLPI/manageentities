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

use AllowDynamicProperties;
use CommonGLPI;
use CommonITILObject;
use DbUtils;
use GlpiPlugin\Mydashboard\Datatable;
use GlpiPlugin\Mydashboard\Helper;
use GlpiPlugin\Mydashboard\Menu;
use GlpiPlugin\Mydashboard\Html as MydashboardHtml;
use GlpiPlugin\Mydashboard\Widget;
use Html;
use Plugin;
use Session;
use Toolbox;

/**
 * Class Dashboard
 */
#[AllowDynamicProperties]
class Dashboard extends CommonGLPI
{
    public $widgets = [];
    private $options;
    private $datas;
    private $form;

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    public function init() {

    }


    /**
     * @return array
     */
    public function getWidgetsForItem()
    {
        $widgets = [
            Menu::$MANAGEMENT => [
                $this->getType() . "1" => [
                    "title" => __("Remaining days number by opened client contracts", "manageentities"),
                    "type" => Widget::$TABLE,
                    "comment" => "",
                ],
                $this->getType() . "2" => [
                    "title" => __("Client annuary", "manageentities"),
                    "type" => Widget::$TABLE,
                    "comment" => "",
                ],
                $this->getType() . "3" => [
                    "title" => __("Tickets without CRI", "manageentities"),
                    "type" => Widget::$TABLE,
                    "comment" => "",
                ],
                $this->getType() . "4" => [
                    "title" => __("Interventions with old contract", "manageentities"),
                    "type" => Widget::$TABLE,
                    "comment" => "",
                ],
                $this->getType() . "5" => [
                    "title" => __("Opened contract prestations without remaining days", "manageentities"),
                    "type" => Widget::$TABLE,
                    "comment" => "",
                ],
            ],
        ];

        return $widgets;
    }


    public function getWidgetContentForItem($widgetId)
    {
        global $DB;

        $dbu = new DbUtils();
        if (empty($this->form)) {
            $this->init();
        }
        switch ($widgetId) {
            case $this->getType() . "1":
                $widget = new MydashboardHtml();
                if (Plugin::isPluginActive("manageentities")) {
                    $link_contract = Toolbox::getItemTypeFormURL("Contract");
                    $link_contract_day = Toolbox::getItemTypeFormURL(ContractDay::class);
                    $entity = new \Entity();
                    $contracts = self::queryFollowUpSimplified($_SESSION['glpiactiveentities'], []);
                    //               Toolbox::logDebug($contracts);
                    $datas = [];
                    if (!empty($contracts)) {
                        foreach ($contracts as $key => $contract_data) {
                            if (is_integer($key)) {
                                if (!is_null($contract_data['contract_begin_date'])) {
                                    foreach ($contract_data['days'] as $key => $days) {
                                        if ($days['contract_is_closed']) {
                                            unset($contract_data['days'][$key]);
                                        }
                                    }

                                    if (!empty($contract_data['days'])) {
                                        foreach ($contract_data['days'] as $day_data) {
                                            $entity->getFromDB($contract_data['entities_id']);
                                            $data["parent"] = $dbu->getTreeLeafValueName(
                                                "glpi_entities",
                                                $entity->fields['entities_id'] ?? ''
                                            );

                                            $data["entities_id"] = $contract_data['entities_name'];

                                            $name_contract = "<a href='" . $link_contract . "?id=" . $contract_data["contracts_id"] . "' target='_blank'>";
                                            $name_contract .= $contract_data['name'] . "</a>";
                                            $data["contracts_id"] = $name_contract;

                                            $name_contract_day = "<a href='" . $link_contract_day . "?id=" . $day_data['contractdays_id'] . "' target='_blank'>";
                                            $name_contract_day .= $day_data['contractdayname'] . "</a>";
                                            $data["days"] = $name_contract_day;
                                            $data["reste"] = $day_data['reste'];
                                            $data["total"] = $day_data['credit'];
                                            $data["end_date"] = Html::convDate($day_data['end_date']);
                                            $datas[] = $data;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $headers = [
                        __('Team', 'manageentities'),
                        __('Entity'),
                        __('Contract'),
                        __('Prestation', 'manageentities'),
                        __('Total remaining', 'manageentities'),
                        __('Total'),
                        __('End date'),
                    ];

                    $widget = new Datatable();

                    $widget->setTabNames($headers);
                    $widget->setTabDatas($datas);
                    $widget->toggleWidgetRefresh();
                } else {
                    $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
                }

                $widget->setWidgetTitle(__("Remaining days number by opened client contracts", "manageentities"));

                return $widget;
                break;
            case $this->getType() . "2":
                $widget = new MydashboardHtml();

                if (Plugin::isPluginActive("manageentities")) {
                    $criteria = [
                        'SELECT' => [
                            'glpi_entities.name as client',
                            'glpi_contacts.firstname',
                            'glpi_contacts.name',
                            'glpi_contacts.phone',
                            'glpi_contacts.mobile',
                        ],
                        'FROM' => 'glpi_contacts',
                        'LEFT JOIN' => [
                            'glpi_plugin_manageentities_contacts' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contacts' => 'contacts_id',
                                    'glpi_contacts' => 'id',
                                ],
                            ],
                            'glpi_entities' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contacts' => 'entities_id',
                                    'glpi_entities' => 'id',
                                ],
                            ],
                        ],
                        'WHERE' => [
                            'glpi_contacts.name' => ['<>', ''],
                            'glpi_entities.name' => ['<>', ''],
                            'glpi_contacts.is_deleted' => 0,
                            //                            'glpi_contacts.phone' => ['<>', 'NULL'],
                            //                            'glpi_contacts.mobile' => ['<>', 'NULL'],
                            //                            'glpi_contacts.name' => ['<>', 'NULL'],
                        ],
                        'ORDERBY' => ['glpi_entities.name',
                            'glpi_contacts.name',
                            'glpi_contacts.firstname ASC'],
                    ];

                    $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
                        'glpi_contacts'
                    );

                    $widget = Helper::getWidgetsFromDBQuery('table', $criteria);
                    $headers = [
                        _n('Client', 'Clients', 1, 'manageentities'),
                        __('First name'),
                        __('Name'),
                        __('Phone'),
                        __('Mobile phone'),
                    ];
                    $widget->setTabNames($headers);

                    $widget->toggleWidgetRefresh();
                } else {
                    $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
                }
                $widget->setWidgetTitle(__("Client annuary", "manageentities"));

                return $widget;
                break;
            case $this->getType() . "3":
                $widget = new MydashboardHtml();
                if (Plugin::isPluginActive("manageentities")) {
                    $link_contract_day = Toolbox::getItemTypeFormURL(ContractDay::class);
                    $link_ticket = Toolbox::getItemTypeFormURL("Ticket");

                    $criteria = [
                        'SELECT' => [
                            'glpi_entities.name as entity',
                            'glpi_tickets.date',
                            'glpi_tickets.id as tickets_id',
                            'glpi_tickets.name as title',
                            'glpi_plugin_manageentities_contractdays.name',
                            'glpi_plugin_manageentities_contractdays.id',
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
                                    'glpi_tickets' => 'entities_id',
                                    'glpi_entities' => 'id',
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
                            'glpi_plugin_manageentities_cridetails.plugin_manageentities_contractdays_id' => ['<>', 0],
                            'glpi_tickets.is_deleted' => 0,
                            'glpi_plugin_manageentities_cridetails.documents_id' => 0,
                            ['glpi_tickets.status' => ['NOT IN', [CommonITILObject::SOLVED, CommonITILObject::CLOSED]]],
                        ],
                        'ORDERBY' => ['glpi_tickets.date DESC'],
                    ];

                    $widget = Helper::getWidgetsFromDBQuery('table', $criteria);
                    $headers = [
                        __('Opening date'),
                        _n('Client', 'Clients', 1, 'manageentities'),
                        __('Title'),
                        __('Prestation', 'manageentities'),
                    ];
                    $widget->setTabNames($headers);

                    $iterator = $DB->request($criteria);

                    $datas = [];
                    $i = 0;
                    if (count($iterator) > 0) {
                        foreach ($iterator as $data) {
                            $datas[$i]["date"] = Html::convDateTime($data['date']);

                            $datas[$i]["entity"] = $data['entity'];

                            $name_ticket = "<a href='" . $link_ticket . "?id=" . $data['tickets_id'] . "' target='_blank'>";
                            $name_ticket .= $data['title'] . "</a>";
                            $datas[$i]["title"] = $name_ticket;

                            $name_contract = "<a href='" . $link_contract_day . "?id=" . $data['id'] . "' target='_blank'>";
                            $name_contract .= $data['name'] . "</a>";
                            $datas[$i]["name"] = $name_contract;

                            $i++;
                        }
                    }

                    $widget->setTabDatas($datas);
                    $widget->setOption("bSort", false);
                    $widget->toggleWidgetRefresh();
                } else {
                    $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
                }

                $widget->setWidgetTitle(__("Tickets without CRI", "manageentities"));

                return $widget;
                break;
            case $this->getType() . "4":
                $year = date("Y");
                $month = date('m', mktime(12, 0, 0, date("m"), 0, date("Y")));
                $date = $year . "-" . $month . "-01";

                $link_contract_day = Toolbox::getItemTypeFormURL(ContractDay::class);
                $link_ticket = Toolbox::getItemTypeFormURL("Ticket");

                $query = ContractDay::queryOldContractDaywithInterventions($date);

                $widget = Helper::getWidgetsFromDBQuery('table', $query);
                $headers = [
                    __('Creation date'),
                    _n('Client', 'Clients', 1, 'manageentities'),
                    __('Ticket'),
                    __('Prestation', 'manageentities'),
                    __('End date'),
                ];
                $widget->setTabNames($headers);

                $iterator = $DB->request($query);

                $datas = [];
                $i = 0;
                if (count($iterator) > 0) {
                    foreach ($iterator as $data) {
                        $datas[$i]["date"] = Html::convDateTime($data['cridetails_date']);

                        $datas[$i]["entity"] = $data['entities_name'];

                        $name_ticket = "<a href='" . $link_ticket . "?id=" . $data['tickets_id'] . "' target='_blank'>";
                        $name_ticket .= $data['tickets_name'] . "</a>";
                        $datas[$i]["tickets_name"] = $name_ticket;

                        $name_contract = "<a href='" . $link_contract_day . "?id=" . $data['id'] . "' target='_blank'>";
                        $name_contract .= $data['name'] . "</a>";
                        $datas[$i]["name"] = $name_contract;

                        $datas[$i]["end_date"] = Html::convDateTime($data['end_date']);

                        $i++;
                    }
                }

                $widget->setTabDatas($datas);
                $widget->setOption("bSort", false);
                $widget->toggleWidgetRefresh();
                $widget->setWidgetTitle(__("Interventions with old contract", "manageentities"));
                //
                return $widget;
                break;
            case $this->getType() . "5":
                $widget = new MydashboardHtml();
                if (Plugin::isPluginActive("manageentities")) {
                    $link_contract = Toolbox::getItemTypeFormURL("Contract");
                    $link_contract_day = Toolbox::getItemTypeFormURL(ContractDay::class);
                    $entity = new \Entity();
                    $contracts = self::queryFollowUpSimplified($_SESSION['glpiactiveentities'], []);
                    $datas = [];
                    if (!empty($contracts)) {
                        foreach ($contracts as $key => $contract_data) {
                            if (is_integer($key)) {
                                if (!is_null($contract_data['contract_begin_date'])) {
                                    foreach ($contract_data['days'] as $key => $days) {
                                        if ($days['contract_is_closed']) {
                                            unset($contract_data['days'][$key]);
                                        }
                                        if ($days['reste'] > 0) {
                                            unset($contract_data['days'][$key]);
                                        }
                                    }

                                    if (!empty($contract_data['days'])) {
                                        $data = [];
                                        foreach ($contract_data['days'] as $day_data) {
                                            $entity->getFromDB($contract_data['entities_id']);
                                            $data["parent"] = $dbu->getTreeLeafValueName(
                                                "glpi_entities",
                                                $entity->fields['entities_id'] ?? ''
                                            );

                                            $data["entities_id"] = $contract_data['entities_name'];

                                            $name_contract = "<a href='" . $link_contract . "?id=" . $contract_data["contracts_id"] . "' target='_blank'>";
                                            $name_contract .= $contract_data['name'] . "</a>";
                                            $data["contracts_id"] = $name_contract;

                                            $name_contract_day = "<a href='" . $link_contract_day . "?id=" . $day_data['contractdays_id'] . "' target='_blank'>";
                                            $name_contract_day .= $day_data['contractdayname'] . "</a>";
                                            $data["days"] = $name_contract_day;
                                            $data["reste"] = $day_data['reste'];
                                            $data["total"] = $day_data['credit'];
                                            $datas[] = $data;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    $headers = [
                        __('Team', 'manageentities'),
                        __('Entity'),
                        __('Contract'),
                        __('Prestation', 'manageentities'),
                        __('Total remaining', 'manageentities'),
                        __('Total'),
                    ];

                    $widget = new Datatable();

                    $widget->setTabNames($headers);
                    $widget->setTabDatas($datas);
                    $widget->toggleWidgetRefresh();
                } else {
                    $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
                }

                $widget->setWidgetTitle(__("Opened contract prestations without remaining days", "manageentities"));

                return $widget;
                break;
        }
    }

    public static function queryFollowUpSimplified($instID, $options = [])
    {
        global $DB;

        $beginDate = 'NULL';
        $num = 0;
        $list = [];
        $nbContratByEntities = 0;// Count the contracts for all entities

        // We configure the type of contract Hourly or Dayly
        $config = Config::getInstance();
        if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
            $types_contracts = [
                Contract::CONTRACT_TYPE_NULL,
                Contract::CONTRACT_TYPE_HOUR,
                Contract::CONTRACT_TYPE_INTERVENTION,
                Contract::CONTRACT_TYPE_UNLIMITED,
            ];
        } else {// Daily
            $types_contracts = [
                Contract::CONTRACT_TYPE_NULL,
                Contract::CONTRACT_TYPE_AT,
                Contract::CONTRACT_TYPE_FORFAIT,
            ];
        }

        $plugin_config = new Config();
        $config_states = $plugin_config->find();
        $config_states = reset($config_states);

        $plugin_pref = new Preference();
        $preferences = $plugin_pref->find(['users_id' => Session::getLoginUserID()]);
        $preferences = reset($preferences);

        $criteria = [
            'SELECT' => [
                'glpi_entities.id AS entities_id',
                'glpi_entities.name AS entities_name',
            ],
            'DISTINCT' => true,
            'FROM' => 'glpi_contracts',
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'ON' => [
                        'glpi_contracts' => 'entities_id',
                        'glpi_entities' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'NOT' => ['glpi_entities.name' => null, 'glpi_entities.id' => null],
            ],
            'ORDERBY' => 'glpi_entities.name',
        ];
        $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
            'glpi_entities'
        );

        if (Session::getCurrentInterface() == 'central') {
            $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
                'glpi_contracts'
            );
        } else {
            $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_contracts.entities_id' => $instID];
        }

        $iterator = $DB->request($criteria);
        $nbTotEntity = (count($iterator) > 0 ? count($iterator) : 0);

        if ($nbTotEntity > 0) {
            foreach ($iterator as $dataEntity) {
                $criteriac = [
                    'SELECT' => [
                        'glpi_contracts.id AS contracts_id',
                        'glpi_contracts.name AS name',
                        'glpi_contracts.num AS num',
                        'glpi_contracts.begin_date AS contract_begin_date',
                        'glpi_contracts.entities_id AS entities_id',
                        'glpi_plugin_manageentities_contracts.contract_type AS contract_type',
                        'glpi_plugin_manageentities_contracts.show_on_global_gantt AS show_on_global_gantt',
                    ],
                    'FROM' => 'glpi_contracts',
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_contracts' => [
                            'ON' => [
                                'glpi_contracts' => 'id',
                                'glpi_plugin_manageentities_contracts' => 'contracts_id',
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_contracts.entities_id' => $dataEntity['entities_id'],
                        'glpi_contracts.is_deleted' => 0,
                    ],
                    'GROUPBY' => 'glpi_contracts.id',
                    'ORDERBY' => ['glpi_plugin_manageentities_contracts.date_signature ASC', 'glpi_contracts.name ASC'],
                ];

                if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
                    $criteriac['SELECT'] = array_merge(
                        $criteriac['SELECT'],
                        ['glpi_plugin_manageentities_contracts.contract_type AS contract_type']
                    );
                    $criteriac['WHERE'] = $criteriac['WHERE'] + ['glpi_plugin_manageentities_contracts.contract_type' => $types_contracts];
                }

                $iteratorc = $DB->request($criteriac);

                foreach ($iteratorc as $dataContract) {
                    $criteriad = [
                        'SELECT' => [
                            'glpi_plugin_manageentities_contractdays.name AS name_contractdays',
                            'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
                            'glpi_plugin_manageentities_contractdays.report AS report',
                            'glpi_plugin_manageentities_contractdays.nbday AS nbday',
                            'glpi_plugin_manageentities_contractstates.is_closed AS is_closed',
                            'glpi_plugin_manageentities_contractdays.begin_date AS begin_date',
                            'glpi_plugin_manageentities_contractdays.end_date AS end_date',
                        ],
                        'FROM' => 'glpi_plugin_manageentities_contractdays',
                        'LEFT JOIN' => [
                            'glpi_contracts' => [
                                'ON' => [
                                    'glpi_contracts' => 'id',
                                    'glpi_plugin_manageentities_contractdays' => 'contracts_id',
                                ],
                            ],
                            'glpi_plugin_manageentities_contractstates' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                                    'glpi_plugin_manageentities_contractstates' => 'id',
                                ],
                            ],
                        ],
                        'WHERE' => [
                            'glpi_contracts.entities_id' => $dataEntity['entities_id'],
                            'glpi_plugin_manageentities_contractdays.contracts_id' => $dataContract["contracts_id"],
                        ],
                        'GROUPBY' => 'glpi_plugin_manageentities_contractdays.id',
                        'ORDERBY' => ['glpi_plugin_manageentities_contractdays.end_date ASC'],
                    ];

                    if ($config->fields['hourorday'] == Config::DAY) {// Hourly
                        $criteriad['SELECT'] = array_merge(
                            $criteriad['SELECT'],
                            ['glpi_plugin_manageentities_contractdays.contract_type AS contract_type']
                        );
                        $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_contractdays.contract_type' => $types_contracts];
                    }

                    if (isset($options['contract_states'])
                        && $options['contract_states'] != '0') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => $options['contract_states']];
                    } elseif (isset($preferences['contract_states']) && $preferences['contract_states'] != null) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => json_decode(
                                $preferences['contract_states'],
                                true
                            ),
                        ];
                    } elseif (isset($config_states['contract_states']) && $config_states['contract_states'] != null) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => json_decode(
                                $config_states['contract_states'],
                                true
                            ),
                        ];
                    }

                    $iteratord = $DB->request($criteriad);

                    $nbContractDay = (count($iteratord) > 0 ? count($iteratord) > 0 : 0);

                    if ($nbContractDay > 0) {
                        $nbContratByEntities++;
                        $name_contract = "";

                        if (Session::getCurrentInterface() == 'central') {
                            $link_contract = Toolbox::getItemTypeFormURL("Contract");
                            $name_contract .= "<a href='" . $link_contract . "?id=" . $dataContract["contracts_id"] . "'>";
                        }
                        if ($dataContract["name"] == null) {
                            $name = "(" . $dataContract["contracts_id"] . ")";
                        } else {
                            $name = $dataContract["name"];
                        }
                        if (Session::getCurrentInterface() == 'central') {
                            $name_contract .= $name . "</a>";
                        }

                        $list[$num]['entities_name'] = $dataEntity['entities_name'];
                        $list[$num]['entities_id'] = $dataEntity['entities_id'];
                        $list[$num]['contract_name'] = $name_contract;
                        $list[$num]['name'] = $name;
                        $list[$num]['contract_num'] = $dataContract['num'];
                        $list[$num]['contracts_id'] = $dataContract['contracts_id'];
                        $list[$num]['contract_begin_date'] = Html::convDate($dataContract['contract_begin_date']);
                        $list[$num]['show_on_global_gantt'] = $dataContract['show_on_global_gantt'];
                        $i = 0;
                        foreach ($iteratord as $dataContractDay) {
                            $i++;
                            if ($config->fields['hourorday'] == Config::HOUR) {// Daily
                                $dataContractDay["contract_type"] = $dataContract["contract_type"];
                            }

                            if ($dataContractDay["name_contractdays"] == null) {
                                $nameperiod = "(" . $dataContractDay["contractdays_id"] . ")";
                            } else {
                                $nameperiod = $dataContractDay["name_contractdays"];
                            }

                            // We get all cri details
                            $dataContractDay['values_begin_date'] = $beginDate;
                            $dataContractDay['contracts_id'] = $dataContract['contracts_id'];
                            $dataContractDay['entities_id'] = $dataContract['entities_id'];
                            $dataContractDay['contractdays_id'] = $dataContractDay["contractdays_id"];

                            $resultCriDetail = self::getCriDetailDataSimplified(
                                $dataContractDay,
                                ["contract_type_id" => $dataContractDay["contract_type"]]
                            );

                            if (Session::getCurrentInterface() == 'helpdesk'
                                && $dataContractDay["contract_type"] == Contract::CONTRACT_TYPE_UNLIMITED) {
                                $credit = Contract::getContractType(
                                    $dataContractDay["contract_type"]
                                );
                            } else {
                                $credit = $dataContractDay['nbday'] + $dataContractDay['report'];
                            }

                            $list[$num]['days'][$i]['contract_is_closed'] = $dataContractDay['is_closed'];
                            $list[$num]['days'][$i]['contractdayname'] = $nameperiod;
                            $list[$num]['days'][$i]['credit'] = $credit;
                            $list[$num]['days'][$i]['end_date'] = $dataContractDay['end_date'];
                            $list[$num]['days'][$i]['reste'] = $resultCriDetail['resultOther']['reste'];
                            $list[$num]['days'][$i]['depass'] = $resultCriDetail['resultOther']['depass'];
                            $list[$num]['days'][$i]['contractdays_id'] = $dataContractDay["contractdays_id"];
                            $list[$num]['days'][$i]['contracts_id'] = $dataContractDay['contracts_id'];
                        }
                        $num++;
                    }
                }
            }
        }

        return $list;
    }

    public static function getCriDetailDataSimplified($contractDayValues = [], $options = [])
    {
        global $DB;
        $params['condition'] = '1';

        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }

        $tot_conso = 0;

        $config = Config::getInstance();

        $PDF = new CriPDF('P', 'mm', 'A4');

        $tabOther = [
            'depass' => 0,
            'reste' => 0,
        ];

        $criteria = [
            'SELECT' => [
                'glpi_plugin_manageentities_cridetails.tickets_id',
                'glpi_plugin_manageentities_cridetails.id as cridetails_id',
                'glpi_tickets.global_validation',
            ],
            'FROM' => 'glpi_plugin_manageentities_cridetails',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
                'glpi_tickettasks' => [
                    'ON' => [
                        'glpi_tickets' => 'id',
                        'glpi_tickettasks' => 'tickets_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_cridetails.contracts_id' => $contractDayValues["contracts_id"],
                'glpi_plugin_manageentities_cridetails.entities_id' => $contractDayValues["entities_id"],
                'glpi_plugin_manageentities_cridetails.plugin_manageentities_contractdays_id' => $contractDayValues["contractdays_id"],
                'glpi_tickets.is_deleted' => 0,
                'glpi_tickets.actiontime' => ['>', 0],

            ],
            'GROUPBY' => ['glpi_plugin_manageentities_cridetails.id'],
            'ORDERBY' => ['glpi_plugin_manageentities_cridetails.date ASC'],
        ];

        $iterator = $DB->request($criteria);

        $restrict = [
            "`glpi_plugin_manageentities_contracts`.`entities_id`" => $contractDayValues["entities_id"],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contractDayValues["contracts_id"],
        ];

        $dbu = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract = reset($pluginContracts);

        if (count($iterator) > 0) {
            foreach ($iterator as $dataCriDetail) {
                $criteriat = [
                    'SELECT' => [
                        'actiontime',
                        'users_id_tech',
                        'is_private',
                    ],
                    'FROM' => 'glpi_tickettasks',
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_cridetails' => [
                            'ON' => [
                                'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                                'glpi_tickettasks' => 'tickets_id',
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_tickettasks.tickets_id' => $dataCriDetail['tickets_id'],
                        'glpi_tickettasks.is_private' => 0,
                        'glpi_plugin_manageentities_cridetails.id' => $dataCriDetail['cridetails_id'],
                    ],
                    'ORDERBY' => ['glpi_tickettasks.begin'],
                ];
                if ($config->fields['hourorday'] == Config::HOUR) {
                    $criteriat['LEFT JOIN'] = $criteriat['LEFT JOIN'] + [
                        'glpi_plugin_manageentities_taskcategories' => [
                            'ON' => [
                                'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                'glpi_tickettasks' => 'taskcategories_id',
                            ],
                        ],
                    ];
                    $criteriat['WHERE'] = $criteriat['WHERE'] + ['glpi_plugin_manageentities_taskcategories.is_usedforcount' => 1];
                }

                $iteratort = $DB->request($criteriat);
                $conso = 0;

                if (count($iteratort) > 0) {
                    foreach ($iteratort as $dataTask) {
                        // Set conso per techs
                        $tmp = CriDetail::setConso(
                            $dataTask['actiontime'],
                            0,
                            $config,
                            $dataCriDetail,
                            $pluginContract,
                            1
                        );

                        // Set global conso of contractday
                        $conso += $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                    }
                }

                $tot_conso += $conso;
            }
        }

        //Rest number / depass
        $tabOther['reste'] = ($contractDayValues["nbday"] + $contractDayValues["report"]) - $tot_conso;
        if ($tabOther['reste'] < 0) {
            $tabOther['depass'] = abs($tabOther['reste']);
            $tabOther['reste'] = 0;
        }

        return ['resultOther' => $tabOther];
    }
}
