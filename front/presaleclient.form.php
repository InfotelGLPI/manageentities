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

use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\PresaleClient;
use GlpiPlugin\Manageentities\PresaleWizard;

$client = new PresaleClient();

if (isset($_POST['update'])) {
    $client->check((int) $_POST['id'], UPDATE);
    $client->update($_POST);
    Html::back();
}

if (isset($_POST['purge'])) {
    $client->check((int) $_POST['id'], PURGE);
    $client->delete($_POST, true);
    $client->redirectToList();
}

// Prospects are created by the wizard, with their first proposal
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(PresaleWizard::getFormUrl() . '?step=' . PresaleWizard::STEP_CLIENT);
}

$client->check($id, READ);
Html::header(PresaleClient::getTypeName(2), '', 'management', Entity::class, 'presaleclient');
$client->display(['id' => $id]);
Html::footer();
