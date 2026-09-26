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

use CommonITILActor;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use Glpi\DBAL\QuerySubQuery;
use ITILFollowup;
use Session;
use Ticket;
use TicketTask;
use Toolbox;

/**
 * "Ongoing tickets" tab of the clients management dashboard: the open tickets of the active
 * customers, per technician and per tech lead
 */
class TicketOverview
{
    public const DEFAULT_STALE_WEEKS = 2;
    public const MAX_STALE_WEEKS     = 52;

    /** Type filter value showing both incidents and requests */
    public const ALL_TYPES = 0;

    /** Ticket search option declared by plugin_manageentities_getAddSearchOptions() */
    public const SEARCH_OPTION_OPENED_BY_CUSTOMER = 4457;

    /**
     * The tab lists the tickets of every customer, whoever they are assigned to. It is a
     * provider-side view: never offered on the simplified interface.
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return Session::getCurrentInterface() === 'central'
            && Session::haveRight(Ticket::$rightname, Ticket::READALL);
    }

    /**
     * Users having at least one profile on the standard interface: they are staff members,
     * not customers, whatever the entity of that profile
     *
     * @return QuerySubQuery
     */
    private static function getStaffUsersSubQuery(): QuerySubQuery
    {
        return new QuerySubQuery([
            'SELECT'     => 'glpi_profiles_users.users_id',
            'DISTINCT'   => true,
            'FROM'       => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profiles' => [
                    'ON' => [
                        'glpi_profiles'       => 'id',
                        'glpi_profiles_users' => 'profiles_id',
                    ],
                ],
            ],
            'WHERE'      => ['glpi_profiles.interface' => 'central'],
        ]);
    }

    /**
     * Search option of the tickets opened by a customer, that is, whose writer has no profile
     * on the standard interface. A ticket collected from an unknown email address has no writer
     * and counts as a customer ticket. Used by the links of the "Ongoing tickets" tab, so that
     * the ticket search matches its counts.
     *
     * @return array
     */
    public static function getOpenedByCustomerSearchOption(): array
    {
        return [
            'table'         => Ticket::getTable(),
            'field'         => 'users_id_recipient',
            'name'          => __('Opened by a customer', 'manageentities'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'nosort'        => true,
            'computation'   => QueryFunction::if(
                ['TABLE.users_id_recipient' => self::getStaffUsersSubQuery()],
                new QueryExpression('0'),
                new QueryExpression('1'),
            ),
        ];
    }

    /**
     * Number of weeks after which an open ticket is overdue, from the tab form
     *
     * @param mixed $value
     *
     * @return int
     */
    public static function sanitizeStaleWeeks(mixed $value): int
    {
        $weeks = is_numeric($value) ? (int) $value : self::DEFAULT_STALE_WEEKS;

        return max(1, min(self::MAX_STALE_WEEKS, $weeks));
    }

    /**
     * Ticket type of the tab filter: Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE or ALL_TYPES
     *
     * @param mixed $value
     *
     * @return int
     */
    public static function sanitizeType(mixed $value): int
    {
        $type = is_numeric($value) ? (int) $value : self::ALL_TYPES;

        return array_key_exists($type, Ticket::getTypes()) ? $type : self::ALL_TYPES;
    }

    /**
     * Entities whose tickets are shown: the entities below wizard_default_entities_id (the
     * customers entity itself excluded), minus the archived customers, whose entity sits under
     * wizard_archive_entities_id (whole subtree excluded, root included), within the given ones.
     * Same scope as TechLead and DirectHelpdesk, but the intermediate levels are kept: a
     * ticket opened on a grouping entity still belongs to the customers.
     *
     * @param array $entities
     *
     * @return int[]
     */
    private static function getCustomerEntities(array $entities): array
    {
        $config = Config::getInstance();

        $parent_id = (int) ($config->fields['wizard_default_entities_id'] ?? 0);
        if ($parent_id > 0) {
            $customer_sons = getSonsOf('glpi_entities', $parent_id);
            unset($customer_sons[$parent_id]);
            $entities = array_intersect($entities, array_keys($customer_sons));
        }

        $archive_entities_id = (int) ($config->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_entities_id > 0) {
            $entities = array_diff($entities, array_keys(getSonsOf('glpi_entities', $archive_entities_id)));
        }

        return array_values(array_unique(array_map('intval', $entities)));
    }

    /**
     * Criteria of the core ticket search matching the same perimeter as this tab: open tickets
     * below the customers entity, the entity itself and the archived customers excluded. The
     * search is further restricted to the active entities of the session by the core.
     *
     * @param int   $type     ticket type, ALL_TYPES for both
     * @param array $criteria extra criteria
     *
     * @return string
     */
    private static function getSearchUrl(int $type, array $criteria): string
    {
        $config = Config::getInstance();

        $base = [
            [
                'field'      => 12, // status
                'searchtype' => 'equals',
                'value'      => 'notold',
            ],
            [
                'link'       => 'AND',
                'field'      => self::SEARCH_OPTION_OPENED_BY_CUSTOMER,
                'searchtype' => 'equals',
                'value'      => 1,
            ],
        ];

        $parent_id = (int) ($config->fields['wizard_default_entities_id'] ?? 0);
        if ($parent_id > 0) {
            $base[] = [
                'link'       => 'AND',
                'field'      => 80, // entity
                'searchtype' => 'under',
                'value'      => $parent_id,
            ];
            $base[] = [
                'link'       => 'AND',
                'field'      => 80,
                'searchtype' => 'notequals',
                'value'      => $parent_id,
            ];
        }

        $archive_entities_id = (int) ($config->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_entities_id > 0) {
            $base[] = [
                'link'       => 'AND',
                'field'      => 80,
                'searchtype' => 'notunder',
                'value'      => $archive_entities_id,
            ];
        }

        if ($type !== self::ALL_TYPES) {
            $criteria[] = [
                'field'      => 14, // type
                'searchtype' => 'equals',
                'value'      => $type,
            ];
        }

        foreach ($criteria as $criterion) {
            $base[] = ['link' => 'AND'] + $criterion;
        }

        return Ticket::getSearchURL() . '?' . Toolbox::append_params([
            'criteria' => $base,
            'reset'    => 'reset',
        ]);
    }

    /**
     * Search criteria of the overdue tickets: opened before the overdue limit, pending ones excluded
     *
     * @param int $weeks
     *
     * @return array
     */
    private static function getStaleCriteria(int $weeks): array
    {
        return [
            [
                'field'      => 15, // opening date
                'searchtype' => 'lessthan',
                'value'      => '-' . $weeks . 'WEEK',
            ],
            [
                'field'      => 12, // status
                'searchtype' => 'notequals',
                'value'      => Ticket::WAITING,
            ],
        ];
    }

    /**
     * Open tickets of the given entities, one row per assigned technician (users_id null for
     * a ticket without any technician)
     *
     * @param int[] $entities
     * @param int   $type     ticket type, ALL_TYPES for both
     *
     * @return array<int, array<string, mixed>> tickets by id, with their technicians
     */
    private static function getOpenTickets(array $entities, int $type): array
    {
        global $DB;

        if ($entities === []) {
            return [];
        }

        $criteria = [
            'SELECT'    => [
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.date',
                'glpi_tickets.status',
                'glpi_tickets.entities_id',
                'glpi_entities.completename AS entity_name',
                'glpi_tickets_users.users_id',
            ],
            'FROM'      => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_entities' => [
                    'ON' => [
                        'glpi_entities' => 'id',
                        'glpi_tickets'  => 'entities_id',
                    ],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                        [
                            'AND' => ['glpi_tickets_users.type' => CommonITILActor::ASSIGN],
                        ],
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_tickets.is_deleted'  => 0,
                'glpi_tickets.status'      => Ticket::getNotSolvedStatusArray(),
                'glpi_tickets.entities_id' => $entities,
                // Tickets opened by the staff for its own needs are not customer requests
                'NOT'                      => ['glpi_tickets.users_id_recipient' => self::getStaffUsersSubQuery()],
            ],
            'ORDERBY'   => ['glpi_tickets.date ASC', 'glpi_tickets.id ASC'],
        ];
        if ($type !== self::ALL_TYPES) {
            $criteria['WHERE']['glpi_tickets.type'] = $type;
        }
        $iterator = $DB->request($criteria);

        $tickets = [];
        foreach ($iterator as $data) {
            $id = (int) $data['id'];
            if (!isset($tickets[$id])) {
                $tickets[$id] = [
                    'id'          => $id,
                    'name'        => $data['name'],
                    'date'        => $data['date'],
                    'status'      => (int) $data['status'],
                    'entities_id' => (int) $data['entities_id'],
                    'entity_name' => $data['entity_name'],
                    'techs'       => [],
                ];
            }
            if ($data['users_id'] !== null) {
                $tickets[$id]['techs'][(int) $data['users_id']] = (int) $data['users_id'];
            }
        }

        return $tickets;
    }

    /**
     * Data displayed by the template for one ticket
     *
     * @param array $ticket
     *
     * @return array<string, mixed>
     */
    private static function formatTicket(array $ticket): array
    {
        return [
            'id'          => $ticket['id'],
            'name'        => $ticket['name'],
            'url'         => Ticket::getFormURLWithID($ticket['id']),
            'date'        => $ticket['date'],
            'status'      => Ticket::getStatus($ticket['status']),
            'entity_name' => $ticket['entity_name'],
            'age'         => (int) floor((strtotime($_SESSION['glpi_currenttime']) - strtotime((string) $ticket['date'])) / DAY_TIMESTAMP),
        ];
    }

    /**
     * Sort groups of tickets: the busiest first, the "none" group (key 0) last
     *
     * @param array $groups
     *
     * @return array
     */
    private static function sortGroups(array $groups): array
    {
        usort(
            $groups,
            static fn(array $a, array $b): int => (($a['users_id'] === 0) <=> ($b['users_id'] === 0))
                ?: (count($b['tickets']) <=> count($a['tickets']))
                ?: strnatcasecmp($a['name'], $b['name']),
        );

        return $groups;
    }

    /**
     * Main tech lead of each ticket entity, declared on that entity itself (0 when none). The
     * other tech leads of a client do not answer for its tickets, and a tech lead set on a
     * parent entity (grouping level, customers root) is not the one of the clients below it
     *
     * @param int[] $entities
     *
     * @return array<int, int>
     */
    private static function getMainTechLeadOfEntities(array $entities): array
    {
        $by_entity = TechLead::getTechLeadsByEntity($entities);

        $result = [];
        foreach ($entities as $entities_id) {
            $result[$entities_id] = 0;
            foreach ($by_entity[$entities_id] ?? [] as $techlead) {
                if ($techlead['is_default']) {
                    $result[$entities_id] = $techlead['users_id'];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Open tickets whose last follow-up was written by one of their requesters, with no task
     * added since: the customer is waiting for an answer
     *
     * @param array $tickets open tickets, by id
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getWaitingForAnswer(array $tickets): array
    {
        global $DB;

        if ($tickets === []) {
            return [];
        }

        $followup_table = ITILFollowup::getTable();
        $last_followups = new QuerySubQuery([
            'SELECT'  => [
                'items_id',
                QueryFunction::max('id', 'last_id'),
            ],
            'FROM'    => $followup_table,
            'WHERE'   => [
                'itemtype' => Ticket::class,
                'items_id' => array_keys($tickets),
            ],
            'GROUPBY' => ['items_id'],
        ], 'last_followups');

        $iterator = $DB->request([
            'SELECT'     => [
                $followup_table . '.items_id',
                $followup_table . '.users_id',
                $followup_table . '.date',
            ],
            'FROM'       => $followup_table,
            'INNER JOIN' => [
                [
                    'TABLE' => $last_followups,
                    'FKEY'  => [
                        'last_followups' => 'last_id',
                        $followup_table  => 'id',
                    ],
                ],
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        $followup_table      => 'items_id',
                        [
                            'AND' => [
                                'glpi_tickets_users.users_id' => new QueryExpression($DB::quoteName($followup_table . '.users_id')),
                                'glpi_tickets_users.type'     => CommonITILActor::REQUESTER,
                            ],
                        ],
                    ],
                ],
            ],
            // A requester having a standard interface profile is a staff member, not a customer
            'WHERE'      => [
                'NOT' => [$followup_table . '.users_id' => self::getStaffUsersSubQuery()],
            ],
        ]);

        $waiting = [];
        foreach ($iterator as $data) {
            $tickets_id = (int) $data['items_id'];
            // A requester who is also assigned to the ticket answers as a technician
            if (isset($tickets[$tickets_id]['techs'][(int) $data['users_id']])) {
                continue;
            }
            $waiting[$tickets_id] = $data;
        }
        if ($waiting === []) {
            return [];
        }

        // A task added after the follow-up means the customer was answered in the meantime
        $task_iterator = $DB->request([
            'SELECT'  => [
                'tickets_id',
                QueryFunction::max('date', 'last_date'),
            ],
            'FROM'    => TicketTask::getTable(),
            'WHERE'   => ['tickets_id' => array_keys($waiting)],
            'GROUPBY' => ['tickets_id'],
        ]);
        foreach ($task_iterator as $data) {
            $tickets_id = (int) $data['tickets_id'];
            if ($data['last_date'] !== null && $data['last_date'] > $waiting[$tickets_id]['date']) {
                unset($waiting[$tickets_id]);
            }
        }

        $result = [];
        foreach ($waiting as $tickets_id => $data) {
            $result[] = self::formatTicket($tickets[$tickets_id]) + [
                'followup_date'   => $data['date'],
                'followup_author' => getUserName((int) $data['users_id']),
                'techs'           => array_map(
                    static fn(int $users_id): string => getUserName($users_id),
                    array_values($tickets[$tickets_id]['techs']),
                ),
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcmp((string) $a['followup_date'], (string) $b['followup_date']));

        return $result;
    }

    /**
     * Everything the tab displays
     *
     * @param array $entities active entities of the session
     * @param int   $weeks    number of weeks after which an open ticket is overdue
     * @param int   $type     ticket type, ALL_TYPES for both
     *
     * @return array<string, mixed>
     */
    public static function getOverview(array $entities, int $weeks, int $type = self::ALL_TYPES): array
    {
        $tickets   = self::getOpenTickets(self::getCustomerEntities($entities), $type);
        $limit     = date('Y-m-d H:i:s', strtotime('-' . $weeks . ' weeks', strtotime($_SESSION['glpi_currenttime'])));
        $stale_criteria = self::getStaleCriteria($weeks);

        $by_tech        = [];
        $unassigned     = 0;
        $stale_by_tech  = [];
        $stale_tickets  = [];
        foreach ($tickets as $ticket) {
            // A pending ticket waits for someone else: it is not late on the technician side
            $is_stale = $ticket['date'] < $limit && $ticket['status'] !== Ticket::WAITING;
            if ($is_stale) {
                $stale_tickets[$ticket['id']] = $ticket;
            }

            if ($ticket['techs'] === []) {
                $unassigned++;
            }
            // A ticket without technician is listed in the "none" group of the overdue tickets
            foreach ($ticket['techs'] ?: [0] as $users_id) {
                if ($users_id > 0) {
                    $by_tech[$users_id] = ($by_tech[$users_id] ?? 0) + 1;
                }
                if ($is_stale) {
                    $stale_by_tech[$users_id][] = self::formatTicket($ticket);
                }
            }
        }

        $techs = [];
        foreach ($by_tech as $users_id => $count) {
            $techs[] = [
                'users_id' => $users_id,
                'name'     => getUserName($users_id),
                'count'    => $count,
                'url'      => self::getSearchUrl($type, [[
                    'field'      => 5, // technician
                    'searchtype' => 'equals',
                    'value'      => $users_id,
                ]]),
            ];
        }
        usort(
            $techs,
            static fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strnatcasecmp($a['name'], $b['name']),
        );

        $stale_techs = [];
        foreach ($stale_by_tech as $users_id => $list) {
            $criteria      = $stale_criteria;
            $criteria[]    = $users_id > 0
                ? ['field' => 5, 'searchtype' => 'equals', 'value' => $users_id]
                : ['field' => 5, 'searchtype' => 'empty', 'value' => 'NULL'];
            $stale_techs[] = [
                'users_id' => $users_id,
                'name'     => $users_id > 0 ? getUserName($users_id) : __('No technician', 'manageentities'),
                'tickets'  => $list,
                'url'      => self::getSearchUrl($type, $criteria),
            ];
        }

        // Tech leads follow a customer, not a ticket: the overdue tickets are grouped by the
        // main tech lead of their entity, the one answering for the customer
        $main_techlead_of_entity = self::getMainTechLeadOfEntities(
            array_values(array_unique(array_column($stale_tickets, 'entities_id'))),
        );
        $stale_by_techlead = [];
        foreach ($stale_tickets as $ticket) {
            $stale_by_techlead[$main_techlead_of_entity[$ticket['entities_id']]][] = self::formatTicket($ticket);
        }
        $stale_techleads = [];
        foreach ($stale_by_techlead as $users_id => $list) {
            $stale_techleads[] = [
                'users_id' => $users_id,
                'name'     => $users_id > 0 ? getUserName($users_id) : __('No tech lead', 'manageentities'),
                'tickets'  => $list,
            ];
        }

        return [
            'total'            => count($tickets),
            'total_url'        => self::getSearchUrl($type, []),
            'techs'            => $techs,
            'unassigned'       => $unassigned,
            'unassigned_url'   => self::getSearchUrl($type, [[
                'field'      => 5,
                'searchtype' => 'empty',
                'value'      => 'NULL',
            ]]),
            'stale_total'      => count($stale_tickets),
            'stale_url'        => self::getSearchUrl($type, $stale_criteria),
            'stale_techs'      => self::sortGroups($stale_techs),
            'stale_techleads'  => self::sortGroups($stale_techleads),
            'waiting'          => self::getWaitingForAnswer($tickets),
        ];
    }

    /**
     * "Ongoing tickets" tab of the portal
     *
     * @param array $entities active entities of the session
     * @param mixed $weeks    number of weeks after which an open ticket is overdue, as posted
     * @param mixed $type     ticket type, as posted
     *
     * @return void
     */
    public static function showOverview(array $entities, mixed $weeks, mixed $type = self::ALL_TYPES): void
    {
        $weeks = self::sanitizeStaleWeeks($weeks);
        $type  = self::sanitizeType($type);

        TemplateRenderer::getInstance()->display(
            '@manageentities/ticket_overview.html.twig',
            self::getOverview($entities, $weeks, $type) + [
                'type'       => $type,
                'types'      => [self::ALL_TYPES => __('All')] + Ticket::getTypes(),
                'weeks'      => $weeks,
                'max_weeks'  => self::MAX_STALE_WEEKS,
                'action_url' => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
            ],
        );
    }
}
