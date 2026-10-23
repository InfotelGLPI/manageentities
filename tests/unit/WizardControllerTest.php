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
        $this->assertIsArray($session['contacts_data']);
        $this->assertEmpty($session['contacts_data']);
    }

    public function testBuildDefaultSessionHasEmptyContractdays(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertIsArray($session['interventions_data']);
        $this->assertEmpty($session['interventions_data']);
    }

    public function testBuildDefaultSessionHasRequiredKeys(): void
    {
        $session = WizardController::buildDefaultSession();
        foreach (['step', 'entities_id', 'entity_data', 'contacts_data', 'contract_data', 'management_data', 'interventions_data', 'documents_ids'] as $key) {
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
            'end_date'                                => '2026-12-31',
            'nbday'                                   => 10,
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

    public function testATemplateOnlyFillsTheEmptyFieldsOfTheContract(): void
    {
        $template = [
            "name"             => "Template name",
            "num"              => "TPL-001",
            "begin_date"       => "2026-01-01",
            "duration"         => 24,
            "contracttypes_id" => 3,
            "states_id"        => 2,
            "comment"          => "Template comment",
        ];
        // Prefilled from a won proposal: name, number, start date, comment
        $current = [
            "name"       => "Expertise & Souscription",
            "num"        => "SOC261019",
            "begin_date" => "2026-11-01",
            "comment"    => "From the proposal SOC261019",
            "duration"   => 0,
            "states_id"  => "",
        ];

        $merged = WizardController::mergeTemplateIntoContract($template, $current);

        // What the wizard defined is kept
        $this->assertSame("Expertise & Souscription", $merged["name"]);
        $this->assertSame("SOC261019", $merged["num"]);
        $this->assertSame("2026-11-01", $merged["begin_date"]);
        $this->assertSame("From the proposal SOC261019", $merged["comment"]);
        // The template fills what is still empty
        $this->assertSame(24, $merged["duration"]);
        $this->assertSame(3, $merged["contracttypes_id"]);
        $this->assertSame(2, $merged["states_id"]);
    }

    public function testATemplateFillsEverythingOnAnEmptyContract(): void
    {
        $template = ["name" => "Template name", "duration" => 12];

        $this->assertSame($template, WizardController::mergeTemplateIntoContract($template, []));
    }
}
