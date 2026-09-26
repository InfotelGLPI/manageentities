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
use RequestType;
use Rule;
use RuleAction;
use RuleCommonITILObject;
use RuleCriteria;
use RuleTicket;
use Session;

/**
 * Ticket business rules assigning the main tech lead of a client to the tickets its users
 * open from the simplified interface: one rule per client, created on demand in the
 * customers entity of the configuration
 */
class TechLeadRule
{
    /** Prefix of the uuid of the generated rules, followed by the client entity id */
    public const UUID_PREFIX = 'manageentities-techlead-';

    /**
     * Rules are managed with the core ticket rules right, on top of the tech leads view
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return TechLead::canView() && Session::haveRight(RuleTicket::$rightname, READ);
    }

    /**
     * @return bool
     */
    public static function canCreate(): bool
    {
        return TechLead::canView() && Session::haveRight(RuleTicket::$rightname, UPDATE);
    }

    /**
     * Entity holding the generated rules: the customers entity of the configuration
     *
     * @return int
     */
    public static function getRulesEntity(): int
    {
        return (int) (Config::getInstance()->fields['wizard_default_entities_id'] ?? 0);
    }

    /**
     * Request source set on the tickets opened from the simplified interface
     *
     * @return int
     */
    public static function getHelpdeskRequestType(): int
    {
        return (int) RequestType::getDefault('helpdesk');
    }

    /**
     * Clients whose users hold a profile on the simplified interface, that is the ones able
     * to open tickets themselves, with their main tech lead and the matching rule
     *
     * @param array $entities active entities of the session
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getClients(array $entities): array
    {
        global $DB;

        $entities = TechLead::filterActiveCustomers($entities);
        if ($entities === []) {
            return [];
        }

        // Helpdesk users of each client, a recursive profile covering the sub-entities too
        $helpdesk_users = array_fill_keys($entities, 0);
        $iterator       = $DB->request([
            'SELECT'     => [
                'glpi_profiles_users.entities_id',
                'glpi_profiles_users.is_recursive',
                'glpi_profiles_users.users_id',
            ],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profiles' => [
                    'ON' => ['glpi_profiles' => 'id', 'glpi_profiles_users' => 'profiles_id'],
                ],
                'glpi_users'    => [
                    'ON' => ['glpi_users' => 'id', 'glpi_profiles_users' => 'users_id'],
                ],
            ],
            'WHERE'      => [
                'glpi_profiles.interface' => 'helpdesk',
                'glpi_users.is_deleted'   => 0,
                'glpi_users.is_active'    => 1,
            ],
        ]);
        $users_by_entity = [];
        foreach ($iterator as $data) {
            $covered = $data['is_recursive']
                ? array_keys(getSonsOf('glpi_entities', (int) $data['entities_id']))
                : [(int) $data['entities_id']];
            foreach (array_intersect($covered, $entities) as $entities_id) {
                $users_by_entity[$entities_id][(int) $data['users_id']] = true;
            }
        }
        foreach ($users_by_entity as $entities_id => $users) {
            $helpdesk_users[$entities_id] = count($users);
        }
        $entities = array_keys(array_filter($helpdesk_users));
        if ($entities === []) {
            return [];
        }

        $techleads = TechLead::getTechLeadsByEntity($entities);
        $rules     = self::getExistingRules();

        $clients = [];
        foreach ($entities as $entities_id) {
            $main = 0;
            foreach ($techleads[$entities_id] ?? [] as $techlead) {
                if ($techlead['is_default']) {
                    $main = $techlead['users_id'];
                    break;
                }
            }
            $rule = $rules[$entities_id] ?? null;
            $clients[] = [
                'entities_id'    => $entities_id,
                'name'           => \Dropdown::getDropdownName('glpi_entities', $entities_id),
                'nb_users'       => $helpdesk_users[$entities_id],
                'techlead_id'    => $main,
                'techlead_name'  => $main > 0 ? getUserName($main) : '',
                'rule_id'        => $rule['id'] ?? 0,
                'rule_url'       => $rule !== null ? RuleTicket::getFormURLWithID($rule['id']) : '',
                'rule_active'    => (bool) ($rule['is_active'] ?? false),
                // The rule assigns someone else than the current main tech lead
                'rule_outdated'  => $rule !== null && $main > 0 && (int) $rule['users_id'] !== $main,
            ];
        }
        usort($clients, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $clients;
    }

    /**
     * Generated rules, by client entity, with the technician they assign
     *
     * @return array<int, array{id: int, is_active: int, users_id: int}>
     */
    private static function getExistingRules(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => ['glpi_rules.id', 'glpi_rules.uuid', 'glpi_rules.is_active', 'glpi_ruleactions.value'],
            'FROM'      => 'glpi_rules',
            'LEFT JOIN' => [
                'glpi_ruleactions' => [
                    'ON' => [
                        'glpi_ruleactions' => 'rules_id',
                        'glpi_rules'       => 'id',
                        [
                            'AND' => ['glpi_ruleactions.field' => '_users_id_assign'],
                        ],
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_rules.sub_type' => RuleTicket::class,
                'glpi_rules.uuid'     => ['LIKE', self::UUID_PREFIX . '%'],
            ],
        ]);

        $rules = [];
        foreach ($iterator as $data) {
            $entities_id = (int) substr((string) $data['uuid'], strlen(self::UUID_PREFIX));
            $rules[$entities_id] = [
                'id'        => (int) $data['id'],
                'is_active' => (int) $data['is_active'],
                'users_id'  => (int) $data['value'],
            ];
        }
        return $rules;
    }

    /**
     * Create the missing assignment rules of every listed client having a main tech lead
     *
     * @return int number of rules created
     */
    public static function createMissingRules(): int
    {
        $created = 0;
        foreach (self::getClients($_SESSION['glpiactiveentities'] ?? []) as $client) {
            if ($client['rule_id'] === 0 && $client['techlead_id'] > 0 && self::createRule($client['entities_id'])) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Create the assignment rule of a client, unless it already exists. The client has to be
     * one of the listed ones (active customer with helpdesk users) and to have a main tech lead.
     *
     * @param int $entities_id client entity
     *
     * @return bool true when a rule was created
     */
    public static function createRule(int $entities_id): bool
    {
        $rules_entity = self::getRulesEntity();
        $requesttype  = self::getHelpdeskRequestType();
        if ($rules_entity <= 0 || $requesttype <= 0) {
            return false;
        }

        // Replay the population of the list rather than trusting the posted entity
        $client = null;
        foreach (self::getClients($_SESSION['glpiactiveentities'] ?? []) as $candidate) {
            if ($candidate['entities_id'] === $entities_id) {
                $client = $candidate;
                break;
            }
        }
        if ($client === null || $client['techlead_id'] <= 0 || $client['rule_id'] > 0) {
            return false;
        }

        $rule     = new RuleTicket();
        $rules_id = $rule->add([
            'name'         => sprintf(
                __('Main tech lead of %s', 'manageentities'),
                $client['name'],
            ),
            'description'  => __('Generated from the clients management portal', 'manageentities'),
            'entities_id'  => $rules_entity,
            'is_recursive' => 1,
            'is_active'    => 1,
            'match'        => Rule::AND_MATCHING,
            'condition'    => RuleCommonITILObject::ONADD,
            'uuid'         => self::UUID_PREFIX . $entities_id,
        ]);
        if (!$rules_id) {
            return false;
        }

        $criteria = new RuleCriteria();
        $criteria->add([
            'rules_id'  => $rules_id,
            'criteria'  => 'requesttypes_id',
            'condition' => Rule::PATTERN_IS,
            'pattern'   => $requesttype,
        ]);
        $criteria->add([
            'rules_id'  => $rules_id,
            'criteria'  => 'entities_id',
            'condition' => Rule::PATTERN_IS,
            'pattern'   => $entities_id,
        ]);
        // Leave the tickets someone already assigned alone
        $criteria->add([
            'rules_id'  => $rules_id,
            'criteria'  => '_users_id_assign',
            'condition' => Rule::PATTERN_DOES_NOT_EXISTS,
            'pattern'   => 1,
        ]);

        $action = new RuleAction();
        $action->add([
            'rules_id'    => $rules_id,
            'action_type' => 'assign',
            'field'       => '_users_id_assign',
            'value'       => $client['techlead_id'],
        ]);

        return true;
    }

    /**
     * Number of clients listed without an assignment rule, for the button of the tech leads tab
     *
     * @param array $clients result of getClients()
     *
     * @return int
     */
    public static function countMissingRules(array $clients): int
    {
        return count(array_filter(
            $clients,
            static fn(array $client): bool => $client['rule_id'] === 0 && $client['techlead_id'] > 0,
        ));
    }

    /**
     * Page listing the clients able to open tickets, their main tech lead and assignment rule
     *
     * @param array $entities active entities of the session
     *
     * @return void
     */
    public static function showList(array $entities): void
    {
        $clients = self::getClients($entities);

        TemplateRenderer::getInstance()->display('@manageentities/techlead/assignment_rules.html.twig', [
            'clients'       => $clients,
            'missing'       => self::countMissingRules($clients),
            'rules_entity'  => \Dropdown::getDropdownName('glpi_entities', self::getRulesEntity()),
            'has_config'    => self::getRulesEntity() > 0 && self::getHelpdeskRequestType() > 0,
            'requesttype'   => \Dropdown::getDropdownName('glpi_requesttypes', self::getHelpdeskRequestType()),
            'can_create'    => self::canCreate(),
            'action_url'    => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/techleadrule.php',
            'back_url'      => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
        ]);
    }
}
