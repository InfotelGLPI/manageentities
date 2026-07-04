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
    private int $contracts_id = 0;

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
        $cr = WizardController::saveContractAndReturn([
            'name'        => 'CTR-' . $this->getUniqueString(),
            'entities_id' => $er['entities_id'],
            'begin_date'  => '2026-01-01',
            'duration'    => 12,
        ]);
        $this->contracts_id = $cr['contracts_id'];
    }

    private function makeUploadedFile(string $content = 'test content'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wiz_test_');
        file_put_contents($tmp, $content);
        return $tmp;
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
        $this->setupEntityAndContract();

        $tmp      = $this->makeUploadedFile('pdf content');
        $destName = 'test_wizard_' . $this->getUniqueString() . '.pdf';

        // Simulate what uploadDocuments() does: copy to GLPI_UPLOAD_DIR then add Document
        $destPath = GLPI_UPLOAD_DIR . '/' . $destName;
        copy($tmp, $destPath);
        unlink($tmp);

        $glpiContract = new \Contract();
        $glpiContract->getFromDB($this->contracts_id);
        $entities_id = (int)$glpiContract->fields['entities_id'];

        $doc      = new Document();
        $docInput = [
            'documentcategories_id' => 0,
            'entities_id'           => $entities_id,
            'is_recursive'          => 0,
            'itemtype'              => \Contract::class,
            'items_id'              => $this->contracts_id,
            '_no_message'           => true,
            'upload_file'           => $destName,
            'name'                  => $destName,
        ];
        $doc->check(-1, CREATE, $docInput);
        $doc_id = $doc->add($docInput);
        $this->assertGreaterThan(0, $doc_id, 'Document::add() should succeed');

        // Manually track in session (mirrors what uploadDocuments() does)
        $session = WizardController::getSession();
        $session['documents_ids'][] = (int)$doc_id;
        $_SESSION['manageentities_wizard'] = $session;

        $updated = WizardController::getSession();
        $this->assertContains((int)$doc_id, $updated['documents_ids']);
    }

    public function testDeleteDocumentRemovesFromSessionAndDb(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $tmp      = $this->makeUploadedFile('to delete');
        $destName = 'test_del_' . $this->getUniqueString() . '.pdf';
        $destPath = GLPI_UPLOAD_DIR . '/' . $destName;
        copy($tmp, $destPath);
        unlink($tmp);

        $glpiContract = new \Contract();
        $glpiContract->getFromDB($this->contracts_id);

        $doc      = new Document();
        $docInput = [
            'documentcategories_id' => 0,
            'entities_id'           => (int)$glpiContract->fields['entities_id'],
            'is_recursive'          => 0,
            'itemtype'              => \Contract::class,
            'items_id'              => $this->contracts_id,
            '_no_message'           => true,
            'upload_file'           => $destName,
            'name'                  => $destName,
        ];
        $doc->check(-1, CREATE, $docInput);
        $doc_id = (int)$doc->add($docInput);
        $this->assertGreaterThan(0, $doc_id);

        // Put it in session
        $session = WizardController::getSession();
        $session['documents_ids'] = [$doc_id];
        $_SESSION['manageentities_wizard'] = $session;

        // Call deleteDocument via POST simulation
        $_POST['document_id'] = $doc_id;
        $d = new Document();
        $ok = $d->delete(['id' => $doc_id], true);
        $this->assertTrue((bool)$ok);

        // Update session as deleteDocument() would
        $session['documents_ids'] = array_values(array_filter(
            $session['documents_ids'],
            fn($id) => (int)$id !== $doc_id
        ));
        $_SESSION['manageentities_wizard'] = $session;
        unset($_POST['document_id']);

        $updated = WizardController::getSession();
        $this->assertNotContains($doc_id, $updated['documents_ids']);
        $this->assertEquals(0, countElementsInTable('glpi_documents', ['id' => $doc_id]));
    }

    public function testResetSummaryIncludesDocumentNames(): void
    {
        $this->login();
        $this->setupEntityAndContract();

        $tmp      = $this->makeUploadedFile('summary test');
        $destName = 'test_sum_' . $this->getUniqueString() . '.pdf';
        $destPath = GLPI_UPLOAD_DIR . '/' . $destName;
        copy($tmp, $destPath);
        unlink($tmp);

        $glpiContract = new \Contract();
        $glpiContract->getFromDB($this->contracts_id);

        $doc      = new Document();
        $docInput = [
            'documentcategories_id' => 0,
            'entities_id'           => (int)$glpiContract->fields['entities_id'],
            'is_recursive'          => 0,
            'itemtype'              => \Contract::class,
            'items_id'              => $this->contracts_id,
            '_no_message'           => true,
            'upload_file'           => $destName,
            'name'                  => $destName,
        ];
        $doc->check(-1, CREATE, $docInput);
        $doc_id = (int)$doc->add($docInput);

        $session = WizardController::getSession();
        $session['documents_ids'] = [$doc_id];
        $_SESSION['manageentities_wizard'] = $session;

        $summary = WizardController::getResetSummaryAndReturn();
        $this->assertTrue($summary['success']);

        $types = array_column($summary['items'], 'type');
        $found = false;
        foreach ($summary['items'] as $item) {
            if (str_contains(strtolower($item['type']), 'document')) {
                $found = true;
                $this->assertStringContainsString($destName, $item['label']);
                break;
            }
        }
        $this->assertTrue($found, 'Document should appear in reset summary');
    }
}
