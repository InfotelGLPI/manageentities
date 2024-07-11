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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

use Glpi\Application\View\TemplateRenderer;
class PluginManageentitiesDirecthelpdesk extends CommonDBTM {

   static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Not billed interventions', 'manageentities');
    }

    /**
     * @return string
     */
    static function getIcon() {
        return "ti ti-file-euro";
    }

   static function loadModal() {

       echo "<form action='".self::getFormURL()."' method='post'>";
       echo "<div class='modal' tabindex='-1' id='directhelpdesk-modal'>";
       echo "<div class='modal-dialog'>";
       echo "<div class='modal-content'>";

       echo "<div class='modal-header'>";
       echo "<h5 class='modal-title'>";
       echo "<i class='fas fa-plus me-2'></i>";
       echo __('Add an unbilled intervention', 'manageentities');
       echo "</h5>";
       echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='".__('Close')."'></button>";
       echo "</div>";

       $name = "";
       echo "<div class='modal-body'>";
       echo "<table class='tab_cadre'>";

       echo "<tr class='tab_bg_1'>";
       echo "<td>" . Entity::getTypeName() . " <span class='red'>*</span></td>";
       echo "<td colspan='4'>";
       $opt = ['name' => 'entities_id'];
       Entity::dropdown($opt);
       echo "</td>";
       echo "</tr>";

       echo "<tr class='tab_bg_1'>";
       echo "<td>" . __('Title') . " <span class='red'>*</span></td>";
       echo "<td colspan='4'>";
       echo Html::input('name', ['size'  => 40]);
       echo "</td>";
       echo "</tr>";

       echo "<tr class='tab_bg_1'>";
       echo "<td>" . __('Description') . " <span class='red'>*</span></td>";
       echo "<td colspan='4'>";
       Html::textarea(['name'            => 'comment',
           'cols'            => '40',
           'rows'            => '10',
           'enable_ricktext' => false]);
       echo "</td>";
       echo "</tr>";

       echo "<tr class='tab_bg_1'>";
       echo "<td>";
       echo __("Date")." <span class='red'>*</span>";
       echo "</td>";
       echo "<td colspan='4'>";
       Html::showDateField("date", [
           'value'      => date("Y-m-d"),
           'maybeempty' => true,
           'canedit'    => true]);
       echo "</td>";
       echo "</tr>";

       echo "<tr class='tab_bg_1'><td>";
       echo "<i class='fas fa-stopwatch fa-fw me-1' title='".__('Duration')."'> </i><span class='red'>*</span></td>";
       echo "<td>";
       Dropdown::showTimeStamp("actiontime", [
           'min' => 0,
           'max' => 50 * HOUR_TIMESTAMP]);
       echo "</td>";
       echo "<td>";
       echo "<i class='fas fa-ticket-alt fa-fw me-1' title='".__('Linked ticket')."'></i></td>";
       echo "<td>";
       //TODO only opened tickets for selected entity
       $linkparam = [
           'name'        => 'tickets_id',
//           'rand'        => $rand,
//           'used'        => $excludedTicketIds,
           'displaywith' => ['id'],
           'display'     => false
       ];
       echo Ticket::dropdown($linkparam);
       echo "</td></tr>";

       echo "<tr class='tab_bg_1 center'><td colspan='4'>";
       echo Html::hidden('users_id', ['value' => Session::getLoginUserID()]);
       echo Html::submit(_sx('button', 'Post'), ['name' => 'add', 'class' => 'btn btn-primary']);
       echo "</td></tr>";

       echo "</table>";


       echo "</div>";

       echo "</div>";
       echo "</div>";
       echo "</div>";
       Html::closeForm();
   }

    function showForm($ID, $options = []) {

        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        return true;
    }

    static function showDashboard() {

        echo Html::script(PLUGIN_MANAGEENTITIES_NOTFULL_DIR . "/lib/echarts/echarts.js");
        echo Html::script(PLUGIN_MANAGEENTITIES_NOTFULL_DIR . "/lib/echarts/theme/azul.js");

        $direct = new PluginManageentitiesDirecthelpdesk();

        if ($items = $direct->find(['is_billed' => 0])) {

            echo "<table class='tab_cadre' style='width: 70%'>";
            echo "<tr class='tab_bg_1 center'>";
            echo "<th colspan='3'>";
            echo __('Dashboard', 'manageentities');
            echo "</th>";
            echo "</tr>";
            $entities = $_SESSION["glpiactiveentities"];
            $directs = [];
            foreach ($items as $item) {

                if (!in_array($item["entities_id"], $entities)) {
                    continue;
                }
                if (isset($directs[$item['entities_id']])) {
                    $directs[$item['entities_id']] += $item['actiontime'];
                } else {
                    $directs[$item['entities_id']] = $item['actiontime'];
                }
            }

            $rowCount = 0;
            $columnCount = 0;
            foreach ($directs as $entities_id => $actiontime) {
                $sum = 0;
                $datas = [];
                if ($columnCount % 3 == 0) {
                    if ($columnCount > 0) {
                        echo "</tr>";
                    }
                    echo "<tr class='tab_bg_1'>";
                    $rowCount++;
                }

                echo "<td class='center' style='border: #e9e9e9 5px solid;background: white;'>";

                $actiontime = ($actiontime * 0.5) / 14400;
                $sum += $actiontime;

                $entity = new Entity();
                $entity->getFromDB($entities_id);
                $name = $entity->getName();

                $datas[] = [
                    'value' => $sum,
                    'name' => $name
                ];

                $dataSet = json_encode($datas);
                $hours = _n('Hour', 'Hours', 2);

                echo "<div id='container$entities_id' style='min-height: 250px;width: 400px;'>";
                echo "</div>";
                if ($sum >= 0.5) {
                    echo "<button class='btn btn-danger' style='margin-bottom: 10px;margin-top: -20px;'>";
                    echo __('Create a ticket');
                    echo "</button>";
                }
                echo "<script type='text/javascript'>

                var dom = document.getElementById('container$entities_id');
                var myChart = echarts.init(dom, null, {
                    renderer: 'canvas',
                    useDirtyRect: false
                });
                var app = {};
                var hours = '$hours';
                var option;

                option = {

                    tooltip: {
                        formatter: '{a} <br/>{b} : {c}%'
                    },
                    series: [
                            {
                                type: 'gauge',
                                startAngle: 180,
                                endAngle: 0,
                                center: ['50%', '75%'],
                                radius: '90%',
                                min: 0,
                                max: 0.5,
                                splitNumber: 8,
                                axisLine: {
                                    lineStyle: {
                                        width: 6,
                                        color: [
                                            [0.25, '#279539'],
                                            [0.5, '#206bc4'],
                                            [0.75, '#e6e75f'],
                                            [1, '#f75454']
                                        ]
                                    }
                                },
                                pointer: {
                                    icon: 'path://M12.8,0.7l12,40.1H0.7L12.8,0.7z',
                                    length: '12%',
                                    width: 20,
                                    offsetCenter: [0, '-60%'],
                                    itemStyle: {
                                        color: 'auto'
                                    }
                                },
                                axisTick: {
                                    length: 12,
                                    lineStyle: {
                                        color: 'auto',
                                        width: 2
                                    }
                                },
                                splitLine: {
                                    length: 20,
                                    lineStyle: {
                                        color: 'auto',
                                        width: 5
                                    }
                                },
                                axisLabel: {
                                    color: '#464646',
                                    fontSize: 13,
                                    distance: -90,
                                    rotate: 'tangential',
                                    formatter: function (value) {
                                        if (value === 0.5) {
                                            return '4 ' + hours;
                                        } else if (value === 0.375) {
                                            return '3 ' + hours;
                                        } else if (value === 0.25) {
                                            return '2 ' + hours;
                                        } else if (value === 0.125) {
                                            return '1 ' + hours;
                                        }
                                    return '';
                                    }
                                },
                                title: {
                                    offsetCenter: [0, '25%'],
                                    fontSize: 12
                                },
                                detail: {
                                    fontSize: 16,
                                    offsetCenter: [0, '-35%'],
                                    valueAnimation: true,
                                    formatter: function (value) {
                                        if (value > 0.375 && value === 0.5) {
                                            return '4 ' + hours;
                                        } else if (value > 0.25 && value <= 0.375) {
                                            return '3 ' + hours;
                                        } else if (value > 0.125 && value <= 0.25) {
                                            return '2 ' + hours;
                                        } else if (value <= 0.125) {
                                            return '1 ' + hours;
                                        }
                                    },
                                    color: 'inherit'
                                },
                                data: $dataSet,
                            }
                        ]
                };

                if (option && typeof option === 'object') {
                    myChart.setOption(option);
                }

                window.addEventListener('resize', myChart.resize);

          </script>";
                echo "</td>";
                $columnCount++;
            }

            if ($columnCount % 3 != 0) {
                for ($i = 0; $i < (3 - $columnCount % 3); $i++) {
                    echo "<td></td>";
                }
                echo "</tr>";
            }

            echo "</table>";
        }
    }
    /**
     * @return array
     */
    function rawSearchOptions() {

        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(2)
        ];

        $tab[] = [
            'id'            => '1',
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'itemlink_type' => $this->getType(),
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => $this->getTable(),
            'field'    => 'date',
            'name'     => __('Date'),
            'datatype' => 'date',
        ];


        $tab[] = [
            'id'       => '8',
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'                 => '9',
            'table'              => $this->getTable(),
            'field'              => 'actiontime',
            'name'               => __('Duration'),
            'datatype'           => 'timestamp',
            'massiveaction'      => false
        ];

        $tab[] = [
            'id'       => '10',
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('User'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => '11',
            'table'    => $this->getTable(),
            'field'    => 'is_billed',
            'name'     => __('Is billed', 'manageentities'),
            'datatype' => 'bool',
        ];


        $tab[] = [
            'id'       => '30',
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => __('Entity'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'    => '81',
            'table' => 'glpi_entities',
            'field' => 'entities_id',
            'name'  => __('Entity') . "-" . __('ID'),
        ];

        return $tab;
    }

   //TODO Add mandatory fields on add
}
