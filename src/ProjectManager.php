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
 * Project managers of a client (entity): several users can be linked to a client, one of
 * them being flagged as the main one (is_default). Same model as the tech leads, the
 * population being the one of the business contacts (central users of the entity).
 */
class ProjectManager extends CommonDBTM
{
    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Project manager', 'Project managers', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-user-cog';
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
        // The picker only offers central users of the entity: the posted value is not bound
        // by it, so replay that population here
        if (!BusinessContact::isCentralUserOfEntity($input['users_id'], $input['entities_id'])) {
            return false;
        }
        // The first project manager of a client becomes its main one
        $input['is_default'] = countElementsInTable(
            self::getTable(),
            ['entities_id' => $input['entities_id']],
        ) === 0 ? 1 : 0;

        return $input;
    }

    public function post_purgeItem()
    {
        // Promote another project manager when the main one is removed, so a client keeps one
        if ($this->fields['is_default']) {
            $others = (new self())->find(['entities_id' => $this->fields['entities_id']], ['id'], 1);
            $other  = reset($others);
            if ($other) {
                (new self())->update(['id' => $other['id'], 'is_default' => 1]);
            }
        }
    }

    /**
     * Drop the project manager links of a user sent to the trash or purged
     *
     * @param \User $user
     *
     * @return void
     */
    public static function removeUserLinks(\User $user): void
    {
        // One by one, so that post_purgeItem() promotes another main project manager
        (new self())->deleteByCriteria(['users_id' => (int) $user->getID()], true);
    }

    /**
     * Flag a project manager as the main one of its client
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
     * Project managers of the given entities, main one first
     *
     * @param array  $entities
     * @param string $root_doc
     *
     * @return array
     */
    public function buildProjectManagersForTemplate(array $entities, string $root_doc): array
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

        $managers = [];
        foreach ($iterator as $data) {
            $managers[] = [
                'link_id'    => $data['link_id'],
                'url'        => $root_doc . '/front/user.form.php?id=' . $data['id'],
                'name'       => getUserName($data['id']),
                'phone'      => $data['phone'] ?? '',
                'mobile'     => $data['mobile'] ?? '',
                'email'      => $data['email'] ?? '',
                'is_default' => (bool) $data['is_default'],
            ];
        }
        return $managers;
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
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
