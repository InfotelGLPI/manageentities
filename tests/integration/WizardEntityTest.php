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
use GlpiPlugin\Manageentities\WizardController;

class WizardEntityTest extends DbTestCase
{
    use WizardTestHelpers;

    public function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['manageentities_wizard']);
        $this->setUpWizardContractTypes();
    }

    public function tearDown(): void
    {
        unset($_SESSION['manageentities_wizard']);
        parent::tearDown();
    }

    public function testSaveEntityStoresDataInSession(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Test Entity WizardEntityTest',
            'entities_id' => $this->wizardParentEntity(),
        ]);

        $this->assertTrue($result['success'], 'Expected success=true but got: ' . ($result['message'] ?? ''));

        $session = WizardController::getSession();
        $this->assertSame('Test Entity WizardEntityTest', $session['entity_data']['name']);
        // No DB write yet
        $existing = countElementsInTable('glpi_entities', ['name' => 'Test Entity WizardEntityTest']);
        $this->assertEquals(0, $existing, 'Entity must not be in DB before commit');
    }

    public function testSaveEntityDoesNotWriteToDb(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Session Entity Test',
            'entities_id' => $this->wizardParentEntity(),
        ]);

        $this->assertTrue($result['success']);
        // entities_id stays 0 in session — nothing created in DB yet
        $session = WizardController::getSession();
        $this->assertSame(0, $session['entities_id']);
    }

    public function testSaveEntityAdvancesStepTo2(): void
    {
        $this->login();

        WizardController::saveEntityAndReturn([
            'name'        => 'Step Advance Test',
            'entities_id' => $this->wizardParentEntity(),
        ]);

        $session = WizardController::getSession();
        $this->assertGreaterThanOrEqual(2, $session['step']);
    }

    public function testSaveEntityRejectsEmptyName(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => '',
            'entities_id' => $this->wizardParentEntity(),
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testSaveEntityOverwritesPreviousSessionData(): void
    {
        $this->login();

        WizardController::saveEntityAndReturn([
            'name'        => 'Original Name',
            'entities_id' => $this->wizardParentEntity(),
        ]);

        $r2 = WizardController::saveEntityAndReturn([
            'name'        => 'Updated Name',
            'entities_id' => $this->wizardParentEntity(),
        ]);
        $this->assertTrue($r2['success']);

        $session = WizardController::getSession();
        $this->assertSame('Updated Name', $session['entity_data']['name']);
    }

    public function testSaveEntityStoresOptionalFields(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Entity With Fields',
            'entities_id' => $this->wizardParentEntity(),
            'phonenumber' => '0123456789',
            'email'       => 'test@example.com',
            'town'        => 'Paris',
        ]);

        $this->assertTrue($result['success']);

        $session = WizardController::getSession();
        // Phone numbers are stored with space separators
        $this->assertSame('01 23 45 67 89', $session['entity_data']['phonenumber']);
        $this->assertSame('test@example.com', $session['entity_data']['email']);
        $this->assertSame('Paris', $session['entity_data']['town']);
    }

    public function testSaveEntityNormalizesPhoneAndWebsite(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Entity Normalized',
            'entities_id' => $this->wizardParentEntity(),
            'phonenumber' => '+33.1.23.45.67.89',
            'fax'         => '01-23-45-67-89',
            'email'       => ' contact@example.com ',
            'website'     => 'www.example.com',
        ]);

        $this->assertTrue($result['success']);

        $session = WizardController::getSession();
        $this->assertSame('+33 1 23 45 67 89', $session['entity_data']['phonenumber']);
        $this->assertSame('01 23 45 67 89', $session['entity_data']['fax']);
        $this->assertSame('contact@example.com', $session['entity_data']['email']);
        $this->assertSame('https://www.example.com', $session['entity_data']['website']);
    }

    public function testSaveEntityRejectsInvalidEmailAndWebsite(): void
    {
        $this->login();

        $result = WizardController::saveEntityAndReturn([
            'name'        => 'Entity Invalid',
            'entities_id' => $this->wizardParentEntity(),
            'email'       => 'contact@example',
            'website'     => 'javascript:alert(1)',
        ]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertArrayHasKey('website', $result['errors']);
        $this->assertEmpty(WizardController::getSession()['entity_data']);
    }

    public function testSaveContactsNormalizesNamesAndPhones(): void
    {
        $this->login();

        $result = WizardController::saveContactsAndReturn(['contacts' => [
            1 => [
                'name'      => 'dupont-martin',
                'firstname' => 'éLODIE',
                'phone'     => '01.23.45.67.89',
                'mobile'    => '0612345678',
                'email'     => 'elodie@example.com',
            ],
        ]]);

        $this->assertTrue($result['success']);

        $contact = WizardController::getSession()['contacts_data'][1];
        $this->assertSame('DUPONT-MARTIN', $contact['name']);
        $this->assertSame('Élodie', $contact['firstname']);
        $this->assertSame('01 23 45 67 89', $contact['phone']);
        $this->assertSame('06 12 34 56 78', $contact['mobile']);
    }

    public function testSaveContactsRejectsInvalidEmail(): void
    {
        $this->login();

        $result = WizardController::saveContactsAndReturn(['contacts' => [
            1 => ['name' => 'Durand', 'firstname' => 'Paul', 'email' => 'paul@'],
        ]]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertSame(1, $result['contact_idx']);
    }

    public function testCommitCreatesEntityInDb(): void
    {
        $this->login('glpi');
        $uid = $this->getUniqueString();

        $r1 = WizardController::saveEntityAndReturn([
            'name'        => "CommitEnt-{$uid}",
            'entities_id' => $this->wizardParentEntity(),
        ]);
        $this->assertTrue($r1['success']);

        $r2 = WizardController::saveContactsAndReturn(['contacts' => []]);
        $this->assertTrue($r2['success']);

        $r3 = WizardController::saveContractAndReturn($this->minimalContractInput(['name' => "CTR-{$uid}"]));
        $this->assertTrue($r3['success']);

        $r4 = WizardController::saveManagementTypeAndReturn([
            'date_signature' => '2026-01-01',
        ]);
        $this->assertTrue($r4['success']);

        $state = new \GlpiPlugin\Manageentities\ContractState();
        $state_id = $state->add(['name' => "St-{$uid}", 'entities_id' => 0]);

        $r5 = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                0 => [
                    'name'                                    => "Period-{$uid}",
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $state_id,
                ],
            ],
        ]);
        $this->assertTrue($r5['success']);

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => "CT-{$uid}", 'entities_id' => 0]);

        $rcp = WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 0,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 500.00,
            'is_default'                        => 1,
        ]);
        $this->assertTrue($rcp['success']);

        $rc = WizardController::commitWizardAndReturn();
        $this->assertTrue($rc['success'], json_encode($rc));

        $this->assertEquals(1, countElementsInTable('glpi_entities', ['name' => "CommitEnt-{$uid}"]));
    }
}
