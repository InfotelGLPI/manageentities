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
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\CriTechnician;

Html::header_nocache();
Session::checkLoginUser();

// canCreate() only checks the global plugin_manageentities_cri_create right, never the
// entity of the targeted ticket. The write actions below read/write ticket data from a
// client-controlled id (params["job"] / input->REPORT_ID), so each must additionally
// enforce READ access to that ticket (right + entity), as showCriForm already does.
$assertTicketReadable = static function (int $tickets_id): void {
    $ticket = new Ticket();
    if (!$ticket->can($tickets_id, READ)) {
        throw new AccessDeniedHttpException();
    }
};

switch ($_POST['action']) {
   case 'showCriForm' :
      $Cri = new Cri();
      $params                  = $_POST["params"];

      // Authorization: unlike the other actions (guarded by canCreate()), this one had
      // no check. Require the CRI-create right (same gate as the sibling actions) AND
      // read access to the underlying ticket before rendering its report (CRI) form.
      $ticket = new Ticket();
      if (!$Cri->canCreate()
          || !$ticket->can((int) $params["job"], READ)) {
          throw new AccessDeniedHttpException();
      }

       $Cri->showForm($params["job"], ['action'   => $params["pdf_action"],
                                                          'modal'    => $_POST["modal"],
                                                          'toupdate' => $params["toupdate"]]);
      break;

   case 'addTech':
       $Cri = new Cri();
      if ($Cri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];
         $assertTicketReadable((int) $params["job"]);

         $toadd["users_id"]                 = $input->users_id;
         $toadd["tickets_id"]               = $params["job"];
          $CriTechnician = new CriTechnician();
          $CriTechnician->add($toadd);

          $Cri->showForm($params["job"], ['action'   => $params["pdf_action"],
                                                             'modal'    => $_POST["modal"],
                                                             'toupdate' => $params["toupdate"]]);
      }
      break;

   case 'deleteTech':
       $Cri = new Cri();
      if ($Cri->canCreate()) {
         $input                             = json_decode(stripslashes($_POST["formInput"]));
         $params                            = $_POST["params"];
         $assertTicketReadable((int) $params["job"]);
         $CriTechnician = new CriTechnician();
          // Scope the deletion to the current ticket: without tickets_id, this would
          // wipe the technician's CriTechnician rows across every ticket (data loss).
          $CriTechnician->deleteByCriteria([
              'users_id'   => (int) $params['tech_id'],
              'tickets_id' => (int) $params['job'],
          ]);

          $Cri->showForm($params["job"], ['action'   => $params["pdf_action"],
                                                             'modal'    => $_POST["modal"],
                                                             'toupdate' => $params["toupdate"]]);
      }
      break;

   case 'addCri':
       $Cri = new Cri();
      if ($Cri->canCreate()) {

         $input                     = json_decode(stripslashes($_POST["formInput"]));
         $input->REPORT_DESCRIPTION = urldecode($input->REPORT_DESCRIPTION);
         $params                    = $_POST["params"];
         $assertTicketReadable((int) $params["job"]);
         $assertTicketReadable((int) ($input->REPORT_ID ?? 0));
         $input->enregistrement     = false;
         if (isset($input->REPORT_ACTIVITE) && $input->REPORT_ACTIVITE) {
            $input->REPORT_ACTIVITE_ID = $input->REPORT_ACTIVITE;
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } elseif (isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } else {
            echo json_encode(['success' => false,
                              'message' => __('Thanks to select a intervention type', 'manageentities')]);
         }
      }
      break;

   case 'updateCri':
       $Cri = new Cri();
      if ($Cri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];
         $assertTicketReadable((int) $params["job"]);
         $assertTicketReadable((int) ($input->REPORT_ID ?? 0));

         $input->enregistrement = false;
         if (isset($input->REPORT_ACTIVITE)) {
            // Purge cri
            $input->REPORT_ACTIVITE_ID = $input->REPORT_ACTIVITE;
            $criDetail                 = new CriDetail();
            $data_criDetail            = $criDetail->find(['tickets_id' => $input->REPORT_ID]);
            $data_criDetail            = reset($data_criDetail);
            $input->documents_id       = $data_criDetail['documents_id'];
            // Generate a new cri
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);

         } elseif (isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } else {
            echo json_encode(['success' => false,
                              'message' => __('Thanks to select a intervention type', 'manageentities')]);
         }
      }
      break;

   case 'saveCri':
       $Cri = new Cri();
      if ($Cri->canCreate()) {
         $input                 = json_decode(stripslashes($_POST["formInput"]));
         $params                = $_POST["params"];
         $assertTicketReadable((int) $params["job"]);
         $assertTicketReadable((int) ($input->REPORT_ID ?? 0));
         $input->enregistrement = true;
         if ($input->REPORT_ACTIVITE) {
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } elseif (isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } else {
            echo json_encode(['success' => false,
                              'message' => __('Thanks to select a intervention type', 'manageentities')]);
         }
      }
      break;

}
