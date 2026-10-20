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

use Migration;

/**
 * References of the proposals: the first three letters of the client and the date of the
 * generation (YYMMDD), e.g. SOC261019. Several proposals of a client the same day share it:
 * the version in the name of the generated document tells them apart.
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
     * Reference of a proposal of a client generated on that date (now by default)
     */
    public static function next(string $client_name, ?\DateTimeInterface $date = null): string
    {
        return self::getPrefix($client_name)
            . ($date ?? new \DateTimeImmutable($_SESSION['glpi_currenttime'] ?? 'now'))->format('ymd');
    }

    public static function install(Migration $migration)
    {
        // The sequences of the month are no longer used: the reference is the date of the day
        self::uninstall();
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
