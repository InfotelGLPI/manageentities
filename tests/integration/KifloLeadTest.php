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
use Glpi\Application\View\TemplateRenderer;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\KifloClient;
use GlpiPlugin\Manageentities\KifloConfig;
use GlpiPlugin\Manageentities\KifloLead;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Loader\FilesystemLoader;

/**
 * Kiflo integration: schema, encrypted key, synchronization of the leads, leads tab
 */
class KifloLeadTest extends DbTestCase
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

        $this->assertArrayNotHasKey(KifloLead::getTable(), $differences);
        // Only the Kiflo columns: the configuration table has other differences of its own
        $this->assertDoesNotMatchRegularExpression('/^[-+].*`(use_)?kiflo_?/m', $differences[Config::getTable()]['diff'] ?? '');
    }

    public function testKeyIsStoredEncryptedAndKeptWhenLeftEmpty(): void
    {
        global $DB;

        $this->login();
        $config = new Config();
        $this->assertTrue($config->update(['id' => 1, 'use_kiflo' => 1, 'kiflo_token' => ' my-key ', 'kiflo_url' => 'https://api.kiflo.test/v3/']));

        $stored = $DB->request(['FROM' => Config::getTable(), 'WHERE' => ['id' => 1]])->current();
        $this->assertNotSame('my-key', $stored['kiflo_token']);
        $this->assertSame('https://api.kiflo.test/v3', $stored['kiflo_url']);
        $this->resetConfigInstance();
        $this->assertSame('my-key', Config::getKifloToken());

        // Empty field: the key is kept
        $this->assertTrue($config->update(['id' => 1, 'kiflo_token' => '']));
        $this->resetConfigInstance();
        $this->assertSame('my-key', Config::getKifloToken());

        // Plain HTTP is refused, the URL is kept
        $config->update(['id' => 1, 'kiflo_url' => 'http://api.kiflo.test']);
        $this->hasSessionMessages(ERROR, ['The Kiflo API URL must start with https://.']);
        $this->resetConfigInstance();
        $this->assertSame('https://api.kiflo.test/v3', Config::getKifloUrl());

        // Clear checkbox
        $this->assertTrue($config->update(['id' => 1, '_blank_kiflo_token' => 1, 'kiflo_token' => '']));
        $this->resetConfigInstance();
        $this->assertSame('', Config::getKifloToken());
    }

    public function testSynchronizationUpsertsAndFlagsTheMissingLeads(): void
    {
        global $DB;

        $this->login();
        $lead = static fn(int $id, string $status) => [
            'id'           => $id,
            'status'       => $status,
            'creationDate' => '2026-09-08T10:56:00Z',
            'properties'   => ['companyName' => 'Company ' . $id, 'commentaires' => "Line 1\n<b>Line 2</b>"],
        ];

        $client = new KifloClient('https://api.kiflo.test/v3', 'key', new MockHttpClient(
            new MockResponse(json_encode(['items' => [$lead(1, 'Pending'), $lead(2, 'Accepted')], 'totalCount' => 2])),
        ));
        $this->assertSame(2, KifloLead::synchronize($client));
        $this->assertSame(2, countElementsInTable(KifloLead::getTable(), ['is_deleted_kiflo' => 0]));

        // Lead 1 changes status, lead 2 is no longer returned
        $client = new KifloClient('https://api.kiflo.test/v3', 'key', new MockHttpClient(
            new MockResponse(json_encode(['items' => [$lead(1, 'Converted')], 'totalCount' => 1])),
        ));
        $this->assertSame(1, KifloLead::synchronize($client));

        $rows = iterator_to_array($DB->request(['FROM' => KifloLead::getTable(), 'ORDERBY' => 'kiflo_id']));
        $this->assertCount(2, $rows);
        $rows = array_values($rows);
        $this->assertSame('Converted', $rows[0]['status']);
        $this->assertSame(0, (int) $rows[0]['is_deleted_kiflo']);
        $this->assertSame(1, (int) $rows[1]['is_deleted_kiflo']);
        $this->assertNotNull($rows[0]['date_creation']);

        // Converted: closed, listed with the closed leads only
        $this->assertSame([], KifloLead::getLeads());
        $this->assertCount(1, KifloLead::getLeads(true));
        $this->resetConfigInstance();
        $this->assertSame('', Config::getInstance()->fields['kiflo_last_error']);
        $this->assertNotEmpty(Config::getInstance()->fields['kiflo_last_sync']);
    }

    public function testFailedSynchronizationKeepsTheLeadsAndStoresTheError(): void
    {
        $this->login();
        $client = new KifloClient('https://api.kiflo.test/v3', 'key', new MockHttpClient(
            new MockResponse(json_encode(['items' => [['id' => 7, 'status' => 'Pending']], 'totalCount' => 1])),
        ));
        KifloLead::synchronize($client);

        $client = new KifloClient('https://api.kiflo.test/v3', 'key', new MockHttpClient(
            new MockResponse('', ['http_code' => 401]),
        ));
        try {
            KifloLead::synchronize($client);
            $this->fail('An exception was expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
        }

        $this->assertSame(1, countElementsInTable(KifloLead::getTable(), ['kiflo_id' => 7, 'is_deleted_kiflo' => 0]));
        $this->resetConfigInstance();
        $this->assertStringContainsString('401', Config::getInstance()->fields['kiflo_last_error']);
    }

    public function testLeadsTabShowsTheEscapedDescriptionInADetail(): void
    {
        $this->login();
        $this->enableKiflo();
        $client = new KifloClient('https://api.kiflo.test/v3', 'key', new MockHttpClient(
            new MockResponse(json_encode(['items' => [[
                'id'         => 42,
                'status'     => 'Pending',
                'properties' => [
                    'companyName'  => 'ACME <script>',
                    'commentaires' => "Needs a demo\n<img src=x onerror=alert(1)>",
                    'budget'       => '10k',
                ],
            ]], 'totalCount' => 1])),
        ));
        KifloLead::synchronize($client);

        $html = $this->render(static fn() => KifloLead::showLeadsTab());

        $this->assertStringContainsString('data-bs-target="#kiflo_lead_', $html);
        $this->assertStringContainsString('class="modal fade"', $html);
        $this->assertStringContainsString('Needs a demo', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('ACME <script>', $html);
        // Custom property added by the publisher, not mapped to a column
        $this->assertStringContainsString('budget', $html);
        $this->assertStringContainsString('10k', $html);
    }

    public function testConfigTabRendersWithoutTheKey(): void
    {
        $this->login();
        $this->enableKiflo();

        $html = $this->render(static fn() => KifloConfig::showForm());

        $this->assertStringContainsString('dropdown_use_kiflo', $html);
        $this->assertStringContainsString('name="kiflo_url"', $html);
        $this->assertStringContainsString('name="kiflo_token"', $html);
        $this->assertStringContainsString('name="kiflo_test"', $html);
        $this->assertStringNotContainsString('my-key', $html);
    }

    /**
     * Output of a template. The test kernel does not boot the plugins: their Twig namespace is
     * declared here, as the plugin loader does
     */
    private function render(callable $display): string
    {
        $loader = TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }

        ob_start();
        try {
            $display();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function enableKiflo(): void
    {
        (new Config())->update(['id' => 1, 'use_kiflo' => 1, 'kiflo_token' => 'my-key']);
        $this->resetConfigInstance();
    }

    /**
     * Config::getInstance() caches the row for the whole request
     */
    private function resetConfigInstance(): void
    {
        $property = new \ReflectionProperty(Config::class, 'instance');
        $property->setValue(null, null);
    }
}
