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

use GlpiPlugin\Manageentities\Contract;

/**
 * Full definition of the remaining_days column, same as install/sql/empty.sql.
 *
 * Migration::fieldFormat() has no "decimal" shorthand and copies an unknown type verbatim,
 * so a bare "decimal" becomes DECIMAL(10,0) NULL in MySQL: 0.5 remaining days is then
 * stored, and listed, as 1.
 */
const PLUGIN_MANAGEENTITIES_REMAINING_DAYS_TYPE = "decimal(10,2) NOT NULL DEFAULT '0.00'";

/**
 * Add remaining_days column and backfill existing contracts.
 */
function addRemainingDaysColumn(): void
{
    $migration = new Migration(PLUGIN_MANAGEENTITIES_VERSION);
    $migration->addField(
        'glpi_plugin_manageentities_contracts',
        'remaining_days',
        PLUGIN_MANAGEENTITIES_REMAINING_DAYS_TYPE,
    );
    $migration->executeMigration();

    refreshAllRemainingDays();
}

/**
 * Repair the remaining_days column created as DECIMAL(10,0) NULL by older updates.
 */
function fixRemainingDaysColumnType(): void
{
    global $DB;

    $table  = 'glpi_plugin_manageentities_contracts';
    $fields = $DB->listFields($table, false);
    $column = $fields['remaining_days'] ?? null;
    if ($column === null
        || (strtolower($column['Type']) === 'decimal(10,2)' && $column['Null'] === 'NO')) {
        return;
    }

    // NOT NULL cannot be set while NULL rows remain (MySQL error 1265 in strict mode).
    $DB->update($table, ['remaining_days' => 0], ['remaining_days' => null]);

    $migration = new Migration(PLUGIN_MANAGEENTITIES_VERSION);
    $migration->changeField(
        $table,
        'remaining_days',
        'remaining_days',
        PLUGIN_MANAGEENTITIES_REMAINING_DAYS_TYPE,
    );
    $migration->executeMigration();
}

/**
 * Recompute remaining_days for every existing contract row.
 *
 * Run on every plugin update: before the ticket task hooks existed, the column was not
 * refreshed when tasks changed, so values stored by older versions may be stale.
 */
function refreshAllRemainingDays(): void
{
    Contract::updateAllRemainingDays();
}
