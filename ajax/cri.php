<?php
/*
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2013 by the manageentities Development Team.
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 -------------------------------------------------------------------------- 
*/
include ('../../../inc/includes.php');

Html::header_nocache ();
Session::checkLoginUser();

switch ($_POST['action']) {
   case 'showCriForm' :
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      $params = $_POST["params"];

      $PluginManageentitiesCri->showForm($params["job"], array('action'   => $params["pdf_action"], 
                                                               'modal'    => $_POST["modal"], 
                                                               'toupdate' => $params["toupdate"]));
      break;
   
   case 'addCri':
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      if ($PluginManageentitiesCri->canCreate()) {
         $input = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];
         $input->enregistrement = false;
         if (isset($input->REPORT_ACTIVITE) && $input->REPORT_ACTIVITE) {
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
         } elseif(isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
         }else{
            echo json_encode(array('success' => false, 
                                   'message' => __('Thanks to select a intervention type', 'manageentities')));
         }
      }
      break;
   
   case 'updateCri':
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      if ($PluginManageentitiesCri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];

         $input->enregistrement = false;
         if ($input->REPORT_ACTIVITE) {
            // Purge cri 
            $criDetail = new PluginManageentitiesCriDetail();
            $data_criDetail = $criDetail->find('tickets_id = '.$input->REPORT_ID);
            $data_criDetail = reset($data_criDetail);
            $input->documents_id = $data_criDetail['documents_id'];
            // Generate a new cri
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
        
         } elseif(isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
         }else{
            echo json_encode(array('success' => false, 
                                   'message' => __('Thanks to select a intervention type', 'manageentities')));
         }
      }
      break;
      
   case 'saveCri':
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      if ($PluginManageentitiesCri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];
         $input->enregistrement = true;
         if ($input->REPORT_ACTIVITE) {
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
         } elseif(isset($input->WITHOUTCONTRACT) && $input->WITHOUTCONTRACT) {
            $ticket = new Ticket();
            $ticket->getFromDB($params['job']);
            $input->REPORT_ACTIVITE = $ticket->fields['name'];
            $PluginManageentitiesCri->generatePdf($input,
                                                  array('modal'    => $_POST["modal"], 
                                                        'toupdate' => $params["toupdate"]));
         }else{
            echo json_encode(array('success' => false, 
                                   'message' => __('Thanks to select a intervention type', 'manageentities')));
         }
      }
      break;
      
   case 'deleteTech':
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      if ($PluginManageentitiesCri->canCreate()) {
         $input  = json_decode(stripslashes($_POST["formInput"]));
         $params = $_POST["params"];
         $PluginManageentitiesCriTechnician = new PluginManageentitiesCriTechnician();
         $PluginManageentitiesCriTechnician->deleteByCriteria(array('users_id' => $params['tech_id']));
         
         $PluginManageentitiesCri->showForm($params["job"], array('action'   => $params["pdf_action"], 
                                                                  'modal'    => $_POST["modal"], 
                                                                  'toupdate' => $params["toupdate"]));
      }
      break;
      
   case 'addTech':
      $PluginManageentitiesCri = new PluginManageentitiesCri();
      if ($PluginManageentitiesCri->canCreate()) {
         $input                             = json_decode(stripslashes($_POST["formInput"]));
         $params                            = $_POST["params"];
         
         $toadd["users_id"]                 = $input->users_id;
         $toadd["tickets_id"]               = $params["job"];
         $PluginManageentitiesCriTechnician = new PluginManageentitiesCriTechnician();
         $PluginManageentitiesCriTechnician->add($toadd);
         
         $PluginManageentitiesCri->showForm($params["job"], array('action'   => $params["pdf_action"], 
                                                                  'modal'    => $_POST["modal"], 
                                                                  'toupdate' => $params["toupdate"]));
      }
      break;
}

?>
