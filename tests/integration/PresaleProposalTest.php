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
use GlpiPlugin\Manageentities\EditorSubscription;
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

    public function testReferenceIsThePrefixAndTheDay(): void
    {
        $date = new \DateTimeImmutable('2031-07-15');

        $this->assertSame('SOC310715', PresaleCounter::next('Société Test', $date));
        // Same prefix the same day: same reference, the version of the document differs
        $this->assertSame('SOC310715', PresaleCounter::next('Socotec', $date));
        $this->assertSame('APR310715', PresaleCounter::next('APRR', $date));
        $this->assertSame('SOC310716', PresaleCounter::next('Société Test', new \DateTimeImmutable('2031-07-16')));
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

    public function testAWonProposalLeftBeforeTheEndOfTheWizardStaysToConvert(): void
    {
        $client   = $this->createPresaleClient();
        $proposal = $this->createProposal($client, [
            'status'    => PresaleProposal::STATUS_GENERATED,
            'version'   => 1,
            'reference' => 'TCV' . mt_rand(100000, 999999),
        ]);
        $this->runAction($proposal, 'won');
        $proposal->getFromDB($proposal->getID());

        // Won but the wizard was left: no longer in progress, listed with the customer to create
        $criteria = [PresaleProposal::getTable() . '.id' => $proposal->getID()];
        $this->assertSame([], PresaleWizard::getOpenProposals($criteria));
        $to_convert = PresaleConversion::getProposalsToConvert($criteria);
        $this->assertCount(1, $to_convert);
        $this->assertSame(PresaleWizard::getCustomerWizardPath($proposal->getID()), $to_convert[0]['customer_url']);
        $this->assertFalse(PresaleConversion::isConverted($proposal));

        // The tab of the proposals offers to start the customer wizard again
        $loader = \Glpi\Application\View\TemplateRenderer::getInstance()->getEnvironment()->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array('manageentities', $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__, 2) . '/templates', 'manageentities');
        }
        ob_start();
        try {
            \GlpiPlugin\Manageentities\Entity::showOpenProposals([PresaleWizard::getTargetEntity()]);
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertStringContainsString('id="me-proposals-to-convert"', $html);
        $this->assertStringContainsString('from_proposal=' . $proposal->getID(), $html);
    }

    public function testASubscriptionOnlyConversionIsRecordedWithoutContract(): void
    {
        $client   = $this->createPresaleClient();
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON, 'documents_id' => 0]);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel());
        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        // End of the wizard: a subscription only, no contract
        $entities_id = getItemByTypeName(\Entity::class, '_test_child_1', true);
        PresaleConversion::afterCommit($session, $entities_id, [], 0);
        $this->hasSessionMessages(INFO, [sprintf(
            'The customer has been created from the proposal %s.',
            (string) ($proposal->fields['reference'] ?: $proposal->fields['name']),
        )]);

        $proposal->getFromDB($proposal->getID());
        $this->assertNotEmpty($proposal->fields['conversion_date']);
        $this->assertTrue(PresaleConversion::isConverted($proposal));
        $this->assertSame([], PresaleConversion::getProposalsToConvert([PresaleProposal::getTable() . '.id' => $proposal->getID()]));
    }

    public function testConversionDatesOfTheProposalsConvertedBeforeTheColumn(): void
    {
        global $DB;

        $converted = $this->createProposal(
            $this->createPresaleClient(['converted_entities_id' => getItemByTypeName(\Entity::class, '_test_child_1', true)]),
            ['status' => PresaleProposal::STATUS_WON, 'documents_id' => 0],
        );
        $waiting = $this->createProposal($this->createPresaleClient(), ['status' => PresaleProposal::STATUS_WON, 'documents_id' => 0]);
        // Generated proposal for an existing customer: its prospect has the entity from the
        // start, only its document linked to a contract tells it was converted
        $document = new \Document();
        $documents_id = (int) $document->add(['name' => 'Proposal', 'entities_id' => $this->getTestRootEntity(true)]);
        $existing = $this->createProposal(
            $this->createPresaleClient(['converted_entities_id' => getItemByTypeName(\Entity::class, '_test_child_1', true)]),
            ['status' => PresaleProposal::STATUS_WON, 'documents_id' => $documents_id],
        );
        $DB->update(
            PresaleProposal::getTable(),
            ['conversion_date' => null],
            ['id' => [$converted->getID(), $waiting->getID(), $existing->getID()]],
        );

        PresaleConversion::initConversionDates();

        $converted->getFromDB($converted->getID());
        $waiting->getFromDB($waiting->getID());
        $existing->getFromDB($existing->getID());
        $this->assertTrue(PresaleConversion::isConverted($converted));
        $this->assertFalse(PresaleConversion::isConverted($waiting));
        $this->assertFalse(PresaleConversion::isConverted($existing));
    }

    public function testTheContractOfAWonProposalGetsItsDocumentAndKeepsItsFieldsOverATemplate(): void
    {
        $document     = new \Document();
        $documents_id = (int) $document->add(['name' => 'Proposal PDF', 'entities_id' => $this->getTestRootEntity(true)]);
        $client       = $this->createPresaleClient();
        $proposal     = $this->createProposal($client, [
            'status'        => PresaleProposal::STATUS_WON,
            'reference'     => 'TPL' . mt_rand(100000, 999999),
            'proposal_type' => 'Expertise & Souscription',
            'proposal_date' => '2026-11-01',
            'documents_id'  => $documents_id,
        ]);
        $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 2, 'unit_price' => 750]], 0, 1);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());
        (new \ReflectionMethod(WizardController::class, 'saveSession'))->invoke(null, $session);

        // The contract step shows the document of the proposal, linked by default
        $vars = (new \ReflectionMethod(WizardController::class, 'buildContractVars'))
            ->invoke(null, WizardController::getSession(), 1);
        $this->assertIsArray($vars['proposal_doc']);
        $this->assertSame('Proposal PDF', $vars['proposal_doc']['name']);
        // The prefill is consumed by this first display, the fields of the proposal are kept
        $this->assertSame([], WizardController::getSession()['contract_prefill'] ?? []);

        // Management step: "the contract is imported in GLPI" checked, the proposal goes with it
        $management = (new \ReflectionMethod(WizardController::class, 'buildManagementVars'))
            ->invoke(null, WizardController::getSession(), 1, true, false);
        $this->assertTrue($management['contract_added']);

        // A contract template loaded afterwards: it fills the empty fields only
        WizardController::prefillFromTemplate([
            'name'     => 'Template contract',
            'num'      => 'TPL-NUM',
            'duration' => 36,
        ]);
        // End of the wizard: the document of the proposal goes with the new contract
        $entities_id  = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $contracts_id = (int) $this->createItem(\Contract::class, ['name' => 'From proposal', 'entities_id' => $entities_id])->getID();
        PresaleConversion::afterCommit(WizardController::getSession(), $entities_id, [], $contracts_id);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        $this->assertSame(1, countElementsInTable(\Document_Item::getTable(), [
            'documents_id' => $documents_id,
            'itemtype'     => \Contract::class,
            'items_id'     => $contracts_id,
        ]));

        $prefill = WizardController::getSession()['contract_prefill'];
        $this->assertSame('Expertise & Souscription', $prefill['name']);
        $this->assertSame($proposal->fields['reference'], $prefill['num']);
        $this->assertSame('2026-11-01', $prefill['begin_date']);
        $this->assertSame(36, $prefill['duration']);
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
        // No subscription annex: no subscription step
        $this->assertFalse(WizardController::hasSubscriptionStep($session));
    }

    public function testRegularAnnexesBecomePeriodsOfContractAtASingleRate(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient(), [
            'status'        => PresaleProposal::STATUS_WON,
            'proposal_date' => '2026-11-01',
        ]);
        $this->addAnnex($proposal, [
            ['designation' => 'Audit', 'quantity' => 2, 'unit_price' => 700],
            ['designation' => 'Atelier', 'quantity' => 2, 'unit_price' => 800],
        ], 0, 1);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel(), 2);
        $this->addAnnex($proposal, [['designation' => 'Formation', 'quantity' => 3, 'unit_price' => 650]], 0, 3);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        // One period per regular annex, the subscription annex left out
        $this->assertSame([1, 2], array_keys($session['interventions_data']));
        $first = $session['interventions_data'][1];
        $this->assertSame('Expertise GLPI', $first['fields']['name']);
        $this->assertSame(4.0, $first['fields']['nbday']);
        $this->assertSame('2026-11-01', $first['fields']['begin_date']);
        $this->assertSame('2027-11-01', $first['fields']['end_date']);
        // A single rate: the average of the lines
        $this->assertCount(1, $first['criprices']);
        $this->assertSame(750.0, $first['criprices'][0]['price']);
        $this->assertSame(650.0, $session['interventions_data'][2]['criprices'][0]['price']);
    }

    public function testSeveralSubscriptionAnnexesAreOfferedToChooseFrom(): void
    {
        $proposal = $this->createProposal($this->createPresaleClient(), ['status' => PresaleProposal::STATUS_WON]);
        $on_premise = $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel(), 1);
        $cloud      = $this->addAnnex($proposal, [['designation' => 'Cloud', 'quantity' => 1, 'unit_price' => 90]], $this->createSubscriptionLevel(), 2);
        $this->updateItem(PresaleProposal_Annex::class, $cloud->getID(), ['cloud_client' => 1]);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        $this->assertSame([$on_premise->getID(), $cloud->getID()], array_keys($session['subscription_choices']));
        // The first one by default, the other one once chosen
        $this->assertSame(0, $session['subscription_data']['cloud_client']);
        $_SESSION['manageentities_wizard'] = ['default' => $session];
        $result = WizardController::saveSubscriptionAndReturn(['change_choice' => 1, 'subscription_choice' => $cloud->getID()]);
        $this->assertTrue($result['success']);
        $this->assertSame(1, WizardController::getSession()['subscription_data']['cloud_client']);
    }

    public function testTheSubscriptionOfAnExistingCustomerIsRenewed(): void
    {
        global $DB;

        $entities_id = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $DB->delete(EditorSubscription::getTable(), ['entities_id' => $entities_id]);
        $subscription = $this->createItem(EditorSubscription::class, [
            'entities_id'         => $entities_id,
            'name'                => 'Référence éditeur',
            'customer_account_id' => 'ACC-42',
            'begin_date'          => '2025-12-01 00:00:00',
            'end_date'            => '2026-11-30 00:00:00',
        ]);
        $client   = $this->createPresaleClient(['converted_entities_id' => $entities_id]);
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON, 'proposal_date' => '2026-11-01']);
        $level    = $this->createSubscriptionLevel();
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $level);

        $session = PresaleConversion::prefill($proposal, WizardController::buildDefaultSession());

        $this->assertTrue(WizardController::hasSubscriptionStep($session));
        $this->assertSame(1, $session['subscription_only']);
        // Renewed from the end of the running subscription, its name and account kept
        $this->assertSame('2026-12-01', $session['subscription_data']['begin_date']);
        $this->assertSame('2027-11-30', $session['subscription_data']['end_date']);
        $this->assertSame('ACC-42', $session['subscription_data']['customer_account_id']);

        // Subscription only: the wizard writes the renewed subscription, no contract
        $session['skip_contract'] = 1;
        $_SESSION['manageentities_wizard'] = ['default' => $session];
        $result = WizardController::commitWizardAndReturn();
        $this->assertTrue($result['success'], json_encode($result));

        $this->assertSame(1, countElementsInTable(EditorSubscription::getTable(), ['entities_id' => $entities_id]));
        $subscription->getFromDB($subscription->getID());
        $this->assertSame('2027-11-30 00:00:00', $subscription->fields['end_date']);
        $this->assertSame($level, (int) $subscription->fields['plugin_manageentities_subscriptionlevels_id']);
    }

    public function testAnExistingCustomerGetsItsSubscriptionAndAContractWithItsPeriods(): void
    {
        global $DB;

        $entities_id = getItemByTypeName(\Entity::class, '_test_child_2', true);
        $DB->delete(EditorSubscription::getTable(), ['entities_id' => $entities_id]);
        $state = $this->createItem(\GlpiPlugin\Manageentities\ContractState::class, ['name' => 'State ' . $this->getUniqueString()]);
        $DB->update(\GlpiPlugin\Manageentities\Config::getTable(), ['wizard_contractstate_id' => $state->getID()], ['id' => 1]);
        $this->resetConfigCache();

        $client   = $this->createPresaleClient(['converted_entities_id' => $entities_id]);
        $proposal = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON, 'proposal_date' => '2026-11-01']);
        $this->addAnnex($proposal, [['designation' => 'Audit', 'quantity' => 5, 'unit_price' => 750]], 0, 1);
        $this->addAnnex($proposal, [['designation' => 'Licence', 'quantity' => 1, 'unit_price' => 900]], $this->createSubscriptionLevel(), 2);

        $_SESSION['manageentities_wizard'] = ['default' => PresaleConversion::prefill($proposal, WizardController::buildDefaultSession())];
        $name = 'CTR ' . $this->getUniqueString();
        $r = WizardController::saveSubscriptionAndReturn(WizardController::getSession()['subscription_data']);
        $this->assertTrue($r['success'], json_encode($r));
        $type  = $this->createItem(\ContractType::class, ['name' => 'Type ' . $this->getUniqueString()]);
        $cstate = $this->createItem(\State::class, ['name' => 'Status ' . $this->getUniqueString(), 'entities_id' => 0, 'is_recursive' => 1]);
        $r = WizardController::saveContractAndReturn([
            'name'             => $name,
            'num'              => 'NUM-' . $this->getUniqueString(),
            'begin_date'       => '2026-11-01',
            'duration'         => 12,
            'contracttypes_id' => $type->getID(),
            'states_id'        => $cstate->getID(),
        ]);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertTrue(WizardController::saveManagementTypeAndReturn(['date_signature' => '2026-11-01'])['success']);

        $result = WizardController::commitWizardAndReturn();
        $this->assertTrue($result['success'], json_encode($result));

        // Subscription created on the customer, contract with the period of the regular annex
        $this->assertSame(1, countElementsInTable(EditorSubscription::getTable(), ['entities_id' => $entities_id]));
        $contracts = (new \Contract())->find(['name' => $name, 'entities_id' => $entities_id]);
        $this->assertCount(1, $contracts);
        $periods = (new \GlpiPlugin\Manageentities\ContractDay())->find(['contracts_id' => array_key_first($contracts)]);
        $this->assertCount(1, $periods);
        $period = reset($periods);
        $this->assertSame('Expertise GLPI', $period['name']);
        $this->assertEquals(5, $period['nbday']);
        $prices = (new \GlpiPlugin\Manageentities\CriPrice())->find(['plugin_manageentities_contractdays_id' => $period['id']]);
        $this->assertEquals(750, reset($prices)['price']);
    }

    public function testACustomerOutOfReachIsNeverCreatedAgain(): void
    {
        $entities_id = getItemByTypeName(\Entity::class, '_test_child_1', true);
        $client      = $this->createPresaleClient(['converted_entities_id' => $entities_id]);
        $proposal    = $this->createProposal($client, ['status' => PresaleProposal::STATUS_WON]);

        // Only the other child is active: the customer entity is out of reach
        $this->setEntity('_test_child_2', false);

        $this->assertSame([], PresaleConversion::prefill($proposal, WizardController::buildDefaultSession()));
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
