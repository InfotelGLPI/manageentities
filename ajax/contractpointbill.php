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

include('../../../inc/includes.php');
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

if (isset($_POST['month']) && $_POST['month'] && isset($_POST['year']) && $_POST['year'] && $_POST['id']) {
    global $DB;
    $date = mktime(0, 0, 0, $_POST['month'], 1, $_POST['year']);
    $begin_date = date('Y-m-d', $date);
    $endOfTheMonth = date('t', $date);
    $end_date = date('Y-m-d h:i:s', mktime(23, 59, 59, $_POST['month'], $endOfTheMonth, $_POST['year']));
    $rows = $DB->request([
        'FROM' => 'glpi_plugin_manageentities_contractpoints_bills',
        'WHERE' => [
            'plugin_manageentities_contractpoints_id' => $_POST['id'],
            ['date' => ['>=', $begin_date]],
            ['date' => ['<=', $end_date]]
        ]
    ]);
    if ($rows->count()) {
        foreach($rows as $row) {
            if (date('Y m', strtotime('-1MONTH')) === date('Y m', $date)) {
                echo '<small>'.__('Points will be automatically reset to ').' '.$row['pre_bill_points'].'</small>';
            } else {
                echo '<small>'.__('Points before billing of this month : ').' '.$row['pre_bill_points'].'</small>';
            }
        }
    } else {
        echo '<small>'.__('No billing history for this month').'</small>';
    }
}

