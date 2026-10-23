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
 * Priced line of an annex: a quantity at a unit price excluding VAT (days at a daily rate,
 * or subscriptions at a monthly price over a number of months for a cloud annex).
 */
class PresaleAnnexLine extends CommonDBChild
{
    public static $rightname = 'plugin_manageentities_presale';

    public static $itemtype = PresaleProposal_Annex::class;
    public static $items_id = 'plugin_manageentities_presaleproposals_annexes_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Annex line', 'Annex lines', $nb, 'manageentities');
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
                            `plugin_manageentities_presaleproposals_annexes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `rank` int NOT NULL DEFAULT '0',
                            `designation` varchar(255) DEFAULT NULL,
                            `quantity` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'number of days',
                            `unit_price` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'daily rate excluding VAT',
                            `months` decimal(10,2) DEFAULT NULL COMMENT 'cloud subscription: number of months',
                            PRIMARY KEY  (`id`),
                            KEY `plugin_manageentities_presaleproposals_annexes_id` (`plugin_manageentities_presaleproposals_annexes_id`),
                            KEY `rank` (`rank`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Number of months of the cloud subscription lines (added after the first release)
        if (!$DB->fieldExists($table, 'months')) {
            $migration->addField($table, 'months', 'decimal(10,2) DEFAULT NULL', [
                'after'   => 'unit_price',
                'comment' => 'cloud subscription: number of months',
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
