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
    // Same reasoning as the add_nbday branch below: check(-1, UPDATE) without the posted body
    // evaluated an empty object whose entities_id is 0, so the submitted entity was never
    // confronted with the caller perimeter. Pass the body, and make sure the parent contract
    // really lives in the targeted entity before hanging a day off it.
    $contractday->check(-1, CREATE, $_POST);
    $entities_id  = (int) ($_POST['entities_id'] ?? -1);
    $coreContract = new \Contract();
    if (
        !$coreContract->getFromDB((int) ($_POST['contracts_id'] ?? 0))
        || (int) $coreContract->fields['entities_id'] !== $entities_id
    ) {
        throw new AccessDeniedHttpException();
    }
    $contractday->add($_POST);
    Html::back();

} elseif (isset($_POST["update"])) {
    $contractday->check($_POST["id"], UPDATE);
    // check() validates the stored row only: pin the ownership columns to it, so the
    // posted body cannot move the period to another entity or contract
    $input                 = $_POST;
    $input['entities_id']  = (int) $contractday->fields['entities_id'];
    $input['contracts_id'] = (int) $contractday->fields['contracts_id'];
    $contractday->update($input);
    Html::back();

} elseif (isset($_POST["delete"])) {
    // Separation of duties: the row is really removed from the table (no is_deleted column),
    // so evaluate the PURGE bit - the deletion bit exposed by the rights matrix of
    // 'plugin_manageentities' - and not UPDATE, which an administrator may want to grant alone.
    $contractday->check($_POST["id"], PURGE);
    // Redirect to the contract of the loaded row, not to a posted value
    $contracts_id = (int) $contractday->fields['contracts_id'];
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
    // The rows being deleted belong to the plugin, so gate the branch on the plugin right and
    // on the bit that matches the operation, rather than on the core "contract UPDATE" right.
    Session::checkRight(ContractDay::$rightname, PURGE);
    foreach ($_POST["item_nbday"] as $key => $val) {
        if ($val == 1) {
            // Per-item check like the deleteAll branch: the global right does not scope the
            // deletion to the user's entity perimeter on each row.
            $contractday->check((int) $key, PURGE);
            $contractday->delete(['id' => (int) $key]);
        }
    }
    Html::back();

} elseif (isset($_POST["deleteAll"])) {
    foreach ($_POST["item"] as $key => $val) {
        $input = ['id' => $key];
        if ($val == 1) {
            $contractday->check($key, PURGE);
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
