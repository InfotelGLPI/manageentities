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
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\GenerateCRI;

$GenerateCri = new GenerateCri();
$Cri         = new Cri();
$ticket                          = new Ticket();

// Every branch of this controller is about tickets. The floor is therefore settled once, before
// any branching, instead of being left to each branch - that is how the display branch ended up
// with no check at all.
Session::checkRight('ticket', READ);

// The ?active_entity= switch that used to sit here changed the active entity of the session on
// a plain GET, which the CSRF listener never validates: a link or an image pointing here moved
// the entity of whoever opened it. Nothing in the plugin produced that parameter any more, the
// entity selector of the core covers it.

if (isset($_POST['generatecri'])) {
    if (Session::haveRight('ticket', CREATE)) {

        $ko = $GenerateCri->checkMandatoryFields($_POST);
        if (!$ko) {
            $ticket_id = $GenerateCri->createTicketAndAssociateContract($_POST);
            if ($ticket_id) {
                $GenerateCri->createTasks($_POST, $ticket_id);
                $config = Config::getInstance();
                $ticket->update(['id'     => $ticket_id,
                    'status' => $config->getField('ticket_state')]);
                if (isset($_POST['description-undone']) && $_POST['description-undone'] != '') {
                    $_POST['content'] = $_POST['description-undone'];
                    $GenerateCri->createTicketTaskUndone($_POST, $ticket_id);
                }
                $GenerateCri->generateCri($_POST, $ticket_id, $Cri);
                if (!$config->getField('get_pdf_cri')) {
                    Html::back();
                }
            }
        } else {
            Html::back();
        }

    } else {
        throw new AccessDeniedHttpException();
    }

} elseif (isset($_GET['download'])) {
    $ticket_id = (int) ($_GET['tickets_id'] ?? 0);
    // IDOR: this branch had no authorization (unlike the generatecri POST branch above).
    // can() enforces the ticket READ right AND entity access before generating/exposing
    // its intervention report (task descriptions, times, technicians).
    if (!$ticket->can($ticket_id, READ)) {
        throw new AccessDeniedHttpException();
    }
    // generateCri() persists: it writes the report Document and upserts the CriDetail row,
    // which then drives the remaining days of the contract. Ticket READ is not enough for
    // that, every other report path requires the cri-create right.
    if (!$Cri->canCreate()) {
        throw new AccessDeniedHttpException();
    }
    // This branch is a GET, which the CSRF listener never validates: a link or an image
    // pointing here rewrote the report of whoever opened it. The only legitimate caller is
    // the redirect issued by the addcridetail POST of front/cri.form.php (CSRF-checked),
    // which arms a one-time token for the ticket; consume it or refuse.
    $pending = $_SESSION['plugin_manageentities_cri_download'] ?? [];
    if (!isset($pending[$ticket_id])) {
        throw new AccessDeniedHttpException();
    }
    unset($_SESSION['plugin_manageentities_cri_download'][$ticket_id]);

    // Rebuild the inputs from the report line of the ticket instead of the (empty) $_POST
    // of a GET, which rewrote the existing report "without contract" and detached it from
    // its contract balance.
    $inputs = [
        'entities_id'                           => (int) $ticket->fields['entities_id'],
        'contracts_id'                          => 0,
        'plugin_manageentities_contractdays_id' => 0,
    ];
    $cridetails = (new CriDetail())->find(['tickets_id' => $ticket_id]);
    $cridetail  = reset($cridetails);
    if (is_array($cridetail) && $cridetail['withcontract']) {
        $inputs['contracts_id']                          = (int) $cridetail['contracts_id'];
        $inputs['plugin_manageentities_contractdays_id'] = (int) $cridetail['plugin_manageentities_contractdays_id'];
    }
    $GenerateCri->generateCri($inputs, $ticket_id, $Cri);
} else {
    // The wizard used to be rendered with no authorization whatsoever, while the POST branch
    // that submits it requires ticket CREATE and the sibling listing front/generatecri.php
    // applies exactly this check before calling the very same showWizard(). Any authenticated
    // user could therefore reach the service contracts and the intervention periods of the
    // entity through showContractLinkDropdown(), and have $_SESSION['glpiactive_entity']
    // rewritten on the way. The check comes before Html::header() so a refusal does not ship a
    // rendered page shell.
    Session::checkRight('ticket', CREATE);

    Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", GenerateCri::class);
    $ticket->fields['itilcategories_id'] = $_POST['itilcategories_id'] ?? 0;
    $ticket->fields['type']              = $_POST['type'] ?? '';
    // The client picked in the wizard only drives the wizard itself. It used to be written into
    // $_SESSION['glpiactive_entity'] without glpiactiveentities, which left the session with an
    // active entity out of sync with its perimeter for every later screen.
    $entities_id = (int) $_SESSION['glpiactive_entity'];
    if (isset($_POST['entities_id']) && Session::haveAccessToEntity((int) $_POST['entities_id'])) {
        $entities_id = (int) $_POST['entities_id'];
    }

    $GenerateCri->showWizard($ticket, $entities_id);

}

if (Session::getCurrentInterface() == 'central') {
    Html::footer();
} else {
    Html::helpFooter();
}
