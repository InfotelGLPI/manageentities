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

use GlpiPlugin\Accounts\Account_Item;
use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\Followup;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Contact;
use GlpiPlugin\Manageentities\Contract;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();

$entity = new \Entity();
$ManagementitiesEntity = new Entity();
$Contact = new Contact();
$Contract = new Contract();
$Cri = new Cri();
$CriDetail = new CriDetail();
$followUp = new Followup();

if (!isset($_POST['plugin_manageentities_tab'])) {
    $_POST['plugin_manageentities_tab'] = $_SESSION['glpi_plugin_manageentities_tab'];
}

if (Session::getCurrentInterface() != 'helpdesk') {
    $entities = $_SESSION["glpiactiveentities"];
} else {
    $entities = [$_SESSION["glpiactive_entity"]];
}

switch ($_POST['plugin_manageentities_tab']) {
    case "follow-up" :
        $_SESSION['glpi_plugin_manageentities_tab'] = "follow-up";
        $followUp->showCriteriasForm($_POST);
        $followUp->showFollowUp($entities, $_POST);
        break;
    case "description" :
        $_SESSION['glpi_plugin_manageentities_tab'] = "description";
        $ManagementitiesEntity->showDescription($entities);
        $Contact->showContacts($entities);
        break;
    case "tickets" :
        $_SESSION['glpi_plugin_manageentities_tab'] = "tickets";
//      $ManagementitiesEntity->showTickets($entities);
        break;
    case "reports":
        $_SESSION['glpi_plugin_manageentities_tab'] = "reports";
        $CriDetail->showReports(0, 0, $entities);
        break;
    case "documents":
        $_SESSION['glpi_plugin_manageentities_tab'] = "documents";
        if (Session::haveRight("Document", READ) && $entity->can($entities, READ)) {
            Document_Item::showForItem($entity);
        }
        break;
    case "contract":
        $_SESSION['glpi_plugin_manageentities_tab'] = "contract";
        if (Session::haveRight("Contract", READ)) {
            $Contract->showContracts($entities);
        }
        break;
    case "accounts":
        $_SESSION['glpi_plugin_manageentities_tab'] = "accounts";
        Account_Item::showForItem($entities);
        break;
    case "all":
        $_SESSION['glpi_plugin_manageentities_tab'] = "all";
        $ManagementitiesEntity->showDescription($entities);
        $Contact->showContacts($entities);
//      $ManagementitiesEntity->showTickets($entities);
        if ($Cri->canView()) {
            $CriDetail->showReports(0, 0, $entities);
        }
        if (Session::haveRight("Document", READ) && $entity->can($entities, READ)) {
            Document_Item::showForItem($entity);
        }
        if (Session::haveRight("Contract", READ)) {
            $Contract->showContracts($entities);
        }

        break;
    default :
        break;
}

?>
