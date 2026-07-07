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

use Ajax;
use CommonDBTM;
use CommonGLPI;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Config;
use Html;
use Session;
use Toolbox;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class CriPrice extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    /**
     * functions mandatory
     * getTypeName(), canCreate(), canView()
     * */
    static function getTypeName($nb = 0)
    {
        $config = Config::getInstance();
        if ($config->fields['hourorday'] == Config::DAY) {
            $name = __('Daily rate', 'manageentities');
        } elseif ($config->fields['hourorday'] == Config::HOUR) {
            $name = __('Hourly rate', 'manageentities');
        }
        return $name;
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::HaveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    static function getIcon()
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
    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate) {
            switch ($item->getType()) {
                case ContractDay::class :
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
                                ["`plugin_manageentities_contractdays_id`" => $item->getID()]
                            )
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
    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $criprice = new self();

        switch ($item->getType()) {
            case ContractDay::class :
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
    function showForm($ID, $options = [])
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

        // Capture CriType dropdown
        ob_start();
        $rand_critype = \Dropdown::show(CriType::class, [
            'name'      => 'plugin_manageentities_critypes_id',
            'value'     => $this->fields['plugin_manageentities_critypes_id'],
            'entity'    => $options['parent']->getField('entities_id'),
            'used'      => $used_critypes,
            'on_change' => 'manageentities_loadSelectPrice();'
        ]);
        $critype_html = ob_get_clean();

        // Capture is_default dropdown — default to Yes for new items
        ob_start();
        \Dropdown::showYesNo('is_default', $ID <= 0 ? 1 : $this->fields['is_default']);
        $is_default_html = ob_get_clean();

        // Capture existing price dropdown
        ob_start();
        $this->showSelectPriceDropdown(
            $this->fields['plugin_manageentities_critypes_id'],
            $options['parent']->getField('entities_id')
        );
        $select_price_html = ob_get_clean();

        $ajax_url = PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/criprice.php";

        TemplateRenderer::getInstance()->display('@manageentities/criprice_form.html.twig', [
            'item'               => $this,
            'params'             => $options,
            'is_day'             => $is_day,
            'critype_html'       => $critype_html,
            'rand_critype'       => $rand_critype,
            'is_default_html'    => $is_default_html,
            'select_price_html'  => $select_price_html,
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
     * @param type $item
     *
     * @return boolean
     */
    function showSelectPriceDropdown($critypes_id, $entities_id)
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
        \Dropdown::showFromArray('select_critype', $data, ['on_change' => "manageentities_loadPrice(this.value)"]);
    }

    /**
     * Show price for cri type
     *
     * @param type $item
     *
     * @return boolean
     */
    function showForCriType($item)
    {
        if (!$this->canView()) {
            return false;
        }
        if (!$this->canCreate()) {
            return false;
        }

        $canedit = $item->can($item->fields['id'], UPDATE);

        $data = $this->getItems(0, $item->getField('id'));
        if (!empty($data) && $canedit) {
            echo "<div class='center'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_1'>";
            echo "<th>" . ContractDay::getTypeName() . "</th>";
            echo "<th>" . _n('Entity', 'Entities', 1) . "</th>";
            echo "<th>" . CriPrice::getTypeName() . "</th>";
            echo "</tr>";
            foreach ($data as $value) {
                echo "<tr class='tab_bg_2'>";
                echo "<td><a href='" . Toolbox::getItemTypeFormURL(
                        Contractday::class
                    ) . "?id=" . $value['plugin_manageentities_contractdays_id'] . "'>" . $value['contractdays_name'] . "</a></td>";
                echo "<td>" . $value['entities_name'] . "</td>";
                echo "<td>" . Html::formatNumber($value["price"], true) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
        }
    }


    /**
     * Show price for contract days
     *
     * @param type $item
     *
     * @return boolean
     */
    function showForContractDay($item)
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

        // Capture add button (inline AJAX edition)
        $add_button_html = '';
        if ($canedit) {
            ob_start();
            echo "<div id='viewcriprice" . $item->fields['id'] . "_$rand'></div>\n";
            self::getJSEdition(
                "viewcriprice" . $item->fields['id'] . "_$rand",
                "viewAddCriprice" . $item->fields['id'] . "_$rand",
                $this->getType(),
                -1,
                ContractDay::class,
                $item->fields['id']
            );
            echo "<a class='btn btn-primary' href='javascript:viewAddCriprice" . $item->fields['id'] . "_$rand();'>";
            echo __('Add a new price', 'manageentities') . "</a>\n";
            $add_button_html = ob_get_clean();
        }

        $this->listItems($item->fields['id'], $data, $canedit, $rand, $add_button_html);
    }


    /**
     * List items for contract days (Twig datatable)
     */
    public function listItems($ID, $data, $canedit, $rand, $add_button_html = '')
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
            ob_start();
            Html::openMassiveActionsForm('masscriprice' . $rand);
            $massive_form_open = ob_get_clean();

            ob_start();
            Html::showMassiveActions(['item' => __CLASS__, 'container' => 'masscriprice' . $rand]);
            $massive_actions_top = ob_get_clean();

            ob_start();
            Html::showMassiveActions(['item' => __CLASS__, 'container' => 'masscriprice' . $rand, 'ontop' => false]);
            $massive_actions_bottom = ob_get_clean();

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
                ob_start();
                Html::showMassiveActionCheckBox(__CLASS__, $field['id']);
                self::getJSEdition(
                    "viewcriprice" . $ID . "_$rand",
                    "viewEditCriprice" . $field['plugin_manageentities_contractdays_id'] . "_" . $field['id'] . "_$rand",
                    $this->getType(),
                    $field['id'],
                    ContractDay::class,
                    $field['plugin_manageentities_contractdays_id']
                );
                $checkbox_html = ob_get_clean();
            }

            $row = [
                '_checkbox'    => $checkbox_html,
                'critypes_name' => $field['critypes_name'],
                'price'        => Html::formatNumber($field['price'], false),
                'is_default'   => \Dropdown::getYesNo($field['is_default']),
                'row_class'    => $canedit ? 'cursor-pointer' : '',
            ];

            if ($canedit) {
                $row['row_onclick'] = "viewEditCriprice" . $field['plugin_manageentities_contractdays_id']
                    . "_" . $field['id'] . "_$rand();";
            }

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
            'add_button_html'       => $add_button_html,
        ]);
    }

    /**
     * get items
     *
     * @param int $contractdays_id
     * @param int $cri_types_id
     * @param string $condition
     *
     * @return type
     * @global type $DB
     *
     */
    function getItems($contractdays_id = 0, $cri_types_id = 0, $condition = [])
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
                        'glpi_plugin_manageentities_critypes' => 'id'
                    ]
                ],
                'glpi_plugin_manageentities_contractdays' => [
                    'ON' => [
                        $this->getTable() => 'plugin_manageentities_contractdays_id',
                        'glpi_plugin_manageentities_contractdays' => 'id'
                    ]
                ],
                'glpi_entities' => [
                    'ON' => [
                        $this->getTable() => 'entities_id',
                        'glpi_entities' => 'id'
                    ]
                ]
            ],
            'WHERE' => [],
            'ORDERBY' => ['glpi_plugin_manageentities_critypes.name'],
        ];

        if ($contractdays_id > 0) {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                    $this->getTable() . '.plugin_manageentities_contractdays_id' => $contractdays_id
                ];
        }

        if ($cri_types_id > 0) {
            $criteria['WHERE'] = $criteria['WHERE'] + [
                    $this->getTable() . '.plugin_manageentities_critypes_id' => $cri_types_id
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
     * Add cri price
     *
     * @param type $values
     */
    function addCriPrice($values)
    {
        if ($this->getFromDBByCrit([
            'plugin_manageentities_critypes_id' => $values["plugin_manageentities_critypes_id"],
            'entities_id' => $values["entities_id"]
        ])) {
            $this->update([
                'id' => $this->fields['id'],
                'price' => $values["price"]
            ]);
        } else {
            $this->add([
                'plugin_manageentities_critypes_id' => $values["plugin_manageentities_critypes_id"],
                'price' => $values["price"]
            ]);
        }
    }

    /**
     * Set default price for contract days
     *
     * @param type $input
     *
     * @return type
     * @global type $DB
     *
     */
    function setDefault($input)
    {
        if (isset($input['is_default']) && $input['is_default']) {
            $data = $this->getItems($input['plugin_manageentities_contractdays_id']);
            $items_id = array_keys($data);

            if (isset($input['id'])) {
                foreach ($items_id as $key => $val) {
                    if ($input['id'] == $val) {
                        unset($items_id[$key]);
                    }
                }
            }

            foreach ($items_id as $key => $val) {
                $this->update(['is_default' => 0, 'id' => $val]);
            }
        }

        return $input;
    }

    /**
     * Manage AJAX showForm display
     *
     * @param type $toupdate
     * @param type $function_name
     * @param type $itemtype
     * @param type $items_id
     * @param type $parenttype
     * @param type $parents_id
     *
     * @global type $CFG_GLPI
     *
     */
    static function getJSEdition($toupdate, $function_name, $itemtype, $items_id, $parenttype, $parents_id)
    {
        global $CFG_GLPI;

        $dbu = new DbUtils();
        $parent = $dbu->getItemForItemtype($parenttype);

        echo "\n<script type='text/javascript' >\n";
        echo "function $function_name() {\n";
        $params = [
            'type' => $itemtype,
            'parenttype' => $parenttype,
            $parent->getForeignKeyField() => $parents_id,
            'id' => $items_id
        ];
        Ajax::updateItemJsCode(
            $toupdate,
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/viewsubitem.php",
            $params
        );
        echo "};";
        echo "</script>\n";
    }

    function prepareInputForUpdate($input)
    {//si un document lié ne pas permettre l'update via le form self::showForTicket($item);
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        $this->setDefault($input);

        return $input;
    }


    function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        $this->setDefault($input);

        return $input;
    }


    /**
     * Check mandatory field for showForm
     *
     * @param type $input
     *
     * @return boolean
     */
    function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $mandatory_fields = [
            'price' => self::getTypeName(),
            'plugin_manageentities_critypes_id' => CriType::getTypeName()
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
                ERROR
            );
            return false;
        }
        return true;
    }

    function rawSearchOptions()
    {
        $tab[] = [
            'id' => '11',
            'table' => $this->getTable(),
            'field' => 'price',
            'name' => self::getTypeName(),
            'datatype' => 'decimal'
        ];

        $tab[] = [
            'id' => '12',
            'table' => 'glpi_plugin_manageentities_critypes',
            'field' => 'name',
            'name' => CriType::getTypeName(),
            'datatype' => 'dropdown',
            'massiveaction' => false
        ];

        $tab[] = [
            'id' => '13',
            'table' => $this->getTable(),
            'field' => 'is_default',
            'name' => __('Is default', 'manageentities'),
            'datatype' => 'bool',
            'massiveaction' => false
        ];

        return $tab;
    }
}
