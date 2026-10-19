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

namespace GlpiPlugin\Manageentities\Tests\Integration;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\ContractDay;

/**
 * Alert of the monthly follow-up: interventions after the end of their period of contract
 */
class OldContractDayTest extends DbTestCase
{
    public function testOnlyTheInterventionsOfTheActiveEntitiesAreListed(): void
    {
        global $DB;

        $this->login();
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(\Entity::class, '_test_child_2', true);

        $tickets = [];
        foreach ([$child_1, $child_2] as $entities_id) {
            $tickets[$entities_id] = $this->createLateIntervention($entities_id);
        }

        $listed = static fn() => array_map(
            'intval',
            array_column(iterator_to_array($DB->request(ContractDay::queryOldContractDaywithInterventions('2026-01-01'))), 'tickets_id'),
        );

        $this->assertTrue(\Session::changeActiveEntities($child_1));
        $this->assertContains($tickets[$child_1], $listed());
        $this->assertNotContains($tickets[$child_2], $listed());

        $this->assertTrue(\Session::changeActiveEntities($child_2));
        $this->assertNotContains($tickets[$child_1], $listed());
        $this->assertContains($tickets[$child_2], $listed());
    }

    /**
     * Intervention of February 2026 on a period of contract ended in 2025
     *
     * @return int id of its ticket
     */
    private function createLateIntervention(int $entities_id): int
    {
        global $DB;

        $contracts_id = (int) $this->createItem(\Contract::class, [
            'name'        => 'Old contract ' . mt_rand(),
            'entities_id' => $entities_id,
        ])->getID();
        $DB->insert('glpi_plugin_manageentities_contractdays', [
            'name'         => 'Period 2025',
            'entities_id'  => $entities_id,
            'contracts_id' => $contracts_id,
            'begin_date'   => '2025-01-01',
            'end_date'     => '2025-12-31',
        ]);
        $contractdays_id = (int) $DB->insertId();

        $tickets_id = (int) $this->createItem(\Ticket::class, [
            'name'        => 'Late intervention ' . mt_rand(),
            'content'     => 'Late intervention',
            'entities_id' => $entities_id,
        ])->getID();
        $DB->insert('glpi_plugin_manageentities_cridetails', [
            'entities_id'                           => $entities_id,
            'contracts_id'                          => $contracts_id,
            'plugin_manageentities_contractdays_id' => $contractdays_id,
            'tickets_id'                            => $tickets_id,
            'date'                                  => '2026-02-15 10:00:00',
        ]);

        return $tickets_id;
    }
}
