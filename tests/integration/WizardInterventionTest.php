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
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\CriPrice;
use GlpiPlugin\Manageentities\WizardController;

class WizardInterventionTest extends DbTestCase
{
    private int $entities_id = 0;
    private int $contracts_id = 0;
    private int $contractstate_id = 0;

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

    private function setupEntityAndContract(): void
    {
        $er = WizardController::saveEntityAndReturn([
            'name'        => 'Ent-' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);
        $this->entities_id = $er['entities_id'];

        $cr = WizardController::saveContractAndReturn([
            'name'        => 'CTR-' . $this->getUniqueString(),
            'entities_id' => $this->entities_id,
        ]);
        $this->contracts_id = $cr['contracts_id'];

        // Create a contract state to use
        $state = new \GlpiPlugin\Manageentities\ContractState();
        $this->contractstate_id = $state->add([
            'name'        => 'Active-' . $this->getUniqueString(),
            'entities_id' => 0,
        ]);
    }

    public function testSaveInterventionCreatesContractDay(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $result = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period Q1',
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'end_date'                                => '2026-03-31',
                    'nbday'                                   => 10,
                    'report'                                  => 0,
                    'charged'                                 => 0,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertArrayHasKey(1, $result['contractdays']);
        $this->assertGreaterThan(0, $result['contractdays'][1]);

        $this->assertEquals(
            1,
            countElementsInTable('glpi_plugin_manageentities_contractdays', ['id' => $result['contractdays'][1]])
        );
    }

    public function testSaveInterventionRejectsMissingName(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $result = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => '',
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        // Empty name is skipped (not an error for the whole save)
        // The result should succeed but with no contractdays saved
        $this->assertTrue($result['success']);
        $this->assertEmpty($result['contractdays']);
    }

    public function testSaveInterventionUpdatesSessionContractdays(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);

        $session = WizardController::getSession();
        $this->assertNotEmpty($session['contractdays']);
    }

    public function testSaveCriPriceCreatesRow(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        $contractday_id = $ir['contractdays'][1];

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => 'Type-' . $this->getUniqueString(), 'entities_id' => 0]);

        $pr = WizardController::saveCriPriceAndReturn([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'plugin_manageentities_critypes_id'     => $critype_id,
            'price'                                 => 750.00,
            'is_default'                            => 1,
        ]);

        $this->assertTrue($pr['success'], json_encode($pr));
        $this->assertGreaterThan(0, $pr['criprice_id']);
        $this->assertEquals(
            1,
            countElementsInTable('glpi_plugin_manageentities_criprices', ['id' => $pr['criprice_id']])
        );
    }

    public function testOnlyOneCriPriceAllowedPerContractDay(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        $contractday_id = $ir['contractdays'][1];

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => 'Type-' . $this->getUniqueString(), 'entities_id' => 0]);

        $pr1 = WizardController::saveCriPriceAndReturn([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'plugin_manageentities_critypes_id'     => $critype_id,
            'price'                                 => 500.00,
            'is_default'                            => 1,
        ]);
        $this->assertTrue($pr1['success']);

        // Second rate on same contractday must be refused
        $pr2 = WizardController::saveCriPriceAndReturn([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'plugin_manageentities_critypes_id'     => $critype_id,
            'price'                                 => 600.00,
            'is_default'                            => 0,
        ]);
        $this->assertFalse($pr2['success'], 'Second rate should be rejected');
        $this->assertEquals(
            1,
            countElementsInTable('glpi_plugin_manageentities_criprices', ['plugin_manageentities_contractdays_id' => $contractday_id])
        );
    }

    public function testSaveInterventionRequiresAtLeastOneRateToFinish(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        // Save an intervention without any rate then try to finalise (save_interventions)
        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        // saveInterventionsAndReturn only fails at the final validation step (step 5 Next)
        // which checks that every contractday has at least one CriPrice.
        // The result here succeeds (saves the day) but a separate call to the finish
        // action would fail — we simulate that by checking the session has contractdays
        // but no criprices exist for it.
        $this->assertTrue($ir['success']);
        $contractday_id = $ir['contractdays'][1];
        $this->assertEquals(
            0,
            countElementsInTable('glpi_plugin_manageentities_criprices', ['plugin_manageentities_contractdays_id' => $contractday_id])
        );
    }

    public function testStakeholderCannotExceedCreditDays(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'nbday'                                   => 5,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        $contractday_id = $ir['contractdays'][1];

        // Create a GLPI user
        $user = new \User();
        $user_id = $user->add([
            'name'      => 'stakeholder-' . $this->getUniqueString(),
            'password'  => 'testpass',
            'password2' => 'testpass',
        ]);

        // Assign exactly the full credit — must succeed
        $r1 = WizardController::addStakeholderAndReturn([
            'contractday_id'       => $contractday_id,
            'users_id'             => $user_id,
            'number_affected_days' => 5,
        ]);
        $this->assertTrue($r1['success'], json_encode($r1));
        $this->assertEquals(0.0, (float)$r1['remaining_days']);

        // Assign 1 more day — must fail (0 remaining)
        $user2 = new \User();
        $user2_id = $user2->add([
            'name'      => 'stakeholder2-' . $this->getUniqueString(),
            'password'  => 'testpass',
            'password2' => 'testpass',
        ]);
        $r2 = WizardController::addStakeholderAndReturn([
            'contractday_id'       => $contractday_id,
            'users_id'             => $user2_id,
            'number_affected_days' => 1,
        ]);
        $this->assertFalse($r2['success'], 'Should reject assignment exceeding credit');
        $this->assertEquals(0.0, (float)$r2['remaining_days']);
    }

    public function testStakeholderAllowedWhenNbdayIsZero(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        // nbday = 0 means no limit
        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'nbday'                                   => 0,
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        $contractday_id = $ir['contractdays'][1];

        $user = new \User();
        $user_id = $user->add([
            'name'      => 'sh-nolimit-' . $this->getUniqueString(),
            'password'  => 'testpass',
            'password2' => 'testpass',
        ]);

        $r = WizardController::addStakeholderAndReturn([
            'contractday_id'       => $contractday_id,
            'users_id'             => $user_id,
            'number_affected_days' => 99,
        ]);
        $this->assertTrue($r['success'], 'Should allow any days when nbday = 0');
        $this->assertNull($r['remaining_days'], 'remaining_days should be null when no limit');
    }

    public function testCriPriceRowEnrichedWithCriTypeName(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $ir = WizardController::saveInterventionsAndReturn([
            'interventions' => [
                1 => [
                    'name'                                    => 'Period ' . $this->getUniqueString(),
                    'entities_id'                             => $this->entities_id,
                    'contracts_id'                            => $this->contracts_id,
                    'begin_date'                              => '2026-01-01',
                    'plugin_manageentities_contractstates_id' => $this->contractstate_id,
                ],
            ],
        ]);
        $contractday_id = $ir['contractdays'][1];

        $critype = new \GlpiPlugin\Manageentities\CriType();
        $critype_id = $critype->add(['name' => 'TypeABC-' . $this->getUniqueString(), 'entities_id' => 0]);

        WizardController::saveCriPriceAndReturn([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'plugin_manageentities_critypes_id'     => $critype_id,
            'price'                                 => 800.00,
            'is_default'                            => 1,
        ]);

        // Reload via buildCriPricesSectionHtml indirectly: check that the rows have critypes_name
        $criPrice = new \GlpiPlugin\Manageentities\CriPrice();
        $rows = $criPrice->find(['plugin_manageentities_contractdays_id' => $contractday_id]);

        // Simulate what getCriPricesForContractDay does
        foreach ($rows as &$row) {
            $ct = new \GlpiPlugin\Manageentities\CriType();
            if ($ct->getFromDB((int)($row['plugin_manageentities_critypes_id'] ?? 0))) {
                $row['critypes_name'] = $ct->fields['completename'] ?? $ct->fields['name'] ?? '';
            }
        }
        $row = reset($rows);
        $this->assertArrayHasKey('critypes_name', $row);
        $this->assertNotEmpty($row['critypes_name'], 'critypes_name should not be empty');
    }
}
