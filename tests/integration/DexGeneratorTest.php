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
use GlpiPlugin\Manageentities\Dat;
use GlpiPlugin\Manageentities\DatOsTemplate;
use GlpiPlugin\Manageentities\DatWizard;
use GlpiPlugin\Manageentities\Dex;
use GlpiPlugin\Manageentities\DexGenerator;
use GlpiPlugin\Manageentities\DexWizard;
use GlpiPlugin\Manageentities\PresaleTemplate;
use GlpiPlugin\Manageentities\Profile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

/**
 * Word document of a DEX: every placeholder filled, one table row per server (taken over from
 * the DAT of the client), versions
 */
class DexGeneratorTest extends DbTestCase
{
    /** Template of the environment set aside during the test, null when none */
    private ?string $kept = null;

    private string $started = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->started = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $this->login();
        Profile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
        Profile::initProfile();
        $this->installTemplate();
    }

    public function tearDown(): void
    {
        // The rollback leaves the files of the generated documents: remove them
        foreach ((new \Document())->find(['name' => ['LIKE', 'DEX - %'], 'date_creation' => ['>=', $this->started]]) as $document) {
            if ($document['filepath'] !== '' && is_file(GLPI_DOC_DIR . '/' . $document['filepath'])) {
                unlink(GLPI_DOC_DIR . '/' . $document['filepath']);
            }
        }
        $file = self::templateFile();
        @unlink($file);
        if ($this->kept !== null) {
            rename($this->kept, $file);
        }
        parent::tearDown();
    }

    private static function templateFile(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/manageentities/presale/dex.docx';
    }

    /**
     * Template holding every placeholder (PROCESSUS, the name of the specification, included),
     * with the two server tables of the real one
     */
    private function installTemplate(): void
    {
        $word    = new PhpWord();
        $section = $word->addSection();
        $section->addHeader()->addText('{{LOGO_CLIENT}} {{DATE_DEX}}');
        $section->addText('DEX {{CLIENT}} {{VERSION_DEX}} {{AUTHOR_DEX}}');
        $section->addText('Nom : {{NOM_DNS}}');
        foreach ([...Dex::SECTIONS, 'PROCESSUS'] as $placeholder) {
            if ($placeholder !== 'CLIENT') {
                $section->addText('{{' . $placeholder . '}}');
            }
        }
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(2000)->addText('IP');
        $table->addCell(2000)->addText('{{IP}}');

        $table = $section->addTable();
        $table->addRow();
        foreach (['TYPE', 'IP', 'NOM'] as $title) {
            $table->addCell(2000)->addText($title);
        }
        $table->addRow();
        $table->addCell(2000)->addText('HOTE A');
        $table->addCell(2000)->addText('{{IP}}');
        $table->addCell(2000)->addText('{{NOM_INTERNE}}');
        $table->addRow();
        $table->addCell(2000)->addText('CNAME');
        $table->addCell(2000)->addText('{{IP}}');
        $table->addCell(2000)->addText('{{NOM_DNS}}');

        $file = self::templateFile();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o775, true);
        }
        if (is_file($file)) {
            $this->kept = $file . '.bak';
            rename($file, $this->kept);
        }
        IOFactory::createWriter($word, 'Word2007')->save($file);
    }

    /**
     * DEX of a client whose DAT holds two servers: they are taken over by the DEX
     */
    private function createDex(): Dex
    {
        $entities_id = (int) $this->createItem(\Entity::class, [
            'name'        => 'DEX client ' . mt_rand(),
            'entities_id' => $this->getTestRootEntity(true),
        ])->getID();

        DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME & Co',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-01-10',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        DatWizard::saveSystem(Dat::getForEntity($entities_id), [
            'servers'  => [
                ['name' => 'front.acme.fr', 'internal_name' => 'srv-front01', 'ip' => '10.0.0.1', 'role' => 'Front'],
                ['name' => 'db.acme.fr', 'internal_name' => 'srv-db01', 'ip' => '10.0.0.2', 'role' => 'Database'],
            ],
            'sections' => ['SYSTEM_INFORMATIONS' => '<p>Debian <strong>12</strong></p>'],
        ]);

        DexWizard::saveGeneral($entities_id, [
            'client'          => 'ACME & Co',
            'author_users_id' => \Session::getLoginUserID(),
            'dex_date'        => '2031-05-12',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        $dex = Dex::getForEntity($entities_id);
        DexWizard::saveSections($dex, DexWizard::STEP_SUPERVISION, ['sections' => [
            'TEXT_PROCESSUS' => '<p>Processes of GLPI</p>',
            'LIST_PROCESSUS' => "apache2\nmariadbd",
        ]]);
        DexWizard::saveSections($dex, DexWizard::STEP_RESTARTS, ['sections' => [
            'COMMAND_RESTART_APACHE' => "systemctl restart php8.2-fpm\nsystemctl restart apache2",
        ]]);
        DexWizard::saveSections($dex, DexWizard::STEP_OPERATIONS, ['sections' => [
            'TEXT_PREPROD_COPY_DATAS' => '<p>Disable the mail collectors</p>',
        ]]);
        $dex->getFromDB($dex->getID());

        return $dex;
    }

    private static function readPart(string $docx, string $part): string
    {
        $zip = new ZipArchive();
        if ($zip->open($docx) !== true) {
            return '';
        }
        $content = (string) $zip->getFromName($part);
        $zip->close();

        return $content;
    }

    /** Text of a WordprocessingML part, its placeholders included */
    private static function text(string $xml): string
    {
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function testGenerationFillsEveryPlaceholder(): void
    {
        $dex          = $this->createDex();
        $documents_id = (new DexGenerator($dex))->generate();

        $dex->getFromDB($dex->getID());
        $this->assertSame(Dex::STATUS_GENERATED, (int) $dex->fields['status']);
        $this->assertSame(1, (int) $dex->fields['version']);
        $this->assertSame($documents_id, (int) $dex->fields['documents_id']);
        $this->assertSame(1, countElementsInTable(\Document_Item::getTable(), [
            'documents_id' => $documents_id,
            'itemtype'     => \Entity::class,
            'items_id'     => (int) $dex->fields['entities_id'],
        ]));

        $document = new \Document();
        $this->assertTrue($document->getFromDB($documents_id));
        $this->assertStringStartsWith('DEX - ', $document->fields['name']);
        $this->assertStringEndsWith(' - v1.0.docx', $document->fields['name']);
        $docx = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        $this->assertFileExists($docx);

        $xml  = self::readPart($docx, 'word/document.xml');
        $body = self::text($xml);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\}\}/', $body, 'A placeholder is left in the body.');
        $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\}\}/', self::text(self::readPart($docx, 'word/header1.xml')));
        $this->assertStringContainsString('12/05/2031', self::text(self::readPart($docx, 'word/header1.xml')));
        foreach (['ACME & Co', '1.0', 'Debian 12', 'Nom : front.acme.fr, db.acme.fr', 'Processes of GLPI', 'Disable the mail collectors'] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }
        // Commands: one line break per line typed, the list of processes under both names
        $this->assertStringContainsString('systemctl restart php8.2-fpm</w:t><w:br/>', $xml);
        $this->assertSame(2, substr_count($xml, 'apache2</w:t><w:br/>'));

        // One row per server in each table holding {{IP}}
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $rows = [];
        foreach ($xpath->query('//w:tbl') as $table) {
            $cells = [];
            foreach ($xpath->query('./w:tr', $table) as $row) {
                $cells[] = trim(implode('|', array_map(
                    static fn(\DOMNode $cell): string => trim($cell->textContent),
                    iterator_to_array($xpath->query('./w:tc', $row)),
                )));
            }
            $rows[] = $cells;
        }
        $this->assertSame(['IP|10.0.0.1', 'IP|10.0.0.2'], $rows[0]);
        $this->assertSame([
            'TYPE|IP|NOM',
            'HOTE A|10.0.0.1|srv-front01',
            'HOTE A|10.0.0.2|srv-db01',
            'CNAME|10.0.0.1|front.acme.fr',
            'CNAME|10.0.0.2|db.acme.fr',
        ], $rows[1]);
    }

    public function testEachGenerationIsANewVersion(): void
    {
        $dex   = $this->createDex();
        $first = (new DexGenerator($dex))->generate();

        DexWizard::saveSections($dex, DexWizard::STEP_BACKUPS, ['sections' => ['TEXT_BACKUPS' => '<p>Daily</p>']]);
        $dex->getFromDB($dex->getID());
        $this->assertSame(Dex::STATUS_DRAFT, (int) $dex->fields['status']);

        $second = (new DexGenerator($dex))->generate();
        $this->assertNotSame($first, $second);
        $dex->getFromDB($dex->getID());
        $this->assertSame(2, (int) $dex->fields['version']);
        $this->assertSame($second, (int) $dex->fields['documents_id']);

        $documents = new \Document();
        $this->assertTrue($documents->getFromDB($first));
        $this->assertStringContainsString('v1.0', $documents->fields['name']);
        $this->assertTrue($documents->getFromDB($second));
        $this->assertStringContainsString('v2.0', $documents->fields['name']);

        // Both versions are listed by the card of the customer sheet, latest first
        $card = DexWizard::getCardData((int) $dex->fields['entities_id']);
        $this->assertCount(2, $card['versions']);
    }

    public function testNoServerRemovesTheRows(): void
    {
        $dex = $this->createDex();
        global $DB;
        $DB->delete('glpi_plugin_manageentities_dexservers', ['plugin_manageentities_dexes_id' => $dex->getID()]);

        $documents_id = (new DexGenerator($dex))->generate();
        $document     = new \Document();
        $document->getFromDB($documents_id);
        $body = self::text(self::readPart(GLPI_DOC_DIR . '/' . $document->fields['filepath'], 'word/document.xml'));
        $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\}\}/', $body);
        $this->assertStringNotContainsString('10.0.0', $body);
    }

    public function testTemplateIsRequired(): void
    {
        unlink(self::templateFile());
        $this->assertFalse(PresaleTemplate::isUploaded(PresaleTemplate::DEX));

        $this->expectException(\RuntimeException::class);
        (new DexGenerator($this->createDex()))->generate();
    }

    /**
     * The real template of docs/dex.md, when it sits at the root of the plugin (not versioned):
     * its placeholders were typed in Word, many of them split over several runs
     */
    public function testRealTemplateLeavesNoPlaceholder(): void
    {
        $real = dirname(__DIR__, 2) . '/DEX PROJET GLPI.docx';
        if (!is_file($real)) {
            $this->markTestSkipped('The real DEX template is not at the root of the plugin.');
        }
        copy($real, self::templateFile());

        $dex          = $this->createDex();
        $documents_id = (new DexGenerator($dex))->generate();
        $document     = new \Document();
        $document->getFromDB($documents_id);
        $docx = GLPI_DOC_DIR . '/' . $document->fields['filepath'];

        $body = self::text(self::readPart($docx, 'word/document.xml'));
        $this->assertDoesNotMatchRegularExpression('/\{\{[A-Z_]+\}\}/', $body);
        $this->assertDoesNotMatchRegularExpression('/\{\{[A-Z_]+\}\}/', self::text(self::readPart($docx, 'word/header1.xml')));
        foreach (['ACME & Co', '10.0.0.1', '10.0.0.2', 'srv-db01', 'db.acme.fr', 'Debian', 'Processes of GLPI', 'mariadbd', 'Disable the mail collectors'] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }
    }
}
