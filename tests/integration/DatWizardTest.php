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
use GlpiPlugin\Manageentities\Profile;

/**
 * Steps of the DAT wizard: saving each step in the draft, servers, rendering
 */
class DatWizardTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->login();
        // The DAT right of the profile, as granted on install
        Profile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
        Profile::initProfile();
    }

    private function createEntity(): int
    {
        return (int) $this->createItem(\Entity::class, [
            'name'        => 'DAT client ' . mt_rand(),
            'entities_id' => $this->getTestRootEntity(true),
        ])->getID();
    }

    private function createDat(int $entities_id): Dat
    {
        $result = DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-05-12',
        ]);
        $this->assertSame([], $result['errors']);

        $dat = Dat::getForEntity($entities_id);
        $this->assertNotNull($dat);

        return $dat;
    }

    public function testGeneralStepCreatesThenUpdatesTheDraft(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $dat = $this->createDat($entities_id);
        $this->assertSame('ACME', $dat->getSections()['CLIENT']);
        $this->assertSame(DatWizard::STEP_GENERAL, (int) $dat->fields['step']);
        $this->assertSame(Dat::STATUS_DRAFT, (int) $dat->fields['status']);

        // Resumed: the same DAT is updated, never a second one
        DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME Corp',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-05-13',
        ]);
        $this->assertSame(1, countElementsInTable(Dat::getTable(), ['entities_id' => $entities_id]));
        $dat = Dat::getForEntity($entities_id);
        $this->assertSame('ACME Corp', $dat->getSections()['CLIENT']);
        $this->assertSame('2031-05-13', $dat->fields['dat_date']);
    }

    public function testGeneralStepChecksItsFields(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $result = DatWizard::saveGeneral($entities_id, [
            'client'          => ' ',
            'author_users_id' => 0,
            'dat_date'        => '2031-02-30',
        ]);
        $this->assertSame(['client', 'author_users_id', 'dat_date'], array_keys($result['errors']));
        $this->assertNull(Dat::getForEntity($entities_id));
    }

    public function testSystemStepSavesTheServersInOrder(): void
    {
        $this->login();
        $dat = $this->createDat($this->createEntity());

        $result = DatWizard::saveSystem($dat, [
            'servers'  => [
                ['id' => 0, 'name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1', 'os' => 'debian', 'role' => 'Front'],
                ['id' => 0, 'name' => '', 'internal_name' => '', 'ip' => '', 'role' => ''], // empty row, dropped
                ['id' => 0, 'name' => 'db.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.2', 'os' => 'debian', 'role' => 'Database'],
            ],
            'sections' => ['SYSTEM_INFORMATIONS' => '<p>Debian 12</p>', 'APACHE_INFORMATIONS' => 'not this step'],
        ]);
        $this->assertSame([], $result['errors']);

        $servers = DatServer::getForDat($dat->getID());
        $this->assertSame(['front.example.com', 'db.example.com'], array_column($servers, 'name'));

        $dat->getFromDB($dat->getID());
        $this->assertSame('<p>Debian 12</p>', $dat->getSections()['SYSTEM_INFORMATIONS']);
        // Only the sections of the step are taken from the post
        $this->assertSame('', $dat->getSections()['APACHE_INFORMATIONS']);
        $this->assertSame(DatWizard::STEP_SYSTEM, (int) $dat->fields['step']);

        // Reordered, one removed: the kept server is updated, not recreated
        $db_id = (int) $servers[1]['id'];
        DatWizard::saveSystem($dat, [
            'servers' => [['id' => $db_id, 'name' => 'db.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.3', 'os' => 'debian', 'role' => 'Database']],
        ]);
        $servers = DatServer::getForDat($dat->getID());
        $this->assertCount(1, $servers);
        $this->assertSame($db_id, (int) $servers[0]['id']);
        $this->assertSame('10.0.0.3', $servers[0]['ip']);
    }

    public function testServersAreChecked(): void
    {
        $this->login();
        $dat = $this->createDat($this->createEntity());

        $result = DatWizard::saveSystem($dat, ['servers' => []]);
        $this->assertArrayHasKey('servers', $result['errors']);

        $result = DatWizard::saveSystem($dat, [
            'servers' => [['name' => 'not a host!', 'internal_name' => 'not internal!', 'ip' => '999.1.1.1']],
        ]);
        $this->assertSame(['servers.0.name', 'servers.0.internal_name', 'servers.0.ip', 'servers.0.os'], array_keys($result['errors']));
        $this->assertSame([], DatServer::getForDat($dat->getID()));
    }

    public function testServerIdsOfAnotherDatAreNotTrusted(): void
    {
        $this->login();
        $other = $this->createDat($this->createEntity());
        DatWizard::saveSystem($other, ['servers' => [['name' => 'other.example.com', 'internal_name' => 'srv01', 'ip' => '10.1.1.1', 'os' => 'debian']]]);
        $other_server = DatServer::getForDat($other->getID())[0];

        $dat = $this->createDat($this->createEntity());
        DatWizard::saveSystem($dat, [
            'servers' => [['id' => $other_server['id'], 'name' => 'mine.example.com', 'internal_name' => 'srv01', 'ip' => '10.2.2.2', 'os' => 'debian']],
        ]);

        // The other DAT keeps its server; this one gets a new one
        $this->assertSame('other.example.com', DatServer::getForDat($other->getID())[0]['name']);
        $mine = DatServer::getForDat($dat->getID());
        $this->assertSame('mine.example.com', $mine[0]['name']);
        $this->assertNotSame((int) $other_server['id'], (int) $mine[0]['id']);
    }

    public function testCodeSectionsKeepTheirLines(): void
    {
        $this->login();
        $dat = $this->createDat($this->createEntity());

        DatWizard::saveSections($dat, DatWizard::STEP_SERVICES, [
            'sections' => ['CRON_INSTALLATION' => "  crontab -e\r\n*/2 * * * * php front/cron.php\r\n\r\n"],
        ]);
        $dat->getFromDB($dat->getID());
        // Leading spaces kept, Windows line breaks and trailing blank lines dropped
        $this->assertSame("  crontab -e\n*/2 * * * * php front/cron.php", $dat->getSections()['CRON_INSTALLATION']);
    }

    public function testSavingAGeneratedDatMakesItADraftAgain(): void
    {
        $this->login();
        $dat = $this->createDat($this->createEntity());
        $dat->update(['id' => $dat->getID(), 'status' => Dat::STATUS_GENERATED, 'step' => DatWizard::STEP_SUMMARY, 'version' => 2]);

        DatWizard::saveSections($dat, DatWizard::STEP_PHP, ['sections' => ['PHP_INFORMATIONS' => 'PHP 8.4']]);
        $dat->getFromDB($dat->getID());
        $this->assertSame(Dat::STATUS_DRAFT, (int) $dat->fields['status']);
        // The last reached step is kept
        $this->assertSame(DatWizard::STEP_SUMMARY, (int) $dat->fields['step']);
        $this->assertSame(2, (int) $dat->fields['version']);
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
            DatWizard::renderStep($entities_id, $step);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function testEveryStepRenders(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        // Before the DAT exists, every step falls back to the first one
        $html = $this->renderStep($entities_id, DatWizard::STEP_SUMMARY);
        $this->assertStringContainsString('name="client"', $html);

        $dat = $this->createDat($entities_id);
        DatWizard::saveSystem($dat, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1', 'os' => 'debian']]]);
        $dat->update(['id' => $dat->getID(), 'step' => DatWizard::STEP_SSO]);

        foreach (array_keys(DatWizard::getSteps()) as $step) {
            $html = $this->renderStep($entities_id, $step);
            $this->assertStringContainsString('id="dat-wizard"', $html, "step $step");
            $this->assertStringContainsString('name="step" value="' . $step . '"', $html, "step $step");
            foreach (DatWizard::getStepSections()[$step] ?? [] as $placeholder) {
                $this->assertStringContainsString('sections[' . $placeholder . ']', $html, "step $step");
            }
        }
        $this->assertStringContainsString('front.example.com', $html);
    }

    public function testNoAccessToAnotherEntity(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->createDat($entities_id);

        // Restricted to the root entity alone, the client entity below it is out of reach
        $this->setEntity($this->getTestRootEntity(true), false);
        $this->expectException(AccessDeniedHttpException::class);
        DatWizard::renderStep($entities_id, DatWizard::STEP_GENERAL);
    }
    public function testCardFollowsTheLifeOfTheDat(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        // No DAT yet: it can be created
        $card = DatWizard::getCardData($entities_id);
        $this->assertFalse($card['exists']);
        $this->assertTrue($card['can_create']);
        $this->assertSame([], $card['versions']);

        // A draft: resumed at the step following the last saved one
        $dat = $this->createDat($entities_id);
        $card = DatWizard::getCardData($entities_id);
        $this->assertTrue($card['exists']);
        $this->assertTrue($card['is_draft']);
        $this->assertFalse($card['can_create']);
        $this->assertTrue($card['can_update']);
        $this->assertStringEndsWith('&step=' . DatWizard::STEP_SYSTEM, $card['resume_url']);

        // Generated: no longer a draft, a new version starts from the first step
        $dat->update(['id' => $dat->getID(), 'status' => Dat::STATUS_GENERATED, 'version' => 1]);
        $card = DatWizard::getCardData($entities_id);
        $this->assertFalse($card['is_draft']);
        $this->assertStringEndsWith('&step=' . DatWizard::STEP_GENERAL, $card['new_url']);
    }

    public function testCardNeedsTheDatRight(): void
    {
        $this->login();
        $entities_id = $this->createEntity();

        $_SESSION['glpiactiveprofile'][Dat::$rightname] = 0;
        $this->assertNull(DatWizard::getCardData($entities_id));

        // Read only: the card shows, without any button
        $_SESSION['glpiactiveprofile'][Dat::$rightname] = READ;
        $card = DatWizard::getCardData($entities_id);
        $this->assertNotNull($card);
        $this->assertFalse($card['can_create']);
        $this->assertFalse($card['can_update']);

        // Never for the root entity, which is not a client
        $_SESSION['glpiactiveprofile'][Dat::$rightname] = ALLSTANDARDRIGHT;
        $this->assertNull(DatWizard::getCardData(0));
    }

    public function testCardIsShownOnTheCustomerSheet(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->renderStep($entities_id, DatWizard::STEP_GENERAL); // declares the Twig namespace

        ob_start();
        try {
            (new \GlpiPlugin\Manageentities\Entity())->showDescription([$entities_id]);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('ti-file-settings', $html);
        $this->assertStringContainsString(DatWizard::getStepUrl($entities_id, DatWizard::STEP_GENERAL), html_entity_decode($html));
    }
    public function testReadOnlyCannotSave(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $this->createDat($entities_id);

        $_SESSION['glpiactiveprofile'][Dat::$rightname] = READ;
        $this->assertFalse(DatWizard::canUse());
        $this->expectException(AccessDeniedHttpException::class);
        DatWizard::saveGeneral($entities_id, [
            'client'          => 'Changed',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-05-12',
        ]);
    }

    public function testSummaryOffersTheGenerationOnceEveryStepIsSaved(): void
    {
        $this->login();
        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);
        DatWizard::saveSystem($dat, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1', 'os' => 'debian']]]);
        $dat->update(['id' => $dat->getID(), 'step' => DatWizard::STEP_SSO]);

        $html = $this->renderStep($entities_id, DatWizard::STEP_SUMMARY);
        $this->assertStringContainsString('name="generate"', $html);
        // The generated document is not offered before the first generation
        $this->assertStringNotContainsString('ti-file-check', $html);
    }

    public function testOsModelPrefillsTheEmptySections(): void
    {
        $this->login();
        DatOsTemplate::saveAll([
            DatOsTemplate::OS_WINDOWS => ['APACHE_INSTALLATION' => 'net start Apache2.4', 'APACHE_INFORMATIONS' => ''],
            'unknown'                 => ['APACHE_INSTALLATION' => 'dropped'],
        ]);
        $this->assertSame('net start Apache2.4', DatOsTemplate::getSectionsFor(DatOsTemplate::OS_WINDOWS)['APACHE_INSTALLATION']);
        $this->assertSame([], DatOsTemplate::getSectionsFor('unknown'));

        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);
        // A server without system is refused
        $result = DatWizard::saveSystem($dat, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1']]]);
        $this->assertArrayHasKey('servers.0.os', $result['errors']);
        DatWizard::saveSystem($dat, ['servers' => [['name' => 'front.example.com', 'internal_name' => 'srv01', 'ip' => '10.0.0.1', 'os' => DatOsTemplate::OS_WINDOWS]]]);
        $this->assertSame(DatOsTemplate::OS_WINDOWS, DatServer::getForDat($dat->getID())[0]['os']);

        // Empty section: shown with the text of the model, nothing saved yet
        $html = $this->renderStep($entities_id, DatWizard::STEP_WEB);
        $this->assertStringContainsString('net start Apache2.4', $html);
        $this->assertStringContainsString('name="apply_model"', $html);

        // A section typed by the user is kept
        DatWizard::saveSections($dat, DatWizard::STEP_WEB, ['sections' => ['APACHE_INSTALLATION' => 'typed by hand']]);
        $html = $this->renderStep($entities_id, DatWizard::STEP_WEB);
        $this->assertStringContainsString('typed by hand', $html);
        $this->assertStringNotContainsString('net start Apache2.4', $html);
    }
}
