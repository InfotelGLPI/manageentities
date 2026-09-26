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
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Monthly follow-up', 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-calculator";
    }

    // Css styles/class
    public static $style = [
        'background-color: #FEC95C;color:#000',
        'text-align:center',
        'background-color: #FA6B6B;',
        'background-color:#FFBA3B',
    ];
    public static $class = ['styleItemTitle', 'styleContractTitle'];

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public static function queryMonthly($values = [])
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

        $criteriaEntity = [
            'SELECT' => [
                'glpi_entities.id AS entities_id',
                'glpi_entities.name AS entities_name',
            ],
            'DISTINCT' => true,
            'FROM' => 'glpi_tickets',
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'ON' => [
                        'glpi_tickets' => 'entities_id',
                        'glpi_entities' => 'id',
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
                [
                    'OR' => [
                        ['glpi_tickettasks.begin' => null],
                        ['glpi_tickettasks.begin' => [
                            '<=',
                            // Compute end_date + 1 day in PHP and pass it as a bound value
                            // instead of interpolating it into a raw SQL expression with the
                            // deprecated $DB->escape().
                            date('Y-m-d', strtotime($values['end_date'] . ' +1 day')),
                        ]],
                    ],
                ],
                [
                    'OR' => [
                        ['glpi_tickettasks.end' => null],
                        ['glpi_tickettasks.end' => ['>=', $values['begin_date']]],
                    ],
                ],
            ],
            'ORDERBY' => 'glpi_entities.name',
        ];
        $criteriaEntity['WHERE'] += getEntitiesRestrictCriteria('glpi_entities');

        $iteratorEntity = $DB->request($criteriaEntity);

        foreach ($iteratorEntity as $dataEntity) {
            $tabResults[$dataEntity['entities_id']]['entities_name'] = $dataEntity['entities_name'];
            $tabResults[$dataEntity['entities_id']]['entities_id'] = $dataEntity['entities_id'];

            if ($config->fields['hourorday'] == Config::HOUR) {
                $types_contracts = [
                    Contract::CONTRACT_TYPE_NULL,
                    Contract::CONTRACT_TYPE_HOUR,
                    Contract::CONTRACT_TYPE_INTERVENTION,
                    Contract::CONTRACT_TYPE_UNLIMITED,
                ];
                $contract_type_field = 'glpi_plugin_manageentities_contracts.contract_type';
            } else {
                $types_contracts = [
                    Contract::CONTRACT_TYPE_NULL,
                    Contract::CONTRACT_TYPE_AT,
                    Contract::CONTRACT_TYPE_FORFAIT,
                ];
                $contract_type_field = 'glpi_plugin_manageentities_contractdays.contract_type';
            }

            $criteriaContractDay = [
                'SELECT' => [
                    'glpi_plugin_manageentities_contractdays.name AS name_contractdays',
                    'glpi_plugin_manageentities_contractdays.id AS contractdays_id',
                    'glpi_plugin_manageentities_contractdays.report AS report',
                    'glpi_plugin_manageentities_contractdays.nbday AS nbday',
                    'glpi_plugin_manageentities_contractdays.begin_date AS begin_date',
                    'glpi_plugin_manageentities_contractdays.end_date AS end_date',
                    'glpi_plugin_manageentities_contractdays.charged AS charged',
                    'glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id AS contractstates_id',
                    'glpi_plugin_manageentities_contractdays.plugin_manageentities_critypes_id',
                    $contract_type_field . ' AS contract_type',
                    'glpi_contracts.name AS name',
                    'glpi_contracts.num AS num',
                    'glpi_contracts.id AS contracts_id',
                    'glpi_contracts.entities_id AS entities_id',
                    'glpi_plugin_manageentities_contractstates.is_closed AS is_closed',
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
                    'glpi_plugin_manageentities_contracts' => [
                        'ON' => [
                            'glpi_plugin_manageentities_contracts' => 'contracts_id',
                            'glpi_contracts' => 'id',
                        ],
                    ],
                    'glpi_plugin_manageentities_contractstates' => [
                        'ON' => [
                            'glpi_plugin_manageentities_contractdays' => 'plugin_manageentities_contractstates_id',
                            'glpi_plugin_manageentities_contractstates' => 'id',
                        ],
                    ],
                    'glpi_plugin_manageentities_cridetails' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_contractdays_id',
                            'glpi_plugin_manageentities_contractdays' => 'id',
                        ],
                    ],
                    'glpi_tickets' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                            'glpi_tickets' => 'id',
                        ],
                    ],
                    'glpi_tickettasks' => [
                        'ON' => [
                            'glpi_tickettasks' => 'tickets_id',
                            'glpi_tickets' => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_plugin_manageentities_contractdays.entities_id' => $dataEntity['entities_id'],
                    'glpi_contracts.is_deleted' => 0,
                    $contract_type_field => $types_contracts,
                    [
                        'OR' => [
                            ['glpi_tickettasks.begin' => null],
                            ['glpi_tickettasks.begin' => [
                                '<=',
                                // Compute end_date + 1 day in PHP and pass it as a bound value
                                // instead of interpolating it into a raw SQL expression with the
                                // deprecated $DB->escape().
                                date('Y-m-d', strtotime($values['end_date'] . ' +1 day')),
                            ]],
                        ],
                    ],
                    [
                        'OR' => [
                            ['glpi_tickettasks.end' => null],
                            ['glpi_tickettasks.end' => ['>=', $values['begin_date']]],
                        ],
                    ],
                ],
                'GROUPBY' => 'glpi_plugin_manageentities_contractdays.id',
                'ORDERBY' => ['glpi_contracts.name ASC', 'glpi_plugin_manageentities_contractdays.end_date ASC'],
            ];

            $iteratorContractDay = $DB->request($criteriaContractDay);

            foreach ($iteratorContractDay as $dataContractDay) {
                $contract_credit = 0;

                // We get all cri details
                $resultCriDetail = CriDetail::getCriDetailData(
                    $dataContractDay,
                    [
                        'contract_type_id' => $dataContractDay['contract_type'],
                        'begin_date' => $values['begin_date'],
                        'end_date' => $values['end_date'],
                    ],
                );

                $resultCriDetail_beforeMonth = CriDetail::getCriDetailData(
                    $dataContractDay,
                    [
                        'contract_type_id' => $dataContractDay['contract_type'],
                        'end_date' => date('Y-m-d', strtotime($values['begin_date'] . ' - 1 DAY')),
                    ],
                );

                $remaining = $lastMonthRemaining = $resultCriDetail_beforeMonth['resultOther']['reste'];

                if (count($resultCriDetail['result']) > 0) {
                    // Credit
                    $credit = $dataContractDay['nbday'] + $dataContractDay['report'];
                    $contract_credit += $credit;
                    $tot_credit += $credit;

                    // Contract day informations. The link of the contract is built by
                    // monthly_report.html.twig from contracts_id and num.
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['name_contractdays'] = $dataContractDay["name_contractdays"];
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contracts_id'] = $dataContractDay["contracts_id"];
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['is_closed'] = $dataContractDay["is_closed"];
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['num'] = $dataContractDay["num"];
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['contract_type'] = Contract::getContractType(
                        $dataContractDay["contract_type"],
                    );
                    $tabResults[$dataEntity['entities_id']][$dataContractDay['contractdays_id']]['credit'] = $credit;

                    $contract_conso = 0;
                    foreach ($resultCriDetail['result'] as $cridetails_id => $dataCriDetail) {
                        $taskCount++;

                        // Conso per tech
                        $conso_per_tech = [];
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
     * Monthly follow-up report.
     *
     * The HTML output is rendered by monthly_report.html.twig from the same cells the CSV and
     * PDF exports are fed with, so every label reaches the page through Twig auto-escaping. It
     * used to be concatenated from HTMLSearchOutput::showItem(), which writes its argument into
     * the cell as is, and each database label had to be escaped by hand on the way.
     *
     * @param array $values criteria of the report (begin_date, end_date, display_type)
     *
     * @return void
     */
    public static function showMonthly($values = [])
    {
        global $DB;

        $results   = self::queryMonthly($values);
        $config    = Config::getInstance();
        $use_price = $config->fields['useprice'] == Config::PRICE;
        $itemtype  = Contract::class;

        $parameters = "begin_date=" . $values['begin_date'] . "&amp;end_date=" . $values['end_date'];

        // Set display type for export if defined
        $output_type    = $values["display_type"] ?? Search::HTML_OUTPUT;
        $output         = SearchEngine::getOutputForLegacyKey($output_type);
        $is_html_output = $output instanceof HTMLSearchOutput;

        if ($is_html_output) {
            $year     = date("Y");
            $month    = date('m', mktime(12, 0, 0, date("m"), 0, date("Y")));
            $date     = $year . "-" . $month . "-01";
            $query    = ContractDay::queryOldContractDaywithInterventions($date);
            $iterator = $DB->request($query);
            if (count($iterator) > 0) {
                $tickets = [];
                foreach ($iterator as $data) {
                    $ticket = new Ticket();
                    $ticket->getFromDB($data["tickets_id"]);
                    $tickets[] = [
                        'id'   => (int) $data["tickets_id"],
                        'link' => $ticket->getLink(),
                    ];
                }
                TemplateRenderer::getInstance()->display('@manageentities/monthly_old_contractdays_alert.html.twig', [
                    'tickets' => $tickets,
                ]);
            }
        }

        $headers = [
            _n('Client', 'Clients', 1, 'manageentities'),
            __('Contract'),
            ContractDay::getTypeName(1),
            $config->fields['hourorday'] == Config::HOUR
                ? __('Mode of management', 'manageentities')
                : __('Type of contract', 'manageentities'),
            __('Initial credit', 'manageentities'),
            __('Remaining on ', 'manageentities') . ' ' . Html::convDate($values['begin_date']),
        ];
        if ($use_price) {
            $headers[] = $config->fields['hourorday'] == Config::DAY
                ? __('Daily rate', 'manageentities')
                : __('Hourly rate', 'manageentities');
        }
        // The total label spans every column in front of the production one. It used to be
        // followed by six empty cells whatever the configuration, so without the rate column
        // the totals were shifted one column to the right of their header.
        $lead_colspan = count($headers);
        $headers[]    = __('Production', 'manageentities');
        $headers[]    = _n('Current stakeholder', 'Current stakeholders', 2, 'manageentities');
        if ($use_price) {
            $headers[] = __('Total production', 'manageentities');
        }
        $headers[] = __('Exceeding', 'manageentities');
        if ($use_price) {
            $headers[] = __('Total exceeding', 'manageentities');
        }
        $headers[] = __('State of intervention', 'manageentities');

        // One line per technician of each intervention
        $list = [];
        foreach ($results as $dataEntity) {
            if (is_array($dataEntity) && sizeof($dataEntity) > 2) {
                foreach ($dataEntity as $dataContractDay) {
                    if (is_array($dataContractDay)) {
                        foreach ($dataContractDay as $dataTask) {
                            if (is_array($dataTask)) {
                                foreach ($dataTask['conso_per_tech'] as $users_id => $conso) {
                                    $list[] = [
                                        'entities_name'        => $dataEntity['entities_name'],
                                        'contracts_id'         => $dataContractDay['contracts_id'],
                                        'num'                  => $dataContractDay['num'],
                                        'name_contractdays'    => $dataContractDay['name_contractdays'],
                                        'contract_type'        => $dataContractDay['contract_type'],
                                        'contract_credit'      => $dataContractDay['contract_credit'],
                                        'contract_remaining'   => $dataContractDay['contract_remaining'],
                                        'pricecri'             => $dataTask['pricecri'],
                                        'conso'                => $conso['conso'],
                                        'users_id'             => $users_id,
                                        'conso_amount'         => $conso['conso_amount'],
                                        'depass'               => $conso['depass'],
                                        'depass_amount'        => $conso['depass_amount'],
                                        'contractdays_state'   => $dataContractDay['contractdays_state'],
                                        'contractstates_color' => $dataContractDay['contractstates_color'],
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        $contract_url    = Toolbox::getItemTypeFormURL(\Contract::class);
        $exceeding_color = self::getStyleColor(self::$style[2]);
        $invoice_color   = self::getStyleColor(self::$style[3]);

        $table_rows = [];
        foreach ($list as $line) {
            $contract_label = in_array($line['num'], [null, ''], true)
                ? '(' . (int) $line['contracts_id'] . ')'
                : $line['num'];

            $cells = [
                self::buildCell($line['entities_name']),
                self::buildCell($contract_label, $contract_url . '?id=' . (int) $line['contracts_id']),
                self::buildCell($line['name_contractdays']),
                self::buildCell($line['contract_type']),
                self::buildCell(Html::formatNumber($line['contract_credit'], false, 2)),
                self::buildCell(Html::formatNumber($line['contract_remaining'], false, 2)),
            ];
            if ($use_price) {
                $cells[] = self::buildCell(Html::formatNumber($line['pricecri'], false, 2));
            }
            $cells[] = self::buildQuantityCell($line['conso']);
            $cells[] = self::buildCell(getUserName($line['users_id']));
            if ($use_price) {
                $cells[] = self::buildCell(Html::formatNumber($line['conso_amount'], false, 2));
            }

            // The exceeding cells used to test the $conso leftover of the loop building the
            // lines, so every line displayed - or hid - the exceeding of the last technician.
            $exceeding = $line['depass'] > 0;
            $cells[]   = $exceeding ? self::buildQuantityCell($line['depass']) : self::buildCell('');
            if ($use_price) {
                $cells[] = self::buildCell($exceeding ? Html::formatNumber($line['depass_amount'], false, 2) : '');
            }
            $cells[] = self::buildCell($line['contractdays_state']);

            $color = '';
            if ($exceeding) {
                $color = $exceeding_color;
            } elseif ($line['contractstates_color'] == self::$style[3]) {
                $color = $invoice_color;
            }

            $table_rows[] = [
                'color' => $color,
                'cells' => $cells,
            ];
        }

        if ($table_rows === []) {
            echo Search::showError($output_type);
        } elseif ($is_html_output) {
            Followup::showExportToolbar($parameters, Monthly::class);

            TemplateRenderer::getInstance()->display('@manageentities/monthly_report.html.twig', [
                'headers' => $headers,
                'rows'    => $table_rows,
                'totals'  => [
                    'lead_colspan'  => $lead_colspan,
                    'conso'         => Html::formatNumber($results['tot_conso'] ?? 0, false, 2),
                    'conso_amount'  => $use_price ? Html::formatNumber($results['tot_conso_amount'] ?? 0, false, 2) : null,
                    'depass'        => Html::formatNumber($results['tot_depass'] ?? 0, false, 2),
                    'depass_amount' => $use_price ? Html::formatNumber($results['tot_depass_amount'] ?? 0, false, 2) : null,
                ],
            ]);
        } else {
            // The exports receive the raw values: the contract column carries its number, no
            // longer the HTML anchor of the page.
            $rows = [];
            foreach ($table_rows as $row_num => $row) {
                foreach ($row['cells'] as $colnum => $cell) {
                    $rows[$row_num + 1][$itemtype . '_' . ($colnum + 1)] = ['displayname' => $cell['value']];
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
            foreach ($headers as $header) {
                $accounts_data['data']['cols'][] = [
                    'name' => $header,
                    'itemtype' => $itemtype,
                    'id' => ++$colid,
                ];
            }

            $output->displayData($accounts_data, []);
        }

        if ($is_html_output) {
            self::showLegendary();
        }
    }

    /**
     * One cell of the monthly report, shared by the HTML template and the exports.
     *
     * @param mixed       $value     label, escaped by Twig on the HTML side
     * @param string|null $url       target of the link wrapping the label, if any
     * @param bool        $highlight whether the HTML report flags the value
     *
     * @return array{value: string, url: string|null, highlight: bool}
     */
    private static function buildCell($value, ?string $url = null, bool $highlight = false): array
    {
        return [
            'value'     => (string) $value,
            'url'       => $url,
            'highlight' => $highlight,
        ];
    }

    /**
     * Cell of a consumed quantity, flagged when it is neither a whole nor a half unit.
     *
     * @param mixed $value
     *
     * @return array{value: string, url: string|null, highlight: bool}
     */
    private static function buildQuantityCell($value): array
    {
        $highlight = false;
        if (!empty($value)) {
            [, $decimal] = explode('.', number_format((float) $value, 2));
            $highlight   = $decimal !== '00' && $decimal !== '50';
        }

        return self::buildCell(Html::formatNumber($value, false, 2), null, $highlight);
    }

    /**
     * Caption of the monthly follow-up.
     *
     * Unlike the general follow-up this one does not list the contract states: it names the
     * two backgrounds the report paints its own cells with. The colours are read back from
     * self::$style so the swatch and the cells cannot drift apart -- the second one used to
     * be written into an unquoted style attribute, which only held together because the
     * declaration happens to carry no space.
     *
     * @return void
     */
    public static function showLegendary()
    {
        TemplateRenderer::getInstance()->display('@manageentities/legend.html.twig', [
            'entries' => [
                [
                    'name'  => __('Exceeding', 'manageentities'),
                    'color' => self::getStyleColor(self::$style[2]),
                ],
                [
                    'name'  => __('Closed') . ' & ' . __('To present an invoice', 'manageentities'),
                    'color' => self::getStyleColor(self::$style[3]),
                ],
            ],
        ]);
    }

    /**
     * Extract the background colour of one of the style declarations of self::$style.
     *
     * @param string $style
     *
     * @return string
     */
    public static function getStyleColor(string $style): string
    {
        $color = '';
        if (preg_match('/background-color[ ]*:[ ]*([^;]+)/', $style, $matches) === 1) {
            $color = $matches[1];
        }

        return Followup::sanitizeStateColor($color);
    }

    public function showHeader($options = [])
    {
        // The whole criteria block is rendered server side. It used to be a run of echo
        // producing a tab_cadre_fixe layout table with tab_bg_2 rows and center cells, plus
        // an empty table filled in by an inline script that built the period picker with
        // FullCalendar 3 button classes (fc-button, fc-state-default) removed in GLPI 11, so
        // the year buttons were styled unlike the month ones and the hardcoded colours of the
        // companion CSS ignored the dark theme.
        // Both values reach the template and the data attributes the script reads back, so
        // they are cast to integers before any use: anything else posted in year_current used
        // to be written into the script block as is.
        $year = (int) ($_GET['year_current'] ?? 0);
        if ($year <= 0) {
            $year = (int) date('Y', strtotime('-1 month'));
        }
        $month = (int) date('m', strtotime($options['begin_date']));

        TemplateRenderer::getInstance()->display('@manageentities/monthly_criterias.html.twig', [
            'form_name'   => 'criterias_form' . mt_rand(),
            'form_action' => './entity.php',
            'year'        => $year,
            'month'       => $month,
            'months'      => Toolbox::getMonthsOfYearArray(),
            'begin_date'  => $options['begin_date'],
            'end_date'    => $options['end_date'],
            'entities_id' => $options['entities_id'],
        ]);
    }
}
