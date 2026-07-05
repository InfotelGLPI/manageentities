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

namespace GlpiPlugin\Manageentities\Tests\Unit;

use GlpiPlugin\Manageentities\WizardController;
use PHPUnit\Framework\TestCase;

class WizardControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // buildDefaultSession
    // -------------------------------------------------------------------------

    public function testBuildDefaultSessionHasStep1(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertSame(1, $session['step']);
    }

    public function testBuildDefaultSessionHasZeroEntityId(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertSame(0, $session['entities_id']);
    }

    public function testBuildDefaultSessionHasEmptyContacts(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertIsArray($session['contacts']);
        $this->assertEmpty($session['contacts']);
    }

    public function testBuildDefaultSessionHasEmptyContractdays(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertIsArray($session['contractdays']);
        $this->assertEmpty($session['contractdays']);
    }

    public function testBuildDefaultSessionHasRequiredKeys(): void
    {
        $session = WizardController::buildDefaultSession();
        foreach (['step', 'entities_id', 'contracts_id', 'plugin_contract_id', 'contacts', 'contractdays', 'documents_ids'] as $key) {
            $this->assertArrayHasKey($key, $session, "Missing key: {$key}");
        }
    }

    public function testBuildDefaultSessionHasEmptyDocumentsIds(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertIsArray($session['documents_ids']);
        $this->assertEmpty($session['documents_ids']);
    }

    // -------------------------------------------------------------------------
    // validateEntityInput
    // -------------------------------------------------------------------------

    public function testValidateEntityInputAcceptsValidName(): void
    {
        $result = WizardController::validateEntityInput(['name' => 'My Entity']);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateEntityInputRejectsEmptyName(): void
    {
        $result = WizardController::validateEntityInput(['name' => '']);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testValidateEntityInputRejectsMissingName(): void
    {
        $result = WizardController::validateEntityInput([]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testValidateEntityInputRejectsWhitespaceName(): void
    {
        $result = WizardController::validateEntityInput(['name' => '   ']);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    // -------------------------------------------------------------------------
    // validateContactInput
    // -------------------------------------------------------------------------

    public function testValidateContactInputAcceptsValidLastName(): void
    {
        $result = WizardController::validateContactInput(['name' => 'Dupont', 'firstname' => 'Jean']);
        $this->assertTrue($result['valid']);
    }

    public function testValidateContactInputRejectsEmptyLastName(): void
    {
        $result = WizardController::validateContactInput(['name' => '']);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    // -------------------------------------------------------------------------
    // validateContractInput
    // -------------------------------------------------------------------------

    public function testValidateContractInputAcceptsValidName(): void
    {
        $result = WizardController::validateContractInput([
            'name'              => 'CTR-2026',
            'num'               => 'N-001',
            'begin_date'        => '2026-01-01',
            'duration'          => 12,
            'contracttypes_id'  => 1,
            'states_id'         => 1,
        ]);
        $this->assertTrue($result['valid']);
    }

    public function testValidateContractInputRejectsEmptyName(): void
    {
        $result = WizardController::validateContractInput([]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    // -------------------------------------------------------------------------
    // validateInterventionInput
    // -------------------------------------------------------------------------

    public function testValidateInterventionInputAcceptsFullInput(): void
    {
        $result = WizardController::validateInterventionInput([
            'name'                                    => 'Period Q1',
            'begin_date'                              => '2026-01-01',
            'plugin_manageentities_contractstates_id' => 1,
        ]);
        $this->assertTrue($result['valid']);
    }

    public function testValidateInterventionInputRejectsMissingName(): void
    {
        $result = WizardController::validateInterventionInput([
            'begin_date'                              => '2026-01-01',
            'plugin_manageentities_contractstates_id' => 1,
        ]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testValidateInterventionInputRejectsMissingBeginDate(): void
    {
        $result = WizardController::validateInterventionInput([
            'name'                                    => 'Period',
            'plugin_manageentities_contractstates_id' => 1,
        ]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('begin_date', $result['errors']);
    }

    public function testValidateInterventionInputRejectsMissingState(): void
    {
        $result = WizardController::validateInterventionInput([
            'name'       => 'Period',
            'begin_date' => '2026-01-01',
        ]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('plugin_manageentities_contractstates_id', $result['errors']);
    }
}
