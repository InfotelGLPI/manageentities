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
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Report;

Html::header(__('Entities portal', 'manageentities'), '', "plugins", "manageentities");

if (isset($_GET)) $tab = $_GET;
if (empty($tab) && isset($_POST)) $tab = $_POST;
if (empty($_POST["date1"]) && empty($_POST["date2"])) {
   $lastday = cal_days_in_month(CAL_GREGORIAN, date("m"), date("Y"));
   if (date("d") == $lastday) {
      $_POST["date2"] = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
      $_POST["date1"] = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y")));
   } else {
      $month          = date("m");
      $lastday        = $month == 1 ? 31 : cal_days_in_month(CAL_GREGORIAN, $month - 1, date("Y"));
      $_POST["date2"] = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, $lastday, date("Y")));
      $_POST["date1"] = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, 1, date("Y")));
   }
}
if ($_POST["date1"] != "" && $_POST["date2"] != "" && strcmp($_POST["date2"], $_POST["date1"]) < 0) {
   $tmp            = $_POST["date1"];
   $_POST["date1"] = $_POST["date2"];
   $_POST["date2"] = $tmp;
}
$dbu = new DbUtils();

\Report::title();

$Entity = new Entity();
if ($Entity->canView() || Session::haveRight("config", UPDATE)) {

   if (isset($_POST["send"])) {
      echo "<div class='center'><form action=\"report_occupation.form.php\" method=\"post\">";
      echo "<table class='tab_cadre'><tr class='tab_bg_2'><td class='right'>";
      echo __('Start date') . " :</td><td>";
      Html::showDateField("date1", ['value' => $_POST["date1"]]);
      echo "<td class='right'>" . __('End date') . " :</td><td>";
      Html::showDateField("date2", ['value' => $_POST["date2"]]);
      echo "</td></tr>";

      $user      = new User();
      $condition = ['is_deleted'  => 0,
                    'entities_id' => $_SESSION["glpiactiveentities"]];
      $users     = $user->find($condition);
      $techs     = [];
      foreach ($users as $data) {
         $techs[$data['id']] = $dbu->getUserName($data['id']);
      }

      echo "<tr><td class='tab_bg_2 center'>";
      echo __('Technician') . " :</td><td class='tab_bg_2 center' colspan='3' >";
      if (isset($_POST['techs'])) {
         Dropdown::showFromArray('techs', $techs, ['multiple' => true, 'values' => $_POST['techs']]);
      } else {
         Dropdown::showFromArray('techs', $techs, ['multiple' => true]);
      }

      echo "</td></tr>";
      if (isset($_POST['techs'])) {
         echo "<tr class='tab_bg_2'><td colspan='4'></td></tr>";
      }
      echo "<tr class='tab_bg_2'><td colspan='4' class='center'>";
      echo Html::submit(_sx('button', 'Post'), ['name' => 'send', 'class' => 'btn btn-primary']);
      echo "</td></tr>";

      echo "</table>";
      Html::closeForm();
      echo "</div>";

      if (isset($_POST['techs'])) {
         $report = new Report();
         $report->showOccupationReports($_POST['techs'], $_POST["date1"], $_POST["date2"]);
      }

   } else {

      echo "<div class='center'><form action=\"report_occupation.form.php\" method=\"post\">";
      echo "<table class='tab_cadre'><tr class='tab_bg_2'><td class='right'>";
      echo __('Start date') . " :</td><td>";
      Html::showDateField("date1", ['value' => $_POST["date1"]]);
      echo "<td class='right'>" . __('End date') . " :</td><td>";
      Html::showDateField("date2", ['value' => $_POST["date2"]]);
      echo "</td></tr>";
      //stats Users
      $dbu       = new DbUtils();
      $user      = new User();
      $condition = ['is_deleted'  => 0,
                    'entities_id' => $_SESSION["glpiactiveentities"]];
      $users     = $user->find($condition);
      $techs     = [];
      foreach ($users as $data) {
         $techs[$data['id']] = $dbu->getUserName($data['id']);
      }

      echo "<tr><td class='tab_bg_2 center'>";
      echo __('Technician') . " :</td><td class='tab_bg_2 center' colspan='3' >";
      Dropdown::showFromArray('techs', $techs, ['multiple' => true]);
      echo "</td></tr>";
      echo "<tr class='tab_bg_2'></td><td colspan='4' class='center'>";
      echo Html::submit(_sx('button', 'Post'), ['name' => 'send', 'class' => 'btn btn-primary']);
      echo "</td></tr>";

      echo "</table>";
      Html::closeForm();
      echo "</div>";

   }

} else {
    throw new AccessDeniedHttpException();
}

Html::footer();
