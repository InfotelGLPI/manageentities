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
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\Entity;

$contractday = new ContractDay();
$contract    = new Contract();

if (isset($_POST["addcontract"])) {
   $contract->check(-1, UPDATE);
   $newID = $contract->add($_POST);
   Html::back();

} else if (isset($_POST["delcontract"])) {
   $contract->check($_POST["id"], UPDATE);
   $contract->delete($_POST);
   Html::back();

} else if (isset($_POST["updatecontract"])) {
   $contract->check($_POST["id"], UPDATE);
   $contract->update($_POST);
   Html::back();

} else if (isset($_POST["add_nbday"]) && isset($_POST['nbday'])) {
   Session::checkRight("contract", UPDATE);
   // addNbDay() writes contracts_id/entities_id straight from the POST body: enforce access
   // to the target entity and that the contract really belongs to it before inserting (IDOR).
   $entities_id  = (int) ($_POST['entities_id'] ?? -1);
   $coreContract = new \Contract();
   if (
      !Session::haveAccessToEntity($entities_id)
      || !$coreContract->getFromDB((int) ($_POST['contracts_id'] ?? 0))
      || (int) $coreContract->fields['entities_id'] !== $entities_id
   ) {
      throw new AccessDeniedHttpException();
   }
   $contractday->addNbDay($_POST);
   Html::back();

} else if (isset($_POST["delete_nbday"])) {
   Session::checkRight("contract", UPDATE);
   foreach ($_POST["item_nbday"] as $key => $val) {
      if ($val == 1) {
         // Per-item check like the twin controller: the global "contract UPDATE" right does
         // not scope the deletion to the user's entity perimeter on each row.
         $contractday->check((int) $key, UPDATE);
         $contractday->delete(['id' => (int) $key]);
      }
   }
   Html::back();

} else {
   $contract->checkGlobal(READ);

   Html::header(ContractDay::getTypeName(2), '', "management", Entity::class, "contractday");
   $contract->display($_GET);

   Html::footer();
}
