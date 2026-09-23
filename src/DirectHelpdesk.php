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
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Html;
use ITILCategory;
use Migration;
use Session;
use Ticket;
use Toolbox;

class DirectHelpdesk extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities_directhelpdesk';

    public $dohistory = true;

    public const ONE_HOUR = 3600;
    public const TWO_HOUR = 7200;
    public const THREE_HOUR = 10800;

    /**
     * Age, in months, past which unbilled interventions are reported as forgotten by
     * showUnbilledOverview().
     */
    public const UNBILLED_ALERT_MONTHS = 6;

    public static function getTypeName($nb = 0)
    {
        return _n('Not billed intervention', 'Not billed interventions', $nb, 'manageentities');
    }

    /**
     * @param array $options
     *
     * @return array
     */
    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }

    /**
     * @return array
     */
    public static function getMenuContent()
    {
        $menu = [];

        $menu['title'] = self::getMenuName();
        $menu['page'] = PLUGIN_MANAGEENTITIES_WEBDIR . "/front/directhelpdesk.php?checkbox3=1";
        $menu['links']['search'] = self::getSearchURL(false);
        $menu['icon'] = self::getIcon();

        return $menu;
    }

    /**
     * @return string
     */
    public static function getIcon()
    {
        return "ti ti-file-euro";
    }

    /**
     * @return string form HTML
     */
    public static function loadModal()
    {
        // Entity selector: capture its markup while keeping the rand for the AJAX callback.
        ob_start();
        $rand = \Entity::dropdown([
            'name'      => 'entities_id',
            'on_change' => 'entity_contract()',
        ]);
        $entity_dropdown = ob_get_clean();

        // Refresh the contract alert whenever the selected entity changes.
        $JS  = "function entity_contract(){";
        $JS .= Ajax::updateItemJsCode(
            "entity_alert",
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/showalertbyentity.php",
            ['entities_id' => '__VALUE__'],
            'dropdown_entities_id' . $rand,
            false,
        );
        $JS .= "}";
        $js_block = Html::scriptBlock($JS);

        $contract = new Contract();
        $alert    = $contract->displayAlertforEntity($_SESSION['glpiactive_entity']);

        $category_dropdown = ITILCategory::dropdown([
            'name'      => 'name',
            'display'   => false,
            'condition' => [
                'OR' => [
                    'is_incident' => 1,
                    'is_request'  => 1,
                ],
            ],
        ]);

        $comment_textarea = Html::textarea([
            'name'            => 'comment',
            'cols'            => '40',
            'rows'            => '10',
            'enable_ricktext' => false,
            'display'         => false,
        ]);

        $date_field = Html::showDateField("date", [
            'value'      => date("Y-m-d"),
            'maybeempty' => true,
            'canedit'    => true,
            'display'    => false,
        ]);

        $time_field = \Dropdown::showTimeStamp("actiontime", [
            'min'     => 0,
            'max'     => 50 * HOUR_TIMESTAMP,
            'display' => false,
        ]);

        //TODO only opened tickets for selected entity
        $ticket_dropdown = Ticket::dropdown([
            'name'        => 'tickets_id',
            'displaywith' => ['id'],
            'display'     => false,
        ]);

        $users_hidden = Html::hidden('users_id', [
            'value'   => Session::getLoginUserID(),
            'display' => false,
        ]);

        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_modal.html.twig', [
            'form_url'          => self::getFormURL(),
            'entity_type'       => \Entity::getTypeName(),
            'entity_dropdown'   => $entity_dropdown,
            'js_block'          => $js_block,
            'alert'             => $alert,
            'category_dropdown' => $category_dropdown,
            'comment_textarea'  => $comment_textarea,
            'date_field'        => $date_field,
            'time_field'        => $time_field,
            'ticket_dropdown'   => $ticket_dropdown,
            'users_hidden'      => $users_hidden,
        ]);
    }

    public static function getDefaultSearchRequest()
    {
        $search = [
            'criteria' => [
                0 => [
                    'field' => 11,
                    'searchtype' => 'equals',
                    'value' => '0',
                ],
            ],
            'sort' => 4,
            'order' => 'ASC',
        ];

        return $search;
    }

    public function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        if (isset($input['name']) && $input['name'] > 0) {
            $cat = new ITILCategory();
            $cat->getFromDB($input['name']);
            $input['name'] = $cat->getName();
        }

        return $input;
    }

    public function post_addItem()
    {
        if (isset($this->input["tickets_id"])) {
            $ticket = new DirectHelpdesk_Ticket();
            $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
            $input['tickets_id'] = $this->input["tickets_id"];
            $ticket->add($input);
        }
    }

    public function post_updateItem($history = true)
    {
        if (isset($this->input["tickets_id"])) {
            $ticket = new DirectHelpdesk_Ticket();

            if ($ticket->getFromDBByCrit(['plugin_manageentities_directhelpdesks_id' => $this->getID()])) {
                $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
                $ticket->deleteByCriteria($input);
            }

            if ($this->input["tickets_id"] > 0
                && !$ticket->getFromDBByCrit(['plugin_manageentities_directhelpdesks_id' => $this->getID()])) {
                $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
                $input['tickets_id'] = $this->input["tickets_id"];
                $ticket->add($input);
            }
        }
    }

    /**
     * checkMandatoryFields
     *
     * @param $input
     *
     * @return boolean
     */
    public function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $mandatory_fields = [
            'name' => __('Title'),
            'date' => __('Date'),
            'actiontime' => __('Duration'),
        ];

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
                ERROR,
            );
            return false;
        }

        if (isset($this->input['entities_id']) && $this->input['entities_id'] == 0) {
            Session::addMessageAfterRedirect(
                __('You cannot add an intervention on this entity', 'manageentities'),
                false,
                ERROR,
            );
            return false;
        }

        return true;
    }

    public function showForm($ID, $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_form.html.twig', [
            'item' => $this,
            'params' => $options,
        ]);

        return true;
    }

    public static function showDashboard($min_sum = 0)
    {
        global $CFG_GLPI;

        // ECharts comes from core (public/lib/echarts.js): ask core to emit it in the page
        // footer, ahead of the gauge script registered in setup.php. An AJAX tab response
        // renders no footer, so the request would not be honoured there -- it would just sit
        // in the session and load the bundle on whatever page comes next. On that path the
        // gauge script fetches the same core bundle itself instead.
        if (!Toolbox::isAjax()) {
            Html::requireJs('charts');
        }

        $direct = new DirectHelpdesk();

        $items = $direct->find(['is_billed' => 0]);
        if (!$items) {
            return;
        }

        $entities = $_SESSION["glpiactiveentities"];
        $directs  = [];
        $techs    = [];
        foreach ($items as $item) {
            if (!in_array($item["entities_id"], $entities)) {
                continue;
            }
            if (isset($directs[$item['entities_id']])) {
                $directs[$item['entities_id']] += $item['actiontime'];
                if (!in_array($item['users_id'], $techs[$item['entities_id']])) {
                    $techs[$item['entities_id']][] = $item['users_id'];
                }
            } else {
                $directs[$item['entities_id']] = $item['actiontime'];
                $techs[$item['entities_id']][] = $item['users_id'];
            }
        }
        arsort($directs);
        if ($min_sum > 0) {
            foreach ($directs as $entities_id => $actiontime) {
                if ($actiontime < $min_sum) {
                    unset($directs[$entities_id]);
                    unset($techs[$entities_id]);
                }
            }
        }

        $is_central = (Session::getCurrentInterface() == 'central');
        $cards      = [];
        foreach ($directs as $entities_id => $actiontime) {
            $sum = ($actiontime * 0.5) / 14400;

            $tech_interventions = [];
            if (is_array($techs[$entities_id]) && count($techs[$entities_id])) {
                $tech_interventions = $techs[$entities_id];
            }
            $tech_names = [];
            foreach ($tech_interventions as $users_id) {
                $tech_names[] = getUserName($users_id);
            }

            $entity = new \Entity();
            $entity->getFromDB($entities_id);
            $name = $entity->getName();

            // The iframe modal window is rendered as safe GLPI markup; only central
            // users can create a ticket, and only once the threshold is reached.
            $modal       = '';
            $show_create = false;
            $can_create  = false;
            if ($is_central) {
                $show_create = true;
                $can_create  = ($sum >= 0.375);
                $modal       = Ajax::createIframeModalWindow(
                    'createticket' . $entities_id,
                    PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/directhelpdesk.php?action=createticket&entities_id=" . $entities_id,
                    [
                        'title'   => __('Create a ticket'),
                        'display' => false,
                    ],
                );
            }

            $cards[] = [
                'entities_id' => (int) $entities_id,
                'name'        => $name,
                // Gauge config consumed by public/scripts/directhelpdesk-gauges.js.
                'gauge'       => ['value' => $sum, 'name' => $name],
                'techs'       => $tech_names,
                'show_create' => $show_create,
                'can_create'  => $can_create,
                'modal'       => $modal,
            ];
        }

        if (empty($cards)) {
            return;
        }

        $nbcol = 4;
        $rows  = array_chunk($cards, $nbcol);
        // Number of empty filler columns to keep the last row aligned on the grid.
        $last_count = count(end($rows));
        $last_pad   = ($last_count % $nbcol != 0) ? ($nbcol - ($last_count % $nbcol)) : 0;

        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_dashboard.html.twig', [
            'rows'     => $rows,
            'last_pad' => $last_pad,
            'hour'     => lcfirst(_n('Hour', 'Hours', 1)),
            'hours'    => lcfirst(_n('Hour', 'Hours', 2)),
            'tag_url'  => PLUGIN_MANAGEENTITIES_WEBDIR . "/pics/tag.png",
        ]);
    }

    /**
     * Management view of the unplanned interventions: how many hours are still waiting to be
     * billed, for which customer, and how long they have been waiting.
     *
     * Built on the same foundations as EditorSubscription::showStatusTab(): same entity
     * perimeter (the customer subtree of wizard_default_entities_id, minus the archive subtree
     * of wizard_archive_entities_id) and the same definition of an ongoing contract (a contract
     * day in one of the states selected in the plugin configuration).
     *
     * Two situations are reported apart from the main table because they are the ones that cost
     * money silently: a customer that was archived while interventions were still unbilled, and
     * interventions left unbilled for more than UNBILLED_ALERT_MONTHS months.
     */
    public static function showUnbilledOverview(): void
    {
        global $DB;

        // This method is public and static: it cannot rely on the guard of whatever renders it.
        if (!Session::haveRight(self::$rightname, READ)) {
            throw new AccessDeniedHttpException();
        }

        $entity_ids = $_SESSION['glpiactiveentities'];
        $config     = Config::getInstance();

        $parent_id           = (int) ($config->fields['wizard_default_entities_id'] ?? 0);
        $archive_entities_id = (int) ($config->fields['wizard_archive_entities_id'] ?? 0);

        // concerned_ids: active customers. archived_ids: customers moved to the archive subtree.
        // Both are intersected with the session perimeter, which never widens them.
        $concerned_ids = [];
        $archived_ids  = [];

        if ($parent_id > 0 && !empty($entity_ids)) {
            $customer_sons = getSonsOf('glpi_entities', $parent_id);
            unset($customer_sons[$parent_id]);

            // The archive root is kept in the list: it is excluded from the active customers
            // and included in the archived ones, exactly as showStatusTab() does.
            $archive_son_ids = $archive_entities_id > 0
                ? array_keys(getSonsOf('glpi_entities', $archive_entities_id))
                : [];

            $concerned_ids = array_map('intval', array_values(
                array_diff(
                    array_intersect($entity_ids, array_keys($customer_sons)),
                    $archive_son_ids,
                ),
            ));
            $archived_ids = array_map('intval', array_values(
                array_intersect($archive_son_ids, $entity_ids),
            ));
        }

        $active_states = json_decode($config->fields['contract_states'] ?? '', true);
        $active_states = is_array($active_states) && !empty($active_states)
            ? array_map('intval', $active_states)
            : [];

        // Customers holding at least one contract day in an active state: the "contract with
        // ongoing services" the main table is restricted to.
        $with_active_contract = [];
        if (!empty($concerned_ids) && !empty($active_states)) {
            $iter = $DB->request([
                'SELECT'     => ['c.entities_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_plugin_manageentities_contractdays AS cd',
                'INNER JOIN' => [
                    'glpi_contracts AS c' => ['FKEY' => ['cd' => 'contracts_id', 'c' => 'id']],
                ],
                'WHERE' => [
                    'c.entities_id' => $concerned_ids,
                    'cd.plugin_manageentities_contractstates_id' => $active_states,
                    'c.is_deleted'  => 0,
                ],
            ]);
            $with_active_contract = array_map(
                'intval',
                array_column(iterator_to_array($iter), 'entities_id'),
            );
        }

        // One aggregate per customer, active and archived alike: the archived ones feed their own
        // alert and must not be filtered out before it is built.
        $scope_ids  = array_values(array_unique(array_merge($concerned_ids, $archived_ids)));
        $aggregates = [];
        if (!empty($scope_ids)) {
            $iter = $DB->request([
                'SELECT'  => [
                    'entities_id',
                    QueryFunction::sum('actiontime', false, 'total_time'),
                    QueryFunction::min('date', 'oldest_date'),
                    QueryFunction::count('id', false, 'nb'),
                ],
                'FROM'    => self::getTable(),
                'WHERE'   => ['is_billed' => 0, 'entities_id' => $scope_ids],
                'GROUPBY' => ['entities_id'],
            ]);
            foreach ($iter as $row) {
                $aggregates[(int) $row['entities_id']] = [
                    'hours'  => round(((int) $row['total_time']) / HOUR_TIMESTAMP, 2),
                    'oldest' => $row['oldest_date'],
                    'nb'     => (int) $row['nb'],
                ];
            }
        }

        $names = [];
        if (!empty($aggregates)) {
            $iter = $DB->request([
                'SELECT' => ['id', 'completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => array_keys($aggregates)],
            ]);
            foreach ($iter as $row) {
                $names[(int) $row['id']] = $row['completename'];
            }
        }

        $threshold = date(
            'Y-m-d H:i:s',
            strtotime('-' . self::UNBILLED_ALERT_MONTHS . ' months'),
        );

        $rows        = [];
        $archived    = [];
        $stale       = [];
        $total_hours = 0.0;
        $total_nb    = 0;

        foreach ($aggregates as $entities_id => $data) {
            $row = [
                'entities_id' => $entities_id,
                'name'        => $names[$entities_id] ?? '',
                'hours'       => $data['hours'],
                'nb'          => $data['nb'],
                'oldest'      => $data['oldest'] !== null ? Html::convDate($data['oldest']) : '',
                // Kept alongside the formatted date: the lists are ordered on it, and the
                // displayed form sorts lexicographically by day/month/year.
                'oldest_raw'  => $data['oldest'],
                'is_stale'    => $data['oldest'] !== null && $data['oldest'] < $threshold,
                'url'         => self::getUnbilledSearchUrl($entities_id),
            ];

            // An archived customer belongs to its own alert and to nothing else: it has no
            // ongoing contract by definition, and its age is not actionable the same way.
            if (in_array($entities_id, $archived_ids, true)) {
                $archived[] = $row;
                continue;
            }

            if (in_array($entities_id, $with_active_contract, true)) {
                $rows[]       = $row;
                $total_hours += $data['hours'];
                $total_nb    += $data['nb'];
            }

            // Deliberately not restricted to the customers of the table above: unbilled hours
            // left on a customer whose contract has ended are exactly what this alert is for.
            if ($row['is_stale']) {
                $stale[] = $row;
            }
        }

        // Oldest first: the point of both lists is what has been waiting the longest, not what
        // weighs the most. A row with no date sorts last rather than first.
        $by_oldest_first = static fn(array $a, array $b): int
            => ($a['oldest_raw'] ?? "\xFF") <=> ($b['oldest_raw'] ?? "\xFF");
        usort($rows, $by_oldest_first);
        usort($stale, $by_oldest_first);

        // The archived list keeps the amount at stake first: nothing is waiting there any more,
        // the customer is gone, so the only actionable ordering is how much is left to bill.
        usort($archived, static fn(array $a, array $b): int => $b['hours'] <=> $a['hours']);

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/unbilled_tab.html.twig',
            [
                'rows'        => $rows,
                'archived'    => $archived,
                'stale'       => $stale,
                'total_hours' => round($total_hours, 2),
                'total_nb'    => $total_nb,
                'months'      => self::UNBILLED_ALERT_MONTHS,
            ],
        );
    }

    /**
     * Search URL listing the unbilled interventions of one entity, used to jump from the
     * overview to the records themselves. Search option 11 is is_billed and 80 is the entity,
     * both declared in rawSearchOptions(). checkbox3 is pinned to 0 so the page does not silently
     * apply its default three-hour filter to the gauges it renders above the list.
     */
    private static function getUnbilledSearchUrl(int $entities_id): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/directhelpdesk.php?' . http_build_query([
            'checkbox3' => 0,
            'criteria'  => [
                ['field' => 11, 'searchtype' => 'equals', 'value' => 0],
                [
                    'link'       => 'AND',
                    'field'      => 80,
                    'searchtype' => 'equals',
                    'value'      => $entities_id,
                ],
            ],
        ]);
    }

    /**
     * @return array
     */
    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => self::getTypeName(2),
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
            'id' => '4',
            'table' => $this->getTable(),
            'field' => 'date',
            'name' => __('Date'),
            'datatype' => 'date',
        ];


        $tab[] = [
            'id' => '8',
            'table' => $this->getTable(),
            'field' => 'comment',
            'name' => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id' => '9',
            'table' => $this->getTable(),
            'field' => 'actiontime',
            'name' => __('Duration'),
            'datatype' => 'timestamp',
        ];

        $tab[] = [
            'id' => '10',
            'table' => 'glpi_users',
            'field' => 'name',
            'name' => __('User'),
            'datatype' => 'dropdown',
            'right' => 'all',
        ];

        $tab[] = [
            'id' => '11',
            'table' => $this->getTable(),
            'field' => 'is_billed',
            'name' => __('Is billed', 'manageentities'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id' => '12',
            'table' => 'glpi_tickets',
            'field' => 'name',
            'name' => __('Linked ticket'),
            'datatype' => 'itemlink',
            'itemlink_type' => 'Ticket',
        ];


        $tab[] = [
            'id' => '30',
            'table' => $this->getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id' => '80',
            'table' => 'glpi_entities',
            'field' => 'completename',
            'name' => _n('Entity', 'Entities', 1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '81',
            'table' => 'glpi_entities',
            'field' => 'entities_id',
            'name' => _n('Entity', 'Entities', 1) . "-" . __('ID'),
        ];

        return $tab;
    }

    public function displayAlertforEntity($instID)
    {
        global $DB;

        $alert = "";
        $iterator = $DB->request([
            'SELECT' => [
                $this->getTable() . '.id',
            ],
            'FROM' => $this->getTable(),
            'WHERE' => [
                $this->getTable() . '.is_billed' => 0,
                $this->getTable() . '.entities_id' => $instID,
            ],
        ]);

        if (count($iterator) > 0) {
            $alert .= "<div class='alert alert-danger d-flex'>";
            $alert .= "<b>" . __(
                "Please note that there are unbilled interventions for this customer.",
                "manageentities",
            ) . "</b></div>";
        }
        return $alert;
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
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
                            `comment` text collate utf8mb4_unicode_ci,
                            `is_billed` tinyint NOT NULL DEFAULT '0',
                            `date` timestamp NULL DEFAULT NULL,
                            `actiontime` int NOT NULL DEFAULT '0',
                            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
                            `date_mod` timestamp NULL DEFAULT NULL,
                            `date_creation` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `entities_id` (`entities_id`),
                            KEY `tickets_id` (`tickets_id`),
                            KEY `users_id` (`users_id`)
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
