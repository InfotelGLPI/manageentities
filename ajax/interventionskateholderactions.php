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
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();
$interventionSkateholder = new PluginManageentitiesInterventionSkateholder();

if (isset($_POST['action']) && $_POST['action'] != "") {
   switch ($_POST['action']) {
      case "add_user_datas":
         if ((isset($_POST["users_id_tech"]) && $_POST['users_id_tech'] > 0) &&
             (isset($_POST["contractdays_id"]) && $_POST['contractdays_id'] > 0) &&
             (isset($_POST["nb_days"]) && $_POST['nb_days'] > 0)) {

            $idUser         = $_POST['users_id_tech'];
            $idContractdays = $_POST['contractdays_id'];
            $nbDays         = $_POST['nb_days'];

            $interventionSkateholder->getFromDBByCrit(['users_id'                              => $idUser,
                                                       'plugin_manageentities_contractdays_id' => $idContractdays]);

            if (isset($interventionSkateholder->fields['id']) && $interventionSkateholder->fields['id'] > 0) {
               $interventionSkateholder->fields['number_affected_days'] += $_POST['nb_days'];

               if ($interventionSkateholder->update($interventionSkateholder->fields)) {
                  $nbDaysAfter                                   = $interventionSkateholder->getNbAvailiableDay($_POST['contractdays_id']);
                  $_SESSION['glpi_plugin_manageentities_nbdays'] += $nbDaysAfter;

                  $interventionSkateholder->showMessage(__("Skatehokder successfully updated.", "manageentities"), INFO);
                  $interventionSkateholder->reinitListSkateholders($interventionSkateholder, $_POST['id_dp_nbdays'], $_POST['contractdays_id']);

                  if ($nbDaysAfter <= 0) {
                     // supprimer la ligne d'ajout
                     $interventionSkateholder->hideAddForm($_POST['contractdays_id']);
                  }

               } else {
                  $interventionSkateholder->showMessage(__("An error happened while saving the data.", "manageentities"), ERROR);
               }

            } else {
               $interventionSkateholder->fields['users_id']                              = $idUser;
               $interventionSkateholder->fields['number_affected_days']                  = $nbDays;
               $interventionSkateholder->fields['plugin_manageentities_contractdays_id'] = $idContractdays;

               if ($interventionSkateholder->add($interventionSkateholder->fields)) {
                  $interventionSkateholder->showMessage(__("Informations successfully added.", "manageentities"), INFO);
                  $interventionSkateholder->reinitListSkateholders($interventionSkateholder, $_POST['id_dp_nbdays'], $_POST['contractdays_id']);
                  $nbDaysAfter = $interventionSkateholder->getNbAvailiableDay($_POST['contractdays_id']);
                  if ($nbDaysAfter == 0) {
                     // Supprimer la ligne d'ajout
                     $interventionSkateholder->hideAddForm($_POST['contractdays_id']);
                  }
               } else {
                  $interventionSkateholder->showMessage(__("An error happened while saving the data.", "manageentities"), ERROR);
               }
            }
         } else {
            $interventionSkateholder->showMessage(__("All fields are not correctly filled.", "manageentities"), ERROR);
         }
         break;

      case "delete_user_datas":
         if (isset($_POST['skateholder_id']) && $_POST['skateholder_id'] > 0) {
            $interventionSkateholder = new PluginManageentitiesInterventionSkateholder();
            $interventionSkateholder->getFromDB($_POST['skateholder_id']);
            $intervention = new PluginManageentitiesContractDay();
            $intervention->getFromDB($_POST['contractdays_id']);

            if ($interventionSkateholder->delete($interventionSkateholder->fields)) {
               $nbDaysAfter                                   = $interventionSkateholder->getNbAvailiableDay($_POST['contractdays_id']);
               $_SESSION['glpi_plugin_manageentities_nbdays'] -= $nbDaysAfter;
               $interventionSkateholder->showMessage(__("Informations successfully deleted.", "manageentities"), INFO);
               $interventionSkateholder->reinitListSkateholders($interventionSkateholder, $_POST['id_dp_nbdays'], $_POST['contractdays_id'], true);

               if ($nbDaysAfter > 0) {
                  $interventionSkateholder->showAddForm($_POST['contractdays_id']);
               }

            } else {
               $interventionSkateholder->showMessage(__("An error happened while deleting the data.", "manageentities"), ERROR);
            }
         }
         break;

      default:
         break;
   }
}


