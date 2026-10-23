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
 * Contact of a prospect (step 1 of the wizard). The main one (is_default) receives the
 * proposal and signs the annexes for the customer; contacts_id is set once the GLPI contact
 * is created by the new customer wizard.
 */
class PresaleContact extends CommonDBChild
{
    public static $rightname = 'plugin_manageentities_presale';

    public static $itemtype = PresaleClient::class;
    public static $items_id = 'plugin_manageentities_presaleclients_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Prospect contact', 'Prospect contacts', $nb, 'manageentities');
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
                            `plugin_manageentities_presaleclients_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `name` varchar(255) DEFAULT NULL,
                            `firstname` varchar(255) DEFAULT NULL,
                            `role` varchar(255) DEFAULT NULL,
                            `email` varchar(255) DEFAULT NULL,
                            `phone` varchar(255) DEFAULT NULL,
                            `mobile` varchar(255) DEFAULT NULL,
                            `is_default` tinyint NOT NULL DEFAULT '0' COMMENT 'main contact: recipient of the proposal, signs the annexes',
                            `contacts_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contacts (id) — contact created once the proposal is won',
                            PRIMARY KEY  (`id`),
                            KEY `plugin_manageentities_presaleclients_id` (`plugin_manageentities_presaleclients_id`),
                            KEY `contacts_id` (`contacts_id`)
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
