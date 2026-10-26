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

namespace GlpiPlugin\Manageentities;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Html;
use Session;
use User;

/**
 * Wizard of the operating document (DEX) of a client, built as the DAT one (DatWizard): one step
 * per group of sections, each one saved in the draft (Dex, DexServer) so that it can be resumed
 * later. The DEX being unique per client, the wizard is addressed by the entity.
 *
 * When the DEX is created (first save of step 1), the servers, the system and the system
 * information of the DAT of the client are copied into it: the DEX then lives on its own.
 *
 * The steps are plain forms posted to front/dex.form.php (handlePost()), then redirected: the
 * errors and values of a failed post are kept in session for the step to show them again.
 */
class DexWizard
{
    public const STEP_GENERAL     = 1;
    public const STEP_SYSTEM      = 2;
    public const STEP_SUPERVISION = 3;
    public const STEP_BACKUPS     = 4;
    public const STEP_RESTARTS    = 5;
    public const STEP_OPERATIONS  = 6;
    public const STEP_SUMMARY     = 7;

    public const LAST_STEP = self::STEP_SUMMARY;

    private const SESSION_KEY = 'manageentities_dex_wizard';

    /**
     * @return array<int, string>
     */
    public static function getSteps(): array
    {
        return [
            self::STEP_GENERAL     => __('General information', 'manageentities'),
            self::STEP_SYSTEM      => __('Servers and system', 'manageentities'),
            self::STEP_SUPERVISION => __('Supervision', 'manageentities'),
            self::STEP_BACKUPS     => __('Backups and processes', 'manageentities'),
            self::STEP_RESTARTS    => __('Service restarts', 'manageentities'),
            self::STEP_OPERATIONS  => __('Tasks, backup, maintenance, preproduction', 'manageentities'),
            self::STEP_SUMMARY     => __('Summary', 'manageentities'),
        ];
    }

    /**
     * Placeholders filled by each step of sections
     *
     * @return array<int, list<string>>
     */
    public static function getStepSections(): array
    {
        return [
            self::STEP_SYSTEM      => ['SYSTEM_INFORMATIONS'],
            self::STEP_SUPERVISION => ['TEXT_PROCESSUS', 'LIST_PROCESSUS', 'TEXT_FOLDERS', 'TEXT_SCRIPTS', 'TEXT_EPURATIONS'],
            self::STEP_BACKUPS     => ['TEXT_BACKUPS', 'TEXT_CHECK_PROCESSUS', 'COMMAND_CHECK_PROCESSUS'],
            self::STEP_RESTARTS    => ['TEXT_RESTART_APACHE', 'COMMAND_RESTART_APACHE', 'TEXT_RESTART_MYSQL', 'COMMAND_RESTART_MYSQL'],
            self::STEP_OPERATIONS  => [
                'TEXT_LAUNCH_CRON',
                'COMMAND_LAUNCH_CRON',
                'TEXT_LAUNCH_BACKUP',
                'COMMAND_LAUNCH_BACKUP',
                'TEXT_LAUNCH_MAINTENANCE_MODE',
                'TEXT_PREPROD_COPY_DATAS',
            ],
        ];
    }

    /**
     * Label of each section placeholder
     *
     * @return array<string, string>
     */
    public static function getSectionLabels(): array
    {
        return [
            'SYSTEM_INFORMATIONS'          => __('System information', 'manageentities'),
            'TEXT_PROCESSUS'               => __('Processes used', 'manageentities'),
            'LIST_PROCESSUS'               => __('List of the processes', 'manageentities'),
            'TEXT_FOLDERS'                 => __('Folders to watch', 'manageentities'),
            'TEXT_SCRIPTS'                 => __('Scripts to watch', 'manageentities'),
            'TEXT_EPURATIONS'              => __('Purges to perform', 'manageentities'),
            'TEXT_BACKUPS'                 => __('Backups', 'manageentities'),
            'TEXT_CHECK_PROCESSUS'         => __('State of the processes', 'manageentities'),
            'COMMAND_CHECK_PROCESSUS'      => __('Commands to check the processes', 'manageentities'),
            'TEXT_RESTART_APACHE'          => __('Restart of the web server', 'manageentities'),
            'COMMAND_RESTART_APACHE'       => __('Commands to restart the web server', 'manageentities'),
            'TEXT_RESTART_MYSQL'           => __('Restart of the database server', 'manageentities'),
            'COMMAND_RESTART_MYSQL'        => __('Commands to restart the database server', 'manageentities'),
            'TEXT_LAUNCH_CRON'             => __('Launch of the scheduled tasks', 'manageentities'),
            'COMMAND_LAUNCH_CRON'          => __('Commands to launch the scheduled tasks', 'manageentities'),
            'TEXT_LAUNCH_BACKUP'           => __('Manual backup of the databases', 'manageentities'),
            'COMMAND_LAUNCH_BACKUP'        => __('Commands of the manual backup', 'manageentities'),
            'TEXT_LAUNCH_MAINTENANCE_MODE' => __('Maintenance mode', 'manageentities'),
            'TEXT_PREPROD_COPY_DATAS'      => __('Copy of the production to the preproduction', 'manageentities'),
        ];
    }

    /**
     * Kind of a section, from its placeholder: TEXT_* and SYSTEM_INFORMATIONS are rich text,
     * COMMAND_* and the list of the processes blocks of commands (each line a line of the "Code"
     * paragraph of the template). The kinds are the DAT ones (DatWizard::KIND_*).
     */
    public static function getSectionKind(string $placeholder): string
    {
        return match (true) {
            $placeholder === 'SYSTEM_INFORMATIONS',
            str_starts_with($placeholder, 'TEXT_')     => DatWizard::KIND_RICHTEXT,
            $placeholder === 'LIST_PROCESSUS',
            $placeholder === 'PROCESSUS',
            str_starts_with($placeholder, 'COMMAND_')  => DatWizard::KIND_CODE,
            default                                    => DatWizard::KIND_TEXT,
        };
    }

    public static function getFormUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/dex.form.php';
    }

    public static function getStepUrl(int $entities_id, int $step): string
    {
        return self::getFormUrl() . '?entities_id=' . $entities_id . '&step=' . $step;
    }

    /**
     * Whether the current user can open the wizard
     */
    public static function canUse(): bool
    {
        return Session::haveRightsOr(Dex::$rightname, [CREATE, UPDATE]);
    }

    // -------------------------------------------------------------------------
    // Session (errors and submitted values of the last failed post)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     */
    private static function keepFailedPost(int $step, array $errors, array $values): void
    {
        $_SESSION[self::SESSION_KEY] = ['step' => $step, 'errors' => $errors, 'values' => $values];
    }

    /**
     * Errors and values of the last failed post of this step, consumed on read
     *
     * @return array{errors: array<string, string>, values: array|null}
     */
    private static function pullFailedPost(int $step): array
    {
        $data = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($data) || (int) ($data['step'] ?? 0) !== $step) {
            return ['errors' => [], 'values' => null];
        }
        return ['errors' => $data['errors'] ?? [], 'values' => $data['values'] ?? null];
    }

    // -------------------------------------------------------------------------
    // Loading and access checks
    // -------------------------------------------------------------------------

    /**
     * Client entity of the wizard: it must exist and be visible
     */
    private static function loadEntity(int $entities_id): \Entity
    {
        $entity = new \Entity();
        if (!$entity->getFromDB($entities_id)) {
            throw new NotFoundHttpException();
        }
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
        return $entity;
    }

    /**
     * DEX of the client, its right checked; null when it does not exist yet, if the user may
     * create it
     */
    private static function loadDex(int $entities_id, int $right): ?Dex
    {
        $dex = Dex::getForEntity($entities_id);
        if ($dex === null) {
            $input = ['entities_id' => $entities_id];
            if (!(new Dex())->can(-1, CREATE, $input)) {
                throw new AccessDeniedHttpException();
            }
            return null;
        }
        if (!$dex->can($dex->getID(), $right)) {
            throw new AccessDeniedHttpException();
        }
        return $dex;
    }

    /**
     * Saving a step of a generated DEX makes it a draft again until the next generation
     */
    private static function updateDex(Dex $dex, int $step, array $input = []): bool
    {
        $input['id']     = $dex->getID();
        $input['step']   = max((int) $dex->fields['step'], $step);
        $input['status'] = Dex::STATUS_DRAFT;

        return $dex->update($input);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public static function renderStep(int $entities_id, int $step): void
    {
        $entity = self::loadEntity($entities_id);
        $dex    = self::loadDex($entities_id, READ);

        // Steps are filled in order: no jump beyond the step following the last saved one
        $reached = $dex !== null ? (int) $dex->fields['step'] : 0;
        $step    = max(self::STEP_GENERAL, min($step, $reached + 1, self::LAST_STEP));

        $failed = self::pullFailedPost($step);
        $values = $failed['values'];
        $vars   = match ($step) {
            self::STEP_GENERAL => self::buildGeneralVars($entity, $dex, $values),
            self::STEP_SUMMARY => self::buildSummaryVars($dex),
            default            => self::buildSectionsVars($dex, $step, $values),
        };

        // The servers and sections steps are the DAT ones: same fields, same script
        $templates = [
            self::STEP_GENERAL => '@manageentities/dex/step_general.html.twig',
            self::STEP_SYSTEM  => '@manageentities/dat/step_system.html.twig',
            self::STEP_SUMMARY => '@manageentities/dex/step_summary.html.twig',
        ];

        TemplateRenderer::getInstance()->display('@manageentities/dex/layout.html.twig', [
            'steps'         => self::getSteps(),
            'step'          => $step,
            'last_step'     => self::LAST_STEP,
            'reached'       => $reached,
            'entities_id'   => $entities_id,
            'client_name'   => $entity->fields['name'],
            'dex'           => $dex?->fields,
            'statuses'      => Dex::getStatuses(),
            'form_url'      => self::getFormUrl(),
            'step_url'      => self::getFormUrl() . '?entities_id=' . $entities_id . '&step=',
            'cancel_url'    => \Entity::getFormURLWithID($entities_id),
            'errors'        => $failed['errors'],
            'step_template' => $templates[$step] ?? '@manageentities/dat/step_sections.html.twig',
            'step_vars'     => $vars,
        ]);
    }

    /**
     * Step 1: client, author, date and system. A new DEX is prefilled with the name of the
     * entity, the current user, the date of the day and the system of the DAT of the client.
     */
    private static function buildGeneralVars(\Entity $entity, ?Dex $dex, ?array $values): array
    {
        $dat = $dex === null ? Dat::getForEntity((int) $entity->getID()) : null;

        $general = $values ?? ($dex !== null
            ? [
                'client'          => $dex->getSections()['CLIENT'],
                'author_users_id' => (int) $dex->fields['author_users_id'],
                'dex_date'        => (string) $dex->fields['dex_date'],
                'os'              => (string) $dex->fields['os'],
            ]
            : [
                'client'          => $entity->fields['name'],
                'author_users_id' => Session::getLoginUserID(),
                'dex_date'        => date('Y-m-d', strtotime($_SESSION['glpi_currenttime'])),
                'os'              => (string) ($dat?->fields['os'] ?? ''),
            ]);

        return [
            'general'     => $general,
            'systems'     => DatOsTemplate::getSystems(),
            // The version is set by the generation: the next one is shown
            'version'     => ($dex !== null ? (int) $dex->fields['version'] : 0) + 1,
            'logo_url'    => DatWizard::getLogoUrl((int) $entity->getID()),
            'entities_id' => (int) $entity->getID(),
            // A new DEX takes the servers over from the DAT of the client, if any
            'from_dat'    => $dat !== null,
        ];
    }

    /**
     * Steps of sections, and the servers of the System step
     */
    private static function buildSectionsVars(?Dex $dex, int $step, ?array $values): array
    {
        $stored   = $dex?->getSections() ?? array_fill_keys(Dex::SECTIONS, '');
        $labels   = self::getSectionLabels();
        $servers  = $dex !== null ? DexServer::getForDex($dex->getID()) : [];
        // Model of the system of the DEX (step 1): it fills the sections left empty
        $os       = (string) ($dex?->fields['os'] ?? '');
        $model    = DexOsTemplate::getSectionsFor($os);
        $sections = [];
        foreach (self::getStepSections()[$step] ?? [] as $placeholder) {
            $value     = (string) ($values['sections'][$placeholder] ?? $stored[$placeholder]);
            $prefilled = $values === null && trim($value) === '' && ($model[$placeholder] ?? '') !== '';
            $sections[] = [
                'key'       => $placeholder,
                'label'     => $labels[$placeholder],
                'kind'      => self::getSectionKind($placeholder),
                'value'     => $prefilled ? $model[$placeholder] : $value,
                'prefilled' => $prefilled,
            ];
        }

        $vars = [
            'sections' => $sections,
            // Offered when the system has a model holding at least one section of the step
            'model'    => array_filter(array_intersect_key($model, array_flip(self::getStepSections()[$step] ?? []))) !== []
                ? DatOsTemplate::getSystems()[$os]
                : '',
        ];
        if ($step === self::STEP_SYSTEM) {
            $vars['servers'] = $values['servers'] ?? $servers;
        }

        return $vars;
    }

    /**
     * "Apply the model": every section of the step replaced by the model of the system of the
     * DEX, after the step was saved
     */
    private static function applyModel(Dex $dex, int $step): void
    {
        $model = DexOsTemplate::getSectionsFor((string) $dex->fields['os']);
        if ($model === []) {
            return;
        }
        $sections = [];
        foreach (self::getStepSections()[$step] ?? [] as $placeholder) {
            $sections[$placeholder] = $model[$placeholder] ?? '';
        }
        self::updateDex($dex, $step, ['sections' => $sections]);
    }

    /**
     * Last step: what the document will hold, section by section
     */
    private static function buildSummaryVars(?Dex $dex): array
    {
        $sections = $dex?->getSections() ?? array_fill_keys(Dex::SECTIONS, '');
        $labels   = self::getSectionLabels();

        $steps = [];
        foreach (self::getStepSections() as $step => $placeholders) {
            $rows = [];
            foreach ($placeholders as $placeholder) {
                $rows[] = [
                    'label' => $labels[$placeholder],
                    'kind'  => self::getSectionKind($placeholder),
                    'value' => $sections[$placeholder],
                ];
            }
            $steps[$step] = $rows;
        }

        return [
            'client'          => $sections['CLIENT'],
            'author'          => $dex !== null ? getUserName((int) $dex->fields['author_users_id']) : '',
            'dex_date'        => $dex?->fields['dex_date'] ?? '',
            'os'              => DatOsTemplate::getSystems()[(string) ($dex?->fields['os'] ?? '')] ?? '',
            'version'         => ($dex !== null ? (int) $dex->fields['version'] : 0) + 1,
            'servers'         => $dex !== null ? DexServer::getForDex($dex->getID()) : [],
            'section_steps'   => $steps,
            'step_labels'     => self::getSteps(),
            'template_ready'  => PresaleTemplate::isUploaded(PresaleTemplate::DEX),
            'config_url'      => Config::canUpdate() ? DexConfig::getConfigUrl() : '',
            'can_generate'    => $dex !== null && $dex->can($dex->getID(), UPDATE),
            'document'        => $dex !== null ? self::getDocument($dex) : null,
        ];
    }

    /**
     * Last generated version of the DEX, null when none or no longer readable
     *
     * @return array{name: string, url: string, version: string}|null
     */
    private static function getDocument(Dex $dex): ?array
    {
        global $CFG_GLPI;

        $document = new \Document();
        $documents_id = (int) $dex->fields['documents_id'];
        if ($documents_id <= 0 || !$document->getFromDB($documents_id) || !$document->can($documents_id, READ)) {
            return null;
        }

        return [
            'name'    => (string) $document->fields['name'],
            'url'     => $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query([
                'docid'    => $documents_id,
                'itemtype' => \Entity::class,
                'items_id' => (int) $dex->fields['entities_id'],
            ]),
            'version' => DatGenerator::versionLabel((int) $dex->fields['version']),
        ];
    }

    // -------------------------------------------------------------------------
    // DEX card of the customer sheet
    // -------------------------------------------------------------------------

    /**
     * Generated versions of the DEX of a client, latest first: the GLPI documents linked to the
     * entity and named by DexGenerator. No history table: a version deleted from the documents
     * of the entity leaves the list.
     *
     * @return list<array{name: string, url: string, date: string}>
     */
    private static function getVersions(int $entities_id): array
    {
        global $CFG_GLPI, $DB;

        $iterator = $DB->request([
            'SELECT'     => ['glpi_documents.id', 'glpi_documents.name', 'glpi_documents.date_creation'],
            'FROM'       => \Document::getTable(),
            'INNER JOIN' => [
                \Document_Item::getTable() => [
                    'ON' => [
                        \Document_Item::getTable() => 'documents_id',
                        \Document::getTable()      => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                'glpi_documents.is_deleted'      => 0,
                'glpi_documents.name'            => ['LIKE', 'DEX - %'],
                'glpi_documents_items.itemtype'  => \Entity::class,
                'glpi_documents_items.items_id'  => $entities_id,
            ],
            'ORDERBY'    => ['glpi_documents.date_creation DESC', 'glpi_documents.id DESC'],
        ]);

        $versions = [];
        $document = new \Document();
        foreach ($iterator as $row) {
            if (!$document->can((int) $row['id'], READ)) {
                continue;
            }
            $versions[] = [
                'name' => (string) $row['name'],
                'url'  => $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query([
                    'docid'    => (int) $row['id'],
                    'itemtype' => \Entity::class,
                    'items_id' => $entities_id,
                ]),
                'date' => (string) $row['date_creation'],
            ];
        }

        return $versions;
    }

    /**
     * Data of the DEX card of the customer sheet (entity/dex_card.html.twig), null when the user
     * has no DEX right or the client cannot get one (root entity)
     *
     * @return array<string, mixed>|null
     */
    public static function getCardData(int $entities_id): ?array
    {
        if ($entities_id <= 0 || !Session::haveRight(Dex::$rightname, READ)) {
            return null;
        }

        $dex        = Dex::getForEntity($entities_id);
        $input      = ['entities_id' => $entities_id];
        $can_create = $dex === null && (new Dex())->can(-1, CREATE, $input);
        $can_update = $dex !== null && $dex->can($dex->getID(), UPDATE);
        if ($dex !== null && !$dex->can($dex->getID(), READ)) {
            return null;
        }

        $reached = $dex !== null ? (int) $dex->fields['step'] : 0;

        return [
            'exists'      => $dex !== null,
            'status'      => $dex !== null ? Dex::getStatuses()[(int) $dex->fields['status']] ?? '' : '',
            'is_draft'    => $dex !== null && (int) $dex->fields['status'] === Dex::STATUS_DRAFT,
            'version'     => $dex !== null ? (int) $dex->fields['version'] : 0,
            'date_mod'    => $dex?->fields['date_mod'] ?? '',
            'versions'    => self::getVersions($entities_id),
            'can_create'  => $can_create,
            'can_update'  => $can_update,
            // A draft never generated can be dropped (DexWizard::deleteDraft())
            'can_delete'  => self::canDeleteDraft($dex),
            // A draft of the next version goes back to the last generation (DexWizard::discardDraft())
            'can_discard' => self::canDiscardDraft($dex),
            'form_url'    => self::getFormUrl(),
            'entities_id' => $entities_id,
            'create_url'  => self::getStepUrl($entities_id, self::STEP_GENERAL),
            // Resumes the draft where it was left; a generated DEX reopens on its summary
            'resume_url'  => self::getStepUrl($entities_id, min($reached + 1, self::LAST_STEP)),
            // A new version starts again from the first step, every section kept
            'new_url'     => self::getStepUrl($entities_id, self::STEP_GENERAL),
            'summary_url' => self::getStepUrl($entities_id, self::STEP_SUMMARY),
        ];
    }

    // -------------------------------------------------------------------------
    // Saving
    // -------------------------------------------------------------------------

    /**
     * Saves the posted step, then goes to the next one (or the previous one: "Previous" saves
     * too, so that nothing typed is lost)
     */
    public static function handlePost(array $input): never
    {
        $entities_id = (int) ($input['entities_id'] ?? 0);
        $step        = (int) ($input['step'] ?? self::STEP_GENERAL);
        self::loadEntity($entities_id);

        if (isset($input['generate'])) {
            self::generate($entities_id);
        }
        if (isset($input['delete_draft'])) {
            self::deleteDraft($entities_id);
        }
        if (isset($input['discard_draft'])) {
            self::handleDiscardDraft($entities_id);
        }

        if ($step === self::STEP_GENERAL) {
            $result = self::saveGeneral($entities_id, $input);
        } else {
            $dex = self::loadDex($entities_id, UPDATE);
            if ($dex === null) {
                // The DEX is created by the first step
                Html::redirect(self::getStepUrl($entities_id, self::STEP_GENERAL));
            }
            $result = match (true) {
                $step === self::STEP_SYSTEM           => self::saveSystem($dex, $input),
                isset(self::getStepSections()[$step]) => self::saveSections($dex, $step, $input),
                default                               => throw new AccessDeniedHttpException(),
            };
        }

        if ($result['errors'] !== []) {
            self::keepFailedPost($step, $result['errors'], $result['values']);
            Html::redirect(self::getStepUrl($entities_id, $step));
        }

        // Saved first, then the sections of the step replaced by the model: back to the step
        if (isset($input['apply_model']) && isset($dex)) {
            $dex->getFromDB($dex->getID());
            self::applyModel($dex, $step);
            Html::redirect(self::getStepUrl($entities_id, $step));
        }

        $next = isset($input['previous']) ? max(self::STEP_GENERAL, $step - 1) : min($step + 1, self::LAST_STEP);
        Html::redirect(self::getStepUrl($entities_id, $next));
    }

    /**
     * A draft never generated is removed with its servers, then back to the customer sheet.
     * Once generated, a DEX is kept: its sections feed the next version.
     */
    public static function canDeleteDraft(?Dex $dex): bool
    {
        return $dex !== null
            && (int) $dex->fields['version'] === 0
            && (int) $dex->fields['status'] === Dex::STATUS_DRAFT
            && $dex->can($dex->getID(), PURGE);
    }

    private static function deleteDraft(int $entities_id): never
    {
        $dex = self::loadDex($entities_id, READ);
        if (!self::canDeleteDraft($dex)) {
            throw new AccessDeniedHttpException();
        }
        if ($dex->delete(['id' => $dex->getID()], true)) {
            Session::addMessageAfterRedirect(__('The draft of the operating document has been deleted.', 'manageentities'));
        }
        Html::redirect(\Entity::getFormURLWithID($entities_id));
    }

    /**
     * A draft of the next version of a generated DEX can be discarded: the DEX goes back to its
     * last generation, from the snapshot taken then (none for a DEX generated before the snapshot
     * existed). Same right as the deletion of a draft never generated: the purge.
     */
    public static function canDiscardDraft(?Dex $dex): bool
    {
        return $dex !== null
            && (int) $dex->fields['version'] > 0
            && (int) $dex->fields['status'] === Dex::STATUS_DRAFT
            && $dex->getGeneratedSnapshot() !== null
            && $dex->can($dex->getID(), PURGE);
    }

    /**
     * Discards the draft (see canDiscardDraft())
     */
    public static function discardDraft(Dex $dex): bool
    {
        if (!self::canDiscardDraft($dex)) {
            throw new AccessDeniedHttpException();
        }

        return $dex->restoreGeneratedSnapshot(self::STEP_SUMMARY);
    }

    private static function handleDiscardDraft(int $entities_id): never
    {
        $dex = self::loadDex($entities_id, READ);
        if ($dex === null) {
            throw new AccessDeniedHttpException();
        }
        if (self::discardDraft($dex)) {
            Session::addMessageAfterRedirect(__('The changes of the draft have been discarded: the last generated version is restored.', 'manageentities'));
        }
        Html::redirect(\Entity::getFormURLWithID($entities_id));
    }

    /**
     * Generates a new version of the Word document, then comes back to the summary
     */
    private static function generate(int $entities_id): never
    {
        $dex = self::loadDex($entities_id, UPDATE);
        // Every step must have been saved at least once
        if ($dex === null || (int) $dex->fields['step'] < self::STEP_OPERATIONS) {
            throw new AccessDeniedHttpException();
        }

        if (!PresaleTemplate::isUploaded(PresaleTemplate::DEX)) {
            Session::addMessageAfterRedirect(
                __('No Word template uploaded in the configuration (DEX tab): the document cannot be generated.', 'manageentities'),
                false,
                ERROR,
            );
            Html::redirect(self::getStepUrl($entities_id, self::STEP_SUMMARY));
        }

        try {
            (new DexGenerator($dex))->generate();
            Session::addMessageAfterRedirect(__('The operating document has been generated.', 'manageentities'));
        } catch (\Throwable $e) {
            \Toolbox::logInFile('manageentities', 'Generation of the DEX ' . $dex->getID() . ' failed: ' . $e->getMessage() . "\n");
            Session::addMessageAfterRedirect(
                __('The generation of the operating document failed. Check the Word template of the configuration.', 'manageentities'),
                false,
                ERROR,
            );
        }
        Html::redirect(self::getStepUrl($entities_id, self::STEP_SUMMARY));
    }

    /**
     * Step 1: creates the DEX of the client (its servers and system taken over from the DAT of
     * the client), or updates it
     *
     * @return array{errors: array<string, string>, values: array}
     */
    public static function saveGeneral(int $entities_id, array $input): array
    {
        $values = [
            'client'          => trim((string) ($input['client'] ?? '')),
            'author_users_id' => (int) ($input['author_users_id'] ?? 0),
            'dex_date'        => trim((string) ($input['dex_date'] ?? '')),
            'os'              => (string) ($input['os'] ?? ''),
        ];

        $errors = [];
        if ($values['client'] === '') {
            $errors['client'] = sprintf(__('%s is mandatory', 'manageentities'), __('Client', 'manageentities'));
        }
        if ($values['author_users_id'] <= 0 || !(new User())->getFromDB($values['author_users_id'])) {
            $errors['author_users_id'] = sprintf(__('%s is mandatory', 'manageentities'), __('Author', 'manageentities'));
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $values['dex_date']);
        if ($date === false || $date->format('Y-m-d') !== $values['dex_date']) {
            $errors['dex_date'] = sprintf(__('%s is mandatory', 'manageentities'), __('Date'));
        }
        // System of the servers of the DEX: its model prefills the sections of the next steps
        if (!isset(DatOsTemplate::getSystems()[$values['os']])) {
            $errors['os'] = sprintf(__('%s is mandatory', 'manageentities'), __('Operating system', 'manageentities'));
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'values' => $values];
        }

        $fields = [
            'author_users_id' => $values['author_users_id'],
            'dex_date'        => $values['dex_date'],
            'os'              => $values['os'],
            'sections'        => ['CLIENT' => $values['client']],
        ];

        $dex = self::loadDex($entities_id, UPDATE);
        if ($dex === null) {
            $dex = new Dex();
            $dat = Dat::getForEntity($entities_id);
            // The system information of the DAT is a default, the steps of the DEX may change it
            $system = $dat?->getSections()['SYSTEM_INFORMATIONS'] ?? '';
            if ($system !== '') {
                $fields['sections']['SYSTEM_INFORMATIONS'] = $system;
            }
            $saved = $dex->add($fields + [
                'entities_id' => $entities_id,
                'step'        => self::STEP_GENERAL,
                'status'      => Dex::STATUS_DRAFT,
            ]) !== false;
            if ($saved && $dat !== null) {
                self::copyServersFromDat($dex, $dat);
            }
        } else {
            $saved = self::updateDex($dex, self::STEP_GENERAL, $fields);
        }
        if (!$saved) {
            return ['errors' => ['client' => __('The operating document could not be saved.', 'manageentities')], 'values' => $values];
        }

        return ['errors' => [], 'values' => $values];
    }

    /**
     * A new DEX starts with the servers of the DAT of the client, in their order: copies, so that
     * the two documents can then differ
     */
    private static function copyServersFromDat(Dex $dex, Dat $dat): void
    {
        $server = new DexServer();
        foreach (DatServer::getForDat($dat->getID()) as $rank => $row) {
            $server->add([
                DexServer::$items_id => $dex->getID(),
                'rank'               => $rank,
                'name'               => (string) $row['name'],
                'internal_name'      => (string) $row['internal_name'],
                'ip'                 => (string) $row['ip'],
                'role'               => (string) $row['role'],
            ]);
        }
    }

    /**
     * Step 2: the servers, then the sections of the step
     *
     * @return array{errors: array<string, string>, values: array}
     */
    public static function saveSystem(Dex $dex, array $input): array
    {
        $servers = DatWizard::readServers($input);
        $errors  = DatWizard::validateServers($servers);
        if ($errors !== []) {
            return ['errors' => $errors, 'values' => ['servers' => $servers, 'sections' => (array) ($input['sections'] ?? [])]];
        }

        self::saveServers($dex, $servers);

        return self::saveSections($dex, self::STEP_SYSTEM, $input);
    }

    /**
     * Replaces the servers of the DEX with the posted ones: the posted ids are only trusted when
     * they belong to this DEX, the others become new servers
     *
     * @param list<array{id: int, name: string, internal_name: string, ip: string, role: string}> $servers
     */
    private static function saveServers(Dex $dex, array $servers): void
    {
        $existing = array_column(DexServer::getForDex($dex->getID()), null, 'id');
        $kept     = [];
        $server   = new DexServer();
        foreach ($servers as $rank => $row) {
            $fields = [
                DexServer::$items_id => $dex->getID(),
                'rank'               => $rank,
                'name'               => $row['name'],
                'internal_name'      => $row['internal_name'],
                'ip'                 => $row['ip'],
                'role'               => $row['role'],
            ];
            if (isset($existing[$row['id']])) {
                $server->update(['id' => $row['id']] + $fields);
                $kept[$row['id']] = true;
            } else {
                $server->add($fields);
            }
        }
        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) {
                $server->delete(['id' => $id], true);
            }
        }
    }

    /**
     * Steps of sections: only the placeholders of the step are taken from the post
     *
     * @return array{errors: array<string, string>, values: array}
     */
    public static function saveSections(Dex $dex, int $step, array $input): array
    {
        $posted   = (array) ($input['sections'] ?? []);
        $sections = [];
        foreach (self::getStepSections()[$step] ?? [] as $placeholder) {
            $value = (string) ($posted[$placeholder] ?? '');
            // Blocks of commands keep their lines as typed, without the trailing blank lines
            $sections[$placeholder] = self::getSectionKind($placeholder) === DatWizard::KIND_CODE
                ? rtrim(str_replace("\r\n", "\n", $value))
                : trim($value);
        }

        if (!self::updateDex($dex, $step, ['sections' => $sections])) {
            return ['errors' => ['sections' => __('The operating document could not be saved.', 'manageentities')], 'values' => ['sections' => $posted]];
        }

        return ['errors' => [], 'values' => ['sections' => $sections]];
    }
}
