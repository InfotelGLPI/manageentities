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

use Glpi\Exception\RedirectException;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\PresaleAnnexLine;
use GlpiPlugin\Manageentities\PresaleAnnexType;
use GlpiPlugin\Manageentities\PresaleClient;
use GlpiPlugin\Manageentities\PresaleContact;
use GlpiPlugin\Manageentities\PresaleProposal;
use GlpiPlugin\Manageentities\PresaleProposal_Annex;
use GlpiPlugin\Manageentities\PresaleWizard;
use GlpiPlugin\Manageentities\Profile;
use GlpiPlugin\Manageentities\SubscriptionLevel;

/**
 * Fixtures of the pre-sales tests: section enabled on the test root entity, the rights of the
 * section, a prospect with a proposal and its annexes. Everything is rolled back by DbTestCase.
 */
trait PresaleTestHelpers
{
    /**
     * Pre-sales enabled, prospects created in the test root entity, the logged in profile
     * holding the rights of the section
     */
    protected function setUpPresale(): void
    {
        global $DB;

        $this->login();
        $DB->update(Config::getTable(), [
            'use_presale'                => 1,
            'use_editorsubscriptions'    => 1,
            'wizard_default_entities_id' => $this->getTestRootEntity(true),
        ], ['id' => 1]);
        $this->resetConfigCache();

        Profile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
        Profile::initProfile();
        // No mail is ever queued by the tests
        $DB->update('glpi_configs', ['value' => 0], ['context' => 'core', 'name' => 'use_notifications']);
        \Config::loadLegacyConfiguration();
    }

    /** Config::getInstance() keeps the row read first */
    protected function resetConfigCache(): void
    {
        $property = new \ReflectionProperty(Config::class, 'instance');
        $property->setValue(null, null);
    }

    protected function createPresaleClient(array $input = []): PresaleClient
    {
        /** @var PresaleClient */
        return $this->createItem(PresaleClient::class, $input + [
            'name'        => 'Prospect ' . $this->getUniqueString(),
            'address'     => '1 rue du test',
            'postcode'    => '44000',
            'town'        => 'Nantes',
            'email'       => 'contact@example.com',
            'entities_id' => $this->getTestRootEntity(true),
            'users_id'    => \Session::getLoginUserID(),
        ]);
    }

    protected function createPresaleContact(PresaleClient $client, array $input = []): PresaleContact
    {
        /** @var PresaleContact */
        return $this->createItem(PresaleContact::class, $input + [
            'plugin_manageentities_presaleclients_id' => $client->getID(),
            'name'                                    => 'DUPONT',
            'firstname'                               => 'Marie',
            'role'                                    => 'DSI',
            'email'                                   => 'marie.dupont@example.com',
            'is_default'                              => 1,
        ]);
    }

    protected function createProposal(PresaleClient $client, array $input = []): PresaleProposal
    {
        /** @var PresaleProposal */
        return $this->createItem(PresaleProposal::class, $input + [
            'plugin_manageentities_presaleclients_id' => $client->getID(),
            'entities_id'                             => $this->getTestRootEntity(true),
            'name'                                    => $client->fields['name'] . ' - Expertise',
            'status'                                  => PresaleProposal::STATUS_DRAFT,
            'step'                                    => PresaleWizard::STEP_GENERAL,
            'proposal_type'                           => 'Expertise',
            'proposal_date'                           => '2026-10-01',
            'sales_users_id'                          => \Session::getLoginUserID(),
            'sales_name'                              => 'Sales test',
            'users_id'                                => \Session::getLoginUserID(),
        ]);
    }

    /**
     * Annex of the proposal with its lines; a subscription annex when $levels_id is set
     *
     * @param list<array{designation: string, quantity: float, unit_price: float}> $lines
     */
    protected function addAnnex(PresaleProposal $proposal, array $lines, int $levels_id = 0, int $rank = 1): PresaleProposal_Annex
    {
        $model = $this->createItem(PresaleAnnexType::class, [
            'name'            => 'Model ' . $this->getUniqueString(),
            'entities_id'     => $this->getTestRootEntity(true),
            'is_active'       => 1,
            'is_subscription' => $levels_id > 0 ? 1 : 0,
            'plugin_manageentities_subscriptionlevels_id' => $levels_id,
        ]);
        /** @var PresaleProposal_Annex $annex */
        $annex = $this->createItem(PresaleProposal_Annex::class, [
            'plugin_manageentities_presaleproposals_id'   => $proposal->getID(),
            'plugin_manageentities_presaleannextypes_id'  => $model->getID(),
            'rank'                                        => $rank,
            'title'                                       => $levels_id > 0 ? 'Souscription éditeur' : 'Expertise GLPI',
            'vat_rate'                                    => 20,
            'plugin_manageentities_subscriptionlevels_id' => $levels_id,
        ]);
        foreach (array_values($lines) as $idx => $line) {
            $this->createItem(PresaleAnnexLine::class, $line + [
                'plugin_manageentities_presaleproposals_annexes_id' => $annex->getID(),
                'rank'                                              => $idx + 1,
            ]);
        }

        return $annex;
    }

    protected function createSubscriptionLevel(): int
    {
        return $this->createItem(SubscriptionLevel::class, [
            'name'              => 'Level ' . $this->getUniqueString(),
            'subscription_type' => SubscriptionLevel::TYPE_ON_PREMISE,
        ])->getID();
    }

    /**
     * Runs an action of the proposal form and returns where it redirects to
     */
    protected function runAction(PresaleProposal $proposal, string $action): string
    {
        try {
            PresaleWizard::handleAction(['id' => $proposal->getID(), 'presale_action' => $action]);
        } catch (RedirectException $e) {
            return $e->getResponse()->getTargetUrl();
        }
        $this->fail('The action did not redirect.');
    }
}
