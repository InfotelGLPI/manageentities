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
use DbUtils;
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
 * All data is kept in session until finishWizard() — nothing is written to the
 * database before the user confirms at the last step.  Documents are the only
 * exception: they are uploaded immediately so GLPI can handle the tmp-file
 * lifecycle, but they are linked to the contract only inside finishWizard().
 *
 * Public methods that end with AndReturn() return an array instead of
 * echoing JSON — these are used by PHPUnit integration tests.
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
            'wizard_mode'       => '',   // '' = choice not made | 'new_entity' | 'existing_entity'
            'step'              => 1,
            // Raw data — nothing written to DB until finishWizard()
            'entity_data'       => [],
            'entities_id'       => 0,   // set only in existing_entity mode or after finishWizard
            'contacts_data'     => [],   // [idx => [fields]]
            'contract_data'     => [],
            'contract_prefill'  => [],
            'management_data'   => [],
            'documents_ids'     => [],   // uploaded document IDs (pre-created, linked at finish)
            'interventions_data'=> [],   // [idx => ['fields'=>[], 'criprices'=>[], 'stakeholders'=>[]]]
        ];
    }

    public static function getSession(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::buildDefaultSession();
        }
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
        global $DB;

        $errors = [];
        if (empty(trim($input['name'] ?? ''))) {
            $errors['name'] = __('Name is required', 'manageentities');
        }

        $num = trim($input['num'] ?? '');
        if ($num !== '' && $DB !== null) {
            $exists = countElementsInTable(\Contract::getTable(), ['num' => $num]) > 0;
            if ($exists) {
                $errors['num'] = __('A contract with this number already exists', 'manageentities');
            }
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
        if (empty($input['end_date'] ?? '')) {
            $errors['end_date'] = __('End date is required', 'manageentities');
        }
        if (empty($input['plugin_manageentities_contractstates_id'] ?? 0)) {
            $errors['plugin_manageentities_contractstates_id'] = __('State is required', 'manageentities');
        }
        if (!isset($input['nbday']) || $input['nbday'] === '' || (float)$input['nbday'] < 0) {
            $errors['nbday'] = __('Initial credit is required', 'manageentities');
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    // -------------------------------------------------------------------------
    // Archive helpers
    // -------------------------------------------------------------------------

    private static function isArchivedEntity(int $entities_id): bool
    {
        $config = Config::getInstance();
        $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_entities_id <= 0 || $entities_id <= 0) {
            return false;
        }
        $sons = getSonsOf('glpi_entities', $archive_entities_id);
        unset($sons[$archive_entities_id]);
        return isset($sons[$entities_id]);
    }

    public static function unarchiveEntity(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::unarchiveEntityAndReturn($_POST));
        exit;
    }

    public static function unarchiveEntityAndReturn(array $input = []): array
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
        $config = Config::getInstance();
        $target_entities_id = (int)($config->fields['wizard_default_entities_id'] ?? 0);
        if ($target_entities_id <= 0) {
            return ['success' => false, 'message' => __('Default parent entity is not configured', 'manageentities')];
        }
        if (!$entity->update(['id' => $entities_id, 'entities_id' => $target_entities_id])) {
            return ['success' => false, 'message' => __('Error unarchiving entity', 'manageentities')];
        }
        $session = self::getSession();
        $session['wizard_mode'] = 'existing_entity';
        $session['entities_id'] = $entities_id;
        $session['step']        = max($session['step'], 3);
        self::saveSession($session);
        return ['success' => true, 'entities_id' => $entities_id, 'step' => 3];
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
        // If the entity is in the archive, propose unarchiving
        if (self::isArchivedEntity($entities_id)) {
            return [
                'success'         => false,
                'entity_archived' => true,
                'entities_id'     => $entities_id,
                'entity_name'     => $entity->fields['name'] ?? '',
            ];
        }

        $config = Config::getInstance();
        $forced_entities_id = (int)($config->fields['wizard_default_entities_id'] ?? 0);
        if ($forced_entities_id > 0) {
            $sons = getSonsOf('glpi_entities', $forced_entities_id);
            unset($sons[$forced_entities_id]);
            if (!isset($sons[$entities_id])) {
                return ['success' => false, 'errors' => ['entities_id' => __('Please select a child entity', 'manageentities')]];
            }
        }
        $session = self::getSession();
        $session['entities_id'] = $entities_id;
        $session['step']        = max($session['step'], 3);
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

        $config = Config::getInstance();
        $forced_entities_id    = (int)($config->fields['wizard_default_entities_id'] ?? 0);
        $submitted_entities_id = (int)($input['entities_id'] ?? 0);
        $resolved_entities_id  = ($submitted_entities_id > 0) ? $submitted_entities_id : $forced_entities_id;

        $entity_data = [
            'name'        => trim($input['name']),
            'entities_id' => $resolved_entities_id,
            'comment'     => $input['comment'] ?? '',
            'phonenumber' => $input['phonenumber'] ?? '',
            'fax'         => $input['fax'] ?? '',
            'email'       => $input['email'] ?? '',
            'website'     => $input['website'] ?? '',
            'address'     => $input['address'] ?? '',
            'postcode'    => $input['postcode'] ?? '',
            'town'        => $input['town'] ?? '',
            'state'       => $input['state'] ?? '',
            'country'     => $input['country'] ?? '',
        ];

        // Pre-check for duplicate (just for UX — actual uniqueness enforced at finishWizard)
        $entity = new \Entity();
        $existing = $entity->find(['name' => $entity_data['name'], 'entities_id' => $entity_data['entities_id']], [], 1);
        if (!empty($existing)) {
            $existing_entity = reset($existing);
            return [
                'success'       => false,
                'entity_exists' => true,
                'entities_id'   => (int)$existing_entity['id'],
                'entity_name'   => $existing_entity['name'],
            ];
        }

        // Check if an entity with the same name exists in the archive
        $config_arc = Config::getInstance();
        $archive_id = (int)($config_arc->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_id > 0) {
            $archived_sons = getSonsOf('glpi_entities', $archive_id);
            unset($archived_sons[$archive_id]);
            if (!empty($archived_sons)) {
                $archived = $entity->find(['name' => $entity_data['name'], 'id' => array_keys($archived_sons)], [], 1);
                if (!empty($archived)) {
                    $archived_row = reset($archived);
                    return [
                        'success'          => false,
                        'entity_archived'  => true,
                        'entities_id'      => (int)$archived_row['id'],
                        'entity_name'      => $archived_row['name'],
                    ];
                }
            }
        }

        $session['entity_data'] = $entity_data;
        $session['step']        = max($session['step'], 2);
        self::saveSession($session);

        return ['success' => true, 'step' => $session['step']];
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

        $contactsInput = $input['contacts'] ?? [];
        $savedData     = [];

        foreach ($contactsInput as $idx => $cInput) {
            if (empty(trim($cInput['name'] ?? ''))) {
                continue;
            }

            $validation = self::validateContactInput($cInput);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors'], 'contact_idx' => $idx];
            }

            $savedData[$idx] = [
                'name'            => trim($cInput['name']),
                'firstname'       => $cInput['firstname'] ?? '',
                'phone'           => $cInput['phone'] ?? '',
                'phone2'          => $cInput['phone2'] ?? '',
                'mobile'          => $cInput['mobile'] ?? '',
                'fax'             => $cInput['fax'] ?? '',
                'email'           => $cInput['email'] ?? '',
                'address'         => $cInput['address'] ?? '',
                'postcode'        => $cInput['postcode'] ?? '',
                'town'            => $cInput['town'] ?? '',
                'state'           => $cInput['state'] ?? '',
                'country'         => $cInput['country'] ?? '',
                'comment'         => $cInput['comment'] ?? '',
                'is_recursive'    => (int)(bool)($cInput['is_recursive'] ?? 0),
                'contacttypes_id' => (int)($cInput['contacttypes_id'] ?? 0),
                'usertitles_id'   => (int)($cInput['usertitles_id'] ?? 0),
                'is_manager'      => (int)(bool)($cInput['is_manager'] ?? 0),
            ];
        }

        $session['contacts_data'] = $savedData;
        $session['step']          = max($session['step'], 3);
        self::saveSession($session);

        return ['success' => true, 'step' => $session['step']];
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
            'fields'           => [],
            'usertitles_html'  => self::buildDropdownHtml(
                fn() => UserTitle::dropdown(['name' => "contacts[{$idx}][usertitles_id]", 'rand' => $rand, 'display' => false])
            ),
            'contacttype_html' => self::buildDropdownHtml(
                fn() => ContactType::dropdown(['name' => "contacts[{$idx}][contacttypes_id]", 'rand' => $rand, 'value' => $default_contacttype, 'display' => false])
            ),
            'entities_html'    => self::buildSessionEntityHtml("contacts[{$idx}][entities_id]", self::getSession()),
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Contract template pre-fill (session only)
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

    // -------------------------------------------------------------------------
    // Step 3 — Contract
    // -------------------------------------------------------------------------

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

        $contract_data = [
            'name'               => trim($input['name']),
            'num'                => $input['num'] ?? '',
            'accounting_number'  => $input['accounting_number'] ?? '',
            'comment'            => $input['comment'] ?? '',
            'is_recursive'       => (int)(bool)($input['is_recursive'] ?? 0),
            'contracttypes_id'   => (int)($input['contracttypes_id'] ?? 0),
            'begin_date'         => !empty($input['begin_date']) ? $input['begin_date'] : '',
            'duration'           => (int)($input['duration'] ?? 0),
            'notice'             => (int)($input['notice'] ?? 0),
            'periodicity'        => (int)($input['periodicity'] ?? 0),
            'billing'            => (int)($input['billing'] ?? 0),
            'renewal'            => (int)($input['renewal'] ?? 0),
            'max_links_allowed'  => (int)($input['max_links_allowed'] ?? 0),
            'use_saturday'       => (int)(bool)($input['use_saturday'] ?? 0),
            'use_sunday'         => (int)(bool)($input['use_sunday'] ?? 0),
            'states_id'          => (int)($input['states_id'] ?? 0),
        ];

        foreach (['week_begin_hour', 'week_end_hour', 'saturday_begin_hour', 'saturday_end_hour', 'sunday_begin_hour', 'sunday_end_hour'] as $hourField) {
            if (!empty($input[$hourField])) {
                $contract_data[$hourField] = $input[$hourField];
            }
        }

        $session['contract_data'] = $contract_data;
        $session['step']          = max($session['step'], 4);
        self::saveSession($session);

        return ['success' => true, 'step' => $session['step']];
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
            'idx'         => $idx,
            'rand'        => $rand,
            'doccat_html' => $doccat_html,
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

    /**
     * Upload documents immediately (GLPI needs to manage the tmp file).
     * IDs are stored in session and linked to the contract inside finishWizard().
     */
    public static function uploadDocuments(): void
    {
        header('Content-Type: application/json');
        $session = self::getSession();

        // We don't have a real entities_id yet in new_entity mode — use 0 (root entity).
        // The document will be re-linked with the correct entity inside finishWizard().
        $entities_id = (int)($session['entities_id'] ?? 0);

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

        if (empty($input['date_signature'] ?? '')) {
            return ['success' => false, 'errors' => ['date_signature' => __('Date of signature is required', 'manageentities')]];
        }

        $management_data = [
            'date_signature'            => $input['date_signature'],
            'date_renewal'              => !empty($input['date_renewal']) ? $input['date_renewal'] : '',
            'management'                => (int)($input['management'] ?? 0),
            'contract_type'             => (int)($input['contract_type'] ?? 0),
            'contract_added'            => (int)(bool)($input['contract_added'] ?? 0),
            'show_on_global_gantt'      => (int)(bool)($input['show_on_global_gantt'] ?? 0),
            'refacturable_costs'        => (int)(bool)($input['refacturable_costs'] ?? 0),
            'moving_management'         => (int)(bool)($input['moving_management'] ?? 0),
            'duration_moving'           => (int)($input['duration_moving'] ?? 0),
            'active_editor_suscription' => (int)(bool)($input['active_editor_suscription'] ?? 0),
            'cloud_client'              => (int)(bool)($input['cloud_client'] ?? 0),
            'internet_publication'      => (int)(bool)($input['internet_publication'] ?? 0),
        ];

        $session['management_data'] = $management_data;
        $session['step']            = max($session['step'], 5);
        self::saveSession($session);

        return ['success' => true, 'step' => $session['step']];
    }

    // -------------------------------------------------------------------------
    // Step 5 — Interventions (stored in session, written at finishWizard)
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

        $session            = self::getSession();
        $interventionsInput = $input['interventions'] ?? [];

        foreach ($interventionsInput as $idx => $iInput) {
            if (empty(trim($iInput['name'] ?? ''))) {
                continue;
            }

            $validation = self::validateInterventionInput($iInput);
            if (!$validation['valid']) {
                return ['success' => false, 'errors' => $validation['errors'], 'intervention_idx' => $idx];
            }

            // Preserve existing criprices/stakeholders when re-saving
            $existing = $session['interventions_data'][$idx] ?? [];

            $fields = [
                'name'                                    => trim($iInput['name']),
                'plugin_manageentities_contractstates_id' => (int)($iInput['plugin_manageentities_contractstates_id'] ?? 0),
                'begin_date'                              => $iInput['begin_date'],
                'end_date'                                => $iInput['end_date'],
                'nbday'                                   => (float)($iInput['nbday'] ?? 0),
                'report'                                  => (float)($iInput['report'] ?? 0),
                'charged'                                 => (int)(bool)($iInput['charged'] ?? 0),
                'comment'                                 => $iInput['comment'] ?? '',
            ];
            $contractType = (int)($iInput['contract_type'] ?? 0);
            if ($contractType > 0) {
                $fields['contract_type'] = $contractType;
            }

            $session['interventions_data'][$idx] = [
                'fields'       => $fields,
                'criprices'    => $existing['criprices']    ?? [],
                'stakeholders' => $existing['stakeholders'] ?? [],
            ];
        }

        self::saveSession($session);

        return [
            'success' => true,
            'step'    => $session['step'],
        ];
    }

    /** Save a single intervention block (per-block Save button). */
    public static function saveIntervention(): void
    {
        header('Content-Type: application/json');
        $input  = $_POST;
        $idx    = (int)($input['idx'] ?? 0);
        $session = self::getSession();
        $iInput  = $input['intervention'] ?? [];

        $validation = self::validateInterventionInput($iInput);
        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'errors' => $validation['errors']]);
            exit;
        }

        $existing = $session['interventions_data'][$idx] ?? [];

        $fields = [
            'name'                                    => trim($iInput['name']),
            'plugin_manageentities_contractstates_id' => (int)($iInput['plugin_manageentities_contractstates_id'] ?? 0),
            'begin_date'                              => $iInput['begin_date'],
            'end_date'                                => $iInput['end_date'],
            'nbday'                                   => (float)($iInput['nbday'] ?? 0),
            'report'                                  => (float)($iInput['report'] ?? 0),
            'charged'                                 => (int)(bool)($iInput['charged'] ?? 0),
            'comment'                                 => $iInput['comment'] ?? '',
        ];
        $contractType = (int)($iInput['contract_type'] ?? 0);
        if ($contractType > 0) {
            $fields['contract_type'] = $contractType;
        }

        $session['interventions_data'][$idx] = [
            'fields'       => $fields,
            'criprices'    => $existing['criprices']    ?? [],
            'stakeholders' => $existing['stakeholders'] ?? [],
        ];
        self::saveSession($session);

        $rand    = mt_rand();
        $config  = Config::getInstance();
        $is_day  = ($config->fields['hourorday'] == Config::DAY);

        $criprices_html    = self::buildCriPricesSectionHtml($idx, $session['interventions_data'][$idx], $rand, $is_day);
        $stakeholders_html = self::buildStakeholdersSectionHtml($idx, $session['interventions_data'][$idx], $rand, $session['entities_id']);

        echo json_encode([
            'success'           => true,
            'intervention_idx'  => $idx,
            'criprices_html'    => $criprices_html,
            'stakeholders_html' => $stakeholders_html,
        ]);
        exit;
    }

    public static function renderInterventionBlock(): void
    {
        $idx     = (int)($_POST['idx'] ?? 1);
        $rand    = mt_rand();
        $session = self::getSession();

        $config  = Config::getInstance();
        $is_day  = ($config->fields['hourorday'] == Config::DAY);

        $contractDates = self::getContractDatesFromSession($session);
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
            'entities_html'        => self::buildSessionEntityHtml("interventions[{$idx}][entities_id]", $session),
            'contracts_html'       => self::buildSessionContractHtml("interventions[{$idx}][contracts_id]", $session, $rand),
            'intervention_idx'     => $idx,
            'criprices_section'    => '',
            'stakeholders_section' => '',
            'wizard_url'           => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // CriPrice — stored in session under interventions_data[idx][criprices]
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

        $idx = (int)($input['intervention_idx'] ?? -1);
        if ($idx < 0) {
            return ['success' => false, 'message' => __('Intervention not saved yet', 'manageentities')];
        }

        $price = (float)($input['price'] ?? 0);
        if ($price <= 0) {
            return ['success' => false, 'message' => __('Price must be greater than 0', 'manageentities')];
        }

        $session = self::getSession();

        if (!isset($session['interventions_data'][$idx])) {
            return ['success' => false, 'message' => __('Intervention not saved yet', 'manageentities')];
        }

        $criprices = $session['interventions_data'][$idx]['criprices'] ?? [];

        // Only one rate per intervention allowed
        if (!empty($criprices)) {
            return ['success' => false, 'message' => __('Only one rate is allowed per service period', 'manageentities')];
        }

        $cp_idx = 0;
        $criprices[$cp_idx] = [
            'plugin_manageentities_critypes_id' => (int)($input['plugin_manageentities_critypes_id'] ?? 0),
            'price'                             => $price,
            'is_default'                        => (int)(bool)($input['is_default'] ?? 0),
        ];

        $session['interventions_data'][$idx]['criprices'] = $criprices;
        self::saveSession($session);

        // Virtual ID = "iidx_cpidx" (never a real DB id)
        $virtual_id = $idx . '_' . $cp_idx;

        return ['success' => true, 'criprice_id' => $virtual_id];
    }

    public static function deleteCriPrice(): void
    {
        header('Content-Type: application/json');
        $virtual_id = $_POST['criprice_id'] ?? '';
        [$idx, $cp_idx] = self::parseVirtualId($virtual_id);

        $session = self::getSession();

        if ($idx >= 0 && isset($session['interventions_data'][$idx]['criprices'][$cp_idx])) {
            unset($session['interventions_data'][$idx]['criprices'][$cp_idx]);
            self::saveSession($session);
        }

        $has_rate = !empty($session['interventions_data'][$idx]['criprices'] ?? []);
        echo json_encode(['success' => true, 'has_rate' => $has_rate, 'intervention_idx' => $idx]);
        exit;
    }

    public static function renderCriPriceBlock(): void
    {
        // Not used in session-only mode (CriPrices are added inline via wizardAddCriPrice)
        echo json_encode(['success' => false]);
        exit;
    }

    // -------------------------------------------------------------------------
    // Stakeholders — stored in session under interventions_data[idx][stakeholders]
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
        $idx      = (int)($input['intervention_idx'] ?? -1);
        $users_id = (int)($input['users_id'] ?? 0);
        $nb_days  = (float)($input['number_affected_days'] ?? 0);

        if ($idx < 0 || $users_id <= 0) {
            return ['success' => false, 'message' => __('Missing required fields', 'manageentities')];
        }
        if ($nb_days <= 0) {
            return ['success' => false, 'message' => __('Number of days must be greater than 0', 'manageentities')];
        }

        $session = self::getSession();

        if (!isset($session['interventions_data'][$idx])) {
            return ['success' => false, 'message' => __('Intervention not saved yet', 'manageentities')];
        }

        $stakeholders = $session['interventions_data'][$idx]['stakeholders'] ?? [];
        $nbday_credit = (float)($session['interventions_data'][$idx]['fields']['nbday'] ?? 0);

        // Check duplicate
        foreach ($stakeholders as $sh) {
            if ((int)$sh['users_id'] === $users_id) {
                $already_assigned = array_sum(array_column($stakeholders, 'number_affected_days'));
                $rem = $nbday_credit > 0 ? $nbday_credit - $already_assigned : null;
                return ['success' => false, 'message' => __('User already added', 'manageentities'),
                    'remaining_days' => $rem, 'credit' => $nbday_credit];
            }
        }

        $already_assigned = array_sum(array_column($stakeholders, 'number_affected_days'));
        if ($nbday_credit > 0 && $nb_days > ($nbday_credit - $already_assigned)) {
            $remaining_real = $nbday_credit - $already_assigned;
            return ['success' => false, 'message' => sprintf(
                __('Cannot assign %.2f day(s): only %.2f day(s) remaining out of %.2f', 'manageentities'),
                $nb_days, $remaining_real, $nbday_credit
            ), 'remaining_days' => $remaining_real, 'credit' => $nbday_credit];
        }

        $sh_idx = count($stakeholders);
        $stakeholders[$sh_idx] = [
            'users_id'             => $users_id,
            'number_affected_days' => $nb_days,
        ];

        $session['interventions_data'][$idx]['stakeholders'] = $stakeholders;
        self::saveSession($session);

        $user = new User();
        $user->getFromDB($users_id);

        $remaining_after = $nbday_credit > 0 ? ($nbday_credit - $already_assigned - $nb_days) : null;
        $virtual_id      = $idx . '_' . $sh_idx;

        return [
            'success'              => true,
            'stakeholder_id'       => $virtual_id,
            'user_name'            => htmlspecialchars($user->getFriendlyName()),
            'number_affected_days' => $nb_days,
            'remaining_days'       => $remaining_after,
            'credit'               => $nbday_credit,
        ];
    }

    public static function deleteStakeholder(): void
    {
        header('Content-Type: application/json');
        $virtual_id = $_POST['stakeholder_id'] ?? '';
        [$idx, $sh_idx] = self::parseVirtualId($virtual_id);

        $session = self::getSession();

        $remaining = null;
        $credit    = null;
        if ($idx >= 0 && isset($session['interventions_data'][$idx])) {
            unset($session['interventions_data'][$idx]['stakeholders'][$sh_idx]);
            self::saveSession($session);

            $nbday_credit = (float)($session['interventions_data'][$idx]['fields']['nbday'] ?? 0);
            if ($nbday_credit > 0) {
                $assigned  = array_sum(array_column($session['interventions_data'][$idx]['stakeholders'], 'number_affected_days'));
                $credit    = $nbday_credit;
                $remaining = $nbday_credit - $assigned;
            }
        }

        echo json_encode([
            'success'        => true,
            'remaining_days' => $remaining,
            'credit'         => $credit,
        ]);
        exit;
    }

    /** Parse a virtual ID of the form "iidx_subidx" → [iidx, subidx]. */
    private static function parseVirtualId(string $virtual_id): array
    {
        $parts = explode('_', $virtual_id, 2);
        if (count($parts) !== 2) {
            return [-1, -1];
        }
        return [(int)$parts[0], (int)$parts[1]];
    }

    // -------------------------------------------------------------------------
    // Document delete
    // -------------------------------------------------------------------------

    public static function deleteDocument(): void
    {
        header('Content-Type: application/json');
        $doc_id = (int)($_POST['document_id'] ?? 0);
        if ($doc_id <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        $d  = new Document();
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

    // -------------------------------------------------------------------------
    // Finish wizard — create everything in DB in one pass
    // -------------------------------------------------------------------------

    public static function finishWizard(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::validateAndSummarize());
        exit;
    }

    public static function commitWizard(): void
    {
        header('Content-Type: application/json');
        echo json_encode(self::commitWizardAndReturn());
        exit;
    }

    /** Validate session data and return a preview summary — does NOT write to DB. */
    public static function validateAndSummarize(): array
    {
        $session       = self::getSession();
        $interventions = $session['interventions_data'] ?? [];

        if (empty($interventions)) {
            return ['success' => false, 'errors' => ['global' => __('At least one service period with a rate is required', 'manageentities')]];
        }
        foreach ($interventions as $idx => $iv) {
            if (empty($iv['criprices'])) {
                $name = $iv['fields']['name'] ?? ('Period #' . $idx);
                return ['success' => false, 'errors' => ['global' => sprintf(
                    __('Period of contract "%s" requires at least one rate', 'manageentities'),
                    $name
                )]];
            }
        }

        return [
            'success' => true,
            'summary' => self::buildFinishSummaryFromSession($session, 0, 0),
        ];
    }

    /** Write everything to DB — called only after the user confirms in the modal. */
    public static function commitWizardAndReturn(): array
    {
        $session = self::getSession();

        // Re-validate before writing
        $interventions = $session['interventions_data'] ?? [];
        if (empty($interventions)) {
            return ['success' => false, 'errors' => ['global' => __('At least one service period with a rate is required', 'manageentities')]];
        }

        // 1. Entity
        $entities_id = (int)($session['entities_id'] ?? 0);
        if ($session['wizard_mode'] !== 'existing_entity') {
            $entity_data = $session['entity_data'] ?? [];
            if (empty($entity_data)) {
                return ['success' => false, 'errors' => ['global' => __('Entity data is missing', 'manageentities')]];
            }
            $entity = new \Entity();
            $entities_id = (int)$entity->add($entity_data);
            if (!$entities_id) {
                return ['success' => false, 'errors' => ['global' => __('Error creating entity', 'manageentities')]];
            }
        }

        // 2. Contacts
        $contact_ids = [];
        foreach (($session['contacts_data'] ?? []) as $idx => $cData) {
            $glpiContact = new GlpiContact();
            $contactInput = array_merge($cData, ['entities_id' => $entities_id]);
            unset($contactInput['is_manager']);
            $contact_id = $glpiContact->add($contactInput);
            if ($contact_id) {
                $contact_ids[$idx] = (int)$contact_id;
                self::linkPluginContact((int)$contact_id, $entities_id, (int)($cData['is_manager'] ?? 0));
            }
        }

        // 3. GLPI Contract
        $contract_data = $session['contract_data'] ?? [];
        if (empty($contract_data)) {
            return ['success' => false, 'errors' => ['global' => __('Contract data is missing', 'manageentities')]];
        }
        $contract_data['entities_id'] = $entities_id;
        $glpiContract = new \Contract();
        // Fix empty dates to NULL-equivalent for DB
        foreach (['begin_date'] as $df) {
            if (empty($contract_data[$df])) {
                $contract_data[$df] = 'NULL';
            }
        }
        $contracts_id = (int)$glpiContract->add($contract_data);
        if (!$contracts_id) {
            return ['success' => false, 'errors' => ['global' => __('Error creating contract', 'manageentities')]];
        }

        // 3b. Link uploaded documents to the contract via Document_Item
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $doc_id = (int)$doc_id;
            if ($doc_id <= 0) continue;
            $di = new \Document_Item();
            $di->add([
                'documents_id' => $doc_id,
                'itemtype'     => \Contract::class,
                'items_id'     => $contracts_id,
                'entities_id'  => $entities_id,
            ]);
        }

        // 4. Plugin contract (management type)
        $management_data = $session['management_data'] ?? [];
        if (empty($management_data)) {
            return ['success' => false, 'errors' => ['global' => __('Management data is missing', 'manageentities')]];
        }
        $management_data['contracts_id'] = $contracts_id;
        $management_data['entities_id']  = $entities_id;
        foreach (['date_renewal'] as $df) {
            if (empty($management_data[$df])) {
                $management_data[$df] = 'NULL';
            }
        }
        $pluginContract = new Contract();
        $plugin_contract_id = (int)$pluginContract->add($management_data);
        if (!$plugin_contract_id) {
            return ['success' => false, 'errors' => ['global' => __('Error creating management type', 'manageentities')]];
        }

        // 5. Interventions
        foreach ($interventions as $iv) {
            $fields = $iv['fields'];
            $fields['entities_id']  = $entities_id;
            $fields['contracts_id'] = $contracts_id;
            if (empty($fields['end_date'])) {
                $fields['end_date'] = 'NULL';
            }

            $contractDay = new ContractDay();
            $contractday_id = (int)$contractDay->add($fields);
            if (!$contractday_id) continue;

            // CriPrices
            foreach (($iv['criprices'] ?? []) as $cp) {
                $criPrice = new CriPrice();
                $criPrice->add([
                    'plugin_manageentities_contractdays_id' => $contractday_id,
                    'entities_id'                           => $entities_id,
                    'plugin_manageentities_critypes_id'     => (int)($cp['plugin_manageentities_critypes_id'] ?? 0),
                    'price'                                 => (float)($cp['price'] ?? 0),
                    'is_default'                            => (int)($cp['is_default'] ?? 0),
                ]);
            }

            // Stakeholders
            foreach (($iv['stakeholders'] ?? []) as $sh) {
                $shObj = new InterventionStakeholder();
                $shObj->add([
                    'plugin_manageentities_contractdays_id' => $contractday_id,
                    'users_id'                              => (int)$sh['users_id'],
                    'number_affected_days'                  => (float)$sh['number_affected_days'],
                ]);
            }
        }

        $summary = self::buildFinishSummaryFromSession($session, $entities_id, $contracts_id);

        // Clear wizard session
        unset($_SESSION[self::SESSION_KEY]);

        return [
            'success'      => true,
            'summary'      => $summary,
            'redirect_url' => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/addelements.form.php',
        ];
    }

    private static function buildFinishSummaryFromSession(array $session, int $entities_id, int $contracts_id): array
    {
        $cfg        = Config::getInstance();
        $unit_label = ($cfg->fields['hourorday'] == Config::HOUR)
            ? __('hours', 'manageentities')
            : __('days', 'manageentities');

        $items = [];

        if ($session['wizard_mode'] !== 'existing_entity' && !empty($session['entity_data']['name'])) {
            $items[] = ['type' => _n('Entity', 'Entities', 1), 'label' => $session['entity_data']['name']];
        }

        foreach (($session['contacts_data'] ?? []) as $c) {
            $items[] = ['type' => __('Contact'), 'label' => trim(($c['firstname'] ?? '') . ' ' . $c['name'])];
        }

        if (!empty($session['contract_data']['name'])) {
            $items[] = ['type' => __('Contract'), 'label' => $session['contract_data']['name']];
        }

        $docCount = count($session['documents_ids'] ?? []);
        if ($docCount > 0) {
            $items[] = ['type' => _n('Document', 'Documents', $docCount), 'label' => sprintf('%d', $docCount)];
        }

        foreach (($session['interventions_data'] ?? []) as $iv) {
            $cdLabel = $iv['fields']['name'] ?? '';
            if ((float)($iv['fields']['nbday'] ?? 0) > 0) {
                $cdLabel .= ' — ' . number_format((float)$iv['fields']['nbday'], 2) . ' ' . $unit_label;
            }
            $items[] = ['type' => _n('Period of contract', 'Periods of contract', 1, 'manageentities'), 'label' => $cdLabel];

            foreach (($iv['criprices'] ?? []) as $cp) {
                $criType  = new CriType();
                $typeName = $criType->getFromDB((int)($cp['plugin_manageentities_critypes_id'] ?? 0))
                    ? ($criType->fields['completename'] ?? $criType->fields['name'] ?? '')
                    : '';
                $items[] = ['type' => CriPrice::getTypeName(1),
                    'label' => ($typeName ? $typeName . ' — ' : '') . number_format((float)($cp['price'] ?? 0), 2)];
            }

            foreach (($iv['stakeholders'] ?? []) as $sh) {
                $u = new User();
                $label = $u->getFromDB((int)$sh['users_id'])
                    ? $u->getFriendlyName() . ' (' . number_format((float)$sh['number_affected_days'], 2) . ' ' . $unit_label . ')'
                    : ('User #' . $sh['users_id']);
                $items[] = ['type' => _n('User affected', 'Users affected', 1, 'manageentities'), 'label' => $label];
            }
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Summary for finish modal (pre-confirm — reads from session, not DB)
    // -------------------------------------------------------------------------

    public static function getFinishSummaryAndReturn(): array
    {
        $session = self::getSession();
        return [
            'success' => true,
            'items'   => self::buildFinishSummaryFromSession($session, 0, 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Reset
    // -------------------------------------------------------------------------

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

        if ($session['wizard_mode'] !== 'existing_entity' && !empty($session['entity_data']['name'])) {
            $items[] = ['type' => _n('Entity', 'Entities', 1), 'label' => $session['entity_data']['name'], 'id' => 0];
        } elseif ($session['wizard_mode'] === 'existing_entity' && $session['entities_id'] > 0) {
            $e = new \Entity();
            if ($e->getFromDB($session['entities_id'])) {
                $items[] = ['type' => _n('Entity', 'Entities', 1), 'label' => $e->fields['completename'] ?? $e->fields['name'], 'id' => 0];
            }
        }

        foreach (($session['contacts_data'] ?? []) as $c) {
            $items[] = ['type' => __('Contact'), 'label' => trim(($c['firstname'] ?? '') . ' ' . $c['name']), 'id' => 0];
        }

        if (!empty($session['contract_data']['name'])) {
            $items[] = ['type' => __('Contract'), 'label' => $session['contract_data']['name'], 'id' => 0];
        }

        $docCount = count($session['documents_ids'] ?? []);
        if ($docCount > 0) {
            $items[] = ['type' => _n('Document', 'Documents', $docCount), 'label' => sprintf('%d document(s)', $docCount), 'id' => 0];
        }

        foreach (($session['interventions_data'] ?? []) as $iv) {
            $items[] = [
                'type'  => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
                'label' => $iv['fields']['name'] ?? '?',
                'id'    => 0,
            ];
        }

        return ['success' => true, 'items' => $items];
    }

    /**
     * Reset wizard: delete uploaded documents (only orphans), clear session.
     * Nothing else to delete — nothing was written to DB yet.
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

        // Delete uploaded documents (they have no itemtype/items_id yet so are true orphans)
        foreach (($session['documents_ids'] ?? []) as $doc_id) {
            $doc_id = (int)$doc_id;
            if ($doc_id <= 0) continue;
            $d = new Document();
            $d->delete(['id' => $doc_id], true);
        }

        unset($_SESSION[self::SESSION_KEY]);
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
        if (!isset($_GET['step'])) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        $session = self::getSession();

        if ($session['wizard_mode'] === '') {
            self::renderModeChoice();
            return;
        }

        $step = (int)($_GET['step'] ?? $session['step']);
        $step = max(1, min(5, $step));

        $config  = Config::getInstance();
        $is_day  = ($config->fields['hourorday'] == Config::DAY);
        $is_hour = ($config->fields['hourorday'] == Config::HOUR);

        $wizard_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php';

        if ($session['wizard_mode'] === 'existing_entity') {
            $steps = [
                1 => _n('Entity', 'Entities', 1),
                3 => __('Contract', 'manageentities'),
                4 => __('Management type', 'manageentities'),
                5 => _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
            ];
        } else {
            $steps = [
                1 => _n('Entity', 'Entities', 1),
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
            'redirect_url'  => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/addelements.form.php',
            'wizard_i18n'   => [
                'nothingToDisplay'      => __('Nothing to display.', 'manageentities'),
                'entityExistsMsg'       => __('The entity "%s" already exists. Do you want to use it to create a new contract?', 'manageentities'),
                'entityArchivedMsg'     => __('The customer "%s" is currently archived. Do you want to unarchive it and place it under the default parent entity to continue?', 'manageentities'),
                'unarchiveAndContinue'  => __('Unarchive and continue', 'manageentities'),
            ],
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
            $config = Config::getInstance();
            $forced_entities_id  = (int)($config->fields['wizard_default_entities_id'] ?? 0);
            $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);
            $allowed_ids = [];

            if ($forced_entities_id > 0) {
                $sons = getSonsOf('glpi_entities', $forced_entities_id);
                unset($sons[$forced_entities_id]); // exclude the parent entity itself
                $allowed_ids = array_keys($sons);
            }
            if ($archive_entities_id > 0) {
                $archive_sons = getSonsOf('glpi_entities', $archive_entities_id);
                unset($archive_sons[$archive_entities_id]); // exclude the archive root itself
                $allowed_ids = array_unique(array_merge($allowed_ids, array_keys($archive_sons)));
            }
            $condition = !empty($allowed_ids) ? ['id' => $allowed_ids] : [];

            return [
                'entity_select_html' => self::buildEntityHtml('entities_id', $session['entities_id'], $rand, $condition),
            ];
        }

        $config = Config::getInstance();
        $forced_entities_id = (int)($config->fields['wizard_default_entities_id'] ?? 0);

        $entity_data        = $session['entity_data'] ?? [];
        $parent_entities_id = (int)($entity_data['entities_id'] ?? $forced_entities_id);

        // Suggestions datalist
        $entity = new \Entity();
        $criteria = $forced_entities_id > 0 ? ['entities_id' => $forced_entities_id] : [];
        $rows = $entity->find($criteria, ['name ASC'], 500);
        $name_suggestions = array_column($rows, 'name');

        return [
            'entity_fields'        => $entity_data,
            'entities_html'        => self::buildEntityHtml('entities_id', $parent_entities_id, $rand),
            'parent_entity_locked' => $forced_entities_id > 0,
            'name_suggestions'     => $name_suggestions,
        ];
    }

    private static function buildContactsVars(array $session, int $rand): array
    {
        $contacts = [];
        foreach ($session['contacts_data'] as $idx => $cData) {
            $contacts[$idx] = [
                'fields'           => $cData,
                'usertitles_html'  => self::buildDropdownHtml(
                    fn() => UserTitle::dropdown(['name' => "contacts[{$idx}][usertitles_id]", 'rand' => $rand, 'value' => $cData['usertitles_id'] ?? 0, 'display' => false])
                ),
                'contacttype_html' => self::buildDropdownHtml(
                    fn() => ContactType::dropdown(['name' => "contacts[{$idx}][contacttypes_id]", 'rand' => $rand, 'value' => $cData['contacttypes_id'] ?? 0, 'display' => false])
                ),
                'entities_html'    => self::buildSessionEntityHtml("contacts[{$idx}][entities_id]", $session),
            ];
        }

        if (empty($contacts)) {
            $contacts[1] = self::buildEmptyContactVars(1, $rand, $session);
        }

        return ['contacts' => $contacts];
    }

    private static function buildEmptyContactVars(int $idx, int $rand, array $session): array
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
            'entities_html'    => self::buildSessionEntityHtml("contacts[{$idx}][entities_id]", $session),
        ];
    }

    private static function buildContractVars(array $session, int $rand): array
    {
        // Prefill from template if set (consumed after render)
        $prefill = $session['contract_prefill'] ?? [];
        if (!empty($prefill)) {
            unset($session['contract_prefill']);
            self::saveSession($session);
        }

        $fields = array_merge($session['contract_data'] ?? [], $prefill);
        $v = fn(string $key, mixed $default) => $fields[$key] ?? $default;

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
        \Contract::dropdownAlert(['name' => 'alerting', 'rand' => $rand, 'value' => $v('alerting', 0)]);
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
            $docCat   = new DocumentCategory();
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
        $template_condition  = ['is_template' => 1];
        $config_forced_entity = (int)(Config::getInstance()->fields['wizard_default_entities_id'] ?? 0);
        if ($config_forced_entity > 0) {
            $dbu = new DbUtils();
            $visible_entities = array_merge(
                [$config_forced_entity],
                array_keys($dbu->getAncestorsOf('glpi_entities', $config_forced_entity))
            );
            $template_condition['glpi_contracts.entities_id'] = $visible_entities;
        }
        Dropdown::show(\Contract::class, [
            'name'        => '_contract_template_id',
            'rand'        => $rand_tpl,
            'value'       => 0,
            'emptylabel'  => __('-- Select a template --', 'manageentities'),
            'condition'   => $template_condition,
            'displaywith' => ['template_name'],
        ]);
        $template_dropdown_html = ob_get_clean();

        return [
            'contract_fields'        => $fields,
            'contracttype_html'      => $contracttype_html,
            'state_html'             => $state_html,
            'alert_html'             => $alert_html,
            'duration_html'          => $duration_html,
            'existing_docs_html'     => $existing_docs_html,
            'entities_html'          => self::buildSessionEntityHtml('entities_id', $session),
            'template_dropdown_html' => $template_dropdown_html,
            'rand'                   => $rand,
            'rand_tpl'               => $rand_tpl,
        ];
    }

    private static function buildManagementVars(array $session, int $rand, bool $is_day, bool $is_hour): array
    {
        $fields    = $session['management_data'] ?? [];
        $is_first  = empty($session['management_data']);  // true before the user has ever submitted step 4

        // Pre-fill date_signature from contract begin_date when not yet set
        if (empty($fields['date_signature']) && !empty($session['contract_data']['begin_date'])) {
            $fields['date_signature'] = $session['contract_data']['begin_date'];
        }

        $management_html    = '';
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
            'contracts_id'             => 0,  // not in DB yet
            'entities_id'              => $session['entities_id'],
            'plugin_contract_id'       => 0,
            'date_signature'           => $fields['date_signature'] ?? '',
            'management_html'          => $management_html,
            'contract_type_html'       => $contract_type_html,
            'duration_moving_html'     => $duration_moving_html,
            'is_hour_mode'             => $is_hour,
            'is_day_price'             => $is_day,
            'show_on_global_gantt'     => $is_first ? true : (bool)($fields['show_on_global_gantt'] ?? false),
            'refacturable_costs'       => (bool)($fields['refacturable_costs'] ?? false),
            'contract_added'           => !empty($session['documents_ids'])
                                           || (bool)($fields['contract_added'] ?? false),
            'active_editor_suscription'=> $is_first ? true : (bool)($fields['active_editor_suscription'] ?? false),
            'cloud_client'             => (bool)($fields['cloud_client'] ?? false),
            'internet_publication'     => (bool)($fields['internet_publication'] ?? false),
            'moving_management'        => (bool)($fields['moving_management'] ?? false),
        ];
    }

    private static function buildInterventionsVars(array $session, int $rand, bool $is_day): array
    {
        $contractDates = self::getContractDatesFromSession($session);
        $interventions = [];

        foreach ($session['interventions_data'] as $idx => $iv) {
            $interventions[$idx] = [
                'fields'               => $iv['fields'],
                'contract_begin_date'  => $contractDates['begin_date'],
                'contract_end_date'    => $contractDates['end_date'],
                'contractstate_html'   => self::buildContractStateHtml(
                    "interventions[{$idx}][plugin_manageentities_contractstates_id]",
                    $rand,
                    (int)($iv['fields']['plugin_manageentities_contractstates_id'] ?? 0)
                ),
                'contract_type_html'   => $is_day ? self::buildDropdownHtml(
                    fn() => Contract::dropdownContractType("interventions[{$idx}][contract_type]", (int)($iv['fields']['contract_type'] ?? 0), $rand)
                ) : '',
                'entities_html'        => self::buildSessionEntityHtml("interventions[{$idx}][entities_id]", $session),
                'contracts_html'       => self::buildSessionContractHtml("interventions[{$idx}][contracts_id]", $session, $rand),
                'intervention_idx'     => $idx,
                'criprices_section'    => self::buildCriPricesSectionHtml($idx, $iv, $rand, $is_day),
                'stakeholders_section' => self::buildStakeholdersSectionHtml($idx, $iv, $rand, $session['entities_id']),
            ];
        }

        if (empty($interventions)) {
            $interventions[1] = self::buildEmptyInterventionVars(1, $rand, $session, $is_day);
        }

        return ['interventions' => $interventions];
    }

    private static function buildEmptyInterventionVars(int $idx, int $rand, array $session, bool $is_day): array
    {
        $contractDates = self::getContractDatesFromSession($session);
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
            'entities_html'        => self::buildSessionEntityHtml("interventions[{$idx}][entities_id]", $session),
            'contracts_html'       => self::buildSessionContractHtml("interventions[{$idx}][contracts_id]", $session, $rand),
            'intervention_idx'     => $idx,
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
        return is_string($returned) && $returned !== '' ? $returned : (string)$captured;
    }

    private static function buildEntityHtml(string $name, int $value = 0, int $rand = 0, array $condition = []): string
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
            'name'      => $name,
            'rand'      => $rand ?: mt_rand(),
            'value'     => $value,
            'condition' => $condition,
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

    /**
     * Entity display for intervention blocks.
     * When the entity is not in DB yet (new_entity mode, entities_id=0), shows the future name from session.
     * When entities_id>0 (existing_entity mode), shows the real entity name.
     */
    private static function buildSessionEntityHtml(string $name, array $session): string
    {
        $entities_id = (int)($session['entities_id'] ?? 0);
        if ($entities_id > 0) {
            $entity = new \Entity();
            $entity->getFromDB($entities_id);
            $label = htmlspecialchars($entity->fields['completename'] ?? $entity->fields['name'] ?? '');
        } else {
            $label = htmlspecialchars($session['entity_data']['name'] ?? '');
        }
        return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . $entities_id . '">'
            . '<input type="text" class="form-control" value="' . $label . '" readonly disabled>';
    }

    /**
     * In session mode the contract is not in DB yet.
     * We show a read-only representation of the contract name stored in session.
     */
    private static function buildSessionContractHtml(string $name, array $session, int $rand): string
    {
        $contractName = $session['contract_data']['name'] ?? '';
        if ($contractName !== '') {
            return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="0">'
                . '<input type="text" class="form-control" value="' . htmlspecialchars($contractName) . '" readonly disabled>';
        }
        ob_start();
        Dropdown::showFromArray($name, [], ['rand' => $rand, 'value' => 0]);
        return ob_get_clean();
    }

    private static function linkPluginContact(int $contact_id, int $entities_id, int $is_manager): void
    {
        $pluginContact = new Contact();

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

    // -------------------------------------------------------------------------
    // Section HTML builders (session-based, no DB IDs)
    // -------------------------------------------------------------------------

    private static function buildCriPricesSectionHtml(int $idx, array $iv, int $rand, bool $is_day): string
    {
        $criprices   = $iv['criprices'] ?? [];
        $enriched    = [];
        foreach ($criprices as $cp_idx => $cp) {
            $criType = new CriType();
            $typeName = $criType->getFromDB((int)($cp['plugin_manageentities_critypes_id'] ?? 0))
                ? ($criType->fields['completename'] ?? $criType->fields['name'] ?? '')
                : '';
            $enriched[] = array_merge($cp, [
                'id'           => $idx . '_' . $cp_idx,
                'critypes_name'=> $typeName,
            ]);
        }

        ob_start();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_criprices_section.html.twig', [
            'intervention_idx' => $idx,
            'rand'             => $rand,
            'is_day'           => $is_day,
            'criprices'        => $enriched,
            'has_rate'         => !empty($enriched),
            'wizard_url'       => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
            'critype_html'     => self::buildDropdownHtml(
                fn() => Dropdown::show(CriType::class, [
                    'name'    => 'new_critype_' . $idx,
                    'rand'    => $rand,
                    'value'   => (int)(Config::getInstance()->fields['wizard_critype_id'] ?? 0),
                    'display' => false,
                ])
            ),
        ]);
        return ob_get_clean();
    }

    private static function buildStakeholdersSectionHtml(int $idx, array $iv, int $rand, int $entities_id): string
    {
        $stakeholders = $iv['stakeholders'] ?? [];
        $credit       = (float)($iv['fields']['nbday'] ?? 0);
        $assigned     = array_sum(array_column($stakeholders, 'number_affected_days'));
        $remaining    = $credit > 0 ? $credit - $assigned : null;

        $enriched = [];
        foreach ($stakeholders as $sh_idx => $sh) {
            $user = new User();
            $user->getFromDB((int)$sh['users_id']);
            $enriched[] = array_merge($sh, [
                'id'        => $idx . '_' . $sh_idx,
                'user_name' => $user->getFriendlyName(),
            ]);
        }

        ob_start();
        TemplateRenderer::getInstance()->display('@manageentities/wizard/step5_stakeholders_section.html.twig', [
            'intervention_idx' => $idx,
            'rand'             => $rand,
            'stakeholders'     => $enriched,
            'credit'           => $credit,
            'remaining_days'   => $remaining,
            'wizard_url'       => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/wizard.php',
            'user_html'        => self::buildDropdownHtml(
                fn() => User::dropdown([
                    'name'    => 'new_user_' . $idx,
                    'rand'    => $rand,
                    'entity'  => $entities_id,
                    'display' => false,
                    'right'   => 'all',
                ])
            ),
        ]);
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private static function getContractDatesFromSession(array $session): array
    {
        $begin    = $session['contract_data']['begin_date'] ?? '';
        $duration = (int)($session['contract_data']['duration'] ?? 0);
        $end      = '';
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
}
