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
use DateTime;
use Html;
use Infocom;
use Session;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\Entity;
use Toolbox;

class Gantt extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('GANTT');
    }

    public static function getIcon()
    {
        return "ti ti-align-box-left-stretch";
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public static function datediffInWeeks($date1, $date2)
    {
        $first = DateTime::createFromFormat('m/d/Y', $date1);
        $second = DateTime::createFromFormat('m/d/Y', $date2);
        if ($date1 > $date2) {
            return self::datediffInWeeks($date2, $date1);
        }
        return floor($first->diff($second)->days / 7) + 1;
    }

    /** show GANTT diagram for a project or for all
     *
     * @param $ID ID of the project or -1 for all projects
     */
    public static function showGantt($values = [])
    {
        echo Html::css(PLUGIN_MANAGEENTITIES_WEBDIR . '/lib/jquery-gantt.css');
        echo Html::script(PLUGIN_MANAGEENTITIES_WEBDIR . "/lib/jquery-gantt.js");
        Entity::showManageentitiesHeader(__('GANTT', 'manageentities'));

        $todisplay = static::getDataToDisplayOnGantt($_SESSION["glpiactiveentities"], true);

        $bsize = [];
        $colSize = 24;

        if (count($todisplay)) {
            // Prepare for display
            $data = [];

            foreach ($todisplay as $key => $val) {
                $temp = [];

                $color = 'ganttGreen';

                $date_end = strtotime($_SESSION['glpi_currenttime']);
                $date_start = strtotime('-1 year', strtotime($date_end));

                $beginDate = (!empty($val['from'])) ? strtotime($val['from']) : $date_start; // or your date as well
                $endDate = (!empty($val['to'])) ? strtotime($val['to']) : $date_end;
                $datediff = $beginDate - $endDate;
                $nbDays = abs(floor($datediff / (60 * 60 * 24)));

                $nbMonth = ((date('Y', $endDate) - date('Y', $beginDate)) * 12) + (date('m', $endDate) - date(
                    'm',
                    $beginDate,
                )) + 1;
                $nbWeeks = self::datediffInWeeks(date('m/d/Y', $beginDate), date('m/d/Y', $endDate));
                $nbHours = $nbDays * 24;

                $color = $val['type'] . $val['id'];

                $bsize[$color]['width_m'] = (($colSize * $nbMonth) * ($val['percent']) / 100);
                $bsize[$color]['width_d'] = (($colSize * $nbDays) * ($val['percent']) / 100);
                $bsize[$color]['width_w'] = (($colSize * $nbWeeks) * ($val['percent']) / 100);
                $bsize[$color]['width_h'] = (($colSize * $nbHours) * ($val['percent']) / 100);

                $bsize[$color]['percent'] = $val['percent'];

                switch ($val['type']) {
                    case 'contract':
                        $color = 'ganttBlue';
                        $temp = [
                            'name' => $val['name'],
                            'desc' => $val['desc'],
                            'values' => [
                                [
                                    'id' => $val['id'],
                                    'from'
                                    => "/Date(" . $beginDate . "000)/",
                                    'to'
                                    => "/Date(" . $endDate . "000)/",
                                    'desc'
                                    => $val['desc'],
                                    'label'
                                    => $val['label'],//$val['link']
                                    'customClass'
                                    => $color,
                                ],
                            ],
                        ];
                        break;

                    case 'contractday':

                        /*$color = 'ganttBegin';
                        //$color = 'ganttGreen';

                        if ($val['percent'] > 50) {
                           $color = 'ganttGreen';
                        }
                        if ($val['percent'] > 75) {
                           $color = 'ganttOrange';
                        }
                        if ($val['percent'] == 100) {
                           $color = 'ganttGrey';
                        }
                        if ($val['percent'] > 100) {
                           $color = 'ganttRed';
                        }
                        if ($val['contractdaycolor'] > 0) {
                           $color = 'ganttGrey';
                        }
                        //ADD XACA
                        //$color = 'ganttMilestone';*/

                        $temp = [
                            'name' => ' ',
                            'desc' => $val['link'],
                            'values' => [
                                [
                                    'id' => 't' . $val['id'],
                                    'from'
                                    => "/Date(" . ($beginDate * 1000) . ")/",
                                    'to'
                                    => "/Date(" . ($endDate * 1000) . ")/",
                                    'desc'
                                    => $val['desc'],
                                    'label'
                                    => $val['label'],//$val['link']
                                    'dep' => $val['dep'],
                                    'customClass'
                                    => $color,
                                ],
                            ],
                        ];
                        break;
                }

                $data[] = $temp;
            }

            $months = [
                __('January'),
                __('February'),
                __('March'),
                __('April'),
                __('May'),
                __('June'),
                __('July'),
                __('August'),
                __('September'),
                __('October'),
                __('November'),
                __('December'),
            ];
            $dow = [
                substr(__('Sunday'), 0, 1),
                substr(__('Monday'), 0, 1),
                substr(__('Tuesday'), 0, 1),
                substr(__('Wednesday'), 0, 1),
                substr(__('Thursday'), 0, 1),
                substr(__('Friday'), 0, 1),
                substr(__('Saturday'), 0, 1),
            ];
            $langwait = __('Please wait', 'manageentities');
            echo "<div class='gantt'></div>";
            // These four payloads are emitted inside an inline <script>, and $data carries entity,
            // contract and contract day names straight from the database. Without JSON_HEX_TAG the
            // HTML parser closes the block on a stored "</script>" before JavaScript ever sees the
            // string. JSON_HEX_QUOT and JSON_HEX_APOS are deliberately left out: json_encode emits
            // the delimiters of the literals itself.
            $json_flags = JSON_HEX_TAG | JSON_HEX_AMP;
            $js = "
                           $('.gantt').gantt({
                                 source: " . json_encode($data, $json_flags) . ",
                                 navigate: 'scroll',
                                 maxScale: 'months',
                                 minScale: 'hours',
                                 scale: 'months',
                                 waitText: " . json_encode($langwait, $json_flags) . ",
                                 itemsPerPage: 100,
                                 months: " . json_encode($months, $json_flags) . ",
                                 dow: " . json_encode($dow, $json_flags) . ",
                     onRender: function(scales) {";

            foreach ($bsize as $col => $size) {
                $js .= "var cwidth=0;

            switch (scales) {
                case 'hours':
                    cwidth='" . $size['width_h'] . "px 100px';
                    break;
                 case 'days':
                    cwidth='" . $size['width_d'] . "px 100px';
                    break;
                case 'weeks':
                    cwidth='" . $size['width_w'] . "px 100px';
                    break;
                case 'months':
                default:
                    cwidth='" . $size['width_m'] . "px 100px';
            }";


                $color = '#F4F4F4';
                $img = 'green.png';

                if ($size['percent'] == 0) {
                    $color = '#FFF';
                    $img = 'white.png';
                }
                if ($size['percent'] > 50) {
                    $img = 'orange.png';
                }
                if ($size['percent'] > 75) {
                    $img = 'red.png';
                }
                if ($size['percent'] == 100) {
                    $img = 'grey.png';
                }
                if ($size['percent'] > 100) {
                    $img = 'red.png';
                }
                $js .= "     $('." . $col . "').css('background-color', '$color');";
                $js .= "     $('." . $col . "').css('border', '1px solid #000');";
                $js .= "     $('." . $col . "').css('background-image', 'url(\"../lib/jquery-plugins/img/$img\")');";

                $js .= "     $('." . $col . "').css('background-repeat', 'no-repeat');";
                $js .= "     $('." . $col . "').css('color', 'transparent');";
                $js .= "     $('." . $col . "').css('-webkit-box-shadow', '0 0 0px rgba(0,0,0,0.25) inset');";
                $js .= "     $('." . $col . "').css('-moz-box-shadow', '0 0 0px rgba(0,0,0,0.25) inset');";
                $js .= "     $('." . $col . "').css('box-shadow', '0 0 0px rgba(0,0,0,0.25) inset');";
                $js .= "     $('." . $col . "').css('background-size',cwidth);";
                $js .= "     $('." . $col . "').css('background-position',' 0px ');";
            }

            $js .= "     }
                  });";
            echo "<script type='text/javascript'>" . $js . "</script>";
        } else {
            echo __('Nothing to display', 'manageentities');
        }
    }

    /** Get data to display on GANTT
     *
     * @param $ID        integer   ID of the contract
     * @param $showall   boolean   show all sub items (contracts / contractdays) (true by default)
     */
    public static function getDataToDisplayOnGantt($entity, $showall = true)
    {
        $contracts = Followup::queryFollowUp($entity, []);
        $todisplay = [];

        if (!empty($contracts)) {
            foreach ($contracts as $key => $contract_data) {
                if (is_integer($key)) {
                    $real_begin = null;
                    $real_end = null;
                    $dep = false;

                    if (!is_null($contract_data['contract_begin_date'])
                        && $contract_data['show_on_global_gantt'] > 0) {
                        foreach ($contract_data['days'] as $key => $days) {
                            if ($days['contract_is_closed']) {
                                unset($contract_data['days'][$key]);
                            }
                        }
                        if (!empty($contract_data['days'])) {
                            $real_begin = date('Y/n/j', strtotime($contract_data['contract_begin_date']) + 86400);
                            $tmp = Infocom::getWarrantyExpir(
                                $contract_data['contract_begin_date'],
                                $contract_data["duration"],
                                0,
                                false,
                            );

                            $real_end = date('Y/n/j', strtotime($tmp) + 86400);
                            //print_r($real_begin);
                            // The gantt library inserts this string into the page as HTML, and both
                            // halves come straight from the database.
                            $name = htmlspecialchars((string) $contract_data['entities_name'], ENT_QUOTES)
                                . " &gt; " . htmlspecialchars((string) $contract_data['name'], ENT_QUOTES);
                            $link_contract = Toolbox::getItemTypeFormURL("Contract");
                            $name_contract = "<a href='" . $link_contract . "?id=" . $contract_data["contracts_id"] . "'>";
                            $name_contract .= $name . "</a>";

                            $desc = __('Name') . ' : ' . $name;
                            $desc .= !empty($contract_data['contract_num']) ? '<br/>' . _x(
                                'phone',
                                'Number',
                            ) . ' : ' . htmlspecialchars((string) $contract_data['contract_num'], ENT_QUOTES) : '';
                            $desc .= !empty($contract_data['contract_added']) ? '<br/>' . __(
                                'Contract present',
                                'manageentities',
                            ) . ' : ' . $contract_data['contract_added'] : '';
                            $desc .= !empty($contract_data['date_signature']) ? '<br/>' . __(
                                'Date of signature',
                                'manageentities',
                            ) . ' : ' . $contract_data['date_signature'] : '';
                            $desc .= !empty($contract_data['date_renewal']) ? '<br/>' . __(
                                'Date of renewal',
                                'manageentities',
                            ) . ' : ' . $contract_data['date_renewal'] : '';

                            //Add current contract
                            //print_r($real_end);
                            $todisplay[$real_begin . '#' . $real_end . '#task' . $contract_data['contracts_id']]
                                = [
                                    // jquery-gantt.js concatenates name, label and desc into HTML
                                    // strings (fn-label spans, then .html(desc) for the hint), so
                                    // these three keys are HTML sinks and they carry raw database
                                    // columns. The JSON_HEX_TAG of showGantt() only keeps the
                                    // payload from breaking out of the <script> block: the string
                                    // is rebuilt as is in memory and handed back to jQuery, which
                                    // parses it as HTML. The 'link' key is not read by the library.
                                    'name' => htmlspecialchars((string) $contract_data['entities_name'], ENT_QUOTES),
                                    'id' => $contract_data['contracts_id'],
                                    'link' => $name,//name_contract
                                    'label' => htmlspecialchars((string) $contract_data['entities_name'], ENT_QUOTES),
                                    'desc' => htmlspecialchars((string) $contract_data['name'], ENT_QUOTES),
                                    'percent' => 0,
                                    'type' => 'contract',
                                    'from' => $real_begin . ' 00:00:00',
                                    'to' => $real_end . ' 00:00:00',
                                    'dep' => $dep,
                                ];

                            if ($showall) {
                                //Add current tasks
                                $todisplay += self::getDataToDisplayOnGanttForContract($contract_data['days']);
                            }
                        }
                    }
                }
            }
        }

        return $todisplay;
    }

    /** Get data to display on GANTT for a project task
     *
     * @param $ID ID of the project task
     */
    public static function getDataToDisplayOnGanttForContract($days)
    {
        $todisplay = [];

        if (!empty($days)) {
            foreach ($days as $day_data) {
                //if(!$day_data['contract_is_closed']){
                $real_begin = null;
                $real_end = null;
                $dep = false;
                // Use real if set
                if (!is_null($day_data['begin_date'])) {
                    $real_begin = date('Y/n/j', strtotime($day_data['begin_date']) + 86400);
                }
                if (!is_null($day_data['end_date'])) {
                    $real_end = date('Y/n/j', strtotime($day_data['end_date']) + 86400);
                }

                // contractdayname is the RAW twin of contractday_name, which Followup.php escapes
                // when it builds it (see Followup.php:486 and 495); this string ends up in
                // .html(desc) on the gantt hint, so it has to be escaped here.
                $desc = __('Name') . ' : ' . htmlspecialchars((string) $day_data['contractdayname'], ENT_QUOTES);
                if (isset($day_data['contract_type']) && $day_data['contract_type'] == Contract::CONTRACT_TYPE_FORFAIT) {
                    $percent = 100;
                } else {
                    $desc .= '<br/>' . __('Initial credit', 'manageentities') . ' : ' . $day_data['credit'] .
                        '<br/>' . __('Total consummated', 'manageentities') . ' : ' . $day_data['conso'];
                    if ($day_data['credit'] == 0) {
                        $percent = 0;
                    } else {
                        $percent = ($day_data['conso'] * 100) / $day_data['credit'];
                    }
                }
                $percentview = Html::formatNumber($percent, 2) . ' %';
                // Add current task
                $todisplay[$real_begin . '#' . $real_end . '#task' . $day_data['contractdays_id']]
                    = [
                        'name' => htmlspecialchars((string) $day_data['contractdayname'], ENT_QUOTES),
                        'id' => $day_data['contractdays_id'],
                        'label' => $percentview,
                        'desc' => $desc,
                        'dep' => $day_data['contracts_id'],
                        'link' => $day_data['contractday_name'],
                        'type' => 'contractday',
                        'percent' => $percent,
                        'from' => $real_begin,
                        'to' => $real_end,
                        'contractdaycolor' => $day_data['contract_is_closed'],
                    ];
                //}
            }
        }

        return $todisplay;
    }

}
