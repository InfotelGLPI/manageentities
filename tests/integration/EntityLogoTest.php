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
use GlpiPlugin\Manageentities\EntityLogo;

/**
 * Logo of a customer: replacing it, including when the file of the previous one is lost
 */
class EntityLogoTest extends DbTestCase
{
    /** @var array{0: array, 1: bool}|null state of the plugins before bootPurgeHook() */
    private ?array $plugin_state = null;

    /**
     * The test kernel does not boot the plugins: the purge hook of the plugin on Document
     * (EntityLogo::cleanForItem(), item_purge) is registered and the plugin flagged active, as in
     * production, so that a purged logo document drops its row as it does there.
     */
    private function bootPurgeHook(): void
    {
        global $PLUGIN_HOOKS;

        $PLUGIN_HOOKS['item_purge']['manageentities'][\Document::class] = [EntityLogo::class, 'cleanForItem'];
        $activated = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $initialized = new \ReflectionProperty(\Plugin::class, 'plugins_initialized');
        $this->plugin_state = [$activated->getValue(), $initialized->getValue()];
        $activated->setValue(null, array_merge($activated->getValue(), ['manageentities']));
        $initialized->setValue(null, true);
    }

    public function tearDown(): void
    {
        global $PLUGIN_HOOKS;

        if ($this->plugin_state !== null) {
            (new \ReflectionProperty(\Plugin::class, 'activated_plugins'))->setValue(null, $this->plugin_state[0]);
            (new \ReflectionProperty(\Plugin::class, 'plugins_initialized'))->setValue(null, $this->plugin_state[1]);
            unset($PLUGIN_HOOKS['item_purge']['manageentities']);
            $this->plugin_state = null;
        }
        parent::tearDown();
    }

    public function testALostLogoIsReplacedByTheFirstUpload(): void
    {
        $this->login();
        $this->bootPurgeHook();
        $entities_id = getItemByTypeName(\Entity::class, '_test_child_1', true);

        $this->addLogo($entities_id, 40);
        $first = $this->getLogoDocument($entities_id);
        $this->assertNotNull($first);
        // The file of the logo is lost on the server
        unlink(GLPI_DOC_DIR . '/' . $first->fields['filepath']);

        // A single upload replaces it
        $this->addLogo($entities_id, 180);

        $logo = $this->getLogoDocument($entities_id);
        $this->assertNotNull($logo, 'The entity was left without logo after the upload.');
        $this->assertNotSame($first->getID(), $logo->getID());
        $this->assertFileExists(GLPI_DOC_DIR . '/' . $logo->fields['filepath']);
        $this->assertSame(1, countElementsInTable(EntityLogo::getTable(), ['entities_id' => $entities_id]));
    }

    public function testTheSamePictureReplacesItsLostFile(): void
    {
        $this->login();
        $this->bootPurgeHook();
        $entities_id = getItemByTypeName(\Entity::class, '_test_child_2', true);

        $this->addLogo($entities_id, 90);
        $first = $this->getLogoDocument($entities_id);
        $this->assertNotNull($first);
        unlink(GLPI_DOC_DIR . '/' . $first->fields['filepath']);

        // The very same picture uploaded again: a new document with its file
        $this->addLogo($entities_id, 90);

        $logo = $this->getLogoDocument($entities_id);
        $this->assertNotNull($logo);
        $this->assertFileExists(GLPI_DOC_DIR . '/' . $logo->fields['filepath']);
    }

    /**
     * Uploads a JPEG logo of a plain colour
     */
    private function addLogo(int $entities_id, int $red): void
    {
        $name  = uniqid('logo_', true) . '.jpg';
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 10, 10));
        imagejpeg($image, GLPI_TMP_DIR . '/' . $name);

        (new EntityLogo())->addLogo(['entities_id' => $entities_id, '_filename' => [$name]]);
    }

    private function getLogoDocument(int $entities_id): ?\Document
    {
        $rows = (new EntityLogo())->find(['entities_id' => $entities_id]);
        if ($rows === []) {
            return null;
        }
        $document = new \Document();

        return $document->getFromDB((int) reset($rows)['logos_id']) ? $document : null;
    }
}
