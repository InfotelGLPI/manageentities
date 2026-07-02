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

use CommonDBTM;
use DbUtils;
use Html;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class TicketTask extends CommonDBTM
{

    var $dohistory = false;

    static $rightname = "plugin_manageentities";

    public static function preItemForm(array $params): void
    {
        $item = $params['item'];
        if ($item->getType() !== 'TicketTask') {
            return;
        }

        $tickets_id = $item->fields['tickets_id'] ?? 0;
        if (!$tickets_id) {
            return;
        }

        if (!self::hasNoRemainingDays($tickets_id)) {
            return;
        }

        echo '<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" role="alert">';
        echo '<i class="ti ti-ban fs-4"></i>';
        echo '<div>';
        echo '<strong>' . __('Task addition blocked', 'manageentities') . '</strong>';
        echo '<br>';
        echo __('No days remaining on this contract period. Task addition is blocked.', 'manageentities');
        echo '</div>';
        echo '</div>';
    }

    private static function hasNoRemainingDays(int $tickets_id): bool
    {
        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable(
            'glpi_plugin_manageentities_cridetails',
            [
                'tickets_id' => $tickets_id,
                ['NOT' => ['plugin_manageentities_contractdays_id' => 0]],
            ]
        );
        $cridetail = reset($cridetails);

        if (empty($cridetail)) {
            return false;
        }

        $contractDay = new ContractDay();
        if (!$contractDay->getFromDB($cridetail['plugin_manageentities_contractdays_id'])) {
            return false;
        }

        $contractDay->fields['contractdays_id'] = $contractDay->fields['id'];
        $result = CriDetail::getCriDetailData($contractDay->fields);

        return $result['resultOther']['reste'] <= 0;
    }

    public static function preItemAdd(\TicketTask $item): void
    {
        $tickets_id = $item->input['tickets_id'] ?? 0;
        if (!$tickets_id) {
            return;
        }

        if (!self::hasNoRemainingDays($tickets_id)) {
            return;
        }

        Session::addMessageAfterRedirect(
            __('No days remaining on this contract period. Task addition is blocked.', 'manageentities'),
            false,
            ERROR
        );
        $item->input = [];
    }

    static public function postForm($params)
    {
        global $CFG_GLPI;

        $tickettask = $params['item'];
        switch ($tickettask->getType()) {
            case 'TicketTask':

                $rand = mt_rand();
                echo '<tr class="tab_bg_1"><td colspan="3"></td>';
                echo '<td>';
                echo "<div class='label right' style='width:300px;margin-right: 0;margin-left: auto;'>";
                $value = $tickettask->fields['date'];
                if (!empty($tickettask->fields['begin'])) {
                    $value = date('Y-m-d H:i:s', strtotime($tickettask->fields['begin'] . ' + 1 DAY'));
                }
                $randDate = Html::showDateTimeField('new_date', [
                    'value' => $value,
                    'rand' => $rand,
                    'mintime' => $CFG_GLPI["planning_begin"],
                    'maxtime' => $CFG_GLPI["planning_end"]
                ]);
                $params = json_encode([
                    'root_doc' => PLUGIN_MANAGEENTITIES_WEBDIR,
                    //                                       'new_date_id'    => 'showdate' . $randDate,
                    'tickets_id' => $tickettask->fields['tickets_id'],
                    'tickettasks_id' => $tickettask->fields['id']
                ]);
                $tickettask_id = $tickettask->fields['id'];
                echo "<span name=\"duplicate_$tickettask_id\" onclick='cloneTicketTask($params);'>";
                echo "<i class='ti ti-copy pointer'
            title='" . _sx('button', 'Duplicate') . "'></i>";

                echo "</span>";
                echo '</div>';
                echo '</td></tr>';
                break;
        }
    }
}
