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

Session::checkLoginUser();
// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    throw new AccessDeniedHttpException();
}

if (strpos($_SERVER['PHP_SELF'], "dropdownGenerateCriCategories.php")) {
   header("Content-Type: text/html; charset=UTF-8");
   Html::header_nocache();
} else if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

$opt = ['entity' => $_POST["entity_restrict"]];
$condition  =[];

$currentcateg = new ITILCategory();
$currentcateg->getFromDB($_POST['value']);

if ($_POST["type"]) {
   switch ($_POST['type']) {
      case Ticket::INCIDENT_TYPE :
         $condition['is_incident'] = 1;
         if ($currentcateg->getField('is_incident') == 1) {
            $opt['value'] = $_POST['value'];
         }
         break;

      case Ticket::DEMAND_TYPE:
         $condition['is_request'] = 1;
         if ($currentcateg->getField('is_request') == 1) {
            $opt['value'] = $_POST['value'];
         }
         break;
   }
}

$opt['condition'] = $condition;
ITILCategory::dropdown($opt);
