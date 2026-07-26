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

use Glpi\Exception\Http\AccessDeniedHttpException;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

if (!defined('GLPI_ROOT')) {
   die("Can not acces directly to this file");
}

Session::checkLoginUser();
// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    throw new AccessDeniedHttpException();
}

global $CFG_GLPI;

$used = [];

if (isset($_POST['used'])) {
   $used = $_POST['used'];
}

if (!isset($_POST['value'])) {
   $_POST['value'] = 0;
}

$one_item = -1;
if (isset($_POST['_one_id'])) {
   $one_item = $_POST['_one_id'];
}

if (!isset($_POST['page'])) {
   $_POST['page']       = 1;
   $_POST['page_limit'] = $CFG_GLPI['dropdown_max'];
}

if (isset($_POST['toadd'])) {
   $toadd = $_POST['toadd'];
} else {
   $toadd = [];
}

$datas = [];
// Count real items returned
$count = 0;

if ($_POST['page'] == 1) {
   if (count($toadd)) {
      foreach ($toadd as $key => $val) {
         if (($one_item < 0) || ($one_item == $key)) {
            array_push($datas, ['id'   => $key,
                                'text' => strval(stripslashes($val))]);
         }
      }
   }
}

// Validate/bound the numeric range: attacker-supplied min/max/step could otherwise
// spin a near-infinite loop (e.g. min=0, max=999999999, step=0.0001) and exhaust
// CPU/memory. Force numeric bounds, a positive step, and cap the iteration count.
$min  = (float) ($_POST['min'] ?? 0);
$max  = (float) ($_POST['max'] ?? 0);
$step = (float) ($_POST['step'] ?? 1);
if ($step <= 0) {
   $step = 1;
}
$max_iterations = 10000;
if ($max >= $min && (($max - $min) / $step) > $max_iterations) {
   $max = $min + ($max_iterations * $step);
}

$values = [];
if (!empty($_POST['searchText'])) {
   for ($i = $min; $i <= $max; $i += $step) {
      if (strstr($i, $_POST['searchText'])) {
         $values[$i] = $i;
      }
   }
} else {
   for ($i = $min; $i <= $max; $i += $step) {
      $values[] = $i;
   }
}
if ($one_item < 0 && count($values)) {
   $start  = ($_POST['page'] - 1) * $_POST['page_limit'];
   $tosend = array_splice($values, $start, $_POST['page_limit']);
   foreach ($tosend as $i) {
      $txt = $i;
      if (isset($_POST['unit'])) {
         $txt = Dropdown::getValueWithUnit($i, $_POST['unit']);
      }
      array_push($datas, ['id'   => $i,
                          'text' => strval($txt)]);
      $count++;
   }

} else {
   if (!isset($toadd[$one_item])) {
      $txt = $one_item;
      if (isset($_POST['unit'])) {
         $txt = Dropdown::getValueWithUnit($one_item, $_POST['unit']);
      }
      array_push($datas, ['id'   => $one_item,
                          'text' => strval(stripslashes($txt))]);
      $count++;
   }
}

if (($one_item >= 0)
    && isset($datas[0])) {
   echo json_encode($datas[0]);
} else {
   $ret['results'] = $datas;
   $ret['count']   = $count;
   echo json_encode($ret);
}
