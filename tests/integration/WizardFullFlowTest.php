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

namespace GlpiPlugin\Manageentities\Tests\Integration;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\WizardController;

class WizardFullFlowTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['manageentities_wizard']);
    }

    public function tearDown(): void
    {
        unset($_SESSION['manageentities_wizard']);
        parent::tearDown();
    }

    public function testCompleteWizardFlow(): void
    {
        $this->login();
        $uid = $this->getUniqueString();

        // Step 1 — Entity
        $r1 = WizardController::saveEntityAndReturn([
            'name'        => "Entity-{$uid}",
            'entities_id' => 0,
            'email'       => "entity-{$uid}@example.com",
        ]);
        $this->assertTrue($r1['success'], 'Step 1 failed: ' . json_encode($r1));
        $entities_id = $r1['entities_id'];

        // Step 2 — Contacts (skip: optional)
        $r2 = WizardController::saveContactsAndReturn(['contacts' => []]);
        $this->assertTrue($r2['success'], 'Step 2 failed: ' . json_encode($r2));

        // Step 3 — Contract
        $r3 = WizardController::saveContractAndReturn([
            'name'        => "CTR-{$uid}",
            'entities_id' => $entities_id,
            'begin_date'  => '2026-01-01',
            'duration'    => 12,
        ]);
        $this->assertTrue($r3['success'], 'Step 3 failed: ' . json_encode($r3));
        $contracts_id = $r3['contracts_id'];

        // Step 4 — Management type
        $r4 = WizardController::saveManagementTypeAndReturn([
            'contracts_id'         => $contracts_id,
            'entities_id'          => $entities_id,
            'show_on_global_gantt' => 1,
            'cloud_client'         => 0,
        ]);
        $this->assertTrue($r4['success'], 'Step 4 failed: ' . json_encode($r4));
        $plugin_contract_id = $r4['plugin_contract_id'];

        // Create a contract state for step 5
        $state = new \GlpiPlugin\Manageentities\ContractState();
        $state_id = $state->add([
            'name'        => "State-{$uid}",
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $state_id);

        // Step 5 — Intervention
        $r5 = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => "Period Q1-{$uid}",
                    'entities_id'                             => $entities_id,
                    'contracts_id'                            => $contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-03-31',
                    'nbday'                                   => 10,
                    'report'                                  => 0,
                    'charged'                                 => 0,
                    'plugin_manageentities_contractstates_id' => $state_id,
                ],
            ],
        ]);
        $this->assertTrue($r5['success'], 'Step 5 failed: ' . json_encode($r5));
        $this->assertCount(1, $r5['contractdays']);

        // Verify all rows exist in DB
        $this->assertEquals(1, countElementsInTable('glpi_entities', ['id' => $entities_id]));
        $this->assertEquals(1, countElementsInTable('glpi_contracts', ['id' => $contracts_id]));
        $this->assertEquals(1, countElementsInTable('glpi_plugin_manageentities_contracts', ['id' => $plugin_contract_id]));
        $contractday_id = reset($r5['contractdays']);
        $this->assertEquals(1, countElementsInTable('glpi_plugin_manageentities_contractdays', ['id' => $contractday_id]));

        // Session should reflect all IDs
        $session = WizardController::getSession();
        $this->assertSame($entities_id, $session['entities_id']);
        $this->assertSame($contracts_id, $session['contracts_id']);
        $this->assertSame($plugin_contract_id, $session['plugin_contract_id']);
        $this->assertContains($contractday_id, $session['contractdays']);
    }

    public function testResetClearsSessionAndStartsOver(): void
    {
        $this->login();

        WizardController::saveEntityAndReturn([
            'name'        => 'Entity to Reset',
            'entities_id' => 0,
        ]);

        $sessionBefore = WizardController::getSession();
        $this->assertGreaterThan(0, $sessionBefore['entities_id']);

        unset($_SESSION['manageentities_wizard']);

        $sessionAfter = WizardController::getSession();
        $this->assertSame(0, $sessionAfter['entities_id']);
        $this->assertSame(1, $sessionAfter['step']);
    }

    public function testSessionContainsDocumentsIdsKey(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertArrayHasKey('documents_ids', $session);
        $this->assertIsArray($session['documents_ids']);
        $this->assertEmpty($session['documents_ids']);
    }

    public function testResetAndDeleteRemovesEntityAndContract(): void
    {
        $this->login();
        $uid = $this->getUniqueString();

        $r1 = WizardController::saveEntityAndReturn([
            'name'        => "ResetEnt-{$uid}",
            'entities_id' => 0,
        ]);
        $entities_id = $r1['entities_id'];

        $r3 = WizardController::saveContractAndReturn([
            'name'        => "ResetCTR-{$uid}",
            'entities_id' => $entities_id,
            'begin_date'  => '2026-01-01',
            'duration'    => 12,
        ]);
        $contracts_id = $r3['contracts_id'];

        $r4 = WizardController::saveManagementTypeAndReturn([
            'contracts_id' => $contracts_id,
            'entities_id'  => $entities_id,
        ]);
        $plugin_contract_id = $r4['plugin_contract_id'];

        // Verify objects exist before reset
        $this->assertEquals(1, countElementsInTable('glpi_entities', ['id' => $entities_id]));
        $this->assertEquals(1, countElementsInTable('glpi_contracts', ['id' => $contracts_id]));
        $this->assertEquals(1, countElementsInTable('glpi_plugin_manageentities_contracts', ['id' => $plugin_contract_id]));

        $result = WizardController::resetAndDeleteAndReturn();
        $this->assertTrue($result['success']);

        // Verify objects are deleted
        $this->assertEquals(0, countElementsInTable('glpi_entities', ['id' => $entities_id]));
        $this->assertEquals(0, countElementsInTable('glpi_contracts', ['id' => $contracts_id]));
        $this->assertEquals(0, countElementsInTable('glpi_plugin_manageentities_contracts', ['id' => $plugin_contract_id]));

        // Session cleared
        $sessionAfter = WizardController::getSession();
        $this->assertSame(0, $sessionAfter['entities_id']);
        $this->assertSame(1, $sessionAfter['step']);
    }
}
