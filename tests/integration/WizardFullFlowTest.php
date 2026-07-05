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

        // Step 1 — Entity (stored in session only)
        $r1 = WizardController::saveEntityAndReturn([
            'name'        => "Entity-{$uid}",
            'entities_id' => 0,
            'email'       => "entity-{$uid}@example.com",
        ]);
        $this->assertTrue($r1['success'], 'Step 1 failed: ' . json_encode($r1));

        // Step 2 — Contacts (optional, skip)
        $r2 = WizardController::saveContactsAndReturn(['contacts' => []]);
        $this->assertTrue($r2['success'], 'Step 2 failed: ' . json_encode($r2));

        // Step 3 — Contract (stored in session only)
        $r3 = WizardController::saveContractAndReturn([
            'name'       => "CTR-{$uid}",
            'begin_date' => '2026-01-01',
            'duration'   => 12,
        ]);
        $this->assertTrue($r3['success'], 'Step 3 failed: ' . json_encode($r3));

        // Step 4 — Management type (stored in session only)
        $r4 = WizardController::saveManagementTypeAndReturn([
            'date_signature'       => '2026-01-01',
            'show_on_global_gantt' => 1,
            'cloud_client'         => 0,
        ]);
        $this->assertTrue($r4['success'], 'Step 4 failed: ' . json_encode($r4));

        // Create a contract state for step 5
        $state = new \GlpiPlugin\Manageentities\ContractState();
        $state_id = $state->add([
            'name'        => "State-{$uid}",
            'entities_id' => 0,
        ]);
        $this->assertGreaterThan(0, $state_id);

        // Step 5 — Intervention (stored in session only)
        $r5 = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => "Period Q1-{$uid}",
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-03-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $state_id,
                ],
            ],
        ]);
        $this->assertTrue($r5['success'], 'Step 5 failed: ' . json_encode($r5));

        // Verify session holds all data — nothing in DB yet
        $session = WizardController::getSession();
        $this->assertSame("Entity-{$uid}", $session['entity_data']['name']);
        $this->assertSame("CTR-{$uid}", $session['contract_data']['name']);
        $this->assertNotEmpty($session['management_data']);
        $this->assertArrayHasKey(1, $session['interventions_data']);
        $this->assertEquals(0, countElementsInTable('glpi_entities', ['name' => "Entity-{$uid}"]));
        $this->assertEquals(0, countElementsInTable('glpi_contracts', ['name' => "CTR-{$uid}"]));

        // Add a CriPrice to satisfy the "at least one rate" requirement
        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => "CT-{$uid}", 'entities_id' => 0]);

        $rcp = WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 1,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 500.00,
            'is_default'                        => 1,
        ]);
        $this->assertTrue($rcp['success'], json_encode($rcp));

        // Commit — writes everything to DB
        $rc = WizardController::commitWizardAndReturn();
        $this->assertTrue($rc['success'], 'Commit failed: ' . json_encode($rc));
        $this->assertArrayHasKey('redirect_url', $rc);

        // Verify all rows exist in DB
        $this->assertEquals(1, countElementsInTable('glpi_entities', ['name' => "Entity-{$uid}"]));
        $this->assertEquals(1, countElementsInTable('glpi_contracts', ['name' => "CTR-{$uid}"]));
        $this->assertEquals(1, countElementsInTable('glpi_plugin_manageentities_contractdays', ['name' => "Period Q1-{$uid}"]));

        // Session should be cleared after commit
        $sessionAfter = WizardController::getSession();
        $this->assertEmpty($sessionAfter['entity_data']);
        $this->assertEmpty($sessionAfter['contract_data']);
        $this->assertSame(1, $sessionAfter['step']);
    }

    public function testResetClearsSessionWithoutTouchingDb(): void
    {
        $this->login();
        $uid = $this->getUniqueString();

        WizardController::saveEntityAndReturn([
            'name'        => "Entity-Reset-{$uid}",
            'entities_id' => 0,
        ]);

        $sessionBefore = WizardController::getSession();
        $this->assertSame("Entity-Reset-{$uid}", $sessionBefore['entity_data']['name']);

        // Nothing was written to DB
        $this->assertEquals(0, countElementsInTable('glpi_entities', ['name' => "Entity-Reset-{$uid}"]));

        unset($_SESSION['manageentities_wizard']);

        $sessionAfter = WizardController::getSession();
        $this->assertEmpty($sessionAfter['entity_data']);
        $this->assertSame(1, $sessionAfter['step']);
    }

    public function testSessionContainsDocumentsIdsKey(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertArrayHasKey('documents_ids', $session);
        $this->assertIsArray($session['documents_ids']);
        $this->assertEmpty($session['documents_ids']);
    }

    /**
     * resetAndDelete() only deletes uploaded documents (orphan files).
     * There is nothing else to delete — no DB rows exist before commit.
     */
    public function testResetAndDeleteOnlyClearsSessionWhenNoDocuments(): void
    {
        $this->login();
        $uid = $this->getUniqueString();

        WizardController::saveEntityAndReturn(['name' => "ResetEnt-{$uid}", 'entities_id' => 0]);
        WizardController::saveContractAndReturn(['name' => "ResetCTR-{$uid}"]);
        WizardController::saveManagementTypeAndReturn(['date_signature' => '2026-01-01']);

        // Nothing is in DB (session-only)
        $this->assertEquals(0, countElementsInTable('glpi_entities', ['name' => "ResetEnt-{$uid}"]));
        $this->assertEquals(0, countElementsInTable('glpi_contracts', ['name' => "ResetCTR-{$uid}"]));

        $result = WizardController::resetAndDeleteAndReturn();
        $this->assertTrue($result['success']);

        // Session cleared
        $sessionAfter = WizardController::getSession();
        $this->assertEmpty($sessionAfter['entity_data']);
        $this->assertSame(1, $sessionAfter['step']);
    }
}
