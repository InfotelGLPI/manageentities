<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */


use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\CriTechnician;

Html::header_nocache();
Session::checkLoginUser();


switch ($_POST['action']) {
   case 'showCriForm' :
      $Cri = new Cri();
      $params                  = $_POST["params"];

       $Cri->showForm($params["job"], ['action'   => $params["pdf_action"],
                                                          'modal'    => $_POST["modal"],
                                                          'toupdate' => $params["toupdate"]]);
      break;

   case 'addTech':
       $Cri = new Cri();
      if ($Cri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];

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
         $CriTechnician = new CriTechnician();
          $CriTechnician->deleteByCriteria(['users_id' => $params['tech_id']]);

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
         $input->enregistrement     = false;
         if (isset($input->REPORT_ACTIVITE) && $input->REPORT_ACTIVITE) {
            $input->REPORT_ACTIVITE_ID = $input->REPORT_ACTIVITE;
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } elseif (isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'] ?? '';
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
            $input->REPORT_ACTIVITE = $ticket->fields['name'] ?? '';
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
         $input->enregistrement = true;
         if ($input->REPORT_ACTIVITE) {
             $Cri->generatePdf($input,
                                                  ['modal'    => $_POST["modal"],
                                                   'toupdate' => $params["toupdate"]]);
         } elseif (isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'] ?? '';
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
