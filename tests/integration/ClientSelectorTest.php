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
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Entity;
use Twig\Loader\FilesystemLoader;

/**
 * Selector of the customer above the tabs of the clients management
 */
class ClientSelectorTest extends DbTestCase
{
    public function testClientsAreTheSonsOfTheCustomersEntityWithoutTheArchives(): void
    {
        $this->login();
        $root    = $this->getTestRootEntity(true);
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $this->configure($root, $child_2);

        $clients = Entity::getSelectableClients();

        $this->assertArrayHasKey($child_1, $clients);
        // The customers entity itself is "all the clients", not one of them
        $this->assertArrayNotHasKey($root, $clients);
        // Archived customers are not offered
        $this->assertArrayNotHasKey($child_2, $clients);
    }

    public function testSelectorShowsTheActiveClient(): void
    {
        $this->login();
        $root    = $this->getTestRootEntity(true);
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $this->configure($root, 0);

        $this->assertTrue(\Session::changeActiveEntities($child_1));
        $html = $this->render();
        $this->assertStringContainsString('name="choose_client"', $html);
        $this->assertMatchesRegularExpression('/<option value=\'' . $child_1 . '\' selected/', $html);
        // A single customer: its summary card above the tabs
        $this->assertStringContainsString('Referent contact', $html);

        // Several entities active: "all the clients", no summary
        $this->assertTrue(\Session::changeActiveEntities($root, true));
        $html = $this->render();
        $this->assertMatchesRegularExpression('/<option value=\'0\' selected/', $html);
        $this->assertStringNotContainsString('Referent contact', $html);
    }

    private function configure(int $customers_entity, int $archive_entity): void
    {
        global $DB;

        $DB->update(Config::getTable(), [
            'wizard_default_entities_id' => $customers_entity,
            'wizard_archive_entities_id' => $archive_entity,
        ], ['id' => 1]);
        $property = new \ReflectionProperty(Config::class, 'instance');
        $property->setValue(null, null);
    }

    /**
     * Output of the selector. The test kernel does not boot the plugins: their Twig namespace is
     * declared here, as the plugin loader does
     */
    private function render(): string
    {
        $loader = TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }

        ob_start();
        try {
            Entity::showClientSelector();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
