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
// */

if (!defined('GLPI_ROOT')) {
     define('GLPI_ROOT', '../../..');
}

include (GLPI_ROOT . "/inc/includes.php");

require("../fpdf/font/symbol.php");

Session::checkLoginUser();

if (!isset($_POST["cri"])) $_POST["cri"] = "";

Html::popHeader($LANG['plugin_manageentities']['title'][2]);

$PluginManageentitiesCri = new PluginManageentitiesCri();
$PluginManageentitiesCriTechnician = new PluginManageentitiesCriTechnician();
$criDetail = new PluginManageentitiesCriDetail();

if (isset($_POST["add_cri"])) {

   if ($PluginManageentitiesCri->canCreate()) {
      if ($_POST["REPORT_ACTIVITE"]) {
         $PluginManageentitiesCri->generatePdf($_POST["REPORT_ID"], $_POST["CONTRAT"], $_POST["REPORT_ACTIVITE"], $_POST["REPORT_DESCRIPTION"], false);
      } else {
         Session::addMessageAfterRedirect($LANG['plugin_manageentities']['cri'][37],true,ERROR);
         Html::back();
      }
   }
} else if (isset($_POST["save_cri"])) {

   if ($PluginManageentitiesCri->canCreate()) {
      if ($_POST["REPORT_ACTIVITE"]) {
         $PluginManageentitiesCri->generatePdf($_POST["REPORT_ID"], $_POST["CONTRAT"], $_POST["REPORT_ACTIVITE"], $_POST["REPORT_DESCRIPTION"], true);
      } else {
         Session::addMessageAfterRedirect($LANG['plugin_manageentities']['cri'][37],true,ERROR);
         Html::back();
      }
   }
} else if (isset($_POST["add_tech"])) {

   $input["users_id"]=$_POST["users_id"];
   $input["tickets_id"]=$_POST["REPORT_ID"];
   if ($PluginManageentitiesCri->canCreate())
      $PluginManageentitiesCriTechnician->add($input);
   Html::back();

} else if (isset($_POST["delete_tech"])) {

   if ($PluginManageentitiesCri->canCreate()) {
      $_POST["id"]=$_POST["tech_id"];
      $PluginManageentitiesCriTechnician->delete($_POST);
   }
   Html::back();

} else if (isset($_POST["addcridetail"])) {

   if($PluginManageentitiesCri->canCreate()){
      if($_POST['contracts_id']!='0'){
         $_POST["withcontract"] = 1;
         $criDetail->add($_POST);
      }
   }

   Html::back();

} else if (isset($_POST["updatecridetail"])) {

   if($PluginManageentitiesCri->canCreate()){
      if($_POST['contracts_id']!='0'){
         $_POST["withcontract"] = 1;
         $criDetail->update($_POST);
      }
   }

   Html::back();
} else if (isset($_POST["delcridetail"])) {

      if($PluginManageentitiesCri->canCreate()){
         $criDetail->delete($_POST);
      }

      Html::back();

} else {

   $PluginManageentitiesCri->showForm($_GET["job"]);

   }

Html::popFooter();

?>