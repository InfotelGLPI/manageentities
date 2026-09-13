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

use Glpi\Event;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\CriDetail;

if (!isset($_POST["cri"])) {
    $_POST["cri"] = "";
}
if (!isset($_GET["action"])) {
    $_GET["action"] = "";
}

Html::popHeader(__('Generation of the intervention report', 'manageentities'));

$Cri           = new Cri();
$criDetail                         = new CriDetail();

if (isset($_POST["addcridetail"])) {
    if ($Cri->canCreate()) {
        // IDOR: bind the new report line to a ticket the user may actually read, so a forged
        // tickets_id cannot attach a cridetail to another entity's ticket. canCreate() only
        // checks the global cri-create right, not the targeted ticket/entity.
        $ticket = new Ticket();
        if ($ticket->can((int) ($_POST['tickets_id'] ?? 0), READ)) {
            // Never hand the raw body to add(): the form only carries the fields below, and
            // anything else posted alongside them would be written to the row as is. The entity
            // is taken from the ticket rather than from the hidden field, so the two can no
            // longer disagree.
            $input = [
                'tickets_id'                            => (int) $ticket->fields['id'],
                'entities_id'                           => (int) $ticket->fields['entities_id'],
                'date'                                  => $_POST['date'] ?? $ticket->fields['date'],
                'withcontract'                          => (int) ($_POST['withcontract'] ?? 0),
                'contracts_id'                          => (int) ($_POST['contracts_id'] ?? 0),
                'plugin_manageentities_contractdays_id' => (int) ($_POST['plugin_manageentities_contractdays_id'] ?? 0),
            ];
            if (!$input['withcontract']) {
                $input['contracts_id']                          = 0;
                $input['plugin_manageentities_contractdays_id'] = 0;
            }
            $criDetail->add($input);
        }
    }
    if (strpos($_SERVER['HTTP_REFERER'] ?? '', "generatecri.form.php") > 0) {
        Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . "/front/generatecri.form.php?download=1&tickets_id=" . (int) $_POST['tickets_id']);
    } else {
        Html::back();
    }

} elseif (isset($_POST["updatecridetail"])) {
    if ($Cri->canCreate()) {
        // IDOR: canCreate() is a global cri-create right. Reload the targeted row and enforce
        // access to its entity before updating, so a forged id cannot edit another entity's line.
        if (!$criDetail->getFromDB((int) $_POST['id'])
            || !Session::haveAccessToEntity($criDetail->fields['entities_id'])) {
            throw new AccessDeniedHttpException();
        }
        if (isset($_POST['withcontract']) && !$_POST['withcontract']) {
            $_POST['contracts_id']                          = 0;
            $_POST['plugin_manageentities_contractdays_id'] = 0;
        }
        $criDetail->update($_POST);
    }
    Html::back();

} elseif (isset($_POST["delcridetail"])) {
    if ($Cri->canCreate()) {
        // Same IDOR guard as updatecridetail: enforce entity access on the targeted row.
        if (!$criDetail->getFromDB((int) $_POST['id'])
            || !Session::haveAccessToEntity($criDetail->fields['entities_id'])) {
            throw new AccessDeniedHttpException();
        }
        $criDetail->delete($_POST);
    }
    Html::back();

} elseif (isset($_POST["purgedoc"])) {
    $doc            = new Document();
    $documents_id   = (int) $_POST['documents_id'];

    if ($doc->can($documents_id, PURGE)) {
        $input['id'] = $documents_id;
        if ($doc->delete($input, 1)) {
            Event::log($input['id'], "documents", 4, "document", $_SESSION["glpiname"] . " " . __('Delete permanently'));
        }
    }
    Html::back();

} else {
    $Cri->showForm($_GET["job"], ['action' => $_GET["action"]]);
}

Html::popFooter();
