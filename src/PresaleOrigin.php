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

use CommonDropdown;
use DBConnection;
use Migration;
use Session;

/**
 * Origin of a pre-sales request (publisher, website, request of an existing customer...), set on
 * each proposal for the statistics. One origin can be the default of the proposals made for an
 * existing customer.
 */
class PresaleOrigin extends CommonDropdown
{
    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Origin of the request', 'Origins of the requests', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-route';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    // This table has no entities_id column — prevent GLPI from adding an entity JOIN
    public function isEntityAssign(): bool
    {
        return false;
    }

    public function getAdditionalFields()
    {
        return [
            [
                'name'  => 'is_active',
                'label' => __('Active'),
                'type'  => 'bool',
            ],
            [
                'name'  => 'is_customer_default',
                'label' => __('Default origin of the requests of existing customers', 'manageentities'),
                'type'  => 'bool',
            ],
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => '14',
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '15',
            'table'    => $this->getTable(),
            'field'    => 'is_customer_default',
            'name'     => __('Default origin of the requests of existing customers', 'manageentities'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    public function post_addItem()
    {
        $this->keepSingleCustomerDefault();
        parent::post_addItem();
    }

    public function post_updateItem($history = true)
    {
        $this->keepSingleCustomerDefault();
        parent::post_updateItem($history);
    }

    /** A single origin is the default of the existing customers */
    private function keepSingleCustomerDefault(): void
    {
        global $DB;

        if ((int) ($this->fields['is_customer_default'] ?? 0) === 1) {
            $DB->update(
                self::getTable(),
                ['is_customer_default' => 0],
                ['is_customer_default' => 1, 'NOT' => ['id' => $this->getID()]]
            );
        }
    }

    /**
     * Active origins, for the wizard; the current one is kept even when deactivated since
     *
     * @return array<int, string>
     */
    public static function getChoices(int $current = 0): array
    {
        $criteria = ['is_active' => 1];
        if ($current > 0) {
            $criteria = ['OR' => ['is_active' => 1, 'id' => $current]];
        }
        $choices = [];
        foreach ((new self())->find($criteria, ['name']) as $origin) {
            $choices[(int) $origin['id']] = (string) $origin['name'];
        }

        return $choices;
    }

    /** Default origin of the proposals made for an existing customer, 0 when none */
    public static function getCustomerDefault(): int
    {
        $origins = (new self())->find(['is_active' => 1, 'is_customer_default' => 1], ['id'], 1);

        return $origins === [] ? 0 : (int) array_key_first($origins);
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
                            `name` varchar(255) DEFAULT NULL,
                            `comment` text,
                            `is_active` tinyint NOT NULL DEFAULT '1',
                            `is_customer_default` tinyint NOT NULL DEFAULT '0' COMMENT 'default origin of the proposals for an existing customer',
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            KEY `name` (`name`),
                            KEY `is_active` (`is_active`),
                            KEY `is_customer_default` (`is_customer_default`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);

            // Starting list, editable in Setup > Dropdowns
            $origins = [
                [__('Publisher', 'manageentities'), 0],
                [__('Website', 'manageentities'), 0],
                [__('Request of an existing customer', 'manageentities'), 1],
            ];
            foreach ($origins as [$name, $is_customer_default]) {
                $DB->insert($table, [
                    'name'                => $name,
                    'is_active'           => 1,
                    'is_customer_default' => $is_customer_default,
                    'date_creation'       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
