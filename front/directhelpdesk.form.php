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
use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Servicecatalog\Main;

if (Session::haveRight("plugin_manageentities", UPDATE)) {
    $direct = new DirectHelpdesk();

    if (isset($_POST["create_ticket"])) {
        // The target entity comes straight from the POST body: enforce that the user
        // actually has access to it before creating a ticket scoped to that entity.
        $entities_id = (int) ($_POST["entities_id"] ?? -1);
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
        $ticket = new Ticket();
        $items = $_POST["select"];
        $sum = 0;
        $input['content'] = '';
        // Only the target entity was validated above. The selected lines come from
        // $_POST['select'] by id: skip any line whose entity the user cannot access before
        // disclosing its data into the ticket content or flagging it as billed (IDOR).
        $selected = [];
        foreach ($items as $item => $check) {
            if ($check == "on") {
                $direct = new DirectHelpdesk();
                if (!$direct->getFromDB((int) $item)
                    || !Session::haveAccessToEntity($direct->fields['entities_id'])) {
                    continue;
                }
                $selected[] = (int) $item;

                $actiontime = $direct->fields['actiontime'];
                $sum += $actiontime;
                $input['entities_id'] = $_POST["entities_id"];
                $input['name'] = __('New intervention', 'manageentities') . " : " . CommonITILObject::getActionTime(
                        $sum
                    );
                $input['content'] .= Html::convDate(
                        $direct->fields['date']
                    ) . " : " . $direct->fields['name'] . " - " . getUserName(
                        $direct->fields['users_id']
                    ) . " (" . CommonITILObject::getActionTime($actiontime) . ")<br>";

                $input['_users_id_assign'][] = $direct->fields['users_id'];
            }
        }

        $newID = $ticket->add($input);

        foreach ($selected as $item) {
            if ($newID > 0) {
                $inputd['id'] = $item;
                $inputd['is_billed'] = 1;
                $inputd['tickets_id'] = $newID;
                $direct->update($inputd);
            }
        }

        Html::redirect($ticket->getLinkURL());

//        Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", "DirectHelpdesk::class);
//        $options['entities_id'] = $_POST['entities_id'];
//        $direct = new DirectHelpdesk();
//        $options['content'] = "";
//        $options['_created_from_directhelpdesk'] = true;

//        $ticket = new Ticket();
//        $ticket->showForm(0, $options);
//        Html::footer();
    } elseif (isset($_POST["add"])) {
        // Per-object check like the update branch: the global plugin UPDATE right is
        // not scoped by entity, so enforce CREATE (with the posted entities_id) before
        // inserting the row.
        $direct->check(-1, CREATE, $_POST);
        $inter = $direct->add($_POST);

        Html::back();
    } elseif (isset($_POST["update"])) {
        $direct->check($_POST["id"], UPDATE);
        $direct->update($_POST);

        Html::back();
    } elseif (isset($_POST["delete"])) {
        // Forced purge below: enforce the per-row PURGE right (existence + entity access)
        // instead of relying only on the file-wide global UPDATE right.
        $direct->check((int) $_POST["id"], PURGE);
        $direct->delete($_POST, 1);
        Html::back();
    } else {
        $direct->checkGlobal(READ);

        if (Session::getCurrentInterface() == 'central') {
            Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", DirectHelpdesk::class);
        } else {
            if (Plugin::isPluginActive('servicecatalog')) {
                Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
            } else {
                Html::helpHeader(__('Entities portal', 'manageentities'));
            }
        }
        $direct->display($_GET);

        if (Session::getCurrentInterface() == 'central') {
            Html::footer();
        } else {
            Html::helpFooter();
        }
    }
} else {
    throw new AccessDeniedHttpException();
}
