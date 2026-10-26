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
 * Wizard of the technical architecture document (DAT) of a client: one step per section of the
 * document, each one saved in the draft (Dat, DatServer) so that it can be resumed later. The
 * DAT being unique per client, the wizard is addressed by the entity, not by the DAT id.
 *
 * The steps are plain forms posted to front/dat.form.php (handlePost()), then redirected: the
 * errors and values of a failed post are kept in session for the step to show them again.
 */
class DatWizard
{
    public const STEP_GENERAL  = 1;
    public const STEP_SYSTEM   = 2;
    public const STEP_WEB      = 3;
    public const STEP_PHP      = 4;
    public const STEP_MYSQL    = 5;
    public const STEP_SERVICES = 6;
    public const STEP_SSO      = 7;
    public const STEP_SUMMARY  = 8;

    public const LAST_STEP = self::STEP_SUMMARY;

    private const SESSION_KEY = 'manageentities_dat_wizard';

    /** Section kinds: rich text, block of commands, plain text */
    public const KIND_RICHTEXT = 'richtext';
    public const KIND_CODE     = 'code';
    public const KIND_TEXT     = 'text';

    /**
     * @return array<int, string>
     */
    public static function getSteps(): array
    {
        return [
            self::STEP_GENERAL  => __('General information', 'manageentities'),
            self::STEP_SYSTEM   => __('System', 'manageentities'),
            self::STEP_WEB      => __('Web server', 'manageentities'),
            self::STEP_PHP      => 'PHP',
            self::STEP_MYSQL    => 'MySQL / MariaDB',
            self::STEP_SERVICES => __('Tasks, logs, HTTPS, NTP, files', 'manageentities'),
            self::STEP_SSO      => 'SSO',
            self::STEP_SUMMARY  => __('Summary', 'manageentities'),
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
            self::STEP_SYSTEM   => ['SYSTEM_INFORMATIONS', 'PAQUETS_INSTALLATION'],
            self::STEP_WEB      => ['APACHE_INFORMATIONS', 'APACHE_INSTALLATION'],
            self::STEP_PHP      => ['PHP_INFORMATIONS', 'PHP_INSTALLATION'],
            self::STEP_MYSQL    => ['MYSQL_INFORMATIONS', 'MYSQL_INSTALLATION'],
            self::STEP_SERVICES => ['CRON_INSTALLATION', 'LOGS_INSTALLATION', 'HTTPS_INSTALLATION', 'NTP_INSTALLATION', 'FILES_INSTALLATION'],
            self::STEP_SSO      => ['SSO_TECHNOLOGY', 'SSO_INSTALLATION'],
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
            'SYSTEM_INFORMATIONS'  => __('System information', 'manageentities'),
            'PAQUETS_INSTALLATION' => __('Installed packages', 'manageentities'),
            'APACHE_INFORMATIONS'  => __('Web server information', 'manageentities'),
            'APACHE_INSTALLATION'  => __('Web server installation', 'manageentities'),
            'PHP_INFORMATIONS'     => __('PHP information', 'manageentities'),
            'PHP_INSTALLATION'     => __('PHP installation', 'manageentities'),
            'MYSQL_INFORMATIONS'   => __('Database information', 'manageentities'),
            'MYSQL_INSTALLATION'   => __('Database installation', 'manageentities'),
            'CRON_INSTALLATION'    => __('System tasks', 'manageentities'),
            'LOGS_INSTALLATION'    => __('Logs', 'manageentities'),
            'HTTPS_INSTALLATION'   => __('HTTPS certificate', 'manageentities'),
            'NTP_INSTALLATION'     => 'NTP',
            'FILES_INSTALLATION'   => __('File modifications', 'manageentities'),
            'SSO_TECHNOLOGY'       => __('SSO technology', 'manageentities'),
            'SSO_INSTALLATION'     => __('SSO installation', 'manageentities'),
        ];
    }

    /**
     * Kind of a section, from its placeholder: *_INFORMATIONS is rich text, *_INSTALLATION a
     * block of commands (each line a line of the "Code" paragraph of the template)
     */
    public static function getSectionKind(string $placeholder): string
    {
        return match (true) {
            str_ends_with($placeholder, '_INFORMATIONS') => self::KIND_RICHTEXT,
            str_ends_with($placeholder, '_INSTALLATION') => self::KIND_CODE,
            default                                      => self::KIND_TEXT,
        };
    }

    public static function getFormUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/dat.form.php';
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
        return Session::haveRightsOr(Dat::$rightname, [CREATE, UPDATE]);
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
     * DAT of the client, its right checked; null when it does not exist yet, if the user may
     * create it
     */
    private static function loadDat(int $entities_id, int $right): ?Dat
    {
        $dat = Dat::getForEntity($entities_id);
        if ($dat === null) {
            $input = ['entities_id' => $entities_id];
            if (!(new Dat())->can(-1, CREATE, $input)) {
                throw new AccessDeniedHttpException();
            }
            return null;
        }
        if (!$dat->can($dat->getID(), $right)) {
            throw new AccessDeniedHttpException();
        }
        return $dat;
    }

    /**
     * Saving a step of a generated DAT makes it a draft again until the next generation
     */
    private static function updateDat(Dat $dat, int $step, array $input = []): bool
    {
        $input['id']     = $dat->getID();
        $input['step']   = max((int) $dat->fields['step'], $step);
        $input['status'] = Dat::STATUS_DRAFT;

        return $dat->update($input);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public static function renderStep(int $entities_id, int $step): void
    {
        $entity = self::loadEntity($entities_id);
        $dat    = self::loadDat($entities_id, READ);

        // Steps are filled in order: no jump beyond the step following the last saved one
        $reached = $dat !== null ? (int) $dat->fields['step'] : 0;
        $step    = max(self::STEP_GENERAL, min($step, $reached + 1, self::LAST_STEP));

        $failed = self::pullFailedPost($step);
        $values = $failed['values'];
        $vars   = match ($step) {
            self::STEP_GENERAL => self::buildGeneralVars($entity, $dat, $values),
            self::STEP_SUMMARY => self::buildSummaryVars($dat),
            default            => self::buildSectionsVars($dat, $step, $values),
        };

        $templates = [
            self::STEP_GENERAL => 'step_general',
            self::STEP_SYSTEM  => 'step_system',
            self::STEP_SUMMARY => 'step_summary',
        ];

        TemplateRenderer::getInstance()->display('@manageentities/dat/layout.html.twig', [
            'steps'         => self::getSteps(),
            'step'          => $step,
            'last_step'     => self::LAST_STEP,
            'reached'       => $reached,
            'entities_id'   => $entities_id,
            'client_name'   => $entity->fields['name'],
            'dat'           => $dat?->fields,
            'statuses'      => Dat::getStatuses(),
            'form_url'      => self::getFormUrl(),
            'step_url'      => self::getFormUrl() . '?entities_id=' . $entities_id . '&step=',
            'cancel_url'    => \Entity::getFormURLWithID($entities_id),
            'errors'        => $failed['errors'],
            'step_template' => '@manageentities/dat/' . ($templates[$step] ?? 'step_sections') . '.html.twig',
            'step_vars'     => $vars,
        ]);
    }

    /**
     * Step 1: client, author, date and version. A new DAT is prefilled with the name of the
     * entity, the current user and the date of the day.
     */
    private static function buildGeneralVars(\Entity $entity, ?Dat $dat, ?array $values): array
    {
        $general = $values ?? ($dat !== null
            ? [
                'client'          => $dat->getSections()['CLIENT'],
                'author_users_id' => (int) $dat->fields['author_users_id'],
                'dat_date'        => (string) $dat->fields['dat_date'],
                'os'              => (string) $dat->fields['os'],
            ]
            : [
                'client'          => $entity->fields['name'],
                'author_users_id' => Session::getLoginUserID(),
                'dat_date'        => date('Y-m-d', strtotime($_SESSION['glpi_currenttime'])),
                'os'              => '',
            ]);

        return [
            'general'     => $general,
            'systems'     => DatOsTemplate::getSystems(),
            // The version is set by the generation: the next one is shown
            'version'     => ($dat !== null ? (int) $dat->fields['version'] : 0) + 1,
            'logo_url'    => self::getLogoUrl((int) $entity->getID()),
            'entities_id' => (int) $entity->getID(),
        ];
    }

    /**
     * Logo of the client (EntityLogo), printed in the header of the document; '' when none
     */
    public static function getLogoUrl(int $entities_id): string
    {
        $logo = (new EntityLogo())->find(['entities_id' => $entities_id], [], 1);
        if ($logo === []) {
            return '';
        }

        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/logo.send.php?docid=' . (int) reset($logo)['logos_id'];
    }

    /**
     * Steps of sections, and the servers of the System step
     */
    private static function buildSectionsVars(?Dat $dat, int $step, ?array $values): array
    {
        $stored   = $dat?->getSections() ?? array_fill_keys(Dat::SECTIONS, '');
        $labels   = self::getSectionLabels();
        $servers  = $dat !== null ? DatServer::getForDat($dat->getID()) : [];
        // Model of the system of the DAT (step 1): it fills the sections left empty
        $os       = (string) ($dat?->fields['os'] ?? '');
        $model    = DatOsTemplate::getSectionsFor($os);
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
     * DAT, after the step was saved
     */
    private static function applyModel(Dat $dat, int $step): void
    {
        $model = DatOsTemplate::getSectionsFor((string) $dat->fields['os']);
        if ($model === []) {
            return;
        }
        $sections = [];
        foreach (self::getStepSections()[$step] ?? [] as $placeholder) {
            $sections[$placeholder] = $model[$placeholder] ?? '';
        }
        self::updateDat($dat, $step, ['sections' => $sections]);
    }

    /**
     * Last step: what the document will hold, section by section
     */
    private static function buildSummaryVars(?Dat $dat): array
    {
        $sections = $dat?->getSections() ?? array_fill_keys(Dat::SECTIONS, '');
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
            'author'          => $dat !== null ? getUserName((int) $dat->fields['author_users_id']) : '',
            'dat_date'        => $dat?->fields['dat_date'] ?? '',
            'os'              => DatOsTemplate::getSystems()[(string) ($dat?->fields['os'] ?? '')] ?? '',
            'version'         => ($dat !== null ? (int) $dat->fields['version'] : 0) + 1,
            'servers'         => $dat !== null ? DatServer::getForDat($dat->getID()) : [],
            'section_steps'   => $steps,
            'step_labels'     => self::getSteps(),
            'template_ready'  => PresaleTemplate::isUploaded(PresaleTemplate::DAT),
            'config_url'      => Config::canUpdate() ? DatConfig::getConfigUrl() : '',
            'can_generate'    => $dat !== null && $dat->can($dat->getID(), UPDATE),
            'document'        => $dat !== null ? self::getDocument($dat) : null,
        ];
    }

    /**
     * Last generated version of the DAT, null when none or no longer readable
     *
     * @return array{name: string, url: string, version: string}|null
     */
    private static function getDocument(Dat $dat): ?array
    {
        global $CFG_GLPI;

        $document = new \Document();
        $documents_id = (int) $dat->fields['documents_id'];
        if ($documents_id <= 0 || !$document->getFromDB($documents_id) || !$document->can($documents_id, READ)) {
            return null;
        }

        return [
            'name'    => (string) $document->fields['name'],
            'url'     => $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query([
                'docid'    => $documents_id,
                'itemtype' => \Entity::class,
                'items_id' => (int) $dat->fields['entities_id'],
            ]),
            'version' => DatGenerator::versionLabel((int) $dat->fields['version']),
        ];
    }

    // -------------------------------------------------------------------------
    // DAT card of the customer sheet
    // -------------------------------------------------------------------------

    /**
     * Generated versions of the DAT of a client, latest first: the GLPI documents linked to the
     * entity and named by DatGenerator. No history table: a version deleted from the documents
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
                'glpi_documents.name'            => ['LIKE', 'DAT - %'],
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
     * Data of the DAT card of the customer sheet (entity/dat_card.html.twig), null when the user
     * has no DAT right or the client cannot get one (root entity)
     *
     * @return array<string, mixed>|null
     */
    public static function getCardData(int $entities_id): ?array
    {
        if ($entities_id <= 0 || !Session::haveRight(Dat::$rightname, READ)) {
            return null;
        }

        $dat        = Dat::getForEntity($entities_id);
        $input      = ['entities_id' => $entities_id];
        $can_create = $dat === null && (new Dat())->can(-1, CREATE, $input);
        $can_update = $dat !== null && $dat->can($dat->getID(), UPDATE);
        if ($dat !== null && !$dat->can($dat->getID(), READ)) {
            return null;
        }

        $reached = $dat !== null ? (int) $dat->fields['step'] : 0;

        return [
            'exists'      => $dat !== null,
            'status'      => $dat !== null ? Dat::getStatuses()[(int) $dat->fields['status']] ?? '' : '',
            'is_draft'    => $dat !== null && (int) $dat->fields['status'] === Dat::STATUS_DRAFT,
            'version'     => $dat !== null ? (int) $dat->fields['version'] : 0,
            'date_mod'    => $dat?->fields['date_mod'] ?? '',
            'versions'    => self::getVersions($entities_id),
            'can_create'  => $can_create,
            'can_update'  => $can_update,
            // A draft never generated can be dropped (DatWizard::deleteDraft())
            'can_delete'  => self::canDeleteDraft($dat),
            // A draft of the next version goes back to the last generation (DatWizard::discardDraft())
            'can_discard' => self::canDiscardDraft($dat),
            'form_url'    => self::getFormUrl(),
            'entities_id' => $entities_id,
            'create_url'  => self::getStepUrl($entities_id, self::STEP_GENERAL),
            // Resumes the draft where it was left; a generated DAT reopens on its summary
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
            $dat = self::loadDat($entities_id, UPDATE);
            if ($dat === null) {
                // The DAT is created by the first step
                Html::redirect(self::getStepUrl($entities_id, self::STEP_GENERAL));
            }
            $result = match (true) {
                $step === self::STEP_SYSTEM                     => self::saveSystem($dat, $input),
                isset(self::getStepSections()[$step])           => self::saveSections($dat, $step, $input),
                default                                         => throw new AccessDeniedHttpException(),
            };
        }

        if ($result['errors'] !== []) {
            self::keepFailedPost($step, $result['errors'], $result['values']);
            Html::redirect(self::getStepUrl($entities_id, $step));
        }

        // Saved first, then the sections of the step replaced by the model: back to the step
        if (isset($input['apply_model']) && isset($dat)) {
            $dat->getFromDB($dat->getID());
            self::applyModel($dat, $step);
            Html::redirect(self::getStepUrl($entities_id, $step));
        }

        $next = isset($input['previous']) ? max(self::STEP_GENERAL, $step - 1) : min($step + 1, self::LAST_STEP);
        Html::redirect(self::getStepUrl($entities_id, $next));
    }

    /**
     * A draft never generated is removed with its servers, then back to the customer sheet.
     * Once generated, a DAT is kept: its sections feed the next version.
     */
    public static function canDeleteDraft(?Dat $dat): bool
    {
        return $dat !== null
            && (int) $dat->fields['version'] === 0
            && (int) $dat->fields['status'] === Dat::STATUS_DRAFT
            && $dat->can($dat->getID(), PURGE);
    }

    private static function deleteDraft(int $entities_id): never
    {
        $dat = self::loadDat($entities_id, READ);
        if (!self::canDeleteDraft($dat)) {
            throw new AccessDeniedHttpException();
        }
        if ($dat->delete(['id' => $dat->getID()], true)) {
            Session::addMessageAfterRedirect(__('The draft of the technical architecture document has been deleted.', 'manageentities'));
        }
        Html::redirect(\Entity::getFormURLWithID($entities_id));
    }

    /**
     * A draft of the next version of a generated DAT can be discarded: the DAT goes back to its
     * last generation, from the snapshot taken then (none for a DAT generated before the snapshot
     * existed). Same right as the deletion of a draft never generated: the purge.
     */
    public static function canDiscardDraft(?Dat $dat): bool
    {
        return $dat !== null
            && (int) $dat->fields['version'] > 0
            && (int) $dat->fields['status'] === Dat::STATUS_DRAFT
            && $dat->getGeneratedSnapshot() !== null
            && $dat->can($dat->getID(), PURGE);
    }

    /**
     * Discards the draft (see canDiscardDraft())
     */
    public static function discardDraft(Dat $dat): bool
    {
        if (!self::canDiscardDraft($dat)) {
            throw new AccessDeniedHttpException();
        }

        return $dat->restoreGeneratedSnapshot(self::STEP_SUMMARY);
    }

    private static function handleDiscardDraft(int $entities_id): never
    {
        $dat = self::loadDat($entities_id, READ);
        if ($dat === null) {
            throw new AccessDeniedHttpException();
        }
        if (self::discardDraft($dat)) {
            Session::addMessageAfterRedirect(__('The changes of the draft have been discarded: the last generated version is restored.', 'manageentities'));
        }
        Html::redirect(\Entity::getFormURLWithID($entities_id));
    }

    /**
     * Generates a new version of the Word document, then comes back to the summary
     */
    private static function generate(int $entities_id): never
    {
        $dat = self::loadDat($entities_id, UPDATE);
        // Every step must have been saved at least once
        if ($dat === null || (int) $dat->fields['step'] < self::STEP_SSO) {
            throw new AccessDeniedHttpException();
        }

        if (!PresaleTemplate::isUploaded(PresaleTemplate::DAT)) {
            Session::addMessageAfterRedirect(
                __('No Word template uploaded in the configuration (DAT tab): the document cannot be generated.', 'manageentities'),
                false,
                ERROR,
            );
            Html::redirect(self::getStepUrl($entities_id, self::STEP_SUMMARY));
        }

        try {
            (new DatGenerator($dat))->generate();
            Session::addMessageAfterRedirect(__('The technical architecture document has been generated.', 'manageentities'));
        } catch (\Throwable $e) {
            \Toolbox::logInFile('manageentities', 'Generation of the DAT ' . $dat->getID() . ' failed: ' . $e->getMessage() . "
");
            Session::addMessageAfterRedirect(
                __('The generation of the technical architecture document failed. Check the Word template of the configuration.', 'manageentities'),
                false,
                ERROR,
            );
        }
        Html::redirect(self::getStepUrl($entities_id, self::STEP_SUMMARY));
    }

    /**
     * Step 1: creates the DAT of the client, or updates it
     *
     * @return array{errors: array<string, string>, values: array}
     */
    public static function saveGeneral(int $entities_id, array $input): array
    {
        $values = [
            'client'          => trim((string) ($input['client'] ?? '')),
            'author_users_id' => (int) ($input['author_users_id'] ?? 0),
            'dat_date'        => trim((string) ($input['dat_date'] ?? '')),
            'os'              => (string) ($input['os'] ?? ''),
        ];

        $errors = [];
        if ($values['client'] === '') {
            $errors['client'] = sprintf(__('%s is mandatory', 'manageentities'), __('Client', 'manageentities'));
        }
        if ($values['author_users_id'] <= 0 || !(new User())->getFromDB($values['author_users_id'])) {
            $errors['author_users_id'] = sprintf(__('%s is mandatory', 'manageentities'), __('Author', 'manageentities'));
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $values['dat_date']);
        if ($date === false || $date->format('Y-m-d') !== $values['dat_date']) {
            $errors['dat_date'] = sprintf(__('%s is mandatory', 'manageentities'), __('Date'));
        }
        // System of every server of the DAT: its model prefills the sections of the next steps
        if (!isset(DatOsTemplate::getSystems()[$values['os']])) {
            $errors['os'] = sprintf(__('%s is mandatory', 'manageentities'), __('Operating system', 'manageentities'));
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'values' => $values];
        }

        $fields = [
            'author_users_id' => $values['author_users_id'],
            'dat_date'        => $values['dat_date'],
            'os'              => $values['os'],
            'sections'        => ['CLIENT' => $values['client']],
        ];

        $dat = self::loadDat($entities_id, UPDATE);
        if ($dat === null) {
            $saved = (new Dat())->add($fields + [
                'entities_id' => $entities_id,
                'step'        => self::STEP_GENERAL,
                'status'      => Dat::STATUS_DRAFT,
            ]) !== false;
        } else {
            $saved = self::updateDat($dat, self::STEP_GENERAL, $fields);
        }
        if (!$saved) {
            return ['errors' => ['client' => __('The technical architecture document could not be saved.', 'manageentities')], 'values' => $values];
        }

        return ['errors' => [], 'values' => $values];
    }

    /**
     * Step 2: the servers, then the sections of the step
     *
     * @return array{errors: array<string, string>, values: array}
     */
    public static function saveSystem(Dat $dat, array $input): array
    {
        $servers = self::readServers($input);
        $errors  = self::validateServers($servers);
        if ($errors !== []) {
            return ['errors' => $errors, 'values' => ['servers' => $servers, 'sections' => (array) ($input['sections'] ?? [])]];
        }

        self::saveServers($dat, $servers);

        return self::saveSections($dat, self::STEP_SYSTEM, $input);
    }

    /**
     * Server rows posted under servers[], in their order; the fully empty ones are dropped
     *
     * @return list<array{id: int, name: string, internal_name: string, ip: string, role: string}>
     */
    public static function readServers(array $input): array
    {
        $servers = [];
        foreach ((array) ($input['servers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $server = [
                'id'            => (int) ($row['id'] ?? 0),
                'name'          => trim((string) ($row['name'] ?? '')),
                'internal_name' => trim((string) ($row['internal_name'] ?? '')),
                'ip'            => trim((string) ($row['ip'] ?? '')),
                'role'          => trim((string) ($row['role'] ?? '')),
            ];
            if ($server['name'] === '' && $server['internal_name'] === '' && $server['ip'] === '' && $server['role'] === '') {
                continue;
            }
            $servers[] = $server;
        }

        return $servers;
    }

    /**
     * At least one server, each one with a DNS name, an internal name and a valid IP address
     *
     * @param list<array{id: int, name: string, internal_name: string, ip: string, role: string}> $servers
     *
     * @return array<string, string>
     */
    public static function validateServers(array $servers): array
    {
        if ($servers === []) {
            return ['servers' => __('Add at least one server.', 'manageentities')];
        }

        $errors = [];
        foreach ($servers as $index => $server) {
            $number = $index + 1;
            if (filter_var($server['name'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $errors["servers.$index.name"] = sprintf(__('Server %d: the DNS name is not valid.', 'manageentities'), $number);
            }
            if (filter_var($server['internal_name'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $errors["servers.$index.internal_name"] = sprintf(__('Server %d: the internal name is not valid.', 'manageentities'), $number);
            }
            if (filter_var($server['ip'], FILTER_VALIDATE_IP) === false) {
                $errors["servers.$index.ip"] = sprintf(__('Server %d: the IP address is not valid.', 'manageentities'), $number);
            }
        }

        return $errors;
    }

    /**
     * Replaces the servers of the DAT with the posted ones: the posted ids are only trusted when
     * they belong to this DAT, the others become new servers
     *
     * @param list<array{id: int, name: string, internal_name: string, ip: string, role: string}> $servers
     */
    private static function saveServers(Dat $dat, array $servers): void
    {
        $existing = array_column(DatServer::getForDat($dat->getID()), null, 'id');
        $kept     = [];
        $server   = new DatServer();
        foreach ($servers as $rank => $row) {
            $fields = [
                DatServer::$items_id => $dat->getID(),
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
    public static function saveSections(Dat $dat, int $step, array $input): array
    {
        $posted   = (array) ($input['sections'] ?? []);
        $sections = [];
        foreach (self::getStepSections()[$step] ?? [] as $placeholder) {
            $value = (string) ($posted[$placeholder] ?? '');
            // Blocks of commands keep their lines as typed, without the trailing blank lines
            $sections[$placeholder] = self::getSectionKind($placeholder) === self::KIND_CODE
                ? rtrim(str_replace("\r\n", "\n", $value))
                : trim($value);
        }

        if (!self::updateDat($dat, $step, ['sections' => $sections])) {
            return ['errors' => ['sections' => __('The technical architecture document could not be saved.', 'manageentities')], 'values' => ['sections' => $posted]];
        }

        return ['errors' => [], 'values' => ['sections' => $sections]];
    }
}
