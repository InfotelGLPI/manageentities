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

use Ajax;
use CommonDBTM;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Html;
use ITILCategory;
use Migration;
use Session;
use Ticket;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class DirectHelpdesk extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    public $dohistory = true;

    const ONE_HOUR = 3600;
    const TWO_HOUR = 7200;
    const THREE_HOUR = 10800;

    public static function getTypeName($nb = 0)
    {
        return _n('Not billed intervention', 'Not billed interventions', $nb, 'manageentities');
    }

    /**
     * @param array $options
     *
     * @return array
     */
    function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }

    /**
     * @return array
     */
    static function getMenuContent()
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
    static function getIcon()
    {
        return "ti ti-file-euro";
    }

    /**
     * @return string form HTML
     */
    static function loadModal()
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
            false
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
                    'value' => '0'
                ]
            ],
            'sort' => 4,
            'order' => 'ASC'
        ];

        return $search;
    }

    function prepareInputForAdd($input)
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

    function post_addItem()
    {
        if (isset($this->input["tickets_id"])) {
            $ticket = new DirectHelpdesk_Ticket();
            $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
            $input['tickets_id'] = $this->input["tickets_id"];
            $ticket->add($input);
        }
    }

    function post_updateItem($history = true)
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
    function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $mandatory_fields = [
            'name' => __('Title'),
            'date' => __('Date'),
            'actiontime' => __('Duration')
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
                ERROR
            );
            return false;
        }

        if (isset($this->input['entities_id']) && $this->input['entities_id'] == 0) {
            Session::addMessageAfterRedirect(
                __('You cannot add an intervention on this entity', 'manageentities'),
                false,
                ERROR
            );
            return false;
        }

        return true;
    }

    function showForm($ID, $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_form.html.twig', [
            'item' => $this,
            'params' => $options,
        ]);

        return true;
    }

    static function showDashboard($min_sum = 0)
    {
        global $CFG_GLPI;

        // echarts + the gauge init script are registered in setup.php (ADD_JAVASCRIPT hook)
        // so they land in the page <head> for both the central and helpdesk interfaces.
        Html::requireJs('charts');

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
                    ]
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
     * @return array
     */
    function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => self::getTypeName(2)
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
            'datatype' => 'timestamp'
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

    function displayAlertforEntity($instID)
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
                $this->getTable() . '.entities_id' => $instID
            ],
        ]);

        if (count($iterator) > 0) {
            $alert .= "<div class='alert alert-danger d-flex'>";
            $alert .= "<b>" . __(
                    "Please note that there are unbilled interventions for this customer.",
                    "manageentities"
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
