<?php
/*
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2003-2012 by the Manageentities Development Team.

 https://forge.indepnet.net/projects/manageentities
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

define('GLPI_ROOT', '../../..');
include (GLPI_ROOT."/inc/includes.php");

Html::header($LANG['plugin_manageentities']['title'][1],'',"plugins","manageentities");

if (isset($_GET)) $tab = $_GET;
if (empty($tab) && isset($_POST)) $tab = $_POST;
if (!isset($_POST["tech_num"]) || empty($_POST["tech_num"])) $owner=Session::getLoginUserID();
else $owner=$_POST["tech_num"];
if (!isset($_GET["usertype"])) $_GET["usertype"]="user";
if (empty($_POST["date1"])) $_POST["date1"] = "";
if (empty($_POST["date2"])) $_POST["date2"] = "";
if ($_POST["date1"]!=""&&$_POST["date2"]!=""&&strcmp($_POST["date2"],$_POST["date1"])<0) {
   $tmp=$_POST["date1"];
   $_POST["date1"]=$_POST["date2"];
   $_POST["date2"]=$tmp;
}

Report::title();

$PluginManageentitiesEntity = new PluginManageentitiesEntity();
if ($PluginManageentitiesEntity->canView() || Session::haveRight("config","w")) {

   if (isset($_POST["choice_tech"])) {

      echo "<div align='center'><form action=\"report.form.php\" method=\"post\">";
      echo "<table class='tab_cadre'><tr class='tab_bg_2'><td class='right'>";
      echo $LANG["search"][8]." :</td><td>";
      Html::showDateFormItem("date1",$_POST["date1"],true,true);
      echo "</td><td rowspan='2' class='center'><input type=\"submit\" class='button' name=\"choice_tech\" Value=\"". $LANG["buttons"][7] ."\" /></td></tr>";
      echo "<tr class='tab_bg_2'><td class='right'>".$LANG["search"][9]." :</td><td>";
      Html::showDateFormItem("date2",$_POST["date2"],true,true);
      echo "</td></tr>";
      ////stats Users
      echo "<tr><td class='tab_bg_2 center' colspan='3'>";
      echo "<input type='radio' id='radio_group' name='usertype' value='group' ".($_POST["usertype"]=="group"?"checked":"").">Tous";
      echo "<hr>";
      echo "<input type='radio' id='radio_alluser' name='usertype' value='user' ".($_POST["usertype"]=="user"?"checked":"").">";
      User::dropdown(array('name' => "tech_num",'value' => $owner,'entity' => $_SESSION["glpiactive_entity"],'right' => 'all'));
      echo "</td></tr>";
      echo "</table>";
      Html::closeForm();
      echo "</div>";
      $PluginManageentitiesCriDetail= new PluginManageentitiesCriDetail();
      $PluginManageentitiesCriDetail->showHelpdeskReports($_POST["usertype"],$owner,$_POST["date1"],$_POST["date2"]);

   } else {

      echo "<div align='center'><form action=\"report.form.php\" method=\"post\">";
      echo "<table class='tab_cadre'><tr class='tab_bg_2'><td class='right'>";
      echo $LANG["search"][8]." :</td><td>";
      Html::showDateFormItem("date1",$_POST["date1"],true,true);
      echo "</td><td rowspan='2' class='center'><input type=\"submit\" class='button' name=\"choice_tech\" Value=\"". $LANG["buttons"][7] ."\" /></td></tr>";
      echo "<tr class='tab_bg_2'><td class='right'>".$LANG["search"][9]." :</td><td>";
      Html::showDateFormItem("date2",$_POST["date2"],true,true);
      echo "</td></tr>";
      //stats Users
      echo "<tr><td class='tab_bg_2 center' colspan='3'>";
      echo "<input type='radio' id='radio_group' name='usertype' value='group' ".($_GET["usertype"]=="group"?"checked":"").">".$LANG['plugin_manageentities']['report'][7];
      echo "<hr>";
      echo "<input type='radio' id='radio_alluser' name='usertype' value='user' ".($_GET["usertype"]=="user"?"checked":"").">";
      User::dropdown(array('name' => "tech_num",'value' => $owner,'entity' => $_SESSION["glpiactive_entity"],'right' => 'all'));

      echo "</td></tr>";
      echo "</table>";
      Html::closeForm();
      echo "</div>";

      }

} else {
   echo "<div align='center'><br><br><img src=\"".$CFG_GLPI["root_doc"]."/pics/warning.png\" alt=\"warning\"><br><br>";
   echo "<b>".$LANG["login"][5]."</b></div>";
}

Html::footer();

?>