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
use Migration;
use Session;

/**
 * Sales representatives and project managers offered by the pre-sales wizard (configuration).
 *
 * Only central users of the customers entity (wizard_default_entities_id) can be declared:
 * once the proposal is won, the chosen ones become the business contact and the project
 * manager of the new customer, which requires that same population.
 */
class PresaleUser extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public const TYPE_SALES           = 1;
    public const TYPE_PROJECT_MANAGER = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Pre-sales user', 'Pre-sales users', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-users';
    }

    /**
     * Managed from the configuration: same rights as the configuration form
     */
    public static function canView(): bool
    {
        return Session::haveRight(Contract::$rightname, UPDATE)
            && Session::haveRight(\Config::$rightname, UPDATE);
    }

    public static function canCreate(): bool
    {
        return self::canView();
    }

    public static function canUpdate(): bool
    {
        return self::canView();
    }

    public static function canDelete(): bool
    {
        return self::canView();
    }

    public static function canPurge(): bool
    {
        return self::canView();
    }

    /**
     * @return array<int, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_SALES           => __('Sales representative', 'manageentities'),
            self::TYPE_PROJECT_MANAGER => _n('Project manager', 'Project managers', 1, 'manageentities'),
        ];
    }

    /**
     * Entity the users have to hold a central profile on: the customers entity, or the root
     * entity when it is not configured yet
     *
     * @return int
     */
    public static function getReferenceEntity(): int
    {
        return (int) (Config::getInstance()->fields['wizard_default_entities_id'] ?? 0);
    }

    public function prepareInputForAdd($input)
    {
        $input['users_id'] = (int) ($input['users_id'] ?? 0);
        $input['type']     = (int) ($input['type'] ?? 0);
        if ($input['users_id'] <= 0 || !isset(self::getTypes()[$input['type']])) {
            return false;
        }
        // The picker only offers central users of the customers entity: replay it here
        if (!BusinessContact::isCentralUserOfEntity($input['users_id'], self::getReferenceEntity())) {
            Session::addMessageAfterRedirect(
                __('The user must hold a central profile on the customers entity.', 'manageentities'),
                false,
                ERROR,
            );
            return false;
        }
        if (countElementsInTable(self::getTable(), ['users_id' => $input['users_id'], 'type' => $input['type']]) > 0) {
            return false;
        }

        return $this->prepareCommonInput($input) + [
            // The first one of its type becomes the default one
            'is_default' => countElementsInTable(self::getTable(), ['type' => $input['type']]) === 0 ? 1 : 0,
        ];
    }

    public function prepareInputForUpdate($input)
    {
        // The user and the type identify the link: only the printed details change
        unset($input['users_id'], $input['type'], $input['is_default']);

        return $this->prepareCommonInput($input);
    }

    /**
     * @param array $input
     *
     * @return array
     */
    private function prepareCommonInput(array $input): array
    {
        foreach (['role_label', 'phone'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = trim((string) $input[$field]);
            }
        }

        return $input;
    }

    public function post_purgeItem()
    {
        // Keep a default one per type when the default one is removed
        if ($this->fields['is_default']) {
            $others = (new self())->find(['type' => $this->fields['type']], ['id'], 1);
            $other  = reset($others);
            if ($other) {
                (new self())->setAsDefault((int) $other['id']);
            }
        }
    }

    /**
     * Flag a user as the default one of its type
     *
     * @param int $id
     *
     * @return void
     */
    public function setAsDefault(int $id): void
    {
        global $DB;

        if (!$this->getFromDB($id)) {
            return;
        }
        // Direct writes: prepareInputForUpdate() keeps is_default out of the regular updates
        $DB->update(self::getTable(), ['is_default' => 0], ['type' => $this->fields['type']]);
        $DB->update(self::getTable(), ['is_default' => 1], ['id' => $id]);
    }

    /**
     * Drop the links of a user sent to the trash or purged
     *
     * @param \User $user
     *
     * @return void
     */
    public static function removeUserLinks(\User $user): void
    {
        // One by one, so that post_purgeItem() promotes another default one
        (new self())->deleteByCriteria(['users_id' => (int) $user->getID()], true);
    }

    /**
     * Declared users of a type, default one first, with the details printed in the proposal.
     * The role and the phone typed in the configuration win; else the title of the user, and its
     * mobile phone (its phone when it has none).
     *
     * @param int $type
     *
     * @return array<int, array{id: int, users_id: int, name: string, role_label: string,
     *                          phone: string, email: string, is_default: bool,
     *                          typed_role: string, typed_phone: string, user_role: string,
     *                          user_phone: string, url: string}>
     */
    public static function getUsers(int $type): array
    {
        global $DB;

        $table    = self::getTable();
        $iterator = $DB->request([
            'SELECT'     => [
                $table . '.id',
                $table . '.users_id',
                $table . '.role_label',
                $table . '.phone AS presale_phone',
                $table . '.is_default',
                'glpi_users.phone',
                'glpi_users.mobile',
                'glpi_usertitles.name AS title',
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
                'glpi_usertitles' => [
                    'ON' => [
                        'glpi_usertitles' => 'id',
                        'glpi_users'      => 'usertitles_id',
                    ],
                ],
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
                $table . '.type'        => $type,
                'glpi_users.is_deleted' => 0,
                'glpi_users.is_active'  => 1,
            ],
            'ORDERBY'    => [$table . '.is_default DESC', 'glpi_users.realname', 'glpi_users.firstname'],
        ]);

        $users = [];
        foreach ($iterator as $data) {
            $typed_role  = (string) ($data['role_label'] ?? '');
            $typed_phone = (string) ($data['presale_phone'] ?? '');
            $user_role   = (string) ($data['title'] ?? '');
            $user_phone  = (string) (($data['mobile'] ?? '') !== '' ? $data['mobile'] : ($data['phone'] ?? ''));
            $users[] = [
                'id'          => (int) $data['id'],
                'users_id'    => (int) $data['users_id'],
                'name'        => getUserName((int) $data['users_id']),
                // Printed values: typed in the configuration, else the ones of the user
                'role_label'  => $typed_role !== '' ? $typed_role : $user_role,
                'phone'       => $typed_phone !== '' ? $typed_phone : $user_phone,
                'email'       => (string) ($data['email'] ?? ''),
                'is_default'  => (bool) $data['is_default'],
                // For the configuration form: what is typed, and what the user gives when not
                'typed_role'  => $typed_role,
                'typed_phone' => $typed_phone,
                'user_role'   => $user_role,
                'user_phone'  => $user_phone,
                'url'         => \User::getFormURLWithID((int) $data['users_id']),
            ];
        }
        return $users;
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
                            `type` tinyint NOT NULL DEFAULT '1' COMMENT '1=sales representative, 2=project manager',
                            `role_label` varchar(255) DEFAULT NULL COMMENT 'role printed in the proposal',
                            `phone` varchar(255) DEFAULT NULL COMMENT 'phone printed in the proposal, user phone when empty',
                            `is_default` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `unicity` (`users_id`,`type`),
                            KEY `type` (`type`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
