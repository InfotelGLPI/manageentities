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

namespace GlpiPlugin\Manageentities;

use Dropdown;
use Glpi\DBAL\QuerySubQuery;
use Html;

/**
 * Renewals to propose, listed in the "Proposals in progress" tab of the clients management:
 * - the expired publisher subscriptions (end date before today, as the ExpiredSubscriptions alert);
 * - the contracts running out of remaining days, the very list of the LowRemainingDaysContracts
 *   mail (Contract::getLowRemainingDaysContracts()).
 *
 * A proposal started from a row keeps its source (plugin_manageentities_editorsubscriptions_id or
 * contracts_id): the row leaves the list while that proposal is in progress or won.
 */
class PresaleRenewal
{
    /**
     * Expired subscriptions of the customers of the perimeter, with no proposal in progress or won
     *
     * @param array<int|string> $entities active entities
     *
     * @return list<array{id: int, entities_id: int, entity: string, name: string, cloud_client: bool,
     *                    level: string, end_date: string, new_url: string}>
     */
    public static function getExpiredSubscriptions(array $entities): array
    {
        global $DB;

        if (!Config::useEditorSubscriptions() || !EditorSubscription::canView()) {
            return [];
        }

        $subscriptions = EditorSubscription::getTable();
        $iterator      = $DB->request([
            'SELECT'    => [
                $subscriptions . '.*',
                'glpi_entities.completename AS entity',
            ],
            'FROM'      => $subscriptions,
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'FKEY' => [$subscriptions => 'entities_id', 'glpi_entities' => 'id'],
                ],
            ],
            'WHERE'     => [
                $subscriptions . '.entities_id' => self::getCustomerEntities($entities),
                ['NOT' => [$subscriptions . '.end_date' => null]],
                [$subscriptions . '.end_date' => ['<', date('Y-m-d') . ' 00:00:00']],
                ['NOT' => [$subscriptions . '.id' => self::getFollowedSources('plugin_manageentities_editorsubscriptions_id')]],
            ],
            'ORDER'     => [$subscriptions . '.end_date ASC', 'glpi_entities.completename ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $data) {
            $levels_id = (int) $data['plugin_manageentities_subscriptionlevels_id'];
            $rows[]    = [
                'id'           => (int) $data['id'],
                'entities_id'  => (int) $data['entities_id'],
                'entity'       => (string) $data['entity'],
                'name'         => (string) $data['name'],
                'cloud_client' => (bool) $data['cloud_client'],
                'level'        => $levels_id > 0 ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), $levels_id) : '',
                'end_date'     => substr((string) $data['end_date'], 0, 10),
                'new_url'      => self::getNewProposalUrl((int) $data['entities_id'], ['editorsubscriptions_id' => (int) $data['id']]),
            ];
        }

        return $rows;
    }

    /**
     * Last proposal started from each subscription, whatever its status; none without the right
     *
     * @param list<int> $subscriptions_ids
     *
     * @return array<int, array{reference: string, status: string, url: string}> by subscription
     */
    public static function getSubscriptionProposals(array $subscriptions_ids): array
    {
        if ($subscriptions_ids === [] || !PresaleProposal::canView()) {
            return [];
        }

        $statuses  = PresaleProposal::getStatuses();
        $proposals = [];
        foreach ((new PresaleProposal())->find([
            'plugin_manageentities_editorsubscriptions_id' => $subscriptions_ids,
        ], ['id DESC']) as $data) {
            $subscriptions_id = (int) $data['plugin_manageentities_editorsubscriptions_id'];
            $proposals[$subscriptions_id] ??= [
                // No reference before the first generation
                'reference' => (string) ($data['reference'] ?: '#' . $data['id']),
                'status'    => $statuses[(int) $data['status']] ?? '',
                'url'       => PresaleProposal::getFormURLWithID((int) $data['id']),
            ];
        }

        return $proposals;
    }

    /**
     * Contracts of the customers of the perimeter running out of remaining days, exactly the
     * list of the "Contracts running out of remaining days" mail
     * (Contract::getLowRemainingDaysContracts()), with no proposal in progress or won
     *
     * @param array<int|string> $entities active entities
     *
     * @return list<array{id: int, entities_id: int, entity: string, name: string, num: string,
     *                    begin_date: string, remaining_days: float, prestations: string,
     *                    contract_url: string, new_url: string}>
     */
    public static function getExpiringContracts(array $entities): array
    {
        if (!\Contract::canView()) {
            return [];
        }
        $customers = array_filter(self::getCustomerEntities($entities), static fn(int $id): bool => $id > 0);
        if ($customers === []) {
            return [];
        }

        $followed = [];
        foreach ((new PresaleProposal())->find([
            'contracts_id' => ['>', 0],
            'status'       => [...PresaleProposal::getOpenStatuses(), PresaleProposal::STATUS_WON],
        ]) as $proposal) {
            $followed[(int) $proposal['contracts_id']] = true;
        }

        $rows     = [];
        $contract = new \Contract();
        foreach (Contract::getLowRemainingDaysContracts($customers) as $data) {
            $id = (int) $data['contracts_id'];
            if (isset($followed[$id]) || !$contract->can($id, READ)) {
                continue;
            }
            $rows[] = [
                'id'             => $id,
                'entities_id'    => (int) $data['entities_id'],
                'entity'         => (string) $data['entity_completename'],
                'name'           => (string) $data['name'],
                'num'            => (string) $data['num'],
                'begin_date'     => substr((string) $data['begin_date'], 0, 10),
                'remaining_days' => (float) $data['remaining_days'],
                'prestations'    => (string) $data['prestations'],
                'contract_url'   => \Contract::getFormURLWithID($id),
                'new_url'        => self::getNewProposalUrl((int) $data['entities_id'], ['contracts_id' => $id]),
            ];
        }

        return $rows;
    }

    /**
     * Source of a new proposal of a customer, checked: a subscription or a contract still to
     * renew, of that very customer. Empty when none is given or when it is not valid.
     *
     * @param array $input editorsubscriptions_id or contracts_id ($_GET of step 1, or its post)
     *
     * @return array{plugin_manageentities_editorsubscriptions_id?: int, contracts_id?: int}
     */
    public static function getSourceInput(array $input, int $entities_id): array
    {
        if ($entities_id <= 0) {
            return [];
        }

        $subscriptions_id = (int) ($input['editorsubscriptions_id'] ?? 0);
        if ($subscriptions_id > 0) {
            $ids = array_column(self::getExpiredSubscriptions([$entities_id]), 'id');
            return in_array($subscriptions_id, $ids, true)
                ? ['plugin_manageentities_editorsubscriptions_id' => $subscriptions_id]
                : [];
        }

        $contracts_id = (int) ($input['contracts_id'] ?? 0);
        if ($contracts_id > 0) {
            $ids = array_column(self::getExpiringContracts([$entities_id]), 'id');
            return in_array($contracts_id, $ids, true) ? ['contracts_id' => $contracts_id] : [];
        }

        return [];
    }

    /**
     * Label of the source of a proposal, empty when none
     *
     * @param array $fields fields of the proposal, or the output of getSourceInput()
     */
    public static function getSourceLabel(array $fields): string
    {
        $subscriptions_id = (int) ($fields['plugin_manageentities_editorsubscriptions_id'] ?? 0);
        $subscription     = new EditorSubscription();
        if ($subscriptions_id > 0 && EditorSubscription::canView() && $subscription->getFromDB($subscriptions_id)) {
            return sprintf(
                __('Renewal of the publisher subscription ended on %s', 'manageentities'),
                Html::convDate((string) $subscription->fields['end_date']),
            );
        }

        $contracts_id = (int) ($fields['contracts_id'] ?? 0);
        $contract     = new \Contract();
        if ($contracts_id > 0 && $contract->getFromDB($contracts_id) && $contract->can($contracts_id, READ)) {
            $name = (string) $contract->fields['name'];
            if ((string) $contract->fields['num'] !== '') {
                $name .= ' (' . $contract->fields['num'] . ')';
            }
            return sprintf(__('Renewal of the contract %s', 'manageentities'), $name);
        }

        return '';
    }

    /**
     * New proposal of the customer, its source kept by the wizard; empty without the right
     *
     * @param array<string, int> $source editorsubscriptions_id or contracts_id
     */
    private static function getNewProposalUrl(int $entities_id, array $source): string
    {
        if (!PresaleWizard::canUse() || !PresaleProposal::canCreate()) {
            return '';
        }

        return PresaleWizard::getFormUrl() . '?' . http_build_query([
            'step'        => PresaleWizard::STEP_CLIENT,
            'entities_id' => $entities_id,
        ] + $source);
    }

    /**
     * Customer entities of the perimeter: the children of the customers entity, the archived
     * ones excluded, as the customers of the wizard
     *
     * @param array<int|string> $entities active entities
     *
     * @return list<int> [-1] when none, so that the criterion matches nothing
     */
    private static function getCustomerEntities(array $entities): array
    {
        $parent = PresaleWizard::getTargetEntity();
        if ($parent <= 0) {
            return [-1];
        }
        $archive  = (int) (Config::getInstance()->fields['wizard_archive_entities_id'] ?? 0);
        $excluded = $archive > 0 ? getSonsOf('glpi_entities', $archive) : [];
        $active   = array_flip(array_map('intval', $entities));

        $customers = [];
        foreach ((new \Entity())->find(['entities_id' => $parent], ['name']) as $data) {
            $id = (int) $data['id'];
            if (isset($active[$id]) && !isset($excluded[$id])) {
                $customers[] = $id;
            }
        }

        return $customers ?: [-1];
    }

    /**
     * Sources already followed by a proposal in progress or won
     *
     * @param string $field plugin_manageentities_editorsubscriptions_id or contracts_id
     */
    private static function getFollowedSources(string $field): QuerySubQuery
    {
        return new QuerySubQuery([
            'SELECT' => $field,
            'FROM'   => PresaleProposal::getTable(),
            'WHERE'  => [
                $field   => ['>', 0],
                'status' => [...PresaleProposal::getOpenStatuses(), PresaleProposal::STATUS_WON],
            ],
        ]);
    }
}
