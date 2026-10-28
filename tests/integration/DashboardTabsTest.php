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
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\ContractState;
use GlpiPlugin\Manageentities\CustomerSummary;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Preference;
use GlpiPlugin\Manageentities\TechLead;

/**
 * Tabs of the clients management: order chosen in the preferences, counters
 */
class DashboardTabsTest extends DbTestCase
{
    public function testOrderIsSavedWithTheKnownTabsOnlyAndAppliedToTheTabs(): void
    {
        global $DB;

        $this->login();
        $users_id = (int) \Session::getLoginUserID();
        $pref_id  = Preference::checkIfPreferenceExists($users_id) ?: Preference::addDefaultPreference($users_id);

        // Everything displayed, then an order with an unknown tab and a duplicate
        $known = array_keys(Entity::getDashboardTabLabels());
        $this->assertContains(Entity::TAB_TICKETS, $known);
        $pref = new Preference();
        $this->assertTrue($pref->update([
            'id'                   => $pref_id,
            'dashboard_tabs'       => $known,
            'dashboard_tabs_order' => Entity::TAB_TICKETS . ',999,abc,4,' . Entity::TAB_TICKETS,
        ]));
        $stored = $DB->request(['FROM' => Preference::getTable(), 'WHERE' => ['id' => $pref_id]])->current();
        $this->assertSame([Entity::TAB_TICKETS, 4], json_decode($stored['dashboard_tabs_order'], true));

        // Read back: the cache of the request is empty until now
        $this->assertSame([Entity::TAB_TICKETS, 4], Preference::getDashboardTabsOrder($users_id));

        $tabs = (new Entity())->getTabNameForItem(new Entity());
        $this->assertIsArray($tabs);
        $keys = array_keys($tabs);
        // The ordered tabs that are offered come first, in that order
        $offered = array_values(array_intersect([Entity::TAB_TICKETS, 4], $keys));
        $this->assertSame($offered, array_slice($keys, 0, count($offered)));
    }

    public function testTechLeadCounterCountsTheClientsWithAMainTechLead(): void
    {
        global $DB;

        $this->login();
        $root    = $this->getTestRootEntity(true);
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $DB->update(Config::getTable(), ['wizard_default_entities_id' => $root, 'wizard_archive_entities_id' => 0], ['id' => 1]);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, null);

        $DB->delete(TechLead::getTable(), ['entities_id' => [$child_1, $child_2]]);
        $tech = getItemByTypeName(\User::class, 'tech', true);
        $DB->insert(TechLead::getTable(), ['users_id' => $tech, 'entities_id' => $child_1, 'is_default' => 1]);
        // A tech lead that is not the main one does not count
        $DB->insert(TechLead::getTable(), ['users_id' => $tech, 'entities_id' => $child_2, 'is_default' => 0]);

        $this->assertSame(1, TechLead::countClientsWithMainTechLead([$child_1]));
        $this->assertSame(0, TechLead::countClientsWithMainTechLead([$child_2]));
        $this->assertSame(1, TechLead::countClientsWithMainTechLead([$child_1, $child_2]));
    }


    public function testSummaryCardIsTheFirstTabOfTheSimplifiedInterface(): void
    {
        global $DB;

        $this->login();
        $DB->update(Config::getTable(), ['use_editorsubscriptions' => 1], ['id' => 1]);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, null);
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $DB->insert(\GlpiPlugin\Manageentities\DirectHelpdesk::getTable(), [
            'entities_id' => $child_1,
            'name'        => 'Unbilled',
            'actiontime'  => 4 * HOUR_TIMESTAMP,
            'is_billed'   => 0,
            'date'        => '2026-10-01 10:00:00',
        ]);

        $this->login('post-only', 'postonly');
        $this->assertSame('helpdesk', \Session::getCurrentInterface());
        $_SESSION['glpiactiveentities'] = [$child_1 => $child_1];
        $_SESSION['glpiactive_entity']  = $child_1;

        $tabs = (new Entity())->getTabNameForItem(new Entity());
        $this->assertIsArray($tabs);
        $this->assertSame(Entity::TAB_SUMMARY, array_key_first($tabs));
        // The summary replaces the administrative data, the subscriptions come before the GANTT
        $this->assertArrayNotHasKey(4, $tabs);
        $keys = array_keys($tabs);
        $this->assertArrayHasKey(6, $tabs);
        if (isset($tabs[3])) {
            $this->assertLessThan(array_search(3, $keys, true), array_search(6, $keys, true));
        }

        $loader = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }
        ob_start();
        try {
            \GlpiPlugin\Manageentities\CustomerSummary::show($child_1);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('_test_child_1', $html);
        // 4 hours unbilled: beyond the 3 hours, in red
        $this->assertStringContainsString('border-red', $html);
    }

    public function testTheTabIsKeptOnceAfterAPostedForm(): void
    {
        $this->assertFalse(Entity::consumeKeepTab());
        Entity::keepTab();
        $this->assertTrue(Entity::consumeKeepTab());
        // Once only: the next visit opens on the first tab again
        $this->assertFalse(Entity::consumeKeepTab());
    }

    public function testContractsOfArchivedCustomersAreNotListed(): void
    {
        global $DB;

        $this->login();
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $DB->update(Config::getTable(), ['contract_states' => null, 'wizard_archive_entities_id' => $child_2], ['id' => 1]);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, null);

        foreach ([$child_1, $child_2] as $entities_id) {
            $contracts_id = (int) $this->createItem(\Contract::class, ['name' => 'Assistance ' . $entities_id, 'entities_id' => $entities_id])->getID();
            $DB->insert(\GlpiPlugin\Manageentities\Contract::getTable(), ['contracts_id' => $contracts_id, 'entities_id' => $entities_id]);
        }

        $contract = new \GlpiPlugin\Manageentities\Contract();
        $this->assertSame(1, $contract->countForEntities([$child_1]));
        // The archive entity: its contracts are left out
        $this->assertSame(0, $contract->countForEntities([$child_2]));
        $this->assertSame(1, $contract->countForEntities([$child_1, $child_2]));
    }

    public function testSimplifiedInterfaceCountsContractsAndHasAnUnbilledTab(): void
    {
        global $DB;

        $this->login();
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $DB->update(Config::getTable(), ['contract_states' => null, 'wizard_archive_entities_id' => 0], ['id' => 1]);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, null);
        $contracts_id = (int) $this->createItem(\Contract::class, ['name' => 'Ongoing', 'entities_id' => $child_1])->getID();
        $DB->insert(\GlpiPlugin\Manageentities\Contract::getTable(), ['contracts_id' => $contracts_id, 'entities_id' => $child_1]);
        // Ongoing: listed by the contractual follow-up, that is, with a period
        $this->createItem(ContractDay::class, [
            'name'                                    => 'Period',
            'entities_id'                             => $child_1,
            'contracts_id'                            => $contracts_id,
            'plugin_manageentities_contractstates_id' => $this->createItem(ContractState::class, ['name' => 'Open', 'is_closed' => 0])->getID(),
            'nbday'                                   => 5,
        ]);
        $DB->insert(\GlpiPlugin\Manageentities\DirectHelpdesk::getTable(), [
            'entities_id' => $child_1,
            'name'        => 'Unbilled',
            'actiontime'  => HOUR_TIMESTAMP,
            'is_billed'   => 0,
            'date'        => '2026-10-01 10:00:00',
        ]);

        $this->assertGreaterThanOrEqual(1, \GlpiPlugin\Manageentities\CustomerSummary::countOngoingContracts([$child_1]));
        $this->assertGreaterThanOrEqual(1, \GlpiPlugin\Manageentities\CustomerSummary::countUnbilled([$child_1]));

        $this->login('post-only', 'postonly');
        $_SESSION['glpiactiveentities'] = [$child_1 => $child_1];
        $_SESSION['glpiactive_entity']  = $child_1;
        // The customer reads its follow-up
        $_SESSION['glpiactiveprofile']['plugin_manageentities'] = READ;
        $tabs = (new Entity())->getTabNameForItem(new Entity());
        $this->assertIsArray($tabs);
        $this->assertArrayHasKey(1, $tabs);
        $this->assertStringContainsString('glpi-badge', (string) $tabs[1]);
        $this->assertArrayHasKey(Entity::TAB_HELPDESK_UNBILLED, $tabs);
        $this->assertStringContainsString('glpi-badge', (string) $tabs[Entity::TAB_HELPDESK_UNBILLED]);
        // Order: summary, contractual follow-up, unbilled interventions
        $this->assertSame([Entity::TAB_SUMMARY, 1, Entity::TAB_HELPDESK_UNBILLED], array_slice(array_keys($tabs), 0, 3));

        // The tab lists the unbilled interventions, as the contractual follow-up did
        $loader = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }
        ob_start();
        try {
            Entity::displayTabContentForItem(new Entity(), Entity::TAB_HELPDESK_UNBILLED);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('Unbilled', $html);
    }

    public function testSimplifiedInterfaceSummaryMatchesTheContractualFollowUp(): void
    {
        global $DB;

        $this->login();
        $entities_id = $this->createItem(\Entity::class, [
            'name'        => 'Customer ' . $this->getUniqueString(),
            'entities_id' => getItemByTypeName(\Entity::class, '_test_root_entity', true),
        ])->getID();
        $open_state  = $this->createItem(ContractState::class, ['name' => 'Open ' . $this->getUniqueString(), 'is_closed' => 0])->getID();
        $other_state = $this->createItem(ContractState::class, ['name' => 'Other ' . $this->getUniqueString(), 'is_closed' => 0])->getID();
        $closed_glpi_state = $this->createItem(\State::class, ['name' => 'Closed ' . $this->getUniqueString()])->getID();
        $DB->update(Config::getTable(), [
            'hourorday'                  => Config::DAY,
            'contract_states'            => json_encode([$open_state]),
            'closed_glpi_state_id'       => $closed_glpi_state,
            'wizard_archive_entities_id' => 0,
        ], ['id' => 1]);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, null);

        $add_contract = function (string $name, int $states_id, array $periods) use ($DB, $entities_id): void {
            $contracts_id = $this->createItem(\Contract::class, [
                'name'        => $name,
                'entities_id' => $entities_id,
                'states_id'   => $states_id,
            ])->getID();
            $DB->insert(\GlpiPlugin\Manageentities\Contract::getTable(), ['contracts_id' => $contracts_id, 'entities_id' => $entities_id]);
            foreach ($periods as $contractstates_id => $nbday) {
                $this->createItem(ContractDay::class, [
                    'name'                                    => $name . ' ' . $contractstates_id,
                    'entities_id'                             => $entities_id,
                    'contracts_id'                            => $contracts_id,
                    'plugin_manageentities_contractstates_id' => $contractstates_id,
                    'nbday'                                   => $nbday,
                ]);
            }
        };
        // Followed: only its period in a configured state counts
        $add_contract('Followed', 0, [$open_state => 5, $other_state => 30]);
        // Closed in GLPI: not followed by the customer
        $add_contract('Closed', $closed_glpi_state, [$open_state => 10]);
        // Without any period in a configured state: not followed either
        $add_contract('Other', 0, [$other_state => 8]);

        $this->login('post-only', 'postonly');
        $_SESSION['glpiactiveentities'] = [$entities_id => $entities_id];
        $_SESSION['glpiactive_entity']  = $entities_id;

        $list = \GlpiPlugin\Manageentities\Followup::queryFollowUp($entities_id);
        $this->assertSame(5.0, (float) $list['tot']['tot_reste']);

        // The tab counter and the summary card show what the tab lists
        $this->assertSame(1, CustomerSummary::countOngoingContracts([$entities_id]));
        $remaining = (new \ReflectionMethod(CustomerSummary::class, 'getOngoingContractsRemaining'))->invoke(null, $entities_id);
        $this->assertSame(['count' => 1, 'remaining' => 5.0], $remaining);
    }

    public function testReferencesAreGroupedByYearWithTheirDetails(): void
    {
        global $DB;

        $this->login();
        $child_1 = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $DB->update('glpi_entities', ['town' => 'Nantes', 'country' => 'France', 'website' => 'https://example.com'], ['id' => $child_1]);
        foreach (['2024-05-02', '2025-01-10'] as $date) {
            $contracts_id = (int) $this->createItem(\Contract::class, ['name' => 'Signed ' . $date, 'entities_id' => $child_1])->getID();
            $DB->insert(\GlpiPlugin\Manageentities\Contract::getTable(), [
                'contracts_id'   => $contracts_id,
                'entities_id'    => $child_1,
                'date_signature' => $date . ' 00:00:00',
            ]);
        }
        $DB->insert(\GlpiPlugin\Manageentities\BusinessContact::getTable(), [
            'users_id'    => getItemByTypeName(\User::class, 'tech', true),
            'entities_id' => $child_1,
            'is_default'  => 1,
        ]);

        $years = Entity::getReferences();
        // The year of the FIRST signature
        $this->assertArrayHasKey(2024, $years);
        $client = current(array_filter($years[2024], static fn(array $c): bool => $c['entities_id'] === $child_1));
        $this->assertIsArray($client);
        $this->assertSame('2024-05-02', $client['signature']);
        $this->assertSame('Nantes', $client['town']);
        $this->assertSame('France', $client['country']);
        $this->assertTrue($client['website_is_url']);
        $this->assertNotSame('', $client['sales']);
        foreach ($years[2025] ?? [] as $other) {
            $this->assertNotSame($child_1, $other['entities_id']);
        }

        $loader = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }
        ob_start();
        try {
            (new Entity())->showReferences([$child_1]);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('export=references', $html);
        $this->assertStringContainsString('&amp;year=2024', $html);
        $this->assertStringContainsString('Nantes, France', $html);

        // CSV of one year: the header, then its clients only
        $rows = Entity::getReferencesCsvRows(2024);
        $this->assertSame('Year', $rows[0][0]);
        $lines = array_slice($rows, 1);
        $this->assertNotEmpty($lines);
        $this->assertSame(['2024'], array_values(array_unique(array_column($lines, 0))));
        $this->assertContains('2024-05-02', array_column($lines, 2));
        // All the years
        $all = Entity::getReferencesCsvRows(null);
        $this->assertGreaterThanOrEqual(count($rows), count($all));
        // Counter of the tab: one per client listed
        $this->assertSame(count($all) - 1, Entity::countReferences());

        // Response of the export: a CSV file, its header line after the UTF-8 BOM. Refused
        // without the right of the plugin.
        $_SESSION['glpiactiveprofile']['plugin_manageentities'] = 0;
        try {
            Entity::getReferencesCsvResponse(2024);
            $this->fail('The export was allowed without the right of the plugin.');
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException) {
            // Expected
        }
        $_SESSION['glpiactiveprofile']['plugin_manageentities'] = READ;
        $response = Entity::getReferencesCsvResponse(2024);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\xEF\xBB\xBFYear;", (string) $response->getContent());
    }
}
