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

class PluginManageentitiesDirecthelpdesk extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Not billed intervention', 'Not billed interventions', $nb, 'manageentities');
    }

    /**
     * @param array $options
     *
     * @return array
     */
    function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }

    /**
     * @return string
     */
    static function getIcon()
    {
        return "ti ti-file-euro";
    }

    static function UpdateBilledInterventions($item)
    {
        global $DB;

//        $item->input['_created_from_directhelpdesk'] = true;
//        Toolbox::logInfo($item);
//die();
        return true;
    }

    /**
     * @return string form HTML
     */
    static function loadModal()
    {
        $form = "<form action='" . self::getFormURL() . "' method='post'>";
        $form .= "<div class='modal' tabindex='-1' id='directhelpdesk-modal'>";
        $form .= "<div class='modal-dialog'>";
        $form .= "<div class='modal-content'>";

        $form .= "<div class='modal-header'>";
        $form .= "<h5 class='modal-title'>";
        $form .= "<i class='ti ti-file-euro me-2'></i>";
        $form .= __('Add an unbilled intervention', 'manageentities');
        $form .= "</h5>";
        $form .= "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='" . __(
                'Close'
            ) . "'></button>";
        $form .= "</div>";

        $name = "";
        $form .= "<div class='modal-body'>";
        $form .= "<table class='tab_cadre'>";

        $form .= "<tr class='tab_bg_1'>";
        $form .= "<td>" . Entity::getTypeName() . " <span class='red'>*</span></td>";
        $form .= "<td>";
        $opt = [
            'name' => 'entities_id',
            'display' => false
        ];
//       $opt['on_change'] = 'this.form.submit()';

        $form .= Entity::dropdown($opt);
        $form .= "</td>";
        $form .= "</tr>";

        $form .= "<tr class='tab_bg_1'>";
        $form .= "<td>" . __('Title') . " <span class='red'>*</span></td>";
        $form .= "<td>";

        $opt = [
            'name' => 'name',
            'display' => false
        ];
        $conditions = [
            'OR' => [
                'is_incident' => 1,
                'is_request' => 1,
            ]
        ];
        $opt['condition'] = $conditions;
//       $opt['entity'] = $options["entities_id"];
        $form .= ITILCategory::dropdown($opt);
//       $form .= Html::input('name', ['size'  => 40]);
        $form .= "</td>";
        $form .= "</tr>";

        $form .= "<tr class='tab_bg_1'>";
        $form .= "<td>" . __('Description') . " </td>";
        $form .= "<td colspan='4'>";
        $form .= Html::textarea([
            'name' => 'comment',
            'cols' => '40',
            'rows' => '10',
            'enable_ricktext' => false,
            'display' => false
        ]);
        $form .= "</td>";
        $form .= "</tr>";

        $form .= "<tr class='tab_bg_1'>";
        $form .= "<td>";
        $form .= __("Date") . " <span class='red'>*</span>";
        $form .= "</td>";
        $form .= "<td>";
        $form .= Html::showDateField("date", [
            'value' => date("Y-m-d"),
            'maybeempty' => true,
            'canedit' => true,
            'display' => false
        ]);
        $form .= "</td>";
        $form .= "</tr>";

        $form .= "<tr class='tab_bg_1'><td>";
        $form .= "<i class='fas fa-stopwatch fa-fw me-1' title='" . __(
                'Duration'
            ) . "'> </i><span class='red'>*</span></td>";
        $form .= "<td>";
        $form .= Dropdown::showTimeStamp("actiontime", [
            'min' => 0,
            'max' => 50 * HOUR_TIMESTAMP,
            'display' => false
        ]);
        $form .= "</td>";

        $form .= "</tr>";

        $form .= "<tr class='tab_bg_1'><td>";
        $form .= "<i class='fas fa-ticket-alt fa-fw me-1' title='" . __('Linked ticket') . "'></i></td>";
        $form .= "<td>";
        //TODO only opened tickets for selected entity
        $linkparam = [
            'name' => 'tickets_id',
//           'rand'        => $rand,
//           'used'        => $excludedTicketIds,
            'displaywith' => ['id'],
            'display' => false
        ];
        $form .= Ticket::dropdown($linkparam);
        $form .= "</td></tr>";

        $form .= "<tr class='tab_bg_1 center'><td>";
        $form .= Html::hidden('users_id', ['value' => Session::getLoginUserID()]);
        $form .= Html::submit(_sx('button', 'Post'), ['name' => 'add', 'class' => 'btn btn-primary']);
        $form .= "</td></tr>";

        $form .= "</table>";


        $form .= "</div>";

        $form .= "</div>";
        $form .= "</div>";
        $form .= "</div>";
        $form .= Html::closeForm(false);

        return $form;
    }

    function prepareInputForAdd($input)
    {
        if (!$this->checkMandatoryFields($input)) {
            return false;
        }

        if (isset($input['name']) && $input['name'] > 0) {
            $cat = new ITILCategory();
            $cat->getFromDB($input['name']);
            $input['name'] = $cat->getName();
        }

        return $input;
    }

    function post_addItem()
    {
        if (isset($this->input["tickets_id"])) {
            $ticket = new PluginManageentitiesDirecthelpdesk_Ticket();
            $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
            $input['tickets_id'] = $this->input["tickets_id"];
            $ticket->add($input);
        }
    }

    function post_updateItem($history = true)
    {
        if (isset($this->input["tickets_id"])) {
            $ticket = new PluginManageentitiesDirecthelpdesk_Ticket();

            if ($ticket->getFromDBByCrit(['plugin_manageentities_directhelpdesks_id' => $this->getID()])) {
                $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
                $ticket->deleteByCriteria($input);
            }

            if ($this->input["tickets_id"] > 0
                && !$ticket->getFromDBByCrit(['plugin_manageentities_directhelpdesks_id' => $this->getID()])) {
                $input['plugin_manageentities_directhelpdesks_id'] = $this->getID();
                $input['tickets_id'] = $this->input["tickets_id"];
                $ticket->add($input);
            }
        }
    }

    /**
     * checkMandatoryFields
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
            'name' => __('Title'),
            'date' => __('Date'),
            'actiontime' => __('Duration')
        ];

        foreach ($input as $key => $value) {
            if (array_key_exists($key, $mandatory_fields)) {
                if (empty($value) || $value == 'NULL') {
                    $msg[] = $mandatory_fields[$key];
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

        if (isset($this->input['entities_id']) && $this->input['entities_id'] == 0) {
            Session::addMessageAfterRedirect(
                __('You cannot add an intervention on this entity', 'manageentities'),
                false,
                ERROR
            );
            return false;
        }

        return true;
    }

    function showForm($ID, $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_form.html.twig', [
            'item' => $this,
            'params' => $options,
        ]);

        return true;
    }

    static function showDashboard()
    {
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
            $techs = [];
            foreach ($items as $item) {
                if (!in_array($item["entities_id"], $entities)) {
                    continue;
                }
                if (isset($directs[$item['entities_id']])) {
                    $directs[$item['entities_id']] += $item['actiontime'];
                    if (!in_array($item['users_id'], $techs[$item['entities_id']])) {
                        $techs[$item['entities_id']][] = $item['users_id'];
                    }
                } else {
                    $directs[$item['entities_id']] = $item['actiontime'];
                    $techs[$item['entities_id']][] = $item['users_id'];
                }
            }
            arsort($directs);

            $columnCount = 0;
            foreach ($directs as $entities_id => $actiontime) {
                $sum = 0;
                $datas = [];
                $tech_interventions = [];
                if ($columnCount % 3 == 0) {
                    if ($columnCount > 0) {
                        echo "</tr>";
                    }
                    echo "<tr class='tab_bg_1'>";
                }

                echo "<td class='center' style='border: #e9e9e9 5px solid;background: white;'>";

                $actiontime = ($actiontime * 0.5) / 14400;
                $sum += $actiontime;
                if (is_array($techs[$entities_id]) && count($techs[$entities_id])) {
                    $tech_interventions = $techs[$entities_id];
                }

                $entity = new Entity();
                $entity->getFromDB($entities_id);
                $name = $entity->getName();

                $datas[] = [
                    'value' => $sum,
//                    'name' => $name
                ];

                $dataSet = json_encode($datas);
                $hour = lcfirst(_n('Hour', 'Hours', 1));
                $hours = lcfirst(_n('Hour', 'Hours', 2));
                echo "<div style='margin-bottom: -50px;'>";
                echo $name;
                echo "</div>";
                echo "<div id='container$entities_id' style='min-height: 230px;width: 400px;'>";

                echo "</div>";
                if ($sum >= 0.4) {
                    echo "<div style='margin-bottom: 10px;margin-left:-20px;'>";
                    echo "<a href=\"#\" data-bs-toggle='modal' class='btn btn btn-danger' data-bs-target='#createticket$entities_id'>".__('Create a ticket')."</a>";
                    echo Ajax::createIframeModalWindow('createticket' . $entities_id,
                        PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/directhelpdesk.php?action=createticket&entities_id=" . $entities_id,
                        ['title' =>__('Create a ticket'),
                            'display' => false]);

//
//                    Html::showSimpleForm(
//                        PLUGIN_MANAGEENTITIES_WEBDIR . '/front/directhelpdesk.form.php',
//                        'create_ticket',
//                        __('Create a ticket'),
//                        ['entities_id' => $entities_id],
//                        '',
//                        "class='btn btn btn-danger'",
//                        __(
//                            'Tag billed interventions and create a ticket ? this action is irreversible',
//                            'manageentities'
//                        )
//                    );
                    echo "</div>";
                }
                echo "<script type='text/javascript'>

                var dom = document.getElementById('container$entities_id');
                var myChart = echarts.init(dom, null, {
                    renderer: 'canvas',
                    useDirtyRect: false
                });
                var app = {};
                var hours = '$hours';
                var hour = '$hour';
                var option;

                option = {
height: '100%',
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
                                    distance: -30,
                                    rotate: 'tangential',
                                    formatter: function (value) {
//                                        if (value === 0.5) {
//                                            return '4 ' + hours;
//                                        } else if (value === 0.375) {
//                                            return '3 ' + hours;
//                                        } else if (value === 0.25) {
//                                            return '2 ' + hours;
//                                        } else if (value === 0.125) {
//                                            return '1 ' + hour;
//                                        }
                                    return '';
                                    }
                                },
                                title: {
                                    offsetCenter: [0, '25%'],
                                    fontSize: 12
                                },
                                detail: {
                                    fontSize: 16,
                                    offsetCenter: [0, '-5%'],
                                    valueAnimation: true,
                                    formatter: function (value) {
                                        if (value > 0.375 && value < 0.5) {
                                            return '< 4 ' + hours;
                                        } else if (value > 0.25 && value <= 0.375) {
                                            return '>= 3 ' + hours;
                                        } else if (value > 0.125 && value <= 0.25) {
                                            return '>= 2 ' + hours;
                                        } else if (value <= 0.125) {
                                            return '>= 1 ' + hour;
                                        } else if (value >= 0.5) {
                                            return '>= 4 ' + hours;
                                        }
//                                        return value;
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
                $url = PLUGIN_MANAGEENTITIES_WEBDIR . "/pics/tag.png";

                echo "<div class='tech-tag-div'>";
                foreach ($tech_interventions as $data) {
                    echo "<div class='tech-tag' style='background: url($url) no-repeat;'>";
                    echo getUserName($data);
                    echo "</div>";
                }
                echo "</div>";

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
    function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => self::getTypeName(2)
        ];

        $tab[] = [
            'id' => '1',
            'table' => $this->getTable(),
            'field' => 'name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemlink_type' => $this->getType(),
        ];

        $tab[] = [
            'id' => '4',
            'table' => $this->getTable(),
            'field' => 'date',
            'name' => __('Date'),
            'datatype' => 'date',
        ];


        $tab[] = [
            'id' => '8',
            'table' => $this->getTable(),
            'field' => 'comment',
            'name' => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id' => '9',
            'table' => $this->getTable(),
            'field' => 'actiontime',
            'name' => __('Duration'),
            'datatype' => 'timestamp'
        ];

        $tab[] = [
            'id' => '10',
            'table' => 'glpi_users',
            'field' => 'name',
            'name' => __('User'),
            'datatype' => 'dropdown',
            'right' => 'all',
        ];

        $tab[] = [
            'id' => '11',
            'table' => $this->getTable(),
            'field' => 'is_billed',
            'name' => __('Is billed', 'manageentities'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id' => '12',
            'table' => 'glpi_tickets',
            'field' => 'name',
            'name' => __('Linked ticket'),
            'datatype' => 'dropdown',
            'right' => 'all',
        ];


        $tab[] = [
            'id' => '30',
            'table' => $this->getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id' => '80',
            'table' => 'glpi_entities',
            'field' => 'completename',
            'name' => __('Entity'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '81',
            'table' => 'glpi_entities',
            'field' => 'entities_id',
            'name' => __('Entity') . "-" . __('ID'),
        ];

        return $tab;
    }
}