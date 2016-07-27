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

$contractday= new PluginManageentitiesContractDay();
$contract = new PluginManageentitiesContract();

if (isset($_POST["addcontract"])) {
   $contract->check(-1, 'w');
   $newID = $contract->add($_POST);

   Html::back();

} else if (isset($_POST["delcontract"])) {
   $contract->check($_POST["id"], 'w');
   $contract->delete($_POST);

   Html::back();

} else if (isset($_POST["updatecontract"])) {
   $contract->check($_POST["id"], 'w');
   $contract->update($_POST);

   Html::back();

}else if (isset($_POST["add_nbday"]) && isset($_POST['nbday'])) {

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

} else {

   Html::header($LANG["common"][12],'',"plugins","manageentities");

   if (Session::haveRight("contract","r")) {
      PluginManageentitiesContractDay::showform_old($CFG_GLPI['root_doc']."/plugins/manageentities/front/contract.form.php",
         $_POST["id"]);
   }
   
   Html::footer();
}

?>