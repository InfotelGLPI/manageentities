<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\Entity;

if (!isset($_GET["id"])) {
    $_GET["id"] = "";
}
if (!isset($_GET["contract_id"])) {
    $_GET["contract_id"] = 0;
}
if (!isset($_GET["showFromPlugin"])) {
    $_GET["showFromPlugin"] = 0;
}

$contractday = new ContractDay();

if (isset($_POST["add"])) {
    $contractday->check(-1, UPDATE);
    $contractday->add($_POST);
    Html::back();

} elseif (isset($_POST["update"])) {
    $contractday->check($_POST["id"], UPDATE);
    $contractday->update($_POST);
    Html::back();

} elseif (isset($_POST["delete"])) {
    $contracts_id = $_POST["contracts_id"];
    $contractday->check($_POST["id"], UPDATE);
    $contractday->delete($_POST);
    Html::redirect(Toolbox::getItemTypeFormURL('Contract') . "?id=" . $contracts_id);

} elseif (isset($_POST["add_nbday"]) && isset($_POST['nbday'])) {
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

} elseif (isset($_POST["delete_nbday"])) {
    Session::checkRight("contract", UPDATE);
    foreach ($_POST["item_nbday"] as $key => $val) {
        if ($val == 1) {
            // Per-item check like the deleteAll branch: the global "contract UPDATE" right
            // does not scope the deletion to the user's entity perimeter on each row.
            $contractday->check((int) $key, UPDATE);
            $contractday->delete(['id' => (int) $key]);
        }
    }
    Html::back();

} elseif (isset($_POST["deleteAll"])) {
    foreach ($_POST["item"] as $key => $val) {
        $input = ['id' => $key];
        if ($val == 1) {
            $contractday->check($key, UPDATE);
            $contractday->delete($input);
        }
    }
    Html::back();

} else {
    Html::header(ContractDay::getTypeName(2), '', "management", Entity::class, "contractday");
    if (Session::haveRight("contract", READ)) {
        $contractday->display($_GET);
    }
    Html::footer();
}
