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
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\CustomerSummary;
use GlpiPlugin\Manageentities\Dat;
use GlpiPlugin\Manageentities\DatOsTemplate;
use GlpiPlugin\Manageentities\DatWizard;
use GlpiPlugin\Manageentities\Dex;
use GlpiPlugin\Manageentities\DexWizard;
use GlpiPlugin\Manageentities\GeneratedDocument;
use GlpiPlugin\Manageentities\Profile;

/**
 * Last generated DAT / DEX of a client: badges of the summary card, download route
 */
class GeneratedDocumentTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->login();
        // The DAT and DEX rights of the profile, as granted on install
        Profile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
        Profile::initProfile();

        $loader = TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }
    }

    private function createEntity(): int
    {
        return (int) $this->createItem(\Entity::class, [
            'name'        => 'Generated client ' . mt_rand(),
            'entities_id' => $this->getTestRootEntity(true),
        ])->getID();
    }

    private function createDat(int $entities_id): Dat
    {
        $result = DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-05-12',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        $this->assertSame([], $result['errors']);

        $dat = Dat::getForEntity($entities_id);
        $this->assertNotNull($dat);

        return $dat;
    }

    private function createDex(int $entities_id): Dex
    {
        $result = DexWizard::saveGeneral($entities_id, [
            'client'          => 'ACME',
            'author_users_id' => \Session::getLoginUserID(),
            'dex_date'        => '2031-05-12',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        $this->assertSame([], $result['errors']);

        $dex = Dex::getForEntity($entities_id);
        $this->assertNotNull($dex);

        return $dex;
    }

    /**
     * What a generation stores (DatGenerator / DexGenerator::generate()), without the Word
     * template: a document linked to the entity, named as the generator names it, then the new
     * version and the last document on the DAT / DEX
     *
     * @param ?int $linked_to entity the document is linked to, the one of the DAT / DEX when null
     */
    private function simulateGeneration(Dat|Dex $item, int $version, ?string $name = null, ?int $linked_to = null): \Document
    {
        $entities_id = (int) $item->fields['entities_id'];
        $prefix      = $item instanceof Dat ? 'DAT' : 'DEX';
        $document    = $this->createItem(\Document::class, [
            'name'        => $name ?? sprintf('%s - ACME - v%d.0.docx', $prefix, $version),
            'entities_id' => $entities_id,
        ]);
        $this->createItem(\Document_Item::class, [
            'documents_id' => $document->getID(),
            'itemtype'     => \Entity::class,
            'items_id'     => $linked_to ?? $entities_id,
        ]);
        $this->assertTrue($item->update([
            'id'           => $item->getID(),
            'version'      => $version,
            'documents_id' => $document->getID(),
            'status'       => $item instanceof Dat ? Dat::STATUS_GENERATED : Dex::STATUS_GENERATED,
        ]));
        $item->getFromDB($item->getID());

        $this->assertInstanceOf(\Document::class, $document);

        return $document;
    }

    private function renderSummary(int $entities_id): string
    {
        ob_start();
        try {
            CustomerSummary::show($entities_id);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Badges of the summary card
    // -------------------------------------------------------------------------

    public function testNoBadgeWithoutAnyGeneratedDocument(): void
    {
        $entities_id = $this->createEntity();
        $this->assertSame([], GeneratedDocument::getBadges($entities_id));

        // A draft never generated has no badge either
        $this->createDat($entities_id);
        $this->createDex($entities_id);
        $this->assertSame([], GeneratedDocument::getBadges($entities_id));

        $html = $this->renderSummary($entities_id);
        $this->assertStringNotContainsString('DAT v', $html);
        $this->assertStringNotContainsString('DEX v', $html);
        $this->assertStringNotContainsString('generateddoc.send.php', $html);
    }

    public function testBadgeShowsTheVersionOfTheLastGeneration(): void
    {
        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);
        $this->simulateGeneration($dat, 1);
        $last = $this->simulateGeneration($dat, 3);

        $badges = GeneratedDocument::getBadges($entities_id);
        $this->assertCount(1, $badges);
        $this->assertSame('dat', $badges[0]['type']);
        $this->assertSame('DAT v3.0', $badges[0]['label']);
        // Central user with the core document right: the core download of the last version
        $this->assertStringContainsString('/front/document.send.php?docid=' . $last->getID() . '&', $badges[0]['url']);

        $dex = $this->createDex($entities_id);
        $this->simulateGeneration($dex, 1);
        $badges = GeneratedDocument::getBadges($entities_id);
        $this->assertSame(['DAT v3.0', 'DEX v1.0'], array_column($badges, 'label'));

        $html = $this->renderSummary($entities_id);
        $this->assertStringContainsString('DAT v3.0', $html);
        $this->assertStringContainsString('DEX v1.0', $html);
    }

    public function testDraftAfterAGenerationKeepsTheBadgeOfTheLastVersion(): void
    {
        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);
        $this->simulateGeneration($dat, 2);

        // A new version started: the DAT goes back to a draft, its last document is kept
        DatWizard::saveGeneral($entities_id, [
            'client'          => 'ACME Corp',
            'author_users_id' => \Session::getLoginUserID(),
            'dat_date'        => '2031-06-01',
            'os'              => DatOsTemplate::OS_DEBIAN,
        ]);
        $this->assertTrue($dat->update(['id' => $dat->getID(), 'status' => Dat::STATUS_DRAFT]));

        $this->assertSame(['DAT v2.0'], array_column(GeneratedDocument::getBadges($entities_id), 'label'));
    }

    public function testNoBadgeWithoutTheRight(): void
    {
        $entities_id = $this->createEntity();
        $this->simulateGeneration($this->createDat($entities_id), 1);
        $this->simulateGeneration($this->createDex($entities_id), 1);

        $_SESSION['glpiactiveprofile'][Dat::$rightname] = 0;
        $this->assertSame(['DEX v1.0'], array_column(GeneratedDocument::getBadges($entities_id), 'label'));

        $_SESSION['glpiactiveprofile'][Dex::$rightname] = 0;
        $this->assertSame([], GeneratedDocument::getBadges($entities_id));
        $this->assertStringNotContainsString('DEX v', $this->renderSummary($entities_id));
    }

    public function testSimplifiedInterfaceBadgeUsesThePluginRoute(): void
    {
        $entities_id = $this->createEntity();
        $document    = $this->simulateGeneration($this->createDat($entities_id), 1);

        $this->login('post-only', 'postonly');
        $this->assertSame('helpdesk', \Session::getCurrentInterface());
        $_SESSION['glpiactiveentities'] = [$entities_id => $entities_id];
        $_SESSION['glpiactive_entity']  = $entities_id;

        // No DAT right on the profile: nothing
        $_SESSION['glpiactiveprofile'][Dat::$rightname] = 0;
        $this->assertSame([], GeneratedDocument::getBadges($entities_id));

        // READ granted by the administrator: the badge, through the plugin route
        $_SESSION['glpiactiveprofile'][Dat::$rightname] = READ;
        $badges = GeneratedDocument::getBadges($entities_id);
        $this->assertCount(1, $badges);
        $this->assertSame(
            PLUGIN_MANAGEENTITIES_WEBDIR . '/front/generateddoc.send.php?type=dat&entities_id=' . $entities_id,
            $badges[0]['url'],
        );
        // ...that serves the document, the core document right not being needed
        $this->assertSame($document->getID(), GeneratedDocument::getDownloadableDocument('dat', $entities_id)->getID());
    }

    // -------------------------------------------------------------------------
    // Download route
    // -------------------------------------------------------------------------

    public function testDownloadReturnsTheLastGeneratedDocument(): void
    {
        $entities_id = $this->createEntity();
        $dat         = $this->createDat($entities_id);
        $this->simulateGeneration($dat, 1);
        $last = $this->simulateGeneration($dat, 2);

        $this->assertSame($last->getID(), GeneratedDocument::getDownloadableDocument('dat', $entities_id)->getID());

        $dex      = $this->createDex($entities_id);
        $last_dex = $this->simulateGeneration($dex, 1);
        $this->assertSame($last_dex->getID(), GeneratedDocument::getDownloadableDocument('dex', $entities_id)->getID());
    }

    public function testDownloadIsRefusedWithoutTheRight(): void
    {
        $entities_id = $this->createEntity();
        $this->simulateGeneration($this->createDat($entities_id), 1);
        $_SESSION['glpiactiveprofile'][Dat::$rightname] = CREATE | UPDATE;

        $this->expectException(AccessDeniedHttpException::class);
        GeneratedDocument::getDownloadableDocument('dat', $entities_id);
    }

    public function testDownloadIsRefusedForAnEntityNotAccessible(): void
    {
        $entities_id = $this->createEntity();
        $other       = $this->createEntity();
        $this->simulateGeneration($this->createDat($entities_id), 1);

        $_SESSION['glpiactiveentities'] = [$other => $other];
        $_SESSION['glpiactive_entity']  = $other;

        $this->expectException(AccessDeniedHttpException::class);
        GeneratedDocument::getDownloadableDocument('dat', $entities_id);
    }

    public function testDownloadIsRefusedForADeletedDocument(): void
    {
        $entities_id = $this->createEntity();
        $document    = $this->simulateGeneration($this->createDat($entities_id), 1);
        $this->assertTrue($document->delete(['id' => $document->getID()]));

        $this->assertSame([], GeneratedDocument::getBadges($entities_id));
        $this->expectException(NotFoundHttpException::class);
        GeneratedDocument::getDownloadableDocument('dat', $entities_id);
    }

    public function testDownloadIsRefusedForADocumentNotLinkedToTheEntity(): void
    {
        $entities_id = $this->createEntity();
        $other       = $this->createEntity();
        $this->simulateGeneration($this->createDat($entities_id), 1, null, $other);

        $this->assertSame([], GeneratedDocument::getBadges($entities_id));
        $this->expectException(NotFoundHttpException::class);
        GeneratedDocument::getDownloadableDocument('dat', $entities_id);
    }

    public function testDownloadIsRefusedForADocumentNamedOtherwise(): void
    {
        $entities_id = $this->createEntity();
        // The DEX pointing to a DAT document: not served as a DEX
        $this->simulateGeneration($this->createDex($entities_id), 1, 'DAT - ACME - v1.0.docx');

        $this->expectException(NotFoundHttpException::class);
        GeneratedDocument::getDownloadableDocument('dex', $entities_id);
    }

    public function testDownloadIsRefusedWithoutAnyGeneration(): void
    {
        $entities_id = $this->createEntity();
        $this->createDat($entities_id);

        $this->expectException(NotFoundHttpException::class);
        GeneratedDocument::getDownloadableDocument('dat', $entities_id);
    }

    public function testDownloadRefusesAnUnknownType(): void
    {
        $entities_id = $this->createEntity();
        $this->simulateGeneration($this->createDat($entities_id), 1);

        foreach (['', 'DAT', 'presale', 'Dat', '../dat', 'GlpiPlugin\\Manageentities\\Dat'] as $type) {
            try {
                GeneratedDocument::getDownloadableDocument($type, $entities_id);
                $this->fail('Type accepted: ' . $type);
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
