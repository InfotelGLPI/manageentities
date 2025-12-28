<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use Ajax;
use CommonDBTM;
use CommonGLPI;
use DbUtils;
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
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
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
        global $CFG_GLPI;

        if ($ID > 0) {
            $this->check($ID, READ);
        } else {
            // Create item
            $options['plugin_manageentities_contractdays_id'] = $options['parent']->getField('id');
            $this->check(-1, UPDATE, $options);
        }

        $config = Config::getInstance();

        $data = $this->getItems($options['parent']->getField('id'));

        $used_critypes = [];
        if (!empty($data)) {
            foreach ($data as $field) {
                $used_critypes[] = $field['plugin_manageentities_critypes_id'];
            }
        }

        $this->showFormHeader($options);
        echo "<tr class='tab_bg_1'>";
        // Cri Type
        echo "<td>";
        echo CriType::getTypeName() . '&nbsp;';
        echo "</td>";
        echo "<td>";
        $rand = \Dropdown::show(CriType::class, [
            'name' => 'plugin_manageentities_critypes_id',
            'value' => $this->fields['plugin_manageentities_critypes_id'] ?? '',
            'entity' => $options['parent']->getField('entities_id'),
            'used' => $used_critypes,
            'on_change' => 'manageentities_loadSelectPrice();'
        ]);
        echo "<script type='text/javascript'>";
        echo "function manageentities_loadSelectPrice(){";
        Ajax::updateItemJsCode(
            'manageentities_loadPrice',
            PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/criprice.php",
            [
                'action' => 'loadPrice',
                'critypes_id' => '__VALUE__',
                'entities_id' => $options['parent']->getField('entities_id')
            ],
            'dropdown_plugin_manageentities_critypes_id' . $rand
        );
        echo "}";
        echo "</script>";
        echo "</td>";

        // Price
        echo "<td>";
        // Display for hourly or daily price title
        if ($config->fields['hourorday'] == Config::DAY) {
            echo __('Daily rate', 'manageentities');
        } elseif ($config->fields['hourorday'] == Config::HOUR) {
            echo __('Hourly rate', 'manageentities');
        }
        echo "</td>";
        echo "<td>";
        echo Html::input('price', ['value' => Html::formatNumber($this->fields["price"]), 'size' => 5]);
        echo "</td>";
        echo "</tr>";
        echo "<tr class='tab_bg_1'>";

        // Is default
        echo "<td>";
        echo __('Is default', 'manageentities') . '&nbsp;';
        echo "</td>";
        echo "<td>";
        \Dropdown::showYesNo('is_default', $this->fields['is_default'] ?? '');
        echo Html::hidden('plugin_manageentities_contractdays_id', ['value' => $options['parent']->getField('id')]);
        echo Html::hidden('entities_id', ['value' => $options['parent']->getField('entities_id')]);
        echo "</td>";

        // Select an existing criprice
        echo "<td>";
        echo __('Select an existing price', 'manageentities') . '&nbsp;';
        echo "</td>";
        echo "<td>";
        echo "<div id='manageentities_loadPrice'>";
        $this->showSelectPriceDropdown(
            $this->fields['plugin_manageentities_critypes_id'] ?? '',
            $options['parent']->getField('entities_id')
        );
        echo "</div>";
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);

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

        $canedit = $item->can($item->fields['id'] ?? '', UPDATE);

        $data = $this->getItems(0, $item->getField('id'));
        if (!empty($data) && $canedit) {
            echo "<div class='center'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_1'>";
            echo "<th>" . ContractDay::getTypeName() . "</th>";
            echo "<th>" . __('Entity') . "</th>";
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

        $canedit = $item->can($item->fields['id'] ?? '', UPDATE);

        $rand = mt_rand();

        $data = $this->getItems($item->getField('id'));

        if ($canedit) {
            echo "<div id='viewcriprice" . $item->fields['id'] ?? '' . "_$rand'></div>\n";
            self::getJSEdition(
                "viewcriprice" . $item->fields['id'] ?? '' . "_$rand",
                "viewAddCriprice" . $item->fields['id'] ?? '' . "_$rand",
                $this->getType(),
                -1,
                ContractDay::class,
                $item->fields['id'] ?? ''
            );
            echo "<div class='center firstbloc'>" .
                "<a class='submit btn btn-primary' href='javascript:viewAddCriprice" . $item->fields['id'] ?? '' . "_$rand();'>";
            echo __('Add a new price', 'manageentities') . "</a></div>\n";
        }

        if (!empty($data)) {
            $this->listItems($item->fields['id'] ?? '', $data, $canedit, $rand);
        }
    }


    /**
     * List items for contract days
     *
     * @param type $ID
     * @param type $data
     * @param type $canedit
     * @param type $rand
     *
     * @global type $CFG_GLPI
     *
     */
    public function listItems($ID, $data, $canedit, $rand)
    {
        global $CFG_GLPI;

        $config = Config::getInstance();

        echo "<div class='left'>";
        if ($canedit) {
            Html::openMassiveActionsForm('masscriprice' . $rand);
            $massiveactionparams = [
                'item' => __CLASS__,
                'container' =>
                    'masscriprice'  . $rand
            ];
            Html::showMassiveActions($massiveactionparams);
        }

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'>";
        echo "<th width='10'>";
        if ($canedit) {
            echo Html::getCheckAllAsCheckbox('masscriprice'  . $rand);
        }
        echo "</th>";
        if (Session::isMultiEntitiesMode()) {
            echo "<th>" . __('Entity') . "</th>";
        }

        //Intervention type only for daily
        echo "<th>" . __('Intervention type', 'manageentities') . "</th>";

        // Display for hourly or daily price title
        if ($config->fields['hourorday'] == Config::DAY) {
            echo "<th>" . __('Daily rate', 'manageentities') . "</th>";
        } elseif ($config->fields['hourorday'] == Config::HOUR) {
            echo "<th>" . __('Hourly rate', 'manageentities') . "</th>";
        }
        echo "<th>" . __('Is default', 'manageentities') . "</th>";
        echo "</tr>";

        foreach ($data as $field) {
            $onclick = ($canedit ? "style='cursor:pointer' onClick=\"viewEditCriprice" . $field['plugin_manageentities_contractdays_id'] . "_" .
                $field['id'] . "_$rand();\"" : '');

            echo "<tr class='tab_bg_2'>";
            echo "<td width='10'>";
            if ($canedit) {
                Html::showMassiveActionCheckBox(__CLASS__, $field['id']);
                self::getJSEdition(
                    "viewcriprice" . $ID . "_$rand",
                    "viewEditCriprice" . $field['plugin_manageentities_contractdays_id'] . "_" . $field["id"] . "_$rand",
                    $this->getType(),
                    $field["id"],
                    ContractDay::class,
                    $field["plugin_manageentities_contractdays_id"]
                );
            }
            echo "</td>";
            if (Session::isMultiEntitiesMode()) {
                echo "<td $onclick>" . \Dropdown::getDropdownName("glpi_entities", $field['entities_id']) . "</td>";
            }

            //Intervention type only for daily
            echo "<td $onclick>" . $field["critypes_name"] . "</td>";
            echo "<td $onclick>" . Html::formatNumber($field["price"], false) . "</td>";
            echo "<td $onclick>" . \Dropdown::getYesNo($field["is_default"]) . "</td>";

            echo "</tr>";
        }
        echo "</table>";
        if ($canedit) {
            $massiveactionparams = [
                'item' => __CLASS__,
                'ontop' => false,
                'container' => 'mass' . __CLASS__ . $rand
            ];
            Html::showMassiveActions($massiveactionparams);
            Html::closeForm();
        }
        echo "</div>";
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
                'id' => $this->fields['id'] ?? '',
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
