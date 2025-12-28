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
use Document;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use Glpi\DBAL\QueryUnion;
use Glpi\RichText\RichText;
use Html;
use Session;
use Ticket;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}



class Cri extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities_cri_create';

    public static function getTypeName($nb = 0)
    {
        return _n('Intervention report', 'Intervention reports', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-headset";
    }

    public function showForm($ID, $options = [])
    {
        global $DB, $CFG_GLPI;

        $config = Config::getInstance();
        $width = 200;
        $job = new Ticket();
        $job->getfromDB($ID);

        $params = [
            'job' => $ID,
            'form' => 'formReport',
            'root_doc' => PLUGIN_MANAGEENTITIES_WEBDIR,
            'toupdate' => $options['toupdate'],
            'pdf_action' => $options['action'],
            'width' => 1000,
            'height' => 550,
        ];

        //      Entity::showManageentitiesHeader(__('Interventions reports', 'manageentities'));

        echo "<div class='red styleContractTitle' style='display:none' id='manageentities_cri_error'></div>";

        echo "<form action=\"" . PLUGIN_MANAGEENTITIES_WEBDIR
            . "/front/cri.form.php\" method=\"post\" name=\"formReport\">";

        // Champ caché pour l'identifiant du ticket.
        echo Html::hidden('REPORT_ID', ['value' => $ID]);
        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";

        /* Information complémentaire déterminant si sous contrat ou non. */
        echo "<tr class='tab_bg_1'>";
        echo "<th>";
        echo _n('Contract', 'Contracts', 1);
        echo "</th>";
        echo "<td colspan='2'>";
        $restrict = [
            "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $job->fields['entities_id'] ?? '',
            "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $job->fields['id'] ?? '',
        ];
        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
        $cridetail = reset($cridetails);
        if (isset($cridetail['withcontract'])) {
            $contractSelected = CriDetail::showContractLinkDropdown(
                $cridetail,
                $job->fields['entities_id'] ?? '',
                'cri'
            );
        } else {
            echo "<table class='tab_cadre' style='margin:0px'>";
            echo "<tr class='tab_bg_1'>";
            echo "<th>" . __('Out of contract', 'manageentities') . "</th>";
            echo "</tr></table>";
            $contractSelected = [
                'contractSelected' => 0,
                'contractdaySelected' => 0,
                'is_contract' => 0,
            ];
        }
        echo "</td>";
        echo "</tr>";

        /* Information complémentaire déterminant les intervenants si plusieurs. */
        $CriTechnician = new CriTechnician();
        $technicians_id = $CriTechnician->getTechnicians($ID, true);

        echo "<tr class='tab_bg_1'>";
        echo "<th>";
        echo __('Technicians', 'manageentities');
        echo "</th>";

        echo "<td>";

        if (self::isTask($ID)) {
            if (!empty($technicians_id)) {
                $techs = [];
                foreach ($technicians_id as $remove => $data) {
                    foreach ($data as $users_id => $users_name) {
                        $rand = mt_rand();
                        if ($remove == 'remove') {
                            $params['tech_id'] = $users_id;
                            $techs[] = $users_name . "&nbsp;"
                                . "<a class='pointer' name='deleteTech$rand'
                                          onclick='manageentities_loadCriForm(\"deleteTech\", \"" . $options['modal'] . "\", " . json_encode(
                                    $params
                                ) . ");'>
                  <i class=\"ti ti-trash\" title=\"" . _sx('button', 'Delete permanently') . "\"></i>
                  </a>";
                        } else {
                            $techs[] = $users_name;
                        }
                    }
                }
                echo implode('<br>', $techs);
            } else {
                echo "<span style=\"font-weight:bold; color:red\">" . __(
                    'Please assign a technician to your tasks',
                    'manageentities'
                ) . "</span>";
            }
        }

        echo "</td>";
        echo "<td>";
        $used = [];
        if (!empty($technicians_id)) {
            foreach ($technicians_id as $data) {
                foreach ($data as $users_id => $users_name) {
                    $used[] = $users_id;
                }
            }
        }
        $rand = mt_rand();
        $idUser = User::dropdown([
            'name' => "users_id",
            'entity' => $job->fields["entities_id"],
            'used' => $used,
            'right' => 'all',
            'width' => $width,
        ]);
        echo "&nbsp;<a class='pointer' name='add_tech$rand'
                                          onclick='manageentities_loadCriForm(\"addTech\", \"" . $options['modal'] . "\", " . json_encode(
            $params
        ) . ");'>
                  <i class=\"ti ti-plus\" title=\"" . __('Add a technician', 'manageentities') . "\"></i>";

        echo "</td>";
        echo "</tr>";

        if ($contractSelected['contractSelected'] && $contractSelected['contractdaySelected']) {
            echo Html::hidden('CONTRAT', ['value' => $contractSelected['contractSelected']]);
            echo Html::hidden('CONTRACTDAY', ['value' => $contractSelected['contractdaySelected']]);

            if ($config->fields['useprice'] == Config::PRICE) {
                /* Information complémentaire pour le libellés des activités. */
                echo "<tr class='tab_bg_1'>";
                echo "<th>";
                echo __('Intervention type', 'manageentities');
                echo "</th>";

                echo "<td colspan='2'>";
                $CriPrice = new CriPrice();
                $critypes = $CriPrice->getItems($contractSelected['contractdaySelected']);
                $critypes_data = [\Dropdown::EMPTY_VALUE];
                $critypes_default = 0;
                foreach ($critypes as $value) {
                    $critypes_data[$value['plugin_manageentities_critypes_id']] = $value['critypes_name'];
                    if ($value['is_default']) {
                        $critypes_default = $value['plugin_manageentities_critypes_id'];
                    }
                }

                \Dropdown::showFromArray('REPORT_ACTIVITE', $critypes_data, [
                    'value' => $critypes_default,
                    'width' => $width,
                ]);
                echo "</td>";
                echo "</tr>";
                //configuration do not use price
            } else {
                echo Html::hidden('REPORT_ACTIVITE', ['value' => 'noprice']);
            }

            $contract = new Contract();
            if ($contract->getFromDBByCrit([
                'contracts_id' => $contractSelected['contractSelected'],
                'entities_id' => $job->fields["entities_id"],
            ])) {
                if ($contract->fields['moving_management'] ?? '') {
                    echo "<tr class='tab_bg_1'>";
                    echo "<th>";
                    echo __('Number of moving', 'manageentities');
                    echo "</th>";
                    echo "<td colspan='2'>";
                    \Dropdown::showNumber('number_moving', [
                        'value' => $cridetail['number_moving'],
                        'width' => $width,
                    ]);
                    echo "</td>";
                    echo "</tr>";
                }
            }
        } elseif (!isset($cridetail['withcontract']) || $cridetail['withcontract'] == false) {
            echo Html::hidden('WITHOUTCONTRACT', ['value' => 1]);
        }

        if (self::isTask($ID)) {
            /*
             * Information complémentaire pour la description globale du CRI.
             * Préremplissage avec les informations des suivis non privés.
             */
            $desc = "";
            $criteria = [
                'SELECT' => [
                    'begin',
                    'content',
                    'end',
                ],
                'FROM' => 'glpi_tickettasks',

                'WHERE' => [
                    'tickets_id' => $ID,
                ],
            ];

            if ($config->fields['use_publictask'] == Config::HOUR) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['is_private' => 0];
            }

            if ($config->fields['hourorday'] == Config::HOUR) {
                $criteria['LEFT JOIN'] = $criteria['LEFT JOIN'] + [
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_taskcategories' => [
                            'ON' => [
                                'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                'glpi_tickettasks' => 'taskcategories_id',
                            ],
                        ],
                    ],
                ];
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_plugin_manageentities_taskcategories.is_usedforcount' => 1];
            }


            $iterator = $DB->request($criteria);

            if (count($iterator) > 0) {
                foreach ($iterator as $data) {
                    $desc .= $data["content"] . "\n\n";
                }
                $desc = substr($desc, 0, strlen($desc) - 2); // Suppression des retours chariot pour le dernier suivi...
                echo "<tr class='tab_bg_1'>";
                echo "<th>";
                echo __('Detail of the realized works', 'manageentities');
                echo "</th>";

                echo "<td colspan='2'>";
                //echo "<textarea name=\"REPORT_DESCRIPTION\" cols='120' rows='22'>$desc</textarea>";
                $rand_text = mt_rand();
                $cols = 120;
                $rows = 22;

                echo Html::script("lib/tinymce.js");
                Html::textarea([
                    'name' => 'REPORT_DESCRIPTION',
                    'value' => RichText::getEnhancedHtml($desc),
                    'enable_richtext' => true,
                    'enable_fileupload' => false,
                    'enable_images' => false,
                    'rand' => $rand_text,
                    'editor_id' => 'comment' . $rand_text,
                ]);
                echo "</td>";
                echo "</tr>";

                /* Bouton de génération du rapport. */
                echo "<tr class='tab_bg_2'>";
                echo "<td class='center' colspan='3'>";
                // action empty : add cri
                if (empty($options['action'])) {
                    if (!empty($technicians_id)) {
                        //                  Html::requireJs('glpi_dialog');
                        //                  $modal = $options['modal'];

                        echo "<input type='button' name='add_cri' value=\""
                            . __('Generation of the intervention report', 'manageentities') . "\" class='submit btn btn-primary manageentities_button'
                  onClick='manageentities_loadCriForm(\"addCri\", \"" . $options['modal'] . "\", " . json_encode(
                                $params
                            ) . ");'>";
                    }
                    // action not empty : update cri
                } elseif ($options['action'] == 'update_cri') {
                    if (!empty($technicians_id)) {
                        echo "<input type='button' name='update_cri' class='submit btn btn-primary manageentities_button' value=\""
                            . __('Regenerate the intervention report', 'manageentities') . "\"
                  onClick='manageentities_loadCriForm(\"updateCri\", \"" . $options['modal'] . "\", " . json_encode(
                                $params
                            ) . ");'>";
                    }
                }
            } else {
                echo "<tr class='tab_bg_1'>";
                echo "<td class='center red'>";
                if ($config->fields['hourorday'] ?? '' != Config::HOUR) {
                    echo __("Impossible generation, you didn't create a scheduled task", 'manageentities');
                } else {
                    echo __('No tasks whose category can be used', 'manageentities');
                }
                echo "</td>";
                echo "</tr>";
            }
        } else {
            echo "<div class='alert alert-important alert-warning d-flex' >";
            echo __("Impossible generation, you didn't create a scheduled task", 'manageentities');
            echo "</div>";
        }
        echo "</td>";
        echo "</tr>";
        echo "</table></div>";
        Html::closeForm();
    }

    public function isTask($tickets_id)
    {
        $tickettask = new \TicketTask();
        $tasks = $tickettask->find(['tickets_id' => $tickets_id]);
        if (count($tasks)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Récupération des données et génération du document. Il sera enregistré suivant le paramétre
     * enregistrement.
     *
     * @param type $params
     * @param type $options
     *
     * @return boolean
     * @global CriPDF $PDF
     * @global type $DB
     * @global type $CFG_GLPI
     *
     */
    public function generatePdf($params, $options = [])
    {
        global $PDF, $DB, $CFG_GLPI;

        $p['CONTRACTDAY'] = 0;
        $p['WITHOUTCONTRACT'] = 0;
        $p['CONTRAT'] = 0;
        $p['REPORT_ACTIVITE'] = '';
        $p['REPORT_ACTIVITE_ID'] = 0;
        $p['documents_id'] = 0;
        $p['number_moving'] = 0;

        foreach ($params as $key => $val) {
            $p[$key] = $val;
            if ($key == 'REPORT_DESCRIPTION') {
                $p[$key] = urldecode($val);
            }
        }

        // ajout de la configuration du plugin
        $config = Config::getInstance();

        $PDF = new CriPDF('P', 'mm', 'A4');

        /* Initialisation du document avec les informations saisies par l'utilisateur. */
        $criType_id = $p['REPORT_ACTIVITE_ID'];
        $typeCri = new CriType();
        if ($typeCri->getFromDB($criType_id)) {
            $p['REPORT_ACTIVITE'] = $typeCri->getField('name');
        }
        if ($config->fields['useprice'] == Config::NOPRICE
            || $config->fields['hourorday'] == Config::HOUR
        ) {
            $p['REPORT_ACTIVITE'] = [];
            //         $criType_id           = 0;
        }

        //$PDF->SetDescriptionCri(Toolbox::unclean_cross_side_scripting_deep($p['REPORT_DESCRIPTION']));
        $p['REPORT_DESCRIPTION'] = str_replace("’", "'", $p['REPORT_DESCRIPTION']);
        $PDF->SetDescriptionCri($p['REPORT_DESCRIPTION']);

        $job = new Ticket();
        if ($job->getfromDB($p['REPORT_ID'])) {
            /* Récupération des informations du ticket et initialisation du rapport. */
            $PDF->SetDemandeAssociee($p['REPORT_ID']); // Demande / ticket associée au rapport.
            // Set intervenants
            $critechnicians = new CriTechnician();
            $intervenants = implode(',', $critechnicians->getTechnicians($p['REPORT_ID']));
            $PDF->SetIntervenant($intervenants);

            if ($p['WITHOUTCONTRACT']) {
                $sous_contrat = false;
                $PDF->SetSousContrat(0);

                /* Information de l'entité active et son contrat. */
                $infos_entite = [];
                $entite = new \Entity();
                $entite->getFromDB($job->fields["entities_id"]);
                $infos_entite[0] = $entite;
                $PDF->SetEntite($infos_entite);

                /* Année et mois de l'intervention (post du ticket). */
                $infos_date = [];
                $infos_date[0] = $job->fields["date"];

                /* Du ... au ... */
                //configuration only public task

                $criteria1 = [
                    'SELECT' => [
                        new QueryExpression("MAX(glpi_tickettasks.end) AS " . $DB->quoteName('max_date')),
                        new QueryExpression("MIN(glpi_tickettasks.begin) AS " . $DB->quoteName('min_date')),
                    ],
                    'FROM' => 'glpi_tickettasks',
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_taskcategories' => [
                            'ON' => [
                                'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                'glpi_tickettasks' => 'taskcategories_id',
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
                    ],
                ];
                if ($config->fields['use_publictask'] == '1') {
                    $criteria1['WHERE'] = $criteria1['WHERE'] + ['is_private' => 0];
                }

                $queries[] = $criteria1;

                $criteria2 = [
                    'SELECT' => [
                        new QueryExpression("MAX(glpi_tickettasks.date) AS " . $DB->quoteName('max_date')),
                        new QueryExpression("MIN(glpi_tickettasks.date) AS " . $DB->quoteName('min_date')),
                    ],
                    'FROM' => 'glpi_tickettasks',
                    'LEFT JOIN' => [
                        'glpi_plugin_manageentities_taskcategories' => [
                            'ON' => [
                                'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                'glpi_tickettasks' => 'taskcategories_id',
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
                    ],
                ];
                if ($config->fields['use_publictask'] == '1') {
                    $criteria2['WHERE'] = $criteria2['WHERE'] + ['is_private' => 0];
                }

                $queries[] = $criteria2;

                $criteria = [
                    'SELECT' => [
                        new QueryExpression("MAX(max_date) AS " . $DB->quoteName('max_date')),
                        new QueryExpression("MIN(min_date) AS " . $DB->quoteName('min_date')),
                    ],
                    'FROM' => new QueryUnion($queries),
                ];

                $iterator = $DB->request($criteria);

                if (count($iterator) > 0) {
                    foreach ($iterator as $data) {
                        $infos_date[1] = $data["min_date"];
                        $infos_date[2] = $data["max_date"];
                    }
                }
                $PDF->SetDateIntervention($infos_date);

                // Forfait

                $temps_passes = [];


                $result = self::getTempsPasses($p);
                $cpt_tps = 0;
                foreach ($result as $data) {
                    $un_temps_passe = [];

                    if ($config->fields['useprice'] == Config::PRICE) {
                        // If the category of the task is not used and is hourly for count we set value to 0
                        if ($data["is_usedforcount"] == 0 && $config->fields['hourorday'] == Config::HOUR) {
                            $un_temps_passe[4] = 0;
                        } else {
                            $un_temps_passe[4] = round($data["tps_passes"], 2,PHP_ROUND_HALF_UP);
                        }
                    } else {
                        // If the category of the task is not used and is hourly for count we set value to 0
                        if ($data["is_usedforcount"] == 0 && $config->fields['hourorday'] == Config::HOUR) {
                            $un_temps_passe[4] = 0;
                        } else {
                            $un_temps_passe[4] = $PDF->TotalTpsPassesArrondis(round($data["tps_passes"], 2,PHP_ROUND_HALF_UP));
                        } //arrondir au quart
                    }
                    if ($data["date_debut"] == null && $data["date_fin"] == null) {
                        $un_temps_passe[0] = substr($data["date"], 8, 2) . "/" . substr(
                            $data["date"],
                            5,
                            2
                        ) . "/" . substr($data["date"], 0, 4);
                        $un_temps_passe[1] = ($data["date"] == "-") ? "-" : substr($data["date"], 11, 2) . ":" . substr(
                            $data["date"],
                            14,
                            2
                        );
                        //calculating the end date
                        if ($config->fields['hourorday'] == Config::HOUR) {
                            $date = date(
                                'Y-m-d H:i:s',
                                strtotime($data["date"] . " + " . (($un_temps_passe[4]) * 3600) . " seconds")
                            );
                        } else {
                            //daily
                            $date = date(
                                'Y-m-d H:i:s',
                                strtotime($data["date"] . " + " . $un_temps_passe[4] * $config->fields['hourbyday'] ?? '' . " hours")
                            );
                        }

                        $un_temps_passe[2] = substr($date, 8, 2) . "/" . substr($date, 5, 2) . "/" . substr(
                            $date,
                            0,
                            4
                        );
                        $un_temps_passe[3] = ($date == "-") ? "-" : substr($date, 11, 2) . ":" . substr($date, 14, 2);
                    } else {
                        $un_temps_passe[0] = substr($data["date_debut"], 8, 2) . "/" . substr(
                            $data["date_debut"],
                            5,
                            2
                        ) . "/" . substr($data["date_debut"], 0, 4);
                        $un_temps_passe[1] = ($data["heure_debut"] == "-") ? "-" : substr(
                            $data["heure_debut"],
                            11,
                            2
                        ) . ":" . substr($data["heure_debut"], 14, 2);
                        $un_temps_passe[2] = substr($data["date_fin"], 8, 2) . "/" . substr(
                            $data["date_fin"],
                            5,
                            2
                        ) . "/" . substr($data["date_fin"], 0, 4);
                        $un_temps_passe[3] = ($data["heure_fin"] == "-") ? "-" : substr(
                            $data["heure_fin"],
                            11,
                            2
                        ) . ":" . substr($data["heure_fin"], 14, 2);
                    }

                    $temps_passes[$cpt_tps] = $un_temps_passe;


                    if ($config->fields['useprice'] == Config::NOPRICE && $config->fields['hourorday'] == Config::HOUR) {
                        $p['REPORT_ACTIVITE'][$cpt_tps] = \Dropdown::getDropdownName(
                            'glpi_taskcategories',
                            $data['taskcat']
                        );
                    }

                    $cpt_tps++;
                }

                $PDF->SetLibelleActivite($p['REPORT_ACTIVITE']);
                $PDF->SetTempsPasses($temps_passes);
            } else {
                $manageentities_contract = new Contract();
                $manageentities_contract_data = $manageentities_contract->find(['contracts_id' => $p['CONTRAT']]);
                $manageentities_contract_data = array_shift($manageentities_contract_data);
                $contract_days = new ContractDay();
                $contract_days->getFromDB($p['CONTRACTDAY']);

                if ($contract_days->fields['begin_date'] == "" && $contract_days->fields['end_date'] == "") {
                    Session::addMessageAfterRedirect(
                        __('Please fill the contract period begin and end dates.', 'manageentities'),
                        true,
                        ERROR
                    );
                    Html::back();
                    return false;
                } elseif ($contract_days->fields['end_date'] == "") {
                    Session::addMessageAfterRedirect(
                        __('Please fill the contract period end date.', 'manageentities'),
                        true,
                        ERROR
                    );
                    Html::back();
                    return false;
                } elseif ($contract_days->fields['begin_date'] == "") {
                    Session::addMessageAfterRedirect(
                        __('Please fill the contract period begin date.', 'manageentities'),
                        true,
                        ERROR
                    );
                    Html::back();
                    return false;
                }

                /* Année et mois de l'intervention (post du ticket). */
                $infos_date = [];
                $infos_date[0] = $job->fields["date"];
                $queries = [];
                // Not Forfait
                if (($config->fields['hourorday'] == Config::HOUR)
                    || (isset($contract_days->fields['contract_type'])
                        && $contract_days->fields['contract_type'] ?? '' != Contract::CONTRACT_TYPE_FORFAIT)) {
                    /* Du ... au ... */
                    //configuration only public task
                    $criteria1 = [
                        'SELECT' => [
                            new QueryExpression("MAX(glpi_tickettasks.end) AS " . $DB->quoteName('max_date')),
                            new QueryExpression("MIN(glpi_tickettasks.begin) AS " . $DB->quoteName('min_date')),
                        ],
                        'FROM' => 'glpi_tickettasks',
                        'LEFT JOIN' => [
                            'glpi_plugin_manageentities_taskcategories' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                    'glpi_tickettasks' => 'taskcategories_id',
                                ],
                            ],
                        ],
                        'WHERE' => [
                            'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
                        ],
                    ];
                    if ($config->fields['use_publictask'] == '1') {
                        $criteria1['WHERE'] = $criteria1['WHERE'] + ['is_private' => 0];
                    }

                    $queries[] = $criteria1;

                    $criteria2 = [
                        'SELECT' => [
                            new QueryExpression("MAX(glpi_tickettasks.date) AS " . $DB->quoteName('max_date')),
                            new QueryExpression("MIN(glpi_tickettasks.date) AS " . $DB->quoteName('min_date')),
                        ],
                        'FROM' => 'glpi_tickettasks',
                        'LEFT JOIN' => [
                            'glpi_plugin_manageentities_taskcategories' => [
                                'ON' => [
                                    'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                                    'glpi_tickettasks' => 'taskcategories_id',
                                ],
                            ],
                        ],
                        'WHERE' => [
                            'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
                        ],
                    ];
                    if ($config->fields['use_publictask'] == '1') {
                        $criteria2['WHERE'] = $criteria2['WHERE'] + ['is_private' => 0];
                    }

                    $queries[] = $criteria2;

                    $criteria = [
                        'SELECT' => [
                            new QueryExpression("MAX(max_date) AS " . $DB->quoteName('max_date')),
                            new QueryExpression("MIN(min_date) AS " . $DB->quoteName('min_date')),
                        ],
                        'FROM' => new QueryUnion($queries),
                    ];

                    $iterator = $DB->request($criteria);

                    if (count($iterator) > 0) {
                        foreach ($iterator as $data) {
                            $infos_date[1] = $data["min_date"];
                            $infos_date[2] = $data["max_date"];
                        }
                    }

                    $PDF->SetDateIntervention($infos_date);
                    // Forfait
                } else {
                    $infos_date[1] = $contract_days->fields['begin_date'] ?? '';
                    $infos_date[2] = $contract_days->fields['end_date'] ?? '';

                    $PDF->SetDateIntervention($infos_date);
                }

                /* Information de l'entité active et son contrat. */
                $infos_entite = [];
                $entite = new \Entity();
                $entite->getFromDB($job->fields["entities_id"]);
                $infos_entite[0] = $entite;

                if ($p['CONTRAT']) {
                    $contract = new \Contract();
                    $contract->getFromDB($p['CONTRAT']);
                    $infos_entite[1] = $contract->fields["num"];
                    $sous_contrat = true;
                } else {
                    $infos_entite[1] = "";
                    $sous_contrat = false;
                }
                $PDF->SetSousContrat($sous_contrat);

                $PDF->SetEntite($infos_entite);

                //type of contract Intervention entitled the total change
                if ($config->fields['hourorday'] == Config::HOUR && (isset($manageentities_contract_data['contract_type']) && $manageentities_contract_data['contract_type'] == Contract::CONTRACT_TYPE_INTERVENTION)) {
                    $PDF->setIntervention();
                }

                if (($config->fields['hourorday'] == Config::HOUR)
                    || (isset($contract_days->fields['contract_type']) && $contract_days->fields['contract_type'] ?? '' != Contract::CONTRACT_TYPE_FORFAIT)) {
                    $result = self::getTempsPasses($p);
                    $temps_passes = [];
                    $cpt_tps = 0;
                    foreach ($result as $data) {
                        $un_temps_passe = [];
                        if (($config->fields['hourorday'] == Config::HOUR) && (isset($manageentities_contract_data['contract_type']) && $manageentities_contract_data['contract_type'] == Contract::CONTRACT_TYPE_INTERVENTION)) {
                            $un_temps_passe[4] = 1;
                        } else {
                            if ($config->fields['useprice'] == Config::PRICE) {
                                // If the category of the task is not used and is hourly for count we set value to 0
                                if ($data["is_usedforcount"] == 0
                                    && $config->fields['hourorday'] == Config::HOUR) {
                                    $un_temps_passe[4] = 0;
                                } else {
                                    $un_temps_passe[4] = round($data["tps_passes"], 2,PHP_ROUND_HALF_UP);
                                }
                            } else {
                                // If the category of the task is not used and is hourly for count we set value to 0
                                if ($data["is_usedforcount"] == 0 && $config->fields['hourorday'] == Config::HOUR) {
                                    $un_temps_passe[4] = 0;
                                } else {
                                    $un_temps_passe[4] = $PDF->TotalTpsPassesArrondis(round($data["tps_passes"], 2,PHP_ROUND_HALF_UP));
                                } //arrondir au quart
                            }
                        }
                        if ($data["date_debut"] == null && $data["date_fin"] == null) {
                            $un_temps_passe[0] = substr($data["date"], 8, 2) . "/" . substr(
                                $data["date"],
                                5,
                                2
                            ) . "/" . substr($data["date"], 0, 4);
                            $un_temps_passe[1] = ($data["date"] == "-") ? "-" : substr(
                                $data["date"],
                                11,
                                2
                            ) . ":" . substr($data["date"], 14, 2);
                            //calculating the end date
                            if ($config->fields['hourorday'] == Config::HOUR) {
                                $date = date(
                                    'Y-m-d H:i:s',
                                    strtotime($data["date"] . " + " . (($un_temps_passe[4]) * 3600) . " seconds")
                                );
                            } else {
                                //daily
                                $date = date(
                                    'Y-m-d H:i:s',
                                    strtotime($data["date"] . " + " . $un_temps_passe[4] * $config->fields['hourbyday'] ?? '' . " hours")
                                );
                            }

                            $un_temps_passe[2] = substr($date, 8, 2) . "/" . substr($date, 5, 2) . "/" . substr(
                                $date,
                                0,
                                4
                            );
                            $un_temps_passe[3] = ($date == "-") ? "-" : substr($date, 11, 2) . ":" . substr(
                                $date,
                                14,
                                2
                            );
                        } else {
                            $un_temps_passe[0] = substr($data["date_debut"], 8, 2) . "/" . substr(
                                $data["date_debut"],
                                5,
                                2
                            ) . "/" . substr($data["date_debut"], 0, 4);
                            $un_temps_passe[1] = ($data["heure_debut"] == "-") ? "-" : substr(
                                $data["heure_debut"],
                                11,
                                2
                            ) . ":" . substr($data["heure_debut"], 14, 2);
                            $un_temps_passe[2] = substr($data["date_fin"], 8, 2) . "/" . substr(
                                $data["date_fin"],
                                5,
                                2
                            ) . "/" . substr($data["date_fin"], 0, 4);
                            $un_temps_passe[3] = ($data["heure_fin"] == "-") ? "-" : substr(
                                $data["heure_fin"],
                                11,
                                2
                            ) . ":" . substr($data["heure_fin"], 14, 2);
                        }

                        $temps_passes[$cpt_tps] = $un_temps_passe;


                        if ($config->fields['useprice'] == Config::NOPRICE || $config->fields['hourorday'] == Config::HOUR) {
                            $p['REPORT_ACTIVITE'][$cpt_tps] = \Dropdown::getDropdownName(
                                'glpi_taskcategories',
                                $data['taskcat']
                            );
                        }

                        $cpt_tps++;
                    }

                    $PDF->SetLibelleActivite($p['REPORT_ACTIVITE']);
                    $PDF->SetTempsPasses($temps_passes);
                    // Forfait
                } else {
                    $PDF->SetForfait();
                    $un_temps_passe[0] = Html::convDate($contract_days->fields['begin_date'] ?? '');
                    $un_temps_passe[1] = '';
                    $un_temps_passe[2] = Html::convDate($contract_days->fields['end_date'] ?? '');
                    $un_temps_passe[3] = '';
                    $un_temps_passe[4] = $PDF->TotalTpsPassesArrondis(round($contract_days->fields['nbday'] ?? '', 2,PHP_ROUND_HALF_UP));
                    $temps_passes[] = $un_temps_passe;

                    if ($config->fields['useprice'] == Config::NOPRICE) {
                        $tickettasks = new \TicketTask();
                        $tasks_data = $tickettasks->find(['tickets_id' => $p['REPORT_ID']]);
                        $tasks_data = array_shift($tasks_data);
                        $p['REPORT_ACTIVITE'][] = \Dropdown::getDropdownName(
                            'glpi_taskcategories',
                            $tasks_data['taskcategories_id']
                        );
                    }
                    $PDF->SetLibelleActivite($p['REPORT_ACTIVITE']);

                    $PDF->SetTempsPasses($temps_passes);
                }

                //Déplacement
                if ($manageentities_contract_data['moving_management']) {
                    $PDF->SetDeplacement(true);
                    if ($config->fields['hourorday'] == Config::HOUR) {
                        $time_in_sec = $manageentities_contract_data['duration_moving'];
                        $time_deplacement = ($time_in_sec * $p['number_moving']) / HOUR_TIMESTAMP;
                        $PDF->SetNombreDeplacement($time_deplacement);
                    } else {
                        $time_in_sec = $manageentities_contract_data['duration_moving'];
                        $time_deplacement = (($time_in_sec * $p['number_moving'] / HOUR_TIMESTAMP) / $config->fields['hourbyday'] ?? '');
                        $PDF->SetNombreDeplacement($PDF->TotalTpsPassesArrondis($time_deplacement));
                    }
                }
            }
        }

        // On dessine le document.
        $PDF->DrawCri();

        //for insert into table cridetails
        $totaltemps_passes = $PDF->TotalTpsPassesArrondis($_SESSION["glpi_plugin_manageentities_total"]);

        /* Génération du fichier et enregistrement de la liaisons en base. */

        $name = "CRI - " . $PDF->GetNoCri();
        $filename = $name . ".pdf";
        $savepath = GLPI_TMP_DIR . "/";
        $seepath = GLPI_PLUGIN_DOC_DIR . "/manageentities/";
        $savefilepath = $savepath . $filename;
        $seefilepath = $seepath . $filename;

        if ($config->fields["backup"] == 1 && $p['enregistrement']) {
            $PDF->Output($savefilepath, 'F');

            $input = [];
            $input["entities_id"] = $job->fields["entities_id"];
            $input["name"] = addslashes($name);
            $input["filename"] = addslashes($filename);
            $input["_filename"][0] = addslashes($filename);
            $input["upload_file"] = $filename;
            $input["documentcategories_id"] = $config->fields["documentcategories_id"];
            $input["mime"] = "application/pdf";
            $input["date_mod"] = date("Y-m-d H:i:s");
            $input["users_id"] = Session::getLoginUserID();
            $input["tickets_id"] = $p['REPORT_ID'];

            $doc = new Document();
            if (empty($p['documents_id'])) {
                $newdoc = $doc->add($input);
            } else {
                $doc->getFromDB($p['documents_id']);
                $input['current_filepath'] = $filename;
                $input['id'] = $p['documents_id'];
                $newdoc = $p['documents_id'];

                // If update worked, delete old file and directory
                $doc->update($input);
            }

            $withcontract = 0;
            if ($sous_contrat == true) {
                $withcontract = 1;
            }

            $values = [];
            $values["entities_id"] = $job->fields["entities_id"];
            $values["date"] = $infos_date[2];
            $values["documents_id"] = $newdoc;
            $values["plugin_manageentities_critypes_id"] = $criType_id;
            $values["withcontract"] = $withcontract;
            $values["contracts_id"] = $p['CONTRAT'];
            $values["realtime"] = $totaltemps_passes;
            $values["technicians"] = $intervenants;
            $values["tickets_id"] = $p['REPORT_ID'];
            $values["number_moving"] = $p['number_moving'];

            $restrict = [
                "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $job->fields['entities_id'] ?? '',
                "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $job->fields['id'] ?? '',
            ];
            $dbu = new DbUtils();
            $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
            $cridetail = reset($cridetails);

            $CriDetail = new CriDetail();
            if (empty($cridetail)) {
                $newID = $CriDetail->add($values);
            } else {
                $values["id"] = $cridetail['id'];
                $CriDetail->update($values);
            }

            //         if(isset($p['download']) && $p['download'] == 1){
            //            echo "<IFRAME style='width:100%;height:90%' src='" . PLUGIN_MANAGEENTITIES_WEBDIR . "/front/cri.send.php?file=_plugins/manageentities/$filename&seefile=1' scrolling=none frameborder=1></IFRAME>";

            //         $doc = new Document();
            //         $doc->getFromDB( $values["documents_id"]);
            //         $this->send($doc);
            //         }

            $this->CleanFiles($seepath);
        } else {
            //Sauvegarde du PDF dans le fichier
            $PDF->Output($seefilepath, 'F');

            if ($config->fields["backup"] == 1) {
                echo "<form method='post' name='formReport'>";
                echo Html::hidden('REPORT_ID', ['value' => $p['REPORT_ID']]);
                echo Html::hidden('REPORT_SOUS_CONTRAT', ['value' => $sous_contrat]);

                if ($config->fields['hourorday'] == Config::HOUR) {
                    echo Html::hidden('REPORT_ACTIVITE', ['value' => 'hour']);
                } elseif ($config->fields['useprice'] == Config::PRICE) {
                    echo Html::hidden('REPORT_ACTIVITE', ['value' => $p['REPORT_ACTIVITE']]);
                } else {
                    echo Html::hidden('REPORT_ACTIVITE', ['value' => 'noprice']);
                }
                $p['REPORT_DESCRIPTION'] = stripcslashes($p['REPORT_DESCRIPTION']);
                $p['REPORT_DESCRIPTION'] = str_replace("\\\\", "\\", $p['REPORT_DESCRIPTION']);
                $p['REPORT_DESCRIPTION'] = str_replace("\\'", "'", $p['REPORT_DESCRIPTION']);
                $p['REPORT_DESCRIPTION'] = str_replace("<br>", " ", $p['REPORT_DESCRIPTION']);
                $p['REPORT_DESCRIPTION'] = str_replace("’", "'", $p['REPORT_DESCRIPTION']);

                Html::textarea([
                    'name' => 'REPORT_DESCRIPTION',
                    'value' => $p['REPORT_DESCRIPTION'],
                    'display' => 'none',
                    'cols' => 100,
                    'rows' => 8,
                    'enable_richtext' => true,
                    'enable_fileupload' => false,
                    'enable_images' => false,
                ]);
                echo Html::hidden('INTERVENANTS', ['value' => $intervenants]);
                echo Html::hidden('documents_id', ['value' => $p['documents_id']]);
                echo Html::hidden('CONTRAT', ['value' => $p['CONTRAT']]);
                echo Html::hidden('CONTRACTDAY', ['value' => $p['CONTRACTDAY']]);
                echo Html::hidden('WITHOUTCONTRACT', ['value' => $p['WITHOUTCONTRACT']]);
                echo Html::hidden('number_moving', ['value' => $p['number_moving']]);
                echo Html::hidden('REPORT_ACTIVITE_ID', ['value' => $p['REPORT_ACTIVITE_ID']]);

                $params = [
                    'job' => $job->fields['id'] ?? '',
                    'form' => 'formReport',
                    'root_doc' => PLUGIN_MANAGEENTITIES_WEBDIR,
                    'toupdate' => $options['toupdate'],
                ];
                echo "<p><input type='button' name='save_cri' value=\""
                    . __('Save the intervention report', 'manageentities') . "\" class='submit btn btn-primary manageentities_button'
                 onClick='manageentities_loadCriForm(\"saveCri\", \"" . $options['modal'] . "\", " . json_encode(
                        $params
                    ) . ");'></p>";

                echo "<IFRAME style='width:500px;height:700px' src='" . PLUGIN_MANAGEENTITIES_WEBDIR . "/front/cri.send.php?file=_plugins/manageentities/$filename' scrolling=none frameborder=1></IFRAME>";
                Html::closeForm();
            }


            //         if(empty($p['documents_id'])){
            //         echo "<IFRAME src='".PLUGIN_MANAGEENTITIES_WEBDIR."/front/cri.send.php?file=_plugins/manageentities/$filename&seefile=1' width='1000' height='1000' scrolling=auto frameborder=1></IFRAME>";
            //         } else {
            //            echo "<IFRAME src='".$CFG_GLPI['root_doc']."/front/document.send.php?docid=$p['documents_id']&tickets_id=$p['REPORT_ID']' width='1000' height='1000' scrolling=auto frameborder=1></IFRAME>";
            //         }
        }
    }

    /**
     * Request: task with time spent
     *
     * @param type $join
     * @param type $where
     * @param type $p
     * @param type $config
     *
     * @global type $DB
     *
     */
    public function getTempsPasses($p)
    {
        global $DB;

        $config = Config::getInstance();

        //configuration by day
        if ($config->fields['hourorday'] == Config::DAY) {
            $nbhour = $config->fields["hourbyday"];
            $condition = "";
        } else {
            //configuration by hour
            $nbhour = 1;
        }

        $criteria1 = [
            'SELECT' => [
                'glpi_tickettasks.id',
                'glpi_tickettasks.taskcategories_id AS taskcat',
                'glpi_tickettasks.date AS date',
                'glpi_tickettasks.begin AS date_debut',
                'glpi_tickettasks.end AS date_fin',
                'glpi_tickettasks.begin AS heure_debut',
                'glpi_tickettasks.end AS heure_fin',
                'glpi_tickettasks.actiontime as actiontime',
                new QueryExpression(
                    "(glpi_tickettasks.actiontime/3600)/" . $DB->quoteValue($nbhour) . " AS " . $DB->quoteName(
                        'tps_passes'
                    )
                ),
                'glpi_plugin_manageentities_taskcategories.is_usedforcount as is_usedforcount',
            ],
            'FROM' => 'glpi_tickettasks',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_taskcategories' => [
                    'ON' => [
                        'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                        'glpi_tickettasks' => 'taskcategories_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
            ],
        ];
        if ($config->fields['use_publictask'] == '1') {
            $criteria1['WHERE'] = $criteria1['WHERE'] + ['is_private' => 0];
        }

        if ($config->fields['hourorday'] == Config::HOUR) {
            $criteria1['WHERE'] = $criteria1['WHERE'] + ['glpi_plugin_manageentities_taskcategories.is_usedforcount' => 1];
        }

        $queries[] = $criteria1;

        $criteria2 = [
            'SELECT' => [
                'glpi_tickettasks.id',
                'glpi_tickettasks.taskcategories_id AS taskcat',
                'glpi_tickettasks.date AS date',
                'glpi_tickettasks.begin AS date_debut',
                'glpi_tickettasks.end AS date_fin',
                'glpi_tickettasks.begin AS heure_debut',
                'glpi_tickettasks.end AS heure_fin',
                'glpi_tickettasks.actiontime as actiontime',
                new QueryExpression(
                    "(glpi_tickettasks.actiontime/3600)/" . $DB->quoteValue($nbhour) . " AS " . $DB->quoteName(
                        'tps_passes'
                    )
                ),
                'glpi_plugin_manageentities_taskcategories.is_usedforcount as is_usedforcount',
            ],
            'FROM' => 'glpi_tickettasks',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_taskcategories' => [
                    'ON' => [
                        'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                        'glpi_tickettasks' => 'taskcategories_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_tickettasks.tickets_id' => $p['REPORT_ID'],
                'NOT' => [
                    'glpi_tickettasks.id' => new QuerySubQuery([
                        'SELECT' => 'id',
                        'DISTINCT' => true,
                        'FROM' => 'glpi_tickettasks',
                    ]),
                ],
            ],
            'ORDERBY' => 'date_debut ASC',
        ];
        if ($config->fields['use_publictask'] == '1') {
            $criteria2['WHERE'] = $criteria2['WHERE'] + ['is_private' => 0];
        }

        $queries[] = $criteria2;

        $criteria = [
            'SELECT' => [
                'taskcat',
                'date',
                'date_debut',
                'date_fin',
                'heure_debut',
                'heure_fin',
                'actiontime',
                'tps_passes',
                'is_usedforcount',
            ],
            'FROM' => new QueryUnion($queries),
        ];

        $iterator = $DB->request($criteria);

        return $iterator;
    }

    public function CleanFiles($dir)
    {
        //Efface les fichiers temporaires
        $t = time();
        $h = opendir($dir);
        while ($file = readdir($h)) {
            if (substr($file, 0, 3) == 'CRI' and substr($file, -4) == '.pdf') {
                $path = $dir . '/' . $file;
                //if ($t-filemtime($path)>3600)
                @unlink($path);
            }
        }
        closedir($h);
    }

    public function send($doc)
    {
        $file = GLPI_DOC_DIR . "/" . $doc->fields['filepath'] ?? '';

        if (!file_exists($file)) {
            die("Error file " . $file . " does not exist");
        }
        // Now send the file with header() magic
        header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
        header('Pragma: private'); /// IE BUG + SSL
        header('Cache-control: private, must-revalidate'); /// IE BUG + SSL
        header("Content-disposition: filename=\"" . $doc->fields['filename'] ?? '' . "\"");
        header("Content-type: " . $doc->fields['mime'] ?? '');

        readfile($file) or die("Error opening file $file");
    }

}
