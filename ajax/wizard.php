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

include('../../../inc/includes.php');

use GlpiPlugin\Manageentities\WizardController;

Session::checkLoginUser();
Session::checkRight('plugin_manageentities', UPDATE);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'save_entity':
        WizardController::saveEntity();
        break;

    case 'save_contacts':
        WizardController::saveContacts();
        break;

    case 'add_contact_block':
        WizardController::renderContactBlock();
        break;

    case 'save_contract':
        WizardController::saveContract();
        break;

    case 'load_contract_template':
        WizardController::loadContractTemplate();
        break;

    case 'add_document_block':
        WizardController::renderDocumentBlock();
        break;

    case 'upload_documents':
        WizardController::uploadDocuments();
        break;

    case 'save_management_type':
        WizardController::saveManagementType();
        break;

    case 'save_interventions':
        WizardController::saveInterventions();
        break;

    case 'finish_wizard':
        WizardController::finishWizard();
        break;

    case 'add_intervention_block':
        WizardController::renderInterventionBlock();
        break;

    case 'save_intervention':
        WizardController::saveIntervention();
        break;

    case 'add_criprice_block':
        WizardController::renderCriPriceBlock();
        break;

    case 'save_criprice':
        WizardController::saveCriPrice();
        break;

    case 'delete_criprice':
        WizardController::deleteCriPrice();
        break;

    case 'add_stakeholder':
        WizardController::addStakeholder();
        break;

    case 'delete_document':
        WizardController::deleteDocument();
        break;

    case 'delete_stakeholder':
        WizardController::deleteStakeholder();
        break;

    case 'choose_mode':
        WizardController::chooseMode();
        break;

    case 'get_reset_summary':
        WizardController::getResetSummary();
        break;

    case 'reset_and_delete':
        WizardController::resetAndDelete();
        break;

    case 'reset':
        WizardController::reset();
        break;

    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}
