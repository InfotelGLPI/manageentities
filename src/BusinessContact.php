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
use Migration;
use Session;
use User;

class BusinessContact extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

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
        $entities_id = (int) ($input['entities_id'] ?? -1);
        $users_id    = (int) ($input['users_id'] ?? 0);
        if (!Session::haveAccessToEntity($entities_id) || $users_id <= 0) {
            return false;
        }
        // The picker only offers users with a central profile covering the entity: the
        // posted value is not bound by it, so replay that population here
        if (!self::isCentralUserOfEntity($users_id, $entities_id)) {
            return false;
        }
        $input['entities_id'] = $entities_id;
        $input['users_id']    = $users_id;

        return $input;
    }

    /**
     * Whether a user belongs to the population offered by the business contact picker:
     * an active user holding a central profile covering the entity
     *
     * @param int $users_id
     * @param int $entities_id
     *
     * @return bool
     */
    public static function isCentralUserOfEntity(int $users_id, int $entities_id): bool
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
            ],
            'WHERE'      => [
                'glpi_users.id'         => $users_id,
                'glpi_users.is_deleted' => 0,
                'glpi_users.is_active'  => 1,
                'glpi_profiles.interface' => 'central',
            ] + getEntitiesRestrictCriteria('glpi_profiles_users', '', $entities_id, true),
        ])->current();

        return (int) ($result['cpt'] ?? 0) > 0;
    }

    public function buildBusinessForTemplate(array $instID, string $root_doc): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'   => [
                $this->getTable() . '.id as users_id',
                'glpi_users.*',
                'glpi_useremails.email',
            ],
            'FROM'     => $this->getTable(),
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        $this->getTable() => 'users_id',
                        'glpi_users'      => 'id',
                    ],
                ],
                'glpi_useremails' => [
                    'ON' => [
                        'glpi_useremails' => 'users_id',
                        'glpi_users'      => 'id',
                    ],
                ],
            ],
            'WHERE'   => [
                $this->getTable() . '.entities_id' => $instID,
            ],
            'GROUPBY' => $this->getTable() . '.users_id',
            'ORDERBY' => 'glpi_users.name',
        ]);

        $business = [];
        foreach ($iterator as $data) {
            $business[] = [
                'link_id'   => $data['users_id'],
                'url'       => $root_doc . '/front/user.form.php?id=' . $data['id'],
                'realname'  => $data['realname'] ?? '',
                'firstname' => $data['firstname'] ?? '',
                'phone'     => $data['phone'] ?? '',
                'phone2'    => $data['phone2'] ?? '',
                'mobile'    => $data['mobile'] ?? '',
                'email'     => $data['email'] ?? '',
            ];
        }
        return $business;
    }

    public function showBusiness($instID)
    {
        global $CFG_GLPI;

        $can_edit  = $this->canCreate();
        $is_single = count($instID) === 1;

        $entity_action_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php';

        $business = $this->buildBusinessForTemplate($instID, $CFG_GLPI['root_doc']);


        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/business_card.html.twig',
            [
                'entity_id'          => $_SESSION['glpiactive_entity'],
                'entity_action_url'  => $entity_action_url,
                'user_form_url'      => $CFG_GLPI['root_doc'] . '/front/user.form.php',
                'business'           => $business,
                'can_edit_business'  => $can_edit,
                'is_single'          => $is_single,
            ],
        );
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

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
