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

use DBConnection;
use Glpi\DBAL\QueryExpression;
use Migration;

/**
 * References of the proposals, as in the proof of concept: the first three letters of the
 * client, the year and month of the generation, and a sequence of the month for that prefix,
 * e.g. SOC260914. One row per prefix and month holds the last sequence used.
 */
class PresaleCounter
{
    public static function getTable(): string
    {
        return 'glpi_plugin_manageentities_presalecounters';
    }

    /**
     * Three letters of the client name, transliterated, upper case, padded with X
     */
    public static function getPrefix(string $client_name): string
    {
        $ascii   = @iconv('UTF-8', 'ASCII//TRANSLIT', $client_name);
        $letters = (string) preg_replace('/[^A-Za-z0-9]/', '', $ascii === false ? $client_name : $ascii);

        return strtoupper(substr(str_pad($letters, 3, 'X'), 0, 3));
    }

    /**
     * Next reference for a client. The row of the prefix and month is locked by the update
     * until the commit, so two proposals generated at once never get the same sequence.
     */
    public static function next(string $client_name, ?\DateTimeInterface $date = null): string
    {
        global $DB;

        $prefix = self::getPrefix($client_name);
        $period = ($date ?? new \DateTimeImmutable($_SESSION['glpi_currenttime'] ?? 'now'))->format('ym');
        $where  = ['prefix' => $prefix, 'period' => $period];

        $DB->beginTransaction();
        try {
            if (countElementsInTable(self::getTable(), $where) === 0) {
                try {
                    $DB->insert(self::getTable(), $where + ['sequence' => 0]);
                } catch (\RuntimeException $e) {
                    // Created meanwhile by a concurrent generation (unique key): use that row
                }
            }
            $DB->update(self::getTable(), ['sequence' => new QueryExpression($DB->quoteName('sequence') . ' + 1')], $where);
            $sequence = (int) ($DB->request(['SELECT' => 'sequence', 'FROM' => self::getTable(), 'WHERE' => $where])->current()['sequence'] ?? 0);
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return sprintf('%s%s%02d', $prefix, $period, $sequence);
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
                            `prefix` varchar(3) NOT NULL DEFAULT '',
                            `period` char(4) NOT NULL DEFAULT '' COMMENT 'YYMM',
                            `sequence` int NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `unicity` (`prefix`,`period`)
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
