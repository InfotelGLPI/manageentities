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
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Manageentities\BusinessContact;
use GlpiPlugin\Servicecatalog\Main;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\Contact;
use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Manageentities\EditorSubscription;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\TechLead;
use GlpiPlugin\Manageentities\TicketOverview;

// CSV exports — must run before any Html::header() output
if (isset($_GET['export']) && $_GET['export'] === 'subscriptions') {
    EditorSubscription::exportCsv();
    // exportCsv() calls exit — code below never reached
}
if (isset($_GET['export']) && $_GET['export'] === 'unbilled') {
    DirectHelpdesk::exportUnbilledCsv();
    // same: the method exits once the file is written
}

$Contract        = new Contract();
$Contact         = new Contact();
$ManageentitiesEntity          = new Entity();
$BusinessContact = new BusinessContact();
$TechLead        = new TechLead();

if (!isset($_POST["entities_id"])) {
    $_POST["entities_id"] = "";
}

if (Session::getCurrentInterface() == 'central') {
    Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);
} else {
    if (Plugin::isPluginActive('servicecatalog')) {
        Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
    } else {
        Html::helpHeader(__('Entities portal', 'manageentities'));
    }
}

if ($ManageentitiesEntity->canView()
    || Session::haveRight("config", UPDATE)) {

    if (isset($_POST["addcontracts"])) {
        // can(-1, CREATE, $input) enforces the CREATE right AND the target entity from
        // the posted input, instead of the entity-agnostic global canCreate().
        if ($Contract->can(-1, CREATE, $_POST)) {
            $Contract->add($_POST);
        }
        Html::back();

    } elseif (isset($_POST["deletecontracts"])) {
        // can($id, DELETE) reloads the row and enforces the DELETE right AND entity
        // access on it, preventing a cross-entity IDOR delete via a forged id.
        if ($Contract->can((int) $_POST["id"], DELETE)) {
            $Contract->delete(['id' => (int) $_POST["id"]]);
        }
        Html::back();

    } elseif (isset($_POST["contractbydefault"])) {
        // Align with the sibling branches: canCreate() is entity-agnostic. Enforce access to
        // the posted entity AND reload the target row via can($id, UPDATE) before flipping the
        // default flag, preventing a cross-entity IDOR.
        if (
            Session::haveAccessToEntity((int) $_POST["entities_id"])
            && $Contract->can((int) $_POST["myid"], UPDATE)
        ) {
            $Contract->addContractByDefault((int) $_POST["myid"], (int) $_POST["entities_id"]);
        }
        Html::back();

    } elseif (isset($_POST["addcontacts"])) {
        if ($Contact->can(-1, CREATE, $_POST)) {
            $Contact->add($_POST);
        }
        Html::back();

    } elseif (isset($_POST["deletecontacts"])) {
        if ($Contact->can((int) $_POST["id"], DELETE)) {
            $Contact->delete(['id' => (int) $_POST["id"]]);
        }
        Html::back();

    } elseif (isset($_POST["addbusiness"])) {
        if ($BusinessContact->can(-1, CREATE, $_POST)) {
            $BusinessContact->add($_POST);
        }
        Html::back();

    } elseif (isset($_POST["deletebusiness"])) {
        if ($BusinessContact->can((int) $_POST["id"], DELETE)) {
            $BusinessContact->delete(['id' => (int) $_POST["id"]]);
        }
        Html::back();

    } elseif (isset($_POST["addtechlead"])) {
        if ($TechLead->can(-1, CREATE, $_POST)) {
            $TechLead->add($_POST);
        }
        Html::back();

    } elseif (isset($_POST["deletetechlead"])) {
        // Same right as the business contacts: PURGE is only granted to the full profile
        if ($TechLead->can((int) $_POST["id"], DELETE)) {
            $TechLead->delete(['id' => (int) $_POST["id"]], true);
        }
        Html::back();

    } elseif (isset($_POST["techleadbydefault"])) {
        // can($id, UPDATE) reloads the row and enforces entity access on it
        if ($TechLead->can((int) $_POST["id"], UPDATE)) {
            $TechLead->setAsDefault((int) $_POST["id"]);
        }
        Html::back();

    } elseif (isset($_POST["toggletechleadbydefault"])) {
        // One-click switch from the "Tech lead by clients" tab: the main tech lead
        // stops being the main one, any other one becomes the main one
        if ($TechLead->can((int) $_POST["id"], UPDATE)) {
            if ($TechLead->fields['is_default']) {
                $TechLead->update(['id' => $TechLead->getID(), 'is_default' => 0]);
            } else {
                $TechLead->setAsDefault($TechLead->getID());
            }
        }
        Html::back();

    } elseif (isset($_POST["contactbydefault"])) {
        // Align with the sibling branches: canCreate() is entity-agnostic. Enforce access to
        // the posted entity AND reload the target row via can($id, UPDATE) before flipping the
        // default flag, preventing a cross-entity IDOR.
        if (
            Session::haveAccessToEntity((int) $_POST["entities_id"])
            && $Contact->can((int) $_POST["contacts_id"], UPDATE)
        ) {
            $Contact->addContactByDefault((int) $_POST["contacts_id"], (int) $_POST["entities_id"]);
        }
        Html::back();

    } else {
        // Manage entity change. The switch happens right here, in the CSRF-checked POST: it
        // used to be delegated to a ?active_entity= GET, which the CSRF listener never
        // validates, so a link or an image pointing at it moved the active entity of whoever
        // opened it. changeActiveEntities() refuses an entity outside the caller's scope.
        if (isset($_POST["choice_entity"]) && $_POST["entities_id"] != 0) {
            $active_entity = (int) $_POST["entities_id"];
            if ($active_entity <= 0) {
                throw new BadRequestHttpException();
            }
            if (!Session::changeActiveEntities($active_entity)) {
                throw new AccessDeniedHttpException();
            }
            Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php");

        } else {
            if (Session::getCurrentInterface() == 'central') {
                $dateYear = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y") - 1));
            } else {
                $dateYear = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y") - 10));
            }
            $lastday = cal_days_in_month(CAL_GREGORIAN, date("m"), date("Y"));

            if (date("d") == $lastday) {
                $dateMonthend   = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
                $dateMonthbegin = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y")));
            } else {
                $month   = date("m");
                $lastday = $month == 1 ? 31 : cal_days_in_month(CAL_GREGORIAN, $month - 1, date("Y"));
                $dateMonthend   = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, $lastday, date("Y")));
                $dateMonthbegin = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, 1, date("Y")));
            }
            $options = ["begin_date_after"  => isset($_POST['begin_date_after']) ? $_POST['begin_date_after'] : $dateYear,
                "begin_date_before" => isset($_POST['begin_date_before']) ? $_POST['begin_date_before'] : "",
                "begin_date"        => isset($_POST['begin_date']) ? $_POST['begin_date'] : $dateMonthbegin,
                "end_date"          => isset($_POST['end_date']) ? $_POST['end_date'] : $dateMonthend,
                "end_date_after"    => isset($_POST['end_date_after']) ? $_POST['end_date_after'] : "",
                "end_date_before"   => isset($_POST['end_date_before']) ? $_POST['end_date_before'] : "",
                "contract_states"   => isset($_POST['contract_states']) ? $_POST['contract_states'] : -1,
                "entities_id"       => (isset($_POST['entities_id']) && (!empty($_POST['entities_id']))) ? $_POST['entities_id'] : -1,
                "business_id"       => isset($_POST['business_id']) ? $_POST['business_id'] : -1,
                "company_id"        => isset($_POST['company_id']) ? $_POST['company_id'] : 0,
                "year_current"      => isset($_POST['year_current']) ? $_POST['year_current'] : 0,
                "stale_weeks"       => TicketOverview::sanitizeStaleWeeks($_POST['stale_weeks'] ?? TicketOverview::DEFAULT_STALE_WEEKS),
                "ticket_type"       => TicketOverview::sanitizeType($_POST['ticket_type'] ?? TicketOverview::ALL_TYPES)];

            $entity = new Entity();
            $entity->display($options);
        }
    }

} else {
    throw new AccessDeniedHttpException();
}

if (Session::getCurrentInterface() != 'central'
    && Plugin::isPluginActive('servicecatalog')) {

    Main::showNavBarFooter('manageentities');
}

if (Session::getCurrentInterface() == 'central') {
    Html::footer();
} else {
    Html::helpFooter();
}
