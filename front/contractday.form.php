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

if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', '../../..');
   include (GLPI_ROOT . "/inc/includes.php");
}
if (!isset($_GET["id"])) $_GET["id"] = "";
if(!isset($_GET["contract_id"])) $_GET["contract_id"] = 0;

$contractday= new PluginManageentitiesContractDay();
$contract = new Contract();

if (isset($_POST["add"])) {
   $contractday->check(-1, 'w');
   $newID = $contractday->add($_POST);

   Html::back();

} else if (isset($_POST["update"])) {
   $contractday->check($_POST["id"], 'w');
   $contractday->update($_POST);

   Html::back();

} else if (isset($_POST["delete"])) {
   $contracts_id = $_POST["contracts_id"];
   $contractday->check($_POST["id"], 'w');
   $contractday->delete($_POST);

   Html::redirect(Toolbox::getItemTypeFormURL('Contract')."?id=".$contracts_id);

} else if (isset($_POST["add_nbday"]) && isset($_POST['nbday'])) {

   Session::checkRight("contract","w");
   $contractday->addNbDay($_POST);
   Html::back();

} else if (isset($_POST["delete_nbday"])) {

   Session::checkRight("contract","w");

   foreach ($_POST["item_nbday"] as $key => $val) {
      if ($val==1) {
         $contractday->delete(array('id'=>$key));
      }
   }
   Html::back();

} else if (isset($_POST["deleteAll"])) {

   foreach ($_POST["item"] as $key => $val) {
      $input = array('id' => $key);
      if ($val==1) {
         $contractday->check($key,'w');
         $contractday->delete($input);
      }
   }
   Html::back();

} else {

   Html::header($LANG['plugin_manageentities']['title'][5],'',"plugins","manageentities");

   if (Session::haveRight("contract","r")) {
      $contractday->showform($_GET["id"], array('contract_id'=>$_GET['contract_id']));
   }
   
   Html::footer();
}

?>