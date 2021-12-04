<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2017 by the Manageentities Development Team.

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

$plugin = new Plugin();


if ($plugin->isActivated("manageentities")) {
   if (Session::haveRight("plugin_manageentities", UPDATE)) {
      $config = new PluginManageentitiesConfig();

      if (isset($_POST["update_config"])) {
         Session::checkRight("config", UPDATE);
         $config->update($_POST);
         Html::back();

      } else {
         Html::header(__('Entities portal', 'manageentities'), '', "management", "pluginmanageentitiesentity");
         $config->GetFromDB(1);
         $config->showConfigForm();
         //$config->showDetails();
         $config->showFormCompany();

         Html::footer();
      }

   } else {
      Html::header(__('Setup'), '', "config", "plugins");
      echo "<div align='center'><br><br>";
      echo "<i class='fas fa-exclamation-triangle fa-4x' style='color:orange'></i><br><br>";
      echo "<b>" . __("You don't have permission to perform this action.") . "</b></div>";
      Html::footer();
   }

} else {
   Html::header(__('Setup'), '', "config", "plugins");
   echo "<div class='alert alert-important alert-warning d-flex'>";
   echo "<b>" . __('Please activate the plugin', 'manageentities') . "</b></div>";
   Html::footer();
}
