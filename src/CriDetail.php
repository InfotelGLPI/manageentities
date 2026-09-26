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

use Ajax;
use CommonDBTM;
use CommonGLPI;
use DBConnection;
use DbUtils;
use Document;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\RichText\RichText;
use Html;
use Migration;
use Planning;
use Session;
use Ticket;
use Toolbox;

class CriDetail extends CommonDBTM
{
    public static $rightname = "plugin_manageentities";

    /**
     * on_change of the contract dropdowns: select2 only fires jQuery events, relayed here as a
     * native one for public/scripts/cridetail-contract.js
     */
    public const CHANGE_EVENT_JS = "this.dispatchEvent(new Event('manageentities:change', {bubbles: true}));";

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
            $config    = Config::getInstance();
            $parent_id = (int) ($config->fields['wizard_default_entities_id'] ?? 0);
            if ($parent_id > 0) {
                $sons = getSonsOf('glpi_entities', $parent_id);
                if (!in_array((int) $item->fields['entities_id'], $sons)) {
                    return '';
                }
            }
            return self::createTabEntry(Cri::getTypeName(1));
        } elseif ($item->getType() == ContractDay::class) {
            return self::createTabEntry(__('Linked interventions', 'manageentities'), self::countForContract($item));
        } elseif ($item->getType() == Config::class) {
            return self::createTabEntry(__('CRI generation', 'manageentities'), 0, $item->getType(), self::getIcon());
        }
        return '';
    }

    public static function countForContract($item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_cridetails',
            ["`plugin_manageentities_contractdays_id`" => $item->getID()],
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // Security (authorization bypass): the plugin right plugin_manageentities_cri_create in
        // READ and the entity scope (the sons of wizard_default_entities_id) were evaluated in
        // getTabNameForItem() alone, that is, in the tab menu builder - the function that
        // decides whether the tab is OFFERED. ajax/common.tabs.php only replays
        // can($_GET['id'], READ) on the HOST item (the ticket) and
        // CommonGLPI::displayStandardTab() forwards the received _glpi_tab verbatim, without
        // ever checking that it was part of the menu, so the content of this tab - the service
        // contracts of the entity, the contract periods, the editor subscription - was
        // reachable with a forged tab name by anyone allowed to read any ticket.
        // Asking the menu builder itself replays every per-itemtype condition exactly once and
        // cannot drift from it; same guard as Entity::displayTabContentForItem().
        $tabs = (new self())->getTabNameForItem($item, $withtemplate);
        $offered = is_array($tabs) ? isset($tabs[(int) $tabnum]) : (string) $tabs !== '';
        if (!$offered) {
            throw new AccessDeniedHttpException();
        }

        if ($item->getType() == 'Ticket') {
            if (Session::getCurrentInterface() == 'central') {
                // Billing information: the customer still has interventions to be billed
                if ($item instanceof Ticket) {
                    echo DirectHelpdesk::getUnbilledAlert((int) $item->fields['entities_id']);
                }
                self::showForTicket($item);
            }
            self::showReports($item, $item->getField('id'));
        } elseif ($item->getType() == ContractDay::class) {
            echo self::showForContractDay($item);
        } elseif ($item->getType() == Config::class) {
            self::showCriForm($item);
        }
        return true;
    }

    public static function showCriForm(Config $config): void
    {
        TemplateRenderer::getInstance()->display(
            '@manageentities/config_cri_form.html.twig',
            [
                'form_url'        => Toolbox::getItemTypeFormURL(Config::class),
                'config'          => $config->fields,
                'ticket_statuses' => Ticket::getAllStatusArray(),
            ],
        );
    }

    /**
     * Tell whether a contract may be attached to a ticket.
     *
     * Replay of the criterion of showContractLinkDropdown(): the contract is only offered when a
     * plugin Contract row declares it for the entity of the ticket. Rebuilding the rule here
     * (comparing entities by hand) would diverge from the dropdown, so the very same lookup is
     * performed instead. Defense in depth only: the controllers already validate the pair, this
     * makes sure no other writer can bind a ticket to a contract of another entity.
     *
     * @param int $tickets_id   the ticket the report line belongs to
     * @param int $contracts_id the core contract identifier
     *
     * @return bool true when there is nothing to attach, or when the pair is legitimate
     */
    public static function isContractAllowedForTicket(int $tickets_id, int $contracts_id): bool
    {
        if ($tickets_id <= 0) {
            // Legacy rows carry tickets_id = 0 until the document-based repair in hook.php
            // fills it in; there is then no ticket to validate the pair against. That is only
            // acceptable while no contract is attached either, otherwise the whole check
            // below could be skipped by simply omitting the ticket from the payload.
            return $contracts_id <= 0;
        }

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        // The early return on contracts_id used to sit before the lookup above, so the
        // "withcontract = 0" path - the one the form takes by default - validated nothing at
        // all and a report line pointing at a ticket that does not exist, or no longer does,
        // was written without a word. The contract/entity comparison itself is still only
        // meaningful when a contract is actually being attached.
        if ($contracts_id <= 0) {
            return true;
        }

        $dbu = new DbUtils();

        return $dbu->countElementsInTable(
            Contract::getTable(),
            [
                'contracts_id' => $contracts_id,
                'entities_id'  => (int) $ticket->fields['entities_id'],
            ],
        ) > 0;
    }

    public function prepareInputForUpdate($input)
    {
        // A detail linked to a report document cannot be updated from the ticket form (showForTicket())
        if (isset($input['updatecridetail'])) {
            $criDetail = new CriDetail();
            $criDetail->getFromDB($input['id']);

            if ($criDetail->fields['documents_id'] != 0 && $criDetail->fields['contracts_id'] != $input['contracts_id']) {
                Session::addMessageAfterRedirect(
                    __('Impossible action as an intervention report exists', 'manageentities'),
                    ERROR,
                    true,
                );
                return false;
            }
        }

        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        if (!$this->checkContractPair($input, $this->fields)) {
            return false;
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        if (!$this->checkContractPair($input, [])) {
            return false;
        }

        return $input;
    }

    /**
     * Refuse a report line whose contract does not belong to the entity of its ticket.
     *
     * @param array<string, mixed> $input   the submitted values
     * @param array<string, mixed> $current the values already stored, for a partial update
     *
     * @return bool
     */
    private function checkContractPair(array $input, array $current): bool
    {
        $tickets_id   = (int) ($input['tickets_id']   ?? $current['tickets_id']   ?? 0);
        $contracts_id = (int) ($input['contracts_id'] ?? $current['contracts_id'] ?? 0);

        if (self::isContractAllowedForTicket($tickets_id, $contracts_id)) {
            return true;
        }

        Session::addMessageAfterRedirect(
            htmlescape(__('This contract is not available for the entity of the ticket', 'manageentities')),
            true,
            ERROR,
        );

        return false;
    }

    public function post_addItem()
    {
        $tickets_id   = (int) ($this->input['tickets_id']   ?? $this->fields['tickets_id']   ?? 0);
        $contracts_id = (int) ($this->input['contracts_id'] ?? $this->fields['contracts_id'] ?? 0);

        $this->linkContractToTicket($tickets_id, $contracts_id);
        Contract::updateRemainingDays($contracts_id);
    }

    public function post_updateItem($history = true)
    {
        $tickets_id   = (int) ($this->fields['tickets_id']   ?? 0);
        $contracts_id = (int) ($this->fields['contracts_id'] ?? 0);

        $this->linkContractToTicket($tickets_id, $contracts_id);
        Contract::updateRemainingDays($contracts_id);
    }

    public function post_deleteItem()
    {
        Contract::updateRemainingDays((int) ($this->fields['contracts_id'] ?? 0));
    }

    private function linkContractToTicket(int $tickets_id, int $contracts_id): void
    {
        global $DB;

        if ($tickets_id <= 0 || $contracts_id <= 0) {
            return;
        }

        // Last line of defense before a raw insert into a core relation table: the pair has
        // already been validated by the controller and by prepareInputForAdd()/Update(), but
        // this method is the one that actually binds a ticket to a contract.
        if (!self::isContractAllowedForTicket($tickets_id, $contracts_id)) {
            return;
        }

        $exists = $DB->request([
            'COUNT'  => 'id',
            'FROM'   => 'glpi_tickets_contracts',
            'WHERE'  => [
                'tickets_id'   => $tickets_id,
                'contracts_id' => $contracts_id,
            ],
        ])->current()['id'] ?? 0;

        if (!$exists) {
            $DB->insert('glpi_tickets_contracts', [
                'tickets_id'   => $tickets_id,
                'contracts_id' => $contracts_id,
            ]);
        }
    }

    public static function autoLinkTicketToActiveContractDay(\Ticket_Contract $item): void
    {
        global $DB;

        $tickets_id   = (int) ($item->fields['tickets_id']   ?? 0);
        $contracts_id = (int) ($item->fields['contracts_id'] ?? 0);

        if ($tickets_id <= 0 || $contracts_id <= 0) {
            return;
        }

        $already_linked = $DB->request([
            'COUNT' => 'id',
            'FROM'  => 'glpi_plugin_manageentities_cridetails',
            'WHERE' => [
                'tickets_id'   => $tickets_id,
                'contracts_id' => $contracts_id,
            ],
        ])->current()['id'] ?? 0;

        if ($already_linked) {
            return;
        }

        $iterator = $DB->request([
            'SELECT'     => ['glpi_plugin_manageentities_contractdays.id'],
            'FROM'       => 'glpi_plugin_manageentities_contractdays',
            'INNER JOIN' => [
                'glpi_plugin_manageentities_contractstates' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contractdays'   => 'plugin_manageentities_contractstates_id',
                        'glpi_plugin_manageentities_contractstates' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_manageentities_contractdays.contracts_id' => $contracts_id,
                'glpi_plugin_manageentities_contractstates.is_active'  => 1,
            ],
        ]);

        if (count($iterator) !== 1) {
            return;
        }

        $contractday_id = (int) $iterator->current()['id'];

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }

        $cridetail = new self();
        $cridetail->add([
            'entities_id' => $ticket->fields['entities_id'],
            'tickets_id' => $tickets_id,
            'contracts_id' => $contracts_id,
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'withcontract' => 1,
        ]);
    }

    public function pre_deleteItem()
    {
        // A detail linked to a report document cannot be deleted from the ticket form (showForTicket())
        if (isset($this->input['delcridetail'])) {
            if ($this->fields['documents_id'] != '0') {
                Session::addMessageAfterRedirect(
                    __('Impossible action as an intervention report exists', 'manageentities'),
                    ERROR,
                    true,
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
                        'glpi_tickets'   => 'id',
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

            ],
            'GROUPBY' => ['glpi_documents.tickets_id'],
            'ORDERBY' => [$this->getTable() . '.date ASC'],
        ];

        $criteria['WHERE'][] = [$this->getTable() . '.date' => ['>=', $date1]];
        $criteria['WHERE'][] = [$this->getTable() . '.date' => ['<=', $date2]];

        if ($usertype != "group") {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                'OR' => [
                    'glpi_tickets_users.users_id' => $technum,
                    'glpi_plugin_manageentities_critechnicians.users_id' => $technum,
                ],
            ];
        }
        $criteria['WHERE'] = $criteria['WHERE'] + getEntitiesRestrictCriteria(
            'glpi_documents',
        );

        $iterator = $DB->request($criteria);

        $use_price = $config->fields['useprice'] == Config::PRICE;
        $is_tree   = Session::isMultiEntitiesMode();

        $columns    = [];
        $formatters = [];
        $entries    = [];

        if ($is_tree) {
            $columns['entity'] = _n('Entity', 'Entities', 1);
        }
        $columns['date']        = __('Date');
        $columns['technicians'] = __('Technicians', 'manageentities');
        if ($use_price) {
            $columns['critype'] = CriType::getTypeName(1);
        }
        $columns['realtime']     = __('Crossed time (itinerary including)', 'manageentities');
        $columns['withcontract'] = __('Intervention with contract', 'manageentities');
        $columns['contract_num'] = __('Contract number', 'manageentities');
        $columns['ticket']       = __('Associated ticket', 'manageentities');
        $columns['name']         = __('Name');
        $columns['file']         = __('File');

        // Columns holding pre-built HTML links must not be re-escaped by the datatable.
        $formatters['ticket'] = 'raw_html';
        $formatters['name']   = 'raw_html';
        $formatters['file']   = 'raw_html';

        foreach ($iterator as $data) {
            $num_contract = '';
            if ($data["withcontract"]) {
                $contract = new \Contract();
                $contract->getFromDB($data["contracts_id"]);
                $num_contract = $contract->fields["num"] ?? '';
            }

            $ticket_html = '';
            if ($data["tickets_id"] > 0) {
                $ticket_html = "<a href='" . $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id=" . (int) $data["tickets_id"] . "'>"
                    . (int) $data["tickets_id"] . "</a>";
            }

            $name_html = "<a href='" . $CFG_GLPI["root_doc"] . "/front/document.form.php?id=" . (int) $data["id"] . "'>"
                . "<strong>" . htmlspecialchars((string) $data["name"], ENT_QUOTES)
                . ($_SESSION["glpiis_ids_visible"] ? " (" . (int) $data["id"] . ")" : "")
                . "</strong></a>";

            $doc = new Document();
            $doc->getFromDB($data["id"]);

            $entry = [
                'date'         => Html::convdate($data["date"]),
                'technicians'  => $data["technicians"] ?? '',
                'realtime'     => $data["realtime"],
                'withcontract' => \Dropdown::getYesNo($data["withcontract"]),
                'contract_num' => $num_contract,
                'ticket'       => $ticket_html,
                'name'         => $name_html,
                'file'         => $doc->getDownloadLink(),
            ];
            if ($is_tree) {
                $entry['entity'] = \Dropdown::getDropdownName("glpi_entities", $data['entity']);
            }
            if ($use_price) {
                $entry['critype'] = \Dropdown::getDropdownName(
                    "glpi_plugin_manageentities_critypes",
                    $data['plugin_manageentities_critypes_id'],
                );
            }
            $entries[] = $entry;
        }

        $title = Cri::getTypeName(2);
        if ($usertype != "group") {
            $title .= ' - ' . $dbu->getusername($technum);
        }
        $title .= ' ' . sprintf(__('From %1$s to %2$s :'), Html::convdate($date1), Html::convdate($date2));

        TemplateRenderer::getInstance()->display('@manageentities/helpdesk_reports.html.twig', [
            'title'      => $title,
            'columns'    => $columns,
            'formatters' => $formatters,
            'entries'    => $entries,
        ]);
    }

    /**
     * @param Ticket $ticket
     * @param array $options
     */
    public static function addReports(Ticket $ticket, $options = [])
    {

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
            "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $ticket->fields['entities_id'],
            "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $ticket->fields['id'],
        ];
        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
        $cridetail = reset($cridetails);

        $generation_ok = false;
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE,
        ) && (empty($cridetail) || ($cridetail['documents_id'] ?? 0) == 0) && !empty($cridetail['contracts_id']) && !empty($cridetail['plugin_manageentities_contractdays_id'])) {
            $generation_ok = true;
        }
        //switch withoutcontract
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE,
        ) && (empty($cridetail) || ($cridetail['documents_id'] ?? 0) == 0) && (isset($cridetail['withcontract']) ? !$cridetail['withcontract'] : true)) {
            $generation_ok = true;
        }

        $regeneration_ok = false;
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE,
        ) && (!empty($cridetail) || ($cridetail['documents_id'] ?? 0) != 0) && !empty($cridetail['contracts_id']) && !empty($cridetail['plugin_manageentities_contractdays_id'])) {
            $regeneration_ok = true;
        }
        //switch withoutcontract
        if (Session::haveRight(
            "plugin_manageentities_cri_create",
            UPDATE,
        ) && (!empty($cridetail) || ($cridetail['documents_id'] ?? 0) != 0) && (isset($cridetail['withcontract']) ? !$cridetail['withcontract'] : true)) {
            $regeneration_ok = true;
        }

        // GENERATE / REGENERATE labels
        $pdf_action  = '';
        $title       = '';
        $show_action = $generation_ok || $regeneration_ok;
        if ($generation_ok) {
            $title = __('Generation of the intervention report', 'manageentities');
        } elseif ($regeneration_ok) {
            $title      = __('Regenerate the intervention report', 'manageentities');
            $pdf_action = 'update_cri';
        }

        $params = [];
        if ($show_action) {
            Html::requireJs('glpi_dialog');
            $params = [
                'pdf_action' => $pdf_action,
                'job'        => $ticket->fields['id'],
                'root_doc'   => PLUGIN_MANAGEENTITIES_WEBDIR,
                'toupdate'   => "showCriDetail$rand",
                'width'      => 1000,
                'height'     => 550,
            ];
        }

        $show_delete = Session::haveRight("plugin_manageentities_cri_create", UPDATE)
                       && ($cridetail['documents_id'] ?? 0) != 0;

        TemplateRenderer::getInstance()->display('@manageentities/cridetail_add_reports.html.twig', [
            'wrapper_id'   => $options['toupdate'] ?? "showCriDetail$rand",
            'show_wrapper' => $generation_ok,
            'show_action'  => $show_action,
            'title'        => $title,
            'modal'        => $modal,
            'params'       => $params,
            'rand'         => $rand,
            'show_delete'  => $show_delete,
            'delete_url'   => Toolbox::getItemTypeFormURL(Cri::class),
            'documents_id' => $cridetail['documents_id'] ?? 0,
        ]);
    }

    //shows CRI from ticket or from entity portal
    public static function showReports($item, $instID, $entity = -1, $condition = [])
    {
        global $DB, $CFG_GLPI;

        $config = new Config();
        $ticket = new Ticket();
        $ticket->getFromDB($instID);

        if ($config->getFromDB(1)) {
            $is_tree = is_array($entity) && count($entity) > 1;

            $criteria = [
                'SELECT' => [
                    'glpi_documents.*',
                    'glpi_plugin_manageentities_cridetails.date',
                    'glpi_plugin_manageentities_cridetails.technicians',
                    'glpi_plugin_manageentities_cridetails.plugin_manageentities_critypes_id',
                    'glpi_plugin_manageentities_cridetails.withcontract',
                    'glpi_plugin_manageentities_cridetails.contracts_id',
                    'glpi_plugin_manageentities_cridetails.realtime',
                    'glpi_entities.name AS entity_name',
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
                    'glpi_entities' => [
                        'ON' => [
                            'glpi_documents' => 'entities_id',
                            'glpi_entities'  => 'id',
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
            }
            if ($entity != -1) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_documents.entities_id' => $entity];
            }
            if ($instID > 0) {
                $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_documents.tickets_id' => $instID];
            }

            $iterator = $DB->request($criteria);

            $use_price = ($config->fields['useprice'] == Config::PRICE);
            $can_read_doc = Session::haveRight("document", READ);

            $entries    = [];
            $columns    = [];
            $formatters = [];

            $columns['date']         = __('Date');
            $columns['technicians']  = __('Technicians', 'manageentities');
            if ($use_price) {
                $columns['critype'] = CriType::getTypeName(1);
            }
            $columns['realtime']     = __('Crossed time (itinerary including)', 'manageentities');
            $columns['withcontract'] = __('Intervention with contract', 'manageentities');
            if ($is_tree) {
                $columns['entity'] = _n('Entity', 'Entities', 1);
            }
            $columns['contract_num'] = __('Contract number', 'manageentities');
            $columns['name']         = __('Name');
            $columns['file']         = __('File');
            $formatters['name'] = 'raw_html';
            $formatters['file'] = 'raw_html';

            foreach ($iterator as $data) {
                $name_html = $can_read_doc
                    ? "<a href='" . $CFG_GLPI["root_doc"] . "/front/document.form.php?id=" . (int) $data["id"] . "'>"
                      . "<strong>" . htmlspecialchars($data["name"], ENT_QUOTES)
                      . ($_SESSION["glpiis_ids_visible"] ? " (" . (int) $data["id"] . ")" : "")
                      . "</strong></a>"
                    : "<strong>" . htmlspecialchars($data["name"], ENT_QUOTES) . "</strong>";

                $doc = new Document();
                $doc->getFromDB($data["id"]);

                $num_contract = '';
                if ($data["withcontract"]) {
                    $contract_obj = new \Contract();
                    $contract_obj->getFromDB($data["contracts_id"]);
                    $num_contract = htmlspecialchars($contract_obj->fields["num"] ?? '', ENT_QUOTES);
                }

                $entry = [
                    'date'         => Html::convdate($data["date"]),
                    'technicians'  => htmlspecialchars($data["technicians"] ?? '', ENT_QUOTES),
                    'realtime'     => Html::formatNumber($data["realtime"], 0, 2),
                    'withcontract' => \Dropdown::getYesNo($data["withcontract"]),
                    'entity'       => htmlspecialchars($data["entity_name"] ?? '', ENT_QUOTES),
                    'contract_num' => $num_contract,
                    'name'         => $name_html,
                    'file'         => $doc->getDownloadLink(),
                ];
                if ($use_price) {
                    $entry['critype'] = \Dropdown::getDropdownName(
                        "glpi_plugin_manageentities_critypes",
                        $data['plugin_manageentities_critypes_id'],
                    );
                }
                $entries[] = $entry;
            }

            ob_start();
            if ($entity == -1) {
                self::addReports($item);
            }
            $add_reports_html = ob_get_clean();

            $all_reports_url = $can_read_doc
                ? $CFG_GLPI["root_doc"] . "/front/document.php?" . http_build_query([
                    'criteria' => [
                        ['field' => 1, 'searchtype' => 'contains', 'value' => 'cri'],
                    ],
                    'start' => 0,
                ])
                : '';

            TemplateRenderer::getInstance()->display('@manageentities/cri_reports.html.twig', [
                'entries'          => $entries,
                'columns'          => $columns,
                'formatters'       => $formatters,
                'all_reports_url'  => $all_reports_url,
                'add_reports_html' => $add_reports_html,
            ]);
        }
    }

    //shows CRI from ticket or from entity portal
    public static function showPeriod($item, $instID, $entity = -1, $options = [])
    {
        global $DB;

        $config = Config::getInstance();
        $is_day = $config->fields['hourorday'] == Config::DAY;
        $is_central = Session::getCurrentInterface() == 'central';

        // The periods used to be group rows spanning the whole table; the datatable
        // component has no such row, so the period a line belongs to is carried by a
        // column of its own. The contract stays the first level of grouping: one card
        // and one table per contract.
        $columns = [
            'period' => __('Periods of contract', 'manageentities'),
            'date'   => __('Date'),
            'object' => __('Object of intervention', 'manageentities'),
            'type'   => CriType::getTypeName(1),
            'file'   => __('File'),
            'conso'  => __('Crossed time (itinerary including)', 'manageentities'),
            'tech'   => __('Technicians', 'manageentities'),
            'rate'   => $is_day
                ? __('Applied daily rate', 'manageentities')
                : __('Applied hourly rate', 'manageentities'),
            'amount' => __('To compute', 'manageentities'),
        ];

        // 'tech' is built name by name by getCriDetailData(), which escapes each of them
        // and joins them with <br/> separators that are meant to be rendered. 'file' is
        // the anchor of Document::getDownloadLink(), which only the central interface
        // builds: everywhere else the column holds the document name as plain text, so
        // the formatter is not declared and the datatable escapes it. Every other column
        // is plain text too and escaped the same way.
        $formatters = ['tech' => 'raw_html'];
        if ($is_central) {
            $formatters['file'] = 'raw_html';
        }

        $contract = new Contract();
        $contracts = $contract->find(['entities_id' => $entity]);

        $blocks = [];
        foreach ($contracts as $data_contract) {
            // The loop below reads id, name and contract_type off every row, so the
            // periods themselves are selected. The aggregate this used to ask for,
            // "COUNT(`glpi_plugin_manageentities_contractdays`.*)", is not valid MySQL
            // anyway, and the ORDERBY carried a stray backtick left over from the days
            // it was a raw SQL string.
            $iterator = $DB->request([
                'SELECT' => 'glpi_plugin_manageentities_contractdays.*',
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
                    'glpi_plugin_manageentities_contractdays.contracts_id' => $data_contract["id"],
                    'glpi_plugin_manageentities_contractdays.entities_id' => $entity,
                    'glpi_plugin_manageentities_contractstates.is_closed' => ['<>', 1],
                ],
                'ORDERBY' => 'glpi_plugin_manageentities_contractdays.begin_date DESC',
            ]);

            if (count($iterator) === 0) {
                continue;
            }

            $entries = [];
            foreach ($iterator as $data) {
                $data['contractdays_id'] = $data['id'];
                $options['sorting_date'] = true;
                $resultCriDetail = self::getCriDetailData($data, $options);

                // A forfait contract billed by the day hides the consumption and the
                // amounts it derives from: the day is due whatever time was spent on it.
                $show_conso = $config->fields['hourorday'] == Config::HOUR
                    || ($is_day && $data['contract_type'] != Contract::CONTRACT_TYPE_FORFAIT);

                foreach ($resultCriDetail['result'] as $dataCriDetail) {
                    $has_document = isset($dataCriDetail["documents_id"])
                        && $dataCriDetail["documents_id"] != 0;

                    // If a cri has been generated we get its data, else no cri generated
                    if ($has_document) {
                        $critypes_name = $dataCriDetail['plugin_manageentities_critypes_name'];
                        $doc = new Document();
                        $doc->getFromDB($dataCriDetail["documents_id"]);
                        $file = $is_central ? $doc->getDownloadLink() : $doc->getName();
                    } else {
                        $critypes_name = \Dropdown::getDropdownName(
                            'glpi_plugin_manageentities_critypes',
                            $dataCriDetail['plugin_manageentities_critypes_id'],
                        );
                        $file = '';
                    }

                    if ($show_conso) {
                        $conso = Html::formatNumber($dataCriDetail['conso'], 0, 2);
                    } else {
                        $conso = $has_document ? '' : \Dropdown::EMPTY_VALUE;
                    }

                    $rate = '';
                    $amount = '';
                    if ($dataCriDetail['pricecri']) {
                        $rate = $show_conso
                            ? Html::formatNumber($dataCriDetail['pricecri'], 0, 2)
                            : \Dropdown::EMPTY_VALUE;
                        $amount = $show_conso
                            ? Html::formatNumber($dataCriDetail['pricecri'] * $dataCriDetail['conso'], 0, 2)
                            : \Dropdown::EMPTY_VALUE;
                    }

                    $entries[] = [
                        'period'    => $data['name'],
                        'date'      => Html::convdate($dataCriDetail['tickets_date']),
                        'object'    => $dataCriDetail['tickets_name'],
                        'type'      => $critypes_name,
                        'file'      => $file,
                        'conso'     => $conso,
                        'tech'      => $dataCriDetail['tech'],
                        'rate'      => $rate,
                        'amount'    => $amount,
                        'row_class' => $dataCriDetail["is_deleted"] == '1' ? 'table-secondary text-muted' : '',
                    ];
                }
            }

            if ($entries === []) {
                continue;
            }

            $blocks[] = [
                'contract_name' => $data_contract["name"],
                'entries'       => $entries,
            ];
        }

        if ($blocks === []) {
            return;
        }

        TemplateRenderer::getInstance()->display('@manageentities/cridetail_periods.html.twig', [
            'blocks'     => $blocks,
            'columns'    => $columns,
            'formatters' => $formatters,
        ]);
    }

    /**
     * Validate that a string is a safe SQL date or datetime (Y-m-d or Y-m-d H:i:s).
     * Used to guard user-supplied date filters before they reach the query builder.
     *
     * @param string $date
     *
     * @return bool
     */
    private static function isValidSqlDate(string $date): bool
    {
        $date = trim($date);
        if ($date === '') {
            return false;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if ($d !== false && $d->format('Y-m-d') === $date) {
            return true;
        }
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $dt !== false && $dt->format('Y-m-d H:i:s') === $date;
    }

    public static function getCriDetailData($contractDayValues = [], $options = [])
    {
        global $DB;
        $params['condition'] = '1';

        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }

        $tabResults = [];
        $taskCount  = 0; // Count the number of tasks for all entities
        $conso      = 0;
        $tot_amount = 0;
        $tot_conso  = 0;
        $price      = 0;

        $config         = Config::getInstance();
        $critechnicians = new CriTechnician();

        $PDF = new CriPDF('P', 'mm', 'A4');

        $tabOther = ['tot_amount'    => 0,
            'reste_montant' => 0,
            'depass'        => 0,
            'reste'         => 0,
            'forfait'       => 0];

        // Validate and normalize the user-supplied date filters once (shared by both
        // queries below). Invalid values are ignored rather than concatenated into SQL.
        $begin_filter = null;
        $end_filter   = null;
        if (isset($options['begin_date']) && self::isValidSqlDate((string) $options['begin_date'])) {
            $begin_filter = $options['begin_date'] . ' 00:00:00';
        }
        if (isset($options['end_date']) && self::isValidSqlDate((string) $options['end_date'])) {
            $end_filter = $options['end_date'] . ' 23:59:59';
        }

        $criDetailWhere = [
            'glpi_plugin_manageentities_cridetails.contracts_id'                        => (int) $contractDayValues["contracts_id"],
            'glpi_plugin_manageentities_cridetails.entities_id'                         => (int) $contractDayValues["entities_id"],
            'glpi_plugin_manageentities_cridetails.plugin_manageentities_contractdays_id' => (int) $contractDayValues["contractdays_id"],
            'glpi_tickets.is_deleted'                                                   => 0,
            'glpi_tickettasks.actiontime'                                               => ['>', 0],
        ];
        if ($begin_filter !== null) {
            $criDetailWhere[] = ['OR' => [
                ['glpi_tickettasks.begin' => ['>=', $begin_filter]],
                ['glpi_tickettasks.begin' => null],
            ]];
        }
        if ($end_filter !== null) {
            $criDetailWhere[] = ['OR' => [
                ['glpi_tickettasks.end' => ['<=', $end_filter]],
                ['glpi_tickettasks.end' => null],
            ]];
        }

        $iteratorCriDetail = $DB->request([
            'SELECT'    => [
                'glpi_plugin_manageentities_cridetails.realtime AS actiontime',
                'glpi_plugin_manageentities_cridetails.documents_id',
                'glpi_documents.is_deleted',
                'glpi_plugin_manageentities_cridetails.tickets_id',
                'glpi_plugin_manageentities_cridetails.id AS cridetails_id',
                'glpi_plugin_manageentities_cridetails.technicians AS technicians',
                'glpi_plugin_manageentities_cridetails.plugin_manageentities_critypes_id',
                'glpi_plugin_manageentities_cridetails.date AS cridetails_date',
                'glpi_tickets.name AS tickets_name',
                'glpi_tickets.date AS tickets_date',
                'glpi_plugin_manageentities_critypes.name AS plugin_manageentities_critypes_name',
                'glpi_tickets.global_validation',
            ],
            'FROM'      => 'glpi_plugin_manageentities_cridetails',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_critypes' => [
                    'ON' => [
                        'glpi_plugin_manageentities_critypes'   => 'id',
                        'glpi_plugin_manageentities_cridetails' => 'plugin_manageentities_critypes_id',
                    ],
                ],
                'glpi_documents' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'documents_id',
                        'glpi_documents'                        => 'id',
                    ],
                ],
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                        'glpi_tickets'                          => 'id',
                    ],
                ],
                'glpi_tickettasks' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets'     => 'id',
                    ],
                ],
            ],
            'WHERE'     => $criDetailWhere,
            'GROUPBY'   => 'glpi_plugin_manageentities_cridetails.id',
            'ORDER'     => isset($options['sorting_date'])
                ? 'tickets_date DESC'
                : 'glpi_plugin_manageentities_cridetails.date ASC',
        ]);
        $numberCriDetail = count($iteratorCriDetail);

        $restrict        = ["`glpi_plugin_manageentities_contracts`.`entities_id`"  => $contractDayValues["entities_id"],
            "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contractDayValues["contracts_id"]];
        $dbu             = new DbUtils();
        $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
        $pluginContract  = reset($pluginContracts);

        // Default Cri price
        $default_price         = 0;
        $default_critypes_name = '';
        $default_critypes_id   = 0;
        $cri_price             = new CriPrice();
        $condition = ['glpi_plugin_manageentities_criprices.is_default' => 1];
        $price_data = $cri_price->getItems($contractDayValues["contractdays_id"], 0, $condition);
        if (!empty($price_data)) {
            $price_data            = reset($price_data);
            $price                 = $price_data["price"];
            $default_price         = $price_data["price"];
            $default_critypes_name = $price_data["critypes_name"];
            $default_critypes_id   = $price_data["plugin_manageentities_critypes_id"];
        }

        if ($numberCriDetail != 0) {
            $taskCount++;

            foreach ($iteratorCriDetail as $dataCriDetail) {
                // Get cridetail Cri Price if exists
                $price         = 0;
                $critypes_name = '';
                $critypes_id   = 0;
                if ($dataCriDetail['plugin_manageentities_critypes_id'] != 0) {
                    $price_data = $cri_price->getItems($contractDayValues["contractdays_id"], $dataCriDetail['plugin_manageentities_critypes_id']);
                    if (!empty($price_data)) {
                        $price_data    = reset($price_data);
                        $price         = $price_data["price"];
                        $critypes_name = $price_data["critypes_name"];
                        $critypes_id   = $price_data["plugin_manageentities_critypes_id"];
                    }
                }
                $price         = empty($price) ? $default_price : $price;
                $critypes_name = empty($critypes_name) ? $default_critypes_name : $critypes_name;
                $critypes_id   = empty($critypes_id) ? $default_critypes_id : $critypes_id;

                $taskWhere = [
                    'glpi_tickettasks.tickets_id'              => (int) $dataCriDetail['tickets_id'],
                    'glpi_tickettasks.is_private'              => 0,
                    'glpi_plugin_manageentities_cridetails.id' => (int) $dataCriDetail['cridetails_id'],
                ];
                $taskJoin = [
                    'glpi_plugin_manageentities_cridetails' => [
                        'ON' => [
                            'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                            'glpi_tickettasks'                      => 'tickets_id',
                        ],
                    ],
                ];
                if ($config->fields['hourorday'] == Config::HOUR) {
                    $taskJoin['glpi_plugin_manageentities_taskcategories'] = [
                        'ON' => [
                            'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                            'glpi_tickettasks'                          => 'taskcategories_id',
                        ],
                    ];
                    $taskWhere['glpi_plugin_manageentities_taskcategories.is_usedforcount'] = 1;
                }
                if ($begin_filter !== null) {
                    $taskWhere[] = ['OR' => [
                        ['glpi_tickettasks.begin' => ['>=', $begin_filter]],
                        ['glpi_tickettasks.begin' => null],
                    ]];
                }
                if ($end_filter !== null) {
                    $taskWhere[] = ['OR' => [
                        ['glpi_tickettasks.end' => ['<=', $end_filter]],
                        ['glpi_tickettasks.end' => null],
                    ]];
                }

                $iteratorTask = $DB->request([
                    'SELECT'    => ['actiontime', 'users_id_tech', 'is_private'],
                    'FROM'      => 'glpi_tickettasks',
                    'LEFT JOIN' => $taskJoin,
                    'WHERE'     => $taskWhere,
                    'ORDER'     => 'glpi_tickettasks.begin',
                ]);
                $numberTask     = count($iteratorTask);
                $tech           = '';
                $conso          = 0;
                $conso_per_tech = [];

                if ($numberTask != 0) {
                    $left = $contractDayValues["nbday"];
                    // This string is handed to the datatable with a raw_html formatter, by
                    // showForContractDay() below and by showPeriod(), while
                    // getTechnicians() returns formatUserName() output, which GLPI 10+ leaves raw.
                    // Escape each name here rather than the joined string, so that the only HTML
                    // left in it is the <br/> separator this rendering relies on.
                    $tech = implode('<br/>', array_map(
                        static fn($name): string => htmlspecialchars((string) $name, ENT_QUOTES),
                        $critechnicians->getTechnicians($dataCriDetail['tickets_id']),
                    ));
                    foreach ($iteratorTask as $dataTask) {
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

                        $round = round($tmp, 2);

                        $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['conso'] += $PDF->TotalTpsPassesArrondis($round);

                        // Set global conso of contractday

                        $conso += $PDF->TotalTpsPassesArrondis($round);

                        // Set depass per techs
                        $left -= self::computeInDays($dataTask['actiontime'], $config, $dataCriDetail, $pluginContract, 1);
                        if ($left <= 0) {
                            $conso_per_tech[$dataCriDetail['tickets_id']][$dataTask['users_id_tech']]['depass'] += abs($PDF->TotalTpsPassesArrondis($left));
                            $left                                                                               = 0;
                        }
                    }
                }

                // Ticket name
                $ticket = new Ticket();
                $ticket->getFromDB($dataCriDetail["tickets_id"]);
                $ticket_name = $ticket->getName();

                $tot_amount += $conso * $price;
                $tot_conso  += $conso;

                //Task informations
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_id']                          = $dataCriDetail['tickets_id'];
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_name']                        = $ticket_name;
                $tabResults[$dataCriDetail['cridetails_id']]['is_deleted']                          = $dataCriDetail['is_deleted'];
                $tabResults[$dataCriDetail['cridetails_id']]['tickets_date']                        = $dataCriDetail['tickets_date'];
                $tabResults[$dataCriDetail['cridetails_id']]['conso']                               = $conso;
                $tabResults[$dataCriDetail['cridetails_id']]['conso_per_tech']                      = $conso_per_tech;
                $tabResults[$dataCriDetail['cridetails_id']]['tech']                                = $tech;
                $tabResults[$dataCriDetail['cridetails_id']]['conso_amount']                        = $conso * $price;
                $tabResults[$dataCriDetail['cridetails_id']]['pricecri']                            = $price;
                $tabResults[$dataCriDetail['cridetails_id']]['documents_id']                        = $dataCriDetail['documents_id'];
                $tabResults[$dataCriDetail['cridetails_id']]['plugin_manageentities_critypes_name'] = $critypes_name;
                $tabResults[$dataCriDetail['cridetails_id']]['plugin_manageentities_critypes_id']   = $critypes_id;
            }
        }

        //Rest number / depass
        $tabOther['reste'] = ($contractDayValues["nbday"] + $contractDayValues["report"]) - $tot_conso;
        if ($tabOther['reste'] < 0) {
            $tabOther['depass'] = abs($tabOther['reste']);
            $tabOther['reste']  = 0;
        }

        // If depass on contract day set depass on last tech of last ticket of last intervention
        if ($tabOther['depass'] > 0) {
            $lastIntervention = end($tabResults);
            if (count($lastIntervention['conso_per_tech']) > 0) {
                $lastTicket = end($lastIntervention['conso_per_tech']);
                end($lastTicket);
                $tabResults[key($tabResults)]['conso_per_tech'][key($lastIntervention['conso_per_tech'])][key($lastTicket)]['depass'] = $tabOther['depass'];
            }
            reset($tabResults);
        }

        //Forfait
        $tabOther['forfait'] = ($contractDayValues["nbday"] + $contractDayValues["report"]) * $default_price;

        // Default criprice
        $tabOther['default_criprice'] = $default_price;

        //Rest amount
        $tabOther['reste_montant'] = $tabOther['forfait'] - $tot_amount;
        $tabOther['tot_amount']    = $tot_amount;

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
                    'manageentities',
                ) . "</div>";
            }
        }

        return $conso;
    }

    public static function showForContractDay(ContractDay $contractDay)
    {
        global $PDF;

        $config = Config::getInstance();
        $PDF = new CriPDF('P', 'mm', 'A4');

        $manageentities_contract = new Contract();
        $manageentities_contract->getFromDBByCrit(['contracts_id' => $contractDay->fields['contracts_id']]);

        $contractDay->fields['contractdays_id'] = $contractDay->fields['id'];
        $resultCriDetail = self::getCriDetailData($contractDay->fields);

        $use_price         = ($config->fields['useprice'] == Config::PRICE);
        $hour_or_day       = ($config->fields['hourorday'] == Config::DAY) ? 'day' : 'hour';
        $show_crossed_time = isset($manageentities_contract->fields['contract_type'])
            && $manageentities_contract->fields['contract_type'] != Contract::CONTRACT_TYPE_INTERVENTION;

        if ($use_price) {
            $conso_label = ($hour_or_day === 'day' || $show_crossed_time)
                ? __('Crossed time (itinerary including)', 'manageentities')
                : _x('Quantity', 'Number') . ' ' . __('of this intervention', 'manageentities');
            $rate_label = ($hour_or_day === 'day')
                ? __('Applied daily rate', 'manageentities')
                : ($show_crossed_time ? __('Applied hourly rate', 'manageentities') : __('Applied rate', 'manageentities'));

            $columns = [
                'date'   => __('Date'),
                'object' => __('Object of intervention', 'manageentities'),
                'type'   => CriType::getTypeName(1),
                'file'   => __('File'),
                'conso'  => $conso_label,
                'tech'   => __('Technicians', 'manageentities'),
                'rate'   => $rate_label,
                'amount' => __('To compute', 'manageentities'),
            ];
            $formatters = ['object' => 'raw_html', 'file' => 'raw_html', 'tech' => 'raw_html'];
        } else {
            $columns = [
                'date'   => __('Date'),
                'object' => __('Object of intervention', 'manageentities'),
                'tech'   => __('Technicians', 'manageentities'),
                'conso'  => __('Consumption', 'manageentities'),
            ];
            $formatters = ['tech' => 'raw_html'];
        }

        $entries = [];
        foreach ($resultCriDetail['result'] as $dataCriDetail) {
            $ticket = new Ticket();
            $ticket->getFromDB($dataCriDetail['tickets_id']);

            $doc_link      = '';
            $critypes_name = $dataCriDetail['plugin_manageentities_critypes_name'];
            if ($dataCriDetail['documents_id'] != 0) {
                $doc = new Document();
                $doc->getFromDB($dataCriDetail['documents_id']);
                $doc_link = $doc->getDownloadLink();
            } else {
                $critypes_name = \Dropdown::getDropdownName(
                    'glpi_plugin_manageentities_critypes',
                    $dataCriDetail['plugin_manageentities_critypes_id'],
                );
            }

            $conso_fmt    = Html::formatNumber($dataCriDetail['conso'], false);
            $pricecri_fmt = $dataCriDetail['pricecri'] ? Html::formatNumber($dataCriDetail['pricecri'], false) : '';
            $amount_fmt   = $dataCriDetail['pricecri']
                ? Html::formatNumber($dataCriDetail['pricecri'] * $dataCriDetail['conso'], false)
                : '';
            $row_class = $dataCriDetail['is_deleted'] == '1' ? 'table-secondary text-muted' : '';

            if ($use_price) {
                $entries[] = [
                    'date'      => Html::convdate($dataCriDetail['tickets_date']),
                    'object'    => $ticket->getLink(),
                    'type'      => $critypes_name,
                    'file'      => $doc_link,
                    'conso'     => $conso_fmt,
                    'tech'      => $dataCriDetail['tech'],
                    'rate'      => $pricecri_fmt,
                    'amount'    => $amount_fmt,
                    'row_class' => $row_class,
                ];
            } else {
                $entries[] = [
                    'date'      => Html::convdate($dataCriDetail['tickets_date']),
                    'object'    => $dataCriDetail['tickets_name'],
                    'tech'      => $dataCriDetail['tech'],
                    'conso'     => $conso_fmt,
                    'row_class' => $row_class,
                ];
            }
        }

        $nb_theoretical_days = 0;
        if ($resultCriDetail['resultOther']['default_criprice'] > 0) {
            $nb_theoretical_days = Html::formatNumber(
                $resultCriDetail['resultOther']['reste_montant'] / $resultCriDetail['resultOther']['default_criprice'],
                false,
            );
        }

        TemplateRenderer::getInstance()->display(
            '@manageentities/cridetail_for_contractday.html.twig',
            [
                'entries'             => $entries,
                'columns'             => $columns,
                'formatters'          => $formatters,
                'use_price'           => $use_price,
                'tot_amount'          => Html::formatNumber($resultCriDetail['resultOther']['tot_amount'], false),
                'nb_theoretical_days' => $nb_theoretical_days,
                'default_criprice'    => $resultCriDetail['resultOther']['default_criprice'],
            ],
        );
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
        $canView = $ticket->can($ticket->fields['id'], READ);
        $canEdit = $ticket->can($ticket->fields['id'], UPDATE);

        $config = Config::getInstance();

        if (!$canView) {
            return false;
        }

        // This block repairs the tickets_id of the intervention reports attached to a document
        // of the ticket, and it used to run on every display: a GET request mutated the
        // database, and a caller holding nothing but the read right on the ticket triggered the
        // write. The repair is kept - removing it would leave the rows unlinked forever, and
        // there is no POST path through this tab to move it to - but it is now reserved to a
        // caller who may actually modify the ticket, which is the right the mutation belongs
        // to. $canEdit is read above, from the same can() pair as $canView.
        if ($canEdit && $config->fields["backup"] == 1) {
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
                    'glpi_documents.tickets_id' => $ticket->fields['id'],
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
            "`glpi_plugin_manageentities_cridetails`.`entities_id`" => $ticket->fields['entities_id'],
            "`glpi_plugin_manageentities_cridetails`.`tickets_id`" => $ticket->fields['id'],
        ];

        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable("glpi_plugin_manageentities_cridetails", $restrict);
        $cridetail = reset($cridetails);

        // Capture the withcontract dropdown HTML
        ob_start();
        $rand = \Dropdown::showFromArray(
            'withcontract',
            [0 => __('Out of contract', 'manageentities'), 1 => __('With contrat', 'manageentities')],
            ['value' => ($cridetail) ? $cridetail['withcontract'] : 1, 'on_change' => self::CHANGE_EVENT_JS],
        );
        $contract_type_dropdown = ob_get_clean();

        // Capture the contract link dropdown HTML + retrieve the preselected contractday
        ob_start();
        $contract_link_info = self::showContractLinkDropdown($cridetail, $ticket->fields['entities_id']);
        $contract_link_dropdown = ob_get_clean();

        // Compute remaining days and load ContractDay fields from the preselected period
        $remaining_days = null;
        $contractday_begin_date = '';
        $contractday_end_date   = '';
        $contractday_comment    = '';
        $contractdays_id_selected = $contract_link_info['contractdaySelected'] ?? 0;
        if ($contractdays_id_selected > 0) {
            $contractDay = new ContractDay();
            if ($contractDay->getFromDB($contractdays_id_selected)) {
                $contractday_begin_date = $contractDay->fields['begin_date'] ?? '';
                $contractday_end_date   = $contractDay->fields['end_date']   ?? '';
                $contractday_comment    = $contractDay->fields['comment']    ?? '';
                $contractDay->fields['contractdays_id'] = $contractDay->fields['id'];
                $result_cri = self::getCriDetailData($contractDay->fields);
                $remaining_days = $result_cri['resultOther']['reste'];
            }
        }

        // Fetch comment, end date and states_id of the preselected contract
        $contract_comment  = '';
        $contract_end_date = '';
        $contract_states_id = 0;
        $contract_selected_id = $contract_link_info['contractSelected'] ?? 0;
        if ($contract_selected_id > 0) {
            $contract_obj = new \Contract();
            if ($contract_obj->getFromDB($contract_selected_id)) {
                $contract_comment  = $contract_obj->fields['comment'] ?? '';
                $contract_end_date = $contract_obj->fields['end_date'] ?? '';
                $contract_states_id = (int) ($contract_obj->fields['states_id'] ?? 0);
            }
        }

        // Closed GLPI state configured in plugin settings
        $me_config = Config::getInstance();
        $closed_glpi_state_id = (int) ($me_config->fields['closed_glpi_state_id'] ?? 0);

        // Publisher subscription for the ticket's entity
        $sub              = EditorSubscription::getForEntity((int) $ticket->fields['entities_id']);
        $now              = date('Y-m-d');
        $sub_end_expired  = !empty($sub['end_date']) && substr($sub['end_date'], 0, 10) < $now;
        $sub_level_name   = !empty($sub['plugin_manageentities_subscriptionlevels_id'])
            ? \Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int) $sub['plugin_manageentities_subscriptionlevels_id'])
            : '';
        $sub_no_active    = !empty($sub) && !($sub['active_editor_suscription'] ?? 0) && !($sub['cloud_client'] ?? 0);

        TemplateRenderer::getInstance()->display('@manageentities/cridetail_for_ticket.html.twig', [
            'rand'                      => $rand,
            'can_edit'                  => $canEdit,
            'use_subscriptions'         => Config::useEditorSubscriptions(),
            'form_url'                  => Toolbox::getItemTypeFormURL(Cri::class),
            'tickets_id'                => $ticket->fields['id'],
            'entities_id'               => $ticket->fields['entities_id'],
            'entity_name'               => \Dropdown::getDropdownName('glpi_entities', $ticket->fields['entities_id']),
            'ticket_date'               => $ticket->fields['date'],
            'is_new'                    => empty($cridetail),
            'cridetail_id'              => $cridetail['id'] ?? 0,
            'remaining_days'            => $remaining_days,
            'contractdays_id_selected'  => $contractdays_id_selected,
            'ajax_url'                  => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/getRemainingDays.php',
            'contract_comment'          => $contract_comment,
            'contract_end_date'         => $contract_end_date,
            'contract_states_id'        => $contract_states_id,
            'closed_glpi_state_id'      => $closed_glpi_state_id,
            'contractday_begin_date'    => $contractday_begin_date,
            'contractday_end_date'      => $contractday_end_date,
            'contractday_comment'       => $contractday_comment,
            'contract_type_dropdown'    => $contract_type_dropdown,
            'contract_link_dropdown'    => $contract_link_dropdown,
            'with_contract'             => (bool) ($cridetail ? $cridetail['withcontract'] : 1),
            // Publisher subscription
            'has_subscription'          => !empty($sub),
            'sub_customer_account_id'   => $sub['customer_account_id'] ?? '',
            'sub_name'                  => $sub['name'] ?? '',
            'sub_active'                => (int) ($sub['active_editor_suscription'] ?? 0),
            'sub_cloud'                 => (int) ($sub['cloud_client'] ?? 0),
            'sub_begin_date'            => $sub['begin_date'] ?? '',
            'sub_end_date'              => $sub['end_date'] ?? '',
            'sub_end_expired'           => $sub_end_expired,
            'sub_level_name'            => $sub_level_name,
            'sub_no_active'             => $sub_no_active,
        ]);
    }

    /**
     * "Intervention with contract" and "Periods of contract" selectors, shared by the CRI detail
     * of a ticket, the CRI report form (type 'cri', read-only) and the CRI generation wizard.
     *
     * @param array|false $cridetail   current CRI detail, [] or false when there is none
     * @param int|array   $entities_id
     * @param string      $type        'ticket' (dropdowns) or 'cri' (read-only names)
     * @param string      $layout      'table' (own table) or 'rows' (bare row of a 4-column table)
     *
     * @return array{contractSelected: int, contractdaySelected: int, is_contract: int}
     */
    public static function showContractLinkDropdown($cridetail, $entities_id, $type = 'ticket', string $layout = 'table')
    {
        $data = self::getContractLinkDropdownData($cridetail, $entities_id, $type, $layout);

        TemplateRenderer::getInstance()->display('@manageentities/contract_link_dropdown.html.twig', $data['template']);

        return $data['selection'];
    }

    /**
     * Data of the "Intervention with contract" and "Periods of contract" selectors.
     *
     * @param mixed  $cridetail   the CRI detail row, if any
     * @param mixed  $entities_id the entity (or entities) of the ticket
     * @param string $type        'ticket' for editable selectors, anything else for read-only names
     * @param string $layout      'table' or 'rows', see contract_link_dropdown.html.twig
     *
     * @return array{template: array<string, mixed>, selection: array{contractSelected: int, contractdaySelected: int, is_contract: int}}
     */
    public static function getContractLinkDropdownData($cridetail, $entities_id, $type = 'ticket', string $layout = 'table'): array
    {
        global $DB;

        $cridetail = is_array($cridetail) ? $cridetail : [];
        $width     = 300;

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

        $selected            = false;
        $contractSelected    = 0;
        $contractdaySelected = 0;
        $value               = 0;
        $elements            = [\Dropdown::EMPTY_VALUE];
        $current_contract    = (int) ($cridetail['contracts_id'] ?? 0);

        foreach ($iterator as $data) {
            if ($current_contract > 0 && $current_contract == $data["id"]) {
                $selected            = true;
                $contractSelected    = $current_contract;
                $contractdaySelected = (int) $cridetail["plugin_manageentities_contractdays_id"];
                $value               = $data["id"];
            } elseif ($type == 'ticket' && $data["is_default"] == '1' && !$selected) {
                $contractSelected = (int) $data['contracts_id'];
                $value            = $data["id"];
            }

            if ($type == 'ticket'
                && (Contract::checkRemainingOpenContractDays($data["id"]) || $current_contract == $data["id"])) {
                $elements[$data["id"]] = $data["name"] . " - " . $data["num"];
            }
        }

        $contract = new \Contract();
        $contract->getEmpty();
        $has_contracts = count($iterator) > 0;

        // Core widgets only: the template escapes everything else
        $contract_field    = '';
        $contract_tooltip  = '';
        $contract_ajax     = '';
        $contractday_field = '';
        if ($has_contracts && $type == 'ticket') {
            if ($value == 0 && count($elements) == 2) {
                unset($elements[0]);
            }
            $rand = mt_rand();
            $contract_field = \Dropdown::showFromArray('contracts_id', $elements, [
                'value'   => $value,
                'width'   => $width,
                'rand'    => $rand,
                'display' => false,
            ]);

            $params = [
                'contracts_id'         => '__VALUE__',
                'contractdays_id'      => $contractdaySelected,
                'current_contracts_id' => $contractSelected,
                'width'                => $width,
            ];
            $contract_ajax = Ajax::updateItemOnSelectEvent(
                "dropdown_contracts_id$rand",
                "show_contractdays",
                PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
                $params,
                false,
            ) . Ajax::updateItem(
                "show_contractdays",
                PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
                $params,
                "dropdown_contracts_id$rand",
                false,
            );
        }

        if (!empty($contractSelected) && $contract->getFromDB($contractSelected)) {
            $contract_tooltip = Html::showToolTip($contract->fields['comment'], [
                'link'       => $contract->getLinkURL(),
                'linktarget' => '_blank',
                'display'    => false,
            ]);
        }

        if ($has_contracts && $type == 'ticket') {
            $contractday_field = \Dropdown::show(ContractDay::class, [
                'name'      => 'plugin_manageentities_contractdays_id',
                'value'     => $contractdaySelected,
                'condition' => [
                    'entities_id'  => $contract->fields['entities_id'],
                    'contracts_id' => $contractSelected,
                    // Closed contract was 8, is now 2
                    'NOT'          => ['plugin_manageentities_contractstates_id' => 2],
                ],
                'width'     => $width,
                'on_change' => self::CHANGE_EVENT_JS,
                'display'   => false,
            ]);
        }

        $template = [
            'layout'            => $layout,
            'type'              => $type,
            'has_contracts'     => $has_contracts,
            'contract_field'    => $contract_field,
            'contract_name'     => $contractSelected
                ? \Dropdown::getDropdownName('glpi_contracts', $contractSelected)
                : '',
            'contract_tooltip'  => $contract_tooltip,
            'contract_ajax'     => $contract_ajax,
            'contractday_field' => $contractday_field,
            'contractday_name'  => $contractdaySelected
                ? \Dropdown::getDropdownName('glpi_plugin_manageentities_contractdays', $contractdaySelected)
                : '',
        ];

        return [
            'template'  => $template,
            'selection' => [
                'contractSelected'    => $contractSelected,
                'contractdaySelected' => $contractdaySelected,
                'is_contract'         => count($iterator),
            ],
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
                    ERROR,
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

        $default_options = [
            'color' => '',
            'event_type_color' => '',
            'check_planned' => false,
            'display_done_events' => true,
        ];
        $options = array_merge($default_options, $options);
        $interv = [];

        if (!isset($options['begin']) || ($options['begin'] == 'NULL')
            || !isset($options['end']) || ($options['end'] == 'NULL')) {
            return $interv;
        }

        $who = (int) $options['who'];
        $who_group = (int) $options['whogroup'];
        $begin = $options['begin'];
        $end = $options['end'];

        // A malformed bound would only make the planning empty, but it is not worth a query.
        if (!self::isValidSqlDate((string) $begin) || !self::isValidSqlDate((string) $end)) {
            return $interv;
        }

        if ($who_group > 0) {
            // Members of the chosen group. The raw version compared an unqualified `users_id`,
            // ambiguous with the joined tables, so it failed: the technicians columns are used
            // here, as in the other branches.
            $members = new QuerySubQuery([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $who_group],
            ]);
        } elseif ($who <= 0 && count($_SESSION['glpigroups'])) {
            // Members of the caller's groups having the assignment flag.
            $members = new QuerySubQuery([
                'SELECT'     => 'glpi_groups_users.users_id',
                'DISTINCT'   => true,
                'FROM'       => 'glpi_groups_users',
                'INNER JOIN' => [
                    'glpi_groups' => [
                        'ON' => [
                            'glpi_groups_users' => 'groups_id',
                            'glpi_groups'       => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    'glpi_groups_users.groups_id' => array_map('intval', $_SESSION['glpigroups']),
                    'glpi_groups.is_assign'       => 1,
                ],
            ]);
        } else {
            // Only personal ones.
            $members = $who;
        }

        $where = [
            'glpi_tickettasks.begin'      => ['>=', $begin],
            'glpi_tickettasks.end'        => ['<=', $end],
            'glpi_tickets.is_deleted'     => 0,
            'NOT'                         => ['glpi_tickettasks.actiontime' => 0],
            // Security (entity segregation): the assignment alternative stays nested in its
            // own OR, so that it can never escape the entity restriction below.
            [
                'OR' => [
                    'glpi_tickettasks.users_id_tech'                   => $members,
                    'glpi_plugin_manageentities_critechnicians.users_id' => $members,
                ],
            ],
        ] + getEntitiesRestrictCriteria('glpi_tickets', '', $_SESSION['glpiactiveentities'], false);

        if ($options['display_done_events'] == false) {
            $where[] = ['NOT' => ['glpi_tickets.status' => [Ticket::CLOSED, Ticket::SOLVED]]];
            $where[] = ['NOT' => ['glpi_tickettasks.state' => Planning::DONE]];
        }

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_tickettasks.users_id_tech',
                'glpi_tickettasks.begin',
                'glpi_tickettasks.end',
                'glpi_tickettasks.id',
                'glpi_tickettasks.actiontime',
                'glpi_tickettasks.content',
                'glpi_tickets.name AS ticket_name',
                'glpi_entities.name AS entities_name',
                'glpi_tickets.id AS tickets_id',
            ],
            'FROM'      => 'glpi_plugin_manageentities_cridetails',
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails' => 'tickets_id',
                        'glpi_tickets'                          => 'id',
                    ],
                ],
                'glpi_entities' => [
                    'ON' => [
                        'glpi_tickets'  => 'entities_id',
                        'glpi_entities' => 'id',
                    ],
                ],
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                    ],
                ],
                'glpi_tickettasks' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets'     => 'id',
                    ],
                ],
                'glpi_plugin_manageentities_critechnicians' => [
                    'ON' => [
                        'glpi_plugin_manageentities_cridetails'     => 'tickets_id',
                        'glpi_plugin_manageentities_critechnicians' => 'tickets_id',
                    ],
                ],
            ],
            'WHERE'     => $where,
            'GROUPBY'   => 'glpi_tickettasks.id',
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
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
                    $data["ticket_name"],
                    $CFG_GLPI["cut"],
                ); // name is re-encoded on JS side
                $interv[$key]["content"] = Html::resume_text(
                    RichText::getTextFromHtml($data["content"], false, true),
                    $CFG_GLPI["cut"],
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
        $dbu = new DbUtils();

        $params = [
            'complete'    => (bool) $complete,
            'entity_name' => $val['entities_name'] ?: '',
            'duration'    => $val['actiontime'] ? Html::timestampToString($val['actiontime'], false) : '',
            'end_date'    => '',
            'user_name'   => '',
            'content'     => (string) $val['content'],
        ];
        if ($complete) {
            if ($val['end']) {
                $params['end_date'] = Html::convDateTime($val['end']);
            }
            if ($val['users_id'] && $who != 0) {
                $params['user_name'] = $dbu->getUserName($val['users_id']);
            }
        } else {
            // showToolTip() renders its content as HTML: the text comes decoded from the task.
            $params['tooltip_html'] = Html::showToolTip(
                htmlescape((string) $val['content']),
                [
                    'applyto' => "cri_" . $val['id'] . mt_rand(),
                    'display' => false,
                ],
            );
        }

        return TemplateRenderer::getInstance()->render('@manageentities/cridetail_planning_item.html.twig', $params);
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
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `date` timestamp NULL DEFAULT NULL,
                            `documents_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id)',
                            `plugin_manageentities_contractdays_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `plugin_manageentities_critypes_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
                            `withcontract` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id)',
                            `realtime` decimal(20,2) DEFAULT '0.00',
                            `technicians` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
                            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
                            `number_moving` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'Number of movements',
                            PRIMARY KEY  (`id`),
                            KEY `entities_id` (`entities_id`),
                            KEY `documents_id` (`documents_id`),
                            KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`),
                            KEY `plugin_manageentities_contractdays_id` (`plugin_manageentities_contractdays_id`),
                            KEY `tickets_id` (`tickets_id`),
                            KEY `contracts_id` (`contracts_id`)
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
