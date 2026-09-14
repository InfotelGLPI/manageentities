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

use GlpiPlugin\Manageentities\Company;
use GlpiPlugin\Manageentities\Entity;

$company = new Company();

if (isset($_POST["add"])) {
    // The input has to be passed so the entity carried by the form is the one checked: without
    // it can(-1, CREATE) falls back on the empty item, whose entities_id is the active entity,
    // and the destination chosen in the dropdown is never compared to the perimeter.
    $company->check(-1, CREATE, $_POST);
    $newID = $company->add($_POST);
    if ($_SESSION['glpibackcreated']) {
        Html::redirect($company->getFormURL() . "?id=" . $newID);
    }
    Html::back();
} elseif (isset($_POST["update"])) {
    $company->check($_POST["id"], UPDATE);
    $company->update($_POST);
    Html::back();
} elseif (isset($_POST["purge"])) {
    $company_id = $_POST["id"];
    $company->check($_POST["id"], PURGE);
    $company->delete($_POST, 1);
    $company->redirectToList();
} else {
    Html::header(Company::getTypeName(2), '', "management", Entity::class, "company");
    $company->display($_GET);
    Html::footer();
}
