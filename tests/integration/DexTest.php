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

use Glpi\System\Diagnostic\DatabaseSchemaIntegrityChecker;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\DatOsTemplate;
use GlpiPlugin\Manageentities\DatWizard;
use GlpiPlugin\Manageentities\Dex;
use GlpiPlugin\Manageentities\DexOsTemplate;
use GlpiPlugin\Manageentities\DexServer;
use GlpiPlugin\Manageentities\DexWizard;
use GlpiPlugin\Manageentities\PresaleTemplate;
use GlpiPlugin\Manageentities\Profile;

/**
 * Data model of the operating documents (DEX): tables, right, sections, models
 */
class DexTest extends DbTestCase
{
    public function testTablesMatchTheEmptySchema(): void
    {
        global $DB;

        $checker     = new DatabaseSchemaIntegrityChecker($DB, false, true, true, true, true, true);
        $differences = $checker->checkCompleteSchema(
            dirname(__DIR__, 2) . '/install/sql/empty.sql',
            true,
            'plugin:manageentities',
        );

        $this->assertArrayNotHasKey(Dex::getTable(), $differences);
        $this->assertArrayNotHasKey(DexServer::getTable(), $differences);
        $this->assertArrayNotHasKey(DexOsTemplate::getTable(), $differences);
        // The configuration table may differ on other columns: not on the DEX one
        $this->assertStringNotContainsString(
            'dex_documentcategories_id',
            (string) ($differences['glpi_plugin_manageentities_configs']['diff'] ?? ''),
        );
        $this->assertTrue($DB->fieldExists('glpi_plugin_manageentities_configs', 'dex_documentcategories_id'));
    }

    public function testRightIsDeclared(): void
    {
        $this->assertContains('plugin_manageentities_dex', array_column(Profile::getAllRights(), 'field'));
        $this->assertSame(1, countElementsInTable('glpi_profilerights', [
            'name'        => 'plugin_manageentities_dex',
            'profiles_id' => 4, // super-admin
        ]));
    }

    public function testSectionsAreFilteredAndMerged(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);

        $dex = new Dex();
        $id  = $dex->add([
            'entities_id' => $entities_id,
            'sections'    => [
                'TEXT_BACKUPS' => '<p>Daily</p>',
                'UNKNOWN'      => 'dropped',
            ],
        ]);
        $this->assertGreaterThan(0, $id);

        $sections = $dex->getSections();
        $this->assertSame(Dex::SECTIONS, array_keys($sections));
        $this->assertSame('<p>Daily</p>', $sections['TEXT_BACKUPS']);
        $this->assertSame('', $sections['COMMAND_LAUNCH_CRON']);

        // A step only posts its own sections: the others are kept
        $this->assertTrue($dex->update([
            'id'       => $id,
            'sections' => ['COMMAND_LAUNCH_CRON' => "cd /var/www/glpi\nphp front/cron.php"],
        ]));
        $dex->getFromDB($id);
        $sections = $dex->getSections();
        $this->assertSame('<p>Daily</p>', $sections['TEXT_BACKUPS']);
        $this->assertSame("cd /var/www/glpi\nphp front/cron.php", $sections['COMMAND_LAUNCH_CRON']);
    }

    public function testOneDexPerEntityThatNeverMoves(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);

        $dex = new Dex();
        $id  = $dex->add(['entities_id' => $entities_id]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, Dex::getForEntity($entities_id)?->getID());

        $this->assertFalse((new Dex())->add(['entities_id' => $entities_id]));
        $this->hasSessionMessages(ERROR, ['This client already has an operating document.']);

        $dex->update(['id' => $id, 'entities_id' => $this->getTestRootEntity(true) + 1]);
        $dex->getFromDB($id);
        $this->assertSame($entities_id, (int) $dex->fields['entities_id']);
    }

    public function testPurgeRemovesTheServers(): void
    {
        $this->login();

        $dex = new Dex();
        $id  = $dex->add(['entities_id' => $this->getTestRootEntity(true)]);
        foreach (['front.example.com', 'db.example.com'] as $rank => $name) {
            $this->assertGreaterThan(0, (new DexServer())->add([
                'plugin_manageentities_dexes_id' => $id,
                'rank'                           => $rank,
                'name'                           => $name,
                'ip'                             => '10.0.0.' . ($rank + 1),
            ]));
        }
        $this->assertSame(['front.example.com', 'db.example.com'], array_column(DexServer::getForDex($id), 'name'));

        $this->assertTrue($dex->delete(['id' => $id], true));
        $this->assertSame([], DexServer::getForDex($id));
    }

    public function testDexTemplateIsNotAPresaleTemplate(): void
    {
        $this->assertArrayNotHasKey(PresaleTemplate::DEX, PresaleTemplate::getKinds());
        $this->assertArrayHasKey(PresaleTemplate::DEX, PresaleTemplate::getAllKinds());
        $this->assertFalse(PresaleTemplate::isUploaded('unknown'));
    }

    public function testSectionKinds(): void
    {
        foreach (Dex::SECTIONS as $placeholder) {
            $expected = match (true) {
                $placeholder === 'CLIENT'                                                       => DatWizard::KIND_TEXT,
                str_starts_with($placeholder, 'COMMAND_') || $placeholder === 'LIST_PROCESSUS' => DatWizard::KIND_CODE,
                default                                                                         => DatWizard::KIND_RICHTEXT,
            };
            $this->assertSame($expected, DexWizard::getSectionKind($placeholder), $placeholder);
        }
        $this->assertSame(DatWizard::KIND_CODE, DexWizard::getSectionKind('PROCESSUS'));
        // Every section of the steps has a label, and is a section of the DEX
        $this->assertSame(array_values(array_diff(Dex::SECTIONS, ['CLIENT'])), DexOsTemplate::getPlaceholders());
        $this->assertSame(DexOsTemplate::getPlaceholders(), array_keys(DexWizard::getSectionLabels()));
    }

    public function testDefaultModelsCoverEverySystem(): void
    {
        foreach (array_keys(DatOsTemplate::getSystems()) as $os) {
            $sections = DexOsTemplate::getSectionsFor($os);
            $this->assertSame(DexOsTemplate::getPlaceholders(), array_keys($sections), $os);
        }
        // Restart commands of each system, and the GLPI-specific points of the defaults
        $this->assertStringContainsString('systemctl restart apache2', DexOsTemplate::getSectionsFor(DatOsTemplate::OS_DEBIAN)['COMMAND_RESTART_APACHE']);
        $this->assertStringContainsString('systemctl restart httpd', DexOsTemplate::getSectionsFor(DatOsTemplate::OS_REDHAT)['COMMAND_RESTART_APACHE']);
        $windows = DexOsTemplate::getSectionsFor(DatOsTemplate::OS_WINDOWS);
        $this->assertStringContainsString('net start Apache2.4', $windows['COMMAND_RESTART_APACHE']);
        $this->assertStringContainsString('Get-Service', $windows['COMMAND_CHECK_PROCESSUS']);
        foreach (array_keys(DatOsTemplate::getSystems()) as $os) {
            $sections = DexOsTemplate::getSectionsFor($os);
            $this->assertStringContainsString('glpicrypt.key', $sections['TEXT_BACKUPS'], $os);
            $this->assertStringContainsString('maintenance:enable', $sections['TEXT_LAUNCH_MAINTENANCE_MODE'], $os);
            $this->assertStringContainsString('collecteurs', $sections['TEXT_PREPROD_COPY_DATAS'], $os);
            $this->assertStringContainsString('front', $sections['COMMAND_LAUNCH_CRON'], $os);
            $this->assertStringContainsString('mysqldump', $sections['COMMAND_LAUNCH_BACKUP'], $os);
        }
    }
}
