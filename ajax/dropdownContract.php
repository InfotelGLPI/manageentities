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

use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Manageentities\Contract as PluginContract;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\ContractState;
use GlpiPlugin\Manageentities\EditorSubscription;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();

if (!isset($_POST["contracts_id"])) {
    throw new NotFoundHttpException();
}

if (isset($_POST["contracts_id"])) {
   $contract = new Contract();
   $contract->getEmpty();
   $contract->getFromDB($_POST["contracts_id"]);

   $contractdays_id = 0;
   if ($_POST["current_contracts_id"] == $_POST["contracts_id"]) {
      $contractdays_id = $_POST["contractdays_id"];
   }

   if ($contractdays_id == 0) {
      $contractday = new ContractDay();
      $restrict    = ['entities_id'  => $contract->fields['entities_id'],
                      'contracts_id' => $_POST["contracts_id"],
                      [
                         'OR' => [
                            ['plugin_manageentities_contractstates_id' => ContractState::getOpenedStates()],
                            ['id' => $contractdays_id]
                         ]
                      ]];
      $datas       = $contractday->find($restrict);
      //if a single contractday
      if (count($datas) == 1) {
         $datas = reset($datas);
         //Default contractday Display
         $contractdays_id = $datas['id'];
      }
   }
   if (isset($contract->fields['states_id']) && $contract->fields['states_id'] > 0) {
      echo "<span class='me-contract-status-data' style='display:none'>"
         . htmlspecialchars(Dropdown::getDropdownName("glpi_states", $contract->fields['states_id']), ENT_QUOTES)
         . "</span>";
      echo "<span class='me-contract-states-id-data' style='display:none'>"
         . (int)$contract->fields['states_id']
         . "</span>";
   }
   echo "<span class='me-contract-comment-data' style='display:none'>"
      . htmlspecialchars($contract->fields['comment'] ?? '', ENT_QUOTES)
      . "</span>";
   echo "<span class='me-contract-end-date-data' style='display:none'>"
      . htmlspecialchars($contract->fields['end_date'] ?? '', ENT_QUOTES)
      . "</span>";

   // Plugin contract fields — subscription flags read from EditorSubscription
   $subData = EditorSubscription::getForEntity((int)$contract->fields['entities_id']);
   echo "<span class='me-contract-editor-sub-data' style='display:none'>"
      . (int)($subData['active_editor_suscription'] ?? 0)
      . "</span>";
   echo "<span class='me-contract-cloud-data' style='display:none'>"
      . (int)($subData['cloud_client'] ?? 0)
      . "</span>";
   echo "<span class='me-contract-inet-data' style='display:none'>"
      . (int)($subData['internet_publication'] ?? 0)
      . "</span>";

   $restrict = ['entities_id'  => $contract->fields['entities_id'],
                'contracts_id' => $_POST["contracts_id"],
                [
                   'OR' => [
                      ['plugin_manageentities_contractstates_id' => ContractState::getOpenedStates()],
                      ['id' => $contractdays_id]
                   ]
                ]];

   Dropdown::show(ContractDay::class, ['name'      => 'plugin_manageentities_contractdays_id',
                                                      'value'     => $contractdays_id,
                                                      'condition' => $restrict,
                                                      'width'     => $_POST['width']]);
}
