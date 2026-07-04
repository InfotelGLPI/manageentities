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

use GlpiPlugin\Manageentities\Contract;

/**
 * Add remaining_days column and backfill existing contracts.
 */
function addRemainingDaysColumn(): void
{
    global $DB;

    $migration = new Migration(PLUGIN_MANAGEENTITIES_VERSION);
    $migration->addField(
        'glpi_plugin_manageentities_contracts',
        'remaining_days',
        'decimal',
        ['value' => '0.00']
    );
    $migration->executeMigration();

    // Backfill: recompute remaining days for every existing contract row
    $iterator = $DB->request([
        'SELECT' => ['contracts_id'],
        'FROM'   => 'glpi_plugin_manageentities_contracts',
    ]);

    foreach ($iterator as $row) {
        Contract::updateRemainingDays((int)$row['contracts_id']);
    }
}
