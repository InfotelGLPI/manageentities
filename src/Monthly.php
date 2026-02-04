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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

use CommonDBTM;
use DbUtils;
use Glpi\DBAL\QueryExpression;
use Glpi\Search\Output\HTMLSearchOutput;
use Glpi\Search\SearchEngine;
use Html;
use Search;
use Session;

use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\Entity;

use Ticket;
use Toolbox;

class Monthly extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 0)
    {
        return __('Monthly follow-up', 'manageentities');
    }

    static function getIcon()
    {
        return "ti ti-calculator";
    }

    // Css styles/class
    static $style = [
        'background-color: #FEC95C;color:#000',
        'text-align:center',
        'background-color: #FA6B6B;',
        'background-color:#FFBA3B'
    ];
    static $class = ['styleItemTitle', 'styleContractTitle'];

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    static function queryMonthly($values = [])
    {
        global $DB;

        $tabResults = [];
        $tot_conso = 0;
        $tot_depass = 0;
        $tot_depass_amount = 0;
        $tot_conso_amount = 0;
        $tot_credit = 0;
        $taskCount = 0;
        $dbu = new DbUtils();
        // We configure the type of contract Hourly or Dayly
        $config = Config::getInstance();

//        $criteria = [
//            'SELECT' => [
//                'glpi_entities.id AS entities_id',
//                'glpi_entities.name AS entities_name',
//            ],
//            'DISTINCT' => true,
//            'FROM' => 'glpi_tickets',
//            'LEFT JOIN' => [
//                'glpi_entities' => [
//                    'ON' => [
//                        'glpi_tickets' => 'entities_id',
//                        'glpi_entities' => 'id'
//                    ]
//                ],
//                'glpi_tickettasks' => [
//                    'ON' => [
//                        'glpi_tickettasks' => 'tickets_id',
//                        'glpi_tickets' => 'id'
//                    ]
//                ]
//            ],
//            'WHERE' => [
//                [
//                    'OR' => [
//                        ['glpi_tickettasks.end' => 'NULL'],
//                        ['glpi_tickettasks.end' => ['>=', $values['begin_date']]]
//                    ],
//                ],
//                [
//                    'OR' => [
//                        ['glpi_tickettasks.begin' => 'NULL'],
//                        [
//                            'glpi_tickettasks.begin' => [
//                                '<=',
//                                new QueryExpression("ADDDATE('" . $values['end_date'] . "', INTERVAL 1 DAY)")
//                            ]
//                        ]
//                    ],
//                ],
//            ],
//            'ORDERBY' => [
//                'glpi_entities.name',
//                'glpi_tickettasks.end ASC'
//            ],
//        ];
//        $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
//                'glpi_entities'
//            );
//
//        $iterator = $DB->request($criteria);
//
//        $nbTotEntity = (count($iterator) > 0 ? count($iterator) : 0);
//
//        if ($nbTotEntity > 0) {
//            foreach ($iterator as $dataEntity) {
        $condition = " " . $dbu->getEntitiesRestrictRequest("", "glpi_entities");

        $queryEntity = "SELECT DISTINCT(`glpi_entities`.`id`) AS entities_id,
                        `glpi_entities`.`name` AS entities_name
                     FROM `glpi_tickets`

                     LEFT JOIN `glpi_entities`
                        ON (`glpi_entities`.`id`
                           = `glpi_tickets`.`entities_id`)

                     LEFT JOIN `glpi_tickettasks`
                        ON (`glpi_tickets`.`id`
                           = `glpi_tickettasks`.`tickets_id`)

                     WHERE $condition

                     AND (`glpi_tickettasks`.`begin` <= ADDDATE('" . $values['end_date'] . "', INTERVAL 1 DAY)
                           OR `glpi_tickettasks`.`begin` IS NULL)

                     AND (`glpi_tickettasks`.`end` >= '" . $values['begin_date'] . "'
                           OR `glpi_tickettasks`.`end` IS NULL)

                     ORDER BY `glpi_entities`.`name`,
                              `glpi_tickettasks`.`end` ASC";

        $resEntity   = $DB->doquery($queryEntity);
        $nbTotEntity = ($resEntity ? $DB->numrows($resEntity) : 0);

        //We get entities datas
        if ($resEntity && $nbTotEntity > 0) {
            while ($dataEntity = $DB->fetchArray($resEntity)) {
                $tabResults[$dataEntity['entities_id']]['entities_name'] = $dataEntity['entities_name'];
                $tabResults[$dataEntity['entities_id']]['entities_id'] = $dataEntity['entities_id'];

//                $criteriad = [
//                    'SELECT' => [
//                        'glpi_plugin_manageentities_contractdays.name AS name_contractdays',
//                        'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id AS contractstates_id',
//                        'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
//                        'glpi_plugin_manageentities_contractdays.plugin_manageentities_critypes_id',
//                        'glpi_plugin_manageentities_contractdays.report AS report',
//                        'glpi_plugin_manageentities_contractdays.nbday AS nbday',
//                        'glpi_plugin_manageentities_contractdays.charged AS charged',
//                        'glpi_plugin_manageentities_contractdays.begin_date AS begin_date',
//                        'glpi_plugin_manageentities_contractdays.end_date AS end_date',
//                        'glpi_plugin_manageentities_contractdays.plugin_manageentities_critypes_id',
//                        'glpi_contracts.name AS name',
//                        'glpi_contracts.id AS contracts_id',
//                        'glpi_contracts.num AS num',
//                        'glpi_contracts.entities_id AS entities_id',
//                        'glpi_plugin_manageentities_contractstates.is_closed AS is_closed',
//                        'glpi_plugin_manageentities_contractstates.color',
//                    ],
//                    'FROM' => 'glpi_plugin_manageentities_contractdays',
//                    'LEFT JOIN' => [
//                        'glpi_contracts' => [
//                            'ON' => [
//                                'glpi_contracts' => 'id',
//                                'glpi_plugin_manageentities_contractdays' => 'contracts_id'
//                            ]
//                        ],
//                        'glpi_plugin_manageentities_contracts' => [
//                            'ON' => [
//                                'glpi_plugin_manageentities_contracts' => 'contracts_id',
//                                'glpi_contracts' => 'id'
//                            ]
//                        ],
//                        'glpi_plugin_manageentities_contractstates' => [
//                            'ON' => [
//                                'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
//                                'glpi_plugin_manageentities_contractstates' => 'id'
//                            ]
//                        ],
//                        'glpi_plugin_manageentities_cridetails' => [
//                            'ON' => [
//                                'glpi_plugin_manageentities_contractdays' => 'id',
//                                'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_contractdays_id'
//                            ]
//                        ],
//                        'glpi_tickets' => [
//                            'ON' => [
//                                'glpi_plugin_manageentities_cridetails' => 'tickets_id',
//                                'glpi_tickets' => 'id'
//                            ]
//                        ],
//                        'glpi_tickettasks' => [
//                            'ON' => [
//                                'glpi_tickettasks' => 'tickets_id',
//                                'glpi_tickets' => 'id'
//                            ]
//                        ]
//                    ],
//                    'WHERE' => [
//                        'glpi_contracts.is_deleted' => 0,
//                        'glpi_plugin_manageentities_contractdays.entities_id' => $dataEntity["entities_id"],
//                        [
//                            'OR' => [
//                                ['glpi_tickettasks.begin' => 'NULL'],
//                                ['glpi_tickettasks.begin' => ['>=', $values['begin_date'] . " 00:00:00"]]
//                            ],
//                        ],
//                        [
//                            'OR' => [
//                                ['glpi_tickettasks.end' => 'NULL'],
//                                ['glpi_tickettasks.end' => ['<=', $values['end_date'] . " 23:59:59"]]
//                            ],
//                        ],
//
//                    ],
//                    'GROUPBY' => 'glpi_plugin_manageentities_contractdays.id',
//                    'ORDERBY' => ['glpi_contracts.name,glpi_plugin_manageentities_contractdays.end_date ASC'],
//                ];
//
//
//                if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
//                    $types_contracts = [
//                        Contract::CONTRACT_TYPE_NULL,
//                        Contract::CONTRACT_TYPE_HOUR,
//                        Contract::CONTRACT_TYPE_INTERVENTION,
//                        Contract::CONTRACT_TYPE_UNLIMITED
//                    ];
//                    $criteriad['SELECT'] = array_merge(
//                        $criteriad['SELECT'],
//                        ['glpi_plugin_manageentities_contracts.contract_type AS contract_type']
//                    );
//                    $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_contracts.contract_type' => $types_contracts];
//                } else {
//                    $types_contracts = [
//                        Contract::CONTRACT_TYPE_NULL,
//                        Contract::CONTRACT_TYPE_AT,
//                        Contract::CONTRACT_TYPE_FORFAIT
//                    ];
//                    $criteriad['SELECT'] = array_merge(
//                        $criteriad['SELECT'],
//                        ['glpi_plugin_manageentities_contractdays.contract_type AS contract_type']
//                    );
//                    $criteriad['WHERE'] = $criteriad['WHERE'] + ['glpi_plugin_manageentities_contractdays.contract_type' => $types_contracts];
//                }
//
//                $iteratord = $DB->request($criteriad);
//
//                $nbContractDay = count($iteratord);
//
////             We get contract days datas
//                if ($nbContractDay > 0) {
//                    foreach ($iteratord as $dataContractDay) {

                if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
                    $configHourOrDay = "AND (`glpi_plugin_manageentities_contracts`.`contract_type`='" . Contract::CONTRACT_TYPE_NULL . "'
                             OR `glpi_plugin_manageentities_contracts`.`contract_type`='" . Contract::CONTRACT_TYPE_HOUR . "'
                             OR `glpi_plugin_manageentities_contracts`.`contract_type`='" . Contract::CONTRACT_TYPE_INTERVENTION . "'
                             OR `glpi_plugin_manageentities_contracts`.`contract_type`='" . Contract::CONTRACT_TYPE_UNLIMITED . "')";
                    // Daily
                } else {
                    $configHourOrDay = "AND (`glpi_plugin_manageentities_contractdays`.`contract_type`='" . Contract::CONTRACT_TYPE_NULL . "'
                             OR `glpi_plugin_manageentities_contractdays`.`contract_type`='" . Contract::CONTRACT_TYPE_AT . "'
                             OR `glpi_plugin_manageentities_contractdays`.`contract_type`='" . Contract::CONTRACT_TYPE_FORFAIT . "')";
                }
                $queryContractDay = "SELECT `glpi_plugin_manageentities_contractdays`.`name`       AS name_contractdays,
                                        `glpi_plugin_manageentities_contractdays`.`id`         AS contractdays_id,
                                        `glpi_plugin_manageentities_contractdays`.`report`     AS report,
                                        `glpi_plugin_manageentities_contractdays`.`nbday`      AS nbday,
                                        `glpi_plugin_manageentities_contractdays`.`begin_date` AS begin_date,
                                        `glpi_plugin_manageentities_contractdays`.`end_date`   AS end_date,
                                        `glpi_plugin_manageentities_contractdays`.`charged`    AS charged,
                                        `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id` AS contractstates_id,
                                        `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_critypes_id`,";

                if ($config->fields['hourorday'] == Config::HOUR) {// Hourly
                    $queryContractDay .= "`glpi_plugin_manageentities_contracts`.`contract_type` AS contract_type,";
                } else {
                    $queryContractDay .= "`glpi_plugin_manageentities_contractdays`.`contract_type` AS contract_type,";
                }

                $queryContractDay .= "`glpi_contracts`.`name` AS name,
                                        `glpi_contracts`.`num`  AS num,
                                        `glpi_contracts`.`id`   AS contracts_id,
                                        `glpi_contracts`.`entities_id` AS entities_id,
                                        `glpi_plugin_manageentities_contractstates`.`is_closed` AS is_closed,
                                        `glpi_plugin_manageentities_contractstates`.`color`

                     FROM `glpi_plugin_manageentities_contractdays`

                     LEFT JOIN `glpi_contracts`
                        ON (`glpi_contracts`.`id`
                        = `glpi_plugin_manageentities_contractdays`.`contracts_id`)

                     LEFT JOIN `glpi_plugin_manageentities_contracts`
                        ON (`glpi_contracts`.`id`
                        = `glpi_plugin_manageentities_contracts`.`contracts_id`)

                     LEFT JOIN `glpi_plugin_manageentities_contractstates`
                        ON (`glpi_plugin_manageentities_contractstates`.`id`
                        = `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id`)

                     LEFT JOIN `glpi_plugin_manageentities_cridetails`
                        ON (`glpi_plugin_manageentities_cridetails`.`plugin_manageentities_contractdays_id` = `glpi_plugin_manageentities_contractdays`.`id`)

                     LEFT JOIN `glpi_tickets`
                        ON (`glpi_plugin_manageentities_cridetails`.`tickets_id` = `glpi_tickets`.`id`)

                     LEFT JOIN `glpi_tickettasks`
                        ON (`glpi_tickettasks`.`tickets_id` = `glpi_tickets`.`id`)

                     WHERE `glpi_plugin_manageentities_contractdays`.`entities_id`='" . $dataEntity['entities_id'] . "'

                     AND `glpi_contracts`.`is_deleted` != 1

                     AND (`glpi_tickettasks`.`begin` >= '" . $values['begin_date'] . " 00:00:00'
                           OR `glpi_tickettasks`.`begin` IS NULL)

                     AND (`glpi_tickettasks`.`end` <= '" . $values['end_date'] . " 23:59:59'
                           OR `glpi_tickettasks`.`end` IS NULL)

                     " . $configHourOrDay . "

                     GROUP BY `glpi_plugin_manageentities_contractdays`.`id`

                     ORDER BY `glpi_contracts`.`name`,
                              `glpi_plugin_manageentities_contractdays`.`end_date` ASC";


                //                                 AND (`glpi_plugin_manageentities_contractdays`.`begin_date` <= ADDDATE('".$values['end_date']."', INTERVAL 1 DAY)
                //                           OR `glpi_plugin_manageentities_contractdays`.`begin_date` IS NULL)
                //
                //                     AND (`glpi_plugin_manageentities_contractdays`.`end_date` >= '".$values['begin_date']."'
                //                           OR `glpi_plugin_manageentities_contractdays`.`end_date` IS NULL)
                $resContractDay   = $DB->doquery($queryContractDay);
                $nbTotContractDay = ($resContractDay ? $DB->numrows($resContractDay) : 0);

                // We get contract days datas
                if ($resContractDay && $nbTotContractDay > 0) {
                    while ($dataContractDay = $DB->fetchAssoc($resContractDay)) {
                        $contract_credit = 0;

                        // We get all cri details
                        $resultCriDetail = CriDetail::getCriDetailData(
                            $dataContractDay,
                            [
                                'contract_type_id' => $dataContractDay['contract_type'],
                                'begin_date' => $values['begin_date'],
                                'end_date' => $values['end_date']
                            ]
                        );

                        $resultCriDetail_beforeMonth = CriDetail::getCriDetailData(
                            $dataContractDay,
                            [
                                'contract_type_id' => $dataContractDay['contract_type'],
                                'end_date' => date('Y-m-d', strtotime($values['begin_date'] . ' - 1 DAY'))
                            ]
                        );

                        $remaining = $lastMonthRemaining = $resultCriDetail_beforeMonth['resultOther']['reste'];

                        if (sizeof($resultCriDetail['result']) > 0) {
                            // Credit
                            $credit = $dataContractDay['nbday'] + $dataContractDay['report'];
                            $contract_credit += $credit;
                            $tot_credit += $credit;

                            // link of contract
                            $link_contract = Toolbox::getItemTypeFormURL("Contract");
                            $name_contract = "<a href='" . $link_contract . "?id=" . $dataContractDay["contracts_id"] . "' target='_blank'>";
                            if ($dataContractDay["num"] == null) {
                                $name_contract .= "(" . $dataContractDay["contracts_id"] . ")";
                            } else {
                                $name_contract .= $dataContractDay["num"];
                            }
                            $name_contract .= "</a>";

                            // Contract day informations
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['name_contract'] = $name_contract;
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['name_contractdays'] = $dataContractDay["name_contractdays"];
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contracts_id'] = $dataContractDay["name_contractdays"];
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['is_closed'] = $dataContractDay["is_closed"];
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['num'] = $dataContractDay["num"];
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contract_type'] = Contract::getContractType(
                                $dataContractDay["contract_type"]
                            );
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['credit'] = $credit;

                            foreach ($resultCriDetail['result'] as $cridetails_id => $dataCriDetail) {
                                $taskCount++;

                                // Conso per tech
                                $conso_per_tech = [];
                                $contract_conso = 0;
                                foreach ($dataCriDetail['conso_per_tech'] as $tickets) {
                                    foreach ($tickets as $users_id => $time) {
                                        $remaining -= $time['conso'];
                                        $depass = 0;


                                        if ($remaining < 0) {
                                            $depass = abs($remaining);
                                            $remaining = 0;
                                        }

                                        $contract_conso += $time['conso'];
                                        $tot_conso += $time['conso'];
                                        $tot_depass += $depass;
                                        $conso_per_tech[$users_id]['conso'] = $time['conso'];
                                        $conso_per_tech[$users_id]['depass'] = $depass;
                                        $conso_per_tech[$users_id]['depass_amount'] = $conso_per_tech[$users_id]['depass'] * $dataCriDetail['pricecri'];
                                        $conso_per_tech[$users_id]['conso_amount'] = $time['conso'] * $dataCriDetail['pricecri'];
                                        $tot_conso_amount += $conso_per_tech[$users_id]['conso_amount'];
                                        $tot_depass_amount += $conso_per_tech[$users_id]['depass_amount'];
                                    }
                                }

                                // Task informations
                                $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']][$cridetails_id]['conso_per_tech'] = $conso_per_tech;
                                $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']][$cridetails_id]['tech'] = $dataCriDetail['tech'];
                                $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']][$cridetails_id]['documents_id'] = $dataCriDetail['documents_id'];
                                $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']][$cridetails_id]['pricecri'] = $dataCriDetail['pricecri'];
                            }

                            // Contract informations
                            $contractdays_state = '';
                            $color = $dataContractDay["color"];
                            if ($dataContractDay['contract_type'] == Contract::CONTRACT_TYPE_AT) {
                                if ($dataContractDay['charged'] == 0) {
                                    $contractdays_state = __('To present an invoice', 'manageentities');
                                } else {
                                    $contractdays_state = __('Already charged', 'manageentities');
                                }
                            } elseif ($dataContractDay['contract_type'] == Contract::CONTRACT_TYPE_FORFAIT) {
                                if ($contract_credit - $contract_conso <= 0) {
                                    if ($dataContractDay['charged'] == 0) {
                                        $contractdays_state = __('To present an invoice', 'manageentities');
                                        if ($dataContractDay["is_closed"]) {
                                            $color = self::$style[3];
                                        }
                                    } else {
                                        $contractdays_state = __('Already charged', 'manageentities');
                                    }
                                } elseif ($dataContractDay["contract_type"] == Contract::CONTRACT_TYPE_FORFAIT && $dataContractDay['charged']) {
                                    $contractdays_state = __('Already charged', 'manageentities');
                                } elseif ($dataContractDay["contract_type"] == Contract::CONTRACT_TYPE_FORFAIT && $dataContractDay["is_closed"]) {
                                    $contractdays_state = __('To present an invoice', 'manageentities');
                                    $color = self::$style[3];
                                } else {
                                    $contractdays_state = __('In progress', 'manageentities');
                                }
                            }

                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contractstates_color'] = $color;
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contract_credit'] = $contract_credit;
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contract_remaining'] = $lastMonthRemaining;
                            $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contractdays_state'] = $contractdays_state;
                        }
                    }
                }
            }
        }

        // Total of all
        if ($taskCount != 0) {
            $tabResults['tot_credit'] = $tot_credit;
            $tabResults['tot_conso'] = $tot_conso;
            $tabResults['tot_depass'] = $tot_depass;
            $tabResults['tot_conso_amount'] = $tot_conso_amount;
            $tabResults['tot_depass_amount'] = $tot_depass_amount;
        }

        return $tabResults;
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
                $class = " class='tab_bg_1' ";
                if ($odd) {
                    $class = " class='tab_bg_2' ";
                }
                $out = "<tr $class >";
        }
        return $out;
    }

    static function showMonthly($values = [])
    {
        global $PDF, $DB;

        $results = self::queryMonthly($values);
        $list = [];

        $PDF = new CriPDF('P', 'mm', 'A4');
        $parameters = "begin_date=" . $values['begin_date'] . "&amp;end_date=" . $values['end_date'];
        $config = Config::getInstance();

        $count_tasks = 0;

        $default_values["start"] = $start = 0;
        $default_values["id"] = $id = 0;
        $default_values["export"] = $export = false;

        foreach ($default_values as $key => $val) {
            if (isset($values[$key])) {
                $$key = $values[$key];
            }
        }
        $itemtype = Contract::class;
        // Set display type for export if define
        $output_type = $values["display_type"] ?? Search::HTML_OUTPUT;
        $output = SearchEngine::getOutputForLegacyKey($output_type);
        $is_html_output = $output instanceof HTMLSearchOutput;
        $html_output = '';

        if (isset($values["display_type"])) {
            $output_type = $values["display_type"];
        }

        $year = date("Y");
        $month = date('m', mktime(12, 0, 0, date("m"), 0, date("Y")));
        $date = $year . "-" . $month . "-01";
        $query = ContractDay::queryOldContractDaywithInterventions($date);
        $iterator = $DB->request($query);
        if (count($iterator) > 0 && $output_type == search::HTML_OUTPUT) {
            echo "<div class = 'alert alert-warning d-flex'>" . __(
                    'Warning : There are supplementary interventions which depends on a prestation with a earlier end date',
                    'manageentities'
                ) . "</div>";
            echo _n('Ticket', 'Tickets', count($iterator));
            echo " : ";
            foreach ($iterator as $data) {
                $ticket = new Ticket();
                $ticket->getFromDB($data["tickets_id"]);
                echo $ticket->getLink() . " (" . $data["tickets_id"] . ")<br>";
            }
        }

        $headers = [];
        $rows = [];

        $i = 0;
        foreach ($results as $dataEntity) {
            if (is_array($dataEntity) && sizeof($dataEntity) > 2) {
                foreach ($dataEntity as $idContractDay => $dataContractDay) {
                    if (is_array($dataContractDay)) {
                        // Display details of contract
                        foreach ($dataContractDay as $dataTask) {
                            if (is_array($dataTask)) {
                                foreach ($dataTask['conso_per_tech'] as $users_id => $conso) {
                                    $list[$i]["entities_name"] = $dataEntity['entities_name'];
                                    $list[$i]["name_contract"] = $dataContractDay['name_contract'];
                                    $list[$i]["name_contractdays"] = $dataContractDay['name_contractdays'];
                                    $list[$i]["contract_type"] = $dataContractDay['contract_type'];
                                    $list[$i]["contract_credit"] = $dataContractDay['contract_credit'];
                                    $list[$i]["contract_remaining"] = $dataContractDay['contract_remaining'];
                                    $list[$i]["pricecri"] = $dataTask['pricecri'];
                                    $list[$i]["conso"] = $conso['conso'];
                                    $list[$i]["users_id"] = $users_id;
                                    $list[$i]["conso_amount"] = $conso['conso_amount'];
                                    $list[$i]["depass"] = $conso['depass'];
                                    $list[$i]["depass_amount"] = $conso['depass_amount'];
                                    $list[$i]["contractdays_state"] = $dataContractDay['contractdays_state'];
                                    $list[$i]["is_closed"] = $dataContractDay['is_closed'];
                                    $list[$i]["contractstates_color"] = $dataContractDay['contractstates_color'];
                                    $i++;
                                }
                            }
                        }
                    }
                }
            }
        }
        $row_num = 0;
        $numrows = count($list);

//        $end_display = $start + $_SESSION['glpilist_limit'];
//        if (isset($_GET['export_all'])) {
            $start = 0;
            $end_display = $numrows;
//        }

        $nbcols = 4;
        if (!$is_html_output) {
            $nbcols--;
        }


        // Show headers
        if ($is_html_output) {
            $html_output .= $output::showHeader($end_display - $start + 1, $nbcols);
        }
        if (!$is_html_output) {
            $headers[] = _n('Client', 'Clients', 1, 'manageentities');
            $headers[] = __('Contract');
            $headers[] = ContractDay::getTypeName(1);
            if ($config->fields['hourorday'] == Config::HOUR) {
                $headers[] = __('Mode of management', 'manageentities');
            } else {
                $headers[] = __('Type of contract', 'manageentities');
            }
            $headers[] = __('Initial credit', 'manageentities');
            $headers[] = __('Remaining on ', 'manageentities') . ' ' . Html::convDate($values['begin_date']);
            if ($config->fields['useprice'] == Config::PRICE) {
                if ($config->fields['hourorday'] == Config::DAY) {
                    $headers[] = __('Daily rate', 'manageentities');
                } else {
                    $headers[] = __('Hourly rate', 'manageentities');
                }
            }
            $headers[] = __('Production', 'manageentities');
            $headers[] = _n('Current skateholder', 'Current stakeholders', 2, 'manageentities');
            if ($config->fields['useprice'] == Config::PRICE) {
                $headers[] = __('Total production', 'manageentities');
            }
            $headers[] = __('Exceeding', 'manageentities');

            if ($config->fields['useprice'] == Config::PRICE) {
                $headers[] = __('Total exceeding', 'manageentities');
            }
            $headers[] = __('State of intervention', 'manageentities');
        } else {
            $header_num = 1;
            $html_output .= $output::showNewLine();
            $html_output .= $output::showHeaderItem(_n('Client', 'Clients', 1, 'manageentities'), $header_num);
            $html_output .= $output::showHeaderItem(__('Contract'), $header_num);
            $html_output .= $output::showHeaderItem(ContractDay::getTypeName(1), $header_num);

            if ($config->fields['hourorday'] == Config::HOUR) {
                $html_output .= $output::showHeaderItem(__('Mode of management', 'manageentities'), $header_num);
            } else {
                $html_output .= $output::showHeaderItem(__('Type of contract', 'manageentities'), $header_num);
            }
            $html_output .= $output::showHeaderItem(__('Initial credit', 'manageentities'), $header_num);
            $html_output .= $output::showHeaderItem(
                __('Remaining on ', 'manageentities') . ' ' . Html::convDate($values['begin_date']),
                $header_num
            );

            if ($config->fields['useprice'] == Config::PRICE) {
                if ($config->fields['hourorday'] == Config::DAY) {
                    $html_output .= $output::showHeaderItem(__('Daily rate', 'manageentities'), $header_num);
                } else {
                    $html_output .= $output::showHeaderItem(__('Hourly rate', 'manageentities'), $header_num);
                }
            }

            $html_output .= $output::showHeaderItem(__('Production', 'manageentities'), $header_num);
            $html_output .= $output::showHeaderItem(
                _n('Current skateholder', 'Current stakeholders', 2, 'manageentities'),
                $header_num
            );

            if ($config->fields['useprice'] == Config::PRICE) {
                $html_output .= $output::showHeaderItem(__('Total production', 'manageentities'), $header_num);
            }
            $html_output .= $output::showHeaderItem(__('Exceeding', 'manageentities'), $header_num);

            if ($config->fields['useprice'] == Config::PRICE) {
                $html_output .= $output::showHeaderItem(__('Total exceeding', 'manageentities'), $header_num);
            }
            $html_output .= $output::showHeaderItem(__('State of intervention', 'manageentities'), $header_num);
            $html_output .= $output::showEndLine();
        }


        if (!empty($list)) {
            for ($i = $start; ($i < $numrows) && ($i < $end_display); $i++) {
                $row_num++;
                $current_row = [];
                $item_num = 1;
                $colnum = 0;
                $firstEntity = true;

                $count_tasks++;

                if ($list[$i]['depass'] > 0) {
                    $depassClass = " style='" . self::$style[2] . "' ";
                } elseif ($list[$i]['contractstates_color'] == self::$style[3]) {
                    $depassClass = " style='" . self::$style[3] . "' ";
                } else {
                    $depassClass = "";
                }

                if ($is_html_output) {
                    $html_output .= self::showNewLine(
                        $output_type,
                        ($i % 2 === 1),
                        $list[$i]['is_closed'],
                        $list[$i]['contractstates_color']
                    );
                }
                // Client
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        $list[$i]['entities_name'],
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => $list[$i]['entities_name']];
                }
                // Contract
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        $list[$i]['name_contract'],
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => $list[$i]['name_contract']];
                }
                //Period
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        $list[$i]['name_contractdays'],
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => $list[$i]['name_contractdays']];
                }
                // Management mode
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        $list[$i]['contract_type'],
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => $list[$i]['contract_type']];
                }
                // Initial credit
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        Html::formatNumber($list[$i]['contract_credit'], 0, 2),
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = [
                        'displayname' => Html::formatNumber(
                            $list[$i]['contract_credit'],
                            0,
                            2
                        )
                    ];
                }
                // Remaining on
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        Html::formatNumber($list[$i]['contract_remaining'], 0, 2),
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = [
                        'displayname' => Html::formatNumber(
                            $list[$i]['contract_remaining'],
                            0,
                            2
                        )
                    ];
                }
                // Price cri
                if ($config->fields['useprice'] == Config::PRICE) {
                    if ($is_html_output) {
                        $html_output .= $output::showItem(
                            Html::formatNumber($list[$i]['pricecri'], 0, 2),
                            $item_num,
                            $row_num,
                            $depassClass
                        );
                    } else {
                        $current_row[$itemtype . '_' . (++$colnum)] = [
                            'displayname' => Html::formatNumber(
                                $list[$i]['pricecri'],
                                0,
                                2
                            )
                        ];
                    }
                }
                // Conso
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        self::checkValue($list[$i]['conso'], $output_type),
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = [
                        'displayname' => self::checkValue(
                            $list[$i]['conso'],
                            $output_type
                        )
                    ];
                }
                // Stakeholder
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        getUserName($list[$i]['users_id']),
                        $item_num,
                        $row_num,
                        $depassClass
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = [
                        'displayname' => getUserName(
                            $list[$i]['users_id']
                        )
                    ];
                }
                // Total conso
                if ($config->fields['useprice'] == Config::PRICE) {
                    if ($is_html_output) {
                        $html_output .= $output::showItem(
                            Html::formatNumber($list[$i]['conso_amount'], 0, 2),
                            $item_num,
                            $row_num,
                            $depassClass
                        );
                    } else {
                        $current_row[$itemtype . '_' . (++$colnum)] = [
                            'displayname' => Html::formatNumber(
                                $list[$i]['conso_amount'],
                                0,
                                2
                            )
                        ];
                    }
                }
                // Depass
                if (!empty($conso['depass'])) {
                    if ($is_html_output) {
                        $html_output .= $output::showItem(
                            self::checkValue($list[$i]['depass'], $output_type),
                            $item_num,
                            $row_num,
                            $depassClass
                        );
                    } else {
                        $current_row[$itemtype . '_' . (++$colnum)] = [
                            'displayname' => self::checkValue(
                                $list[$i]['depass'],
                                $output_type
                            )
                        ];
                    }
                    // Total depass
                    if ($config->fields['useprice'] == Config::PRICE) {
                        if ($is_html_output) {
                            $html_output .= $output::showItem(
                                Html::formatNumber($list[$i]['depass_amount'], 0, 2),
                                $item_num,
                                $row_num,
                                $depassClass
                            );
                        } else {
                            $current_row[$itemtype . '_' . (++$colnum)] = [
                                'displayname' => Html::formatNumber(
                                    $list[$i]['depass_amount'],
                                    0,
                                    2
                                )
                            ];
                        }
                    }
                } else {
                    if ($is_html_output) {
                        $html_output .= $output::showItem('', $item_num, $row_num);
                    } else {
                        $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => ''];
                    }

                    if ($config->fields['useprice'] == Config::PRICE) {
                        if ($is_html_output) {
                            $html_output .= $output::showItem('', $item_num, $row_num);
                        } else {
                            $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => ''];
                        }
                    }
                }
                // Intervention state
                if ($is_html_output) {
                    $html_output .= $output::showItem(
                        $list[$i]['contractdays_state'],
                        $item_num,
                        $row_num
                    );
                } else {
                    $current_row[$itemtype . '_' . (++$colnum)] = ['displayname' => $list[$i]['contractdays_state']];
                }

                $rows[$row_num] = $current_row;
                if ($is_html_output) {
                    $html_output .= $output::showEndLine(false);
                }
            }
        }

        if ($count_tasks) {
            // Total

            if ($is_html_output) {
                $row_num++;
                $num = 0;
                $html_output .= self::showNewLine(
                    $output_type,
                    ($i % 2 === 1),
                );

                $html_output .= $output::showItem(
                    __('Total'),
                    $item_num,
                    $row_num,
                    "style='" . Monthly::$style[1] . "'"
                );

                for ($i = 0; $i < 6; $i++) {
                    $html_output .= $output::showItem(
                        '',
                        $item_num,
                        $row_num,
                        "style='" . Monthly::$style[1] . "'"
                    );
                }

                if ($list) {
                    $html_output .= $output::showItem(
                        Html::formatNumber($results['tot_conso'], 0, 2),
                        $item_num,
                        $row_num,
                        "style='" . Monthly::$style[1] . "'"
                    );

                    $html_output .= $output::showItem(
                        '',
                        $item_num,
                        $row_num,
                        "style='" . Monthly::$style[1] . "'"
                    );

                    if ($config->fields['useprice'] == Config::PRICE) {
                        $html_output .= $output::showItem(
                            Html::formatNumber($results['tot_conso_amount'], 0, 2),
                            $item_num,
                            $row_num,
                            "style='" . Monthly::$style[1] . "'"
                        );
                    }

                    $html_output .= $output::showItem(
                        Html::formatNumber($results['tot_depass'], 0, 2),
                        $item_num,
                        $row_num,
                        "style='" . Monthly::$style[1] . "'"
                    );


                    if ($config->fields['useprice'] == Config::PRICE) {
                        $html_output .= $output::showItem(
                            Html::formatNumber($results['tot_depass_amount'], 0, 2),
                            $item_num,
                            $row_num,
                            "style='" . Monthly::$style[1] . "'"
                        );
                    }

                    $html_output .= $output::showItem(
                        '',
                        $item_num,
                        $row_num,
                        "style='" . Monthly::$style[1] . "'"
                    );


                    if ($is_html_output) {
                        Html::closeForm();
                        $output::showFooter(
                            __('Entities portal', 'manageentities') . " - " . __('Monthly follow-up', 'manageentities'),
                            $numrows
                        );
                    }
                }
            }
            if ($is_html_output) {
                Followup::printPager(
                    $start,
                    $numrows,
                    $_SERVER['PHP_SELF'],
                    $parameters,
                    Monthly::class
                );
            }

            if ($is_html_output) {
                echo $html_output;
            } else {
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
                        'totalcount' => $numrows,
                        'count' => $numrows,
                        'search' => '',
                        'cols' => [],
                        'rows' => $rows,
                    ],
                ]);

                $colid = 0;
                foreach ($headers as $header) {
                    $accounts_data['data']['cols'][] = [
                        'name' => $header,
                        'itemtype' => $itemtype,
                        'id' => ++$colid,
                    ];
                }

                $output->displayData($accounts_data, []);
            }
        } else {
            echo Search::showError($output_type);
        }
        if ($is_html_output) {
            self::showLegendary();
        }
    }

    static function showLegendary()
    {
        $contractstate = new ContractState();
        $contracts = $contractstate->find();
        $nb = count($contracts);
        echo "<div class='center'>";
        echo "<table class='tab_cadre'><tr><th colspan='10'>" . __('Caption') . "</th></tr>";
        /*$i = 0;
        foreach ($contracts as $contract){
           if($i == 5){
              echo "</tr><tr>";
           }
           echo "<td width=10px style='background-color:".$contract['color']."'> </td>";
           echo "<td> ".$contract['name']."</td>";
           $i = $i + 1;
        }
        echo "</tr><tr>";*/
        echo "<tr><td width=10px style='" . self::$style[2] . "'></td>";
        echo "<td>" . __('Exceeding', 'manageentities') . "</td>";
        echo "<td width=10px style=" . self::$style[3] . "></td>";
        echo "<td>" . __('Closed') . " & " . __('To present an invoice', 'manageentities') . "</td>";
        echo "</tr></table></br>";
        echo "</div>";
    }

    static function checkValue($value, $output_type)
    {
        if (!empty($value)) {
            list($integer, $decimal) = explode('.', number_format($value, 2));
            if ($decimal != 00 && $decimal != 50 && $output_type == Search::HTML_OUTPUT) {
                return "<span style='color:red;'>" . html::formatNumber($value, 0, 2) . "</span>";
            }
        }
        return html::formatNumber($value, 0, 2);
    }

    function showHeader($options = [])
    {
        Entity::showManageentitiesHeader(__('Monthly follow-up', 'manageentities'));

        $rand = mt_rand();
        echo "<form method='post' name='criterias_form$rand' id='criterias_form$rand'
               action=\"./entity.php\">";
        echo "<div class='plugin_manageentities_color' ><table style='margin: 0px auto 5px auto;'>";
        echo "<tr><td colspan='2' class='center' name='year'></td></tr>";
        echo "<tr><td>";
        echo "<ul id='last_year'></ul></td>";
        echo "<td><ul id='manageentities-months-list'></ul>";
        echo "<td>";
        echo "<ul id='next_year'></ul></td>";
        echo "</td></tr>";
        echo "</table></div>";
        $year = ($_GET['year_current'] != 0) ? $_GET['year_current'] : Date('Y', strtotime('-1 month'));
        $month = date('m', strtotime($options['begin_date']));
        echo "<script type='text/javascript'>";
        echo "var yearIdElm = $('[name=\"year\"]');";
        echo "yearIdElm.html($year);";
        echo "lastYearManagesEntities('criterias_form$rand', '#last_year', $year, " . json_encode(
                Toolbox::getMonthsOfYearArray()
            ) . ");";
        echo "manageentitiesShowMonth('criterias_form$rand', '#manageentities-months-list', " . json_encode(
                Toolbox::getMonthsOfYearArray()
            ) . ", $year,  $month) ;";
        echo "nextYearManagesEntities('criterias_form$rand', '#next_year', $year, " . json_encode(
                Toolbox::getMonthsOfYearArray()
            ) . ");";

        echo "</script>";

        echo "<div align='spaced'><table class='tab_cadre_fixe center'>";

        echo "<tr class='tab_bg_2'>";
        echo "<td class='center'>" . __('Begin date') . "</td>";
        echo "<td class='center'>";
        Html::showDateField("begin_date", ['value' => $options['begin_date']]);
        echo "</td><td class='center'>" . __('End date') . "</td>";
        echo "<td class='center'>";
        Html::showDateField("end_date", ['value' => $options['end_date']]);
        echo "</td></tr>";
        echo "<tr class='tab_bg_2'>";
        echo "<td class='center' colspan='8'>";
        echo Html::submit(_sx('button', 'Search'), ['name' => 'searchmonthly', 'class' => 'btn btn-primary']);
        echo Html::hidden('entities_id', ['value' => $options['entities_id']]);
        echo Html::hidden('year_current', ['id' => 'action', 'value' => $year]);
        echo "</td></tr>";
        echo "</table></div>";

        Html::closeForm();
    }
}
