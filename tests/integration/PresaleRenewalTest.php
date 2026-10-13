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

use Alert;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\Contract;
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
     * Contract of the plugin ending $months months after $begin
     */
    private function createContract(int $entities_id, string $begin, int $months, int $notice = 0, bool $plugin = true): \Contract
    {
        /** @var \Contract $contract */
        $contract = $this->createItem(\Contract::class, [
            'entities_id' => $entities_id,
            'name'        => 'Contract ' . $this->getUniqueString(),
            'num'         => 'NUM-' . mt_rand(1000, 9999),
            'begin_date'  => $begin,
            'duration'    => $months,
            'notice'      => $notice,
            'alert'       => (2 ** Alert::END) + (2 ** Alert::NOTICE),
        ]);
        if ($plugin) {
            $this->createItem(Contract::class, [
                'contracts_id' => $contract->getID(),
                'entities_id'  => $entities_id,
            ]);
        }

        return $contract;
    }

    /** Contract alerts of the entities: enabled, $delay days ahead */
    private function enableContractAlerts(int $delay): void
    {
        // Through update(): the cache of the entity configuration is cleared
        $this->updateItem(\Entity::class, $this->getTestRootEntity(true), [
            'use_contracts_alert'               => 1,
            'send_contracts_alert_before_delay' => $delay,
        ]);
        $this->updateItem(\Entity::class, $this->getCustomerEntity(), [
            'use_contracts_alert'               => \Entity::CONFIG_PARENT,
            'send_contracts_alert_before_delay' => \Entity::CONFIG_PARENT,
        ]);
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

    public function testContractsFollowTheEndAndNoticeAlertsOfTheCore(): void
    {
        $entities_id = $this->getCustomerEntity();
        $this->enableContractAlerts(30);

        // Ends in about 20 days: reported
        $ending   = $this->createContract($entities_id, date('Y-m-d', strtotime('-12 months +20 days')), 12);
        // Ends in about 6 months, notice of 6 months: notice date in a few days, reported
        $notice   = $this->createContract($entities_id, date('Y-m-d', strtotime('-6 months +5 days')), 12, 6);
        // Ends in about 6 months, no notice: not yet
        $this->createContract($entities_id, date('Y-m-d', strtotime('-6 months')), 12);
        // Ending, but not handled by the plugin
        $this->createContract($entities_id, date('Y-m-d', strtotime('-12 months +20 days')), 12, 0, false);

        $ids = array_column(PresaleRenewal::getExpiringContracts([$entities_id]), 'id');
        sort($ids);
        $expected = [$ending->getID(), $notice->getID()];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testNoContractWhenTheAlertsAreDisabled(): void
    {
        $entities_id = $this->getCustomerEntity();
        $this->createContract($entities_id, date('Y-m-d', strtotime('-12 months +20 days')), 12);
        $this->updateItem(\Entity::class, $this->getTestRootEntity(true), ['use_contracts_alert' => 0]);
        $this->updateItem(\Entity::class, $entities_id, ['use_contracts_alert' => \Entity::CONFIG_PARENT]);

        $this->assertSame([], PresaleRenewal::getExpiringContracts([$entities_id]));
    }

    public function testTheSourceOfANewProposalIsCheckedAgainstItsCustomer(): void
    {
        $entities_id  = $this->getCustomerEntity();
        $subscription = $this->createSubscription($entities_id, date('Y-m-d', strtotime('-10 days')));
        $this->enableContractAlerts(30);
        $contract = $this->createContract($entities_id, date('Y-m-d', strtotime('-12 months +20 days')), 12);

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
