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

$plugin = new Plugin();

if ($plugin->isActivated("manageentities")) {

   Session::checkRight("config","w");

   $config= new PluginManageentitiesConfig();
   $criprice= new PluginManageentitiesCriPrice();

   if (isset($_POST["update_config"])) {

      Session::checkRight("config","w");
      $config->update($_POST);
      Html::back();

   } else if (isset($_POST["add_price"])) {

      Session::checkRight("contract","w");
      if (isset($_POST['price']) && isset($_POST['plugin_manageentities_critypes_id'])) {
         $criprice->addCriPrice($_POST);
      }
      Html::back();

   } else if (isset($_POST["delete_price"])) {

      Session::checkRight("contract","w");

      foreach ($_POST["item_price"] as $key => $val) {
         if ($val==1) {
            $criprice->delete(array('id'=>$key));
         }
      }
      Html::back();

   } else {

      Html::header($LANG["common"][12],'',"plugins","manageentities");

      $config->GetFromDB(1);
      $config->showForm();
      $config->showDetails();

      Html::footer();
   }

} else {
   Html::header($LANG["common"][12],'',"config","plugins");
   echo "<div align='center'><br><br><img src=\"".$CFG_GLPI["root_doc"]."/pics/warning.png\" alt=\"warning\"><br><br>";
   echo "<b>Please activate the plugin</b></div>";
   Html::footer();
}

?>