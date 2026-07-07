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

/**
 * Verifies that two concurrent wizard instances (two browser tabs) do not
 * overwrite each other's session data.
 *
 * Each test simulates a different wizard_id by injecting it into $_GET['wid']
 * before calling WizardController static methods, then restoring state.
 */
class WizardIsolationTest extends DbTestCase
{
    private string $widA = '';
    private string $widB = '';

    public function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['manageentities_wizard']);
        $this->widA = WizardController::generateWizardId();
        $this->widB = WizardController::generateWizardId();
    }

    public function tearDown(): void
    {
        unset($_SESSION['manageentities_wizard'], $_GET['wid'], $_POST['wid']);
        parent::tearDown();
    }

    private function withWid(string $wid, callable $fn): mixed
    {
        $prev = $_GET['wid'] ?? null;
        $_GET['wid'] = $wid;
        try {
            return $fn();
        } finally {
            if ($prev === null) {
                unset($_GET['wid']);
            } else {
                $_GET['wid'] = $prev;
            }
        }
    }

    // -------------------------------------------------------------------------

    public function testGenerateWizardIdIsUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = WizardController::generateWizardId();
        }
        $this->assertCount(100, array_unique($ids), 'wizard IDs must be unique');
    }

    public function testGenerateWizardIdFormat(): void
    {
        $wid = WizardController::generateWizardId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $wid);
    }

    public function testTwoInstancesDoNotShareEntityData(): void
    {
        $this->login();
        $uidA = $this->getUniqueString();
        $uidB = $this->getUniqueString();

        $this->withWid($this->widA, fn() => WizardController::saveEntityAndReturn([
            'name'        => "EntityA-{$uidA}",
            'entities_id' => 0,
        ]));

        $this->withWid($this->widB, fn() => WizardController::saveEntityAndReturn([
            'name'        => "EntityB-{$uidB}",
            'entities_id' => 0,
        ]));

        $sessionA = $this->withWid($this->widA, fn() => WizardController::getSession());
        $sessionB = $this->withWid($this->widB, fn() => WizardController::getSession());

        $this->assertSame("EntityA-{$uidA}", $sessionA['entity_data']['name']);
        $this->assertSame("EntityB-{$uidB}", $sessionB['entity_data']['name']);
    }

    public function testResetOneInstanceLeavesOtherIntact(): void
    {
        $this->login();
        $uid = $this->getUniqueString();

        $this->withWid($this->widA, fn() => WizardController::saveEntityAndReturn([
            'name'        => "EntityA-{$uid}",
            'entities_id' => 0,
        ]));
        $this->withWid($this->widB, fn() => WizardController::saveEntityAndReturn([
            'name'        => "EntityB-{$uid}",
            'entities_id' => 0,
        ]));

        // Reset only widB
        $this->withWid($this->widB, fn() => WizardController::resetAndDeleteAndReturn());

        // widA must be untouched
        $sessionA = $this->withWid($this->widA, fn() => WizardController::getSession());
        $this->assertSame("EntityA-{$uid}", $sessionA['entity_data']['name']);

        // widB must be back to default
        $sessionB = $this->withWid($this->widB, fn() => WizardController::getSession());
        $this->assertEmpty($sessionB['entity_data']);
    }

    public function testWizardModeIsPerInstance(): void
    {
        $this->login();

        $this->withWid($this->widA, fn() => WizardController::chooseModeAndReturn(['wizard_mode' => 'new_entity']));
        $this->withWid($this->widB, fn() => WizardController::chooseModeAndReturn(['wizard_mode' => 'existing_entity']));

        $sessionA = $this->withWid($this->widA, fn() => WizardController::getSession());
        $sessionB = $this->withWid($this->widB, fn() => WizardController::getSession());

        $this->assertSame('new_entity',      $sessionA['wizard_mode']);
        $this->assertSame('existing_entity', $sessionB['wizard_mode']);
    }

    public function testStepProgressIsPerInstance(): void
    {
        $this->login();

        $this->withWid($this->widA, fn() => WizardController::saveEntityAndReturn([
            'name'        => 'EA-' . $this->getUniqueString(),
            'entities_id' => 0,
        ]));
        $this->withWid($this->widA, fn() => WizardController::saveContactsAndReturn(['contacts' => []]));

        // widB still at step 1
        $sessionA = $this->withWid($this->widA, fn() => WizardController::getSession());
        $sessionB = $this->withWid($this->widB, fn() => WizardController::getSession());

        $this->assertGreaterThanOrEqual(3, $sessionA['step']);
        $this->assertSame(1, $sessionB['step']);
    }

    public function testCurrentWizardIdAcceptsValidWid(): void
    {
        $_GET['wid'] = $this->widA;
        $this->assertSame($this->widA, WizardController::currentWizardId());
    }

    public function testCurrentWizardIdFallsBackToDefaultForInvalidWid(): void
    {
        $_GET['wid'] = 'not-a-valid-wid!!';
        $this->assertSame('default', WizardController::currentWizardId());
        unset($_GET['wid']);
        $this->assertSame('default', WizardController::currentWizardId());
    }

    public function testLegacyFlatSessionMigratedOnFirstAccess(): void
    {
        // Simulate a session written by the old (pre-wid) code
        $_SESSION['manageentities_wizard'] = [
            'wizard_mode'        => 'new_entity',
            'step'               => 3,
            'entity_data'        => ['name' => 'OldEntity'],
            'entities_id'        => 0,
            'contacts_data'      => [],
            'subscription_data'  => [],
            'contract_data'      => [],
            'contract_prefill'   => [],
            'management_data'    => [],
            'documents_ids'      => [],
            'interventions_data' => [],
        ];

        // Reading with wid='default' must transparently migrate + return the old data
        $_GET['wid'] = 'default';
        $session = WizardController::getSession();

        $this->assertSame('new_entity', $session['wizard_mode']);
        $this->assertSame(3, $session['step']);
        $this->assertSame('OldEntity', $session['entity_data']['name']);

        // Internal structure must now be keyed
        $this->assertArrayHasKey('default', $_SESSION['manageentities_wizard']);
    }
}
