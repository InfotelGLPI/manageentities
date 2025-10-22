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
use DbUtils;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Preference;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

use Glpi\DBAL\QueryExpression;
use Html;
use Search;
use Session;
use Toolbox;

class Followup extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 0)
    {
        return __('General follow-up', 'manageentities');
    }

    static function getIcon()
    {
        return "ti ti-vocabulary";
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [READ, CREATE, UPDATE, DELETE]);
    }

    static function queryFollowUp($instID, $options = [])
    {
        global $DB;

        $dbu = new DbUtils();

        $beginDateAfter = '';
        $beginDateBefore = '';
        $endDateAfter = '';
        $endDateBefore = '';
        $beginDate = '';
        $endDate = '';
        $contractState = '';
        $queryBusiness = '';
        $queryCompany = '';
        $num = 0;
        $list = [];
        $tot_credit = 0;
        $contract_credit = 0;
        $tot_conso = 0;
        $contract_conso = 0;
        $tot_reste = 0;
        $tot_depass = 0;
        $tot_forfait = 0;
        $contract_forfait = 0;
        $tot_reste_montant = 0;
        $contract_reste_montant = 0;
        $nbContratByEntities = 0;// Count the contracts for all entities
        $contract_depass = 0;
        $pricecri = [];

        // We configure the type of contract Hourly or Dayly
        $config = Config::getInstance();
        if ($config->fields['hourorday'] == Config::HOUR) {// Hourly

            $types_contracts = [
                Contract::CONTRACT_TYPE_NULL,
                Contract::CONTRACT_TYPE_HOUR,
                Contract::CONTRACT_TYPE_INTERVENTION,
                Contract::CONTRACT_TYPE_UNLIMITED
            ];
        } else {// Daily

            $types_contracts = [
                Contract::CONTRACT_TYPE_NULL,
                Contract::CONTRACT_TYPE_AT,
                Contract::CONTRACT_TYPE_FORFAIT
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
                'glpi_entities.name AS entities_name'
            ],
            'DISTINCT' => true,
            'FROM' => 'glpi_contracts',
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'ON' => [
                        'glpi_contracts' => 'entities_id',
                        'glpi_entities' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'NOT' => ['glpi_entities.name' => 'NULL', 'glpi_entities.id' => 'NULL']
            ],
            'ORDERBY' => 'glpi_entities.name',
        ];
        $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
                'glpi_entities'
            );

        if (isset($options['entities_id']) && $options['entities_id'] != '-1') {
            $sons = $dbu->getSonsOf('glpi_entities', $options['entities_id']);
            $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_contracts.entities_id' => $sons];
        } else {
            if (Session::getCurrentInterface() == 'central') {
                $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
                        'glpi_contracts'
                    );
            } else {
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_contracts.entities_id' => $instID];
            }
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
                        'glpi_contracts.duration AS duration',
                        'glpi_contracts.entities_id AS entities_id',
                        'glpi_plugin_manageentities_contracts.management AS management',
                        'glpi_plugin_manageentities_contracts.contract_type AS contract_type',
                        'glpi_plugin_manageentities_contracts.date_signature AS date_signature',
                        'glpi_plugin_manageentities_contracts.date_renewal AS date_renewal',
                        'glpi_plugin_manageentities_contracts.contract_added AS contract_added',
                        'glpi_plugin_manageentities_contracts.show_on_global_gantt AS show_on_global_gantt',
                    ],
                    'FROM' => 'glpi_contracts',
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_contracts' => [
                            'ON' => [
                                'glpi_contracts' => 'id',
                                'glpi_plugin_manageentities_contracts' => 'contracts_id'
                            ]
                        ]
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
                            'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id AS contractstates_id',
                            'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
                            'glpi_plugin_manageentities_contractdays.plugin_manageentities_critypes_id',
                            'glpi_plugin_manageentities_contractdays.report AS report',
                            'glpi_plugin_manageentities_contractdays.nbday AS nbday',
                            'glpi_plugin_manageentities_contractstates.is_closed AS is_closed',
                            'glpi_plugin_manageentities_contractdays.begin_date AS begin_date',
                            'glpi_plugin_manageentities_contractdays.end_date AS end_date',
                            'glpi_plugin_manageentities_contractstates.color',
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
                            'glpi_plugin_manageentities_businesscontacts' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'entities_id',
                                    'glpi_plugin_manageentities_businesscontacts' => 'entities_id'
                                ]
                            ],
                            'glpi_entities' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'entities_id',
                                    'glpi_entities' => 'id'
                                ]
                            ]
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
                                )
                            ];
                    } elseif (isset($config_states['contract_states']) && $config_states['contract_states'] != null) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => json_decode(
                                    $config_states['contract_states'],
                                    true
                                )
                            ];
                    }

                    if (isset($options['business_id'])
                        && $options['business_id'] != '0') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_businesscontacts.users_id' => $options['contract_states']];
                    } elseif (isset($preferences['business_id']) && $preferences['business_id'] != null) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_businesscontacts.users_id' => json_decode(
                                    $preferences['contract_states'],
                                    true
                                )
                            ];
                    } elseif (isset($config_states['business_id']) && $config_states['business_id'] != null) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_businesscontacts.users_id' => json_decode(
                                    $config_states['contract_states'],
                                    true
                                )
                            ];
                    }

                    if (isset($options['company_id']) && $options['company_id'] != '0') {
                        $temp = 0;
                        foreach ($options['company_id'] as $id) {
                            $plugin_company = new Company();
                            $company = $plugin_company->find(['id' => $id]);
                            $company = reset($company);
                            $sons = [];
                            if ($company['recursive'] == 1) {
                                $sons = $dbu->getSonsOf('glpi_entities', $company['entity_id']);
                            } else {
                                $sons[0] = $company['entity_id'];
                            }
                        }
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_entities.id' => $sons
                            ];
                    } elseif (isset($preferences['companies_id']) && $preferences['companies_id'] != null) {
                        foreach (json_decode($preferences['companies_id'], true) as $id) {
                            $sons = [];
                            $plugin_company = new Company();
                            $company = $plugin_company->find(['id' => $id]);
                            $company = reset($company);
                            if ($company['recursive'] == 1) {
                                $sons = $dbu->getSonsOf('glpi_entities', $company['entity_id']);
                            } else {
                                $sons[0] = $company['entity_id'];
                            }
                        }
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_entities.id' => $sons
                            ];
                    }

                    //$beginDateAfter $beginDateBefore $endDateAfter $endDateBefore
                    if (isset($options['begin_date_after']) && $options['begin_date_after'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.begin_date' => [
                                    '>=',
                                    $options['begin_date_after']
                                ]
                            ];
                        $beginDate = $options['begin_date_after'];
                    }

                    if (isset($options['begin_date_before']) && $options['begin_date_before'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.begin_date' =>
                                    [
                                        '<=',
                                        new QueryExpression(
                                            "ADDDATE('" . $options['begin_date_before'] . "' , INTERVAL 1 DAY)"
                                        )
                                    ]
                            ];
                    }

                    if (isset($options['end_date_after']) && $options['end_date_after'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.end_date' => ['>=', $options['end_date_after']]
                            ];
                    }

                    if (isset($options['end_date_before']) && $options['end_date_before'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.end_date' =>
                                    [
                                        '<=',
                                        new QueryExpression(
                                            "ADDDATE('" . $options['end_date_before'] . "' , INTERVAL 1 DAY)"
                                        )
                                    ]
                            ];
                        $endDate = $options['end_date_before'];
                    }

                    $iteratord = $DB->request($criteriad);

                    $nbContractDay = (count($iteratord) > 0 ? count($iteratord) > 0 : 0);
                    if ($nbContractDay > 0) {
                        $nbContratByEntities++;
                        $contract_reste = 0;
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
                        $list[$num]['management'] = Contract::getContractManagement(
                            $dataContract['management']
                        );
                        $list[$num]['contract_type'] = $dataContract['contract_type'];
                        $list[$num]['contract_added'] = \Dropdown::getYesNo($dataContract['contract_added']);
                        $list[$num]['date_signature'] = Html::convDate($dataContract['date_signature']);
                        $list[$num]['date_renewal'] = Html::convDate($dataContract['date_renewal']);
                        $list[$num]['contract_begin_date'] = Html::convDate($dataContract['contract_begin_date']);
                        $list[$num]['duration'] = $dataContract['duration'];
                        $list[$num]['contracts_id'] = $dataContract['contracts_id'];
                        $list[$num]['show_on_global_gantt'] = $dataContract['show_on_global_gantt'];
                        $i = 0;
                        foreach ($iteratord as $dataContractDay) {
                            $i++;
                            $name_period = "";
                            if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
                                $dataContractDay["contract_type"] = $dataContract["contract_type"];
                            }
                            if (Session::getCurrentInterface() == 'central') {
                                $link_period = Toolbox::getItemTypeFormURL(ContractDay::class);
                                $name_period = "<a class='ganttWhite' href='" . $link_period . "?id=" . $dataContractDay["contractdays_id"] . "&showFromPlugin=1'>";
                            } else {
                                $name_period = $dataContractDay["name_contractdays"];
                            }

                            if ($dataContractDay["name_contractdays"] == null) {
                                $nameperiod = "(" . $dataContractDay["contractdays_id"] . ")";
                            } else {
                                $nameperiod = $dataContractDay["name_contractdays"];
                            }
                            if (Session::getCurrentInterface() == 'central') {
                                $name_period .= $nameperiod . "</a>";
                            }

                            // We get all cri details
                            $dataContractDay['values_begin_date'] = $beginDate;
                            $dataContractDay['values_end_date'] = $endDate;
                            $dataContractDay['contracts_id'] = $dataContract['contracts_id'];
                            $dataContractDay['entities_id'] = $dataContract['entities_id'];

                            $resultCriDetail = CriDetail::getCriDetailData(
                                $dataContractDay,
                                ["contract_type_id" => $dataContractDay["contract_type"]]
                            );

                            $tot_amount = 0;
                            $forfait = $resultCriDetail['resultOther']['forfait'];
                            $depass = 0;
                            $conso = 0;

                            foreach ($resultCriDetail['result'] as $dataCriDetail) {
                                $conso += $dataCriDetail['conso'];
                                $tot_amount += $dataCriDetail['conso_amount'];

                                $pricecri[$dataCriDetail['plugin_manageentities_critypes_id']] = $dataCriDetail['pricecri'];
                            }

                            //Rest number / depass
                            $reste = ($dataContractDay["nbday"] + $dataContractDay["report"]) - $conso;
                            if ($reste < 0) {
                                $depass = abs($reste);
                                $reste = 0;
                            }

                            //Rest amount
                            $reste_montant = $resultCriDetail['resultOther']['reste_montant'];

                            if (Session::getCurrentInterface() == 'helpdesk'
                                && $dataContractDay["contract_type"] == Contract::CONTRACT_TYPE_UNLIMITED) {
                                $credit = Contract::getContractType(
                                    $dataContractDay["contract_type"]
                                );
                            } else {
                                $credit = $dataContractDay['nbday'] + $dataContractDay['report'];
                                $tot_credit += $credit;
                                $tot_reste += $resultCriDetail['resultOther']['reste'];
                                $tot_depass += $resultCriDetail['resultOther']['depass'];
                            }
                            $contract_credit += $credit;
                            $tot_conso += $conso;
                            $contract_conso += $conso;
                            $tot_forfait += $forfait;
                            $contract_forfait += $forfait;
                            $tot_reste_montant += $reste_montant;
                            $contract_reste_montant += $reste_montant;

                            $and = "";

                            if ($config->fields['useprice'] == Config::NOPRICE) {
                                $criteria_tik = [
                                    'SELECT' => [
                                        'date',
                                    ],
                                    'FROM' => 'glpi_tickets',
                                    'WHERE' => [
                                        'glpi_tickets.entities_id' => $dataEntity['entities_id'],
                                        'glpi_tickets.is_deleted' => 0
                                    ],
                                ];

                                if (!empty($dataContractDay['begin_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                            'date' => [
                                                '>=',
                                                $dataContractDay['begin_date']
                                            ]
                                        ];
                                }

                                if (!empty($dataContractDay['end_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                            'date' => [
                                                '>=',
                                                new QueryExpression(
                                                    "ADDDATE('" . $dataContractDay['end_date'] . "' , INTERVAL 1 DAY)"
                                                )
                                            ]
                                        ];
                                }
                            } else {
                                $criteria_tik = [
                                    'SELECT' => [
                                        'glpi_plugin_manageentities_cridetails.date',
                                    ],
                                    'FROM' => 'glpi_plugin_manageentities_cridetails',
                                    'LEFT JOIN' => [
                                        'glpi_tickets' => [
                                            'ON' => [
                                                'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                                                'glpi_tickets' => 'id'
                                            ]
                                        ]
                                    ],
                                    'WHERE' => [
                                        'glpi_tickets.entities_id' => $dataEntity['entities_id'],
                                        'glpi_tickets.is_deleted' => 0
                                    ],
                                    'ORDERBY' => 'glpi_plugin_manageentities_cridetails.date DESC',
                                    'LIMIT' => 1,
                                ];

                                if (!empty($dataContractDay['begin_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                            'glpi_plugin_manageentities_cridetails.date' => [
                                                '>=',
                                                $dataContractDay['begin_date']
                                            ]
                                        ];
                                }

                                if (!empty($dataContractDay['end_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                            'glpi_plugin_manageentities_cridetails.date' => [
                                                '>=',
                                                new QueryExpression(
                                                    "ADDDATE('" . $dataContractDay['end_date'] . "' , INTERVAL 1 DAY)"
                                                )
                                            ]
                                        ];
                                }
                            }


                            $iterator_tik = $DB->request($criteria_tik);
                            $date = null;
                            foreach ($iterator_tik as $dataTicket) {
                                $date = Html::convDate($dataTicket['date']);
                            }

                            $iterator_col = $DB->request([
                                'SELECT' => [
                                    'color',
                                ],
                                'FROM' => 'glpi_plugin_manageentities_contractstates',
                                'WHERE' => [
                                    'id' => $dataContractDay['contractstates_id']
                                ],
                            ]);

                            if (count($iterator_col) > 0) {
                                foreach ($iterator_col as $data_col) {
                                    $color = $data_col['color'];
                                }
                            }

                            $list[$num]['days'][$i]['contract_is_closed'] = $dataContractDay['is_closed'];
                            $list[$num]['days'][$i]['contractday_name'] = $name_period;
                            $list[$num]['days'][$i]['contractdayname'] = $nameperiod;
                            $list[$num]['days'][$i]['contractstates'] = \Dropdown::getDropdownName(
                                'glpi_plugin_manageentities_contractstates',
                                $dataContractDay['contractstates_id']
                            );
                            $list[$num]['days'][$i]['contractstates_color'] = $color;
                            $list[$num]['days'][$i]['begin_date'] = Html::convDate($dataContractDay['begin_date']);
                            $list[$num]['days'][$i]['end_date'] = Html::convDate($dataContractDay['end_date']);
                            $list[$num]['days'][$i]['credit'] = $credit;
                            $list[$num]['days'][$i]['conso'] = $conso;
                            $list[$num]['days'][$i]['reste'] = $resultCriDetail['resultOther']['reste'];
                            $list[$num]['days'][$i]['depass'] = $resultCriDetail['resultOther']['depass'];
                            $list[$num]['days'][$i]['price'] = $pricecri;
                            $list[$num]['days'][$i]['forfait'] = Html::formatNumber($forfait);
                            $list[$num]['days'][$i]['reste_montant'] = Html::formatNumber(
                                $resultCriDetail['resultOther']['reste_montant']
                            );
                            $list[$num]['days'][$i]['last_visit'] = $date;
                            $list[$num]['days'][$i]['contractdays_id'] = $dataContractDay["contractdays_id"];
                            $list[$num]['days'][$i]['contract_type'] = $dataContractDay["contract_type"];
                            $list[$num]['days'][$i]['contracts_id'] = $dataContractDay['contracts_id'];

                            $contract_reste += $resultCriDetail['resultOther']['reste'];
                            $contract_depass += $resultCriDetail['resultOther']['depass'];
                        }


                        if ($contract_reste < 0) {
                            $contract_depass = abs($contract_reste);
                            $contract_reste = 0;
                        }

                        $list[$num]['contract_tot']['contract_credit'] = $contract_credit;
                        $list[$num]['contract_tot']['contract_conso'] = $contract_conso;
                        $list[$num]['contract_tot']['contract_reste'] = $contract_reste;
                        $list[$num]['contract_tot']['contract_depass'] = $contract_depass;
                        $list[$num]['contract_tot']['contract_forfait'] = $contract_forfait;
                        $list[$num]['contract_tot']['contract_reste_montant'] = $contract_reste_montant;

                        $contract_credit = 0;
                        $contract_conso = 0;
                        $contract_depass = 0;
                        $contract_forfait = 0;
                        $contract_reste_montant = 0;
                        $num++;
                    }
                }
            }
        }
        if ($nbContratByEntities > 0) {
            $list['tot']['tot_credit'] = $tot_credit;
            $list['tot']['tot_conso'] = $tot_conso;
            $list['tot']['tot_reste'] = $tot_reste;
            $list['tot']['tot_depass'] = $tot_depass;
            $list['tot']['tot_forfait'] = $tot_forfait;
            $list['tot']['tot_reste_montant'] = $tot_reste_montant;
        }

        return $list;
    }

    static function showFollowUp($values)
    {
        global $DB;
        $list = self::queryFollowUp($_SESSION["glpiactive_entity"], $values);

        $default_values["start"] = $start = 0;
        $default_values["id"] = $id = 0;
        $default_values["export"] = $export = false;

        foreach ($default_values as $key => $val) {
            if (isset($values[$key])) {
                $$key = $values[$key];
            }
        }

        // Set display type for export if define
        $output_type = Search::HTML_OUTPUT;

        if (isset($values["display_type"])) {
            $output_type = $values["display_type"];
        }

        $nbcols = 12;
        $row_num = 0;
        $numrows = 1;
        $config = Config::getInstance();

        $contract_states = null;
        if (isset($values['contract_states'])
            && is_array($values['contract_states'])
            && count($values['contract_states']) > 0) {
            foreach ($values['contract_states'] as $key => $contract_state) {
                $contract_states .= "&amp;contract_states[$key]=$contract_state";
            }
        } else {
            $contract_states .= "&amp;contract_states=0";
        }

        $business_ids = null;
        if (isset($values['business_id'])
            && is_array($values['business_id'])
            && count($values['business_id']) > 0) {
            foreach ($values['business_id'] as $key => $id) {
                $business_ids .= "&amp;business_id[$key]=$id";
            }
        }

        $company_ids = null;
        if (isset($values['company_id'])
            && is_array($values['company_id'])
            && count($values['company_id']) > 0) {
            foreach ($values['company_id'] as $key => $id) {
                $company_ids .= "&amp;company_id[$key]=$id";
            }
        }

        $parameters = "begin_date_after=" . $values['begin_date_after'] . "&amp;begin_date_before=" .
            $values['begin_date_before'] . "&amp;end_date_after=" . $values['end_date_after'] .
            "&amp;end_date_before=" . $values['end_date_before']
            . $contract_states . "&amp;entities_id=" . $values['entities_id'] . "&amp;" . $business_ids . $company_ids;

        // Colspan
        $colspan = '2';
        if (Session::getCurrentInterface() == 'helpdesk') {
            $colspan = '6';
        }
        $colspan_contract = $colspan + 1;

        if (!empty($list)) {
            if ($output_type == Search::HTML_OUTPUT && Session::getCurrentInterface() == 'central') {
                self::showLegendary();
                self::printPager($start, $numrows, $_SERVER['PHP_SELF'], $parameters, Followup::class);
            }

            echo Search::showHeader($output_type, 1, $nbcols);
            echo Search::showBeginHeader($output_type);
            $item_num = 0;
            echo Search::showNewLine($output_type);
            if ($output_type != Search::HTML_OUTPUT) {
                if (Session::getCurrentInterface() == 'central') {
                    echo Search::showHeaderItem($output_type, _n('Client', 'Clients', 1, 'manageentities'), $item_num);
                }

                echo Search::showHeaderItem(
                    $output_type,
                    __('Contract'),
                    $item_num,
                    "",
                    0,
                    "",
                    "colspan='" . $colspan_contract . "'"
                );
                echo Search::showHeaderItem($output_type, '', $item_num);
                echo Search::showHeaderItem($output_type, '', $item_num);
                echo Search::showHeaderItem(
                    $output_type,
                    _x('phone', 'Number'),
                    $item_num,
                    "",
                    0,
                    "",
                    "colspan='" . $colspan . "'"
                );
                echo Search::showHeaderItem($output_type, '', $item_num);
            }

            if (Session::getCurrentInterface() == 'central') {
                if ($output_type != Search::HTML_OUTPUT) {
                    if ($config->fields['hourorday'] == Config::DAY) {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Contract present', 'manageentities'),
                            $item_num,
                            "",
                            0,
                            "",
                            "colspan='2'"
                        );
                    } else {
                        echo Search::showHeaderItem($output_type, '', $item_num);
                    }
                    echo Search::showHeaderItem($output_type, '', $item_num);
                    echo Search::showHeaderItem(
                        $output_type,
                        __('Date of signature', 'manageentities'),
                        $item_num,
                        "",
                        0,
                        "",
                        "colspan='2'"
                    );
                    echo Search::showHeaderItem($output_type, '', $item_num);
                    echo Search::showHeaderItem(
                        $output_type,
                        __('Date of renewal', 'manageentities'),
                        $item_num,
                        "",
                        0,
                        "",
                        "colspan='2'"
                    );
                    //               echo Search::showHeaderItem($output_type, '', $item_num);
                    if ($config->fields['hourorday'] == Config::HOUR) {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Mode of management', 'manageentities'),
                            $item_num
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Type of service contract', 'manageentities'),
                            $item_num
                        );
                    }
                }
            }

            echo Search::showEndLine($output_type);
            echo Search::showEndHeader($output_type);

            $entity_id = 0;
            $first = true;

            foreach ($list as $v => $contract) {
                if ($config->fields['useprice'] == Config::NOPRICE) {
                    $colspanNoprice = "colspan='2'";
                } else {
                    $colspanNoprice = "";
                }

                if (is_numeric($v)) {
                    $first = false;// First entity ?

                    // Display Entity
                    if ($output_type == Search::HTML_OUTPUT && Session::getCurrentInterface() == 'central') {
                        if ($entity_id != $contract['entities_id']) {
                            $row_num++;
                            $item_num = 0;

                            echo Search::showNewLine($output_type);
                            if ($config->fields['hourorday'] == Config::HOUR) {
                                $colspanContract = "colspan = '13'";
                            } else {
                                $colspanContract = "colspan = '12'";
                            }
                            if (empty($contract['contract_name'])) {
                                $contract['contract_name'] = $contract['name'];
                            }
                            echo Search::showHeaderItem(
                                $output_type,
                                '<b>' . _n(
                                    'Client',
                                    'Clients',
                                    1,
                                    'manageentities'
                                ) . ' : </b>' . $contract['entities_name'],
                                $item_num,
                                '',
                                0,
                                '',
                                $colspanContract . " style='" . Monthly::$style[0] . "' "
                            );
                            echo Search::showEndLine($output_type);
                        }
                    }

                    $row_num++;
                    $item_num = 0;

                    echo Search::showNewLine($output_type);
                    // Display Entity
                    if (Session::getCurrentInterface() == 'central') {
                        if ($entity_id != $contract['entities_id']) {
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showItem($output_type, $contract['entities_name'], $item_num, $row_num);
                            }
                        } else {
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showItem($output_type, '', $item_num, $row_num);
                            }
                        }
                        $entity_id = $contract['entities_id'];
                    }

                    // Display Contract title
                    if (empty($contract['contract_name'])) {
                        $contract['contract_name'] = $contract['name'];
                    }
                    if ($output_type != Search::HTML_OUTPUT) {
                        echo Search::showItem($output_type, $contract['contract_name'], $item_num, $row_num);
                        echo Search::showItem($output_type, '', $item_num, $row_num);
                        echo Search::showItem($output_type, '', $item_num, $row_num);
                    } else {
                        $colspanContractName = "colspan='4'";

                        echo Search::showItem(
                            $output_type,
                            '<b>' . __('Contract') . ' : </b>' . $contract['contract_name'],
                            $item_num,
                            $row_num,
                            $colspanContractName
                        );
                    }

                    // Display contract Num
                    if ($output_type != Search::HTML_OUTPUT) {
                        echo Search::showItem(
                            $output_type,
                            $contract['contract_num'],
                            $item_num,
                            $row_num,
                            "colspan='" . $colspan . "'"
                        );
                        echo Search::showItem($output_type, '', $item_num, $row_num);
                    } else {
                        echo Search::showItem(
                            $output_type,
                            '<b>' . _x('phone', 'Number') . ' : </b>' . $contract['contract_num'],
                            $item_num,
                            $row_num,
                            "colspan='" . $colspan . "'"
                        );
                    }

                    if (Session::getCurrentInterface() == 'central') {
                        // Display contract added
                        if ($output_type != Search::HTML_OUTPUT) {
                            if ($config->fields['hourorday'] == Config::DAY) {
                                echo Search::showItem(
                                    $output_type,
                                    $contract['contract_added'],
                                    $item_num,
                                    $row_num,
                                    "colspan='2'"
                                );
                            } else {
                                echo Search::showItem($output_type, '', $item_num, $row_num);
                            }
                            echo Search::showItem($output_type, '', $item_num, $row_num);
                        } else {
                            if ($config->fields['hourorday'] == Config::DAY) {
                                echo Search::showItem(
                                    $output_type,
                                    '<b>' . __(
                                        'Contract present',
                                        'manageentities'
                                    ) . ' : </b>' . $contract['contract_added'],
                                    $item_num,
                                    $row_num,
                                    "colspan='2'"
                                );
                            }
                        }
                        // Display Signature
                        if ($output_type != Search::HTML_OUTPUT) {
                            echo Search::showItem(
                                $output_type,
                                $contract['date_signature'],
                                $item_num,
                                $row_num,
                                "colspan='2'"
                            );
                            echo Search::showItem($output_type, '', $item_num, $row_num);
                        } else {
                            echo Search::showItem(
                                $output_type,
                                '<b>' . __(
                                    'Date of signature',
                                    'manageentities'
                                ) . ' : </b>' . $contract['date_signature'],
                                $item_num,
                                $row_num,
                                "colspan='2'"
                            );
                        }
                        // Display reconduction
                        if ($output_type != Search::HTML_OUTPUT) {
                            echo Search::showItem(
                                $output_type,
                                $contract['date_renewal'],
                                $item_num,
                                $row_num,
                                "colspan='2'"
                            );
                            //                     echo Search::showItem($output_type, '', $item_num, $row_num);
                        } else {
                            echo Search::showItem(
                                $output_type,
                                '<b>' . __('Date of renewal', 'manageentities') . ' : </b>' . $contract['date_renewal'],
                                $item_num,
                                $row_num,
                                "colspan='2'"
                            );
                        }
                        // Display contract Type and contract mode
                        if ($config->fields['hourorday'] == Config::HOUR) {
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showItem($output_type, $contract['management'], $item_num, $row_num);
                                echo Search::showItem(
                                    $output_type,
                                    Contract::getContractType($contract['contract_type']),
                                    $item_num,
                                    $row_num
                                );
                            } else {
                                echo Search::showItem(
                                    $output_type,
                                    '<b>' . __(
                                        'Mode of management',
                                        'manageentities'
                                    ) . ' : </b>' . $contract['management'],
                                    $item_num,
                                    $row_num
                                );
                                echo Search::showItem(
                                    $output_type,
                                    '<b>' . __(
                                        'Type of service contract',
                                        'manageentities'
                                    ) . ' : </b>' . Contract::getContractType(
                                        $contract['contract_type']
                                    ),
                                    $item_num,
                                    $row_num
                                );
                            }
                        }
                    }
                    echo Search::showEndLine($output_type);

                    // Contract details headers
                    $row_num++;
                    $item_num = 0;

                    echo Search::showNewLine($output_type);
                    if (Session::getCurrentInterface() == 'central' && $output_type != Search::HTML_OUTPUT) {
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                    }
                    echo Search::showHeaderItem(
                        $output_type,
                        __('Period of contract', 'manageentities'),
                        $item_num,
                        '',
                        0,
                        '',
                        " $colspanNoprice style='" . Monthly::$style[1] . "'"
                    );

                    if ($config->fields['hourorday'] == Config::HOUR)// Coslpan if type = Hourly
                    {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('State of contract', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan='2' style='" . Monthly::$style[1] . "'"
                        );
                    } else {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('State of contract', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                    }

                    if ($config->fields['hourorday'] == Config::DAY) {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Type of contract', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan='2' style='" . Monthly::$style[1] . "'"
                        );
                    }

                    if ($config->fields['hourorday'] == Config::HOUR)// Coslpan if type = Hourly
                    {
                        echo Search::showHeaderItem($output_type, __('End date'), $item_num, '', 0, '', "colspan='2'");
                    } else {
                        echo Search::showHeaderItem($output_type, __('End date'), $item_num, '');
                    }

                    echo Search::showHeaderItem(
                        $output_type,
                        __('Initial credit', 'manageentities'),
                        $item_num,
                        '',
                        0,
                        '',
                        "$colspanNoprice style='" . Monthly::$style[1] . "'"
                    );
                    echo Search::showHeaderItem(
                        $output_type,
                        __('Total consummated', 'manageentities'),
                        $item_num,
                        '',
                        0,
                        '',
                        "style='" . Monthly::$style[1] . "'"
                    );
                    if (Session::getCurrentInterface() == 'helpdesk'
                        && ($config->fields['hourorday'] == Config::HOUR && $contract['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED)) {
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                    } else {
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Total remaining', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        if (Session::getCurrentInterface() == 'central') {
                            echo Search::showHeaderItem(
                                $output_type,
                                __('Total exceeding', 'manageentities'),
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            if ($config->fields['useprice'] == Config::PRICE) {
                                echo Search::showHeaderItem(
                                    $output_type,
                                    __('Last visit', 'manageentities'),
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                                //                        if($config->fields['hourorday'] == Config::DAY) echo Search::showItem($output_type, __('Applied daily rate', 'manageentities'), $item_num, '', 0, '', "style='".Monthly::$style[1]."'");
                                //                        else echo Search::showHeaderItem($output_type, __('Applied hourly rate', 'manageentities'), $item_num, '', 0, '', "style='".Monthly::$style[1]."'");
                                echo Search::showHeaderItem(
                                    $output_type,
                                    __('Guaranteed package', 'manageentities'),
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                                echo Search::showHeaderItem(
                                    $output_type,
                                    __('Remaining total (amount)', 'manageentities'),
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                            } else {
                                echo Search::showHeaderItem(
                                    $output_type,
                                    __('Last visit', 'manageentities'),
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    " colspan='2' style='" . Monthly::$style[1] . "'"
                                );
                                if ($output_type != Search::HTML_OUTPUT) {
                                    //                           echo Search::showHeaderItem($output_type, '', $item_num, '', 0, '', "style='".Monthly::$style[1]."'");
                                    echo Search::showHeaderItem(
                                        $output_type,
                                        '',
                                        $item_num,
                                        '',
                                        0,
                                        '',
                                        "style='" . Monthly::$style[1] . "'"
                                    );
                                    echo Search::showHeaderItem(
                                        $output_type,
                                        '',
                                        $item_num,
                                        '',
                                        0,
                                        '',
                                        "style='" . Monthly::$style[1] . "'"
                                    );
                                }
                            }
                        }
                    }
                    if ($config->fields['hourorday'] == Config::HOUR && $output_type != Search::HTML_OUTPUT) {
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                    }
                    echo Search::showEndLine($output_type);

                    foreach ($contract['days'] as $w => $day) {
                        $row_num++;
                        $item_num = 0;

                        echo Followup::showNewLine(
                            $output_type,
                            false,
                            $day['contract_is_closed'],
                            $day['contractstates_color']
                        );
                        if (Session::getCurrentInterface() == 'central' && $output_type != Search::HTML_OUTPUT) {
                            echo Search::showItem($output_type, '', $item_num, $row_num);
                        }
                        echo Search::showItem(
                            $output_type,
                            $day['contractday_name'],
                            $item_num,
                            $row_num,
                            " $colspanNoprice "
                        );

                        if ($config->fields['hourorday'] == Config::HOUR)// Coslpan if type = Hourly
                        {
                            echo Search::showItem(
                                $output_type,
                                $day['contractstates'],
                                $item_num,
                                $row_num,
                                "colspan='2' "
                            );
                        } else {
                            echo Search::showItem($output_type, $day['contractstates'], $item_num, $row_num, "");
                        }

                        if ($config->fields['hourorday'] == Config::DAY) {
                            echo Search::showItem(
                                $output_type,
                                Contract::getContractType($day['contract_type']),
                                $item_num,
                                $row_num,
                                "colspan='2' "
                            );
                        }

                        if ($config->fields['hourorday'] == Config::HOUR)// Coslpan if type = Hourly
                        {
                            echo Search::showItem($output_type, $day['end_date'], $item_num, $row_num, "colspan='2' ");
                        } else {
                            echo Search::showItem($output_type, $day['end_date'], $item_num, $row_num, "");
                        }

                        if ((Session::getCurrentInterface() == 'helpdesk' &&
                                ($config->fields['hourorday'] == Config::DAY && $day['contract_type'] == Contract::CONTRACT_TYPE_FORFAIT)) ||
                            ($config->fields['hourorday'] == Config::HOUR && $day['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED)) {
                            echo Search::showItem(
                                $output_type,
                                Dropdown::EMPTY_VALUE,
                                $item_num,
                                $row_num,
                                "$colspanNoprice "
                            );
                        } else {
                            echo Search::showItem(
                                $output_type,
                                Html::formatNumber($day['credit'], 0, 2),
                                $item_num,
                                $row_num,
                                "$colspanNoprice "
                            );
                        }

                        if (Session::getCurrentInterface() == 'central' ||
                            ($config->fields['hourorday'] == Config::DAY && $day['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                            if (Session::getCurrentInterface() == 'helpdesk' &&
                                ($config->fields['hourorday'] == Config::HOUR && $day['contract_type'] != Contract::CONTRACT_TYPE_UNLIMITED && $day['conso'] > $day['credit'])) {
                                echo Search::showItem(
                                    $output_type,
                                    Html::formatNumber($day['credit'], 0, 2),
                                    $item_num,
                                    $row_num,
                                    ""
                                );
                            } else {
                                echo Search::showItem(
                                    $output_type,
                                    Html::formatNumber($day['conso'], 0, 2),
                                    $item_num,
                                    $row_num,
                                    ""
                                );
                            }
                        } else {
                            echo Search::showItem($output_type, \Dropdown::EMPTY_VALUE, $item_num, $row_num, "");
                        }
                        if (Session::getCurrentInterface() == 'helpdesk'
                            && ($config->fields['hourorday'] == Config::HOUR && $contract['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED)) {
                            echo Search::showItem($output_type, '', $item_num, $row_num, "");
                            echo Search::showItem($output_type, '', $item_num, $row_num, "");
                        } else {
                            if (Session::getCurrentInterface(
                                ) == 'central' || $day['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT) {
                                echo Search::showItem(
                                    $output_type,
                                    Html::formatNumber($day['reste'], 0, 2),
                                    $item_num,
                                    $row_num,
                                    ""
                                );
                            } else {
                                echo Search::showItem($output_type, \Dropdown::EMPTY_VALUE, $item_num, $row_num, "");
                            }
                            if (Session::getCurrentInterface() == 'central') {
                                echo Search::showItem(
                                    $output_type,
                                    Html::formatNumber($day['depass'], 0, 2),
                                    $item_num,
                                    $row_num,
                                    ""
                                );
                            }
                        }
                        if (Session::getCurrentInterface() == 'central') {
                            if ($config->fields['useprice'] == Config::PRICE) {
                                echo Search::showItem($output_type, $day['last_visit'], $item_num, $row_num, "");
                                //                        echo Search::showItem($output_type, Html::formatNumber($day['price'], 0, 2), $item_num, $row_num, "");
                                echo Search::showItem($output_type, $day['forfait'], $item_num, $row_num, "");
                                echo Search::showItem($output_type, $day['reste_montant'], $item_num, $row_num, "");
                            } else {
                                echo Search::showItem(
                                    $output_type,
                                    $day['last_visit'],
                                    $item_num,
                                    $row_num,
                                    "colspan='2' "
                                );
                                if ($output_type != Search::HTML_OUTPUT) {
                                    //                           echo Search::showItem($output_type, '', $item_num, $row_num, "");
                                    echo Search::showItem($output_type, '', $item_num, $row_num, "");
                                    echo Search::showItem($output_type, '', $item_num, $row_num);
                                }
                            }
                            if ($config->fields['hourorday'] == Config::HOUR && $output_type != Search::HTML_OUTPUT) {
                                echo Search::showItem($output_type, '', $item_num, $row_num);
                                echo Search::showItem($output_type, '', $item_num, $row_num);
                            }
                        }
                        echo Search::showEndLine($output_type);
                    }

                    if (Session::getCurrentInterface() == 'central') {
                        $row_num++;
                        $item_num = 0;

                        echo Search::showNewLine($output_type);
                        if ($output_type != Search::HTML_OUTPUT) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        }

                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            $row_num,
                            0,
                            '',
                            " $colspanNoprice style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan='2' style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            __('Subtotal', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan='2' style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            Html::formatNumber($contract['contract_tot']['contract_credit'], 0, 2),
                            $item_num,
                            '',
                            0,
                            '',
                            "$colspanNoprice style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            Html::formatNumber($contract['contract_tot']['contract_conso'], 0, 2),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            Html::formatNumber($contract['contract_tot']['contract_reste'], 0, 2),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            Html::formatNumber($contract['contract_tot']['contract_depass'], 0, 2),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );

                        if ($config->fields['useprice'] == Config::PRICE) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );

                            echo Search::showHeaderItem(
                                $output_type,
                                Html::formatNumber($contract['contract_tot']['contract_forfait'], 0, 2),
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            echo Search::showHeaderItem(
                                $output_type,
                                Html::formatNumber($contract['contract_tot']['contract_reste_montant'], 0, 2),
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        } else {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                " $colspanNoprice style='" . Monthly::$style[1] . "'"
                            );
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showHeaderItem(
                                    $output_type,
                                    '',
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                                echo Search::showHeaderItem(
                                    $output_type,
                                    '',
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                            }
                        }
                        if ($config->fields['hourorday'] == Config::HOUR && $output_type != Search::HTML_OUTPUT) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        }
                        echo Search::showEndLine($output_type);
                    }
                } else {
                    //line total
                    if (Session::getCurrentInterface() == 'central') {
                        if ($output_type == Search::HTML_OUTPUT) {
                            $row_num++;
                            $item_num = 0;

                            if ($config->fields['hourorday'] == Config::HOUR) {
                                $colspanTotal = "colspan = '14'";
                            } else {
                                $colspanTotal = "colspan = '13'";
                            }

                            echo Search::showNewLine($output_type);
                            echo Search::showItem(
                                $output_type,
                                '',
                                $item_num,
                                $row_num,
                                "$colspanTotal style='" . Monthly::$style[0] . "'"
                            );
                            echo Search::showEndLine($output_type);
                        }

                        $row_num++;
                        $item_num = 0;

                        echo Search::showNewLine($output_type);
                        if ($output_type != Search::HTML_OUTPUT) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        }
                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            " $colspanNoprice style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan ='2' style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            '',
                            $item_num,
                            '',
                            0,
                            '',
                            "colspan ='2' style='" . Monthly::$style[1] . "'"
                        );

                        echo Search::showHeaderItem(
                            $output_type,
                            __('Total initial credit', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "$colspanNoprice style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Total consummated', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Total remaining', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );
                        echo Search::showHeaderItem(
                            $output_type,
                            __('Total exceeding', 'manageentities'),
                            $item_num,
                            '',
                            0,
                            '',
                            "style='" . Monthly::$style[1] . "'"
                        );

                        if ($config->fields['useprice'] == Config::PRICE) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            echo Search::showHeaderItem(
                                $output_type,
                                __('Total Guaranteed package', 'manageentities'),
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            echo Search::showHeaderItem(
                                $output_type,
                                __('Remaining total (amount)', 'manageentities'),
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        } else {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                " $colspanNoprice style='" . Monthly::$style[1] . "'"
                            );
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showHeaderItem(
                                    $output_type,
                                    '',
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                                echo Search::showHeaderItem(
                                    $output_type,
                                    '',
                                    $item_num,
                                    '',
                                    0,
                                    '',
                                    "style='" . Monthly::$style[1] . "'"
                                );
                            }
                        }
                        if ($config->fields['hourorday'] == Config::HOUR && $output_type != Search::HTML_OUTPUT) {
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                            echo Search::showHeaderItem(
                                $output_type,
                                '',
                                $item_num,
                                '',
                                0,
                                '',
                                "style='" . Monthly::$style[1] . "'"
                            );
                        }
                        echo Search::showEndLine($output_type);

                        $row_num++;
                        $item_num = 0;

                        echo Search::showNewLine($output_type);
                        if ($output_type != Search::HTML_OUTPUT) {
                            echo Search::showItem($output_type, '', $item_num, $row_num);
                        }
                        echo Search::showItem($output_type, '', $item_num, $row_num, " $colspanNoprice ");

                        echo Search::showItem($output_type, '', $item_num, $row_num, "colspan='2'");

                        echo Search::showItem($output_type, '', $item_num, $row_num, "colspan='2' ");

                        echo Search::showItem(
                            $output_type,
                            Html::formatNumber($contract['tot_credit'], 0, 2),
                            $item_num,
                            $row_num,
                            "$colspanNoprice "
                        );
                        echo Search::showItem(
                            $output_type,
                            Html::formatNumber($contract['tot_conso'], 0, 2),
                            $item_num,
                            $row_num,
                            ""
                        );
                        echo Search::showItem(
                            $output_type,
                            Html::formatNumber($contract['tot_reste'], 0, 2),
                            $item_num,
                            $row_num,
                            ""
                        );
                        echo Search::showItem(
                            $output_type,
                            Html::formatNumber($contract['tot_depass'], 0, 2),
                            $item_num,
                            $row_num,
                            ""
                        );

                        echo Search::showItem($output_type, '', $item_num, $row_num, " ");

                        if ($config->fields['useprice'] == Config::PRICE) {
                            echo Search::showItem(
                                $output_type,
                                Html::formatNumber($contract['tot_forfait'], 0, 2),
                                $item_num,
                                $row_num,
                                ""
                            );
                            echo Search::showItem(
                                $output_type,
                                Html::formatNumber($contract['tot_reste_montant'], 0, 2),
                                $item_num,
                                $row_num,
                                ""
                            );
                        } else {
                            echo Search::showItem($output_type, '', $item_num, $row_num, " $colspanNoprice ");
                            if ($output_type != Search::HTML_OUTPUT) {
                                echo Search::showItem($output_type, '', $item_num, $row_num, "");
                            }
                        }
                        if ($config->fields['hourorday'] == Config::HOUR && $output_type != Search::HTML_OUTPUT) {
                            echo Search::showItem($output_type, '', $item_num, $row_num, "");
                            echo Search::showItem($output_type, '', $item_num, $row_num);
                        }
                        echo Search::showEndLine($output_type);
                    }
                }
            }
            if ($output_type == Search::HTML_OUTPUT) {
                Html::closeForm();
            }
            // Display footer
            echo Search::showFooter(
                $output_type,
                __('Entities portal', 'manageentities') . " - " . __('General follow-up', 'manageentities')
            );
        } else {
            echo Search::showError($output_type);
        }
    }


    /**
     * Print generic new line
     *
     * @param $type         display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
     * @param $odd          is it a new odd line ? (false by default)
     * @param $is_deleted   is it a deleted search ? (false by default)
     * @param $color        color the line with this one
     *
     * @return string to display
     **/
    static function showNewLine($type, $odd = false, $is_deleted = false, $color = "")
    {
        $out = "";
        switch ($type) {
            case Search::PDF_OUTPUT_LANDSCAPE : //pdf
            case Search::PDF_OUTPUT_PORTRAIT :
                global $PDF_TABLE;
                $style = "";
                if ($odd) {
                    $style = " style=\"background-color:#DDDDDD;\" ";
                }
                $PDF_TABLE .= "<tr $style nobr=\"true\">";
                break;

            case Search::CSV_OUTPUT : //csv
                break;

            default :

                if ($color != "") {
                    $class = " style='background-color:" . $color . "' ";
                } else {
                    $class = " class='tab_bg_1' ";
                    if ($odd) {
                        $class = " class='tab_bg_2' ";
                    }
                }
                $out = "<tr $class >";
        }
        return $out;
    }

    static function showLegendary()
    {
        $contractstate = new ContractState();
        $contracts = $contractstate->find();
        $nb = count($contracts);
        echo "<div class='center'>";
        echo "<table class='tab_cadre'><tr><th colspan='20'>" . __('Caption') . "</th></tr>";
        $i = 0;
        foreach ($contracts as $contract) {
            if ($i == 10) {
                echo "</tr><tr>";
            }
            echo "<td width=10px style='background-color:" . $contract['color'] . "'> </td>";
            echo "<td> " . $contract['name'] . "</td>";
            $i = $i + 1;
        }
        echo "</tr></table></br>";
        echo "</div>";
    }

    function showCriteriasForm($options = [])
    {
        global $DB;
        Entity::showManageentitiesHeader(__('General follow-up', 'manageentities'));

        if (Session::getCurrentInterface() == 'central') {
            $rand = mt_rand();

            echo "<form method='post' name='criterias_form$rand' id='criterias_form$rand'
               action=\"./entity.php\">";

            echo "<div align='spaced'><table class='tab_cadre_fixe'>";

            echo "<tr class='tab_bg_1'>";
            if ((isset($_SESSION['glpiactive_entity_recursive'])
                    && $_SESSION['glpiactive_entity_recursive'])
                || (isset($_SESSION['glpishowallentities'])
                    && $_SESSION['glpishowallentities'])) {
                echo "<td>" . __('Entity') . "</td>";
                echo "<td>";
                \Dropdown::show('Entity', ['value' => $options['entities_id']]);
                echo "</td>";
                $colspan = '1';
            } else {
                $colspan = '2';
                echo Html::hidden('entities_id', ['value' => -1]);
            }

            $plugin_config = new Config();
            $config_states = $plugin_config->find();
            $config_states = reset($config_states);

            $plugin_pref = new Preference();
            $preferences = $plugin_pref->find(['users_id' => Session::getLoginUserID()]);
            $preferences = reset($preferences);

            $contractstate = new ContractState();
            $contractstates = $contractstate->find();
            $states = [];
            foreach ($contractstates as $key => $val) {
                $states[$key] = $val['name'];
            }
            echo "<td class='left' colspan='$colspan'>" . _n(
                    'State of contract',
                    'States of contract',
                    2,
                    'manageentities'
                ) . "</td>";
            echo "<td class='left' colspan='$colspan'>";

            if (isset($options['contract_states']) && $options['contract_states'] != '0') {
                \Dropdown::showFromArray("contract_states", $states, [
                    'multiple' => true,
                    'width' => 200,
                    'values' => $options['contract_states']
                ]);
            } elseif (isset($preferences['contract_states']) && $preferences['contract_states'] != null) {
                $options['contract_states'] = json_decode($preferences['contract_states'], true);
                \Dropdown::showFromArray("contract_states", $states, [
                    'multiple' => true,
                    'width' => 200,
                    'values' => $options['contract_states']
                ]);
            } elseif (isset($config_states['contract_states']) && $config_states['contract_states'] != null) {
                $options['contract_states'] = json_decode($config_states['contract_states'], true);
                \Dropdown::showFromArray("contract_states", $states, [
                    'multiple' => true,
                    'width' => 200,
                    'values' => $options['contract_states']
                ]);
            } else {
                \Dropdown::showFromArray("contract_states", $states, [
                    'multiple' => true,
                    'width' => 200,
                    'value' => "contract_states"
                ]);
            }
            echo "</td></tr><tr class='tab_bg_1'>";

            echo "<td class='left'>" . __('Begin date') . " " .
                __('of period of contract', 'manageentities') . ", " . __('after') . "</td>";
            echo "<td class='left'>";
            Html::showDateField("begin_date_after", ['value' => $options['begin_date_after']]);
            echo "</td>";
            echo "<td class='left'>" . __('Begin date') . " " .
                __('of period of contract', 'manageentities') . ", " . __('before') . "</td>";
            echo "<td class='left'>";
            Html::showDateField("begin_date_before", ['value' => $options['begin_date_before']]);
            echo "</td>";
            echo "</tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td class='left'>" . __('End date') . " " .
                __('of period of contract', 'manageentities') . ", " . __('after') . "</td>";
            echo "<td class='left'>";
            Html::showDateField("end_date_after", ['value' => $options['end_date_after']]);
            echo "</td>";
            echo "<td class='left'>" . __('End date') . " " .
                __('of period of contract', 'manageentities') . ", " . __('before') . "</td>";
            echo "<td class='left'>";
            Html::showDateField("end_date_before", ['value' => $options['end_date_before']]);
            echo "</td>";
            echo "</tr>";


            $iterator_use = $DB->request([
                'SELECT' => [
                    'glpi_users.*',
                    'glpi_plugin_manageentities_businesscontacts.id AS users_id',
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

            if (count($iterator_use) > 0) {
                foreach ($iterator_use as $data_use) {
                    $users[$data_use['id']] = $data_use['realname'] . " " . $data_use['firstname'];
                }
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td class='left'>";
            echo __('Business', 'manageentities');
            echo "</td>";
            echo "<td class='left'>";

            if ($options['business_id'] == 0) {
                $options['business_id'] = [];
            }
            if ($preferences['business_id'] == 0) {
                $preferences['business_id'] = [];
            }
            if ($config_states['business_id'] == 0) {
                $config_states['business_id'] = [];
            }
//            if (isset($options['business_id']) && $options['business_id'] != '0') {
//                Dropdown::showFromArray("business_id", $users, [
//                    'multiple' => true,
//                    'width' => 200,
//                    'values' => $options['business_id']
//                ]);
//            } elseif (isset($preferences['business_id']) && $preferences['business_id'] != null) {
//                $options['business_id'] = json_decode($preferences['business_id'], true);
//                Dropdown::showFromArray("business_id", $users, [
//                    'multiple' => true,
//                    'width' => 200,
//                    'values' => $options['business_id']
//                ]);
//            } elseif (isset($config_states['business_id']) && $config_states['business_id'] != null) {
//                $options['business_id'] = json_decode($config_states['business_id'], true);
//                Dropdown::showFromArray("business_id", $users, [
//                    'multiple' => true,
//                    'width' => 200,
//                    'values' => $options['business_id']
//                ]);
//            } else {
//                Dropdown::showFromArray("business_id", $users, [
//                    'multiple' => true,
//                    'width' => 200,
//                    'value' => 'name'
//                ]);
//            }

            $plugin_company = new Company();
            $result = $plugin_company->find();

            $company = [];
            foreach ($result as $data) {
                $company[$data['id']] = $data['name'];
            }
            echo "</td>";
            echo "<td class='left'>";
            echo _n('Company', 'Companies', 2, 'manageentities');
            echo "</td>";
            echo "<td class='left'>";

            if (isset($options['company_id']) && $options['company_id'] != '0') {
                \Dropdown::showFromArray("company_id", $company, [
                    'multiple' => true,
                    'width' => 200,
                    'values' => $options['company_id']
                ]);
            } elseif (isset($preferences['companies_id']) && $preferences['companies_id'] != null) {
                $options['company_id'] = json_decode($preferences['companies_id'], true);
                \Dropdown::showFromArray("company_id", $company, [
                    'multiple' => true,
                    'width' => 200,
                    'values' => $options['company_id']
                ]);
            } else {
                \Dropdown::showFromArray("company_id", $company, [
                    'multiple' => true,
                    'width' => 200,
                    'value' => 'name'
                ]);
            }
            echo "</td></tr>";

            echo "<tr class='tab_bg_1'>";
            echo "<td class='center' colspan='4'>";
            echo Html::submit(_sx('button', 'Search'), ['name' => 'searchcontract', 'class' => 'btn btn-primary']);
            echo Html::hidden('begin_date', ['value' => $options['begin_date']]);
            echo Html::hidden('end_date', ['value' => $options['end_date']]);
            echo "</td></tr>";


            echo "</table></div>";

            Html::closeForm();
        }
    }

    static function printPager(
        $start,
        $numrows,
        $target,
        $parameters,
        $item_type_output = 0,
        $item_type_output_param = 0
    ) {
        global $CFG_GLPI;

        $list_limit = $_SESSION['glpilist_limit'];
        // Forward is the next step forward
        $forward = $start + $list_limit;

        // This is the end, my friend
        $end = $numrows - $list_limit;

        // Human readable count starts here
        $current_start = $start + 1;

        // And the human is viewing from start to end
        $current_end = $current_start + $list_limit - 1;
        if ($current_end > $numrows) {
            $current_end = $numrows;
        }

        // Backward browsing
        if ($current_start - $list_limit <= 0) {
            $back = 0;
        } else {
            $back = $start - $list_limit;
        }

        // Print it

        echo "<form method='GET' action=\"" . $CFG_GLPI["root_doc"] .
            "/front/report.dynamic.php\" target='_blank'>\n";

        echo "<table class='tab_cadre_pager'>\n";
        echo "<tr>\n";

        if (Session::getCurrentInterface()
            && Session::getCurrentInterface()) {
            echo "<td class='tab_bg_2' width='30%'>";

            echo Html::hidden('item_type', ['value' => $item_type_output]);
            if ($item_type_output_param != 0) {
                echo Html::hidden('item_type_param', ['value' => serialize($item_type_output_param)]);
            }

            $explode = explode("&amp;", $parameters);
            for ($i = 0; $i < count($explode); $i++) {
                $pos = strpos($explode[$i], '=');
                $name = substr($explode[$i], 0, $pos);
                echo Html::hidden($name, ['value' => substr($explode[$i], $pos + 1)]);
            }
            echo "<select class='form-select' name='display_type'>";
            echo "<option value='" . Search::PDF_OUTPUT_LANDSCAPE . "'>" . __(
                    'Current page in landscape PDF'
                ) . "</option>";
            echo "<option value='" . Search::PDF_OUTPUT_PORTRAIT . "'>" . __(
                    'Current page in portrait PDF'
                ) . "</option>";
            echo "<option value='" . Search::CSV_OUTPUT . "'>" . __('Current page in CSV') . "</option>";
            echo "</select>&nbsp;";
            echo Html::submit(_sx('button', 'Export'), ['name' => 'export', 'class' => 'btn btn-primary']);
            echo "</td>";
        }

        // End pager
        echo "</tr>\n";
        echo "</table><br>\n";
    }
}
