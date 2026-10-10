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
use GlpiPlugin\Manageentities\PresaleEstimate;
use GlpiPlugin\Manageentities\PresaleGenerator;
use GlpiPlugin\Manageentities\PresaleProposal;
use GlpiPlugin\Manageentities\PresaleTemplate;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

/**
 * Generation of a proposal from Word templates holding every placeholder: no placeholder is
 * left in the document, the custom properties are filled, the proposal gets its reference.
 *
 * No template ships with the plugin: minimal ones are built here, in the templates folder of
 * the test environment, and removed afterwards.
 */
class PresaleGeneratorTest extends DbTestCase
{
    use PresaleTestHelpers;

    private const ANNEX_PLACEHOLDERS = [
        'TITRE', 'CLIENT', 'LIEU', 'DATE', 'SIGNATAIRE_INFOTEL', 'SIGNATAIRE_CLIENT',
        'FONCTION_CLIENT', 'OBJET', 'CHIFFRAGE', 'DESCRIPTION', 'TAUX_TVA', 'MENTION_TVA',
        'TOTAL_HT', 'TOTAL_TVA', 'TOTAL_TTC',
    ];

    private const ROW_PLACEHOLDERS = ['DESIGNATION', 'QUANTITE', 'TARIF_JOUR', 'LIGNE_HT', 'LIGNE_TVA', 'LIGNE_TTC'];

    /** @var array<string, ?string> templates of the environment set aside during the test */
    private array $kept = [];

    /** Start of the test, to find the documents it generated */
    private string $started = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->started = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $this->setUpPresale();
        foreach ([PresaleTemplate::PROPOSAL, PresaleTemplate::ANNEX] as $kind) {
            $this->installTemplate($kind);
        }
    }

    public function tearDown(): void
    {
        // The rollback leaves the files of the generated documents: remove them
        foreach ((new \Document())->find(['name' => ['LIKE', '%.docx'], 'date_creation' => ['>=', $this->started]]) as $document) {
            if ($document['filepath'] !== '' && is_file(GLPI_DOC_DIR . '/' . $document['filepath'])) {
                unlink(GLPI_DOC_DIR . '/' . $document['filepath']);
            }
        }
        foreach ($this->kept as $file => $backup) {
            @unlink($file);
            if ($backup !== null) {
                rename($backup, $file);
            }
        }
        $this->resetConfigCache();
        parent::tearDown();
    }

    /**
     * Builds a template of the given kind holding the placeholders and the custom properties
     */
    private function installTemplate(string $kind): void
    {
        $word = new PhpWord();
        $section = $word->addSection();
        if ($kind === PresaleTemplate::PROPOSAL) {
            $section->addText('Proposition commerciale');
            $section->addText('{{PM_NAME}} {{PM_ROLE}} {{PM_EMAIL}} {{PM_PHONE}}');
            $section->addText('{{LOGO_COVER}}');
            foreach (['Client', 'Référence', 'Type de contrat', 'Commercial', 'Contact', 'Date contrat'] as $property) {
                $word->getDocInfo()->setCustomProperty($property, '-');
            }
        } else {
            foreach (self::ANNEX_PLACEHOLDERS as $placeholder) {
                $section->addText('{{' . $placeholder . '}}');
            }
            $table = $section->addTable();
            $table->addRow();
            foreach (self::ROW_PLACEHOLDERS as $placeholder) {
                $table->addCell(1500)->addText('{{' . $placeholder . '}}');
            }
        }

        // Folder of the uploaded templates (PresaleTemplate::DIR)
        $file = GLPI_PLUGIN_DOC_DIR . '/manageentities/presale/' . $kind . '.docx';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
        $backup = null;
        if (is_file($file)) {
            $backup = $file . '.bak';
            rename($file, $backup);
        }
        $this->kept[$file] = $backup;
        IOFactory::createWriter($word, 'Word2007')->save($file);
    }

    /** Contents of a part of the generated document */
    private function readPart(string $docx, string $part): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($docx) === true, "Unable to open $docx");
        $content = (string) $zip->getFromName($part);
        $zip->close();

        return $content;
    }

    /**
     * Body of an annex: each one is a document embedded in the proposal (w:altChunk,
     * word/annexeN.docx, see DocxAppender)
     */
    private function readAnnexBody(string $docx, int $rank): string
    {
        $annex = tempnam(GLPI_TMP_DIR, 'annex_');
        file_put_contents($annex, $this->readPart($docx, 'word/annexe' . $rank . '.docx'));
        try {
            return $this->readPart($annex, 'word/document.xml');
        } finally {
            unlink($annex);
        }
    }

    /** Text of a WordprocessingML part, its placeholders included */
    private static function text(string $xml): string
    {
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function testMissingTemplatesAreReported(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel());

        // The proposal and annex templates are there, not the one of the subscription annexes
        $this->assertSame(
            [PresaleTemplate::getKinds()[PresaleTemplate::SUBSCRIPTION_ANNEX]],
            (new PresaleGenerator($proposal))->getMissingTemplates(),
        );
    }

    public function testGenerationLeavesNoPlaceholder(): void
    {
        $client   = $this->createPresaleClient(['name' => 'Générateur Test']);
        $contact  = $this->createPresaleContact($client, ['role' => 'Directeur des SI']);
        $proposal = $this->createProposal($client, [
            'plugin_manageentities_presalecontacts_id' => $contact->getID(),
            'proposal_type'                            => 'Expertise',
            'pm_name'                                  => 'Chef Projet',
        ]);
        $annex = $this->addAnnex($proposal, [
            ['designation' => 'Audit & conseil', 'quantity' => 2, 'unit_price' => 750],
            ['designation' => 'Paramétrage', 'quantity' => 3, 'unit_price' => 750],
        ]);
        $annex->update([
            'id'          => $annex->getID(),
            'objet'       => '<p>Objet <strong>riche</strong></p>',
            'description' => '<ul><li>Point 1</li><li>Point 2</li></ul>',
            'estimate'    => PresaleEstimate::encode([
                ['type' => PresaleEstimate::TYPE_TITLE, 'label' => 'Audit', 'days' => ''],
                ['type' => PresaleEstimate::TYPE_LINE, 'label' => 'Entretiens', 'days' => '5'],
            ]),
        ]);
        $proposal->getFromDB($proposal->getID());

        $documents_id = (new PresaleGenerator($proposal))->generate();

        // The proposal is generated, with its reference and its document
        $proposal->getFromDB($proposal->getID());
        $this->assertSame(PresaleProposal::STATUS_GENERATED, (int) $proposal->fields['status']);
        $this->assertSame(1, (int) $proposal->fields['version']);
        $this->assertMatchesRegularExpression('/^GEN\d{6}$/', (string) $proposal->fields['reference']);
        $this->assertSame($documents_id, (int) $proposal->fields['documents_id']);
        $this->assertSame(1, countElementsInTable(\Document_Item::getTable(), [
            'documents_id' => $documents_id,
            'itemtype'     => PresaleProposal::class,
            'items_id'     => $proposal->getID(),
        ]));

        $document = new \Document();
        $this->assertTrue($document->getFromDB($documents_id));
        $docx = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        $this->assertFileExists($docx);

        $body  = self::text($this->readPart($docx, 'word/document.xml'));
        $annex = self::text($this->readAnnexBody($docx, 1));
        foreach (['proposal' => $body, 'annex' => $annex] as $part => $text) {
            $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\}\}/', $text, "A placeholder is left in the $part.");
        }
        $this->assertStringContainsString('Chef Projet', $body);
        foreach (['Générateur Test', 'Directeur des SI', 'Audit & conseil', 'Paramétrage', 'riche', 'Point 2', 'Entretiens'] as $expected) {
            $this->assertStringContainsString($expected, $annex);
        }
        // 5 days × 750 € = 3 750 € excl. VAT, 4 500 € incl. VAT (French format, any space)
        $this->assertMatchesRegularExpression('/3\s750,00/u', $annex);
        $this->assertMatchesRegularExpression('/4\s500,00/u', $annex);
        $this->assertMatchesRegularExpression('/20\s%/u', $annex);

        $properties = html_entity_decode($this->readPart($docx, 'docProps/custom.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('Générateur Test', $properties);
        $this->assertStringContainsString((string) $proposal->fields['reference'], $properties);
        $this->assertStringContainsString('Expertise', $properties);
        $this->assertStringContainsString('01/10/2026', $properties);
    }

    public function testANewGenerationIsANewVersionWithTheSameReference(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());
        $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 1, 'unit_price' => 750]]);

        $first = (new PresaleGenerator($proposal))->generate();
        $proposal->getFromDB($proposal->getID());
        $reference = $proposal->fields['reference'];
        $second = (new PresaleGenerator($proposal))->generate();
        $proposal->getFromDB($proposal->getID());

        $this->assertNotSame($first, $second);
        $this->assertSame($reference, $proposal->fields['reference']);
        $this->assertSame(2, (int) $proposal->fields['version']);
        // The previous version is kept
        $this->assertTrue((new \Document())->getFromDB($first));
    }
}
