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
use CommonGLPI;
use DBConnection;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Config;
use Html;
use Migration;
use Session;

class CriPrice extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    /**
     * functions mandatory
     * getTypeName(), canCreate(), canView()
     * */
    public static function getTypeName($nb = 0)
    {
        $config = Config::getInstance();
        if ($config->fields['hourorday'] == Config::DAY) {
            $name = __('Daily rate', 'manageentities');
        } elseif ($config->fields['hourorday'] == Config::HOUR) {
            $name = __('Hourly rate', 'manageentities');
        }
        return $name;
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::HaveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    /**
     * Display tab for item
     *
     * @param CommonGLPI $item
     * @param int $withtemplate
     *
     * @return array|string
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate) {
            switch ($item->getType()) {
                case ContractDay::class:
                    $config = Config::getInstance();
                    if ($config->fields['hourorday'] == Config::DAY) {
                        $name = __('Daily rate', 'manageentities');
                    } elseif ($config->fields['hourorday'] == Config::HOUR) {
                        $name = __('Hourly rate', 'manageentities');
                    }

                    if ($_SESSION['glpishow_count_on_tabs']) {
                        $dbu = new DbUtils();
                        return self::createTabEntry(
                            $name,
                            $dbu->countElementsInTable(
                                $this->getTable(),
                                ["`plugin_manageentities_contractdays_id`" => $item->getID()],
                            ),
                        );
                    }

                    return $name;
                    break;
            }
        }
        return '';
    }

    /**
     * Display content for each users
     *
     * @static
     *
     * @param CommonGLPI $item
     * @param int $tabnum
     * @param int $withtemplate
     *
     * @return bool|true
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $criprice = new self();

        switch ($item->getType()) {
            case ContractDay::class:
                $criprice->showForContractDay($item);
                break;
        }
        return true;
    }

    /**
     * Print the contractday price form
     *
     * @param $ID        integer  ID of the item
     * @param $options   array    options used
     * */
    public function showForm($ID, $options = [])
    {
        if ($ID > 0) {
            $this->check($ID, READ);
        } else {
            $options['plugin_manageentities_contractdays_id'] = $options['parent']->getField('id');
            $this->check(-1, UPDATE, $options);
        }

        $config   = Config::getInstance();
        $is_day   = ($config->fields['hourorday'] == Config::DAY);

        $existing      = $this->getItems($options['parent']->getField('id'));
        $used_critypes = array_column($existing, 'plugin_manageentities_critypes_id');

        $this->initForm($ID, $options);

        $ajax_url = PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/criprice.php";

        TemplateRenderer::getInstance()->display('@manageentities/criprice_form.html.twig', [
            'item'               => $this,
            'params'             => $options,
            'is_day'             => $is_day,
            'used_critypes'      => $used_critypes,
            // Default to Yes for new items
            'is_default'         => $ID <= 0 ? 1 : $this->fields['is_default'],
            'select_prices'      => $this->getSelectPriceChoices(
                $this->fields['plugin_manageentities_critypes_id'],
                $options['parent']->getField('entities_id'),
            ),
            'change_event_js'    => CriDetail::CHANGE_EVENT_JS,
            'change_event_data'  => CriDetail::CHANGE_EVENT_DATA,
            'price'              => Html::formatNumber($this->fields['price']),
            'ajax_url'           => $ajax_url,
            'entities_id'        => $options['parent']->getField('entities_id'),
            'contractdays_id'    => $options['parent']->getField('id'),
            'price_label'        => $is_day ? __('Daily rate', 'manageentities') : __('Hourly rate', 'manageentities'),
        ]);

        return true;
    }

    /**
     * Show price selection for critype and entity
     *
     * @param $item
     *
     * @return boolean
     */
    public function showSelectPriceDropdown($critypes_id, $entities_id)
    {
        \Dropdown::showFromArray(
            'select_critype',
            $this->getSelectPriceChoices($critypes_id, $entities_id),
            ['add_data_attributes' => CriDetail::CHANGE_EVENT_DATA],
        );
    }

    /**
     * Prices already used by the entity for an intervention type, keyed by their raw value.
     *
     * @param mixed $critypes_id
     * @param mixed $entities_id
     *
     * @return array<int|string, string>
     */
    public function getSelectPriceChoices($critypes_id, $entities_id): array
    {
        $data = [\Dropdown::EMPTY_VALUE];
        if (!empty($critypes_id)) {
            $condition = [
                $this->getTable() . '.plugin_manageentities_critypes_id' => $critypes_id,
                $this->getTable() . '.entities_id' => $entities_id,
            ];
            $dataForEntity = $this->getItems(0, 0, $condition);
            if (!empty($dataForEntity)) {
                foreach ($dataForEntity as $val) {
                    $data[$val['price']] = Html::formatNumber($val['price']);
                }
            }
        }
        return $data;
    }

    /**
     * Show price for cri type
     *
     * @param $item
     *
     * @return boolean
     */
    public function showForCriType($item)
    {
        if (!$this->canView()) {
            return false;
        }
        if (!$this->canCreate()) {
            return false;
        }

        $canedit = $item->can($item->fields['id'], UPDATE);

        // The types of intervention are global: the rates listed are those of the entities of
        // the session only
        $data = $this->getItems(0, $item->getField('id'), getEntitiesRestrictCriteria(self::getTable()));
        if (!empty($data) && $canedit) {
            $rows = [];
            foreach ($data as $value) {
                $rows[] = [
                    'url'        => ContractDay::getFormURLWithID((int) $value['plugin_manageentities_contractdays_id']),
                    'contractday' => $value['contractdays_name'],
                    'entity'     => $value['entities_name'],
                    'price'      => Html::formatNumber($value["price"], true),
                ];
            }
            TemplateRenderer::getInstance()->display('@manageentities/criprice_for_critype.html.twig', [
                'contractday_label' => ContractDay::getTypeName(),
                'price_label'       => CriPrice::getTypeName(),
                'rows'              => $rows,
            ]);
        }
    }

    /**
     * Show price for contract days
     *
     * @param $item
     *
     * @return boolean
     */
    public function showForContractDay($item)
    {
        if (!$this->canView()) {
            return false;
        }
        if (!$this->canCreate()) {
            return false;
        }

        $canedit = $item->can($item->fields['id'], UPDATE);
        $rand    = mt_rand();
        $data    = $this->getItems($item->getField('id'));

        $this->listItems($item->fields['id'], $data, $canedit, $rand);
    }

    /**
     * List items for contract days (Twig datatable)
     */
    public function listItems($ID, $data, $canedit, $rand)
    {
        $config      = Config::getInstance();
        $is_day      = ($config->fields['hourorday'] == Config::DAY);
        $multi_entity = Session::isMultiEntitiesMode();

        // Massive actions capture
        $massive_form_open    = '';
        $massive_actions_top  = '';
        $massive_actions_bottom = '';
        $massive_form_close   = '';

        if ($canedit) {
            $massive_form_open      = Html::getOpenMassiveActionsForm('masscriprice' . $rand);
            $massive_actions_top    = Html::showMassiveActions([
                'item'      => __CLASS__,
                'container' => 'masscriprice' . $rand,
                'display'   => false,
            ]);
            $massive_actions_bottom = Html::showMassiveActions([
                'item'      => __CLASS__,
                'container' => 'masscriprice' . $rand,
                'ontop'     => false,
                'display'   => false,
            ]);

            $massive_form_close = '</form>';
        }

        // Build columns
        $columns = ['_checkbox' => ''];
        if ($multi_entity) {
            $columns['entities_name'] = _n('Entity', 'Entities', 1);
        }
        $columns['critypes_name'] = CriType::getTypeName(1);
        $columns['price']         = $is_day ? __('Daily rate', 'manageentities') : __('Hourly rate', 'manageentities');
        $columns['is_default']    = __('Is default', 'manageentities');

        $formatters = [
            '_checkbox' => 'raw_html',
            'price'     => 'raw_html',
            'is_default' => 'raw_html',
        ];

        // Build entries
        $entries = [];
        foreach ($data as $field) {
            $checkbox_html = '';
            if ($canedit) {
                $checkbox_html = Html::getMassiveActionCheckBox(__CLASS__, $field['id']);
            }

            $row = [
                'id'           => $field['id'],
                '_checkbox'    => $checkbox_html,
                'critypes_name' => $field['critypes_name'],
                'price'        => Html::formatNumber($field['price'], false),
                'is_default'   => \Dropdown::getYesNo($field['is_default']),
                'row_class'    => $canedit ? 'cursor-pointer' : '',
            ];

            if ($multi_entity) {
                $row['entities_name'] = \Dropdown::getDropdownName('glpi_entities', $field['entities_id']);
            }

            $entries[] = $row;
        }

        TemplateRenderer::getInstance()->display('@manageentities/criprice_list.html.twig', [
            'rand'                  => $rand,
            'can_edit'              => $canedit,
            'entries'               => $entries,
            'columns'               => $columns,
            'formatters'            => $formatters,
            'massive_form_open'     => $massive_form_open,
            'massive_actions_top'   => $massive_actions_top,
            'massive_actions_bottom' => $massive_actions_bottom,
            'massive_form_close'    => $massive_form_close,
            // Edition form loaded by public/scripts/criprice.js
            'editor'                => [
                'url'          => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/viewsubitem.php',
                'type'         => self::class,
                'parenttype'   => ContractDay::class,
                'parent_field' => ContractDay::getForeignKeyField(),
                'parents_id'   => (int) $ID,
            ],
        ]);
    }

    /**
     * get items
     *
     * @param int $contractdays_id
     * @param int $cri_types_id
     * @param array<mixed> $condition criteria added to the WHERE clause
     *
     * @return type
     * @global type $DB
     *
     */
    public function getItems($contractdays_id = 0, $cri_types_id = 0, $condition = [])
    {
        global $DB;

        $output = [];

        $criteria = [
            'SELECT' => [
                $this->getTable() . '.id',
                $this->getTable() . '.price',
                $this->getTable() . '.plugin_manageentities_contractdays_id',
                $this->getTable() . '.plugin_manageentities_critypes_id',
                $this->getTable() . '.entities_id',
                $this->getTable() . '.is_default',
                'glpi_plugin_manageentities_contractdays.name as contractdays_name',
                'glpi_entities.completename as entities_name',
                'glpi_plugin_manageentities_critypes.name as critypes_name',
            ],
            'FROM' => $this->getTable(),
            'INNER JOIN' => [
                'glpi_plugin_manageentities_critypes' => [
                    'ON' => [
                        $this->getTable() => 'plugin_manageentities_critypes_id',
                        'glpi_plugin_manageentities_critypes' => 'id',
                    ],
                ],
                'glpi_plugin_manageentities_contractdays' => [
                    'ON' => [
                        $this->getTable() => 'plugin_manageentities_contractdays_id',
                        'glpi_plugin_manageentities_contractdays' => 'id',
                    ],
                ],
                'glpi_entities' => [
                    'ON' => [
                        $this->getTable() => 'entities_id',
                        'glpi_entities' => 'id',
                    ],
                ],
            ],
            'WHERE' => [],
            'ORDERBY' => ['glpi_plugin_manageentities_critypes.name'],
        ];

        if ($contractdays_id > 0) {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                $this->getTable() . '.plugin_manageentities_contractdays_id' => $contractdays_id,
            ];
        }

        if ($cri_types_id > 0) {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                $this->getTable() . '.plugin_manageentities_critypes_id' => $cri_types_id,
            ];
        }

        if (count($condition) > 0) {
            $criteria['WHERE'] = $criteria['WHERE'] + $condition;
        }

        $iterator = $DB->request($criteria);

        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                $output[$data['id']] = $data;
            }
        }

        return $output;
    }

    /**
     * A single default rate per contract period: the others of the period of this row are no
     * longer the default one. Runs once the row is saved, so only after its input was checked.
     */
    private function demoteOtherDefaults(): void
    {
        $contractdays_id = (int) ($this->fields['plugin_manageentities_contractdays_id'] ?? 0);
        if ((int) ($this->fields['is_default'] ?? 0) !== 1 || $contractdays_id <= 0) {
            return;
        }
        $other = new self();
        foreach ($this->find([
            'plugin_manageentities_contractdays_id' => $contractdays_id,
            'is_default'                            => 1,
            ['NOT' => ['id' => $this->getID()]],
        ]) as $row) {
            $other->update(['id' => $row['id'], 'is_default' => 0]);
        }
    }

    public function prepareInputForUpdate($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        // A rate belongs to the period it was created for, in the entity of that period
        if (!Entity::isInternalWrite()) {
            unset($input['entities_id'], $input['plugin_manageentities_contractdays_id']);
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        // The contract period is the parent of the rate: it has to exist and be writable by the
        // session, and it gives the entity of the rate - never the request
        if (!Entity::isInternalWrite()) {
            $period          = new ContractDay();
            $contractdays_id = (int) ($input['plugin_manageentities_contractdays_id'] ?? 0);
            if ($contractdays_id <= 0 || !$period->can($contractdays_id, UPDATE)) {
                Session::addMessageAfterRedirect(
                    __s('The period of contract is not accessible.', 'manageentities'),
                    false,
                    ERROR,
                );
                return false;
            }
            $input['plugin_manageentities_contractdays_id'] = $contractdays_id;
            $input['entities_id']                           = (int) $period->fields['entities_id'];
        }

        return $input;
    }

    public function post_addItem()
    {
        $this->demoteOtherDefaults();
    }

    public function post_updateItem($history = true)
    {
        if (in_array('is_default', $this->updates, true)) {
            $this->demoteOtherDefaults();
        }
    }

    /**
     * Check mandatory field for showForm
     *
     * @param $input
     *
     * @return boolean
     */
    public function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $mandatory_fields = [
            'price' => self::getTypeName(),
            'plugin_manageentities_critypes_id' => CriType::getTypeName(),
        ];

        foreach ($input as $key => $value) {
            if (array_key_exists($key, $mandatory_fields)) {
                if ($value === null) {
                    $msg[$key] = $mandatory_fields[$key];
                    $checkKo = true;
                }
            }
        }

        if ($checkKo) {
            Session::addMessageAfterRedirect(
                sprintf(__("Mandatory fields are not filled. Please correct: %s"), implode(', ', $msg)),
                false,
                ERROR,
            );
            return false;
        }
        return true;
    }

    public function rawSearchOptions()
    {
        $tab[] = [
            'id' => '11',
            'table' => $this->getTable(),
            'field' => 'price',
            'name' => self::getTypeName(),
            'datatype' => 'decimal',
        ];

        $tab[] = [
            'id' => '12',
            'table' => 'glpi_plugin_manageentities_critypes',
            'field' => 'name',
            'name' => CriType::getTypeName(),
            'datatype' => 'dropdown',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id' => '13',
            'table' => $this->getTable(),
            'field' => 'is_default',
            'name' => __('Is default', 'manageentities'),
            'datatype' => 'bool',
            'massiveaction' => false,
        ];

        return $tab;
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
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `plugin_manageentities_critypes_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
                            `plugin_manageentities_contractdays_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
                            `price` decimal(20,4) NOT NULL DEFAULT '0.0000',
                            `is_default` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            KEY `entities_id` (`entities_id`),
                            KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`)
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
