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

class WizardEntityTest extends DbTestCase
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

    public function testSaveEntityCreatesGlpiEntity(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Test Entity WizardEntityTest',
            'entities_id' => 0,
        ]);

        $this->assertTrue($result['success'], 'Expected success=true but got: ' . ($result['message'] ?? ''));
        $this->assertGreaterThan(0, $result['entities_id']);
        $this->assertEquals(
            1,
            countElementsInTable('glpi_entities', ['id' => $result['entities_id']]),
            'Entity row not found in DB'
        );
    }

    public function testSaveEntityUpdatesSessionEntitiesId(): void
    {
        $this->login();

        WizardController::saveEntityAndReturn([
            'name'        => 'Session Entity Test',
            'entities_id' => 0,
        ]);

        $session = WizardController::getSession();
        $this->assertGreaterThan(0, $session['entities_id']);
    }

    public function testSaveEntityAdvancesStepTo2(): void
    {
        $this->login();

        WizardController::saveEntityAndReturn([
            'name'        => 'Step Advance Test',
            'entities_id' => 0,
        ]);

        $session = WizardController::getSession();
        $this->assertGreaterThanOrEqual(2, $session['step']);
    }

    public function testSaveEntityRejectsEmptyName(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => '',
            'entities_id' => 0,
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testSaveEntityUpdatesExistingEntity(): void
    {
        $this->login();

        $r1 = WizardController::saveEntityAndReturn([
            'name'        => 'Original Name',
            'entities_id' => 0,
        ]);
        $this->assertTrue($r1['success']);

        // Second call updates (session has entities_id now)
        $r2 = WizardController::saveEntityAndReturn([
            'name'        => 'Updated Name',
            'entities_id' => 0,
        ]);
        $this->assertTrue($r2['success']);
        $this->assertSame($r1['entities_id'], $r2['entities_id'], 'Should update the same entity');

        $entity = new \Entity();
        $entity->getFromDB($r1['entities_id']);
        $this->assertSame('Updated Name', $entity->fields['name']);
    }

    public function testSaveEntityStoresOptionalFields(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Entity With Fields',
            'entities_id' => 0,
            'phonenumber' => '0123456789',
            'email'       => 'test@example.com',
            'town'        => 'Paris',
        ]);

        $this->assertTrue($result['success']);
        $entity = new \Entity();
        $entity->getFromDB($result['entities_id']);
        $this->assertSame('0123456789', $entity->fields['phonenumber']);
        $this->assertSame('test@example.com', $entity->fields['email']);
        $this->assertSame('Paris', $entity->fields['town']);
    }
}
