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

namespace GlpiPlugin\Manageentities;

use Contact as GlpiContact;
use ContactType;
use ContractType as GlpiContractType;
use Document;
use DocumentCategory;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Session;
use State;
use User;
use UserTitle;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * Handles all wizard step save/render logic.
 *
 * Public methods that end with AndReturn() return an array instead of
 * echoing JSON — these are used by PHPUnit integration tests.
 * The corresponding public methods without that suffix call them and emit JSON.
 */
class WizardController
{
    private const SESSION_KEY = 'manageentities_wizard';

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    public static function buildDefaultSession(): array
    {
        return [
            'wizard_mode'        => '',  // '' = choice not made | 'new_entity' | 'existing_entity'
            'step'               => 1,
            'entities_id'        => 0,
            'contracts_id'       => 0,
            'plugin_contract_id' => 0,
            'contacts'           => [],
            'contractdays'       => [],
            'documents_ids'      => [],
        ];
    }

    public static function getSession(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::buildDefaultSession();
        }
        // Merge defaults so keys added after a session was created are always present
        $_SESSION[self::SESSION_KEY] = array_merge(self::buildDefaultSession(), $_SESSION[self::SESSION_KEY]);
        return $_SESSION[self::SESSION_KEY];
    }

    private static function saveSession(array $data): void
    {
        $_SESSION[self::SESSION_KEY] = $data;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    public static function validateEntityInput(array $input): array
    {
        $errors = [];
        if (empty(trim($input['name'] ?? ''))) {
            $errors['name'] = __('Name is required', 'manageentities');
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateContactInput(array $input): array
    {
        $errors = [];
        if (empty(trim($input['name'] ?? ''))) {
            $errors['name'] = __('Last name is required', 'manageentities');
        }
        if (empty(trim($input['firstname'] ?? ''))) {
            $errors['firstname'] = __('First name is required', 'manageentities');
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateContractInput(array $input): array
    {
        $errors = [];
        if (empty(trim($input['name'] ?? ''))) {
            $errors['name'] = __('Name is required', 'manageentities');
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    public static function validateInterventionInput(array $input): array
    {
        $errors = [];
        if (empty(trim($input['name'] ?? ''))) {
            $errors['name'] = __('Name is required', 'manageentities');
        }
        if (empty($input['begin_date'] ?? '')) {
            $errors['begin_date'] = __('Begin date is required', 'manageentities');
        }
        if (empty($input['plugin_manageentities_contractstates_id'] ?? 0)) {
            $errors['plugin_manageentities_contractstates_id'] = __('State is required', 'manageentities');
        }
        try {
            $config = Config::getInstance();
            $hourorday = $config->fields['hourorday'] ?? null;
        } catch (\Throwable $e) {
            $hourorday = null;
        }
        if ($hourorday !== null && $hourorday == Config::DAY && empty((int)($input['contract_type'] ?? 0))) {
            $errors['contract_type'] = __('Intervention type is required', 'manageentities');
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Mode choice (landing page before step 1)
    // -------------------------------------------------------------------------

    public static function renderModeChoice(): void
    {
        $wizard_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php';
        TemplateRenderer::getInstance()->display('@manageentities/wizard/mode_choice.html.twig', [
            'wizard_url' => $wizard_url,
        ]);
    }

    public static function chooseMode(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::chooseModeAndReturn($_POST));
        exit;
    }

    public static function chooseModeAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }
        $mode = $input['wizard_mode'] ?? '';
        if (!in_array($mode, ['new_entity', 'existing_entity'], true)) {
            return ['success' => false, 'message' => __('Invalid wizard mode', 'manageentities')];
        }
        $session = self::getSession();
        $session['wizard_mode'] = $mode;
        self::saveSession($session);
        return ['success' => true];
    }

    // -------------------------------------------------------------------------
    // Step 1 — Entity
    // -------------------------------------------------------------------------

    public static function saveEntity(): void
    {
        header('Content-Type: application/json');
        $session = self::getSession();
        if ($session['wizard_mode'] === 'existing_entity') {
            echo json_encode(self::saveSelectEntityAndReturn($_POST));
        } else {
            echo json_encode(self::saveEntityAndReturn($_POST));
        }
        exit;
    }

    public static function saveSelectEntityAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }
        $entities_id = (int)($input['entities_id'] ?? 0);
        if ($entities_id <= 0) {
            return ['success' => false, 'errors' => ['entities_id' => __('Please select an entity', 'manageentities')]];
        }
        $entity = new \Entity();
        if (!$entity->getFromDB($entities_id)) {
            return ['success' => false, 'errors' => ['entities_id' => __('Entity not found', 'manageentities')]];
        }
        $session = self::getSession();
        $session['entities_id'] = $entities_id;
        $session['step']        = max($session['step'], 3); // skip contacts step
        self::saveSession($session);
        return ['success' => true, 'entities_id' => $entities_id, 'step' => $session['step']];
    }

    public static function saveEntityAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $validation = self::validateEntityInput($input);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $session = self::getSession();

        $entity = new \Entity();

        $data = [
            'name'         => trim($input['name']),
            'entities_id'  => (int)($input['entities_id'] ?? 0),
            'comment'      => $input['comment'] ?? '',
            'phonenumber'  => $input['phonenumber'] ?? '',
            'fax'          => $input['fax'] ?? '',
            'email'        => $input['email'] ?? '',
            'website'      => $input['website'] ?? '',
            'address'      => $input['address'] ?? '',
            'postcode'     => $input['postcode'] ?? '',
            'town'         => $input['town'] ?? '',
            'state'        => $input['state'] ?? '',
            'country'      => $input['country'] ?? '',
        ];

        if ($session['entities_id'] > 0) {
            $data['id'] = $session['entities_id'];
            if (!$entity->update($data)) {
                return ['success' => false, 'message' => __('Error updating entity', 'manageentities')];
            }
            $entities_id = $session['entities_id'];
        } else {
            $entities_id = $entity->add($data);
            if (!$entities_id) {
                return ['success' => false, 'message' => __('Error creating entity', 'manageentities')];
            }
        }

        $session['entities_id'] = (int)$entities_id;
        $session['step'] = max($session['step'], 2);
        self::saveSession($session);

        return ['success' => true, 'entities_id' => (int)$entities_id, 'step' => $session['step']];
    }

    // -------------------------------------------------------------------------
    // Step 2 — Contacts
    // -------------------------------------------------------------------------

    public static function saveContacts(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::saveContactsAndReturn($_POST));
        exit;
    }

    public static function saveContactsAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $session = self::getSession();

        // contacts is an array: contacts[idx][field]
        $contactsInput = $input['contacts'] ?? [];
        $savedIds      = [];

        foreach ($contactsInput as $idx => $cInput) {
            if (empty(trim($cInput['name'] ?? ''))) {
                continue; // skip empty blocks
            }

            $validation = self::validateContactInput($cInput);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors'], 'contact_idx' => $idx];
            }

            $entities_id = (int)($cInput['entities_id'] ?? $session['entities_id']);

            $glpiContact = new GlpiContact();
            $contactData = [
                'name'             => trim($cInput['name']),
                'firstname'        => $cInput['firstname'] ?? '',
                'phone'            => $cInput['phone'] ?? '',
                'phone2'           => $cInput['phone2'] ?? '',
                'mobile'           => $cInput['mobile'] ?? '',
                'fax'              => $cInput['fax'] ?? '',
                'email'            => $cInput['email'] ?? '',
                'address'          => $cInput['address'] ?? '',
                'postcode'         => $cInput['postcode'] ?? '',
                'town'             => $cInput['town'] ?? '',
                'state'            => $cInput['state'] ?? '',
                'country'          => $cInput['country'] ?? '',
                'comment'          => $cInput['comment'] ?? '',
                'entities_id'      => $entities_id,
                'is_recursive'     => (int)(bool)($cInput['is_recursive'] ?? 0),
                'contacttypes_id'  => (int)($cInput['contacttypes_id'] ?? 0),
                'usertitles_id'    => (int)($cInput['usertitles_id'] ?? 0),
            ];

            $existing_id = (int)($session['contacts'][$idx] ?? 0);
            if ($existing_id > 0) {
                $contactData['id'] = $existing_id;
                $glpiContact->update($contactData);
                $contact_id = $existing_id;
            } else {
                $contact_id = $glpiContact->add($contactData);
                if (!$contact_id) {
                    return ['success' => false, 'message' => __('Error creating contact', 'manageentities')];
                }
            }

            // Plugin contact link
            $is_manager = (int)(bool)($cInput['is_manager'] ?? 0);
            self::linkPluginContact((int)$contact_id, $entities_id, $is_manager);

            $session['contacts'][$idx] = (int)$contact_id;
            $savedIds[$idx] = (int)$contact_id;
        }

        $session['step'] = max($session['step'], 3);
        self::saveSession($session);

        return ['success' => true, 'contacts' => $savedIds, 'step' => $session['step']];
    }

    private static function linkPluginContact(int $contact_id, int $entities_id, int $is_manager): void
    {
        $pluginContact = new Contact();

        // If manager: reset all others for this entity
        if ($is_manager) {
            $existing = $pluginContact->find(['entities_id' => $entities_id]);
            foreach ($existing as $row) {
                $pluginContact->update(['id' => $row['id'], 'is_default' => 0]);
            }
        }

        $existingRow = $pluginContact->find([
            'contacts_id' => $contact_id,
            'entities_id' => $entities_id,
        ]);

        if (count($existingRow) > 0) {
            $row = reset($existingRow);
            $pluginContact->update(['id' => $row['id'], 'is_default' => $is_manager]);
        } else {
            $pluginContact->add([
                'contacts_id' => $contact_id,
                'entities_id' => $entities_id,
                'is_default'  => $is_manager,
            ]);
        }
    }

    public static function renderContactBlock(): void
    {
        $idx  = (int)($_POST['idx'] ?? 1);
        $rand = mt_rand();
        $default_contacttype = 0;
        try {
            $default_contacttype = (int)(Config::getInstance()->fields['wizard_contacttypes_id'] ?? 0);
        } catch (\Throwable $e) {
        }
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step2_contact_block.html.twig', [
            'idx'              => $idx,
            'rand'             => $rand,
            'usertitles_html'  => self::buildDropdownHtml(
                fn() => UserTitle::dropdown(['name' => "contacts[{$idx}][usertitles_id]", 'rand' => $rand, 'display' => false])
            ),
            'contacttype_html' => self::buildDropdownHtml(
                fn() => ContactType::dropdown(['name' => "contacts[{$idx}][contacttypes_id]", 'rand' => $rand, 'value' => $default_contacttype, 'display' => false])
            ),
            'entities_html'    => self::buildEntityHtml("contacts[{$idx}][entities_id]", 0, $rand),
            'show_entity_dropdown' => true,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Step 3 — Contract
    // -------------------------------------------------------------------------

    public static function loadContractTemplate(): void
    {
        header('Content-Type: application/json');
        $contracts_id = (int)($_POST['contracts_id'] ?? 0);
        if ($contracts_id <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        $contract = new \Contract();
        if (!$contract->getFromDB($contracts_id) || empty($contract->fields['is_template'])) {
            echo json_encode(['success' => false]);
            exit;
        }
        $f = $contract->fields;

        // Store prefill values in session so buildContractVars can inject them into dropdowns
        $session = self::getSession();
        $session['contract_prefill'] = [
            'name'             => $f['name'] ?? '',
            'num'              => $f['num'] ?? '',
            'begin_date'       => $f['begin_date'] ?? '',
            'duration'         => (int)($f['duration'] ?? 12),
            'contracttypes_id' => (int)($f['contracttypes_id'] ?? 0),
            'states_id'        => (int)($f['states_id'] ?? 0),
            'comment'          => $f['comment'] ?? '',
        ];
        self::saveSession($session);

        echo json_encode(['success' => true, 'redirect' => true]);
        exit;
    }

    public static function saveContract(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::saveContractAndReturn($_POST));
        exit;
    }

    public static function saveContractAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $validation = self::validateContractInput($input);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $session = self::getSession();

        $glpiContract = new \Contract();

        $entities_id = (int)($input['entities_id'] ?? $session['entities_id']);

        $data = [
            'name'               => trim($input['name']),
            'num'                => $input['num'] ?? '',
            'accounting_number'  => $input['accounting_number'] ?? '',
            'comment'            => $input['comment'] ?? '',
            'entities_id'        => $entities_id,
            'is_recursive'       => (int)(bool)($input['is_recursive'] ?? 0),
            'contracttypes_id'   => (int)($input['contracttypes_id'] ?? 0),
            'begin_date'         => !empty($input['begin_date']) ? $input['begin_date'] : 'NULL',
            'duration'           => (int)($input['duration'] ?? 0),
            'notice'             => (int)($input['notice'] ?? 0),
            'periodicity'        => (int)($input['periodicity'] ?? 0),
            'billing'            => (int)($input['billing'] ?? 0),
            'renewal'            => (int)($input['renewal'] ?? 0),
            'max_links_allowed'  => (int)($input['max_links_allowed'] ?? 0),
            'use_saturday'       => (int)(bool)($input['use_saturday'] ?? 0),
            'use_sunday'         => (int)(bool)($input['use_sunday'] ?? 0),
            'states_id'          => (int)($input['states_id'] ?? 0),
            'week_begin_hour'    => $input['week_begin_hour'] ?? '',
            'week_end_hour'      => $input['week_end_hour'] ?? '',
            'saturday_begin_hour'=> $input['saturday_begin_hour'] ?? '',
            'saturday_end_hour'  => $input['saturday_end_hour'] ?? '',
            'sunday_begin_hour'  => $input['sunday_begin_hour'] ?? '',
            'sunday_end_hour'    => $input['sunday_end_hour'] ?? '',
        ];

        $existing_id = $session['contracts_id'];
        if ($existing_id > 0) {
            $data['id'] = $existing_id;
            if (!$glpiContract->update($data)) {
                return ['success' => false, 'message' => __('Error updating contract', 'manageentities')];
            }
            $contracts_id = $existing_id;
        } else {
            $contracts_id = $glpiContract->add($data);
            if (!$contracts_id) {
                return ['success' => false, 'message' => __('Error creating contract', 'manageentities')];
            }
        }

        $session['contracts_id'] = (int)$contracts_id;
        $session['step'] = max($session['step'], 4);
        self::saveSession($session);

        return ['success' => true, 'contracts_id' => (int)$contracts_id, 'step' => $session['step']];
    }

    public static function renderDocumentBlock(): void
    {
        $rand = mt_rand();
        $idx  = (int)($_POST['idx'] ?? 1);

        $cfg = Config::getInstance();
        $doccat_html = self::buildDocumentCategorySelect(
            "documents[{$idx}][documentcategories_id]",
            $rand,
            (int)($cfg->fields['wizard_documentcategories_id'] ?? 0)
        );

        TemplateRenderer::getInstance()->display('@manageentities/wizard/step3_document_block.html.twig', [
            'idx'        => $idx,
            'rand'       => $rand,
            'doccat_html'=> $doccat_html,
        ]);
        exit;
    }

    private static function buildDocumentCategorySelect(string $name, int $rand, int $value = 0): string
    {
        $cats = (new DocumentCategory())->find([], ['name']);
        $id   = 'doccat_' . $rand;
        $html = '<select name="' . htmlspecialchars($name) . '" id="' . $id . '" class="form-select">';
        $html .= '<option value="0">---</option>';
        foreach ($cats as $cat) {
            $selected = ((int)$cat['id'] === $value) ? ' selected' : '';
            $html .= '<option value="' . (int)$cat['id'] . '"' . $selected . '>'
                   . htmlspecialchars($cat['completename'] ?? $cat['name'])
                   . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    public static function uploadDocuments(): void
    {
        header('Content-Type: application/json');
        $session      = self::getSession();
        $contracts_id = (int)($session['contracts_id'] ?? 0);

        if ($contracts_id <= 0) {
            echo json_encode(['success' => false, 'message' => __('Save the contract first', 'manageentities')]);
            exit;
        }

        $glpiContract = new \Contract();
        $glpiContract->getFromDB($contracts_id);
        $entities_id = (int)($glpiContract->fields['entities_id'] ?? 0);

        // PHP inverts nested file-input arrays: documents[idx][file] becomes
        // $_FILES['documents']['name'][idx]['file'], not $_FILES['documents'][idx]['file']['name'].
        $fileNames    = $_FILES['documents']['name']     ?? [];
        $fileTmpNames = $_FILES['documents']['tmp_name'] ?? [];
        $fileErrors   = $_FILES['documents']['error']    ?? [];

        if (empty($fileNames)) {
            echo json_encode(['success' => true, 'added' => 0]);
            exit;
        }

        $list = [];
        foreach ($fileNames as $idx => $subfield) {
            $name     = $subfield['file'] ?? null;
            $tmp_name = $fileTmpNames[$idx]['file'] ?? null;
            $error    = (int)($fileErrors[$idx]['file'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK || !$name || !$tmp_name) {
                continue;
            }
            $list[] = [
                'tmp_name'              => $tmp_name,
                'name'                  => $name,
                'documentcategories_id' => (int)($_POST['documents'][$idx]['documentcategories_id'] ?? 0),
            ];
        }

        $added     = 0;
        $errors    = [];
        $newDocIds = [];
        foreach ($list as $entry) {
            // GLPI Document::add() via upload_file expects the file already in GLPI_UPLOAD_DIR.
            // Copy the PHP tmp file there under its original name.
            $destName = basename($entry['name']);
            $destPath = GLPI_UPLOAD_DIR . '/' . $destName;
            if (!copy($entry['tmp_name'], $destPath)) {
                $errors[] = $entry['name'];
                continue;
            }

            $doc      = new Document();
            $docInput = [
                'documentcategories_id' => $entry['documentcategories_id'],
                'entities_id'           => $entities_id,
                'is_recursive'          => 0,
                'itemtype'              => \Contract::class,
                'items_id'              => $contracts_id,
                '_no_message'           => true,
                'upload_file'           => $destName,
                'name'                  => $entry['name'],
            ];
            $doc->check(-1, CREATE, $docInput);
            $doc_id = $doc->add($docInput);

            if ($doc_id) {
                $added++;
                $newDocIds[] = (int)$doc_id;
            } else {
                @unlink($destPath);
                $errors[] = $entry['name'];
            }
        }

        // Track created document IDs in session for reset/summary
        if (!empty($newDocIds)) {
            $session['documents_ids'] = array_merge($session['documents_ids'] ?? [], $newDocIds);
            self::saveSession($session);
        }

        echo json_encode([
            'success' => empty($errors),
            'added'   => $added,
            'errors'  => $errors,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Step 4 — Management type
    // -------------------------------------------------------------------------

    public static function saveManagementType(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::saveManagementTypeAndReturn($_POST));
        exit;
    }

    public static function saveManagementTypeAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $session = self::getSession();
        $contracts_id = (int)($input['contracts_id'] ?? $session['contracts_id']);

        if ($contracts_id <= 0) {
            return ['success' => false, 'message' => __('Save the contract first', 'manageentities')];
        }

        $pluginContract = new Contract();

        $data = [
            'contracts_id'             => $contracts_id,
            'entities_id'              => (int)($input['entities_id'] ?? $session['entities_id']),
            'date_signature'           => !empty($input['date_signature']) ? $input['date_signature'] : 'NULL',
            'date_renewal'             => !empty($input['date_renewal']) ? $input['date_renewal'] : 'NULL',
            'management'               => (int)($input['management'] ?? 0),
            'contract_type'            => (int)($input['contract_type'] ?? 0),
            'contract_added'           => (int)(bool)($input['contract_added'] ?? 0),
            'show_on_global_gantt'     => (int)(bool)($input['show_on_global_gantt'] ?? 0),
            'refacturable_costs'       => (int)(bool)($input['refacturable_costs'] ?? 0),
            'moving_management'        => (int)(bool)($input['moving_management'] ?? 0),
            'duration_moving'          => (int)($input['duration_moving'] ?? 0),
            'active_editor_suscription'=> (int)(bool)($input['active_editor_suscription'] ?? 0),
            'cloud_client'             => (int)(bool)($input['cloud_client'] ?? 0),
            'internet_publication'     => (int)(bool)($input['internet_publication'] ?? 0),
        ];

        $existing_id = $session['plugin_contract_id'];
        if ($existing_id > 0) {
            $data['id'] = $existing_id;
            if (!$pluginContract->update($data)) {
                return ['success' => false, 'message' => __('Error updating management type', 'manageentities')];
            }
            $plugin_contract_id = $existing_id;
        } else {
            $plugin_contract_id = $pluginContract->add($data);
            if (!$plugin_contract_id) {
                return ['success' => false, 'message' => __('Error creating management type', 'manageentities')];
            }
        }

        $session['plugin_contract_id'] = (int)$plugin_contract_id;
        $session['step'] = max($session['step'], 5);
        self::saveSession($session);

        return ['success' => true, 'plugin_contract_id' => (int)$plugin_contract_id, 'step' => $session['step']];
    }

    // -------------------------------------------------------------------------
    // Step 5 — Interventions (ContractDay)
    // -------------------------------------------------------------------------

    public static function saveInterventions(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::saveInterventionsAndReturn($_POST));
        exit;
    }

    public static function saveInterventionsAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $session      = self::getSession();
        $interventionsInput = $input['interventions'] ?? [];
        $savedIds     = [];

        foreach ($interventionsInput as $idx => $iInput) {
            if (empty(trim($iInput['name'] ?? ''))) {
                continue;
            }

            $validation = self::validateInterventionInput($iInput);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors'], 'intervention_idx' => $idx];
            }

            $entities_id  = (int)($iInput['entities_id'] ?? $session['entities_id']);
            $contracts_id = (int)($iInput['contracts_id'] ?? $session['contracts_id']);

            $contractDay = new ContractDay();
            $data = [
                'name'                                   => trim($iInput['name']),
                'entities_id'                            => $entities_id,
                'contracts_id'                           => $contracts_id,
                'plugin_manageentities_contractstates_id'=> (int)($iInput['plugin_manageentities_contractstates_id'] ?? 0),
                'begin_date'                             => $iInput['begin_date'],
                'end_date'                               => !empty($iInput['end_date']) ? $iInput['end_date'] : 'NULL',
                'nbday'                                  => (float)($iInput['nbday'] ?? 0),
                'report'                                 => (float)($iInput['report'] ?? 0),
                'charged'                                => (int)(bool)($iInput['charged'] ?? 0),
                'comment'                                => $iInput['comment'] ?? '',
            ];

            if (!empty($iInput['contract_type'])) {
                $data['plugin_manageentities_critypes_id'] = (int)$iInput['contract_type'];
            }

            $existing_id = (int)($session['contractdays'][$idx] ?? 0);
            if ($existing_id > 0) {
                $data['id'] = $existing_id;
                $contractDay->update($data);
                $contractday_id = $existing_id;
            } else {
                $contractday_id = $contractDay->add($data);
                if (!$contractday_id) {
                    return ['success' => false, 'message' => __('Error creating intervention', 'manageentities')];
                }
            }

            $session['contractdays'][$idx] = (int)$contractday_id;
            $savedIds[$idx] = (int)$contractday_id;
        }

        self::saveSession($session);

        return [
            'success'     => true,
            'contractdays'=> $savedIds,
            'step'        => $session['step'],
        ];
    }

    private static function buildEntityRedirectUrl(int $entities_id): string
    {
        if ($entities_id <= 0) {
            return '';
        }
        return \Toolbox::getItemTypeFormURL('Entity') . '?id=' . $entities_id;
    }

    // -------------------------------------------------------------------------
    // Step 5 finish — validate CriPrices then return summary
    // -------------------------------------------------------------------------

    public static function finishWizard(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::finishWizardAndReturn());
        exit;
    }

    public static function finishWizardAndReturn(): array
    {
        $session = self::getSession();
        $allIds  = array_filter(array_values($session['contractdays'] ?? []));

        if (empty($allIds)) {
            return ['success' => false, 'errors' => ['global' => __('At least one service period with a rate is required', 'manageentities')]];
        }

        foreach ($allIds as $cdId) {
            $criPrices = self::getCriPricesForContractDay($cdId);
            if (empty($criPrices)) {
                $cd = new ContractDay();
                $cd->getFromDB($cdId);
                return ['success' => false, 'errors' => ['global' => sprintf(
                    __('Period of contract "%s" requires at least one rate', 'manageentities'),
                    $cd->fields['name'] ?? $cdId
                )]];
            }
        }

        $summary = self::getFinishSummaryAndReturn();

        return [
            'success'     => true,
            'summary'     => $summary['items'] ?? [],
            'redirect_url'=> self::buildEntityRedirectUrl((int)($session['entities_id'] ?? 0)),
        ];
    }

    public static function getFinishSummaryAndReturn(): array
    {
        $session = self::getSession();
        $items   = [];

        $entities_id = (int)($session['entities_id'] ?? 0);
        if ($entities_id > 0) {
            $e = new \Entity();
            if ($e->getFromDB($entities_id)) {
                $items[] = ['type' => __('Entity'), 'label' => $e->fields['completename'] ?? $e->fields['name']];
            }
        }

        foreach (($session['contacts'] ?? []) as $contact_id) {
            $c = new GlpiContact();
            if ($c->getFromDB((int)$contact_id)) {
                $items[] = ['type' => __('Contact'),
                    'label' => trim(($c->fields['firstname'] ?? '') . ' ' . $c->fields['name'])];
            }
        }

        $contracts_id = (int)($session['contracts_id'] ?? 0);
        if ($contracts_id > 0) {
            $ct = new \Contract();
            if ($ct->getFromDB($contracts_id)) {
                $items[] = ['type' => __('Contract'), 'label' => $ct->fields['name']];
            }
        }

        $docNames = [];
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $d = new Document();
            if ($d->getFromDB((int)$doc_id)) {
                $docNames[] = $d->fields['name'] ?? $d->fields['filename'] ?? ('Doc #' . $doc_id);
            }
        }
        if (!empty($docNames)) {
            $items[] = ['type' => _n('Document', 'Documents', count($docNames)), 'label' => implode(', ', $docNames)];
        }

        $cfg         = Config::getInstance();
        $unit_label  = ($cfg->fields['hourorday'] == Config::HOUR)
            ? __('hours', 'manageentities')
            : __('days', 'manageentities');

        foreach (($session['contractdays'] ?? []) as $cd_id) {
            $cd = new ContractDay();
            if (!$cd->getFromDB((int)$cd_id)) continue;

            $cdLabel = $cd->fields['name'] ?? ('ID ' . $cd_id);
            if ((float)($cd->fields['nbday'] ?? 0) > 0) {
                $cdLabel .= ' — ' . number_format((float)$cd->fields['nbday'], 2) . ' ' . $unit_label;
            }
            $items[] = ['type' => __('Period of contract', 'manageentities'), 'label' => $cdLabel];

            $criPrice = new CriPrice();
            foreach ($criPrice->find(['plugin_manageentities_contractdays_id' => (int)$cd_id]) as $cp) {
                $criType = new CriType();
                $typeName = $criType->getFromDB((int)($cp['plugin_manageentities_critypes_id'] ?? 0))
                    ? ($criType->fields['completename'] ?? $criType->fields['name'] ?? '')
                    : '';
                $items[] = ['type' => CriPrice::getTypeName(1),
                    'label' => ($typeName ? $typeName . ' — ' : '') . number_format((float)($cp['price'] ?? 0), 2)];
            }

            $sh    = new InterventionStakeholder();
            $names = [];
            foreach ($sh->find(['plugin_manageentities_contractdays_id' => (int)$cd_id]) as $row) {
                $u = new User();
                $names[] = $u->getFromDB((int)($row['users_id'] ?? 0))
                    ? $u->getFriendlyName() . ' (' . number_format((float)($row['number_affected_days'] ?? 0), 2) . ' ' . $unit_label . ')'
                    : ('User #' . $row['users_id']);
            }
            if (!empty($names)) {
                $items[] = ['type' => _n('User affected', 'Users affected', count($names), 'manageentities'),
                    'label' => implode(', ', $names)];
            }
        }

        return ['success' => true, 'items' => $items];
    }

    // Save a single intervention block (AJAX — called by the per-block Save button)
    public static function saveIntervention(): void
    {
        header('Content-Type: application/json');
        $input   = $_POST;
        $idx     = (int)($input['idx'] ?? 0);
        $session = self::getSession();
        $iInput  = $input['intervention'] ?? [];

        $validation = self::validateInterventionInput($iInput);
        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'errors' => $validation['errors']]);
            exit;
        }

        $entities_id  = (int)($iInput['entities_id'] ?? $session['entities_id']);
        $contracts_id = (int)($iInput['contracts_id'] ?? $session['contracts_id']);

        $contractDay = new ContractDay();
        $data = [
            'name'                                    => trim($iInput['name']),
            'entities_id'                             => $entities_id,
            'contracts_id'                            => $contracts_id,
            'plugin_manageentities_contractstates_id' => (int)($iInput['plugin_manageentities_contractstates_id'] ?? 0),
            'begin_date'                              => $iInput['begin_date'],
            'end_date'                                => !empty($iInput['end_date']) ? $iInput['end_date'] : 'NULL',
            'nbday'                                   => (float)($iInput['nbday'] ?? 0),
            'report'                                  => (float)($iInput['report'] ?? 0),
            'charged'                                 => (int)(bool)($iInput['charged'] ?? 0),
            'comment'                                 => $iInput['comment'] ?? '',
        ];
        if (!empty($iInput['contract_type'])) {
            $data['plugin_manageentities_critypes_id'] = (int)$iInput['contract_type'];
        }

        $existing_id = (int)($session['contractdays'][$idx] ?? 0);
        if ($existing_id > 0) {
            $data['id'] = $existing_id;
            $contractDay->update($data);
            $contractday_id = $existing_id;
        } else {
            $contractday_id = $contractDay->add($data);
            if (!$contractday_id) {
                echo json_encode(['success' => false, 'message' => __('Error creating intervention', 'manageentities')]);
                exit;
            }
        }

        $session['contractdays'][$idx] = (int)$contractday_id;
        self::saveSession($session);

        $rand   = mt_rand();
        $config = Config::getInstance();
        $is_day = ($config->fields['hourorday'] == Config::DAY);

        $criprices_html    = self::buildCriPricesSectionHtml((int)$contractday_id, $rand, $is_day);
        $stakeholders_html = self::buildStakeholdersSectionHtml((int)$contractday_id, $rand, $session['entities_id']);

        echo json_encode([
            'success'           => true,
            'contractday_id'    => (int)$contractday_id,
            'criprices_html'    => $criprices_html,
            'stakeholders_html' => $stakeholders_html,
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Stakeholders
    // -------------------------------------------------------------------------

    public static function addStakeholder(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::addStakeholderAndReturn($_POST));
        exit;
    }

    public static function addStakeholderAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }
        $contractday_id = (int)($input['contractday_id'] ?? 0);
        $users_id       = (int)($input['users_id'] ?? 0);
        $nb_days        = (float)($input['number_affected_days'] ?? 0);

        if ($contractday_id <= 0 || $users_id <= 0) {
            return ['success' => false, 'message' => __('Missing required fields', 'manageentities')];
        }

        if ($nb_days <= 0) {
            return ['success' => false, 'message' => __('Number of days must be greater than 0', 'manageentities')];
        }

        $cd = new ContractDay();
        $cd->getFromDB($contractday_id);
        $nbday_credit = (float)($cd->fields['nbday'] ?? 0);

        $sh = new InterventionStakeholder();
        $existing = $sh->find([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'users_id'                              => $users_id,
        ]);
        $already_assigned = array_sum(array_column(
            $sh->find(['plugin_manageentities_contractdays_id' => $contractday_id]),
            'number_affected_days'
        ));

        if (!empty($existing)) {
            $rem = $nbday_credit > 0 ? $nbday_credit - $already_assigned : null;
            return ['success' => false, 'message' => __('User already added', 'manageentities'),
                'remaining_days' => $rem, 'credit' => $nbday_credit];
        }

        if ($nbday_credit > 0 && $nb_days > ($nbday_credit - $already_assigned)) {
            $remaining_real = $nbday_credit - $already_assigned;
            return ['success' => false, 'message' => sprintf(
                __('Cannot assign %.2f day(s): only %.2f day(s) remaining out of %.2f', 'manageentities'),
                $nb_days, $remaining_real, $nbday_credit
            ), 'remaining_days' => $remaining_real, 'credit' => $nbday_credit];
        }

        $id = $sh->add([
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'users_id'                              => $users_id,
            'number_affected_days'                  => $nb_days,
        ]);
        if (!$id) {
            return ['success' => false, 'message' => __('Error adding stakeholder', 'manageentities')];
        }

        $user = new User();
        $user->getFromDB($users_id);

        $remaining_after = $nbday_credit > 0 ? ($nbday_credit - $already_assigned - $nb_days) : null;

        return [
            'success'              => true,
            'stakeholder_id'       => (int)$id,
            'user_name'            => htmlspecialchars($user->getFriendlyName()),
            'number_affected_days' => $nb_days,
            'remaining_days'       => $remaining_after,
            'credit'               => $nbday_credit,
        ];
    }

    public static function deleteDocument(): void
    {
        header('Content-Type: application/json');
        $doc_id = (int)($_POST['document_id'] ?? 0);
        if ($doc_id <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        $d = new Document();
        $ok = $d->delete(['id' => $doc_id], true);
        if ($ok) {
            $session = self::getSession();
            $session['documents_ids'] = array_values(array_filter(
                $session['documents_ids'] ?? [],
                fn($id) => (int)$id !== $doc_id
            ));
            self::saveSession($session);
        }
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    public static function deleteStakeholder(): void
    {
        header('Content-Type: application/json');
        $id = (int)($_POST['stakeholder_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        $sh = new InterventionStakeholder();
        $sh->getFromDB($id);
        $contractday_id = (int)($sh->fields['plugin_manageentities_contractdays_id'] ?? 0);

        $ok = $sh->delete(['id' => $id], true);
        if (!$ok) {
            echo json_encode(['success' => false]);
            exit;
        }

        $remaining = null;
        $credit    = 0.0;
        if ($contractday_id > 0) {
            $cd = new ContractDay();
            $cd->getFromDB($contractday_id);
            $credit = (float)($cd->fields['nbday'] ?? 0);
            if ($credit > 0) {
                $assigned = array_sum(array_column(
                    (new InterventionStakeholder())->find(['plugin_manageentities_contractdays_id' => $contractday_id]),
                    'number_affected_days'
                ));
                $remaining = $credit - $assigned;
            }
        }

        echo json_encode([
            'success'        => true,
            'remaining_days' => $remaining,
            'credit'         => $credit,
        ]);
        exit;
    }

    public static function renderInterventionBlock(): void
    {
        $idx  = (int)($_POST['idx'] ?? 1);
        $rand = mt_rand();
        $session = self::getSession();

        $config  = Config::getInstance();
        $is_day  = ($config->fields['hourorday'] == Config::DAY);

        $contractDates = self::getContractDates($session['contracts_id']);
        $cfg = Config::getInstance();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_intervention_block.html.twig', [
            'idx'                  => $idx,
            'rand'                 => $rand,
            'is_day'               => $is_day,
            'contract_begin_date'  => $contractDates['begin_date'],
            'contract_end_date'    => $contractDates['end_date'],
            'contractstate_html'   => self::buildContractStateHtml(
                "interventions[{$idx}][plugin_manageentities_contractstates_id]",
                $rand,
                (int)($cfg->fields['wizard_contractstate_id'] ?? 0)
            ),
            'contract_type_html'   => $is_day ? self::buildDropdownHtml(
                fn() => Contract::dropdownContractType("interventions[{$idx}][contract_type]", (int)($cfg->fields['wizard_contract_type'] ?? 0), $rand)
            ) : '',
            'entities_html'        => self::buildEntityHtml("interventions[{$idx}][entities_id]", $session['entities_id'], $rand),
            'contracts_html'       => self::buildContractListHtml("interventions[{$idx}][contracts_id]", $session['contracts_id'], $session['entities_id'], $rand),
            'contractday_id'       => 0,
            'criprices_section'    => '',
            'stakeholders_section' => '',
            'wizard_url'           => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // CriPrice
    // -------------------------------------------------------------------------

    public static function saveCriPrice(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::saveCriPriceAndReturn($_POST));
        exit;
    }

    public static function saveCriPriceAndReturn(array $input = []): array
    {
        if (empty($input)) {
            $input = $_POST;
        }

        $contractday_id = (int)($input['plugin_manageentities_contractdays_id'] ?? 0);
        if ($contractday_id <= 0) {
            return ['success' => false, 'message' => __('Intervention not saved yet', 'manageentities')];
        }

        $price = (float)($input['price'] ?? 0);
        if ($price <= 0) {
            return ['success' => false, 'message' => __('Price must be greater than 0', 'manageentities')];
        }

        $contractDay = new ContractDay();
        $contractDay->getFromDB($contractday_id);

        $criPrice = new CriPrice();
        $data = [
            'plugin_manageentities_contractdays_id' => $contractday_id,
            'entities_id'                           => $contractDay->fields['entities_id'],
            'plugin_manageentities_critypes_id'     => (int)($input['plugin_manageentities_critypes_id'] ?? 0),
            'price'                                 => $price,
            'is_default'                            => (int)(bool)($input['is_default'] ?? 0),
        ];

        $existing_id = (int)($input['criprice_id'] ?? 0);
        if ($existing_id > 0) {
            $data['id'] = $existing_id;
            $ok = $criPrice->update($data);
        } else {
            // Only one rate allowed per intervention in the wizard
            $already = $criPrice->find(['plugin_manageentities_contractdays_id' => $contractday_id]);
            if (!empty($already)) {
                return ['success' => false, 'message' => __('Only one rate is allowed per service period', 'manageentities')];
            }
            $ok = $criPrice->add($data);
        }

        if (!$ok) {
            return ['success' => false, 'message' => __('Error saving price', 'manageentities')];
        }

        return ['success' => true, 'criprice_id' => (int)($criPrice->fields['id'] ?? $existing_id)];
    }

    public static function deleteCriPrice(): void
    {
        header('Content-Type: application/json');
        $id = (int)($_POST['criprice_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        $criPrice = new CriPrice();
        $criPrice->getFromDB($id);
        $contractday_id = (int)($criPrice->fields['plugin_manageentities_contractdays_id'] ?? 0);
        $ok = $criPrice->delete(['id' => $id], true);
        // After deletion, check if any rate remains
        $has_rate = $contractday_id > 0 && !empty((new CriPrice())->find(['plugin_manageentities_contractdays_id' => $contractday_id]));
        echo json_encode(['success' => (bool)$ok, 'has_rate' => $has_rate, 'contractday_id' => $contractday_id]);
        exit;
    }

    public static function renderCriPriceBlock(): void
    {
        $contractday_id = (int)($_POST['contractday_id'] ?? 0);
        $rand = mt_rand();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_criprice_block.html.twig', [
            'rand'              => $rand,
            'contractday_id'    => $contractday_id,
            'criprice_id'       => 0,
            'critype_html'      => self::buildDropdownHtml(
                fn() => Dropdown::show(CriType::class, [
                    'name'    => 'plugin_manageentities_critypes_id',
                    'rand'    => $rand,
                    'display' => false,
                ])
            ),
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Reset
    // -------------------------------------------------------------------------

    /**
     * Return a human-readable summary of all objects created during this wizard session.
     * Used to populate the confirmation modal before destructive reset.
     */
    public static function getResetSummary(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::getResetSummaryAndReturn());
        exit;
    }

    public static function getResetSummaryAndReturn(): array
    {
        $session = self::getSession();
        $items   = [];

        // Entity — only show when we created it (not in existing_entity mode)
        $entities_id = (int)($session['entities_id'] ?? 0);
        if ($entities_id > 0 && ($session['wizard_mode'] ?? '') !== 'existing_entity') {
            $e = new \Entity();
            if ($e->getFromDB($entities_id)) {
                $items[] = [
                    'type'  => __('Entity'),
                    'label' => $e->fields['completename'] ?? $e->fields['name'],
                    'id'    => $entities_id,
                ];
            }
        }

        // Contacts
        foreach (($session['contacts'] ?? []) as $contact_id) {
            $contact_id = (int)$contact_id;
            if ($contact_id <= 0) continue;
            $c = new GlpiContact();
            if ($c->getFromDB($contact_id)) {
                $items[] = [
                    'type'  => __('Contact'),
                    'label' => trim(($c->fields['firstname'] ?? '') . ' ' . $c->fields['name']),
                    'id'    => $contact_id,
                ];
            }
        }

        // Contract
        $contracts_id = (int)($session['contracts_id'] ?? 0);
        if ($contracts_id > 0) {
            $ct = new \Contract();
            if ($ct->getFromDB($contracts_id)) {
                $items[] = [
                    'type'  => __('Contract'),
                    'label' => $ct->fields['name'],
                    'id'    => $contracts_id,
                ];
            }
        }

        // Documents
        $docNames = [];
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $doc_id = (int)$doc_id;
            if ($doc_id <= 0) continue;
            $d = new Document();
            if ($d->getFromDB($doc_id)) {
                $docNames[] = $d->fields['name'] ?? $d->fields['filename'] ?? ('Doc #' . $doc_id);
            }
        }
        if (!empty($docNames)) {
            $items[] = [
                'type'  => _n('Document', 'Documents', count($docNames)),
                'label' => implode(', ', $docNames),
                'id'    => 0,
            ];
        }

        // ContractDays + their CriPrices + Stakeholders
        foreach (($session['contractdays'] ?? []) as $cd_id) {
            $cd_id = (int)$cd_id;
            if ($cd_id <= 0) continue;
            $cd = new ContractDay();
            if ($cd->getFromDB($cd_id)) {
                $items[] = [
                    'type'  => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
                    'label' => $cd->fields['name'] ?? ('ID ' . $cd_id),
                    'id'    => $cd_id,
                ];

                $criPrice = new CriPrice();
                foreach ($criPrice->find(['plugin_manageentities_contractdays_id' => $cd_id]) as $cp) {
                    $items[] = [
                        'type'  => __('Daily rate', 'manageentities'),
                        'label' => number_format((float)($cp['price'] ?? 0), 2),
                        'id'    => $cp['id'],
                    ];
                }

                $sh    = new InterventionStakeholder();
                $names = [];
                foreach ($sh->find(['plugin_manageentities_contractdays_id' => $cd_id]) as $row) {
                    $u      = new User();
                    $names[] = $u->getFromDB((int)($row['users_id'] ?? 0))
                        ? $u->getFriendlyName()
                        : ('User #' . $row['users_id']);
                }
                if (!empty($names)) {
                    $items[] = [
                        'type'  => _n('User affected', 'Users affected', count($names), 'manageentities'),
                        'label' => implode(', ', $names),
                        'id'    => 0,
                    ];
                }
            }
        }

        return ['success' => true, 'items' => $items];
    }

    /**
     * Delete all objects created by the wizard, then clear the session.
     */
    public static function resetAndDelete(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::resetAndDeleteAndReturn());
        exit;
    }

    public static function resetAndDeleteAndReturn(): array
    {
        $session = self::getSession();

        // Clear session immediately so it is always reset, even if deletions fail
        unset($_SESSION[self::SESSION_KEY]);

        // Delete in child-first order to respect FK constraints

        foreach (($session['contractdays'] ?? []) as $cd_id) {
            $cd_id = (int)$cd_id;
            if ($cd_id <= 0) continue;

            $sh = new InterventionStakeholder();
            foreach ($sh->find(['plugin_manageentities_contractdays_id' => $cd_id]) as $row) {
                $sh->delete(['id' => $row['id']], true);
            }

            $criPrice = new CriPrice();
            foreach ($criPrice->find(['plugin_manageentities_contractdays_id' => $cd_id]) as $row) {
                $criPrice->delete(['id' => $row['id']], true);
            }

            $cd = new ContractDay();
            $cd->delete(['id' => $cd_id], true);
        }

        // Plugin contract row
        $plugin_contract_id = (int)($session['plugin_contract_id'] ?? 0);
        if ($plugin_contract_id > 0) {
            $pc = new Contract();
            $pc->delete(['id' => $plugin_contract_id], true);
        }

        // Documents linked to the contract
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $doc_id = (int)$doc_id;
            if ($doc_id <= 0) continue;
            $d = new Document();
            $d->delete(['id' => $doc_id], true);
        }

        // GLPI contract
        $contracts_id = (int)($session['contracts_id'] ?? 0);
        if ($contracts_id > 0) {
            $ct = new \Contract();
            $ct->delete(['id' => $contracts_id], true);
        }

        // Contacts (plugin link then GLPI contact)
        foreach (($session['contacts'] ?? []) as $contact_id) {
            $contact_id = (int)$contact_id;
            if ($contact_id <= 0) continue;

            $pluginContact = new Contact();
            foreach ($pluginContact->find(['contacts_id' => $contact_id]) as $row) {
                $pluginContact->delete(['id' => $row['id']], true);
            }

            $c = new GlpiContact();
            $c->delete(['id' => $contact_id], true);
        }

        // Entity — only delete if we created it (not in existing_entity mode)
        $entities_id = (int)($session['entities_id'] ?? 0);
        if ($entities_id > 0 && ($session['wizard_mode'] ?? '') !== 'existing_entity') {
            $e = new \Entity();
            $e->delete(['id' => $entities_id], true);
        }

        return ['success' => true];
    }

    public static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Render full step (called from front/addelements.form.php)
    // -------------------------------------------------------------------------

    public static function renderStep(): void
    {
        // Fresh arrival (no ?step= in URL) always resets the session and shows the mode choice
        if (!isset($_GET['step'])) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        $session = self::getSession();

        // Show mode choice page when no mode selected yet
        if ($session['wizard_mode'] === '') {
            self::renderModeChoice();
            return;
        }

        $step = (int)($_GET['step'] ?? $session['step']);
        // clamp 1-5
        $step = max(1, min(5, $step));

        $config  = Config::getInstance();
        $is_day  = ($config->fields['hourorday'] == Config::DAY);
        $is_hour = ($config->fields['hourorday'] == Config::HOUR);

        $wizard_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php';

        if ($session['wizard_mode'] === 'existing_entity') {
            $steps = [
                1 => __('Entity', 'manageentities'),
                3 => __('Contract', 'manageentities'),
                4 => __('Management type', 'manageentities'),
                5 => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
            ];
        } else {
            $steps = [
                1 => __('Entity', 'manageentities'),
                2 => __('Contacts', 'manageentities'),
                3 => __('Contract', 'manageentities'),
                4 => __('Management type', 'manageentities'),
                5 => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
            ];
        }

        $step_template = '@manageentities/wizard/step' . $step . '_' . self::stepSlug($step, $session['wizard_mode']) . '.html.twig';
        $rand          = mt_rand();

        $vars = [
            'step'        => $step,
            'max_step'    => $session['step'],
            'steps'       => $steps,
            'session'     => $session,
            'wizard_url'  => $wizard_url,
            'wizard_mode' => $session['wizard_mode'],
            'rand'        => $rand,
            'is_day'      => $is_day,
            'is_hour'     => $is_hour,
        ];

        $vars += self::buildStepVars($step, $session, $rand, $is_day, $is_hour);

        TemplateRenderer::getInstance()->display('@manageentities/wizard/layout.html.twig', array_merge($vars, [
            'step_template' => $step_template,
            'step_vars'     => $vars,
            'redirect_url'  => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
        ]));
    }

    private static function stepSlug(int $step, string $wizard_mode = ''): string
    {
        if ($step === 1 && $wizard_mode === 'existing_entity') {
            return 'select_entity';
        }
        return match ($step) {
            1 => 'entity',
            2 => 'contacts',
            3 => 'contract',
            4 => 'management',
            5 => 'interventions',
            default => 'entity',
        };
    }

    private static function buildStepVars(int $step, array $session, int $rand, bool $is_day, bool $is_hour): array
    {
        return match ($step) {
            1 => self::buildEntityVars($session, $rand),
            2 => self::buildContactsVars($session, $rand),
            3 => self::buildContractVars($session, $rand),
            4 => self::buildManagementVars($session, $rand, $is_day, $is_hour),
            5 => self::buildInterventionsVars($session, $rand, $is_day),
            default => [],
        };
    }

    // -------------------------------------------------------------------------
    // Per-step variable builders
    // -------------------------------------------------------------------------

    private static function buildEntityVars(array $session, int $rand): array
    {
        if ($session['wizard_mode'] === 'existing_entity') {
            return [
                'entity_select_html' => self::buildEntityHtml('entities_id', $session['entities_id'], $rand),
            ];
        }

        $entity = new \Entity();
        if ($session['entities_id'] > 0) {
            $entity->getFromDB($session['entities_id']);
        } else {
            $entity->getEmpty();
        }

        return [
            'entity_fields'  => $entity->fields,
            'entities_html'  => self::buildEntityHtml('entities_id', (int)($entity->fields['entities_id'] ?? 0), $rand),
        ];
    }

    private static function buildContactsVars(array $session, int $rand): array
    {
        $contacts = [];
        foreach ($session['contacts'] as $idx => $contact_id) {
            if ($contact_id > 0) {
                $c = new GlpiContact();
                $c->getFromDB($contact_id);

                // Read is_default from the plugin table (not stored in glpi_contacts)
                $pluginContact = new Contact();
                $pluginRows = $pluginContact->find(['contacts_id' => $contact_id]);
                $is_manager = !empty($pluginRows) ? (int)(reset($pluginRows)['is_default'] ?? 0) : 0;

                $fields = $c->fields;
                $fields['is_manager'] = $is_manager;

                $contacts[$idx] = [
                    'fields'          => $fields,
                    'usertitles_html' => self::buildDropdownHtml(
                        fn() => UserTitle::dropdown(['name' => "contacts[{$idx}][usertitles_id]", 'rand' => $rand, 'value' => $c->fields['usertitles_id'] ?? 0, 'display' => false])
                    ),
                    'contacttype_html'=> self::buildDropdownHtml(
                        fn() => ContactType::dropdown(['name' => "contacts[{$idx}][contacttypes_id]", 'rand' => $rand, 'value' => $c->fields['contacttypes_id'] ?? 0, 'display' => false])
                    ),
                    'entities_html'   => self::buildEntityHtml("contacts[{$idx}][entities_id]", $c->fields['entities_id'] ?? 0, $rand),
                ];
            }
        }

        // Always provide at least 1 empty block
        if (empty($contacts)) {
            $contacts[1] = self::buildEmptyContactVars(1, $rand, $session['entities_id']);
        }

        return ['contacts' => $contacts];
    }

    private static function buildEmptyContactVars(int $idx, int $rand, int $entities_id): array
    {
        $default_contacttype = 0;
        try {
            $default_contacttype = (int)(Config::getInstance()->fields['wizard_contacttypes_id'] ?? 0);
        } catch (\Throwable $e) {
        }
        return [
            'fields'           => [],
            'usertitles_html'  => self::buildDropdownHtml(
                fn() => UserTitle::dropdown(['name' => "contacts[{$idx}][usertitles_id]", 'rand' => $rand, 'display' => false])
            ),
            'contacttype_html' => self::buildDropdownHtml(
                fn() => ContactType::dropdown(['name' => "contacts[{$idx}][contacttypes_id]", 'rand' => $rand, 'value' => $default_contacttype, 'display' => false])
            ),
            'entities_html'    => self::buildEntityHtml("contacts[{$idx}][entities_id]", $entities_id, $rand),
        ];
    }

    private static function buildContractVars(array $session, int $rand): array
    {
        $contract = new \Contract();
        if ($session['contracts_id'] > 0) {
            $contract->getFromDB($session['contracts_id']);
        } else {
            $contract->getEmpty();
        }

        // Prefill from template if set (consumed after render)
        $prefill = $session['contract_prefill'] ?? [];
        if (!empty($prefill)) {
            unset($session['contract_prefill']);
            self::saveSession($session);
        }

        $fields = $contract->fields;
        $v = fn(string $key, mixed $default) => $prefill[$key] ?? $fields[$key] ?? $default;

        ob_start();
        GlpiContractType::dropdown([
            'name'  => 'contracttypes_id',
            'rand'  => $rand,
            'value' => $v('contracttypes_id', 0),
        ]);
        $contracttype_html = ob_get_clean();

        ob_start();
        State::dropdown([
            'name'  => 'states_id',
            'rand'  => $rand,
            'value' => $v('states_id', 0),
        ]);
        $state_html = ob_get_clean();

        ob_start();
        \Contract::dropdownAlert(['name' => 'alerting', 'rand' => $rand, 'value' => $fields['alerting'] ?? 0]);
        $alert_html = ob_get_clean();

        ob_start();
        Dropdown::showNumber('duration', ['rand' => $rand, 'value' => $v('duration', 12), 'min' => 0, 'max' => 120]);
        $duration_html = ob_get_clean();

        // Pre-render existing document rows (for Back navigation)
        $existing_docs_html = '';
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $doc_id = (int)$doc_id;
            if ($doc_id <= 0) continue;
            $d = new Document();
            if (!$d->getFromDB($doc_id)) continue;
            $docCat  = new DocumentCategory();
            $docCatId = (int)($d->fields['documentcategories_id'] ?? 0);
            $catName  = ($docCatId > 0 && $docCat->getFromDB($docCatId)) ? ($docCat->fields['completename'] ?? $docCat->fields['name']) : '';
            $docName  = htmlspecialchars($d->fields['name'] ?? $d->fields['filename'] ?? '');
            $existing_docs_html .= '<div class="document-block d-flex align-items-center gap-2 mb-2 flex-wrap" data-id="' . $doc_id . '">'
                . '<span class="badge bg-outline-secondary">' . $docName . '</span>'
                . ($catName ? '<span class="text-muted small">' . htmlspecialchars($catName) . '</span>' : '')
                . '<button type="button" class="btn btn-sm btn-outline-danger"'
                . ' onclick="wizardDeleteDocument(' . $doc_id . ', this, WIZARD_URL)">'
                . '<i class="ti ti-trash"></i></button>'
                . '</div>';
        }

        $rand_tpl = mt_rand();
        ob_start();
        Dropdown::show(\Contract::class, [
            'name'       => '_contract_template_id',
            'rand'       => $rand_tpl,
            'value'      => 0,
            'emptylabel' => __('-- Select a template --', 'manageentities'),
            'condition'  => ['is_template' => 1],
            'displaywith'=> ['template_name'],
        ]);
        $template_dropdown_html = ob_get_clean();

        return [
            'contract_fields'         => array_merge($fields, $prefill),
            'contracttype_html'       => $contracttype_html,
            'state_html'              => $state_html,
            'alert_html'              => $alert_html,
            'duration_html'           => $duration_html,
            'existing_docs_html'      => $existing_docs_html,
            'entities_html'           => self::buildEntityHtml('entities_id', $session['entities_id'], $rand),
            'template_dropdown_html'  => $template_dropdown_html,
            'rand'                    => $rand,
            'rand_tpl'                => $rand_tpl,
        ];
    }

    private static function buildManagementVars(array $session, int $rand, bool $is_day, bool $is_hour): array
    {
        $pluginContract = new Contract();
        if ($session['plugin_contract_id'] > 0) {
            $pluginContract->getFromDB($session['plugin_contract_id']);
        } else {
            $pluginContract->getEmpty();
        }
        $fields = $pluginContract->fields;

        // Pre-fill date_signature with contract begin_date when creating a new management type
        if ($session['plugin_contract_id'] <= 0 && $session['contracts_id'] > 0) {
            $glpiContract = new \Contract();
            $glpiContract->getFromDB($session['contracts_id']);
            $fields['date_signature'] = $glpiContract->fields['begin_date'] ?? '';
        }

        $management_html  = '';
        $contract_type_html = '';
        if ($is_hour) {
            $management_html = self::buildDropdownHtml(
                fn() => Contract::dropdownContractManagement('management', $fields['management'] ?? 0, $rand)
            );
            $contract_type_html = self::buildDropdownHtml(
                fn() => Contract::dropdownContractType('contract_type', $fields['contract_type'] ?? 0, $rand)
            );
        }

        ob_start();
        Dropdown::showTimeStamp('duration_moving', ['rand' => $rand, 'value' => $fields['duration_moving'] ?? 0]);
        $duration_moving_html = ob_get_clean();

        return [
            'contracts_id'         => $session['contracts_id'],
            'entities_id'          => $session['entities_id'],
            'plugin_contract_id'   => $session['plugin_contract_id'],
            'date_signature'       => $fields['date_signature'] ?? '',
            'management_html'      => $management_html,
            'contract_type_html'   => $contract_type_html,
            'duration_moving_html' => $duration_moving_html,
            'is_hour_mode'         => $is_hour,
            'is_day_price'         => $is_day,
            // Default to checked for new records; use stored value when editing
            'show_on_global_gantt'     => $session['plugin_contract_id'] > 0 ? (bool)$fields['show_on_global_gantt'] : true,
            'refacturable_costs'       => (bool)($fields['refacturable_costs'] ?? false),
            'contract_added'           => (bool)($fields['contract_added'] ?? false),
            'active_editor_suscription'=> $session['plugin_contract_id'] > 0 ? (bool)$fields['active_editor_suscription'] : true,
            'cloud_client'             => (bool)($fields['cloud_client'] ?? false),
            'internet_publication'     => (bool)($fields['internet_publication'] ?? false),
            'moving_management'        => (bool)($fields['moving_management'] ?? false),
        ];
    }

    private static function buildInterventionsVars(array $session, int $rand, bool $is_day): array
    {
        $contractDates = self::getContractDates($session['contracts_id']);
        $interventions = [];
        foreach ($session['contractdays'] as $idx => $contractday_id) {
            if ($contractday_id > 0) {
                $cd = new ContractDay();
                $cd->getFromDB($contractday_id);
                $interventions[$idx] = [
                    'fields'               => $cd->fields,
                    'contract_begin_date'  => $contractDates['begin_date'],
                    'contract_end_date'    => $contractDates['end_date'],
                    'contractstate_html'   => self::buildContractStateHtml(
                        "interventions[{$idx}][plugin_manageentities_contractstates_id]",
                        $rand,
                        $cd->fields['plugin_manageentities_contractstates_id'] ?? 0
                    ),
                    'contract_type_html'   => $is_day ? self::buildDropdownHtml(
                        fn() => Contract::dropdownContractType("interventions[{$idx}][contract_type]", $cd->fields['plugin_manageentities_critypes_id'] ?? 0, $rand)
                    ) : '',
                    'entities_html'        => self::buildEntityHtml("interventions[{$idx}][entities_id]", $cd->fields['entities_id'] ?? 0, $rand),
                    'contracts_html'       => self::buildContractListHtml("interventions[{$idx}][contracts_id]", $cd->fields['contracts_id'] ?? 0, $cd->fields['entities_id'] ?? 0, $rand),
                    'contractday_id'       => $contractday_id,
                    'criprices_section'    => self::buildCriPricesSectionHtml($contractday_id, $rand, $is_day),
                    'stakeholders_section' => self::buildStakeholdersSectionHtml($contractday_id, $rand, $session['entities_id']),
                ];
            }
        }

        if (empty($interventions)) {
            $interventions[1] = self::buildEmptyInterventionVars(1, $rand, $session, $is_day);
        }

        return ['interventions' => $interventions];
    }

    private static function buildEmptyInterventionVars(int $idx, int $rand, array $session, bool $is_day): array
    {
        $contractDates = self::getContractDates($session['contracts_id']);
        $cfg = Config::getInstance();
        return [
            'fields'               => [],
            'contract_begin_date'  => $contractDates['begin_date'],
            'contract_end_date'    => $contractDates['end_date'],
            'contractstate_html'   => self::buildContractStateHtml(
                "interventions[{$idx}][plugin_manageentities_contractstates_id]",
                $rand,
                (int)($cfg->fields['wizard_contractstate_id'] ?? 0)
            ),
            'contract_type_html'   => $is_day ? self::buildDropdownHtml(
                fn() => Contract::dropdownContractType("interventions[{$idx}][contract_type]", (int)($cfg->fields['wizard_contract_type'] ?? 0), $rand)
            ) : '',
            'entities_html'        => self::buildEntityHtml("interventions[{$idx}][entities_id]", $session['entities_id'], $rand),
            'contracts_html'       => self::buildContractListHtml("interventions[{$idx}][contracts_id]", $session['contracts_id'], $session['entities_id'], $rand),
            'contractday_id'       => 0,
            'criprices_section'    => '',
            'stakeholders_section' => '',
        ];
    }

    // -------------------------------------------------------------------------
    // Dropdown HTML builders
    // -------------------------------------------------------------------------

    private static function buildDropdownHtml(callable $fn): string
    {
        ob_start();
        $returned = $fn();
        $captured = ob_get_clean();
        // Some dropdowns return HTML (display:false), others echo it directly
        return is_string($returned) && $returned !== '' ? $returned : (string)$captured;
    }

    private static function buildEntityHtml(string $name, int $value = 0, int $rand = 0): string
    {
        if ($value > 0) {
            $entity = new \Entity();
            $entity->getFromDB($value);
            $label = htmlspecialchars($entity->fields['completename'] ?? $entity->fields['name'] ?? '');
            return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . $value . '">'
                . '<input type="text" class="form-control" value="' . $label . '" readonly disabled>';
        }

        ob_start();
        Dropdown::show(\Entity::class, [
            'name'  => $name,
            'rand'  => $rand ?: mt_rand(),
            'value' => $value,
        ]);
        return ob_get_clean();
    }

    private static function buildContractStateHtml(string $name, int $rand, int $value = 0): string
    {
        ob_start();
        Dropdown::show(ContractState::class, [
            'name'  => $name,
            'rand'  => $rand,
            'value' => $value,
        ]);
        return ob_get_clean();
    }

    private static function buildContractListHtml(string $name, int $value, int $entities_id, int $rand): string
    {
        if ($value > 0) {
            $contract = new \Contract();
            $contract->getFromDB($value);
            $label = htmlspecialchars($contract->fields['name'] ?? '');
            return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . $value . '">'
                . '<input type="text" class="form-control" value="' . $label . '" readonly disabled>';
        }

        global $DB;
        $contracts = [];
        if ($entities_id > 0) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_contracts',
                'WHERE'  => ['entities_id' => $entities_id, 'is_deleted' => 0],
            ]);
            foreach ($iterator as $row) {
                $contracts[$row['id']] = $row['name'];
            }
        }
        ob_start();
        Dropdown::showFromArray($name, $contracts, ['rand' => $rand, 'value' => $value]);
        return ob_get_clean();
    }

    private static function getContractDates(int $contracts_id): array
    {
        if ($contracts_id <= 0) {
            return ['begin_date' => '', 'end_date' => ''];
        }
        $c = new \Contract();
        $c->getFromDB($contracts_id);
        $begin = $c->fields['begin_date'] ?? '';
        $duration = (int)($c->fields['duration'] ?? 0);
        $end = '';
        if ($begin && $duration > 0) {
            try {
                $dt = new \DateTime($begin);
                $dt->modify("+{$duration} months");
                $end = $dt->format('Y-m-d');
            } catch (\Exception $e) {
                $end = '';
            }
        }
        return ['begin_date' => $begin, 'end_date' => $end];
    }

    private static function buildCriPricesSectionHtml(int $contractday_id, int $rand, bool $is_day): string
    {
        $criprices = self::getCriPricesForContractDay($contractday_id);
        ob_start();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_criprices_section.html.twig', [
            'contractday_id' => $contractday_id,
            'rand'           => $rand,
            'is_day'         => $is_day,
            'criprices'      => $criprices,
            'has_rate'       => !empty($criprices),
            'wizard_url'     => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
            'critype_html'   => self::buildDropdownHtml(
                fn() => Dropdown::show(CriType::class, [
                    'name'    => 'new_critype_' . $contractday_id,
                    'rand'    => $rand,
                    'value'   => (int)(Config::getInstance()->fields['wizard_critype_id'] ?? 0),
                    'display' => false,
                ])
            ),
        ]);
        return ob_get_clean();
    }

    private static function buildStakeholdersSectionHtml(int $contractday_id, int $rand, int $entities_id): string
    {
        $cd = new ContractDay();
        $cd->getFromDB($contractday_id);
        $credit = (float)($cd->fields['nbday'] ?? 0);

        $stakeholders = self::getStakeholdersForContractDay($contractday_id);
        $enriched = [];
        $assigned = 0.0;
        foreach ($stakeholders as $sh) {
            $user = new User();
            $user->getFromDB((int)$sh['users_id']);
            $sh['user_name'] = $user->getFriendlyName();
            $enriched[] = $sh;
            $assigned += (float)$sh['number_affected_days'];
        }
        $remaining = $credit - $assigned;

        ob_start();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_stakeholders_section.html.twig', [
            'contractday_id' => $contractday_id,
            'rand'           => $rand,
            'stakeholders'   => $enriched,
            'credit'         => $credit,
            'remaining_days' => $remaining,
            'wizard_url'     => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
            'user_html'      => self::buildDropdownHtml(
                fn() => User::dropdown([
                    'name'     => 'new_user_' . $contractday_id,
                    'rand'     => $rand,
                    'entity'   => $entities_id,
                    'display'  => false,
                    'right'    => 'all',
                ])
            ),
        ]);
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private static function getCriPricesForContractDay(int $contractday_id): array
    {
        $criPrice = new CriPrice();
        $rows = $criPrice->find(['plugin_manageentities_contractdays_id' => $contractday_id]);
        foreach ($rows as &$row) {
            $criType = new CriType();
            if ($criType->getFromDB((int)($row['plugin_manageentities_critypes_id'] ?? 0))) {
                $row['critypes_name'] = $criType->fields['completename'] ?? $criType->fields['name'] ?? '';
            } else {
                $row['critypes_name'] = '';
            }
        }
        return $rows;
    }

    private static function getStakeholdersForContractDay(int $contractday_id): array
    {
        $sh = new InterventionStakeholder();
        return $sh->find(['plugin_manageentities_contractdays_id' => $contractday_id]);
    }
}
