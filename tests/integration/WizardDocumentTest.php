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
use Document;
use GlpiPlugin\Manageentities\WizardController;

class WizardDocumentTest extends DbTestCase
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

    private function makeUploadedFile(string $content = 'test content'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wiz_test_');
        file_put_contents($tmp, $content);
        return $tmp;
    }

    /**
     * Create a Document row in DB and add its ID to the wizard session,
     * mirroring what uploadDocuments() does.
     */
    private function uploadDocumentToSession(string $content = 'pdf content'): int
    {
        $tmp      = $this->makeUploadedFile($content);
        $destName = 'test_wizard_' . $this->getUniqueString() . '.pdf';
        $destPath = GLPI_UPLOAD_DIR . '/' . $destName;
        copy($tmp, $destPath);
        unlink($tmp);

        $doc    = new Document();
        $doc_id = (int)$doc->add([
            'documentcategories_id' => 0,
            'entities_id'           => 0,
            'is_recursive'          => 0,
            '_no_message'           => true,
            'upload_file'           => $destName,
            'name'                  => $destName,
        ]);
        $this->assertGreaterThan(0, $doc_id, 'Document::add() should succeed');

        $session = WizardController::getSession();
        $session['documents_ids'][] = $doc_id;
        $_SESSION['manageentities_wizard'] = ['default' => $session];

        return $doc_id;
    }

    public function testDocumentsIdsInitialisedEmpty(): void
    {
        $session = WizardController::buildDefaultSession();
        $this->assertArrayHasKey('documents_ids', $session);
        $this->assertEmpty($session['documents_ids']);
    }

    public function testUploadDocumentTracksIdInSession(): void
    {
        $this->login();

        $doc_id = $this->uploadDocumentToSession();

        $updated = WizardController::getSession();
        $this->assertContains($doc_id, $updated['documents_ids']);
    }

    public function testDeleteDocumentRemovesFromSessionAndDb(): void
    {
        $this->login();

        $doc_id = $this->uploadDocumentToSession('to delete');

        // Delete from DB
        $d = new Document();
        $ok = $d->delete(['id' => $doc_id], true);
        $this->assertTrue((bool)$ok);

        // Update session as deleteDocument() would
        $session = WizardController::getSession();
        $session['documents_ids'] = array_values(array_filter(
            $session['documents_ids'],
            fn($id) => (int)$id !== $doc_id
        ));
        $_SESSION['manageentities_wizard'] = ['default' => $session];

        $updated = WizardController::getSession();
        $this->assertNotContains($doc_id, $updated['documents_ids']);
        $this->assertEquals(0, countElementsInTable('glpi_documents', ['id' => $doc_id]));
    }

    public function testResetSummaryIncludesDocumentCount(): void
    {
        $this->login();

        $this->uploadDocumentToSession('summary test');

        $summary = WizardController::getResetSummaryAndReturn();
        $this->assertTrue($summary['success']);

        $found = false;
        foreach ($summary['items'] as $item) {
            if (str_contains(strtolower($item['type']), 'document')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Document should appear in reset summary');
    }

    public function testDocumentsLinkedToContractAfterCommit(): void
    {
        $this->login('glpi');
        $uid = $this->getUniqueString();

        WizardController::saveEntityAndReturn(['name' => "DocEnt-{$uid}", 'entities_id' => 0]);
        WizardController::saveContactsAndReturn(['contacts' => []]);
        WizardController::saveContractAndReturn(['name' => "DocCTR-{$uid}", 'begin_date' => '2026-01-01', 'duration' => 12]);
        WizardController::saveManagementTypeAndReturn(['date_signature' => '2026-01-01']);

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

        $doc_id = $this->uploadDocumentToSession('commit doc test');

        $rc = WizardController::commitWizardAndReturn();
        $this->assertTrue($rc['success'], json_encode($rc));

        // After commit the document must be linked to the contract via Document_Item
        $this->assertEquals(
            1,
            countElementsInTable('glpi_documents_items', [
                'documents_id' => $doc_id,
                'itemtype'     => \Contract::class,
            ]),
            'Document should be linked to the contract in glpi_documents_items'
        );
    }
}
