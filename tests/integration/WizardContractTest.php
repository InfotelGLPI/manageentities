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

class WizardContractTest extends DbTestCase
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

    /** Populate entity and contract data in session (no DB writes). */
    private function prepareEntityAndContractInSession(): void
    {
        WizardController::saveEntityAndReturn([
            'name'        => 'Entity for Contract ' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);
    }

    public function testSaveContractStoresDataInSession(): void
    {
        $this->login();
        $this->prepareEntityAndContractInSession();

        $result = WizardController::saveContractAndReturn([
            'name' => 'CTR-TEST-' . $this->getUniqueString(),
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');

        $session = WizardController::getSession();
        $this->assertNotEmpty($session['contract_data']['name']);
        // No DB write yet
        $this->assertEquals(0, countElementsInTable('glpi_contracts', ['name' => $session['contract_data']['name']]));
    }

    public function testSaveContractRejectsEmptyName(): void
    {
        $this->login();

        $result = WizardController::saveContractAndReturn([
            'name' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testSaveContractAdvancesStepTo4(): void
    {
        $this->login();
        $this->prepareEntityAndContractInSession();

        WizardController::saveContractAndReturn([
            'name' => 'CTR-' . $this->getUniqueString(),
        ]);

        $session = WizardController::getSession();
        $this->assertGreaterThanOrEqual(4, $session['step']);
    }

    public function testSaveManagementTypeStoresDataInSession(): void
    {
        $this->login();
        $this->prepareEntityAndContractInSession();

        WizardController::saveContractAndReturn(['name' => 'CTR-' . $this->getUniqueString()]);

        $mr = WizardController::saveManagementTypeAndReturn([
            'date_signature'       => '2026-01-01',
            'show_on_global_gantt' => 1,
        ]);

        $this->assertTrue($mr['success'], $mr['message'] ?? '');

        $session = WizardController::getSession();
        $this->assertNotEmpty($session['management_data']);
        $this->assertSame(1, $session['management_data']['show_on_global_gantt']);
        // cloud_client and internet_publication are managed by EditorSubscription, not management_data
        $this->assertArrayNotHasKey('cloud_client',         $session['management_data']);
        $this->assertArrayNotHasKey('internet_publication', $session['management_data']);
    }

    public function testSaveManagementTypeFailsWithoutDateSignature(): void
    {
        $this->login();

        $result = WizardController::saveManagementTypeAndReturn([
            'date_signature' => '',
        ]);

        $this->assertFalse($result['success']);
    }

    public function testCommitCreatesContractAndPluginContractInDb(): void
    {
        $this->login('glpi');
        $uid = $this->getUniqueString();

        WizardController::saveEntityAndReturn(['name' => "Ent-{$uid}", 'entities_id' => 0]);
        WizardController::saveContactsAndReturn(['contacts' => []]);
        WizardController::saveContractAndReturn(['name' => "CTR-{$uid}", 'begin_date' => '2026-01-01', 'duration' => 12]);
        WizardController::saveManagementTypeAndReturn([
            'date_signature'       => '2026-01-01',
            'show_on_global_gantt' => 1,
        ]);

        $state = new \GlpiPlugin\Manageentities\ContractState();
        $state_id = $state->add(['name' => "St-{$uid}", 'entities_id' => 0]);

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                0 => [
                    'name'                                    => "P-{$uid}",
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 5,
                    'plugin_manageentities_contractstates_id' => $state_id,
                ],
            ],
        ]);

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => "CT-{$uid}", 'entities_id' => 0]);

        WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 0,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 500.00,
            'is_default'                        => 1,
        ]);

        $rc = WizardController::commitWizardAndReturn();
        $this->assertTrue($rc['success'], json_encode($rc));

        $this->assertEquals(1, countElementsInTable('glpi_contracts', ['name' => "CTR-{$uid}"]));
        $this->assertEquals(1, countElementsInTable('glpi_entities', ['name' => "Ent-{$uid}"]));
    }
}
