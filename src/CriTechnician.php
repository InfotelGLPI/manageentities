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
use DBConnection;
use Migration;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class CriTechnician extends CommonDBTM
{

    function getTechnicians($tickets_id, $remove_tag = false)
    {
        global $DB;

        $techs = [];

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_tickettasks.users_id_tech as users_id',
                'glpi_users.name',
                'glpi_users.realname',
                'glpi_users.firstname',
            ],
            'FROM' => 'glpi_tickettasks',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_tickettasks' => 'users_id_tech',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'tickets_id' => $tickets_id
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                if ($data['users_id'] != 0) {
                    if ($remove_tag) {
                        $techs['notremove'][$data['users_id']] = formatUserName(
                            $data["users_id"],
                            $data["name"],
                            $data["realname"],
                            $data["firstname"],
                            0
                        );
                    } else {
                        $techs[$data['users_id']] = formatUserName(
                            $data["users_id"],
                            $data["name"],
                            $data["realname"],
                            $data["firstname"],
                            0
                        );
                    }
                }
            }
        }

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_manageentities_critechnicians.users_id as users_id',
                'glpi_users.name',
                'glpi_users.realname',
                'glpi_users.firstname',
            ],
            'FROM' => 'glpi_plugin_manageentities_critechnicians',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_plugin_manageentities_critechnicians' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'tickets_id' => $tickets_id
            ],
        ]);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                if ($data['users_id'] != 0 && !isset($techs['notremove'][$data['users_id']])) {
                    if ($remove_tag) {
                        $techs['remove'][$data['users_id']] = formatUserName(
                            $data["users_id"],
                            $data["name"],
                            $data["realname"],
                            $data["firstname"],
                            0
                        );
                    } else {
                        $techs[$data['users_id']] = formatUserName(
                            $data["users_id"],
                            $data["name"],
                            $data["realname"],
                            $data["firstname"],
                            0
                        );
                    }
                }
            }
        }

        return $techs;
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
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            PRIMARY KEY  (`id`),
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
