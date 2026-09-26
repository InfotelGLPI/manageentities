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
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Search\Output\HTMLSearchOutput;
use Glpi\Search\SearchEngine;
use Html;
use Search;
use Session;
use Toolbox;

class Followup extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('General follow-up', 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-vocabulary";
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [READ, CREATE, UPDATE, DELETE]);
    }

    /**
     * The upper bound of a "before" date criterion, shifted one day forward.
     *
     * The two criteria below used to reach the database as
     * QueryExpression("ADDDATE('" . $value . "', INTERVAL 1 DAY)"), and a QueryExpression is
     * handed to the engine verbatim: whatever is placed in that string becomes SQL syntax. The
     * value arrives unfiltered from $_POST through front/entity.php and from $_GET through
     * Entity::displayTabContentForItem(). The shift is therefore computed in PHP and the result
     * goes through the query builder as a plain value, which parameterises it. An unparsable date
     * returns null and the criterion is dropped rather than silently widened.
     *
     * @param string $date
     *
     * @return string|null
     */
    private static function shiftOneDayForward(string $date): ?string
    {
        $date = trim($date);
        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTime::createFromFormat($format, $date);
            if ($parsed !== false && $parsed->format($format) === $date) {
                return $parsed->modify('+1 day')->format($format);
            }
        }

        return null;
    }

    /**
     * Ids a criterion of the follow-up form was submitted with.
     *
     * front/entity.php passes -1 for a criterion the form never posted, while a cleared
     * multiple select posts the empty value of its hidden input (and the export links carry 0).
     *
     * @param array  $options criteria of the report
     * @param string $key     name of the criterion
     *
     * @return int[]|null null when not submitted, [] when submitted empty
     */
    private static function getSubmittedIds(array $options, string $key): ?array
    {
        if (!array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if (!is_array($value) && (int) $value < 0) {
            return null;
        }

        return array_values(array_filter(
            array_map('intval', (array) $value),
            static fn(int $id): bool => $id > 0,
        ));
    }

    /**
     * @param string|null $json list of ids stored by the configuration or the preferences
     *
     * @return int[]
     */
    private static function decodeIds(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $decoded),
            static fn(int $id): bool => $id > 0,
        ));
    }

    public static function queryFollowUp($instID, $options = [])
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
        $config_states = reset($config_states) ?: [];

        $plugin_pref = new Preference();
        $preferences = $plugin_pref->find(['users_id' => Session::getLoginUserID()]);
        $preferences = reset($preferences) ?: [];

        $is_helpdesk = Session::getCurrentInterface() === 'helpdesk';

        // Contract states and business contacts: the selection of the form wins, and an
        // emptied one means no filter at all. It used to fall back to the defaults, since the
        // empty value the core posts for a cleared multiple select is no array, so clearing the
        // field could never list everything. The defaults only apply when the criterion was not
        // submitted - the preference then the configuration on the central side, the
        // configuration alone on the simplified interface, which may not widen it.
        $contract_states = self::getSubmittedIds($options, 'contract_states');
        if ($contract_states === null || ($contract_states === [] && $is_helpdesk)) {
            $contract_states = $is_helpdesk
                ? self::decodeIds($config_states['contract_states'] ?? null)
                : (self::decodeIds($preferences['contract_states'] ?? null)
                    ?: self::decodeIds($config_states['contract_states'] ?? null));
        }

        $business_ids = self::getSubmittedIds($options, 'business_id');
        if ($business_ids === null || ($business_ids === [] && $is_helpdesk)) {
            $business_ids = $is_helpdesk
                ? self::decodeIds($config_states['business_id'] ?? null)
                : (self::decodeIds($preferences['business_id'] ?? null)
                    ?: self::decodeIds($config_states['business_id'] ?? null));
        }

        // Companies only have a personal default
        $company_ids = self::getSubmittedIds($options, 'company_id')
            ?? self::decodeIds($preferences['companies_id'] ?? null);
        $company_entities = [];
        foreach ($company_ids as $company_id) {
            $company = new Company();
            if (!$company->getFromDB($company_id)) {
                continue;
            }
            if ($company->fields['is_recursive']) {
                $company_entities = array_merge(
                    $company_entities,
                    array_values($dbu->getSonsOf('glpi_entities', $company->fields['entities_id'])),
                );
            } else {
                $company_entities[] = (int) $company->fields['entities_id'];
            }
        }

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
            'glpi_entities',
        );

        if (isset($options['entities_id']) && $options['entities_id'] != '-1') {
            $sons = $dbu->getSonsOf('glpi_entities', $options['entities_id']);
            $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_contracts.entities_id' => $sons];
        } else {
            if (Session::getCurrentInterface() == 'central') {
                $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
                    'glpi_contracts',
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
                        ['glpi_plugin_manageentities_contracts.contract_type AS contract_type'],
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
                                    'glpi_plugin_manageentities_contractdays' => 'contracts_id',
                                ],
                            ],
                            'glpi_plugin_manageentities_contractstates' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                                    'glpi_plugin_manageentities_contractstates' => 'id',
                                ],
                            ],
                            'glpi_plugin_manageentities_businesscontacts' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'entities_id',
                                    'glpi_plugin_manageentities_businesscontacts' => 'entities_id',
                                ],
                            ],
                            'glpi_entities' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_contractdays' => 'entities_id',
                                    'glpi_entities' => 'id',
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

                    if ($config->fields['hourorday'] == Config::DAY) {// Daily
                        $criteriad['SELECT'] = array_merge(
                            $criteriad['SELECT'],
                            ['glpi_plugin_manageentities_contractdays.contract_type AS contract_type'],
                        );
                        $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_contractdays.contract_type' => $types_contracts];
                    }

                    if ($contract_states !== []) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => $contract_states,
                        ];
                    }
                    if ($business_ids !== []) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_businesscontacts.users_id' => $business_ids,
                        ];
                    }
                    // Only the entities of the last company used to be kept, each company
                    // overwrote the list of the previous one
                    if ($company_ids !== []) {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_entities.id' => $company_entities !== [] ? array_values(array_unique($company_entities)) : [-1],
                        ];
                    }

                    //$beginDateAfter $beginDateBefore $endDateAfter $endDateBefore
                    if (isset($options['begin_date_after']) && $options['begin_date_after'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_contractdays.begin_date' => [
                                '>=',
                                $options['begin_date_after'],
                            ],
                        ];
                        $beginDate = $options['begin_date_after'];
                    }

                    if (isset($options['begin_date_before']) && $options['begin_date_before'] != '') {
                        $begin_before = self::shiftOneDayForward((string) $options['begin_date_before']);
                        if ($begin_before !== null) {
                            $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.begin_date'
                                => [
                                    '<=',
                                    $begin_before,
                                ],
                            ];
                        }
                    }

                    if (isset($options['end_date_after']) && $options['end_date_after'] != '') {
                        $criteriad['WHERE'] = $criteriad['WHERE'] + [
                            'glpi_plugin_manageentities_contractdays.end_date' => [
                                '>=',
                                $options['end_date_after'],
                            ],
                        ];
                    }

                    if (isset($options['end_date_before']) && $options['end_date_before'] != '') {
                        $end_before = self::shiftOneDayForward((string) $options['end_date_before']);
                        if ($end_before !== null) {
                            $criteriad['WHERE'] = $criteriad['WHERE'] + [
                                'glpi_plugin_manageentities_contractdays.end_date'
                                => [
                                    '<=',
                                    $end_before,
                                ],
                            ];
                        }
                        $endDate = $options['end_date_before'];
                    }

                    $iteratord = $DB->request($criteriad);

                    $nbContractDay = count($iteratord);
                    if ($nbContractDay > 0) {
                        $nbContratByEntities++;
                        $contract_reste = 0;

                        // Raw values only: the report template builds the links and escapes them
                        $list[$num]['entities_name'] = $dataEntity['entities_name'];
                        $list[$num]['entities_id'] = $dataEntity['entities_id'];
                        $list[$num]['name'] = $dataContract["name"] == null
                            ? "(" . $dataContract["contracts_id"] . ")"
                            : $dataContract["name"];
                        $list[$num]['contract_num'] = $dataContract['num'];
                        $list[$num]['management'] = Contract::getContractManagement(
                            $dataContract['management'],
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
                            if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
                                $dataContractDay["contract_type"] = $dataContract["contract_type"];
                            }

                            if ($dataContractDay["name_contractdays"] == null) {
                                $nameperiod = "(" . $dataContractDay["contractdays_id"] . ")";
                            } else {
                                $nameperiod = $dataContractDay["name_contractdays"];
                            }

                            // We get all cri details
                            $dataContractDay['values_begin_date'] = $beginDate;
                            $dataContractDay['values_end_date'] = $endDate;
                            $dataContractDay['contracts_id'] = $dataContract['contracts_id'];
                            $dataContractDay['entities_id'] = $dataContract['entities_id'];

                            $resultCriDetail = CriDetail::getCriDetailData(
                                $dataContractDay,
                                ["contract_type_id" => $dataContractDay["contract_type"]],
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
                                    $dataContractDay["contract_type"],
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
                                        'glpi_tickets.is_deleted' => 0,
                                    ],
                                ];

                                if (!empty($dataContractDay['begin_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                        'date' => [
                                            '>=',
                                            $dataContractDay['begin_date'],
                                        ],
                                    ];
                                }

                                if (!empty($dataContractDay['end_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                        'date' => [
                                            '<=',
                                            $dataContractDay['end_date'],
                                        ],
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
                                                'glpi_tickets' => 'id',
                                            ],
                                        ],
                                    ],
                                    'WHERE' => [
                                        'glpi_tickets.entities_id' => $dataEntity['entities_id'],
                                        'glpi_tickets.is_deleted' => 0,
                                    ],
                                    'ORDERBY' => 'glpi_plugin_manageentities_cridetails.date DESC',
                                    'LIMIT' => 1,
                                ];

                                if (!empty($dataContractDay['begin_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                        'glpi_plugin_manageentities_cridetails.date' => [
                                            '>=',
                                            $dataContractDay['begin_date'],
                                        ],
                                    ];
                                }

                                if (!empty($dataContractDay['end_date'])) {
                                    $criteria_tik['WHERE'] = $criteria_tik['WHERE'] + [
                                        'glpi_plugin_manageentities_cridetails.date' => [
                                            '<=',
                                            $dataContractDay['end_date'],
                                        ],
                                    ];
                                }
                            }


                            $iterator_tik = $DB->request($criteria_tik);
                            $date = null;
                            foreach ($iterator_tik as $dataTicket) {
                                $date = Html::convDate($dataTicket['date']);
                            }

                            $color = null;
                            $iterator_col = $DB->request([
                                'SELECT' => [
                                    'color',
                                ],
                                'FROM' => 'glpi_plugin_manageentities_contractstates',
                                'WHERE' => [
                                    'id' => $dataContractDay['contractstates_id'],
                                ],
                            ]);

                            if (count($iterator_col) > 0) {
                                foreach ($iterator_col as $data_col) {
                                    $color = $data_col['color'];
                                }
                            }

                            $list[$num]['days'][$i]['contract_is_closed'] = $dataContractDay['is_closed'];
                            $list[$num]['days'][$i]['contractdayname'] = $nameperiod;
                            $list[$num]['days'][$i]['contractstates'] = \Dropdown::getDropdownName(
                                'glpi_plugin_manageentities_contractstates',
                                $dataContractDay['contractstates_id'],
                            );
                            if (isset($color)) {
                                $list[$num]['days'][$i]['contractstates_color'] = $color;
                            }
                            $list[$num]['days'][$i]['begin_date'] = Html::convDate($dataContractDay['begin_date']);
                            $list[$num]['days'][$i]['end_date'] = Html::convDate($dataContractDay['end_date']);
                            $list[$num]['days'][$i]['credit'] = $credit;
                            $list[$num]['days'][$i]['conso'] = $conso;
                            $list[$num]['days'][$i]['reste'] = $resultCriDetail['resultOther']['reste'];
                            $list[$num]['days'][$i]['depass'] = $resultCriDetail['resultOther']['depass'];
                            $list[$num]['days'][$i]['price'] = $pricecri;
                            $list[$num]['days'][$i]['forfait'] = Html::formatNumber($forfait);
                            $list[$num]['days'][$i]['reste_montant'] = Html::formatNumber(
                                $resultCriDetail['resultOther']['reste_montant'],
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

    /**
     * General follow-up report.
     *
     * The HTML output is rendered by followup_report.html.twig from the same cells the CSV and
     * PDF exports are fed with, so every label - contract, period, entity and contract state
     * names - reaches the page through Twig auto-escaping. It used to be concatenated from
     * HTMLSearchOutput::showItem(), which writes its argument into the cell as is, and the
     * contract state label was the one value left unescaped (stored XSS).
     *
     * @param array $values criteria of the report
     *
     * @return void
     */
    public static function showFollowUp($values)
    {
        $results = self::queryFollowUp($_SESSION["glpiactive_entity"], $values);
        unset($results['tot']);

        $itemtype = Contract::class;
        // Set display type for export if defined
        $output_type    = $values["display_type"] ?? Search::HTML_OUTPUT;
        $output         = SearchEngine::getOutputForLegacyKey($output_type);
        $is_html_output = $output instanceof HTMLSearchOutput;

        $config     = Config::getInstance();
        $is_central = Session::getCurrentInterface() == 'central';
        $is_hour    = $config->fields['hourorday'] == Config::HOUR;
        $use_price  = $config->fields['useprice'] == Config::PRICE;

        if ($results === []) {
            echo Search::showError($output_type);
            return;
        }

        // The export links replay the criteria as submitted. A criterion emptied in the form is
        // carried as 0 so that the export lists everything as well, one never submitted is left
        // out so that the export applies the same defaults as the page.
        $criteria_parameters = '';
        foreach (['contract_states', 'business_id', 'company_id'] as $criterion) {
            $ids = self::getSubmittedIds($values, $criterion);
            if ($ids === null) {
                continue;
            }
            if ($ids === []) {
                $criteria_parameters .= "&amp;$criterion=0";
            }
            foreach ($ids as $key => $id) {
                $criteria_parameters .= "&amp;{$criterion}[$key]=$id";
            }
        }
        $parameters = "begin_date_after=" . $values['begin_date_after'] . "&amp;begin_date_before="
            . $values['begin_date_before'] . "&amp;end_date_after=" . $values['end_date_after']
            . "&amp;end_date_before=" . $values['end_date_before']
            . "&amp;entities_id=" . $values['entities_id'] . $criteria_parameters;

        // Columns of the periods. They depend on the interface and on the configuration only,
        // so every contract shares them. The helpdesk used to replace "Total remaining" by two
        // blank columns for the unlimited hourly contracts, shifting that contract's cells
        // against the others.
        $headers = [
            _n('Period of contract', 'Periods of contract', 1, 'manageentities'),
            ContractState::getTypeName(1),
        ];
        if (!$is_hour) {
            $headers[] = __('Type of contract', 'manageentities');
        }
        $headers[] = __('End date');
        $headers[] = __('Initial credit', 'manageentities');
        $headers[] = __('Total consummated', 'manageentities');
        $headers[] = __('Total remaining', 'manageentities');
        if ($is_central) {
            $headers[] = __('Total exceeding', 'manageentities');
            $headers[] = __('Last visit', 'manageentities');
            if ($use_price) {
                $headers[] = __('Guaranteed package', 'manageentities');
                $headers[] = __('Remaining total (amount)', 'manageentities');
            }
        }

        $contract_url    = Toolbox::getItemTypeFormURL(\Contract::class);
        $contractday_url = Toolbox::getItemTypeFormURL(ContractDay::class);

        $sections  = [];
        $entity_id = null;
        foreach ($results as $contract) {

            $details = [
                ['label' => _x('phone', 'Number'), 'value' => (string) $contract['contract_num']],
            ];
            if ($is_central) {
                if (!$is_hour) {
                    $details[] = ['label' => __('Contract present', 'manageentities'), 'value' => (string) $contract['contract_added']];
                }
                $details[] = ['label' => __('Date of signature', 'manageentities'), 'value' => (string) $contract['date_signature']];
                $details[] = ['label' => __('Date of renewal', 'manageentities'), 'value' => (string) ($contract['date_renewal'] ?? '')];
                if ($is_hour) {
                    $details[] = ['label' => __('Mode of management', 'manageentities'), 'value' => (string) $contract['management']];
                    $details[] = [
                        'label' => __('Type of service contract', 'manageentities'),
                        'value' => (string) Contract::getContractType($contract['contract_type']),
                    ];
                }
            }

            // Unlimited hourly contracts have no remaining credit to show on the helpdesk
            $hide_remaining = !$is_central && $is_hour
                && $contract['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED;

            $rows = [];
            foreach ($contract['days'] as $day) {
                $cells = [
                    self::buildCell(
                        $day['contractdayname'],
                        $is_central ? $contractday_url . '?id=' . (int) $day['contractdays_id'] . '&showFromPlugin=1' : null,
                    ),
                    self::buildCell($day['contractstates']),
                ];
                if (!$is_hour) {
                    $cells[] = self::buildCell(Contract::getContractType($day['contract_type']));
                }
                $cells[] = self::buildCell($day['end_date']);

                // Initial credit
                if ((!$is_central && !$is_hour && $day['contract_type'] == Contract::CONTRACT_TYPE_FORFAIT)
                    || ($is_hour && $day['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED)) {
                    $cells[] = self::buildCell(\Dropdown::EMPTY_VALUE);
                } else {
                    $cells[] = self::buildCell(Html::formatNumber($day['credit'], false, 2));
                }

                // Consumed: the helpdesk never sees more than the credit of an hourly contract
                if ($is_central || (!$is_hour && $day['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                    if (!$is_central && $is_hour
                        && $day['contract_type'] != Contract::CONTRACT_TYPE_UNLIMITED
                        && $day['conso'] > $day['credit']) {
                        $cells[] = self::buildCell(Html::formatNumber($day['credit'], false, 2));
                    } else {
                        $cells[] = self::buildCell(Html::formatNumber($day['conso'], false, 2));
                    }
                } else {
                    $cells[] = self::buildCell(\Dropdown::EMPTY_VALUE);
                }

                // Remaining
                if ($hide_remaining) {
                    $cells[] = self::buildCell('');
                } elseif ($is_central || $day['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT) {
                    $cells[] = self::buildCell(Html::formatNumber($day['reste'], false, 2));
                } else {
                    $cells[] = self::buildCell(\Dropdown::EMPTY_VALUE);
                }

                if ($is_central) {
                    $cells[] = self::buildCell(Html::formatNumber($day['depass'], false, 2));
                    $cells[] = self::buildCell($day['last_visit'] ?? '');
                    if ($use_price) {
                        $cells[] = self::buildCell($day['forfait']);
                        $cells[] = self::buildCell($day['reste_montant']);
                    }
                }

                $color = (string) ($day['contractstates_color'] ?? '');
                $rows[] = [
                    'color' => $color !== '' ? self::sanitizeStateColor($color) : '',
                    'cells' => $cells,
                ];
            }

            // The client heading is only repeated when the entity changes, on the central side
            $entity_name = null;
            if ($is_central && $entity_id !== $contract['entities_id']) {
                $entity_name = (string) $contract['entities_name'];
            }
            $entity_id = $contract['entities_id'];

            $sections[] = [
                'entities_name' => $entity_name,
                'export_entity' => (string) $contract['entities_name'],
                'contract'      => [
                    'name'    => (string) $contract['name'],
                    'url'     => $is_central ? $contract_url . '?id=' . (int) $contract['contracts_id'] : null,
                    'num'     => (string) $contract['contract_num'],
                    'details' => $details,
                ],
                'rows'          => $rows,
            ];
        }

        if ($is_html_output) {
            if ($is_central) {
                self::showLegendary();
                self::showExportToolbar($parameters, Followup::class);
            }

            TemplateRenderer::getInstance()->display('@manageentities/followup_report.html.twig', [
                'headers'      => $headers,
                'sections'     => $sections,
                'client_label' => _n('Client', 'Clients', 1, 'manageentities'),
                'client_color' => Monthly::getStyleColor(Monthly::$style[0]),
            ]);
            return;
        }

        // The exports are flat: one line per period, preceded by its client and its contract.
        // They used to receive only the period cells, accumulated from one period to the next
        // of the same contract, and headers that did not match them.
        $export_headers = array_merge(
            [_n('Client', 'Clients', 1, 'manageentities'), __('Contract'), _x('phone', 'Number')],
            $headers,
        );
        $rows = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $values_row = array_merge(
                    [$section['export_entity'], $section['contract']['name'], $section['contract']['num']],
                    array_column($row['cells'], 'value'),
                );
                $current_row = [];
                foreach ($values_row as $colnum => $value) {
                    $current_row[$itemtype . '_' . ($colnum + 1)] = ['displayname' => $value];
                }
                $rows[count($rows) + 1] = $current_row;
            }
        }

        $params = [
            'start' => 0,
            'is_deleted' => 0,
            'as_map' => 0,
            'browse' => 0,
            'unpublished' => 1,
            'criteria' => [],
            'metacriteria' => [],
            'display_type' => 0,
            'hide_controls' => true,
        ];

        $accounts_data = SearchEngine::prepareDataForSearch($itemtype, $params);
        $accounts_data = array_merge($accounts_data, [
            'itemtype' => $itemtype,
            'data' => [
                'totalcount' => count($rows),
                'count' => count($rows),
                'search' => '',
                'cols' => [],
                'rows' => $rows,
            ],
        ]);

        $colid = 0;
        foreach ($export_headers as $header) {
            $accounts_data['data']['cols'][] = [
                'name' => $header,
                'itemtype' => $itemtype,
                'id' => ++$colid,
            ];
        }

        $output->displayData($accounts_data, []);
    }

    /**
     * One cell of the follow-up report, shared by the HTML template and the exports.
     *
     * @param mixed       $value label, escaped by Twig on the HTML side
     * @param string|null $url   target of the link wrapping the label, if any
     *
     * @return array{value: string, url: string|null}
     */
    private static function buildCell($value, ?string $url = null): array
    {
        return [
            'value' => (string) $value,
            'url'   => $url,
        ];
    }

    /**
     * Restrict a contract state colour to something that can only ever be a colour.
     *
     * ContractState.color is free text typed in the dropdown form and it is written into a
     * style attribute twice: on every row of the report and on every swatch of the caption.
     * Escaping the quotes keeps the value inside the attribute but still lets it close the
     * declaration and append its own, so the value itself is checked here and replaced by a
     * transparent background when it is not a plain CSS colour.
     *
     * @param string $color
     *
     * @return string
     */
    public static function sanitizeStateColor(string $color): string
    {
        $color = trim($color);

        $patterns = [
            '/^#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/',
            '/^[A-Za-z]{3,20}$/',
            '/^rgba?[(][0-9.,%\/ ]+[)]$/',
            '/^hsla?[(][0-9.,%\/ adeg]+[)]$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $color) === 1) {
                return $color;
            }
        }

        return 'transparent';
    }

    /**
     * Caption of the report: one swatch per contract state.
     *
     * @return void
     */
    public static function showLegendary()
    {
        $contract_state = new ContractState();

        $entries = [];
        foreach ($contract_state->find() as $state) {
            $entries[] = [
                'name'  => $state['name'],
                'color' => self::sanitizeStateColor((string) $state['color']),
            ];
        }

        TemplateRenderer::getInstance()->display('@manageentities/legend.html.twig', [
            'entries' => $entries,
        ]);
    }

    /**
     * Criteria form of the general follow-up.
     *
     * @param array $options
     *
     * @return void
     */
    public function showCriteriasForm($options = [])
    {
        if (Session::getCurrentInterface() !== 'central') {
            return;
        }

        // The entity selector is only offered when the session actually spans several
        // entities; otherwise the report stays on the active one, which -1 stands for.
        $show_entity = !empty($_SESSION['glpiactive_entity_recursive'])
            || !empty($_SESSION['glpishowallentities']);

        $contract_state = new ContractState();
        $contract_states = [];
        foreach ($contract_state->find() as $key => $state) {
            $contract_states[$key] = $state['name'];
        }

        $plugin_company = new Company();
        $companies = [];
        foreach ($plugin_company->find() as $company) {
            $companies[$company['id']] = $company['name'];
        }

        $plugin_pref = new Preference();
        $preferences = $plugin_pref->find(['users_id' => Session::getLoginUserID()]);
        $preferences = reset($preferences) ?: [];

        $plugin_config = new Config();
        $config_states = $plugin_config->find();
        $config_states = reset($config_states) ?: [];

        // Same rule as the report itself: what the form submitted wins, even emptied - an empty
        // field lists everything - and the defaults only fill a criterion never submitted, the
        // personal preference first, then the plugin configuration (no configuration for the
        // companies).
        $selected_contract_states = self::getSubmittedIds($options, 'contract_states')
            ?? (self::decodeIds($preferences['contract_states'] ?? null)
                ?: self::decodeIds($config_states['contract_states'] ?? null));
        $selected_business = self::getSubmittedIds($options, 'business_id')
            ?? (self::decodeIds($preferences['business_id'] ?? null)
                ?: self::decodeIds($config_states['business_id'] ?? null));
        $selected_companies = self::getSubmittedIds($options, 'company_id')
            ?? self::decodeIds($preferences['companies_id'] ?? null);
        TemplateRenderer::getInstance()->display('@manageentities/followup_criterias.html.twig', [
            'form_name'                => 'criterias_form' . mt_rand(),
            'form_action'              => './entity.php',
            'show_entity'              => $show_entity,
            'entities_id'              => $options['entities_id'],
            'contract_states'          => $contract_states,
            'contract_states_label'    => ContractState::getTypeName(2),
            'selected_contract_states' => $selected_contract_states,
            'companies'                => $companies,
            'selected_companies'       => $selected_companies,
            'business_users'           => BusinessContact::getBusinessUsers(),
            'selected_business'        => $selected_business,
            'begin_date_after'         => $options['begin_date_after'],
            'begin_date_before'        => $options['begin_date_before'],
            'end_date_after'           => $options['end_date_after'],
            'end_date_before'          => $options['end_date_before'],
            'begin_date'               => $options['begin_date'],
            'end_date'                 => $options['end_date'],
        ]);
    }

    /**
     * Export control of the two reports.
     *
     * Used to be printPager(): it carried a $start/$numrows/$target triple that only fed
     * local variables nothing ever read, since the pager navigation itself had already been
     * removed and only the export select was left. The form it opened was never closed, so
     * everything rendered after it -- the report and the forms below -- ended up nested in
     * it. The core exports through links since GLPI 10, so there is no form left to close.
     *
     * @param string $parameters       Query string of the report, joined with "&amp;"
     * @param string $item_type_output Itemtype plugin_manageentities_dynamicReport() dispatches on
     *
     * @return void
     */
    public static function showExportToolbar(string $parameters, string $item_type_output)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        // $parameters is built for href attributes, so its separators are entities: decode
        // once and parse, which also rebuilds the nested keys (company_id[0]) the report
        // reads back. The former explode() on "&amp;" split on the first "=" without ever
        // checking there was one, and emitted an unnamed hidden field for the trailing
        // separator the callers always leave behind.
        $criteria = [];
        parse_str(html_entity_decode($parameters, ENT_QUOTES, 'UTF-8'), $criteria);
        $criteria['item_type'] = $item_type_output;

        TemplateRenderer::getInstance()->display('@manageentities/followup_export_toolbar.html.twig', [
            'rand'       => mt_rand(),
            'export_url' => $CFG_GLPI['root_doc'] . '/front/report.dynamic.php?' . http_build_query($criteria),
        ]);
    }
}
