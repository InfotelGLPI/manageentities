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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Manageentities\NotificationTargetPresaleProposal;
use GlpiPlugin\Manageentities\PresaleAnnexLine;
use GlpiPlugin\Manageentities\PresaleClient;
use GlpiPlugin\Manageentities\PresaleContact;
use GlpiPlugin\Manageentities\PresaleConversion;
use GlpiPlugin\Manageentities\PresaleCounter;
use GlpiPlugin\Manageentities\PresaleProposal;
use GlpiPlugin\Manageentities\PresaleProposal_Annex;
use GlpiPlugin\Manageentities\PresaleWizard;
use GlpiPlugin\Manageentities\WizardController;

/**
 * Life of a proposal: reference, outcome (won, lost, reactivated), duplication, deletion,
 * notifications and the customer wizard prefilled from a won proposal
 */
class PresaleProposalTest extends DbTestCase
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

    // -------------------------------------------------------------------------
    // Reference
    // -------------------------------------------------------------------------

    public function testReferencesFollowEachOtherByPrefixAndMonth(): void
    {
        $date = new \DateTimeImmutable('2031-07-15');

        $this->assertSame('SOC310701', PresaleCounter::next('Société Test', $date));
        $this->assertSame('SOC310702', PresaleCounter::next('Socotec', $date));
        // Another prefix starts its own sequence
        $this->assertSame('APR310701', PresaleCounter::next('APRR', $date));
        // A new month too
        $this->assertSame('SOC310801', PresaleCounter::next('Société Test', new \DateTimeImmutable('2031-08-01')));
    }

    public function testCounterLeavesNoTransactionOpen(): void
    {
        global $DB;

        $level = $this->callPrivateMethod($DB, 'isInTransaction');
        PresaleCounter::next('Transaction', new \DateTimeImmutable('2031-09-01'));

        $this->assertSame($level, $this->callPrivateMethod($DB, 'isInTransaction'));
    }

    // -------------------------------------------------------------------------
    // Outcome
    // -------------------------------------------------------------------------

    public function testAProposalNeverGeneratedCannotBeWon(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());

        $this->expectException(AccessDeniedHttpException::class);
        $this->runAction($proposal, 'won');
    }

    public function testWonOpensTheCustomerWizard(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient(), [
            'status'    => PresaleProposal::STATUS_GENERATED,
            'version'   => 1,
            'reference' => 'WON' . mt_rand(100000, 999999),
        ]);

        $url = $this->runAction($proposal, 'won');

        $this->assertStringEndsWith(PresaleWizard::getCustomerWizardPath($proposal->getID()), $url);
        $proposal->getFromDB($proposal->getID());
        $this->assertSame(PresaleProposal::STATUS_WON, (int) $proposal->fields['status']);
    }

    public function testAWonProposalCanNoLongerBeChanged(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient(), ['status' => PresaleProposal::STATUS_WON, 'version' => 1]);

        foreach (['lost', 'won', 'delete', 'reactivate'] as $action) {
            try {
                $this->runAction($proposal, $action);
                $this->fail("The action \"$action\" was allowed on a won proposal.");
            } catch (AccessDeniedHttpException) {
                // Expected
            }
        }
        $proposal->getFromDB($proposal->getID());
        $this->assertSame(PresaleProposal::STATUS_WON, (int) $proposal->fields['status']);
    }

    public function testLostArchivesTheProposalAndItsProspectThenReactivateRestoresThem(): void
    {
        $client   = $this->createPresaleClient();
        $proposal = $this->createProposal($client);

        $this->runAction($proposal, 'lost');

        $proposal->getFromDB($proposal->getID());
        $client->getFromDB($client->getID());
        $this->assertSame(PresaleProposal::STATUS_LOST, (int) $proposal->fields['status']);
        $this->assertNotEmpty($proposal->fields['archive_date']);
        // Nothing deleted, the prospect archived: all its proposals are lost
        $this->assertSame(1, (int) $client->fields['is_archived']);

        $this->runAction($proposal, 'reactivate');

        $proposal->getFromDB($proposal->getID());
        $client->getFromDB($client->getID());
        $this->assertSame(PresaleProposal::STATUS_DRAFT, (int) $proposal->fields['status']);
        $this->assertNull($proposal->fields['archive_date']);
        $this->assertSame(0, (int) $client->fields['is_archived']);
    }

    public function testAProspectStaysActiveWhileOneProposalIsOpen(): void
    {
        $client = $this->createPresaleClient();
        $lost   = $this->createProposal($client);
        $this->createProposal($client);

        $this->runAction($lost, 'lost');

        $client->getFromDB($client->getID());
        $this->assertSame(0, (int) $client->fields['is_archived']);
    }

    public function testOnlyALostProposalCanBeReactivated(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());

        $this->expectException(AccessDeniedHttpException::class);
        $this->runAction($proposal, 'reactivate');
    }

    // -------------------------------------------------------------------------
    // Duplication and deletion
    // -------------------------------------------------------------------------

    public function testDuplicateCopiesTheAnnexesAndLinesIntoANewDraft(): void
    {
        $client   = $this->createPresaleClient();
        $proposal = $this->createProposal($client, [
            'status'    => PresaleProposal::STATUS_LOST,
            'version'   => 3,
            'reference' => 'DUP' . mt_rand(100000, 999999),
            'summary'   => 'Summary of the source',
        ]);
        $this->addAnnex($proposal, [
            ['designation' => 'Audit', 'quantity' => 2, 'unit_price' => 750],
            ['designation' => 'Setup', 'quantity' => 3, 'unit_price' => 750],
        ]);
        $client->update(['id' => $client->getID(), 'is_archived' => 1]);

        $url = $this->runAction($proposal, 'duplicate');

        $this->assertMatchesRegularExpression('/[?&]id=(\d+)&step=' . PresaleWizard::STEP_ANNEXES . '$/', $url);
        preg_match('/[?&]id=(\d+)/', $url, $matches);
        $copy = new PresaleProposal();
        $this->assertTrue($copy->getFromDB((int) $matches[1]));
        $this->assertNotSame($proposal->getID(), $copy->getID());

        // A new draft, no reference, version nor document of its own
        $this->assertSame(PresaleProposal::STATUS_DRAFT, (int) $copy->fields['status']);
        $this->assertEmpty($copy->fields['reference']);
        $this->assertSame(0, (int) $copy->fields['version']);
        $this->assertSame(0, (int) $copy->fields['documents_id']);
        $this->assertSame('Summary of the source', $copy->fields['summary']);
        $this->assertSame($client->getID(), (int) $copy->fields['plugin_manageentities_presaleclients_id']);

        $annexes = (new PresaleProposal_Annex())->find(['plugin_manageentities_presaleproposals_id' => $copy->getID()]);
        $this->assertCount(1, $annexes);
        $lines = (new PresaleAnnexLine())->find(['plugin_manageentities_presaleproposals_annexes_id' => array_key_first($annexes)], ['rank']);
        $this->assertSame(['Audit', 'Setup'], array_values(array_column($lines, 'designation')));

        // The source is left as it was, the prospect active again
        $proposal->getFromDB($proposal->getID());
        $this->assertSame(PresaleProposal::STATUS_LOST, (int) $proposal->fields['status']);
        $this->assertCount(1, (new PresaleProposal_Annex())->find(['plugin_manageentities_presaleproposals_id' => $proposal->getID()]));
        $client->getFromDB($client->getID());
        $this->assertSame(0, (int) $client->fields['is_archived']);
    }

    public function testDeletePurgesTheProposalItsAnnexesAndTheProspectLeftWithout(): void
    {
        $client   = $this->createPresaleClient();
        $contact  = $this->createPresaleContact($client);
        $proposal = $this->createProposal($client);
        $annex    = $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 1, 'unit_price' => 750]]);

        $this->runAction($proposal, 'delete');

        $this->assertFalse((new PresaleProposal())->getFromDB($proposal->getID()));
        $this->assertFalse((new PresaleProposal_Annex())->getFromDB($annex->getID()));
        $this->assertSame(0, countElementsInTable(PresaleAnnexLine::getTable(), [
            'plugin_manageentities_presaleproposals_annexes_id' => $annex->getID(),
        ]));
        $this->assertFalse((new PresaleClient())->getFromDB($client->getID()));
        $this->assertFalse((new PresaleContact())->getFromDB($contact->getID()));
    }

    public function testDeleteKeepsAProspectThatHasOtherProposals(): void
    {
        $client = $this->createPresaleClient();
        $proposal = $this->createProposal($client);
        $this->createProposal($client);

        $this->runAction($proposal, 'delete');

        $this->assertTrue((new PresaleClient())->getFromDB($client->getID()));
    }

    public function testDeleteKeepsTheProspectOfACustomer(): void
    {
        $client   = $this->createPresaleClient(['converted_entities_id' => $this->getTestRootEntity(true)]);
        $proposal = $this->createProposal($client);

        $this->runAction($proposal, 'delete');

        $this->assertTrue((new PresaleClient())->getFromDB($client->getID()));
    }

    public function testPurgingAProspectPurgesItsProposalsInCascade(): void
    {
        $client   = $this->createPresaleClient();
        $contact  = $this->createPresaleContact($client);
        $proposal = $this->createProposal($client);
        $annex    = $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 1, 'unit_price' => 750]]);

        $this->assertTrue($client->delete(['id' => $client->getID()], true));

        $this->assertFalse((new PresaleContact())->getFromDB($contact->getID()));
        $this->assertFalse((new PresaleProposal())->getFromDB($proposal->getID()));
        $this->assertFalse((new PresaleProposal_Annex())->getFromDB($annex->getID()));
        $this->assertSame(0, countElementsInTable(PresaleAnnexLine::getTable(), [
            'plugin_manageentities_presaleproposals_annexes_id' => $annex->getID(),
        ]));
    }

    // -------------------------------------------------------------------------
    // Rights and entities
    // -------------------------------------------------------------------------

    public function testAProposalOfAnEntityOutOfReachCannotBeActedOn(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient(), [
            'entities_id' => getItemByTypeName(\Entity::class, '_test_child_2', true),
        ]);
        $this->setEntity('_test_child_1', false);

        foreach (['lost', 'duplicate', 'delete'] as $action) {
            try {
                $this->runAction($proposal, $action);
                $this->fail("The action \"$action\" was allowed out of the active entities.");
            } catch (AccessDeniedHttpException) {
                // Expected
            }
        }
    }

    public function testDeleteNeedsThePurgeRight(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());
        $_SESSION['glpiactiveprofile'][PresaleProposal::$rightname] = READ | CREATE | UPDATE;

        $this->expectException(AccessDeniedHttpException::class);
        $this->runAction($proposal, 'delete');
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient());

        $this->expectException(AccessDeniedHttpException::class);
        $this->runAction($proposal, 'anything');
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public function testNotificationsAreInstalledWithTheirTargets(): void
    {
        foreach ([
            NotificationTargetPresaleProposal::EVENT_GENERATED => 0,
            NotificationTargetPresaleProposal::EVENT_WON       => 1,
            NotificationTargetPresaleProposal::EVENT_LOST      => 0,
        ] as $event => $active) {
            $notifications = (new \Notification())->find(['itemtype' => PresaleProposal::class, 'event' => $event]);
            $this->assertCount(1, $notifications, "No notification for $event.");
            $notification = reset($notifications);
            // Only the won one is active by default
            $this->assertSame($active, (int) $notification['is_active'], $event);
            $targets = array_column(
                (new \NotificationTarget())->find(['notifications_id' => $notification['id']]),
                'items_id',
            );
            sort($targets);
            $this->assertSame(
                [NotificationTargetPresaleProposal::TARGET_SALES, NotificationTargetPresaleProposal::TARGET_PM],
                array_map('intval', $targets),
                $event,
            );
        }
    }

    public function testTargetsAreTheSalesRepresentativeTheProjectManagerAndTheAuthor(): void
    {
        $sales  = getItemByTypeName(\User::class, 'tech', true);
        $pm     = getItemByTypeName(\User::class, 'normal', true);
        $author = getItemByTypeName(\User::class, TU_USER, true);
        // A mail recipient needs an address
        foreach ([$sales, $pm] as $users_id) {
            $this->createItem(\UserEmail::class, [
                'users_id'   => $users_id,
                'email'      => 'presale' . $users_id . '@example.com',
                'is_default' => 1,
            ]);
        }
        $proposal = $this->createProposal($this->createPresaleClient(), [
            'sales_users_id' => $sales,
            'pm_users_id'    => $pm,
            'users_id'       => $author,
        ]);
        $target = new NotificationTargetPresaleProposal(
            $this->getTestRootEntity(true),
            NotificationTargetPresaleProposal::EVENT_WON,
            $proposal,
        );
        // As NotificationEvent::raiseEvent() does, for the mails
        $target->setMode(\Notification_NotificationTemplate::MODE_MAIL)
            ->setEvent(\NotificationEventMailing::class);

        $notifications = (new \Notification())->find([
            'itemtype' => PresaleProposal::class,
            'event'    => NotificationTargetPresaleProposal::EVENT_WON,
        ]);
        $notifications_id = (int) array_key_first($notifications);

        $expected = [
            NotificationTargetPresaleProposal::TARGET_SALES  => $sales,
            NotificationTargetPresaleProposal::TARGET_PM     => $pm,
            NotificationTargetPresaleProposal::TARGET_AUTHOR => $author,
        ];
        foreach ($expected as $type => $users_id) {
            $target->target = [];
            $target->addForTarget([
                'type'             => \Notification::USER_TYPE,
                'items_id'         => $type,
                'notifications_id' => $notifications_id,
            ]);
            $this->assertSame(
                [$users_id],
                array_values(array_map(static fn($r) => (int) $r['users_id'], $target->getTargets())),
                "Target $type",
            );
        }
    }

    public function testTemplateDataOfAWonProposal(): void
    {
        $level    = $this->createSubscriptionLevel();
        $client   = $this->createPresaleClient(['name' => 'Fondation Test']);
        $proposal = $this->createProposal($client, [
            'reference' => 'FON' . mt_rand(100000, 999999),
            'total_ht'  => 6300,
            'total_ttc' => 7560,
            'status'    => PresaleProposal::STATUS_WON,
        ]);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 6300]], $level);

        $target = new NotificationTargetPresaleProposal(
            $this->getTestRootEntity(true),
            NotificationTargetPresaleProposal::EVENT_WON,
            $proposal,
        );
        $target->addDataForTemplate(NotificationTargetPresaleProposal::EVENT_WON);

        $this->assertSame('Fondation Test', $target->data['##presale.client##']);
        $this->assertSame($proposal->fields['reference'], $target->data['##presale.reference##']);
        $this->assertSame(__('Yes'), $target->data['##presale.subscription##']);
        $this->assertStringEndsWith(
            PresaleWizard::getCustomerWizardPath($proposal->getID()),
            $target->data['##presale.customer.url##'],
        );
        $this->assertStringContainsString('6', $target->data['##presale.total_ht##']);
    }

    public function testTheHtmlOfTheTemplatesIsEscapedOnce(): void
    {
        global $DB;

        $won = $DB->request([
            'SELECT'     => 'glpi_notificationtemplatetranslations.content_html',
            'FROM'       => 'glpi_notificationtemplatetranslations',
            'INNER JOIN' => [
                'glpi_notificationtemplates' => ['ON' => [
                    'glpi_notificationtemplates'              => 'id',
                    'glpi_notificationtemplatetranslations'   => 'notificationtemplates_id',
                ]],
            ],
            'WHERE'      => [
                'glpi_notificationtemplates.itemtype' => PresaleProposal::class,
                'glpi_notificationtemplates.name'     => 'Pre-sales - ' . NotificationTargetPresaleProposal::EVENT_WON,
            ],
        ])->current();

        $this->assertNotNull($won);
        // "s'il" is stored as GLPI stores HTML (escaped once): s&#039;il, never s&amp;#039;il
        $this->assertStringNotContainsString('&amp;#039;', $won['content_html']);
        $this->assertStringContainsString('&lt;p&gt;', $won['content_html']);
    }

    // -------------------------------------------------------------------------
    // Customer wizard prefilled from a won proposal
    // -------------------------------------------------------------------------

    public function testOnlyAWonProposalPrefillsTheCustomerWizard(): void
    {
        $client = $this->createPresaleClient();

        $this->assertNull(PresaleConversion::getWonProposal($this->createProposal($client)->getID()));
        $won = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON]);
        $this->assertSame($won->getID(), PresaleConversion::getWonProposal($won->getID())?->getID());
        $this->assertNull(PresaleConversion::getWonProposal(0));
    }

    public function testPrefillOfANewCustomer(): void
    {
        $client = $this->createPresaleClient([
            'name'        => 'Prospect Prefill',
            'phonenumber' => '02 40 00 00 00',
            'website'     => 'https://example.com',
        ]);
        $second = $this->createPresaleContact($client, ['name' => 'MARTIN', 'firstname' => 'Paul', 'is_default' => 0]);
        $main   = $this->createPresaleContact($client);
        $proposal = $this->createProposal($client, [
            'status'        => PresaleProposal::STATUS_WON,
            'reference'     => 'PRE' . mt_rand(100000, 999999),
            'proposal_type' => 'Expertise & Souscription',
            'proposal_date' => '2026-11-01',
        ]);
        $level = $this->createSubscriptionLevel();
        $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 2, 'unit_price' => 750]], 0, 1);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $level, 2);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        $this->assertSame($proposal->getID(), $session[PresaleConversion::SESSION_PROPOSAL]);
        $this->assertSame('new_entity', $session['wizard_mode']);
        $this->assertSame(1, $session['step']);
        $this->assertSame('Prospect Prefill', $session['entity_data']['name']);
        $this->assertSame('Nantes', $session['entity_data']['town']);
        $this->assertSame($this->getTestRootEntity(true), $session['entity_data']['entities_id']);

        // The main contact first, as manager, each one linked back to its prospect contact
        $this->assertSame([1, 2], array_keys($session['contacts_data']));
        $this->assertSame($main->getID(), $session['contacts_data'][1]['presale_contacts_id']);
        $this->assertSame(1, $session['contacts_data'][1]['is_manager']);
        $this->assertSame('DSI', $session['contacts_data'][1]['comment']);
        $this->assertSame($second->getID(), $session['contacts_data'][2]['presale_contacts_id']);
        $this->assertSame(0, $session['contacts_data'][2]['is_manager']);

        // The subscription of the subscription annex, a year from the date of the proposal
        $this->assertSame($level, $session['subscription_data']['plugin_manageentities_subscriptionlevels_id']);
        $this->assertSame('2026-11-01', $session['subscription_data']['begin_date']);
        $this->assertSame('2027-10-31', $session['subscription_data']['end_date']);
        // A regular annex: a contract to make
        $this->assertArrayNotHasKey('subscription_only', $session);

        $this->assertSame('Expertise & Souscription', $session['contract_prefill']['name']);
        $this->assertSame($proposal->fields['reference'], $session['contract_prefill']['num']);
        $this->assertSame('2026-11-01', $session['contract_prefill']['begin_date']);
    }

    public function testPrefillOfASubscriptionOnlyProposalSkipsTheContract(): void
    {
        $client   = $this->createPresaleClient();
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON]);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel());

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        $this->assertFalse(PresaleConversion::hasRegularAnnex($proposal));
        $this->assertSame(1, $session['subscription_only']);
    }

    public function testPrefillOfAnExistingCustomerKeepsItsEntity(): void
    {
        $entities_id = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $client      = $this->createPresaleClient(['converted_entities_id' => $entities_id]);
        $this->createPresaleContact($client);
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON]);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        $this->assertSame('existing_entity', $session['wizard_mode']);
        $this->assertSame($entities_id, $session['entities_id']);
        // Its contacts already exist: none to create
        $this->assertSame([], $session['contacts_data'] ?? []);
    }

    public function testOnlyAContactOfTheProspectIsLinkedBack(): void
    {
        $client   = $this->createPresaleClient();
        $contact  = $this->createPresaleContact($client);
        $other    = $this->createPresaleContact($this->createPresaleClient());
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON]);
        $session  = [PresaleConversion::SESSION_PROPOSAL => $proposal->getID()];

        $this->assertTrue(PresaleConversion::isContactOf($session, $contact->getID()));
        // A contact id forged in the post, of another prospect
        $this->assertFalse(PresaleConversion::isContactOf($session, $other->getID()));
        $this->assertFalse(PresaleConversion::isContactOf([], $contact->getID()));
    }
}
