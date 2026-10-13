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

use Alert;
use Dropdown;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use Glpi\DBAL\QueryIdentifier;
use Glpi\DBAL\QuerySubQuery;
use Html;

/**
 * Renewals to propose, listed in the "Proposals in progress" tab of the clients management:
 * - the expired publisher subscriptions (end date before today, as the ExpiredSubscriptions alert);
 * - the contracts of the plugin about to end, on the criteria of the end and notice alerts of the
 *   core (Contract::cronContract()): end = begin + duration, notice = end - notice, reported
 *   send_contracts_alert_before_delay days ahead in the entities where these alerts are enabled.
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
     * Contracts of the plugin, of the customers of the perimeter, reported by the end or notice
     * alert of the core, with no proposal in progress or won
     *
     * @param array<int|string> $entities active entities
     *
     * @return list<array{id: int, entities_id: int, entity: string, name: string, num: string,
     *                    end_date: string, notice_date: string, expired: bool, contract_url: string,
     *                    new_url: string}>
     */
    public static function getExpiringContracts(array $entities): array
    {
        global $DB;

        if (!\Contract::canView()) {
            return [];
        }

        // Delay of the alerts of each entity, as Contract::cronContract(): nothing is reported
        // where the contract alerts are disabled
        $delays   = [];
        $notified = \Entity::getEntitiesToNotify('use_contracts_alert');
        foreach (self::getCustomerEntities($entities) as $entities_id) {
            if (isset($notified[$entities_id])) {
                $delays[$entities_id] = max(0, (int) \Entity::getUsedConfig('send_contracts_alert_before_delay', $entities_id));
            }
        }
        if ($delays === []) {
            return [];
        }

        $contracts   = \Contract::getTable();
        $end_date    = QueryFunction::dateAdd(
            date: new QueryIdentifier($contracts . '.begin_date'),
            interval: new QueryIdentifier($contracts . '.duration'),
            interval_unit: 'MONTH',
        );
        $notice_date = QueryFunction::dateAdd(
            date: new QueryIdentifier($contracts . '.begin_date'),
            interval: new QueryExpression($DB::quoteName($contracts . '.duration') . ' - ' . $DB::quoteName($contracts . '.notice')),
            interval_unit: 'MONTH',
        );
        $days_to_end    = 'DATEDIFF(' . $end_date . ', CURDATE())';
        $days_to_notice = 'DATEDIFF(' . $notice_date . ', CURDATE())';
        $alert          = $DB::quoteName($contracts . '.alert');

        // Same windows as the end and notice alerts, with the delay of each entity
        $windows = [];
        foreach ($delays as $entities_id => $delay) {
            $windows[] = [
                $contracts . '.entities_id' => $entities_id,
                [
                    'OR' => [
                        // End: from $delay days ahead; an ended contract stays until it is handled
                        [
                            new QueryExpression($alert . ' & ' . (2 ** Alert::END) . ' > 0'),
                            new QueryExpression("$days_to_end <= $delay"),
                        ],
                        // Notice: contract still running, notice date within $delay days
                        [
                            new QueryExpression($alert . ' & ' . (2 ** Alert::NOTICE) . ' > 0'),
                            $contracts . '.notice' => ['!=', 0],
                            new QueryExpression("$days_to_end > 0"),
                            new QueryExpression("$days_to_notice <= $delay"),
                        ],
                    ],
                ],
            ];
        }

        $where = [
            $contracts . '.is_deleted'  => 0,
            $contracts . '.is_template' => 0,
            ['NOT' => [$contracts . '.begin_date' => null]],
            $contracts . '.duration'    => ['!=', 0],
            // The contracts handled by the plugin only
            $contracts . '.id'          => new QuerySubQuery([
                'SELECT' => 'contracts_id',
                'FROM'   => Contract::getTable(),
            ]),
            ['NOT' => [$contracts . '.id' => self::getFollowedSources('contracts_id')]],
            ['OR' => $windows],
            getEntitiesRestrictCriteria($contracts, '', '', true),
        ];
        // A closed contract is over: it is not renewed (as the remaining days alert)
        $closed_glpi_state_id = (int) (Config::getInstance()->fields['closed_glpi_state_id'] ?? 0);
        if ($closed_glpi_state_id > 0) {
            $where[] = ['NOT' => [$contracts . '.states_id' => $closed_glpi_state_id]];
        }

        $iterator = $DB->request([
            'SELECT'    => [
                $contracts . '.id',
                $contracts . '.entities_id',
                $contracts . '.name',
                $contracts . '.num',
                $contracts . '.notice',
                'glpi_entities.completename AS entity',
                new QueryExpression($end_date, 'end_date'),
                new QueryExpression($notice_date, 'notice_date'),
            ],
            'FROM'      => $contracts,
            'LEFT JOIN' => [
                'glpi_entities' => [
                    'FKEY' => [$contracts => 'entities_id', 'glpi_entities' => 'id'],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => ['end_date ASC', 'glpi_entities.completename ASC'],
        ]);

        $today = date('Y-m-d');
        $rows  = [];
        foreach ($iterator as $data) {
            $end    = substr((string) $data['end_date'], 0, 10);
            $rows[] = [
                'id'           => (int) $data['id'],
                'entities_id'  => (int) $data['entities_id'],
                'entity'       => (string) $data['entity'],
                'name'         => (string) $data['name'],
                'num'          => (string) $data['num'],
                'end_date'     => $end,
                'notice_date'  => (int) $data['notice'] > 0 ? substr((string) $data['notice_date'], 0, 10) : '',
                'expired'      => $end < $today,
                'contract_url' => \Contract::getFormURLWithID((int) $data['id']),
                'new_url'      => self::getNewProposalUrl((int) $data['entities_id'], ['contracts_id' => (int) $data['id']]),
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
