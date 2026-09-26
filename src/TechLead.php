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

use CommonDBTM;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use Migration;
use Session;

/**
 * Tech leads of a client (entity): several technicians can be linked to a client,
 * one of them being flagged as the main one (is_default).
 */
class TechLead extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Tech lead', 'Tech leads', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-user-star';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public function prepareInputForAdd($input)
    {
        // The target entity comes from the input: it has to belong to the caller perimeter
        if (!Session::haveAccessToEntity((int) ($input['entities_id'] ?? 0))) {
            return false;
        }
        if ((int) ($input['users_id'] ?? 0) <= 0) {
            return false;
        }
        $input['entities_id'] = (int) $input['entities_id'];
        $input['users_id']    = (int) $input['users_id'];
        // The picker only offers technicians (own_ticket) of the entity: the posted value
        // is not bound by it, so replay that population here
        if (!self::isTechnicianOfEntity($input['users_id'], $input['entities_id'])) {
            return false;
        }
        // The first tech lead of a client becomes its main one
        $input['is_default'] = countElementsInTable(
            self::getTable(),
            ['entities_id' => $input['entities_id']],
        ) === 0 ? 1 : 0;

        return $input;
    }

    /**
     * Whether a user belongs to the population offered by the tech lead picker:
     * an active user holding the own_ticket right on a central profile covering the entity
     *
     * @param int $users_id
     * @param int $entities_id
     *
     * @return bool
     */
    public static function isTechnicianOfEntity(int $users_id, int $entities_id): bool
    {
        global $DB;

        $result = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => [
                    'ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id'],
                ],
                'glpi_profiles'       => [
                    'ON' => ['glpi_profiles' => 'id', 'glpi_profiles_users' => 'profiles_id'],
                ],
                'glpi_profilerights'  => [
                    'ON' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles' => 'id'],
                ],
            ],
            'WHERE'      => [
                'glpi_users.id'         => $users_id,
                'glpi_users.is_deleted' => 0,
                'glpi_users.is_active'  => 1,
                'glpi_profiles.interface'   => 'central',
                'glpi_profilerights.name'   => 'ticket',
                'glpi_profilerights.rights' => ['&', \Ticket::OWN],
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', $entities_id, true),
        ])->current();

        return (int) ($result['cpt'] ?? 0) > 0;
    }

    public function post_purgeItem()
    {
        // Promote another tech lead when the main one is removed, so a client keeps a main one
        if ($this->fields['is_default']) {
            $others = (new self())->find(['entities_id' => $this->fields['entities_id']], ['id'], 1);
            $other  = reset($others);
            if ($other) {
                (new self())->update(['id' => $other['id'], 'is_default' => 1]);
            }
        }
    }

    /**
     * Drop the tech lead links of a user sent to the trash or purged: a deleted user would
     * otherwise keep following its clients (overdue tickets by tech lead, customer sheet)
     *
     * @param \User $user
     *
     * @return void
     */
    public static function removeUserLinks(\User $user): void
    {
        // One by one, so that post_purgeItem() promotes another main tech lead
        (new self())->deleteByCriteria(['users_id' => (int) $user->getID()], true);
    }

    /**
     * Flag a tech lead as the main one of its client
     *
     * @param int $id
     *
     * @return void
     */
    public function setAsDefault(int $id): void
    {
        if (!$this->getFromDB($id)) {
            return;
        }
        $link = new self();
        foreach ($this->find(['entities_id' => $this->fields['entities_id'], 'is_default' => 1]) as $data) {
            $link->update(['id' => $data['id'], 'is_default' => 0]);
        }
        $link->update(['id' => $id, 'is_default' => 1]);
    }

    /**
     * Tech leads of the given entities, main one first
     *
     * @param array  $entities
     * @param string $root_doc
     *
     * @return array
     */
    public function buildTechLeadsForTemplate(array $entities, string $root_doc): array
    {
        global $DB;

        $table    = self::getTable();
        $iterator = $DB->request([
            'SELECT'     => [
                $table . '.id AS link_id',
                $table . '.is_default',
                'glpi_users.id',
                'glpi_users.phone',
                'glpi_users.mobile',
                'glpi_useremails.email',
            ],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $table       => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'LEFT JOIN'  => [
                'glpi_useremails' => [
                    'ON' => [
                        'glpi_useremails' => 'users_id',
                        'glpi_users'      => 'id',
                        [
                            'AND' => ['glpi_useremails.is_default' => 1],
                        ],
                    ],
                ],
            ],
            'WHERE'      => [
                $table . '.entities_id' => $entities,
                'glpi_users.is_deleted' => 0,
            ],
            'ORDERBY'    => [$table . '.is_default DESC', 'glpi_users.realname', 'glpi_users.firstname'],
        ]);

        $techleads = [];
        foreach ($iterator as $data) {
            $techleads[] = [
                'link_id'    => $data['link_id'],
                'url'        => $root_doc . '/front/user.form.php?id=' . $data['id'],
                'name'       => getUserName($data['id']),
                'phone'      => $data['phone'] ?? '',
                'mobile'     => $data['mobile'] ?? '',
                'email'      => $data['email'] ?? '',
                'is_default' => (bool) $data['is_default'],
            ];
        }
        return $techleads;
    }

    /**
     * Tech leads of the given entities, indexed by entity id, main one first.
     * Public entry point for other plugins (activity).
     *
     * @param array $entities
     *
     * @return array<int, array<int, array{users_id: int, is_default: bool}>>
     */
    public static function getTechLeadsByEntity(array $entities): array
    {
        global $DB;

        if ($entities === []) {
            return [];
        }

        $result   = [];
        $table    = self::getTable();
        $iterator = $DB->request([
            'SELECT'     => [$table . '.entities_id', $table . '.users_id', $table . '.is_default'],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $table       => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                $table . '.entities_id' => $entities,
                'glpi_users.is_deleted' => 0,
            ],
            'ORDERBY'    => [$table . '.is_default DESC'],
        ]);
        foreach ($iterator as $data) {
            $result[(int) $data['entities_id']][] = [
                'users_id'   => (int) $data['users_id'],
                'is_default' => (bool) $data['is_default'],
            ];
        }
        return $result;
    }

    /**
     * Keep the active customers only, same scope as DirectHelpdesk::getUnbilledOverviewData():
     * the entities below wizard_default_entities_id (the customers entity itself excluded),
     * minus the archived ones, a customer being archived by moving its entity under
     * wizard_archive_entities_id (whole subtree excluded, root included), and minus the
     * parent entities of other active customers
     *
     * @param array $entities
     *
     * @return int[]
     */
    public static function filterActiveCustomers(array $entities): array
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

        // Parent entities are only grouping levels of the tree: the clients are their leaves
        $entities = array_map('intval', array_values($entities));
        if ($entities !== []) {
            $entities = array_values(array_diff(
                $entities,
                array_map('intval', getAncestorsOf('glpi_entities', $entities)),
            ));
        }

        return $entities;
    }

    /**
     * Number of clients per tech lead, within the given entities
     *
     * @param array $entities
     *
     * @return array
     */
    public static function getClientsCountByTech(array $entities): array
    {
        global $DB;

        $entities = self::filterActiveCustomers($entities);
        if ($entities === []) {
            return [];
        }

        $table    = self::getTable();
        $iterator = $DB->request([
            'SELECT'     => [
                $table . '.id',
                $table . '.users_id',
                $table . '.entities_id',
                $table . '.is_default',
            ],
            'FROM'       => $table,
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $table       => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                $table . '.entities_id' => $entities,
                'glpi_users.is_deleted' => 0,
            ],
        ]);

        $techs         = [];
        $total_clients = [];
        foreach ($iterator as $data) {
            $users_id = (int) $data['users_id'];
            if (!isset($techs[$users_id])) {
                $techs[$users_id] = [
                    'users_id'   => $users_id,
                    'name'       => getUserName($users_id),
                    'nb_clients' => 0,
                    'nb_main'    => 0,
                    'clients'    => [],
                ];
            }
            $techs[$users_id]['nb_clients']++;
            if ($data['is_default']) {
                $techs[$users_id]['nb_main']++;
            }
            $techs[$users_id]['clients'][] = [
                'name'    => \Dropdown::getDropdownName('glpi_entities', $data['entities_id']),
                'url'     => self::getCustomerSheetUrl((int) $data['entities_id']),
                'is_main' => (bool) $data['is_default'],
                'link_id' => (int) $data['id'],
            ];
            $total_clients[(int) $data['entities_id']] = true;
        }

        $max = 0;
        foreach ($techs as $tech) {
            $max = max($max, $tech['nb_clients']);
        }
        foreach ($techs as &$tech) {
            usort($tech['clients'], static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            // Bar width relative to the busiest tech lead: a client can have several tech leads,
            // so the counts do not add up to the number of clients
            $tech['percent'] = $max > 0 ? round($tech['nb_clients'] * 100 / $max, 1) : 0;
        }
        unset($tech);

        $techs = array_values($techs);
        usort(
            $techs,
            static fn(array $a, array $b): int => ($b['nb_clients'] <=> $a['nb_clients']) ?: strnatcasecmp($a['name'], $b['name']),
        );

        return [
            'techs'         => $techs,
            'total_clients' => count($total_clients),
        ];
    }

    /**
     * Clients of the given entities without any tech lead
     *
     * @param array $entities
     *
     * @return array
     */
    public static function getClientsWithoutTechLead(array $entities): array
    {
        global $DB;

        $entities = self::filterActiveCustomers($entities);
        // Customers that never had a contract are not followed yet: no tech lead expected
        $entities = array_values(array_diff($entities, EditorSubscription::getNeverContractEntities($entities)));
        if ($entities === []) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'    => ['glpi_entities.id', 'glpi_entities.completename'],
            'FROM'      => 'glpi_entities',
            'LEFT JOIN' => [
                self::getTable() => [
                    'ON' => [
                        self::getTable() => 'entities_id',
                        'glpi_entities'  => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_entities.id'           => $entities,
                self::getTable() . '.id'     => null,
            ],
            'ORDERBY'   => ['glpi_entities.completename'],
        ]);

        $clients = [];
        foreach ($iterator as $data) {
            $clients[] = [
                'name' => $data['completename'],
                'url'  => self::getCustomerSheetUrl((int) $data['id']),
            ];
        }
        return $clients;
    }

    /**
     * Clients of the given entities having tech leads, but none of them as the main one
     *
     * @param array $entities
     *
     * @return array
     */
    public static function getClientsWithoutMainTechLead(array $entities): array
    {
        global $DB;

        $entities = self::filterActiveCustomers($entities);
        // Customers that never had a contract are not followed yet: no tech lead expected
        $entities = array_values(array_diff($entities, EditorSubscription::getNeverContractEntities($entities)));
        if ($entities === []) {
            return [];
        }

        $table    = self::getTable();
        $iterator = $DB->request([
            'SELECT'     => ['glpi_entities.id', 'glpi_entities.completename'],
            'FROM'       => 'glpi_entities',
            'INNER JOIN' => [
                $table => [
                    'ON' => [
                        $table          => 'entities_id',
                        'glpi_entities' => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_entities.id' => $entities,
            ],
            'GROUPBY'    => ['glpi_entities.id', 'glpi_entities.completename'],
            'HAVING'     => [
                new QueryExpression(QueryFunction::sum($table . '.is_default') . ' = 0'),
            ],
            'ORDERBY'    => ['glpi_entities.completename'],
        ]);

        $clients = [];
        foreach ($iterator as $data) {
            $clients[] = [
                'name' => $data['completename'],
                'url'  => self::getCustomerSheetUrl((int) $data['id']),
            ];
        }
        return $clients;
    }

    /**
     * Entity form opened on its "Customer sheet" tab, where the tech leads are changed
     *
     * @param int $entities_id
     *
     * @return string
     */
    private static function getCustomerSheetUrl(int $entities_id): string
    {
        return \Entity::getFormURLWithID($entities_id) . '&' . http_build_query([
            'forcetab' => CustomerSheet::class . '$1',
        ]);
    }

    /**
     * "Tech lead by clients" tab of the portal
     *
     * @param array $entities
     *
     * @return void
     */
    public static function showClientsByTech(array $entities): void
    {
        $stats = self::getClientsCountByTech($entities);

        // Button to the assignment rules page, with the number of rules left to create
        $can_view_rules = TechLeadRule::canView();

        TemplateRenderer::getInstance()->display('@manageentities/techlead/clients_by_tech.html.twig', [
            'techs'           => $stats['techs'] ?? [],
            'total_clients'   => $stats['total_clients'] ?? 0,
            'without_techlead' => self::getClientsWithoutTechLead($entities),
            'without_main'     => self::getClientsWithoutMainTechLead($entities),
            'can_toggle_main'  => self::canUpdate(),
            'action_url'       => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
            'can_view_rules'   => $can_view_rules,
            'missing_rules'    => $can_view_rules
                ? TechLeadRule::countMissingRules(TechLeadRule::getClients($entities))
                : 0,
            'rules_url'        => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/techleadrule.php',
        ]);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `is_default` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `unicity` (`users_id`,`entities_id`),
                            KEY `users_id` (`users_id`),
                            KEY `entities_id` (`entities_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Links left by users sent to the trash or purged before their removal was handled
        $orphans = $DB->request([
            'SELECT'    => [$table . '.id'],
            'FROM'      => $table,
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $table       => 'users_id',
                        'glpi_users' => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'OR' => [
                    'glpi_users.id'         => null,
                    'glpi_users.is_deleted' => 1,
                ],
            ],
        ]);
        $link = new self();
        foreach ($orphans as $data) {
            $link->delete(['id' => $data['id']], true);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
