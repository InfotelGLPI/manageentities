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

    private function createEntity(): int
    {
        $r = WizardController::saveEntityAndReturn([
            'name'        => 'Entity for Contract ' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);
        return $r['entities_id'];
    }

    public function testSaveContractCreatesGlpiContract(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $result = WizardController::saveContractAndReturn([
            'name'        => 'CTR-TEST-' . $this->getUniqueString(),
            'entities_id' => $entities_id,
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertGreaterThan(0, $result['contracts_id']);
        $this->assertEquals(
            1,
            countElementsInTable('glpi_contracts', ['id' => $result['contracts_id']])
        );
    }

    public function testSaveContractRejectsEmptyName(): void
    {
        $this->login();

        $result = WizardController::saveContractAndReturn([
            'name'        => '',
            'entities_id' => 0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testSaveContractAdvancesStepTo4(): void
    {
        $this->login();
        $this->createEntity();

        WizardController::saveContractAndReturn([
            'name'        => 'CTR-' . $this->getUniqueString(),
            'entities_id' => WizardController::getSession()['entities_id'],
        ]);

        $session = WizardController::getSession();
        $this->assertGreaterThanOrEqual(4, $session['step']);
    }

    public function testSaveManagementTypeCreatesPluginRow(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $cr = WizardController::saveContractAndReturn([
            'name'        => 'CTR-' . $this->getUniqueString(),
            'entities_id' => $entities_id,
        ]);
        $this->assertTrue($cr['success']);

        $mr = WizardController::saveManagementTypeAndReturn([
            'contracts_id' => $cr['contracts_id'],
            'entities_id'  => $entities_id,
        ]);

        $this->assertTrue($mr['success'], $mr['message'] ?? '');
        $this->assertGreaterThan(0, $mr['plugin_contract_id']);
        $this->assertEquals(
            1,
            countElementsInTable(
                'glpi_plugin_manageentities_contracts',
                ['id' => $mr['plugin_contract_id']]
            )
        );
    }

    public function testSaveManagementTypeFailsWithoutContracts_id(): void
    {
        $this->login();

        // Fresh session — no contract saved yet
        $result = WizardController::saveManagementTypeAndReturn([
            'contracts_id' => 0,
            'entities_id'  => 0,
        ]);

        $this->assertFalse($result['success']);
    }

    public function testSaveManagementTypePersistsBooleanFields(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $cr = WizardController::saveContractAndReturn([
            'name'        => 'CTR-' . $this->getUniqueString(),
            'entities_id' => $entities_id,
        ]);

        $mr = WizardController::saveManagementTypeAndReturn([
            'contracts_id'             => $cr['contracts_id'],
            'entities_id'              => $entities_id,
            'show_on_global_gantt'     => 1,
            'cloud_client'             => 1,
            'internet_publication'     => 0,
        ]);

        $this->assertTrue($mr['success']);

        global $DB;
        $row = $DB->request([
            'SELECT' => ['show_on_global_gantt', 'cloud_client', 'internet_publication'],
            'FROM'   => 'glpi_plugin_manageentities_contracts',
            'WHERE'  => ['id' => $mr['plugin_contract_id']],
        ])->current();

        $this->assertSame(1, (int)$row['show_on_global_gantt']);
        $this->assertSame(1, (int)$row['cloud_client']);
        $this->assertSame(0, (int)$row['internet_publication']);
    }
}
