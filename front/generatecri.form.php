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
use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\GenerateCRI;

Session::checkLoginUser();

$GenerateCri = new GenerateCri();
$Cri         = new Cri();
$ticket                          = new Ticket();

if (count($_SESSION["glpiactiveentities"]) > 1
    && isset($_GET['active_entity'])) {

   if (!isset($_POST["is_recursive"])) {
      $_POST["is_recursive"] = 0;
   }
   if (Session::changeActiveEntities($_GET["active_entity"], $_POST["is_recursive"])) {
      if ($_GET["active_entity"] == $_SESSION["glpiactive_entity"]) {
         global $CFG_GLPI;
         $referer = $_SERVER['HTTP_REFERER'] ?? '';
         $cleaned = preg_replace("/entities_id.*/", "", $referer);
         if (!empty($cleaned) && str_starts_with($cleaned, $CFG_GLPI['url_base'])) {
             Html::redirect($cleaned);
         } else {
             Html::back();
         }
      }
   }
}

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
            //            $_POST['download'] = true;
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

} else if (isset($_GET['download'])) {
   $ticket_id = $_GET['tickets_id'];
    $GenerateCri->generateCri($_POST, $ticket_id, $Cri);
} else {
   Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", GenerateCri::class);
   $ticket->fields['itilcategories_id'] = $_POST['itilcategories_id'] ?? 0;
   $ticket->fields['type']              = $_POST['type'] ?? '';
   $_SESSION['glpiactive_entity']       = $_POST['entities_id'] ?? 0;
   $_SESSION['glpiactive_entity']       = $_POST['entities_id'] ?? 0;

    $GenerateCri->showWizard($ticket, $_SESSION['glpiactive_entity']);
   Html::footer();

}

if (Session::getCurrentInterface() == 'central') {
   Html::footer();
} else {
   Html::helpFooter();
}
