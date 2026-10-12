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
use GlpiPlugin\Manageentities\Dat;
use GlpiPlugin\Manageentities\DatServer;
use GlpiPlugin\Manageentities\PresaleTemplate;
use GlpiPlugin\Manageentities\Profile;

/**
 * Data model of the technical architecture documents (DAT): tables, right, sections
 */
class DatTest extends DbTestCase
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

        $this->assertArrayNotHasKey(Dat::getTable(), $differences);
        $this->assertArrayNotHasKey(DatServer::getTable(), $differences);
        $this->assertTrue($DB->fieldExists('glpi_plugin_manageentities_configs', 'dat_documentcategories_id'));
    }

    public function testRightIsDeclared(): void
    {
        $this->assertContains('plugin_manageentities_dat', array_column(Profile::getAllRights(), 'field'));
        $this->assertSame(1, countElementsInTable('glpi_profilerights', [
            'name'        => 'plugin_manageentities_dat',
            'profiles_id' => 4, // super-admin
        ]));
    }

    public function testSectionsAreFilteredAndMerged(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);

        $dat = new Dat();
        $id  = $dat->add([
            'entities_id' => $entities_id,
            'sections'    => [
                'PHP_INFORMATIONS' => '<p>PHP 8.3</p>',
                'UNKNOWN'          => 'dropped',
            ],
        ]);
        $this->assertGreaterThan(0, $id);

        $sections = $dat->getSections();
        $this->assertSame(Dat::SECTIONS, array_keys($sections));
        $this->assertSame('<p>PHP 8.3</p>', $sections['PHP_INFORMATIONS']);
        $this->assertSame('', $sections['NTP_INSTALLATION']);

        // A step only posts its own sections: the others are kept
        $this->assertTrue($dat->update([
            'id'       => $id,
            'sections' => ['NTP_INSTALLATION' => "timedatectl\nchronyc sources"],
        ]));
        $dat->getFromDB($id);
        $sections = $dat->getSections();
        $this->assertSame('<p>PHP 8.3</p>', $sections['PHP_INFORMATIONS']);
        $this->assertSame("timedatectl\nchronyc sources", $sections['NTP_INSTALLATION']);
    }

    public function testOneDatPerEntityThatNeverMoves(): void
    {
        $this->login();
        $entities_id = $this->getTestRootEntity(true);

        $dat = new Dat();
        $id  = $dat->add(['entities_id' => $entities_id]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, Dat::getForEntity($entities_id)?->getID());

        // A second DAT for the client is refused before reaching the unique key
        $this->assertFalse((new Dat())->add(['entities_id' => $entities_id]));
        $this->hasSessionMessages(ERROR, ['This client already has a technical architecture document.']);

        $dat->update(['id' => $id, 'entities_id' => $this->getTestRootEntity(true) + 1]);
        $dat->getFromDB($id);
        $this->assertSame($entities_id, (int) $dat->fields['entities_id']);
    }

    public function testPurgeRemovesTheServers(): void
    {
        $this->login();

        $dat = new Dat();
        $id  = $dat->add(['entities_id' => $this->getTestRootEntity(true)]);
        foreach (['front.example.com', 'db.example.com'] as $rank => $name) {
            $this->assertGreaterThan(0, (new DatServer())->add([
                'plugin_manageentities_dats_id' => $id,
                'rank'                          => $rank,
                'name'                          => $name,
                'ip'                            => '10.0.0.' . ($rank + 1),
            ]));
        }
        $this->assertSame(['front.example.com', 'db.example.com'], array_column(DatServer::getForDat($id), 'name'));

        $this->assertTrue($dat->delete(['id' => $id], true));
        $this->assertSame([], DatServer::getForDat($id));
    }

    public function testDatTemplateIsNotAPresaleTemplate(): void
    {
        $this->assertArrayNotHasKey(PresaleTemplate::DAT, PresaleTemplate::getKinds());
        $this->assertArrayHasKey(PresaleTemplate::DAT, PresaleTemplate::getAllKinds());
    }
}
