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

use CommonDBChild;
use DBConnection;
use Migration;

/**
 * Server described by a DAT: DNS name, IP address and optional role (front, database...). In
 * the Word document, the table row holding {{NOM_DNS}} / {{IP}} is duplicated for each server.
 */
class DatServer extends CommonDBChild
{
    public static string $rightname = 'plugin_manageentities_dat';

    public static string $itemtype = Dat::class;
    public static string $items_id = 'plugin_manageentities_dats_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Server', 'Servers', $nb, 'manageentities');
    }

    /**
     * Servers of a DAT, by rank
     *
     * @return list<array<string, mixed>>
     */
    public static function getForDat(int $dats_id): array
    {
        return array_values((new self())->find([self::$items_id => $dats_id], ['rank', 'id']));
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `plugin_manageentities_dats_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `rank` int NOT NULL DEFAULT '0',
                            `name` varchar(255) DEFAULT NULL COMMENT 'DNS name',
                            `internal_name` varchar(255) DEFAULT NULL COMMENT 'internal host name, the HOTE A row of the DNS table',
                            `ip` varchar(255) DEFAULT NULL,
                            `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'operating system, a DatOsTemplate::OS_* model',
                            `role` varchar(255) DEFAULT NULL COMMENT 'front, database... free text',
                            PRIMARY KEY  (`id`),
                            KEY `plugin_manageentities_dats_id` (`plugin_manageentities_dats_id`),
                            KEY `rank` (`rank`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Upgrade: internal name of the server, added after the first release of the DAT
        if (!$DB->fieldExists($table, 'internal_name')) {
            $migration->addField($table, 'internal_name', 'string', [
                'after'   => 'name',
                'comment' => 'internal host name, the HOTE A row of the DNS table',
            ]);
            $migration->migrationOneTable($table);
        }
        // Upgrade: operating system of the server, which picks the model of the sections
        if (!$DB->fieldExists($table, 'os')) {
            $migration->addField($table, 'os', "varchar(50) NOT NULL DEFAULT ''", [
                'after'   => 'ip',
                'comment' => 'operating system, a DatOsTemplate::OS_* model',
            ]);
            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
