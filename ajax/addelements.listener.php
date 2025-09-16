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


use GlpiPlugin\Manageentities\AddElementsModel;
use GlpiPlugin\Manageentities\AddElementsView;
use GlpiPlugin\Manageentities\ContractDay;

Html::header_nocache();
Session::checkLoginUser();

$pModel = AddElementsModel::getInstance();
$pView  = new AddElementsView();

// checker le $_POST['criprice_id']

switch ($_POST['action']) {
    case \GlpiPlugin\Manageentities\Action::ADD_ONLY_ENTITY:
       // if all fields filled
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            $pView->showResults($addEntityOK);
        }

        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ONLY_CONTACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel->getContacts($_POST['fakeid_new_contact']));
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $addContactOK = $pModel->addContactToBase($pView);
            $pView->showResults($addContactOK);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ONLY_CONTRACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());
        $addContractOK = false;
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("contract_name", false, false);
            $addContractOK = $pModel->addContractToBase($pView);
            $pView->showResults($addContractOK);
           //               $pView->showCriPriceForm();
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ONLY_INTERVENTION:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($_POST['fakeid_new_intervention']));
        $addInterventionOK = false;
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_INTERVENTION, \GlpiPlugin\Manageentities\Errors::ERROR_ADD, $_POST['fakeid_new_intervention']);
            $addInterventionOK = $pModel->addInterventionToBase($pView);
           //         INFOTEL : MODIFICATION PRESALES
            $interventions  = $pModel->getContractDays();
            $nbIntervention = $pModel->getNbContractDays();
            if (isset($_POST["presales"]) && isset($interventions[$nbIntervention + 1])) {
                $pModel->setNbContractDays($nbIntervention + 1);
            }
           //        INFOTEL
            $pView->showResults($addInterventionOK);
        }
        break;


    case \GlpiPlugin\Manageentities\Action::SHOW_FORM_CRI_PRICE:
        $pView->showFormCriPrice($_POST['id_criprice'], $_POST['id_intervention'], $_POST['fakeid_new_intervention'], ["parent" => $_POST['parent']]);
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_CRI_PRICE:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CRIPRICE, $pModel->getCriPrice($_POST['fakeid_new_intervention'], $_POST['new_criprice_critypes']));
        $addCriPrice = false;
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CRIPRICE, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CRIPRICE, \GlpiPlugin\Manageentities\Errors::ERROR_ADD, $_POST['fakeid_new_intervention']);
            $addCriPrice = $pModel->addCripriceToBase($pView, $_POST['fakeid_new_intervention'], $_POST['new_criprice_critypes']);
            $pView->showResults($addCriPrice);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::DELETE_CRI_PRICE:
        if (isset($_POST['id_criprice'])) {
            $deleteCriPrice = $pModel->deleteCriPrice($pView, $_POST['fakeid_new_intervention'], $_POST['id_criprice']);
            $pView->showResults($deleteCriPrice);
            $pView->updateCriPriceFromType(null, null);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ENTITY_AND_CONTACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel->getContacts($_POST['fakeid_new_contact']));

       // Add entity
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            if ($addEntityOK['result'] == Status::ADDED) {
                // If success, add contact
                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel)) {
                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                    $addContactOK = $pModel->addContactToBase($pView);
                    if ($addContactOK['result'] == Status::ADDED) {
                        // If success, show message 'entity and contract added'
                        $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::ENTITY . \GlpiPlugin\Manageentities\ElementType::CONTACT, Status::ADDED), Messages::MESSAGE_INFO);
                    } else {
                      // If fail, show error
                        $pView->showResults($addContactOK);
                    }
                }
            } else {
               // if fail, show error
                $pView->showResults($addEntityOK);
            }
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ENTITY_AND_CONTRACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());
       // Add entity
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            if ($addEntityOK['result'] == Status::ADDED) {
                // If success, add contract
                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel)) {
                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                    $addContractOK = $pModel->addContractToBase($pView);
                    if ($addContractOK['result'] == Status::ADDED) {
                        // If success, show message 'entity and contract added'
                        $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::ENTITY . \GlpiPlugin\Manageentities\ElementType::CONTRACT, Status::ADDED), Messages::MESSAGE_INFO);
                    } else {
                      // If fail, show error
                        $pView->showResults($addContractOK);
                    }
                }
            } else {
               // if fail, show error
                $pView->showResults($addEntityOK);
            }
        }

        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ENTITY_AND_INTERVENTION:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($_POST['fakeid_new_intervention']));

        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
           // add entity
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            if ($addEntityOK['result'] == Status::ADDED) {
                // If success, add intervention
                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel)) {
                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_INTERVENTION, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                    $addInterventionOK = $pModel->addInterventionToBase($pView);
                    if ($addInterventionOK['result'] == Status::ADDED) {
                        // If success, show message 'entity and contract added'
                        $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::ENTITY . \GlpiPlugin\Manageentities\ElementType::INTERVENTION, Status::ADDED), Messages::MESSAGE_INFO);
                    } else {
                      // If fail, show error
                        $pView->showResults($addInterventionOK);
                    }
                }
            } else {
               // if fail, show error
                $pView->showResults($addEntityOK);
            }
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_ENTITY_INTERVENTION_AND_CONTRACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($_POST['fakeid_new_intervention']));
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
           // add entity
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            if ($addEntityOK['result'] == Status::ADDED) {
                // If success, add contract
                // contract name
                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel)) {
                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                    $pView->bordersOnError("entity_name", false, false);
                    $addContractOK = $pModel->addContractToBase($pView);
                    if ($addContractOK['result'] == Status::ADDED) {
                        // If success, add intervention
                        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel)) {
                            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_INTERVENTION, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                            $addInterventionOK = $pModel->addInterventionToBase($pView);
                            if ($addInterventionOK['result'] == Status::ADDED) {
                                 // If success, show message 'entity and contract added'
                                 $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::ENTITY . \GlpiPlugin\Manageentities\ElementType::CONTRACT . \GlpiPlugin\Manageentities\ElementType::INTERVENTION, Status::ADDED), Messages::MESSAGE_INFO);
                            } else {
                              // If fail, show error
                                $pView->showResults($addInterventionOK);
                            }
                        }
                    } else {
                        $pView->showResults($addContractOK);
                    }
                }
            } else {
               // if fail, show error
                $pView->showResults($addEntityOK);
            }
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_INTERVENTION_AND_CONTRACT:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($_POST['fakeid_new_intervention']));
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());

        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel)) {
           // add contract
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $pView->bordersOnError("contract_name", false, false);

            $addContractOK = $pModel->addContractToBase($pView);
            if ($addContractOK['result'] == Status::ADDED) {
                // If success, add intervention
                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel)) {
                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_INTERVENTION, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
                    $addInterventionOK = $pModel->addInterventionToBase($pView);
                    if ($addInterventionOK['result'] == Status::ADDED) {
                        // If success, show message 'entity and contract added'
                        $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::CONTRACT . \GlpiPlugin\Manageentities\ElementType::INTERVENTION, Status::ADDED), Messages::MESSAGE_INFO);
                    } else {
                      // If fail, show error
                        $pView->showResults($addInterventionOK);
                    }
                }
            } else {
               // if fail, show error
                $pView->showResults($addContractOK);
            }
        }
        break;

    case \GlpiPlugin\Manageentities\Action::CONFIRM_DELETE_CONTRACT_MANAGEMENT_TYPE:
        $pView->showAlertsJQ($_POST['id_div_ajax'], "delete-management-type", $pModel->getMessage("irreversible_action"));
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_CONTRACT_MANAGEMENT_TYPE:
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT_MANAGEMENT_TYPE, $pModel->getContractManagementType());
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT_MANAGEMENT_TYPE, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT_MANAGEMENT_TYPE, \GlpiPlugin\Manageentities\Errors::ERROR_ADD);
            $addContractManagementOK = $pModel->addContractManagementTypeToBase($pView);
            $pView->showResults($addContractManagementOK);
        }
        break;
    case \GlpiPlugin\Manageentities\Action::DELETE_CONTRACT_MANAGEMENT_TYPE:
        $deleteContractManagementOK = $pModel->deleteContractManagementType($pView);
        if ($deleteContractManagementOK['result'] == Status::ADDED) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT_MANAGEMENT_TYPE, \GlpiPlugin\Manageentities\Errors::ERROR_DELETE);
            $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::CONTRACT_MANAGEMENT_TYPE, Status::DELETED), Messages::MESSAGE_INFO);
        } else {
            $pView->showResults($deleteContractManagementOK);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_NEW_CONTACT:
        $contacts  = $pModel->getContacts();
        $nbContact = $pModel->getNbContact();


        if (!isset($contacts[$nbContact + 1]) && isset($contacts[$nbContact]->fields['id']) && $contacts[$nbContact]->fields['id'] > 0) {
            $nbContact++;
            $pModel->setNbContact($nbContact);

            $contact = new Contact();
            $contact->getEmpty();
            $pModel->addContact($contact, $nbContact);

            $pView   = new AddElementsView();
            $content = $pView->showFormAddContact($nbContact);
            $pView->updateTabs($_POST['id_div_ajax'], "div#mytabscontacts", \GlpiPlugin\Manageentities\ElementType::CONTACT, $nbContact);
            $pView->selectTab("mytabscontacts", $nbContact);

            $pView->showJSfunction("addAnotherContact" . $nbContact, $content['idDivNewContact'], $pModel->getUrl(), $content['listIds'], $content['paramsAddNewContact']);
            $pView->showJSfunction("addOnlyContact" . $nbContact, $content['idDivAjax'], $pModel->getUrl(), $content['listIds'], $content['params']);
        } else {
            $pView->selectTab("mytabscontacts", $nbContact);
            $pView->showMessage(__("Please add this contact before adding another.", "manageentities"), Messages::MESSAGE_ERROR);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::ADD_NEW_INTERVENTION:
        $interventions     = $pModel->getContractDays();
        $nbIntervention    = $pModel->getNbContractDays();
        $oldNbIntervention = $pModel->getNbContractDays();

        if (!isset($interventions[$nbIntervention + 1]) && isset($interventions[$nbIntervention]->fields['id']) && $interventions[$nbIntervention]->fields['id'] > 0) {
            $nbIntervention++;
            $pModel->setNbContractDays($nbIntervention);
            $currentContractday = $interventions[$oldNbIntervention];

            $intervention = new ContractDay();
            $intervention->getEmpty();
            $pModel->addContractDay($intervention, $nbIntervention);
            $pView = new AddElementsView();
           //         INFOTEL : MODIFICATION PRESALES
            $params = [];
            if (isset($_POST["presales"])) {
                $params = ["presales" => $_POST["presales"], "contracts_id" => $_POST["new_intervention_contract_id"]];
            }
            $intervention = $pView->showFormAddInterventions($nbIntervention, $params);
           //        INFOTEL
           //            $pView->showFormCriPrice(-1,$nbIntervention+1,array('parent' => ContractDay::class));
           //            $pView->initCriPricesView($intervention,$nbIntervention);
            $pView->updateTabs($_POST['id_div_ajax'], "div#mytabsinterventions", \GlpiPlugin\Manageentities\ElementType::INTERVENTION, $nbIntervention);
            $pView->selectTab("mytabsinterventions", $nbIntervention);

            $pView->showJSfunction("addOnlyIntervention" . $nbIntervention, $intervention['idDivAjax'], $pModel->getUrl(), $intervention['listIds'], $intervention['params'], $intervention['idDivStakeholdersAjax']);
            $pView->showJSfunction("addAnotherIntervention" . $nbIntervention, $intervention['idDivNewIntervention'], $pModel->getUrl(), $intervention['listIds'], $intervention['paramsAddNewIntervention']);
        } else {
            $pView->selectTab("mytabsinterventions", $nbIntervention);
            $pView->showMessage(__("Please add this intervention before adding another.", "manageentities"), Messages::MESSAGE_ERROR);
        }
        break;


    case \GlpiPlugin\Manageentities\Action::LOAD_CONTRACT_TEMPLATE:
        if (isset($_POST['selected_template']) && $_POST['selected_template'] > 0) {
            $idTemplate = $_POST['selected_template'];
            $template   = new Contract();
            $template->getFromDBByCrit(['id'          => $idTemplate,
                                     'is_template' => 1]);

            $oldContract               = $pModel->getContract();
            $oldContract->fields       = $template->fields;
            $oldContract->fields['id'] = '';
            $pModel->setIdContractTemplate($idTemplate);
            $pModel->setIsContractTemplate(1);
            $pModel->setContract($oldContract);
           //         INFOTEL : MODIFICATION PRESALES
            if (!isset($_POST["paramshide"])) {
                 $_POST["paramshide"] = false;
            }
            $contractContent = $pView->showFormAddContract(["presales" => $_POST["paramshide"]]);
           //        INFOTEL
            $contractContent['params']['action'] = \GlpiPlugin\Manageentities\Action::ADD_ONLY_CONTRACT;
            $pView->showJSfunction("addOnlyContract" . $contractContent['params']['rand'], $contractContent['idDivAjax'], $pModel->getUrl(), $contractContent['listIds'], $contractContent['params']);
        } else {
            $oldContract = $pModel->getContract();
            $oldContract->getEmpty();
            $pModel->setIdContractTemplate(-1);
            $pModel->setIsContractTemplate(0);
            $pModel->setContract($oldContract);
           //         INFOTEL : MODIFICATION PRESALES
            if (!isset($_POST["paramshide"])) {
                $_POST["paramshide"] = false;
            }
            $pView->showFormAddContract(["presales" => $_POST["paramshide"]]);
           //        INFOTEL
        }
        break;

    case \GlpiPlugin\Manageentities\Action::UPDATE_CRI_PRICE:
        if (isset($_POST['new_intervention_critypes_id'])
          && $_POST['new_intervention_critypes_id'] > 0
          && $_POST['previous_entity_for_intervention'] != "true") {
            $critypeId  = $_POST['new_intervention_critypes_id'];
            $entitiesId = $_POST['new_intervention_entity_id'];
            $cprice     = $pModel->getCripriceFromDB($critypeId, $entitiesId);
            $pView->updateCriPrice($cprice, "price_" . $_POST ['fakeid_new_intervention']);
        } elseif (isset($_POST['new_intervention_critypes_id'])
                && $_POST['new_intervention_critypes_id'] > 0
                && isset($pModel->getEntity()->fields['id'])
                && $pModel->getEntity()->fields['id'] > 0) {
            $critypeId  = $_POST['new_intervention_critypes_id'];
            $entitiesId = $pModel->getEntity()->fields['id'];
            $cprice     = $pModel->getCripriceFromDB($critypeId, $entitiesId);
            $pView->updateCriPrice($cprice, "price_" . $_POST ['fakeid_new_intervention']);
        } else {
            $pView->updateCriPrice(false, "price_" . $_POST ['fakeid_new_intervention']);
        }
        break;


    case \GlpiPlugin\Manageentities\Action::UPDATE_CRI_PRICE_FROM_TYPE:
        if (isset($_POST['new_criprice_critype'])) {
            $pView->updateCriPriceFromType($pModel->getCriPriceFromType($_POST), $_POST);
        }
        break;

    case \GlpiPlugin\Manageentities\Action::UPDATE_CONTRACT_LIST:
        $entitiesId     = $_POST['new_intervention_entity_id'];
        $idIntervention = $_POST['fakeid_new_intervention'];
        $previousEntity = $_POST['previous_entity_for_intervention'];
        $pView->changeContractList($entitiesId, $previousEntity, $idIntervention);
        break;


    case \GlpiPlugin\Manageentities\Action::ADD_NEW_CONTRACT_PDF:
        $addContractOK = $pModel->addPDFContractToBase($pView);
        $_SESSION["manageentities"]["add_doc_status"] = $addContractOK;

        break;

    case \GlpiPlugin\Manageentities\Action::REINIT_FORMS:
        $pModel->destroy();
        Html::back();
        break;


   // Next cases are for managing add and update all ellements (click on btnAddNewElement)
   // -- Not used currently --

    case \GlpiPlugin\Manageentities\Action::CONFIRM_ADD_ALL_ELEMENT:
        $pView->showResults(['result' => Status::ADD_ALL]);
        break;
    case \GlpiPlugin\Manageentities\Action::CONFIRM_UPDATE_ALL_ELEMENT:
        $pView->showResults(['result' => Status::UPDATE_ALL]);
        break;
    case \GlpiPlugin\Manageentities\Action::ADD_ALL_ELEMENT:
    case \GlpiPlugin\Manageentities\Action::UPDATE_ALL_ELEMENT:
        if ($_POST ['action'] == \GlpiPlugin\Manageentities\Action::ADD_ALL_ELEMENT) {
            $action = DBOperation::ADD;
            $error  = \GlpiPlugin\Manageentities\Errors::ERROR_ADD;
            $status = Status::ADDED;
        } else {
            $action = DBOperation::UPDATE;
            $error  = \GlpiPlugin\Manageentities\Errors::ERROR_UPDATE;
            $status = Status::UPDATED;
        }

        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($_POST['fakeid_new_intervention']));
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());

        $continue = true;
       // Add Entity
        $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel->getEntity());
        if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::ENTITY, $pModel)) {
            $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_ENTITY, $error);
            $pView->bordersOnError("entity_name", false, false);
            $addEntityOK = $pModel->addEntityToBase($pView);
            if ($addEntityOK['result'] == $status) {
               // parcours contacts
                $nbContact = $pModel->getNbContact();
                for ($i = 1; $i <= $nbContact; $i++) {
                    // add contacts
                    $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel->getContacts($i));
                    if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTACT, $pModel)) {
                        $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTACT, $status);
                        $addContactOK = $pModel->addContactToBase($pView, $i);
                        if ($addContactOK['result'] != $status) {
                             $continue = false;
                             break;
                        }
                    } else {
                        $continue = false;
                    }
                }
                if ($continue) {
                   // add contract
                    $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel->getContract());
                    if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::CONTRACT, $pModel)) {
                        $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_CONTRACT, $error);
                        $addContractOK = $pModel->addContractToBase($pView);
                        if ($addContractOK['result'] == $status) {
                          // parcours interventions
                            $nbIntervention = $pModel->getNbContractDays();
                            for ($i = 1; $i <= $nbContact; $i++) {
                                // add intervention
                                $pModel->storeDatasInSession(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel->getContractDay($i));
                                if ($pView->checkFields(\GlpiPlugin\Manageentities\ElementType::INTERVENTION, $pModel)) {
                                    $pModel->deleteError(\GlpiPlugin\Manageentities\Errors::ERROR_INTERVENTION, $error);
                                    $addInterventionOK = $pModel->addInterventionToBase($pView, $i);
                                    if ($addInterventionOK['result'] != $status) {
                                          $continue = false;
                                          break;
                                    }
                                } else {
                                    $continue = false;
                                    $pView->showResults($addInterventionOK);
                                }
                            }

                            if ($continue) {
                               // All elements saved
                                if ($status == Status::ADDED) {
                                    $pView->changeBtnName('btnAddAll', __("Update all previous elements", "manageentities"));
                                    $pView->changeBtnAction('btnAddAll', "confirmUpdateAllElements()");
                                }
                                $pView->showMessage($pModel->getMessage(\GlpiPlugin\Manageentities\ElementType::ALL, $status), Messages::MESSAGE_INFO);
                            }
                        } else {
                            $pView->showResults($addContractOK);
                        }
                    }
                } else {
                    $pView->showResults($addContactOK);
                }
            } else {
                $pView->showResults($addEntityOK);
            }
        }
        break;

    default:
        break;
}
