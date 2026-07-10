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

use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\InterventionStakeholder;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();
Session::checkRight('plugin_manageentities', UPDATE);
$interventionStakeholder = new InterventionStakeholder();

/**
 * Ensure the current user may write on the parent contract-day entity.
 * Returns false (and shows an error) when access is denied.
 */
$checkContractDayAccess = static function (): bool {
   $contractDay = new ContractDay();
   if (!$contractDay->getFromDB((int) ($_POST['contractdays_id'] ?? 0))
       || !Session::haveAccessToEntity($contractDay->fields['entities_id'])) {
      Html::displayRightError();
      return false;
   }
   return true;
};

if (isset($_POST['action']) && $_POST['action'] != "") {
   switch ($_POST['action']) {
      case "add_user_datas":
         if (!$checkContractDayAccess()) {
            break;
         }
         if ((isset($_POST["users_id_tech"]) && $_POST['users_id_tech'] > 0) &&
             (isset($_POST["contractdays_id"]) && $_POST['contractdays_id'] > 0) &&
             (isset($_POST["nb_days"]) && $_POST['nb_days'] > 0)) {

            $idUser         = $_POST['users_id_tech'];
            $idContractdays = $_POST['contractdays_id'];
            $nbDays         = $_POST['nb_days'];

            $interventionStakeholder->getFromDBByCrit(['users_id'                              => $idUser,
                                                       'plugin_manageentities_contractdays_id' => $idContractdays]);

            if (isset($interventionStakeholder->fields['id']) && $interventionStakeholder->fields['id'] > 0) {
               $interventionStakeholder->fields['number_affected_days'] += $_POST['nb_days'];

               if ($interventionStakeholder->update($interventionStakeholder->fields)) {
                  $nbDaysAfter                                   = $interventionStakeholder->getNbAvailiableDay($_POST['contractdays_id']);
                  $_SESSION['glpi_plugin_manageentities_nbdays'] += $nbDaysAfter;

                  $interventionStakeholder->showMessage(__("Stakeholder successfully updated.", "manageentities"), INFO);
                  $interventionStakeholder->reinitListStakeholders($interventionStakeholder, $_POST['contractdays_id'], $_POST['id_dp_nbdays']);

                  if ($nbDaysAfter <= 0) {
                     $interventionStakeholder->hideAddForm($_POST['contractdays_id']);
                  }

               } else {
                  $interventionStakeholder->showMessage(__("An error happened while saving the data.", "manageentities"), ERROR);
               }

            } else {
               $interventionStakeholder->fields['users_id']                              = $idUser;
               $interventionStakeholder->fields['number_affected_days']                  = $nbDays;
               $interventionStakeholder->fields['plugin_manageentities_contractdays_id'] = $idContractdays;

               if ($interventionStakeholder->add($interventionStakeholder->fields)) {
                  $interventionStakeholder->showMessage(__("Informations successfully added.", "manageentities"), INFO);
                  $interventionStakeholder->reinitListStakeholders($interventionStakeholder, $_POST['contractdays_id'], $_POST['id_dp_nbdays']);
                  $nbDaysAfter = $interventionStakeholder->getNbAvailiableDay($_POST['contractdays_id']);
                  if ($nbDaysAfter == 0) {
                     $interventionStakeholder->hideAddForm($_POST['contractdays_id']);
                  }
               } else {
                  $interventionStakeholder->showMessage(__("An error happened while saving the data.", "manageentities"), ERROR);
               }
            }
         } else {
            $interventionStakeholder->showMessage(__("All fields are not correctly filled.", "manageentities"), ERROR);
         }
         break;

      case "delete_user_datas":
         if (!$checkContractDayAccess()) {
            break;
         }
         if (isset($_POST['stakeholder_id']) && $_POST['stakeholder_id'] > 0) {
            $interventionStakeholder = new InterventionStakeholder();
            $interventionStakeholder->getFromDB($_POST['stakeholder_id']);
            $intervention = new ContractDay();
            $intervention->getFromDB($_POST['contractdays_id']);

            if ($interventionStakeholder->delete($interventionStakeholder->fields)) {
               $nbDaysAfter                                   = $interventionStakeholder->getNbAvailiableDay($_POST['contractdays_id']);
               $_SESSION['glpi_plugin_manageentities_nbdays'] -= $nbDaysAfter;
               $interventionStakeholder->showMessage(__("Informations successfully deleted.", "manageentities"), INFO);
               $interventionStakeholder->reinitListStakeholders($interventionStakeholder, $_POST['contractdays_id'], $_POST['id_dp_nbdays'], true);

               if ($nbDaysAfter > 0) {
                  $interventionStakeholder->showAddForm($_POST['contractdays_id']);
               }

            } else {
               $interventionStakeholder->showMessage(__("An error happened while deleting the data.", "manageentities"), ERROR);
            }
         }
         break;

      default:
         break;
   }
}
