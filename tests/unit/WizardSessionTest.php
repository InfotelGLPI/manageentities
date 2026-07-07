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

class WizardSessionTest extends TestCase
{
    protected function setUp(): void
    {
        // Simulate a PHP session for unit tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['manageentities_wizard']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['manageentities_wizard']);
    }

    public function testGetSessionCreatesDefaultWhenAbsent(): void
    {
        $session = WizardController::getSession();
        $this->assertSame(1, $session['step']);
        $this->assertSame(0, $session['entities_id']);
    }

    public function testGetSessionReturnsExistingSession(): void
    {
        $data = WizardController::buildDefaultSession();
        $data['entities_id'] = 42;
        $_SESSION['manageentities_wizard'] = ['default' => $data];

        $session = WizardController::getSession();
        $this->assertSame(42, $session['entities_id']);
    }

    public function testGetSessionPersistsToPhpSession(): void
    {
        WizardController::getSession();
        $this->assertArrayHasKey('manageentities_wizard', $_SESSION);
        $this->assertArrayHasKey('default', $_SESSION['manageentities_wizard']);
    }

    public function testGetSessionReplacesInvalidSession(): void
    {
        // Scalar at root level: treated as corrupt, wiped and rebuilt
        $_SESSION['manageentities_wizard'] = 'not-an-array';

        $session = WizardController::getSession();
        $this->assertIsArray($session);
        $this->assertSame(1, $session['step']);
    }

    public function testSessionStepProgression(): void
    {
        $session = WizardController::buildDefaultSession();
        // Simulate step advancement
        $session['step'] = max($session['step'], 2);
        $this->assertSame(2, $session['step']);

        $session['step'] = max($session['step'], 1);
        $this->assertSame(2, $session['step'], 'Step should never go backwards via max()');
    }

    public function testDocumentsIdsTrackedInSession(): void
    {
        $data = WizardController::buildDefaultSession();
        $data['documents_ids'] = [42, 43];
        $_SESSION['manageentities_wizard'] = ['default' => $data];

        $session = WizardController::getSession();
        $this->assertContains(42, $session['documents_ids']);
        $this->assertContains(43, $session['documents_ids']);
    }

    public function testDocumentsIdsRemovedOnDelete(): void
    {
        $data = WizardController::buildDefaultSession();
        $data['documents_ids'] = [10, 20, 30];
        $_SESSION['manageentities_wizard'] = ['default' => $data];

        // Simulate what deleteDocument() does to the session
        $session = WizardController::getSession();
        $removeId = 20;
        $session['documents_ids'] = array_values(array_filter(
            $session['documents_ids'],
            fn($id) => (int)$id !== $removeId
        ));
        $_SESSION['manageentities_wizard'] = ['default' => $session];

        $updated = WizardController::getSession();
        $this->assertNotContains(20, $updated['documents_ids']);
        $this->assertContains(10, $updated['documents_ids']);
        $this->assertContains(30, $updated['documents_ids']);
        $this->assertCount(2, $updated['documents_ids']);
    }

    public function testResetSummaryContainsDocumentsIdsKey(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertArrayHasKey('documents_ids', $session);
        $this->assertIsArray($session['documents_ids']);
    }
}
