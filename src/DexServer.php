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
 * Server described by a DEX: DNS name, internal name, IP address and optional role. Taken over
 * from the DAT of the client when the DEX is created, then edited in the DEX alone. In the Word
 * document, the table rows holding {{IP}} are duplicated for each server.
 */
class DexServer extends CommonDBChild
{
    public static $rightname = 'plugin_manageentities_dex';

    public static $itemtype = Dex::class;
    public static $items_id = 'plugin_manageentities_dexes_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Server', 'Servers', $nb, 'manageentities');
    }

    /**
     * Servers of a DEX, by rank
     *
     * @return list<array<string, mixed>>
     */
    public static function getForDex(int $dexes_id): array
    {
        return array_values((new self())->find([self::$items_id => $dexes_id], ['rank', 'id']));
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
                            `plugin_manageentities_dexes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `rank` int NOT NULL DEFAULT '0',
                            `name` varchar(255) DEFAULT NULL COMMENT 'DNS name',
                            `internal_name` varchar(255) DEFAULT NULL COMMENT 'internal host name, the HOTE A row of the DNS table',
                            `ip` varchar(255) DEFAULT NULL,
                            `role` varchar(255) DEFAULT NULL COMMENT 'front, database... free text',
                            PRIMARY KEY  (`id`),
                            KEY `plugin_manageentities_dexes_id` (`plugin_manageentities_dexes_id`),
                            KEY `rank` (`rank`)
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
