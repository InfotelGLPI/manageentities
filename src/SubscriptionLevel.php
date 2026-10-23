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
use Glpi\Application\View\TemplateRenderer;
use Migration;
use Session;

class SubscriptionLevel extends CommonDropdown
{
    public static $rightname = 'plugin_manageentities';

    public const TYPE_ALL         = 0;
    public const TYPE_ON_PREMISE  = 1;
    public const TYPE_CLOUD       = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Subscription level', 'Subscription levels', $nb, 'manageentities');
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::HaveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    // This table has no entities_id column — prevent GLPI from adding an entity JOIN
    public function isEntityAssign(): bool
    {
        return false;
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_ALL        => __('All'),
            self::TYPE_ON_PREMISE => __('On premise', 'manageentities'),
            self::TYPE_CLOUD      => __('Cloud', 'manageentities'),
        ];
    }

    public function getAdditionalFields(): array
    {
        return [
            [
                'name'  => 'subscription_type',
                'label' => __('Subscription type', 'manageentities'),
                'type'  => 'subscription_type_select',
                'list'  => true,
            ],
            [
                'name'        => 'price_per_user',
                'label'       => __('Monthly price per user excl. VAT', 'manageentities'),
                'type'        => 'price_per_user',
                'list'        => true,
                // Cloud levels only: the row follows the subscription type
                // (public/scripts/subscriptionlevel.js)
                'form_params' => [
                    'add_field_attribs' => ['data-me-cloud-only' => '1'],
                    'add_field_class'   => (int) ($this->fields['subscription_type'] ?? self::TYPE_ALL) === self::TYPE_CLOUD ? '' : 'd-none',
                ],
            ],
        ];
    }

    /**
     * Price of a cloud level, for a future annex dedicated to the cloud subscriptions: empty
     * for the other levels, French decimal comma accepted
     *
     * @param array|false $input
     *
     * @return array|false
     */
    private function preparePriceInput($input)
    {
        if (!is_array($input) || !array_key_exists('price_per_user', $input)) {
            return $input;
        }
        $type = (int) ($input['subscription_type'] ?? $this->fields['subscription_type'] ?? self::TYPE_ALL);
        $text = trim((string) $input['price_per_user']);
        if ($type !== self::TYPE_CLOUD || $text === '') {
            $input['price_per_user'] = null;
            return $input;
        }
        $price = PresalePricing::parseNumber($text);
        if ($price === null || $price < 0) {
            Session::addMessageAfterRedirect(__('The price per user must be a positive number.', 'manageentities'), false, ERROR);
            return false;
        }
        $input['price_per_user'] = round($price, 2);

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        return $this->preparePriceInput(parent::prepareInputForAdd($input));
    }

    public function prepareInputForUpdate($input)
    {
        return $this->preparePriceInput(parent::prepareInputForUpdate($input));
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = []): void
    {
        if ($field['type'] === 'subscription_type_select') {
            $current = (int) ($this->fields['subscription_type'] ?? self::TYPE_ALL);
            \Dropdown::showFromArray('subscription_type', self::getTypes(), [
                'value'     => $current,
                'on_change' => CriDetail::CHANGE_EVENT_JS,
            ]);
        } elseif ($field['type'] === 'price_per_user') {
            $price = $this->fields['price_per_user'] ?? null;
            TemplateRenderer::getInstance()->display('@manageentities/subscriptionlevel_price_field.html.twig', [
                'value' => $price !== null ? str_replace('.', ',', (string) (float) $price) : '',
            ]);
        }
    }

    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();
        $tab[] = [
            'id'       => '10',
            'table'    => $this->getTable(),
            'field'    => 'subscription_type',
            'name'     => __('Subscription type', 'manageentities'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => '11',
            'table'    => $this->getTable(),
            'field'    => 'price_per_user',
            'name'     => __('Monthly price per user excl. VAT', 'manageentities'),
            'datatype' => 'decimal',
        ];
        return $tab;
    }

    /**
     * Return all levels as array indexed by id, with their subscription_type,
     * suitable for JSON output to the subscription wizard JS filter.
     */
    public static function getAllForJS(): array
    {
        global $DB;
        $out = [];
        $iterator = $DB->request(['FROM' => self::getTable(), 'ORDER' => ['name ASC']]);
        foreach ($iterator as $row) {
            $out[] = [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'type' => (int) $row['subscription_type'],
                'price_per_user' => $row['price_per_user'] !== null ? (float) $row['price_per_user'] : null,
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
                            `price_per_user` decimal(20,2) DEFAULT NULL COMMENT 'monthly price per user excl. VAT, cloud levels',
                            PRIMARY KEY (`id`),
                            KEY `name` (`name`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Monthly price per user of the cloud levels (pre-sales, added after the first release)
        if (!$DB->fieldExists($table, 'price_per_user')) {
            $migration->addField($table, 'price_per_user', 'decimal(20,2) DEFAULT NULL', [
                'after'   => 'subscription_type',
                'comment' => 'monthly price per user excl. VAT, cloud levels',
            ]);
            $migration->migrationOneTable($table);
        }
    }


    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
