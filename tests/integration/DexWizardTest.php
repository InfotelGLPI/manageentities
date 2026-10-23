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

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\Dat;
use GlpiPlugin\Manageentities\DatOsTemplate;
use GlpiPlugin\Manageentities\DatServer;
use GlpiPlugin\Manageentities\DatWizard;
use GlpiPlugin\Manageentities\Dex;
use GlpiPlugin\Manageentities\DexOsTemplate;
use GlpiPlugin\Manageentities\DexServer;
use GlpiPlugin\Manageentities\DexWizard;
use GlpiPlugin\Manageentities\Profile;

/**
 * Steps of the DEX wizard: servers and system taken over from the DAT, saving each step in the
 * draft, rendering, card of the customer sheet
 */
class DexWizardTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->login();
        // The DAT and DEX rights of the profile, as granted on install
        Profile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
        Profile::initProfile();
    }

    private function createEntity(): int
    {
        return (int) $this->createItem(\Entity::class, [
            'name'        => 'DEX client ' . mt_rand(),
            'entities_id' => $this->getTestRootEntity(true),
        ])->getID();
    }

    private function createDex(int $entities_id, string $os = DatOsTemplate::OS_DEBIAN): Dex
    {
        $result = DexWizard::saveGeneral($entities_id, [
            'client'          => 'ACME',
            'author_users_id' => \Session::getLoginUserID(),
            'dex_date'        => '2031-05-12',
            'os'              => $os,
        ]);
        $this->assertSame([], $result['errors']);

        $dex = Dex::getForEntity($entities_id);
        $this->assertNotNull($dex);

        return $dex;
    }

    /**
     * DAT of the client with two servers, a system and its system information
     */
    private function createDat(int $entities_id): Dat
    {
        DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-01-10',
            'os'              => DatOsTemplate::OS_REDHAT,
        ]);
        $dat = Dat::getForEntity($entities_id);
        $this->assertNotNull($dat);
        $result = DatWizard::saveSystem($dat, [
            'servers'  => [
                ['name' => 'front.acme.fr', 'internal_name' => 'srv-front01', 'ip' => '10.0.0.1', 'role' => 'Front'],
                ['name' => 'db.acme.fr', 'internal_name' => 'srv-db01', 'ip' => '10.0.0.2', 'role' => 'Database'],
            ],
            'sections' => ['SYSTEM_INFORMATIONS' => '<p>RHEL 9 from the DAT</p>'],
        ]);
        $this->assertSame([], $result['errors']);

        return $dat;
    }

    public function testNewDexTakesTheServersAndSystemOfTheDatOver(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);

        // Step 1 of a new DEX offers the system of the DAT
        $html = $this->renderStep($entities_id, DexWizard::STEP_GENERAL);
        $this->assertMatchesRegularExpression('/<option value="redhat"\s+selected/', $html);

        $dex = $this->createDex($entities_id, DatOsTemplate::OS_REDHAT);
        $servers = DexServer::getForDex($dex->getID());
        $this->assertSame(['front.acme.fr', 'db.acme.fr'], array_column($servers, 'name'));
        $this->assertSame(['srv-front01', 'srv-db01'], array_column($servers, 'internal_name'));
        $this->assertSame(['10.0.0.1', '10.0.0.2'], array_column($servers, 'ip'));
        $this->assertSame(['Front', 'Database'], array_column($servers, 'role'));
        $this->assertSame('<p>RHEL 9 from the DAT</p>', $dex->getSections()['SYSTEM_INFORMATIONS']);
        $this->assertSame(DatOsTemplate::OS_REDHAT, $dex->fields['os']);

        // Copies: changing the DEX servers leaves the DAT ones untouched
        DexWizard::saveSystem($dex, [
            'servers' => [['id' => $servers[0]['id'], 'name' => 'glpi.acme.fr', 'internal_name' => 'srv-front01', 'ip' => '10.0.0.10']],
        ]);
        $this->assertSame(['glpi.acme.fr'], array_column(DexServer::getForDex($dex->getID()), 'name'));
        $this->assertSame(['front.acme.fr', 'db.acme.fr'], array_column(DatServer::getForDat($dat->getID()), 'name'));

        // Resaving step 1 does not take the DAT servers over again
        $this->createDex($entities_id, DatOsTemplate::OS_REDHAT);
        $this->assertCount(1, DexServer::getForDex($dex->getID()));
    }

    public function testWithoutDatTheDexStartsEmpty(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());
        $this->assertSame([], DexServer::getForDex($dex->getID()));
        $this->assertSame('', $dex->getSections()['SYSTEM_INFORMATIONS']);
    }

    public function testGeneralStepCreatesThenUpdatesTheDraft(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $dex = $this->createDex($entities_id);
        $this->assertSame('ACME', $dex->getSections()['CLIENT']);
        $this->assertSame(DexWizard::STEP_GENERAL, (int) $dex->fields['step']);
        $this->assertSame(Dex::STATUS_DRAFT, (int) $dex->fields['status']);

        DexWizard::saveGeneral($entities_id, [
            'client'          => 'ACME Corp',
            'author_users_id' => \Session::getLoginUserID(),
            'dex_date'        => '2031-05-13',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        $this->assertSame(1, countElementsInTable(Dex::getTable(), ['entities_id' => $entities_id]));
        $dex = Dex::getForEntity($entities_id);
        $this->assertSame('ACME Corp', $dex->getSections()['CLIENT']);
        $this->assertSame('2031-05-13', $dex->fields['dex_date']);
    }

    public function testGeneralStepChecksItsFields(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $result = DexWizard::saveGeneral($entities_id, [
            'client'          => ' ',
            'author_users_id' => 0,
            'dex_date'        => '2031-02-30',
            'os'              => 'unknown',
        ]);
        $this->assertSame(['client', 'author_users_id', 'dex_date', 'os'], array_keys($result['errors']));
        $this->assertNull(Dex::getForEntity($entities_id));
    }

    public function testSystemStepSavesTheServersInOrder(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());

        $result = DexWizard::saveSystem($dex, [
            'servers'  => [
                ['id' => 0, 'name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1', 'role' => 'Front'],
                ['id' => 0, 'name' => '', 'internal_name' => '', 'ip' => '', 'role' => ''], // empty row, dropped
                ['id' => 0, 'name' => 'db.example.com', 'internal_name' => 'srv02', 'ip' => '10.0.0.2', 'role' => 'Database'],
            ],
            'sections' => ['SYSTEM_INFORMATIONS' => '<p>Debian 12</p>', 'TEXT_BACKUPS' => 'not this step'],
        ]);
        $this->assertSame([], $result['errors']);

        $servers = DexServer::getForDex($dex->getID());
        $this->assertSame(['front.example.com', 'db.example.com'], array_column($servers, 'name'));

        $dex->getFromDB($dex->getID());
        $this->assertSame('<p>Debian 12</p>', $dex->getSections()['SYSTEM_INFORMATIONS']);
        $this->assertSame('', $dex->getSections()['TEXT_BACKUPS']);
        $this->assertSame(DexWizard::STEP_SYSTEM, (int) $dex->fields['step']);

        // Reordered, one removed: the kept server is updated, not recreated
        $db_id = (int) $servers[1]['id'];
        DexWizard::saveSystem($dex, [
            'servers' => [['id' => $db_id, 'name' => 'db.example.com', 'internal_name' => 'srv02', 'ip' => '10.0.0.3', 'role' => 'Database']],
        ]);
        $servers = DexServer::getForDex($dex->getID());
        $this->assertCount(1, $servers);
        $this->assertSame($db_id, (int) $servers[0]['id']);
        $this->assertSame('10.0.0.3', $servers[0]['ip']);
    }

    public function testServersAreChecked(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());

        $result = DexWizard::saveSystem($dex, ['servers' => []]);
        $this->assertArrayHasKey('servers', $result['errors']);

        $result = DexWizard::saveSystem($dex, [
            'servers' => [['name' => 'not a host!', 'internal_name' => 'not internal!', 'ip' => '999.1.1.1']],
        ]);
        $this->assertSame(['servers.0.name', 'servers.0.internal_name', 'servers.0.ip'], array_keys($result['errors']));
        $this->assertSame([], DexServer::getForDex($dex->getID()));
    }

    public function testServerIdsOfAnotherDexAreNotTrusted(): void
    {
        $this->login();
        $other = $this->createDex($this->createEntity());
        DexWizard::saveSystem($other, ['servers' => [['name' => 'other.example.com', 'internal_name' => 'srv01', 'ip' => '10.1.1.1']]]);
        $other_server = DexServer::getForDex($other->getID())[0];

        $dex = $this->createDex($this->createEntity());
        DexWizard::saveSystem($dex, [
            'servers' => [['id' => $other_server['id'], 'name' => 'mine.example.com', 'internal_name' => 'srv01', 'ip' => '10.2.2.2']],
        ]);

        $this->assertSame('other.example.com', DexServer::getForDex($other->getID())[0]['name']);
        $mine = DexServer::getForDex($dex->getID());
        $this->assertSame('mine.example.com', $mine[0]['name']);
        $this->assertNotSame((int) $other_server['id'], (int) $mine[0]['id']);
    }

    public function testCodeSectionsKeepTheirLines(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());

        DexWizard::saveSections($dex, DexWizard::STEP_OPERATIONS, [
            'sections' => [
                'COMMAND_LAUNCH_CRON' => "  cd /var/www/glpi\r\nphp front/cron.php\r\n\r\n",
                'TEXT_LAUNCH_CRON'    => "  <p>Rich text</p>  \n",
            ],
        ]);
        $dex->getFromDB($dex->getID());
        $this->assertSame("  cd /var/www/glpi\nphp front/cron.php", $dex->getSections()['COMMAND_LAUNCH_CRON']);
        $this->assertSame('<p>Rich text</p>', $dex->getSections()['TEXT_LAUNCH_CRON']);
    }

    public function testSavingAGeneratedDexMakesItADraftAgain(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());
        $dex->update(['id' => $dex->getID(), 'status' => Dex::STATUS_GENERATED, 'step' => DexWizard::STEP_SUMMARY, 'version' => 2]);

        DexWizard::saveSections($dex, DexWizard::STEP_RESTARTS, ['sections' => ['COMMAND_RESTART_APACHE' => 'systemctl restart apache2']]);
        $dex->getFromDB($dex->getID());
        $this->assertSame(Dex::STATUS_DRAFT, (int) $dex->fields['status']);
        $this->assertSame(DexWizard::STEP_SUMMARY, (int) $dex->fields['step']);
        $this->assertSame(2, (int) $dex->fields['version']);
    }

    /**
     * Output of a step. The test kernel does not boot the plugins: their Twig namespace is
     * declared here, as the plugin loader does
     */
    private function renderStep(int $entities_id, int $step): string
    {
        $loader = TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }

        ob_start();
        try {
            DexWizard::renderStep($entities_id, $step);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function testEveryStepRenders(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        // Before the DEX exists, every step falls back to the first one
        $html = $this->renderStep($entities_id, DexWizard::STEP_SUMMARY);
        $this->assertStringContainsString('name="client"', $html);
        $this->assertStringContainsString('name="dex_date"', $html);

        $dex = $this->createDex($entities_id);
        DexWizard::saveSystem($dex, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1']]]);
        $dex->update(['id' => $dex->getID(), 'step' => DexWizard::STEP_OPERATIONS]);

        foreach (array_keys(DexWizard::getSteps()) as $step) {
            $html = $this->renderStep($entities_id, $step);
            $this->assertStringContainsString('id="dex-wizard"', $html, "step $step");
            $this->assertStringContainsString('name="step" value="' . $step . '"', $html, "step $step");
            foreach (DexWizard::getStepSections()[$step] ?? [] as $placeholder) {
                $this->assertStringContainsString('sections[' . $placeholder . ']', $html, "step $step");
            }
        }
        // Summary: the servers and the generation
        $this->assertStringContainsString('front.example.com', $html);
        $this->assertStringContainsString('name="generate"', $html);
        $this->assertStringNotContainsString('ti-file-check', $html);
    }

    public function testNoAccessToAnotherEntity(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->createDex($entities_id);

        $this->setEntity($this->getTestRootEntity(true), false);
        $this->expectException(AccessDeniedHttpException::class);
        DexWizard::renderStep($entities_id, DexWizard::STEP_GENERAL);
    }

    public function testCardFollowsTheLifeOfTheDex(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $card = DexWizard::getCardData($entities_id);
        $this->assertFalse($card['exists']);
        $this->assertTrue($card['can_create']);
        $this->assertSame([], $card['versions']);

        $dex  = $this->createDex($entities_id);
        $card = DexWizard::getCardData($entities_id);
        $this->assertTrue($card['exists']);
        $this->assertTrue($card['is_draft']);
        $this->assertFalse($card['can_create']);
        $this->assertTrue($card['can_update']);
        $this->assertTrue($card['can_delete']);
        $this->assertStringEndsWith('&step=' . DexWizard::STEP_SYSTEM, $card['resume_url']);

        $dex->update(['id' => $dex->getID(), 'status' => Dex::STATUS_GENERATED, 'version' => 1]);
        $card = DexWizard::getCardData($entities_id);
        $this->assertFalse($card['is_draft']);
        $this->assertFalse($card['can_delete']);
        $this->assertStringEndsWith('&step=' . DexWizard::STEP_GENERAL, $card['new_url']);
    }

    public function testCardNeedsTheDexRight(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $_SESSION['glpiactiveprofile'][Dex::$rightname] = 0;
        $this->assertNull(DexWizard::getCardData($entities_id));

        $_SESSION['glpiactiveprofile'][Dex::$rightname] = READ;
        $card = DexWizard::getCardData($entities_id);
        $this->assertNotNull($card);
        $this->assertFalse($card['can_create']);
        $this->assertFalse($card['can_update']);

        $_SESSION['glpiactiveprofile'][Dex::$rightname] = ALLSTANDARDRIGHT;
        $this->assertNull(DexWizard::getCardData(0));
    }

    public function testCardIsShownOnTheCustomerSheet(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->renderStep($entities_id, DexWizard::STEP_GENERAL); // declares the Twig namespace

        ob_start();
        try {
            (new \GlpiPlugin\Manageentities\Entity())->showDescription([$entities_id]);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('ti-file-power', $html);
        $this->assertStringContainsString(DexWizard::getStepUrl($entities_id, DexWizard::STEP_GENERAL), html_entity_decode($html));
        // The DAT card is still there
        $this->assertStringContainsString(DatWizard::getStepUrl($entities_id, DatWizard::STEP_GENERAL), html_entity_decode($html));
    }

    public function testReadOnlyCannotSave(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->createDex($entities_id);

        $_SESSION['glpiactiveprofile'][Dex::$rightname] = READ;
        $this->assertFalse(DexWizard::canUse());
        $this->expectException(AccessDeniedHttpException::class);
        DexWizard::saveGeneral($entities_id, [
            'client'          => 'Changed',
            'author_users_id' => \Session::getLoginUserID(),
            'dex_date'        => '2031-05-12',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
    }

    public function testOsModelPrefillsTheEmptySections(): void
    {
        $this->login();
        DexOsTemplate::saveAll([
            DatOsTemplate::OS_WINDOWS => ['COMMAND_RESTART_APACHE' => "net stop Apache2.4\r\nnet start Apache2.4\r\n", 'TEXT_RESTART_APACHE' => ''],
            'unknown'                 => ['COMMAND_RESTART_APACHE' => 'dropped'],
        ]);
        $this->assertSame("net stop Apache2.4\nnet start Apache2.4", DexOsTemplate::getSectionsFor(DatOsTemplate::OS_WINDOWS)['COMMAND_RESTART_APACHE']);
        $this->assertSame([], DexOsTemplate::getSectionsFor('unknown'));

        $entities_id = $this->createEntity();
        $dex = $this->createDex($entities_id, DatOsTemplate::OS_WINDOWS);
        DexWizard::saveSystem($dex, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1']]]);
        DexWizard::saveSections($dex, DexWizard::STEP_SUPERVISION, ['sections' => []]);
        DexWizard::saveSections($dex, DexWizard::STEP_BACKUPS, ['sections' => []]);

        $html = $this->renderStep($entities_id, DexWizard::STEP_RESTARTS);
        $this->assertStringContainsString('net start Apache2.4', $html);
        $this->assertStringContainsString('name="apply_model"', $html);

        DexWizard::saveSections($dex, DexWizard::STEP_RESTARTS, ['sections' => ['COMMAND_RESTART_APACHE' => 'typed by hand']]);
        $html = $this->renderStep($entities_id, DexWizard::STEP_RESTARTS);
        $this->assertStringContainsString('typed by hand', $html);
        $this->assertStringNotContainsString('net start Apache2.4', $html);
    }

    public function testOnlyADraftNeverGeneratedCanBeDeleted(): void
    {
        $this->login();
        $dex = $this->createDex($this->createEntity());
        DexWizard::saveSystem($dex, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1']]]);
        $dex->getFromDB($dex->getID());
        $this->assertTrue(DexWizard::canDeleteDraft($dex));

        $dex->update(['id' => $dex->getID(), 'version' => 1, 'status' => Dex::STATUS_DRAFT]);
        $dex->getFromDB($dex->getID());
        $this->assertFalse(DexWizard::canDeleteDraft($dex));
        $this->assertFalse(DexWizard::canDeleteDraft(null));

        $dex->update(['id' => $dex->getID(), 'version' => 0]);
        $this->assertTrue($dex->delete(['id' => $dex->getID()], true));
        $this->assertSame([], DexServer::getForDex($dex->getID()));
    }
}
