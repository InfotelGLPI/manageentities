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

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use Glpi\DBAL\QuerySubQuery;

/**
 * Summary card of a customer, first tab of the clients management on the simplified interface:
 * logo, administrative data, main contact, and a badge per follow-up (ongoing contract,
 * subscription, unbilled interventions)
 */
class CustomerSummary
{
    /** Ongoing contract: alert below this many days left */
    public const LOW_REMAINING_DAYS = 0.5;

    /** Unbilled interventions: alert above this many hours */
    public const UNBILLED_ALERT_HOURS = 3;

    /**
     * Badge colour of the ongoing contracts: red below LOW_REMAINING_DAYS left or without any
     * ongoing contract, green otherwise
     */
    public static function getContractColor(?float $remaining_days): string
    {
        if ($remaining_days === null) {
            return 'red';
        }

        return $remaining_days < self::LOW_REMAINING_DAYS ? 'red' : 'green';
    }

    /**
     * Badge colour of the subscription: red once expired, green while running, grey without one
     */
    public static function getSubscriptionColor(?string $end_date, string $today): string
    {
        if ($end_date === null) {
            return 'secondary';
        }
        if ($end_date === '') {
            // No end date: running
            return 'green';
        }

        return substr($end_date, 0, 10) < $today ? 'red' : 'green';
    }

    /**
     * Unbilled time in man-days, from seconds, with the hours of a working day of the
     * configuration (0 when it is not set, as the rest of the plugin guards it)
     */
    public static function toManDays(int $seconds, int $hours_per_day): float
    {
        return $hours_per_day > 0 ? round($seconds / HOUR_TIMESTAMP / $hours_per_day, 2) : 0.0;
    }

    /**
     * Badge colour of the unbilled interventions: red beyond UNBILLED_ALERT_HOURS
     */
    public static function getUnbilledColor(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'green';
        }

        return $seconds > self::UNBILLED_ALERT_HOURS * HOUR_TIMESTAMP ? 'red' : 'orange';
    }

    /**
     * Counter of the contractual follow-up of the simplified interface: the ongoing contracts
     *
     * @param array<int|string> $entities active entities
     */
    public static function countOngoingContracts(array $entities): int
    {
        return $entities === [] ? 0 : self::getOngoingContractsRemaining($entities)['count'];
    }

    /**
     * Counter of the unbilled interventions tab of the simplified interface
     *
     * @param array<int|string> $entities active entities
     */
    public static function countUnbilled(array $entities): int
    {
        return $entities === [] ? 0 : self::getUnbilled($entities)['count'];
    }

    /**
     * Card of the active customer
     */
    public static function show(int $entities_id): void
    {
        global $CFG_GLPI;

        $entity = new \Entity();
        if (!$entity->getFromDB($entities_id)) {
            return;
        }
        $config        = Config::getInstance();
        $hours_per_day = (int) ($config->fields['hourbyday'] ?? 0);
        $is_day        = (int) $config->fields['hourorday'] === Config::DAY;

        $logos = [];
        foreach ((new EntityLogo())->find(['entities_id' => $entities_id]) as $logo) {
            $logos[] = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/logo.send.php?docid=' . (int) $logo['logos_id'];
        }

        // Main contact first, else the first one
        $contacts = (new Contact())->buildContactsForTemplate([$entities_id], $CFG_GLPI['root_doc']);
        usort($contacts, static fn(array $a, array $b): int => (int) $b['is_default'] <=> (int) $a['is_default']);

        $remaining   = self::getOngoingContractsRemaining($entities_id);
        $subscription = Config::useEditorSubscriptions() ? EditorSubscription::getForEntity($entities_id) : [];
        $end_date     = $subscription === [] ? null : (string) ($subscription['end_date'] ?? '');
        $unbilled     = self::getUnbilled($entities_id);

        TemplateRenderer::getInstance()->display('@manageentities/entity/customer_summary.html.twig', [
            'entity'   => $entity->fields,
            'logo'     => $logos[0] ?? '',
            'contact'  => $contacts[0] ?? null,
            'contract' => [
                'count'     => $remaining['count'],
                'remaining' => $remaining['remaining'],
                'is_day'    => $is_day,
                'color'     => self::getContractColor($remaining['count'] > 0 ? $remaining['remaining'] : null),
            ],
            'subscription' => Config::useEditorSubscriptions() ? [
                'exists'   => $subscription !== [],
                'end_date' => $end_date,
                'level'    => !empty($subscription['plugin_manageentities_subscriptionlevels_id'])
                    ? \Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int) $subscription['plugin_manageentities_subscriptionlevels_id'])
                    : '',
                'color'    => self::getSubscriptionColor($end_date, date('Y-m-d')),
            ] : null,
            'unbilled' => [
                'count'    => $unbilled['count'],
                'man_days' => self::toManDays($unbilled['seconds'], $hours_per_day),
                'color'    => self::getUnbilledColor($unbilled['seconds']),
            ],
            'alert_hours'   => self::UNBILLED_ALERT_HOURS,
            'low_remaining' => self::LOW_REMAINING_DAYS,
        ]);
    }

    /**
     * Ongoing contracts of the customers (not deleted, with an open period) and the days left on
     * them, from the denormalized remaining_days of the contracts tab
     *
     * @param int|array<int|string> $entities_id
     *
     * @return array{count: int, remaining: float}
     */
    private static function getOngoingContractsRemaining(int|array $entities_id): array
    {
        global $DB;

        $states = Config::getInstance()->getActiveContractStates();
        $table  = Contract::getTable();
        $where  = [
            $table . '.entities_id'   => $entities_id,
            'glpi_contracts.is_deleted' => 0,
        ];
        if ($states !== []) {
            $where[] = [
                $table . '.contracts_id' => new QuerySubQuery([
                    'SELECT' => 'contracts_id',
                    'FROM'   => ContractDay::getTable(),
                    'WHERE'  => ['plugin_manageentities_contractstates_id' => $states],
                ]),
            ];
        }

        $data = $DB->request([
            'SELECT'    => [
                QueryFunction::count($table . '.id', false, 'nb'),
                QueryFunction::sum($table . '.remaining_days', false, 'remaining'),
            ],
            'FROM'      => $table,
            'INNER JOIN' => [
                'glpi_contracts' => [
                    'ON' => [
                        $table           => 'contracts_id',
                        'glpi_contracts' => 'id',
                    ],
                ],
            ],
            'WHERE'     => $where,
        ])->current();

        return ['count' => (int) $data['nb'], 'remaining' => (float) $data['remaining']];
    }

    /**
     * Unbilled interventions of the customers
     *
     * @param int|array<int|string> $entities_id
     *
     * @return array{count: int, seconds: int}
     */
    private static function getUnbilled(int|array $entities_id): array
    {
        global $DB;

        $table = DirectHelpdesk::getTable();
        $data  = $DB->request([
            'SELECT' => [
                QueryFunction::count("$table.id", false, 'nb'),
                QueryFunction::sum("$table.actiontime", false, 'total'),
            ],
            'FROM'   => $table,
            'WHERE'  => ['is_billed' => 0, 'entities_id' => $entities_id],
        ])->current();

        return ['count' => (int) $data['nb'], 'seconds' => (int) $data['total']];
    }
}
