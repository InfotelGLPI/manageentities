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
 * Add remaining_days column and backfill existing contracts.
 */
function addRemainingDaysColumn(): void
{
    $migration = new Migration(PLUGIN_MANAGEENTITIES_VERSION);
    $migration->addField(
        'glpi_plugin_manageentities_contracts',
        'remaining_days',
        'decimal',
        ['value' => '0.00'],
    );
    $migration->executeMigration();

    refreshAllRemainingDays();
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
