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
use GlpiPlugin\Manageentities\PresaleProposal;
use GlpiPlugin\Manageentities\PresaleWizard;

$proposal = new PresaleProposal();

if (isset($_POST['purge'])) {
    $proposal->check((int) $_POST['id'], PURGE);
    $proposal->delete($_POST, true);
    $proposal->redirectToList();
}

// Generate, won, lost, reactivate (step 7 and summary of the proposal)
if (isset($_POST['presale_action'])) {
    PresaleWizard::handleAction($_POST);
}

// Proposals are created and edited through the wizard only
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    Html::redirect(PresaleWizard::getFormUrl());
}

$proposal->check($id, READ);
Html::header(PresaleProposal::getTypeName(2), '', 'management', Entity::class, 'presaleproposal');
$proposal->display(['id' => $id]);
Html::footer();
