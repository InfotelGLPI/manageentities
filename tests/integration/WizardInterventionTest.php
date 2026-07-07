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

class WizardInterventionTest extends DbTestCase
{
    use WizardTestHelpers;

    private int $contractstate_id = 0;

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

    /** Populate entity + contract data in session (no DB writes). */
    private function setupSessionData(): void
    {
        WizardController::saveEntityAndReturn([
            'name'        => 'Ent-' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);

        WizardController::saveContractAndReturn($this->minimalContractInput());

        $state = new \GlpiPlugin\Manageentities\ContractState();
        $this->contractstate_id = $state->add([
            'name'        => 'Active-' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);
    }

    public function testSaveInterventionStoresInSession(): void
    {
        $this->login();
        $this->setupSessionData();

        $result = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period Q1',
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-03-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result));

        $session = WizardController::getSession();
        $this->assertArrayHasKey(1, $session['interventions_data']);
        $this->assertSame('Period Q1', $session['interventions_data'][1]['fields']['name']);
        // Nothing written to DB yet
        $this->assertEquals(0, countElementsInTable('glpi_plugin_manageentities_contractdays', ['name' => 'Period Q1']));
    }

    public function testSaveInterventionRejectsMissingName(): void
    {
        $this->login();
        $this->setupSessionData();

        $result = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => '',
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        // Empty name is skipped — result succeeds but no intervention stored for that idx
        $this->assertTrue($result['success']);
        $session = WizardController::getSession();
        $this->assertArrayNotHasKey(1, $session['interventions_data']);
    }

    public function testSaveCriPriceStoresInSession(): void
    {
        $this->login();
        $this->setupSessionData();

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => 'Type-' . $this->getUniqueString(), 'entities_id' => 0]);

        $pr = WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 1,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 750.00,
            'is_default'                        => 1,
        ]);

        $this->assertTrue($pr['success'], json_encode($pr));
        $this->assertNotEmpty($pr['criprice_id']);

        $session = WizardController::getSession();
        $criprices = $session['interventions_data'][1]['criprices'];
        $this->assertCount(1, $criprices);
        $this->assertSame(750.00, reset($criprices)['price']);

        // Nothing written to DB yet
        $this->assertEquals(0, countElementsInTable('glpi_plugin_manageentities_criprices'));
    }

    public function testOnlyOneCriPriceAllowedPerIntervention(): void
    {
        $this->login();
        $this->setupSessionData();

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => 'Type-' . $this->getUniqueString(), 'entities_id' => 0]);

        $pr1 = WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 1,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 500.00,
            'is_default'                        => 1,
        ]);
        $this->assertTrue($pr1['success']);

        $pr2 = WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 1,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 600.00,
            'is_default'                        => 0,
        ]);
        $this->assertFalse($pr2['success'], 'Second rate should be rejected');

        $session = WizardController::getSession();
        $this->assertCount(1, $session['interventions_data'][1]['criprices']);
    }

    public function testStakeholderCannotExceedCreditDays(): void
    {
        $this->login();
        $this->setupSessionData();

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 5,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $user = new \User();
        $user_id = $user->add(['name' => 'sh-' . $this->getUniqueString(), 'password' => 'tp', 'password2' => 'tp']);

        $r1 = WizardController::addStakeholderAndReturn([
            'intervention_idx'     => 1,
            'users_id'             => $user_id,
            'number_affected_days' => 5,
        ]);
        $this->assertTrue($r1['success'], json_encode($r1));
        $this->assertEquals(0.0, (float)$r1['remaining_days']);

        $user2 = new \User();
        $user2_id = $user2->add(['name' => 'sh2-' . $this->getUniqueString(), 'password' => 'tp', 'password2' => 'tp']);
        $r2 = WizardController::addStakeholderAndReturn([
            'intervention_idx'     => 1,
            'users_id'             => $user2_id,
            'number_affected_days' => 1,
        ]);
        $this->assertFalse($r2['success'], 'Should reject assignment exceeding credit');
        $this->assertEquals(0.0, (float)$r2['remaining_days']);
    }

    public function testStakeholderAllowedWhenNbdayIsZero(): void
    {
        $this->login();
        $this->setupSessionData();

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-12-31',
                    'nbday'                                   => 0,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $user = new \User();
        $user_id = $user->add(['name' => 'sh-nolimit-' . $this->getUniqueString(), 'password' => 'tp', 'password2' => 'tp']);

        $r = WizardController::addStakeholderAndReturn([
            'intervention_idx'     => 1,
            'users_id'             => $user_id,
            'number_affected_days' => 99,
        ]);
        $this->assertTrue($r['success'], 'Should allow any days when nbday = 0');
        $this->assertNull($r['remaining_days'], 'remaining_days should be null when no limit');
    }

    public function testCommitCreatesInterventionAndCripriceInDb(): void
    {
        $this->login('glpi');
        $uid = $this->getUniqueString();

        WizardController::saveEntityAndReturn(['name' => "E-{$uid}", 'entities_id' => 0]);
        WizardController::saveContactsAndReturn(['contacts' => []]);
        WizardController::saveContractAndReturn($this->minimalContractInput(['name' => "C-{$uid}"]));
        WizardController::saveManagementTypeAndReturn(['date_signature' => '2026-01-01']);

        $state = new \GlpiPlugin\Manageentities\ContractState();
        $state_id = $state->add(['name' => "St-{$uid}", 'entities_id' => 0]);

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => "Period-{$uid}",
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-03-31',
                    'nbday'                                   => 10,
                    'plugin_manageentities_contractstates_id' => $state_id,
                ],
            ],
        ]);

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => "CT-{$uid}", 'entities_id' => 0]);

        WizardController::saveCriPriceAndReturn([
            'intervention_idx'                  => 1,
            'plugin_manageentities_critypes_id' => $critype_id,
            'price'                             => 750.00,
            'is_default'                        => 1,
        ]);

        $rc = WizardController::commitWizardAndReturn();
        $this->assertTrue($rc['success'], json_encode($rc));

        $this->assertEquals(1, countElementsInTable('glpi_plugin_manageentities_contractdays', ['name' => "Period-{$uid}"]));
        $row = (new \GlpiPlugin\Manageentities\ContractDay())->find(['name' => "Period-{$uid}"]);
        $contractday_id = (int)array_key_first($row);
        $this->assertGreaterThan(0, $contractday_id);
        $this->assertEquals(
            1,
            countElementsInTable('glpi_plugin_manageentities_criprices', ['plugin_manageentities_contractdays_id' => $contractday_id])
        );
    }
}
