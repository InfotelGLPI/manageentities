<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use CommonDropdown;
use DBConnection;
use Migration;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class SubscriptionLevel extends CommonDropdown
{

    static $rightname = 'plugin_manageentities';

    const TYPE_ALL         = 0;
    const TYPE_ON_PREMISE  = 1;
    const TYPE_CLOUD       = 2;

    static function getTypeName($nb = 0)
    {
        return _n('Subscription level', 'Subscription levels', $nb, 'manageentities');
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::HaveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    // This table has no entities_id column — prevent GLPI from adding an entity JOIN
    public function isEntityAssign(): bool
    {
        return false;
    }

    static function getTypes(): array
    {
        return [
            self::TYPE_ALL        => __('All'),
            self::TYPE_ON_PREMISE => __('On premise', 'manageentities'),
            self::TYPE_CLOUD      => __('Cloud', 'manageentities'),
        ];
    }

    function getAdditionalFields(): array
    {
        return [
            [
                'name'  => 'subscription_type',
                'label' => __('Subscription type', 'manageentities'),
                'type'  => 'subscription_type_select',
                'list'  => true,
            ],
        ];
    }

    function displaySpecificTypeField($ID, $field = [], array $options = []): void
    {
        if ($field['type'] === 'subscription_type_select') {
            $current = (int)($this->fields['subscription_type'] ?? self::TYPE_ALL);
            echo '<select name="subscription_type" class="form-select">';
            foreach (self::getTypes() as $val => $label) {
                $selected = ($val === $current) ? ' selected' : '';
                echo '<option value="' . $val . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
            }
            echo '</select>';
        }
    }

    function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();
        $tab[] = [
            'id'       => '10',
            'table'    => $this->getTable(),
            'field'    => 'subscription_type',
            'name'     => __('Subscription type', 'manageentities'),
            'datatype' => 'specific',
        ];
        return $tab;
    }

    /**
     * Return all levels as array indexed by id, with their subscription_type,
     * suitable for JSON output to the subscription wizard JS filter.
     */
    static function getAllForJS(): array
    {
        global $DB;
        $out = [];
        $iterator = $DB->request(['FROM' => self::getTable(), 'ORDER' => ['name ASC']]);
        foreach ($iterator as $row) {
            $out[] = [
                'id'   => (int)$row['id'],
                'name' => $row['name'],
                'type' => (int)$row['subscription_type'],
            ];
        }
        return $out;
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
                            `name`              varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
                            `comment`           text collate utf8mb4_unicode_ci,
                            `subscription_type` tinyint NOT NULL DEFAULT '0' COMMENT '0=all, 1=on_premise, 2=cloud',
                            PRIMARY KEY (`id`),
                            KEY `name` (`name`)
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
