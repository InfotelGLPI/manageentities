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
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\ContractState;
use GlpiPlugin\Manageentities\EditorSubscription;
use GlpiPlugin\Manageentities\PresaleProposal;
use GlpiPlugin\Manageentities\PresaleRenewal;
use GlpiPlugin\Manageentities\PresaleWizard;

/**
 * Renewals to propose in the "Proposals in progress" tab: expired subscriptions and contracts
 * reported by the end / notice alerts, until a proposal follows them
 */
class PresaleRenewalTest extends DbTestCase
{
    use PresaleTestHelpers;

    public function setUp(): void
    {
        parent::setUp();
        $this->setUpPresale();
    }

    public function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    /** Customer entity: a child of the customers entity (the test root entity) */
    private function getCustomerEntity(): int
    {
        return getItemByTypeName(\Entity::class, '_test_child_1', true);
    }

    private function createSubscription(int $entities_id, string $end_date): EditorSubscription
    {
        global $DB;

        // One subscription per entity
        $DB->delete(EditorSubscription::getTable(), ['entities_id' => $entities_id]);

        /** @var EditorSubscription */
        return $this->createItem(EditorSubscription::class, [
            'entities_id' => $entities_id,
            'name'        => 'Subscription ' . $this->getUniqueString(),
            'begin_date'  => date('Y-m-d 00:00:00', strtotime($end_date . ' -1 year')),
            'end_date'    => $end_date . ' 00:00:00',
        ]);
    }

    /**
     * Contract of the plugin with one open period of $days days, nothing consumed yet
     */
    private function createContract(int $entities_id, float $days, bool $plugin = true): \Contract
    {
        /** @var \Contract $contract */
        $contract = $this->createItem(\Contract::class, [
            'entities_id' => $entities_id,
            'name'        => 'Contract ' . $this->getUniqueString(),
            'num'         => 'NUM-' . mt_rand(1000, 9999),
            'begin_date'  => date('Y-m-d', strtotime('-6 months')),
            'duration'    => 12,
        ]);
        if ($plugin) {
            $this->createItem(Contract::class, [
                'contracts_id' => $contract->getID(),
                'entities_id'  => $entities_id,
            ]);
        }
        $this->createItem(ContractDay::class, [
            'name'                                    => 'Period ' . $this->getUniqueString(),
            'entities_id'                             => $entities_id,
            'contracts_id'                            => $contract->getID(),
            'plugin_manageentities_contractstates_id' => $this->getOpenState(),
            'begin_date'                              => date('Y-m-d', strtotime('-6 months')),
            'end_date'                                => date('Y-m-d', strtotime('+6 months')),
            'nbday'                                   => $days,
        ], ['begin_date', 'end_date']);

        return $contract;
    }

    private ?int $open_state = null;

    /** State of period that is not closed */
    private function getOpenState(): int
    {
        return $this->open_state ??= $this->createItem(ContractState::class, [
            'name'      => 'Open ' . $this->getUniqueString(),
            'is_closed' => 0,
        ])->getID();
    }

    public function testExpiredSubscriptionIsListedUntilAProposalFollowsIt(): void
    {
        $entities_id  = $this->getCustomerEntity();
        $subscription = $this->createSubscription($entities_id, date('Y-m-d', strtotime('-10 days')));

        $rows = PresaleRenewal::getExpiredSubscriptions([$entities_id]);
        $this->assertSame([$subscription->getID()], array_column($rows, 'id'));
        $this->assertStringContainsString('editorsubscriptions_id=' . $subscription->getID(), $rows[0]['new_url']);
        $this->assertStringContainsString('entities_id=' . $entities_id, $rows[0]['new_url']);

        // A proposal in progress follows it: the subscription leaves the list
        $proposal = $this->createProposal($this->createPresaleClient(['converted_entities_id' => $entities_id]), [
            'plugin_manageentities_editorsubscriptions_id' => $subscription->getID(),
        ]);
        $this->assertSame([], PresaleRenewal::getExpiredSubscriptions([$entities_id]));

        // Lost: to renew again
        $this->updateItem(PresaleProposal::class, $proposal->getID(), ['status' => PresaleProposal::STATUS_LOST]);
        $this->assertCount(1, PresaleRenewal::getExpiredSubscriptions([$entities_id]));
    }

    public function testRunningSubscriptionAndOtherEntitiesAreNotListed(): void
    {
        $entities_id = $this->getCustomerEntity();
        $this->createSubscription($entities_id, date('Y-m-d', strtotime('+1 month')));
        $this->assertSame([], PresaleRenewal::getExpiredSubscriptions([$entities_id]));

        // Expired, but outside the active entities
        $this->createSubscription($entities_id, date('Y-m-d', strtotime('-1 month')));
        $other = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $this->assertSame([], PresaleRenewal::getExpiredSubscriptions([$other]));
    }

    public function testContractsAreTheOnesOfTheRemainingDaysMail(): void
    {
        $entities_id = $this->getCustomerEntity();

        // No day left: reported, as in the mail
        $empty = $this->createContract($entities_id, 0);
        // Not handled by the plugin
        $this->createContract($entities_id, 0, false);

        $rows = PresaleRenewal::getExpiringContracts([$entities_id]);
        $this->assertSame([$empty->getID()], array_column($rows, 'id'));
        $this->assertSame(
            array_column(Contract::getLowRemainingDaysContracts([$entities_id]), 'contracts_id'),
            array_column($rows, 'id'),
        );
        $this->assertStringContainsString('contracts_id=' . $empty->getID(), $rows[0]['new_url']);

        // A proposal in progress follows it: it leaves the list
        $this->createProposal($this->createPresaleClient(['converted_entities_id' => $entities_id]), [
            'contracts_id' => $empty->getID(),
        ]);
        $this->assertSame([], PresaleRenewal::getExpiringContracts([$entities_id]));
    }

    public function testTheTabShowsTheBadgesAndTheListsOfRenewals(): void
    {
        $entities_id = $this->getCustomerEntity();
        $this->createSubscription($entities_id, date('Y-m-d', strtotime('-10 days')));
        $this->createContract($entities_id, 0);

        // The templates of the plugin: registered by the plugin loader, absent from the tests
        $loader = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }

        ob_start();
        try {
            \GlpiPlugin\Manageentities\Entity::showOpenProposals([$entities_id]);
        } finally {
            $html = (string) ob_get_clean();
        }

        // A badge per list, opening it
        $this->assertStringContainsString('href="#me-renewal-subscriptions"', $html);
        $this->assertStringContainsString('href="#me-renewal-contracts"', $html);
        $this->assertStringContainsString('id="me-renewal-subscriptions"', $html);
        $this->assertStringContainsString('id="me-renewal-contracts"', $html);
        $this->assertSame(2, substr_count($html, 'contracts_id=') + substr_count($html, 'editorsubscriptions_id='));
    }

    public function testACustomerWithDaysLeftOnAnotherContractIsNotListed(): void
    {
        $entities_id = $this->getCustomerEntity();
        $this->createContract($entities_id, 0);
        // Days left on another contract of the same customer: nothing runs out, as in the mail
        $this->createContract($entities_id, 10);

        $this->assertSame([], PresaleRenewal::getExpiringContracts([$entities_id]));
    }

    public function testTheSourceOfANewProposalIsCheckedAgainstItsCustomer(): void
    {
        $entities_id  = $this->getCustomerEntity();
        $subscription = $this->createSubscription($entities_id, date('Y-m-d', strtotime('-10 days')));
        $contract = $this->createContract($entities_id, 0);

        $this->assertSame(
            ['plugin_manageentities_editorsubscriptions_id' => $subscription->getID()],
            PresaleRenewal::getSourceInput(['editorsubscriptions_id' => $subscription->getID()], $entities_id),
        );
        $this->assertSame(
            ['contracts_id' => $contract->getID()],
            PresaleRenewal::getSourceInput(['contracts_id' => $contract->getID()], $entities_id),
        );
        // Another customer: refused
        $other = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $this->assertSame([], PresaleRenewal::getSourceInput(['editorsubscriptions_id' => $subscription->getID()], $other));
        $this->assertSame([], PresaleRenewal::getSourceInput(['contracts_id' => $contract->getID()], $other));
    }

    public function testStepOneCreatesTheProposalLinkedToTheRenewedSubscription(): void
    {
        $entities_id  = $this->getCustomerEntity();
        $subscription = $this->createSubscription($entities_id, date('Y-m-d', strtotime('-10 days')));

        $result = PresaleWizard::saveClient(0, [
            'customer_entities_id'   => $entities_id,
            'editorsubscriptions_id' => $subscription->getID(),
            'name'                   => 'Customer ' . $this->getUniqueString(),
            'address'                => '1 rue du test',
            'postcode'               => '44000',
            'town'                   => 'Nantes',
            'contacts'               => [['name' => 'DUPONT', 'firstname' => 'Marie', 'email' => 'marie@example.com']],
            'is_default'             => 0,
        ]);

        $this->assertSame([], $result['errors']);
        $proposal = new PresaleProposal();
        $this->assertTrue($proposal->getFromDB($result['id']));
        $this->assertSame($subscription->getID(), (int) $proposal->fields['plugin_manageentities_editorsubscriptions_id']);
        $this->assertSame([], PresaleRenewal::getExpiredSubscriptions([$entities_id]));
    }
}
