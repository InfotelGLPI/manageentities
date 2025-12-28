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

use Ajax;
use CommonDBTM;
use CommonGLPI;
use DbUtils;
use Document;
use Glpi\RichText\RichText;
use Html;
use Planning;
use Session;
use Ticket;
use Toolbox;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class CriDetail extends CommonDBTM
{
    public static $rightname = "plugin_manageentities";

    public static function getTypeName($nb = 0)
    {
        return _n('Intervention task', 'Intervention tasks', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-headset";
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Ticket'
            && Session::haveRight("plugin_manageentities_cri_create", READ)) {
            return self::createTabEntry(Cri::getTypeName(1));
        } elseif ($item->getType() == ContractDay::class) {
            return self::createTabEntry(__('Linked interventions', 'manageentities'), self::countForContract($item));
        }
        return '';
    }

    public static function countForContract($item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_cridetails',
            ["`plugin_manageentities_contractdays_id`" => $item->getID()]
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == 'Ticket') {
            if (Session::getCurrentInterface() == 'central') {
                self::showForTicket($item);
            }
            self::showReports($item, $item->getField('id'));
        } elseif ($item->getType() == ContractDay::class) {
            echo self::showForContractDay($item);
        }
        return true;
    }

    public function prepareInputForUpdate($input)
    {//si un document lié ne pas permettre l'update via le form self::showForTicket($item);
        if (isset($input['updatecridetail'])) {
            $criDetail = new CriDetail();
            $criDetail->getFromDB($input['id']);

            if ($criDetail->fields['documents_id'] ?? '' != 0 && $criDetail->fields['contracts_id'] ?? '' != $input['contracts_id']) {
                Session::addMessageAfterRedirect(
                    __('Impossible action as an intervention report exists', 'manageentities'),
                    ERROR,
                    true
                );
                return false;
            }
        }

        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        return $input;
    }

    public function pre_deleteItem()
    {
        //si un document lié ne pas permettre le delete via le form self::showForTicket($item);
        if (isset($this->input['delcridetail'])) {
            if ($this->fields['documents_id'] ?? '' != '0') {
                Session::addMessageAfterRedirect(
                    __('Impossible action as an intervention report exists', 'manageentities'),
                    ERROR,
                    true
                );
                return false;
            }
        }

        return true;
    }

    //Shows CRI from check date - report.form.php function
    public function showHelpdeskReports($usertype, $technum, $date1, $date2)
    {
        global $DB, $CFG_GLPI;

        $dbu = new DbUtils();
        // ajout de la configuration du plugin
        $config = Config::getInstance();

        $criteria = [
            'SELECT' => [
                'glpi_documents.*',
                'glpi_tickets_users.users_id',
                'glpi_entities.id AS entity',
                $this->getTable() . '.date',
                $this->getTable() . '.technicians',
                $this->getTable() . '.plugin_manageentities_critypes_id',
                $this->getTable() . '.withcontract',
                $this->getTable() . '.contracts_id',
                $this->getTable() . '.realtime',
            ],
            'FROM' => 'glpi_documents',
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'ON' => [
                        'glpi_documents' => 'entities_id',
                        'glpi_entities' => 'id',
                    ],
                ],
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_documents' => 'tickets_id',
                        'glpi_contracts' => 'id',
                    ],
                ],
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets' => 'id',
                    ],
                ],
                $this->getTable() => [
                    'ON' => [
                        $this->getTable() => 'documents_id',
                        'glpi_documents' => 'id',
                    ],
                ],
                'glpi_plugin_manageentities_critechnicians' => [
                    'ON' => [
                        'glpi_documents' => 'tickets_id',
                        'glpi_plugin_manageentities_critechnicians' => 'tickets_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_tickets_users.type' => Ticket::ASSIGNED,
                'glpi_tickets' . '.is_deleted' => 0,
                'glpi_documents' . '.documentcategories_id' => $config->fields["documentcategories_id"],
                $this->getTable() . '.date' => ['>=', $date1],
                $this->getTable() . '.date' => ['<=', $date2],

            ],
            'GROUPBY' => ['glpi_documents.tickets_id'],
            'ORDERBY' => [$this->getTable() . '.date ASC'],
        ];

        if ($usertype != "group") {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                'OR' => [
                    'glpi_tickets_users.users_id' => $technum,
                    'glpi_plugin_manageentities_critechnicians.users_id' => $technum,
                ],
            ];
        }
        $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
            'glpi_documents'
        );

        $iterator = $DB->request($criteria);

        if (Session::isMultiEntitiesMode()) {
            $colsup = 1;
        } else {
            $colsup = 0;
        }

        if (count($iterator) > 0) {
            echo "<form method='post' action=\"./front/entity.php\">";
            echo "<div class='center'><table class='tab_cadre center' width='95%'>";
            echo "<tr><th colspan='" . (12 + $colsup) . "'>" . Cri::getTypeName(2) . "&nbsp;";
            if ($usertype != "group") {
                echo " -" . $dbu->getusername($technum) . "&nbsp;";
            }
            printf(__('From %1$s to %2$s :'), Html::convdate($date1), Html::convdate($date2)) . "</th></tr>";
            echo "<tr>";
            if (Session::isMultiEntitiesMode()) {
                echo "<th>" . _n('Entity', 'Entities', 1) . "</th>";
            }
            echo "<th>" . __('Date') . "</th>";
            echo "<th>" . __('Technicians', 'manageentities') . "</th>";
            if ($config->fields['useprice'] == Config::PRICE) {
                echo "<th>" . __('Intervention type', 'manageentities') . "</th>";
            }
            echo "<th>" . __('Crossed time (itinerary including)', 'manageentities') . "</th>";
            echo "<th>" . __('Intervention with contract', 'manageentities') . "</th>";
            echo "<th>" . __('Contract number') . "</th>";
            echo "<th>" . __('Associated ticket', 'manageentities') . "</th>";
            echo "<th>" . __('Name') . "</th>";
            echo "<th width='100px'>" . __('File') . "</th>";
            echo "</tr>";
            $i = 0;
            foreach ($iterator as $data) {
                $i++;
                $class = " class='tab_bg_2 ";
                if ($i % 2) {
                    $class = " class='tab_bg_1 ";
                }
                echo "<tr $class" . ($data["is_deleted"] == '1' ? "_2" : "") . "'>";

                if (Session::isMultiEntitiesMode()) {
                    echo "<td class='center'>" . \Dropdown::getDropdownName("glpi_entities", $data['entity']) . "</td>";
                }
                echo "<td class='center'>" . Html::convdate($data["date"]) . "</td>";
                echo "<td class='center'>" . $data["technicians"] . "</td>";
                if ($config->fields['useprice'] == Config::PRICE) {
                    echo "<td class='center'>" . \Dropdown::getDropdownName(
                        "glpi_plugin_manageentities_critypes",
                        $data['plugin_manageentities_critypes_id']
                    ) . "</td>";
                }
                echo "<td class='center'>" . $data["realtime"] . "</td>";
                echo "<td class='center'>" . \Dropdown::getYesNo($data["withcontract"]) . "</td>";
                $num_contract = "";
                if ($data["withcontract"]) {
                    $contract = new \Contract();
                    $contract->getFromDB($data["contracts_id"]);
                    $num_contract = $contract->fields["num"];
                }
                echo "<td class='center'>" . $num_contract . "</td>";
                echo "<td class='center'>";
                if ($data["tickets_id"] > 0) {
                    echo "<a href=\"" . $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id=" . $data["tickets_id"] . "\">" . $data["tickets_id"] . "</a>";
                }
                echo "</td>";
                echo "<td class='left'><a href='" . $CFG_GLPI["root_doc"] . "/front/document.form.php?id=" . $data["id"] . "'><b>" . $data["name"];
                if ($_SESSION["glpiis_ids_visible"]) {
                    echo " (" . $data["id"] . ")";
                }
                echo "</b></a></td>";
                $doc = new Document();
                $doc->getFromDB($data["id"]);
                echo "<td class='center'  width='100px'>" . $doc->getDownloadLink() . "</td>";

                echo "</tr>";
            }
            echo "</table></div>";
            Html::closeForm();
        }
    }

    /**
     * @param Ticket $ticket
     * @param array $options
     */
    public static function addReports(Ticket $ticket, $options = [])
    {
        global $CFG_GLPI;

        $rand = mt_rand();
        $toupdate = 'showCriDetail' . $rand;
        $modal = 'manageentities_cri_form' . $rand;
        if (isset($options['toupdate'])) {
            $toupdate = $options['toupdate'];
        }
        if (isset($options['modal'])) {
            $modal = $options['modal'];
        }

        $restrict = [
            "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $ticket->fields['entities_id'] ?? '',
            "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $ticket->fields['id'] ?? '',
        ];
        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
        $cridetail = reset($cridetails);

        $generation_ok = false;
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE
        ) && (empty($cridetail) || ($cridetail['documents_id'] ?? 0) == 0) && !empty($cridetail['contracts_id']) && !empty($cridetail['plugin_manageentities_contractdays_id'])) {
            $generation_ok = true;
        }
        //switch withoutcontract
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE
        ) && (empty($cridetail) || ($cridetail['documents_id'] ?? 0) == 0) && (isset($cridetail['withcontract']) ? !$cridetail['withcontract'] : true)) {
            $generation_ok = true;
        }

        $regeneration_ok = false;
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE
        ) && (!empty($cridetail) || ($cridetail['documents_id'] ?? 0) != 0) && !empty($cridetail['contracts_id']) && !empty($cridetail['plugin_manageentities_contractdays_id'])) {
            $regeneration_ok = true;
        }
        //switch withoutcontract
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE
        ) && (!empty($cridetail) || ($cridetail['documents_id'] ?? 0) != 0) && (isset($cridetail['withcontract']) ? !$cridetail['withcontract'] : true)) {
            $regeneration_ok = true;
        }

        if ($generation_ok) {
            if (isset($options['toupdate'])) {
                echo "<div id='" . $options['toupdate'] . "'>";
            } else {
                echo "<div id='showCriDetail$rand'>";
            }
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_1'><th>";
            echo __('Intervention report', 'manageentities');
            echo "</th></tr>";
            echo "<tr class='tab_bg_1'>";
            echo "<td class='center'>";
        }

        // GENERATE
        $pdf_action = '';
        $title = '';
        if ($generation_ok) {
            $title = __('Generation of the intervention report', 'manageentities');
            // REGENERATE
        } elseif ($regeneration_ok) {
            $title = __('Regenerate the intervention report', 'manageentities');
            $pdf_action = 'update_cri';
        }

        if ($generation_ok || $regeneration_ok) {
            Html::requireJs('glpi_dialog');
            $params = [
                'pdf_action' => $pdf_action,
                'job' => $ticket->fields['id'] ?? '',
                'root_doc' => PLUGIN_MANAGEENTITIES_WEBDIR,
                'toupdate' => "showCriDetail$rand",
                'width' => 1000,
                'height' => 550,
            ];

            echo Html::submit($title, [
                'name' => 'showCriForm',
                'class' => 'btn btn-primary',
                'onclick' => "manageentities_loadCriForm(\"showCriForm\", \"$modal\", " . json_encode($params) . ");",
            ]);

            //         if (!isset($options['modal'])) {
            //            echo "<div id=\"$modal\" title=\"" . $title . "\" style=\"display:none;text-align:center\"></div>";
            //         }
        }

        // DELETE
        if (Session::haveRight("plugin_manageentities_cri_create", UPDATE)
            && ($cridetail['documents_id'] ?? 0) != 0) {
            echo "<form method='post' name='cridetail_form$rand' id='cridetail_form$rand'
               action='" . Toolbox::getItemTypeFormURL(Cri::class) . "' style='display:inline'>";
            echo Html::submit(
                _sx('button', 'Delete permanently'),
                ['name' => 'purgedoc', 'class' => 'btn btn-primary']
            );
            echo Html::hidden('documents_id', ['value' => $cridetail['documents_id']]);
            Html::closeForm();
        }

        if ($generation_ok) {
            echo "</td>";
            echo "</tr>";
            echo "</table>";
            echo "</div>";
        }
    }

    //shows CRI from ticket or from entity portal
    public static function showReports($item, $instID, $entity = -1, $condition = [])
    {
        global $DB, $CFG_GLPI;

        $config = new Config();
        $ticket = new Ticket();
        $ticket->getFromDB($instID);

        if ($config->getFromDB(1)) {
            $criteria = [
                'SELECT' => [
                    'glpi_documents.*',
                    'glpi_plugin_manageentities_cridetails.date',
                    'glpi_plugin_manageentities_cridetails.technicians',
                    'glpi_plugin_manageentities_cridetails.plugin_manageentities_critypes_id',
                    'glpi_plugin_manageentities_cridetails.withcontract',
                    'glpi_plugin_manageentities_cridetails.contracts_id',
                    'glpi_plugin_manageentities_cridetails.realtime',
                ],
                'FROM' => 'glpi_documents',
                'LEFT JOIN' => [
                    'glpi_plugin_manageentities_cridetails' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'documents_id',
                            'glpi_documents' => 'id',
                        ],
                    ],
                    'glpi_plugin_manageentities_contractdays' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_contractdays_id',
                            'glpi_plugin_manageentities_contractdays' => 'id',
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
                    'glpi_documents.documentcategories_id' => $config->fields["documentcategories_id"],

                ],
                'ORDERBY' => 'glpi_plugin_manageentities_cridetails.date DESC',
                'LIMIT' => 10,
            ];
            if (count($condition) > 0) {
                $criteria['WHERE'] = $criteria['WHERE'] + [
                    'OR' => [
                        ['glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => 'NULL'],
                        $condition,
                    ],
                ];
            } else {
                $criteria['WHERE'] = $criteria['WHERE'] + [
                    'OR' => [
                        ['glpi_plugin_manageentities_contractdays.plugin_manageentities_contractstates_id' => 'NULL'],
                    ],
                ];
            }
            if ($entity != -1) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_documents.entities_id' => $entity];
            }
            if ($instID > 0) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_documents.tickets_id' => $instID];
            }

            $iterator = $DB->request($criteria);

            if (count($iterator) > 0) {
                echo "<table class='tab_cadre_fixe'>";
                echo "<tr class='tab_bg_1'><th colspan='8'>" . __('Associated intervention reports', 'manageentities');

                if (Session::haveRight("document", READ)) {
                    echo " <a href='" . $CFG_GLPI["root_doc"] . "/front/document.php?contains%5B0%5D=cri&amp;field%5B0%5D=1&amp;sort=19&amp;deleted=0&amp;start=0'>";
                    echo __('All reports', 'manageentities') . "</a>";
                }
                echo "</th></tr>";
                echo "<tr class='tab_bg_1'>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Technicians', 'manageentities') . "</th>";
                if ($config->fields['useprice'] == Config::PRICE) {
                    echo "<th>" . __('Intervention type', 'manageentities') . "</th>";
                }
                echo "<th>" . __('Crossed time (itinerary including)', 'manageentities') . "</th>";
                echo "<th>" . __('Intervention with contract', 'manageentities') . "</th>";
                echo "<th>" . __('Contract number', 'manageentities') . "</th>";
                echo "<th>" . __('Name') . "</th>";
                echo "<th width='100px'>" . __('File') . "</th>";
                echo "</tr>";

                foreach ($iterator as $data) {
                    echo "<tr class='tab_bg_1" . ($data["is_deleted"] == '1' ? "_2" : "") . "'>";
                    echo "<td class='center'>" . Html::convdate($data["date"]) . "</td>";
                    echo "<td class='center'>" . $data["technicians"] . "</td>";
                    if ($config->fields['useprice'] == Config::PRICE) {
                        echo "<td class='center'>" . \Dropdown::getDropdownName(
                            "glpi_plugin_manageentities_critypes",
                            $data['plugin_manageentities_critypes_id']
                        ) . "</td>";
                    }
                    echo "<td class='center'>" . Html::formatNumber($data["realtime"], 0, 2) . "</td>";
                    echo "<td class='center'>" . \Dropdown::getYesNo($data["withcontract"]) . "</td>";
                    $num_contract = "";
                    if ($data["withcontract"]) {
                        $contract = new \Contract();
                        $contract->getFromDB($data["contracts_id"]);
                        $num_contract = $contract->fields["num"];
                    }
                    echo "<td class='center'>" . $num_contract . "</td>";
                    echo "<td class='center'>";
                    if (Session::haveRight("document", READ)) {
                        echo "<a href='" . $CFG_GLPI["root_doc"] . "/front/document.form.php?id=" . $data["id"] . "'>";
                    }
                    echo "<b>" . $data["name"];
                    if ($_SESSION["glpiis_ids_visible"]) {
                        echo " (" . $data["id"] . ")";
                    }
                    echo "</b>";
                    if (Session::haveRight("document", READ)) {
                        echo "</a>";
                    }
                    echo "</td>";
                    $doc = new Document();
                    $doc->getFromDB($data["id"]);
                    echo "<td class='center' width='100px'>" . $doc->getDownloadLink() . "</td>";
                    echo "</tr>";
                }
                if ($entity == -1) {
                    echo "<tr class='tab_bg_1'>";
                    echo "<td class='center' colspan='8'>";
                    self::addReports($item);
                    echo "</td>";
                    echo "</tr>";
                }

                echo "</table>";
            } else {
                echo "<div class='center alert alert-important alert-info d-flex'>";
                echo __('No results found');
                echo "</div>";
                if ($entity == -1) {
                    self::addReports($item);
                }
            }
        }
    }

    //shows CRI from ticket or from entity portal
    public static function showPeriod($item, $instID, $entity = -1, $options = [])
    {
        global $DB;

        $colspan = 8;
        $config = Config::getInstance();

        $contract = new Contract();
        $contracts = $contract->find(['entities_id' => $entity]);

        foreach ($contracts as $data_contract) {
            $iterator = $DB->request([
                'SELECT' => [
                    'COUNT' => 'glpi_plugin_manageentities_contractdays.*',
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
                    'contracts_id' => $data_contract["id"],
                    'entities_id' => $entity,
                    'glpi_plugin_manageentities_contractstates.is_closed' => ['<>', 1],
                ],
                'ORDERBY' => 'glpi_plugin_manageentities_contractdays.begin_date` DESC',
            ]);

            if (count($iterator) > 0) {
                echo "<div class='center'>";
                echo "<table class='tab_cadre_fixe' cellpadding='5'>";
                echo "<tr>";
                echo "<tr><th colspan='" . $colspan . "'>" . __(
                    'Intervention of contract',
                    'manageentities'
                ) . " : " . $data_contract["name"] . "</th></tr>";
                echo "<tr>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Object of intervention', 'manageentities') . "</th>";
                echo "<th>" . __('Intervention type', 'manageentities') . "</th>";
                echo "<th>" . __('File') . "</th>";
                echo "<th>" . __('Crossed time (itinerary including)', 'manageentities') . "</th>";
                echo "<th>" . __('Technicians', 'manageentities') . "</th>";
                if ($config->fields['hourorday'] == Config::DAY) {
                    echo "<th>" . __('Applied daily rate', 'manageentities') . "</th>";
                } else {
                    echo "<th>" . __('Applied hourly rate', 'manageentities') . "</th>";
                }
                echo "<th>" . __('To compute', 'manageentities') . "</th>";
                echo "</tr>";
                foreach ($iterator as $data) {
                    $data['contractdays_id'] = $data['id'];
                    $options['sorting_date'] = true;
                    $resultCriDetail = self::getCriDetailData($data, $options);

                    if (sizeof($resultCriDetail['result']) > 0) {
                        echo "<tr  class='tab_bg_2'><td class='center' colspan='" . $colspan . "'>" . __(
                            'Periods of contract',
                            'manageentities'
                        ) . " :  " . $data['name'] . "</td></tr>";
                        foreach ($resultCriDetail['result'] as $dataCriDetail) {
                            echo "<tr class='tab_bg_1" . ($dataCriDetail["is_deleted"] == '1' ? "_2" : "") . "'>";
                            echo "<td>" . Html::convdate($dataCriDetail['tickets_date']) . "</td>";
                            echo "<td>" . $dataCriDetail['tickets_name'] . "</td>";

                            // If a cri as been generated we get its data
                            if (isset($dataCriDetail["documents_id"]) && $dataCriDetail["documents_id"] != 0) {
                                echo "<td>" . $dataCriDetail['plugin_manageentities_critypes_name'] . "</td>";
                                $doc = new Document();
                                $doc->getFromDB($dataCriDetail["documents_id"]);
                                if (Session::getCurrentInterface() == 'central') {
                                    echo "<td class='center'  width='100px'>" . $doc->getDownloadLink() . "</td>";
                                } else {
                                    echo "<td class='center'  width='100px'>" . $doc->getName() . "</td>";
                                }
                                if ($config->fields['hourorday'] == Config::HOUR
                                    || ($config->fields['hourorday'] == Config::DAY && $data['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                                    echo "<td>" . Html::formatNumber($dataCriDetail['conso'], 0, 2) . "</td>";
                                } else {
                                    echo "<td></td>";
                                }

                                echo "<td>" . $dataCriDetail['tech'] . "</td>";
                                // Else no cri generated
                            } else {
                                echo "<td>" . \Dropdown::getDropdownName(
                                    'glpi_plugin_manageentities_critypes',
                                    $dataCriDetail['plugin_manageentities_critypes_id']
                                ) . "</td>";
                                echo "<td class='center'  width='100px'></td>";
                                if ($config->fields['hourorday'] == Config::HOUR
                                    || ($config->fields['hourorday'] == Config::DAY && $data['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                                    echo "<td>" . Html::formatNumber($dataCriDetail['conso'], 0, 2) . "</td>";
                                } else {
                                    echo "<td>" . \Dropdown::EMPTY_VALUE . "</td>";
                                }
                                echo "<td>" . $dataCriDetail['tech'] . "</td>";
                            }

                            if ($dataCriDetail['pricecri']) {
                                echo "<td>";
                                if ($config->fields['hourorday'] == Config::HOUR
                                    || ($config->fields['hourorday'] == Config::DAY && $data['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                                    echo Html::formatNumber($dataCriDetail['pricecri'], 0, 2);
                                } else {
                                    echo \Dropdown::EMPTY_VALUE;
                                }

                                echo "</td>";
                                if ($config->fields['hourorday'] == Config::HOUR
                                    || ($config->fields['hourorday'] == Config::DAY && $data['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT)) {
                                    echo "<td>" . Html::formatNumber(
                                        $dataCriDetail['pricecri'] * $dataCriDetail['conso'],
                                        0,
                                        2
                                    ) . "</td>";
                                } else {
                                    echo "<td>" . \Dropdown::EMPTY_VALUE . "</td>";
                                }
                            } else {
                                echo "<td colspan='2'>";
                                echo "</td>";
                            }
                            echo "</tr>";
                        }
                    }
                }
                echo "</table>";
                echo "</div>";
            }
        }
    }

    public static function getCriDetailData($contractDayValues = [], $options = [])
    {
        global $DB;
        $params['condition'] = '1';

        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }

        $tabResults = [];
        $taskCount = 0; // Count the number of tasks for all entities
        $conso = 0;
        $tot_amount = 0;
        $tot_conso = 0;
        $price = 0;

        $config = Config::getInstance();
        $critechnicians = new CriTechnician();

        $PDF = new CriPDF('P', 'mm', 'A4');

        $tabOther = [
            'tot_amount' => 0,
            'reste_montant' => 0,
            'depass' => 0,
            'reste' => 0,
            'forfait' => 0,
        ];

        $criteria = [
            'SELECT' => [
                'glpi_plugin_manageentities_cridetails.realtime as actiontime',
                'glpi_plugin_manageentities_cridetails.documents_id',
                'glpi_documents.is_deleted',
                'glpi_plugin_manageentities_cridetails.tickets_id',
                'glpi_plugin_manageentities_cridetails.id as cridetails_id',
                'glpi_plugin_manageentities_cridetails.technicians as technicians',
                'glpi_plugin_manageentities_cridetails.plugin_manageentities_critypes_id',
                'glpi_plugin_manageentities_cridetails.date as cridetails_date',
                'glpi_tickets.name as tickets_name',
                'glpi_tickets.date as tickets_date',
                'glpi_plugin_manageentities_critypes.name as plugin_manageentities_critypes_name',
                'glpi_tickets.global_validation',
            ],
            'FROM' => 'glpi_plugin_manageentities_cridetails',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_critypes' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_critypes_id',
                        'glpi_plugin_manageentities_critypes' => 'id',
                    ],
                ],
                'glpi_documents' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'documents_id',
                        'glpi_documents' => 'id',
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
            'ORDERBY' => [],
        ];

        if (isset($options['begin_date'])) {
            $options['begin_date'] .= ' 00:00:00';
            $criteria['WHERE'] = $criteria['WHERE'] + [
                'OR' => [
                    'glpi_tickettasks.begin' => ['>=', $options['begin_date']],
                    'glpi_tickettasks.begin' => 'NULL',
                ],
            ];
        }

        if (isset($options['end_date'])) {
            $options['end_date'] .= ' 23:59:59';
            $criteria['WHERE'] = $criteria['WHERE'] + [
                'OR' => [
                    'glpi_tickettasks.end' => ['<=', $options['end_date']],
                    'glpi_tickettasks.end' => 'NULL',
                ],
            ];
        }

        if (isset($options['sorting_date'])) {
            $criteria['ORDERBY'] = $criteria['GROUPBY'] + ['tickets_date DESC'];
        } else {
            $criteria['ORDERBY'] = $criteria['GROUPBY'] + ['glpi_plugin_manageentities_cridetails ASC'];
        }

        $iterator = $DB->request($criteria);

        $restrict = [
            "`glpi_plugin_manageentities_contracts`.`entities_id`" => $contractDayValues["entities_id"],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contractDayValues["contracts_id"],
        ];
        $dbu = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract = reset($pluginContracts);

        // Default Cri price
        $default_price = 0;
        $default_critypes_name = '';
        $default_critypes_id = 0;
        $cri_price = new CriPrice();
        $condition = ['glpi_plugin_manageentities_criprices.is_default' => 1];
        $price_data = $cri_price->getItems($contractDayValues["contractdays_id"], 0, $condition);
        if (!empty($price_data)) {
            $price_data = reset($price_data);
            $price = $price_data["price"];
            $default_price = $price_data["price"];
            $default_critypes_name = $price_data["critypes_name"];
            $default_critypes_id = $price_data["plugin_manageentities_critypes_id"];
        }

        if (count($iterator) > 0) {
            $taskCount++;
            foreach ($iterator as $dataCriDetail) {
                // Get cridetail Cri Price if exists
                $price = 0;
                $critypes_name = '';
                $critypes_id = 0;
                if ($dataCriDetail['plugin_manageentities_critypes_id'] != 0) {
                    $price_data = $cri_price->getItems(
                        $contractDayValues["contractdays_id"],
                        $dataCriDetail['plugin_manageentities_critypes_id']
                    );
                    if (!empty($price_data)) {
                        $price_data = reset($price_data);
                        $price = $price_data["price"];
                        $critypes_name = $price_data["critypes_name"];
                        $critypes_id = $price_data["plugin_manageentities_critypes_id"];
                    }
                }
                $price = empty($price) ? $default_price : $price;
                $critypes_name = empty($critypes_name) ? $default_critypes_name : $critypes_name;
                $critypes_id = empty($critypes_id) ? $default_critypes_id : $critypes_id;

                $criteria = [
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

                if (isset($options['begin_date'])) {
                    $criteria['WHERE'] = $criteria['WHERE'] + [
                        'OR' => [
                            'glpi_tickettasks.begin' => ['>=', $options['begin_date']],
                            'glpi_tickettasks.begin' => 'NULL',
                        ],
                    ];
                }

                if (isset($options['end_date'])) {
                    $criteria['WHERE'] = $criteria['WHERE'] + [
                        'OR' => [
                            'glpi_tickettasks.end' => ['<=', $options['end_date']],
                            'glpi_tickettasks.end' => 'NULL',
                        ],
                    ];
                }

                if ($config->fields['hourorday'] == Config::HOUR) {
                    $criteria['LEFT JOIN'] = $criteria['LEFT JOIN'] + [
                        'glpi_plugin_manageentities_taskcategories' => [
                            'ON' => [
                                'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                'glpi_tickettasks' => 'taskcategories_id',
                            ],
                        ],
                    ];
                    $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_plugin_manageentities_taskcategories.is_usedforcount' => 1];
                }
                $iterator = $DB->request($criteria);

                $tech = '';
                $conso = 0;
                $conso_per_tech = [];

                if (count($iterator) > 0) {
                    $left = $contractDayValues["nbday"];
                    $tech = implode('<br/>', $critechnicians->getTechnicians($dataCriDetail['tickets_id']));

                    foreach ($iterator as $dataTask) {
                        // Init depass
                        if (!isset($conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['depass'])) {
                            $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['depass'] = 0;
                        }

                        //Init conso per techs
                        if (!isset($conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['conso'])) {
                            $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['conso'] = 0;
                        }
                        // Set conso per techs
                        $tmp = self::setConso($dataTask['actiontime'], 0, $config, $dataCriDetail, $pluginContract, 1);

                        $round = round($tmp, 2,PHP_ROUND_HALF_UP);

                        $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['conso'] += $PDF->TotalTpsPassesArrondis(
                            $round
                        );

                        // Set global conso of contractday
                        $tempasse = $PDF->TotalTpsPassesArrondis($round);
                        $conso += $tempasse;

                        // Set depass per techs
                        $left -= self::computeInDays(
                            $dataTask['actiontime'],
                            $config,
                            $dataCriDetail,
                            $pluginContract,
                            1
                        );
                        if ($left <= 0) {
                            $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['depass'] += abs(
                                $PDF->TotalTpsPassesArrondis($left)
                            );
                            $left = 0;
                        }
                    }
                }

                // Ticket name
                $ticket = new Ticket();
                $ticket->getFromDB($dataCriDetail["tickets_id"]);
                $ticket_name = $ticket->getName();


                $tot_amount += $conso * $price;
                $tot_conso += $conso;

                //Task informations
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_id'] = $dataCriDetail['tickets_id'];
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_name'] = $ticket_name;
                $tabResults[$dataCriDetail['cridetails_id']]['is_deleted'] = $dataCriDetail['is_deleted'];
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_date'] = $dataCriDetail['tickets_date'];
                $tabResults[$dataCriDetail['cridetails_id']]['conso'] = $conso;
                $tabResults[$dataCriDetail['cridetails_id']]['conso_per_tech'] = $conso_per_tech;
                $tabResults[$dataCriDetail['cridetails_id']]['tech'] = $tech;
                $tabResults[$dataCriDetail['cridetails_id']]['conso_amount'] = $conso * $price;
                $tabResults[$dataCriDetail['cridetails_id']]['pricecri'] = $price;
                $tabResults[$dataCriDetail['cridetails_id']]['documents_id'] = $dataCriDetail['documents_id'];
                $tabResults[$dataCriDetail['cridetails_id']]['plugin_manageentities_critypes_name'] = $critypes_name;
                $tabResults[$dataCriDetail['cridetails_id']]['plugin_manageentities_critypes_id'] = $critypes_id;
            }
        }

        //Rest number / depass
        $tabOther['reste'] = (number_format($contractDayValues["nbday"], 0) + number_format($contractDayValues["report"], 0)) - $tot_conso;
        if ($tabOther['reste'] < 0) {
            $tabOther['depass'] = abs($tabOther['reste']);
            $tabOther['reste'] = 0;
        }

        // If depass on contract day set depass on last tech of last ticket of last intervention
        if ($tabOther['depass'] > 0) {
            $lastIntervention = end($tabResults);
            if (count($lastIntervention['conso_per_tech']) > 0) {
                $lastTicket = end($lastIntervention['conso_per_tech']);
                end($lastTicket);
                $tabResults[key($tabResults)]['conso_per_tech'][key($lastIntervention['conso_per_tech'])][key(
                    $lastTicket
                )]['depass'] = $tabOther['depass'];
            }
            reset($tabResults);
        }

        //Forfait
        $tabOther['forfait'] = ($contractDayValues["nbday"] + $contractDayValues["report"]) * $default_price;

        // Default criprice
        $tabOther['default_criprice'] = $default_price;

        //Rest amount
        $tabOther['reste_montant'] = $tabOther['forfait'] - $tot_amount;
        $tabOther['tot_amount'] = $tot_amount;

        return ['result' => $tabResults, 'resultOther' => $tabOther];
    }

    public static function setConso($actiontime, $conso, $config, $dataCriDetail, $pluginContract, $numberTask = 0)
    {
        $tmp = 0;

        // Compute conso on tickets
        if ($config->fields['hourorday'] == Config::DAY) {//configuration by day
            if ($config->fields["hourbyday"] != 0) {
                $tmp = $actiontime / 3600 / $config->fields["hourbyday"];
            } else {
                $tmp = 0;
            }

            $conso += $tmp;
        } elseif ($config->fields['needvalidationforcri'] == 1 && $dataCriDetail['global_validation'] != 'accepted') {
            $conso = "<div style='color:red;'>" . __('Ticket not validated', 'manageentities') . "</div>";
        } else {//configuration by hour
            if ($pluginContract['contract_type'] == Contract::CONTRACT_TYPE_INTERVENTION) {
                $conso = $numberTask;
            } elseif ($pluginContract['contract_type'] == Contract::CONTRACT_TYPE_HOUR || $pluginContract['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED) {
                $tmp = $actiontime / 3600;
                $conso += $tmp;
            } else {
                $conso = "<div style='color:red;'>" . __(
                    'Type of service contract missing',
                    'manageentities'
                ) . "</div>";
            }
        }

        return $conso;
    }

    public static function showForContractDay(ContractDay $contractDay)
    {
        global $PDF;

        $colspan = 8;
        $config = Config::getInstance();
        $PDF = new CriPDF('P', 'mm', 'A4');

        $manageentities_contract = new Contract();
        $manageentities_contract->getFromDBByCrit(['contracts_id' => $contractDay->fields['contracts_id'] ?? '']);
        // We get all cri detail data
        $contractDay->fields['contractdays_id'] = $contractDay->fields['id'] ?? '';
        $resultCriDetail = self::getCriDetailData($contractDay->fields);

        if ($config->fields['useprice'] == Config::PRICE) {
            echo "<div class='center'>";
            echo "<table class='tab_cadre_fixe' cellpadding='5'>";

            if (sizeof($resultCriDetail['result']) > 0) {
                echo "<tr>";
                echo "<tr><th colspan='" . $colspan . "'>" . __(
                    'Intervention periods of contract',
                    'manageentities'
                ) . "</th></tr>";
                echo "<tr>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Object of intervention', 'manageentities') . "</th>";
                echo "<th>" . __('Intervention type', 'manageentities') . "</th>";
                echo "<th>" . __('File') . "</th>";
                if ($config->fields['hourorday'] == Config::DAY
                    || (isset($manageentities_contract->fields['contract_type'])
                        && $manageentities_contract->fields['contract_type'] ?? '' != Contract::CONTRACT_TYPE_INTERVENTION)) {
                    echo "<th>" . __('Crossed time (itinerary including)', 'manageentities') . "</th>";
                    echo "<th>" . __('Technicians', 'manageentities') . "</th>";
                    if ($config->fields['hourorday'] == Config::DAY) {
                        echo "<th>" . __('Applied daily rate', 'manageentities') . "</th>";
                    } else {
                        echo "<th>" . __('Applied hourly rate', 'manageentities') . "</th>";
                    }
                } else {
                    echo "<th>" . _x('Quantity', 'Number') . " " . __(
                        'of this intervention',
                        'manageentities'
                    ) . "</th>";
                    echo "<th>" . __('Technicians', 'manageentities') . "</th>";
                    echo "<th>" . __('Applied rate', 'manageentities') . "</th>";
                }
                echo "<th>" . __('To compute', 'manageentities') . "</th>";
                echo "</tr>";

                foreach ($resultCriDetail['result'] as $dataCriDetail) {
                    echo "<tr class='tab_bg_1" . ($dataCriDetail["is_deleted"] == '1' ? "_2" : "") . "'>";
                    echo "<td>" . Html::convdate($dataCriDetail['tickets_date']) . "</td>";

                    $ticket = new Ticket();
                    $ticket->getFromDB($dataCriDetail["tickets_id"]);
                    echo "<td>" . $ticket->getLink();
                    echo "</td>";
                    // If a cri as been generated we get its data
                    if ($dataCriDetail["documents_id"] != 0) {
                        echo "<td>" . $dataCriDetail['plugin_manageentities_critypes_name'] . "</td>";
                        $doc = new Document();
                        $doc->getFromDB($dataCriDetail["documents_id"]);
                        echo "<td class='center'  width='100px'>" . $doc->getDownloadLink() . "</td>";
                        echo "<td>" . Html::formatNumber($dataCriDetail['conso'], false) . "</td>";
                        echo "<td>" . $dataCriDetail['tech'] . "</td>";
                        // Else no cri generated
                    } else {
                        echo "<td>" . \Dropdown::getDropdownName(
                            'glpi_plugin_manageentities_critypes',
                            $dataCriDetail['plugin_manageentities_critypes_id']
                        ) . "</td>";
                        echo "<td class='center'  width='100px'></td>";
                        echo "<td>" . Html::formatNumber($dataCriDetail['conso'], false) . "</td>";
                        echo "<td>" . $dataCriDetail['tech'] . "</td>";
                    }

                    if ($dataCriDetail['pricecri']) {
                        echo "<td>";
                        echo Html::formatNumber($dataCriDetail['pricecri'], false);
                        echo "</td>";
                        echo "<td>" . Html::formatNumber(
                            $dataCriDetail['pricecri'] * $dataCriDetail['conso'],
                            false
                        ) . "</td>";
                    } else {
                        echo "<td colspan='2'>";
                        echo "</td>";
                    }
                    echo "</tr>";
                }

                echo "<tr class='tab_bg_2'>";
                echo "<td colspan='" . ($colspan - 1) . "' class='right'><b>" . __(
                    'Total yearly consumption',
                    'manageentities'
                ) . " : </b></td>";
                echo "<td><b>" . Html::formatNumber($resultCriDetail['resultOther']['tot_amount'], false) . "</b></td>";
                $nbtheoricaldays = 0;
                if ($resultCriDetail['resultOther']['default_criprice'] > 0) {
                    $nbtheoricaldays = $resultCriDetail['resultOther']['reste_montant'] / $resultCriDetail['resultOther']['default_criprice'];
                }
                echo "<tr class='tab_bg_2'>";
                echo "<td colspan='" . ($colspan - 1) . "' align='right'><b>" . __(
                    'Estimated number of remaining days',
                    'manageentities'
                ) . " : </b></td>";
                echo "<td><b>" . Html::formatNumber($nbtheoricaldays, false) . "</b></td>";
                echo "</tr>";
            } else {
                echo "<tr class='tab_bg_2 center'><td>";
                echo __('No interventions in the dates of the period', 'manageentities');
                echo "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
        } else { // NO USE PRICE
            $colspan = $colspan - 1;

            if (sizeof($resultCriDetail['result']) != 0) {
                echo "<div class='center'>";
                echo "<table class='tab_cadre_fixe' cellpadding='5'>";
                echo "<tr>";
                echo "<th colspan='4'>" . __('Intervention periods of contract', 'manageentities') . "</th></tr>";
                echo "<tr>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Object of intervention', 'manageentities') . "</th>";
                echo "<th>" . __('Technicians', 'manageentities') . "</th>";
                echo "<th>" . __('Consumption', 'manageentities') . "</th>";
                echo "</tr>";

                foreach ($resultCriDetail['result'] as $dataCriDetail) {
                    echo "<tr class='tab_bg_1" . ($dataCriDetail["is_deleted"] == '1' ? "_2" : "") . "'>";
                    echo "<td class='center'>" . Html::convdate($dataCriDetail["tickets_date"]) . "</td>";
                    echo "<td class='center'>" . $dataCriDetail['tickets_name'] . "</td>";
                    echo "<td class='center'>" . $dataCriDetail['tech'] . "</td>";
                    echo "<td class='center'>" . $dataCriDetail['conso'] . "</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr class='tab_bg_2 center'><td>";
                echo __('No interventions in the dates of the period', 'manageentities');
                echo "</td></tr>";
            }

            echo "</table>";
            echo "</div>";
        }
    }

    /**
     * @param Ticket $ticket
     *
     * @return false
     * @throws \GlpitestSQLError
     */
    public static function showForTicket(Ticket $ticket)
    {
        global $DB;

        $rand = mt_rand();
        $canView = $ticket->can($ticket->fields['id'] ?? '', READ);
        $canEdit = $ticket->can($ticket->fields['id'] ?? '', UPDATE);

        $config = Config::getInstance();

        if (!$canView) {
            return false;
        }

        if ($config->fields["backup"] == 1) {
            $criDetail = new CriDetail();

            $iterator = $DB->request([
                'SELECT' => [
                    'glpi_documents.id AS doc_id',
                    'glpi_documents.tickets_id AS doc_tickets_id',
                    'glpi_plugin_manageentities_cridetails.id AS cri_id',
                    'glpi_plugin_manageentities_cridetails.tickets_id AS cri_tickets_id',
                ],
                'FROM' => 'glpi_documents',
                'LEFT JOIN' => [
                    'glpi_plugin_manageentities_cridetails' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'documents_id',
                            'glpi_documents' => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_documents.documentcategories_id' => $config->fields["documentcategories_id"],
                    'glpi_documents.tickets_id' => $ticket->fields['id'] ?? '',
                ],
            ]);

            if (count($iterator) > 0) {
                foreach ($iterator as $data) {
                    if ($data['cri_tickets_id'] == '0') {
                        $criDetail->update([
                            'id' => $data['cri_id'],
                            'tickets_id' => $data['doc_tickets_id'],
                        ]);
                    }
                }
            }
        }

        $restrict = [
            "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $ticket->fields['entities_id'] ?? '',
            "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $ticket->fields['id'] ?? '',
        ];

        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
        $cridetail = reset($cridetails);

        if ($canEdit) {
            echo "<form method='post' name='cridetail_form$rand' id='cridetail_form$rand'
               action='" . Toolbox::getItemTypeFormURL(Cri::class) . "'>";
        }

        echo "<div align='spaced'><table class='tab_cadre_fixe center'>";
        echo "<tr><th colspan='2'>" . __('Associate to a contract', 'manageentities') . "</th></tr>";
        echo "<tr class='tab_bg_1'><td class='center' colspan='2'>";
        echo "<div class='center' style='margin:0 auto; display:table'>";
        $rand = \Dropdown::showFromArray(
            'withcontract',
            [0 => __('Out of contract', 'manageentities'), 1 => __('With contrat', 'manageentities')],
            ['value' => ($cridetail) ? $cridetail['withcontract'] : 1, 'on_change' => 'changecontract();']
        );
        echo "</div>";
        echo "</td>";
        echo "</tr>";
        echo Html::scriptBlock(
            "
         function changecontract(){
            if($('#dropdown_withcontract$rand').val() != 0){
               $('#contract').show();
            } else {
               $('#contract').hide();
            }
         }
         changecontract();
      "
        );

        echo "<tr class='tab_bg_1'><td class='center' colspan='2'>";
        echo "<div id='contract' class='center' style='margin:0 auto; display:table'>";
        $contractSelected = self::showContractLinkDropdown($cridetail, $ticket->fields['entities_id'] ?? '');
        echo "</div>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo Html::hidden('tickets_id', ['value' => $ticket->fields['id'] ?? '']);
        echo Html::hidden('entities_id', ['value' => $ticket->fields['entities_id'] ?? '']);
        echo Html::hidden('date', ['value' => $ticket->fields['date'] ?? '']);

        if ($canEdit) {
            if (empty($cridetail)) {
                echo "<td class='center' colspan='2'>";
                echo Html::submit(_sx('button', 'Add'), ['name' => 'addcridetail', 'class' => 'btn btn-primary']);
                echo "</td>";
            } else {
                echo "<td class='center' colspan='2'>";
                echo Html::hidden('id', ['value' => $cridetail['id']]);
                echo Html::submit(_sx('button', 'Update'), ['name' => 'updatecridetail', 'class' => 'btn btn-primary']);
                echo "&nbsp;";
                echo Html::submit(
                    _sx('button', 'Delete permanently'),
                    ['name' => 'delcridetail', 'class' => 'btn btn-primary']
                );
                echo "</td>";
            }
        }
        echo "</tr>";
        echo "</table></div>";
        if ($canEdit) {
            Html::closeForm();
        }
    }

    public static function showContractLinkDropdown($cridetail, $entities_id, $type = 'ticket')
    {
        global $DB;

        $contract = new \Contract();
        $contract->getEmpty();
        $rand = mt_rand();
        $width = 300;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_contracts.id',
                'glpi_contracts.num',
                'glpi_contracts.name',
                'glpi_plugin_manageentities_contracts.contracts_id',
                'glpi_plugin_manageentities_contracts.id AS ID_us',
                'glpi_plugin_manageentities_contracts.is_default AS is_default',
            ],
            'DISTINCT' => true,
            'FROM' => 'glpi_contracts',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_contracts' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contracts' => 'contracts_id',
                        'glpi_contracts' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_contracts.entities_id' => $entities_id,
                'glpi_contracts.is_deleted' => 0,
            ],
            'ORDERBY' => 'glpi_contracts.name',
        ]);

        $selected = false;
        $contractSelected = 0;
        $contractdaySelected = 0;

        echo "<table class='tab_cadre' style='margin:0px'>";
        // Display contract
        echo "<tr class='tab_bg_1'>";
        echo "<th>" . __('Intervention with contract', 'manageentities') . "</th>";
        echo "<td>";
        if (count($iterator) > 0) {
            if ($type == 'ticket') {
                $elements = [\Dropdown::EMPTY_VALUE];
                $value = 0;
                foreach ($iterator as $data) {
                    if (($cridetail['contracts_id'] ?? 0) == $data["id"]) {
                        $selected = true;
                        $contractSelected = $cridetail['contracts_id'];
                        $contractdaySelected = $cridetail["plugin_manageentities_contractdays_id"];
                        $value = $data["id"];
                    } elseif ($data["is_default"] == '1' && !$selected) {
                        $contractSelected = $data['contracts_id'];
                        $value = $data["id"];
                    }

                    if (Contract::checkRemainingOpenContractDays($data["id"])
                        || ($cridetail['contracts_id'] ?? 0) == $data["id"]) {
                        $elements[$data["id"]] = $data["name"] . " - " . $data["num"];
                    }
                }
                if ($value == 0 && count($elements) == 2) {
                    unset($elements[0]);
                }
                $rand = \Dropdown::showFromArray('contracts_id', $elements, ['value' => $value, 'width' => $width]);
            } else {
                foreach ($iterator as $data) {
                    if ($cridetail['contracts_id'] == $data["id"]) {
                        $contractSelected = $cridetail['contracts_id'];
                        $contractdaySelected = $cridetail["plugin_manageentities_contractdays_id"];
                    }
                }
                if ($contractSelected) {
                    echo \Dropdown::getDropdownName('glpi_contracts', $contractSelected);
                }
            }
        } else {
            echo __('No active contracts', 'manageentities');
        }

        // Tooltip for contract
        if (!empty($contractSelected)) {
            echo '&nbsp;';
            $contract->getFromDB($contractSelected);
            Html::showToolTip($contract->fields['comment'] ?? '', [
                'link' => $contract->getLinkURL(),
                'linktarget' => '_blank',
            ]);
        }

        // Ajax for contract
        $params = [
            'contracts_id' => '__VALUE__',
            'contractdays_id' => $contractdaySelected,
            'current_contracts_id' => $contractSelected,
            'width' => $width,
        ];
        Ajax::updateItemOnSelectEvent(
            "dropdown_contracts_id$rand",
            "show_contractdays",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
            $params
        );
        Ajax::updateItem(
            "show_contractdays",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
            $params,
            "dropdown_contracts_id$rand"
        );
        echo "</td>";

        // Display contract day
        echo "<th>" . __('Periods of contract', 'manageentities') . "</th>";
        echo "<td>";
        $restrict = [
            'entities_id' => $contract->fields['entities_id'] ?? '',
            'contracts_id' => $contractSelected,
        ];
        $restrict += ['NOT' => ['plugin_manageentities_contractstates_id' => 2]];//Closed contract was 8, is now 2
        if ($type == 'ticket') {
            echo "<span id='show_contractdays'>";
            \Dropdown::show(ContractDay::class, [
                'name' => 'plugin_manageentities_contractdays_id',
                'value' => $contractdaySelected,
                'condition' => $restrict,
                'width' => $width,
            ]);
            echo "</span>";
        } else {
            echo \Dropdown::getDropdownName('glpi_plugin_manageentities_contractdays', $contractdaySelected);
        }
        echo "</td>";
        echo "</tr>";
        echo "</table>";

        return [
            'contractSelected' => $contractSelected,
            'contractdaySelected' => $contractdaySelected,
            'is_contract' => count($iterator),
        ];
    }

    public function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;
        if (isset($input['withcontract']) && $input['withcontract']) {
            $mandatory_fields = [
                'contracts_id' => __('Contract'),
                'plugin_manageentities_contractdays_id' => __('Periods of contract', 'manageentities'),
            ];

            foreach ($input as $key => $value) {
                if (array_key_exists($key, $mandatory_fields)) {
                    if (empty($value)) {
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
        }
        return true;
    }

    public static function computeInDays($actiontime, $config, $dataCriDetail, $pluginContract, $numberTask)
    {
        // Compute conso on tickets
        if ($config->fields['hourorday'] == Config::DAY) {//configuration by day
            if ($config->fields["hourbyday"] != 0) {
                return $actiontime / 3600 / $config->fields["hourbyday"];
            } else {
                return 0;
            }
        } elseif ($config->fields['needvalidationforcri'] == 1 && $dataCriDetail['global_validation'] != 'accepted') {
            return "<div style='color:red;'>" . __('Ticket not validated', 'manageentities') . "</div>";
        } else {//configuration by hour
            if ($pluginContract['contract_type'] == Contract::CONTRACT_TYPE_INTERVENTION) {
                return $numberTask;
            } elseif ($pluginContract['contract_type'] == Contract::CONTRACT_TYPE_HOUR || $pluginContract['contract_type'] == Contract::CONTRACT_TYPE_UNLIMITED) {
                return $actiontime / 3600;
            } else {
                return "<div style='color:red;'>" . __('Type of service contract missing', 'manageentities') . "</div>";
            }
        }
    }

    /**
     * Add items in the items fields of the parm array
     * Items need to have an unique index beginning by the begin date of the item to display
     * needed to be correcly displayed
     **/
    public static function populatePlanning($options = [])
    {
        global $DB, $CFG_GLPI;

        $dbu = new DbUtils();
        $default_options = [
            'color' => '',
            'event_type_color' => '',
            'check_planned' => false,
            'display_done_events' => true,
        ];
        $options = array_merge($default_options, $options);
        $addcrit = "";
        if ($options['display_done_events'] == false) {
            $addcrit = "AND `glpi_tickets`.`status` NOT IN ('" . Ticket::CLOSED . "','" . Ticket::SOLVED . "')
         AND `glpi_tickettasks`.`state` NOT IN ('" . Planning::DONE . "') ";
        }
        $interv = [];

        if (!isset($options['begin']) || ($options['begin'] == 'NULL')
            || !isset($options['end']) || ($options['end'] == 'NULL')) {
            return $interv;
        }

        $who = $options['who'];
        $who_group = $options['whogroup'];
        $begin = $options['begin'];
        $end = $options['end'];

        $ASSIGN = "";

        if (count($_SESSION["glpigroups"])) {
            $groups = implode("','", $_SESSION['glpigroups']);
            $ASSIGN = "(`glpi_tickettasks`.`users_id_tech`
                           IN (SELECT DISTINCT `users_id`
                               FROM `glpi_groups_users`
                               INNER JOIN `glpi_groups`
                                  ON (`glpi_groups_users`.`groups_id` = `glpi_groups`.`id`)
                               WHERE `glpi_groups_users`.`groups_id` IN ('$groups')
                                     AND `glpi_groups`.`is_assign`))
                      OR (`glpi_plugin_manageentities_critechnicians`.`users_id`
                           IN (SELECT DISTINCT `users_id`
                               FROM `glpi_groups_users`
                               INNER JOIN `glpi_groups`
                                  ON (`glpi_groups_users`.`groups_id` = `glpi_groups`.`id`)
                               WHERE `glpi_groups_users`.`groups_id` IN ('$groups')
                                     AND `glpi_groups`.`is_assign`))";
        } else { // Only personal ones
            $ASSIGN = " `glpi_tickettasks`.`users_id_tech` ='" . $who . "'
         OR `glpi_plugin_manageentities_critechnicians`.`users_id` ='" . $who . "'";
        }

        if ($who > 0) {
            $ASSIGN = " `glpi_tickettasks`.`users_id_tech` ='" . $who . "'
         OR `glpi_plugin_manageentities_critechnicians`.`users_id` ='" . $who . "'";
        }
        if ($who_group > 0) {
            $ASSIGN = " AND `users_id` IN (SELECT `users_id`
                                 FROM `glpi_groups_users`
                                 WHERE `groups_id` = '$who_group')";
        }


        $query = "SELECT `glpi_tickettasks`.`users_id_tech`,
                       `glpi_tickettasks`.`begin`,
                       `glpi_tickettasks`.`end`,
                       `glpi_tickettasks`.`id`,
                       `glpi_tickettasks`.`actiontime`,
                       `glpi_tickettasks`.`content`,
                       `glpi_tickets`.`name`,
                       `glpi_entities`.`name` AS entities_name,
                       `glpi_tickets`.`id`AS tickets_id "
            . " FROM `glpi_plugin_manageentities_cridetails` "
            . " LEFT JOIN `glpi_tickets` ON (`glpi_plugin_manageentities_cridetails`.`tickets_id` = `glpi_tickets`.`id`)"
            . " LEFT JOIN `glpi_entities` ON (`glpi_tickets`.`entities_id` = `glpi_entities`.`id`)"
            . " LEFT JOIN `glpi_tickets_users` ON (`glpi_tickets_users`.`tickets_id` = `glpi_tickets`.`id`)"
            . " LEFT JOIN `glpi_tickettasks` ON (`glpi_tickettasks`.`tickets_id` = `glpi_tickets`.`id`)"
            . " LEFT JOIN `glpi_plugin_manageentities_critechnicians` ON (`glpi_plugin_manageentities_cridetails`.`tickets_id` = `glpi_plugin_manageentities_critechnicians`.`tickets_id`) "
            . " WHERE (`glpi_tickettasks`.`begin` >= '" . $begin . "'
                  AND `glpi_tickettasks`.`end` <= '" . $end . "') "
            . " AND NOT `glpi_tickets`.`is_deleted` "
            . " $addcrit "
            . " AND $ASSIGN ";
        $query .= $dbu->getEntitiesRestrictRequest(
            "AND",
            "glpi_tickets",
            '',
            $_SESSION["glpiactiveentities"],
            false
        );
        $query .= " AND `glpi_tickettasks`.`actiontime` != 0";
        $query .= " GROUP BY `glpi_tickettasks`.`id` ";
        //$query.= " ORDER BY `glpi_plugin_manageentities_cridetails`.`date` ASC";

        $result = $DB->doQuery($query);
        $i = 0;

        if ($DB->numrows($result) > 0) {
            for ($i = 0; $data = $DB->fetchArray($result); $i++) {
                $key = $data["begin"] . "$$" . CriDetail::class . $data["id"];

                $interv[$key]['color'] = $options['color'];
                $interv[$key]['event_type_color'] = $options['event_type_color'];

                $interv[$key]["itemtype"] = CriDetail::class;

                $interv[$key]["id"] = $data["id"];
                $interv[$key]["users_id"] = $data["users_id_tech"];
                $interv[$key]["entities_name"] = $data["entities_name"];
                if (strcmp($begin, $data["begin"]) > 0) {
                    $interv[$key]["begin"] = $begin;
                } else {
                    $interv[$key]["begin"] = $data["begin"];
                }
                if (strcmp($end, $data["end"]) < 0) {
                    $interv[$key]["end"] = $end;
                } else {
                    $interv[$key]["end"] = $data["end"];
                }
                $interv[$key]["name"] = Html::resume_text(
                    $data["name"],
                    $CFG_GLPI["cut"]
                ); // name is re-encoded on JS side
                $interv[$key]["content"] = RichText::getSafeHtml(
                    Html::resume_text($data["content"], $CFG_GLPI["cut"])
                );
                $interv[$key]["actiontime"] = $data["actiontime"];
                $interv[$key]["url"] = $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id="
                    . $data['tickets_id'];
                $interv[$key]["ajaxurl"] = $CFG_GLPI["root_doc"] . "/ajax/planning.php"
                    . "?action=edit_event_form"
                    . "&itemtype=TicketTask&parentitemtype=Ticket"
                    . "&parentid=" . $data['tickets_id']
                    . "&id=" . $data['id']
                    . "&url=" . $interv[$key]["url"];
                $cri = new \TicketTask();
                $cri->getFromDB($data["id"]);
                $interv[$key]["editable"] = $cri->canUpdateItem();
            }
        }

        return $interv;
    }

    /**
     * Display a Planning Item
     *
     * @param $parm Array of the item to display
     *
     * @return Nothing (display function)
     **/
    public static function displayPlanningItem(array $val, $who, $type = "", $complete = 0)
    {
        global $CFG_GLPI;

        $html = "";
        $rand = mt_rand();
        $dbu = new DbUtils();
        if ($complete) {
            if ($val["entities_name"]) {
                $html .= "<strong>" . __('Entity') . "</strong> : " . $val['entities_name'] . "<br>";
            }

            if ($val["end"]) {
                $html .= "<strong>" . __('End date') . "</strong> : " . Html::convdatetime($val["end"]) . "<br>";
            }
            if ($val["users_id"] && $who != 0) {
                $html .= "<strong>" . __('User') . "</strong> : " . $dbu->getUserName($val["users_id"]) . "<br>";
            }
            if ($val["actiontime"]) {
                $html .= "<strong>" . __('Total duration') . "</strong> : " . Html::timestampToString(
                    $val['actiontime'],
                    false
                ) . "<br>";
            }

            $html .= "<div class='event-description'>" . $val["content"] . "</div>";
        } else {
            if ($val["entities_name"]) {
                $html .= "<strong>" . __('Entity') . "</strong> : " . $val['entities_name'] . "<br>";
            }
            if ($val["actiontime"]) {
                $html .= "<strong>" . __('Total duration') . "</strong> : " . Html::timestampToString(
                    $val['actiontime'],
                    false
                ) . "<br>";
            }

            //$html.= "<div class='event-description'>".$val["content"]."</div>";

            $html .= Html::showToolTip(
                $val["content"],
                [
                    'applyto' => "cri_" . $val["id"] . $rand,
                    'display' => false,
                ]
            );
        }


        return $html;
    }

}
