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

use CommonDBTM;
use CommonITILObject;
use DBConnection;
use DbUtils;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Migration;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class DirectHelpdesk_Ticket extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Not billed intervention', 'Not billed interventions', $nb, 'manageentities');
    }

    /**
     * @return string
     */
    static function getIcon()
    {
        return "ti ti-file-euro";
    }

    static function countForTicket($item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_directhelpdesks_tickets',
            ["`tickets_id`" => $item->getID()]
        );
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Ticket'
            && isset($_SESSION['glpiactiveprofile']['interface'])
            && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
            $config    = Config::getInstance();
            $parent_id = (int)($config->fields['wizard_default_entities_id'] ?? 0);
            if ($parent_id > 0) {
                $sons = getSonsOf('glpi_entities', $parent_id);
                if (!in_array((int)$item->fields['entities_id'], $sons)) {
                    return '';
                }
            }
            if (self::countForTicket($item) > 0) {
                if ($_SESSION['glpishow_count_on_tabs']) {
                    return self::createTabEntry(
                        _n(
                            'Not billed intervention',
                            'Not billed interventions',
                            self::countForTicket($item),
                            'manageentities'
                        ),
                        self::countForTicket($item)
                    );
                }
                return self::createTabEntry(self::getTypeName(self::countForTicket($item)));
            } else {
                return self::createTabEntry(self::getTypeName(1));
            }
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        if ($item->getType() == 'Ticket' && self::countForTicket($item) > 0) {
            $self = new self();
            if ($items = $self->find(['tickets_id' => $item->getID()])) {
                echo "<table class='tab_cadre_fixe'>";
                echo "<tr class='tab_bg_1'>";
                echo "<th>" . __('Title') . "</th>";
                echo "<th>" . __('Date') . "</th>";
                echo "<th>" . __('Technician') . "</th>";
                echo "<th>" . __('Duration') . "</th>";
                echo "<th>" . __('Description') . "</th>";
                echo "</tr>";
                foreach ($items as $item) {
                    $direct = new DirectHelpdesk();
                    $direct->getFromDB($item['plugin_manageentities_directhelpdesks_id']);
                    $actiontime = $direct->fields['actiontime'];
                    echo "<tr class='tab_bg_1'>";
                    echo "<td>" . $direct->fields['name'] . "</td>";
                    echo "<td>" . Html::convDate($direct->fields['date']) . "</td>";
                    echo "<td>" . getUserName($direct->fields['users_id']) . "</td>";
                    echo "<td>" . CommonITILObject::getActionTime($actiontime) . "</td>";
                    echo "<td>" . $direct->fields['comment'] . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<div class='center alert alert-info d-flex'>";
                echo __('No results found');
                echo "</div>";
            }
        } else {
            echo "<div class='center alert alert-info d-flex'>";
            echo __('No results found');
            echo "</div>";
        }
        return true;
    }

    static function selectDirectHeldeskForTicket($entities_id)
    {
        $direct = new DirectHelpdesk();
        if ($items = $direct->find(['is_billed' => 0, 'entities_id' => $entities_id], ['date'])) {
            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'id'         => $item['id'],
                    'name'       => $item['name'],
                    'date'       => Html::convDate($item['date']),
                    'technician' => getUserName($item['users_id']),
                    'duration'   => CommonITILObject::getActionTime($item['actiontime']),
                    'comment'    => $item['comment'],
                ];
            }

            TemplateRenderer::getInstance()->display('@manageentities/entity/directhelpdesk_ticket_select.html.twig', [
                'rows'        => $rows,
                'is_central'  => (Session::getCurrentInterface() == 'central'),
                'entities_id' => $entities_id,
                'form_url'    => $direct->getFormURL(),
            ]);
        }
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
                            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
                            `plugin_manageentities_directhelpdesks_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            KEY `tickets_id` (`tickets_id`),
                            KEY `plugin_manageentities_directhelpdesks_id` (`plugin_manageentities_directhelpdesks_id`)
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
