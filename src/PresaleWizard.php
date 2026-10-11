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

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Manageentities\Presale\Docx\RichText;
use Html;
use Session;

/**
 * Pre-sales wizard (front/presale.form.php): builds a financial proposal step by step.
 *
 * Unlike the new customer wizard, everything is written to the database as soon as a step is
 * saved: step 1 creates the prospect and a draft proposal, the following steps update them.
 * A draft can therefore be resumed later from the list of the proposals. Plain HTML forms:
 * each step posts to the front controller, which saves and redirects to the next step, the
 * errors and the submitted values being kept in the session for the redisplay.
 *
 * The annexes come before the general information, which deduces the type of contract and the
 * internal label from them. The last step shows a summary and generates the Word document (PresaleGenerator); won and lost
 * proposals are then read-only.
 */
class PresaleWizard
{
    public const STEP_CLIENT   = 1;
    public const STEP_TEAM     = 2;
    public const STEP_ANNEXES  = 3;
    public const STEP_GENERAL  = 4;
    public const STEP_GENERATE = 5;

    /** Failed posts of the estimate screen of the project manager, kept apart from the steps */
    private const STEP_ESTIMATE = 100;

    /** Last step of the wizard */
    public const LAST_STEP = self::STEP_GENERATE;

    private const SESSION_KEY = 'manageentities_presale_wizard';

    /** Postal code of the POC: letters, digits, spaces and dashes, 2 to 10 characters */
    private const POSTCODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 \-]{1,9}$/';

    /**
     * @return array<int, string>
     */
    public static function getSteps(): array
    {
        return [
            self::STEP_CLIENT   => __('Client and contacts', 'manageentities'),
            self::STEP_TEAM     => __('Sales representative and project manager', 'manageentities'),
            self::STEP_ANNEXES  => _n('Annex', 'Annexes', 2, 'manageentities'),
            self::STEP_GENERAL  => __('Proposal', 'manageentities'),
            self::STEP_GENERATE => __('Generation', 'manageentities'),
        ];
    }

    public static function getFormUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presale.form.php';
    }

    /**
     * Whether the current user can open the pre-sales section
     */
    public static function canUse(): bool
    {
        return Config::usePresale()
            && Session::haveRightsOr(PresaleProposal::$rightname, [CREATE, UPDATE]);
    }

    /**
     * Entity the prospects and the proposals are created in: the customers entity
     */
    public static function getTargetEntity(): int
    {
        return (int) (Config::getInstance()->fields['wizard_default_entities_id'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Session (errors and submitted values of the last failed post)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array                 $values
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
     * Proposal edited by the wizard, its rights checked. Won and lost proposals are read-only.
     */
    private static function loadProposal(int $id, int $right = UPDATE): PresaleProposal
    {
        $proposal = new PresaleProposal();
        if (!$proposal->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        if (!$proposal->can($id, $right)) {
            throw new AccessDeniedHttpException();
        }
        return $proposal;
    }

    private static function isEditable(PresaleProposal $proposal): bool
    {
        return in_array(
            (int) $proposal->fields['status'],
            [PresaleProposal::STATUS_DRAFT, PresaleProposal::STATUS_GENERATED],
            true,
        );
    }

    private static function loadClient(PresaleProposal $proposal): PresaleClient
    {
        $client = new PresaleClient();
        if (!$client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])) {
            throw new NotFoundHttpException();
        }
        return $client;
    }

    /**
     * Saving a step of a generated proposal makes it a draft again until the next generation
     *
     * @param PresaleProposal $proposal
     * @param int             $step  step just saved
     * @param array           $input fields of the proposal to update
     */
    private static function updateProposal(PresaleProposal $proposal, int $step, array $input = []): bool
    {
        $input['id']     = $proposal->getID();
        $input['step']   = max((int) $proposal->fields['step'], $step);
        $input['status'] = PresaleProposal::STATUS_DRAFT;

        return $proposal->update($input);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Landing page of the section: new proposal, or resume one of the drafts
     */
    public static function renderHome(): void
    {
        $drafts = self::getOpenProposals([], 10);

        TemplateRenderer::getInstance()->display('@manageentities/presale/home.html.twig', [
            'new_url'        => self::getFormUrl() . '?step=' . self::STEP_CLIENT,
            'list_url'       => PresaleProposal::getSearchURL(),
            'clients_url'    => PresaleClient::getSearchURL(),
            'estimates'      => self::getEstimatesToDo(),
            'drafts'         => $drafts,
            'action_url'     => self::getActionUrl(),
            'can_update'     => PresaleProposal::canUpdate(),
            'can_purge'      => PresaleProposal::canPurge(),
            'nb_steps'       => count(self::getSteps()),
            'missing_entity' => self::getTargetEntity() <= 0,
        ]);
    }

    /**
     * Proposals in progress (draft or generated), last changed first, as listed by the landing
     * page, the form of a prospect and the pre-sales tab of the clients management
     *
     * @param array $criteria extra criteria on the proposals or their prospect
     * @param int   $limit    0 for all of them
     *
     * @return list<array{id: int, url: string, name: string, client: string, client_url: string,
     *                    status: string, step: int, date_mod: string, total_ht: float,
     *                    can_win: bool, modified: bool}>
     */
    public static function getOpenProposals(array $criteria = [], int $limit = 0): array
    {
        global $DB;

        $proposals = PresaleProposal::getTable();
        $clients   = PresaleClient::getTable();
        $request   = [
            'SELECT'    => [
                $proposals . '.id',
                $proposals . '.name',
                $proposals . '.status',
                $proposals . '.step',
                $proposals . '.version',
                $proposals . '.total_ht',
                $proposals . '.estimate_status',
                $proposals . '.date_mod',
                $clients . '.id AS clients_id',
                $clients . '.name AS client_name',
            ],
            'FROM'      => $proposals,
            'LEFT JOIN' => [
                $clients => [
                    'ON' => [
                        $clients   => 'id',
                        $proposals => 'plugin_manageentities_presaleclients_id',
                    ],
                ],
            ],
            'WHERE'     => [
                $proposals . '.status' => PresaleProposal::getOpenStatuses(),
            ] + $criteria + getEntitiesRestrictCriteria($proposals, '', '', true),
            'ORDERBY'   => [$proposals . '.date_mod DESC'],
        ];
        if ($limit > 0) {
            $request['LIMIT'] = $limit;
        }

        $list = [];
        foreach ($DB->request($request) as $data) {
            $list[] = [
                'url'        => self::getFormUrl() . '?' . http_build_query([
                    'id'   => $data['id'],
                    'step' => min(self::LAST_STEP, (int) $data['step'] + 1),
                ]),
                'name'       => $data['name'] ?: $data['client_name'],
                'client'     => (string) $data['client_name'],
                'client_url' => PresaleClient::getFormURLWithID((int) $data['clients_id']),
                'status'     => PresaleProposal::getStatuses()[(int) $data['status']] ?? '',
                'step'       => (int) $data['step'],
                'date_mod'   => (string) $data['date_mod'],
                'total_ht'   => (float) $data['total_ht'],
                'estimate_status' => (int) $data['estimate_status'],
                'estimate_url'    => self::getEstimateUrl((int) $data['id']),
                'id'         => (int) $data['id'],
                // Same rules as the last step: won once generated, the warning when changed since
                'can_win'    => (int) $data['version'] > 0,
                'modified'   => (int) $data['version'] > 0 && (int) $data['status'] === PresaleProposal::STATUS_DRAFT,
            ];
        }

        return $list;
    }

    /**
     * Number of proposals by status, in the active entities
     *
     * @param array $criteria extra criteria, on the proposals or their prospect
     *
     * @return array<int, int> {status: count}, every status present
     */
    public static function countProposalsByStatus(array $criteria = []): array
    {
        global $DB;

        $proposals = PresaleProposal::getTable();
        $clients   = PresaleClient::getTable();
        $counts    = array_fill_keys(array_keys(PresaleProposal::getStatuses()), 0);
        $iterator  = $DB->request([
            'SELECT'    => [$proposals . '.status', QueryFunction::count($proposals . '.id', false, 'cpt')],
            'FROM'      => $proposals,
            'LEFT JOIN' => [
                $clients => [
                    'ON' => [
                        $clients   => 'id',
                        $proposals => 'plugin_manageentities_presaleclients_id',
                    ],
                ],
            ],
            'WHERE'     => $criteria + getEntitiesRestrictCriteria($proposals, '', '', true),
            'GROUPBY'   => $proposals . '.status',
        ]);
        foreach ($iterator as $data) {
            $counts[(int) $data['status']] = (int) $data['cpt'];
        }

        return $counts;
    }

    /**
     * One step of the wizard. Without id, only step 1 (new prospect and proposal) is allowed.
     */
    /**
     * @param array $query $_GET: prospects_id or entities_id of a new proposal, prefilling step 1
     */
    public static function renderStep(int $id, int $step, array $query = []): void
    {
        if (self::getTargetEntity() <= 0) {
            Html::redirect(self::getFormUrl());
        }

        $proposal = null;
        if ($id > 0) {
            $proposal = self::loadProposal($id, READ);
            // Steps are filled in order: no jump beyond the step following the last saved one
            $step = max(self::STEP_CLIENT, min($step, (int) $proposal->fields['step'] + 1, self::LAST_STEP));
        } else {
            if (!PresaleProposal::canCreate()) {
                throw new AccessDeniedHttpException();
            }
            $step = self::STEP_CLIENT;
        }

        $failed = self::pullFailedPost($step);
        $values = $failed['values'];
        $vars   = match ($step) {
            // Client and contacts: one form, the contacts posted under contacts[]
            self::STEP_CLIENT   => self::buildClientStepVars($proposal, $values, $query),
            // Sales representative and project manager: one block each, posted under sales[] / pm[]
            self::STEP_TEAM     => [
                'sales' => self::buildPersonVars($proposal, PresaleUser::TYPE_SALES, 'sales', $values['sales'] ?? null),
                'pm'    => self::buildPersonVars($proposal, PresaleUser::TYPE_PROJECT_MANAGER, 'pm', $values['pm'] ?? null),
            ],
            self::STEP_ANNEXES  => self::buildAnnexesVars($proposal, $values),
            self::STEP_GENERATE => self::buildGenerateVars($proposal),
            default             => self::buildGeneralVars($proposal, $values),
        };

        $templates = [
            self::STEP_CLIENT   => 'step_client',
            self::STEP_TEAM     => 'step_team',
            self::STEP_ANNEXES  => 'step_annexes',
            self::STEP_GENERAL  => 'step_general',
            self::STEP_GENERATE => 'step_generate',
        ];

        TemplateRenderer::getInstance()->display('@manageentities/presale/layout.html.twig', [
            'steps'         => self::getSteps(),
            'step'          => $step,
            'last_step'     => self::LAST_STEP,
            'reached'       => $proposal !== null ? (int) $proposal->fields['step'] : 0,
            'proposal'      => $proposal?->fields,
            'client_name'   => $proposal !== null ? self::loadClient($proposal)->fields['name'] : '',
            'readonly'      => $proposal !== null && !self::isEditable($proposal),
            'form_url'      => self::getFormUrl(),
            'step_url'      => $proposal !== null
                ? self::getFormUrl() . '?id=' . $proposal->getID() . '&step='
                : '',
            'home_url'      => self::getFormUrl(),
            'proposal_url'  => $proposal !== null ? PresaleProposal::getFormURLWithID($proposal->getID()) : '',
            'action_url'    => self::getActionUrl(),
            'errors'        => $failed['errors'],
            'step_template' => '@manageentities/presale/' . $templates[$step] . '.html.twig',
            'step_vars'     => $vars,
        ]);
    }

    /**
     * @param PresaleProposal|null $proposal
     * @param array|null           $values submitted values of a failed post
     */
    /**
     * Step 1: the client and its contacts. A new proposal starts from a new prospect, a known
     * prospect or an existing customer entity: choosing one reloads the step with its details
     * and its contacts, which can still be changed.
     *
     * @param array      $query  prospects_id or entities_id chosen for a new proposal
     * @param array|null $values submitted values of a failed post
     */
    private static function buildClientStepVars(?PresaleProposal $proposal, ?array $values, array $query): array
    {
        global $CFG_GLPI;

        // Source of a new proposal: posted back after an error, else chosen in the lists
        $prospects_id = (int) ($values['prospects_id'] ?? $query['prospects_id'] ?? 0);
        $entities_id  = (int) ($values['customer_entities_id'] ?? $query['entities_id'] ?? 0);

        $client   = [];
        $contacts = [];
        $missing  = [];
        if ($proposal !== null) {
            $client   = self::loadClient($proposal)->fields;
            $contacts = self::getClientContacts((int) $client['id']);
        } elseif ($prospects_id > 0) {
            $prospect = self::getAvailableProspect($prospects_id);
            if ($prospect !== null) {
                $client   = $prospect->fields;
                $contacts = self::getClientContacts($prospects_id);
            } else {
                $prospects_id = 0;
            }
        } elseif ($entities_id > 0) {
            $source = self::getCustomerSource($entities_id);
            if ($source !== null) {
                [$client, $contacts, $missing] = $source;
                $prospects_id                  = (int) ($client['id'] ?? 0);
            } else {
                $entities_id = 0;
            }
        }

        // After an error, the posted values win
        if ($values !== null) {
            if (isset($values['contacts'])) {
                $contacts = array_values($values['contacts']);
                $default  = (int) ($values['is_default'] ?? -1);
                foreach ($contacts as $idx => &$contact) {
                    $contact['is_default'] = $idx === $default;
                }
                unset($contact);
            }
            $client = array_diff_key($values, ['contacts' => true, 'is_default' => true, 'plugin_manageentities_presaleorigins_id' => true]) + $client;
        }
        if ($contacts === []) {
            $contacts[] = ['is_default' => true];
        }

        $converted = (int) ($client['converted_entities_id'] ?? $entities_id);

        // Origin of the request: posted back, else the proposal's, else the default of an existing customer
        $origin = (int) ($values['plugin_manageentities_presaleorigins_id']
            ?? $proposal?->fields['plugin_manageentities_presaleorigins_id']
            ?? ($converted > 0 ? PresaleOrigin::getCustomerDefault() : 0));

        return [
            'origins'     => PresaleOrigin::getChoices($origin),
            'origin'      => $origin,
            'client'      => $client,
            'contacts'    => $contacts,
            // Lists of a new proposal only; + preserves the numeric keys
            'prospects'   => $proposal === null ? self::getProspectChoices() : [],
            'customers'   => $proposal === null ? self::getCustomerChoices() : [],
            'prospect'    => $prospects_id,
            'customer'    => $entities_id,
            // Details missing on the customer entity, to fill in again
            'missing'     => $missing,
            'entity_name' => $converted > 0 ? Dropdown::getDropdownName('glpi_entities', $converted) : '',
            'entity_url'  => $converted > 0 ? \Entity::getFormURLWithID($converted) : '',
            'step_url'    => self::getFormUrl() . '?step=' . self::STEP_CLIENT,
            // Served by the core with the document rights (logo.send.php only serves entity logos)
            'logo_url'    => !empty($client['logo_documents_id'])
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $client['logo_documents_id']
                : '',
        ];
    }

    /**
     * Prospects that can receive a new proposal: not converted into a customer, not archived
     *
     * @return array<int, string>
     */
    private static function getProspectChoices(): array
    {
        $choices = [];
        foreach ((new PresaleClient())->find([
            'converted_entities_id' => 0,
            'is_archived'           => 0,
        ] + getEntitiesRestrictCriteria(PresaleClient::getTable(), '', '', true), ['name']) as $data) {
            $choices[(int) $data['id']] = $data['name'];
        }

        return $choices;
    }

    /**
     * Customer entities: the children of the customers entity, the archived ones excluded
     *
     * @return array<int, string>
     */
    private static function getCustomerChoices(): array
    {
        $parent = self::getTargetEntity();
        if ($parent <= 0) {
            return [];
        }
        $archive  = (int) (Config::getInstance()->fields['wizard_archive_entities_id'] ?? 0);
        $excluded = $archive > 0 ? getSonsOf('glpi_entities', $archive) : [];
        // Whatever the active entity, as the prospects: the entities of the profiles of the user
        // granting the pre-sales right, not only the ones selected in the entity switcher
        $allowed = array_flip(array_map('intval', \Profile_User::getUserEntitiesForRight(
            Session::getLoginUserID(),
            PresaleProposal::$rightname,
            CREATE | UPDATE,
        )));

        $choices = [];
        foreach ((new \Entity())->find(['entities_id' => $parent], ['name']) as $data) {
            if (!isset($excluded[(int) $data['id']]) && isset($allowed[(int) $data['id']])) {
                $choices[(int) $data['id']] = $data['name'];
            }
        }

        return $choices;
    }

    /**
     * Prospect that can receive a new proposal, null when unknown or not available
     */
    private static function getAvailableProspect(int $prospects_id): ?PresaleClient
    {
        $client = new PresaleClient();
        if (
            !$client->getFromDB($prospects_id)
            || !$client->can($prospects_id, READ)
            || (int) $client->fields['converted_entities_id'] > 0
            || (int) $client->fields['is_archived'] === 1
        ) {
            return null;
        }

        return $client;
    }

    /**
     * Customer entity offered for a new proposal: in the list of getCustomerChoices()
     */
    private static function isCustomerEntity(int $entities_id): bool
    {
        return isset(self::getCustomerChoices()[$entities_id]);
    }

    /**
     * Details of a customer entity for step 1: its prospect when it already had proposals (the
     * empty fields completed by the entity), else the entity itself; contacts of the prospect,
     * else the ones linked to the entity. Also the fields still missing.
     *
     * @return array{0: array, 1: list<array>, 2: list<string>}|null null when not a customer entity
     */
    private static function getCustomerSource(int $entities_id): ?array
    {
        if (!self::isCustomerEntity($entities_id)) {
            return null;
        }
        $entity = new \Entity();
        $entity->getFromDB($entities_id);

        $fields  = ['name', 'address', 'postcode', 'town', 'state', 'country', 'phonenumber', 'email', 'website'];
        $client  = ['converted_entities_id' => $entities_id];
        $known   = self::getProspectOfEntity($entities_id);
        if ($known !== null) {
            $client = $known->fields;
        }
        foreach ($fields as $field) {
            if (trim((string) ($client[$field] ?? '')) === '') {
                $client[$field] = (string) ($entity->fields[$field] ?? '');
            }
        }
        // Logo of the entity (portal of the customer) when the prospect has none
        if ((int) ($client['logo_documents_id'] ?? 0) === 0) {
            $client['logo_documents_id'] = self::getEntityLogo($entities_id);
        }

        $contacts = $known !== null ? self::getClientContacts($known->getID()) : [];
        if ($contacts === []) {
            $contacts = self::getEntityContacts($entities_id);
        }

        $labels  = [
            'address'  => __('Address'),
            'postcode' => __('Postal code'),
            'town'     => __('City'),
        ];
        $missing = [];
        foreach ($labels as $field => $label) {
            if (trim((string) $client[$field]) === '') {
                $missing[] = $label;
            }
        }
        if ($contacts === []) {
            $missing[] = _n('Contact', 'Contacts', 2);
        }

        return [$client, $contacts, $missing];
    }

    /**
     * Logo of a customer entity (EntityLogo), 0 when none or not an image
     */
    private static function getEntityLogo(int $entities_id): int
    {
        $logo = (new EntityLogo())->find(['entities_id' => $entities_id], [], 1);
        $documents_id = (int) (reset($logo)['logos_id'] ?? 0);
        $document     = new \Document();
        if (
            $documents_id <= 0
            || !$document->getFromDB($documents_id)
            || !str_starts_with((string) $document->fields['mime'], 'image/')
        ) {
            return 0;
        }

        return $documents_id;
    }

    /**
     * Prospect already made from a customer entity (by a won proposal, or a previous proposal
     * made for that customer)
     */
    private static function getProspectOfEntity(int $entities_id): ?PresaleClient
    {
        $client = new PresaleClient();
        $rows   = $client->find(['converted_entities_id' => $entities_id], ['id DESC'], 1);
        if ($rows === [] || !$client->getFromDB((int) array_key_first($rows))) {
            return null;
        }

        return $client;
    }

    /**
     * Contacts of a prospect, main one first
     *
     * @return list<array>
     */
    private static function getClientContacts(int $clients_id): array
    {
        return array_values((new PresaleContact())->find([
            'plugin_manageentities_presaleclients_id' => $clients_id,
        ], ['is_default DESC', 'id']));
    }

    /**
     * Contacts linked to a customer entity (Contacts card of the entity), as new contacts of the
     * proposal: the main one of the entity stays the main one
     *
     * @return list<array>
     */
    private static function getEntityContacts(int $entities_id): array
    {
        $contacts = [];
        foreach ((new Contact())->buildContactsForTemplate([$entities_id], '') as $data) {
            $contact = new \Contact();
            if (!$contact->getFromDB((int) $data['link_id'])) {
                continue;
            }
            $contacts[] = [
                'id'          => 0,
                'name'        => (string) $contact->fields['name'],
                'firstname'   => (string) $contact->fields['firstname'],
                'role'        => $contact->fields['usertitles_id'] ?? 0
                    ? Dropdown::getDropdownName('glpi_usertitles', (int) $contact->fields['usertitles_id'])
                    : '',
                'email'       => (string) $contact->fields['email'],
                'phone'       => (string) $contact->fields['phone'],
                'mobile'      => (string) $contact->fields['mobile'],
                'is_default'  => $data['is_default'],
                'contacts_id' => $contact->getID(),
            ];
        }
        usort($contacts, static fn(array $a, array $b): int => (int) $b['is_default'] <=> (int) $a['is_default']);

        return $contacts;
    }

    /**
     * Sales representative or project manager (step 2): one of the declared users, its details
     * copied into the proposal and editable
     *
     * @param PresaleProposal|null $proposal
     * @param int                  $type   PresaleUser::TYPE_*
     * @param string               $prefix column prefix in the proposal (sales / pm)
     * @param array|null           $values
     */
    private static function buildPersonVars(?PresaleProposal $proposal, int $type, string $prefix, ?array $values): array
    {
        $users  = PresaleUser::getUsers($type);
        $fields = $proposal !== null ? $proposal->fields : [];

        $current = $values ?? [
            'users_id' => (int) ($fields[$prefix . '_users_id'] ?? 0),
            'name'     => $fields[$prefix . '_name'] ?? '',
            'role'     => $fields[$prefix . '_role'] ?? '',
            'email'    => $fields[$prefix . '_email'] ?? '',
            'phone'    => $fields[$prefix . '_phone'] ?? '',
        ];

        // Nothing chosen yet: the connected user when declared, else the default one
        if ($values === null && (int) $current['users_id'] === 0 && ($current['name'] ?? '') === '') {
            $pick = null;
            foreach ($users as $user) {
                if ($user['users_id'] === (int) Session::getLoginUserID()) {
                    $pick = $user;
                    break;
                }
            }
            if ($pick === null) {
                foreach ($users as $user) {
                    if ($user['is_default']) {
                        $pick = $user;
                        break;
                    }
                }
            }
            if ($pick !== null) {
                $current = self::personFromUser($pick, $type);
            }
        }

        // Details of each declared user, for the client side prefill when the choice changes
        $prefill = [];
        foreach ($users as $user) {
            $prefill[$user['users_id']] = self::personFromUser($user, $type);
        }

        return [
            'prefix'   => $prefix,
            'type'     => $type,
            'is_sales' => $type === PresaleUser::TYPE_SALES,
            'optional' => $type === PresaleUser::TYPE_PROJECT_MANAGER,
            // + keeps the user ids as keys
            'users'    => [0 => $type === PresaleUser::TYPE_PROJECT_MANAGER ? __('No project manager', 'manageentities') : Dropdown::EMPTY_VALUE]
                + array_column($users, 'name', 'users_id'),
            'prefill'  => $prefill,
            'person'   => $current,
        ];
    }

    /**
     * Details printed for a declared user
     *
     * @param array $user row of PresaleUser::getUsers()
     *
     * @return array{users_id: int, name: string, role: string, email: string, phone: string}
     */
    private static function personFromUser(array $user, int $type): array
    {
        return [
            'users_id' => $user['users_id'],
            'name'     => $user['name'],
            'role'     => $user['role_label'] !== '' ? $user['role_label'] : PresaleUser::getTypes()[$type],
            'email'    => $user['email'],
            'phone'    => $user['phone'],
        ];
    }

    private static function buildGeneralVars(?PresaleProposal $proposal, ?array $values): array
    {
        $fields = $proposal !== null ? $proposal->fields : [];

        return [
            'general' => $values ?? [
                'proposal_type' => $fields['proposal_type'] ?? '',
                'proposal_date' => $fields['proposal_date'] ?? date('Y-m-d'),
                'name'          => $fields['name'] ?? '',
                'summary'       => $fields['summary'] ?? '',
            ],
            'reference'  => $fields['reference'] ?? '',
            // What the annexes give, shown beside the fields to restore it
            'deduced'    => $proposal !== null ? self::getDeduced($proposal) : ['proposal_type' => '', 'name' => ''],
            'recipients' => $proposal !== null ? self::getContactChoices($proposal) : [],
            'recipient'  => (int) ($values['plugin_manageentities_presalecontacts_id'] ?? $fields['plugin_manageentities_presalecontacts_id'] ?? 0),
        ];
    }

    /**
     * Type of contract and internal label given by the annexes of the proposal
     *
     * @return array{proposal_type: string, name: string}
     */
    private static function getDeduced(PresaleProposal $proposal): array
    {
        $type = PresaleProposal_Annex::deduceContractType(PresaleProposal_Annex::namesOf(self::loadAnnexes($proposal)));

        return [
            'proposal_type' => $type,
            'name'          => PresaleProposal_Annex::deduceLabel((string) self::loadClient($proposal)->fields['name'], $type),
        ];
    }

    /**
     * @return array<int, string> contacts of the prospect, by id
     */
    private static function getContactChoices(PresaleProposal $proposal): array
    {
        $choices = [];
        foreach ((new PresaleContact())->find([
            'plugin_manageentities_presaleclients_id' => $proposal->fields['plugin_manageentities_presaleclients_id'],
        ], ['is_default DESC', 'name']) as $data) {
            $choices[(int) $data['id']] = trim($data['name'] . ' ' . $data['firstname'])
                . ($data['role'] ? ' — ' . $data['role'] : '');
        }
        return $choices;
    }

    // -------------------------------------------------------------------------
    // Saving (front controller, POST)
    // -------------------------------------------------------------------------

    /**
     * Save the posted step, then redirect to the next one or back to the same one on error
     *
     * @param array $input $_POST
     * @param array $files $_FILES
     */
    public static function handlePost(array $input, array $files): never
    {
        $id   = (int) ($input['id'] ?? 0);
        $step = (int) ($input['step'] ?? self::STEP_CLIENT);

        if ($step === self::STEP_ANNEXES && isset($input['add_annex'])) {
            self::addAnnex($id, $input);
        }
        // Request of the estimate: the annexes are saved first, as with "Next", without the
        // check of the days (the project manager sets them)
        if ($step === self::STEP_ANNEXES && isset($input['request_estimate'])) {
            $proposal = self::loadProposal($id);
            if (!self::isEditable($proposal)) {
                throw new AccessDeniedHttpException();
            }
            $result = self::saveAnnexes($proposal, ['_skip_days_check' => true] + $input);
            if ($result['errors'] !== []) {
                self::keepFailedPost($step, $result['errors'], $result['values']);
            } else {
                self::requestEstimate(self::loadProposal($id), $input);
            }
            Html::redirect(self::getFormUrl() . '?id=' . $id . '&step=' . self::STEP_ANNEXES);
        }

        if ($step === self::STEP_CLIENT) {
            $result = self::saveClient($id, $input, $files);
        } else {
            $proposal = self::loadProposal($id);
            if (!self::isEditable($proposal)) {
                throw new AccessDeniedHttpException();
            }
            $result = match ($step) {
                self::STEP_TEAM    => self::saveTeam($proposal, $input),
                self::STEP_GENERAL => self::saveGeneral($proposal, $input),
                self::STEP_ANNEXES => self::saveAnnexes($proposal, $input),
                default            => throw new AccessDeniedHttpException(),
            };
        }

        if ($result['errors'] !== []) {
            self::keepFailedPost($step, $result['errors'], $result['values']);
            Html::redirect(self::getFormUrl() . '?' . http_build_query(array_filter([
                'id'   => $result['id'] ?: $id,
                'step' => $step,
            ])));
        }

        // "Previous" saves too, then goes back
        $next = isset($input['previous']) ? max(self::STEP_CLIENT, $step - 1) : $step + 1;
        if ($next > self::LAST_STEP) {
            Html::redirect(PresaleProposal::getFormURLWithID($result['id']));
        }
        Html::redirect(self::getFormUrl() . '?id=' . $result['id'] . '&step=' . $next);
    }

    /**
     * Step 1: saves the client and its contacts, both checked before anything is written.
     * - id > 0: updates the prospect of the proposal;
     * - a known prospect (prospects_id): updates it and creates the new proposal for it;
     * - a customer entity (customer_entities_id): updates its prospect, or creates one tied to the
     *   entity (converted_entities_id), and creates the new proposal for it;
     * - else: creates a new prospect and its proposal.
     *
     * @return array{id: int, errors: array<string, string>, values: array}
     */
    public static function saveClient(int $id, array $input, array $files = []): array
    {
        $values = array_intersect_key($input, array_flip([
            'name', 'address', 'postcode', 'town', 'state', 'country',
            'phonenumber', 'email', 'website', 'comment',
        ]));
        $values          = array_map(static fn($value) => trim((string) $value), $values);
        $posted_contacts = self::readContacts($input);
        $prospects_id    = $id === 0 ? (int) ($input['prospects_id'] ?? 0) : 0;
        $entities_id     = $id === 0 && $prospects_id === 0 ? (int) ($input['customer_entities_id'] ?? 0) : 0;
        $origins_id      = (int) ($input['plugin_manageentities_presaleorigins_id'] ?? 0);
        // Posted back with the values after an error, so that the step keeps its source and origin
        $source = array_filter(['prospects_id' => $prospects_id, 'customer_entities_id' => $entities_id])
            + ['plugin_manageentities_presaleorigins_id' => $origins_id];

        // The chosen source must still be available
        $client = new PresaleClient();
        if ($prospects_id > 0) {
            $known = self::getAvailableProspect($prospects_id);
            if ($known === null) {
                return ['id' => 0, 'errors' => ['prospects_id' => __('Unknown prospect', 'manageentities')], 'values' => $values];
            }
            $client = $known;
        } elseif ($entities_id > 0) {
            if (!self::isCustomerEntity($entities_id)) {
                return ['id' => 0, 'errors' => ['customer_entities_id' => __('Unknown customer', 'manageentities')], 'values' => $values];
            }
            $client = self::getProspectOfEntity($entities_id) ?? $client;
        }

        $errors = self::validateClient($values) + self::validateContacts($posted_contacts);
        if ($origins_id > 0 && !PresaleOrigin::getById($origins_id)) {
            $errors['plugin_manageentities_presaleorigins_id'] = __('Unknown origin', 'manageentities');
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors, 'values' => $values + $posted_contacts + $source];
        }

        $values['phonenumber'] = WizardController::formatPhoneNumber($values['phonenumber'] ?? '');
        $values['website']     = WizardController::formatWebsite($values['website'] ?? '');

        if ($id > 0) {
            $proposal = self::loadProposal($id);
            if (!self::isEditable($proposal)) {
                throw new AccessDeniedHttpException();
            }
            $client = self::loadClient($proposal);
            $client->update(['id' => $client->getID()] + $values);
            $proposal->update(['id' => $id, 'plugin_manageentities_presaleorigins_id' => $origins_id]);
        } elseif (!$client->isNewItem()) {
            // Known prospect, or prospect of a customer entity: its details as corrected here
            if (!$client->can($client->getID(), UPDATE)) {
                throw new AccessDeniedHttpException();
            }
            if ($entities_id > 0 && (int) $client->fields['logo_documents_id'] === 0) {
                $values['logo_documents_id'] = self::getEntityLogo($entities_id);
            }
            $client->update(['id' => $client->getID()] + $values);
            $id = self::createProposal($client, $origins_id);
        } else {
            $values += [
                'entities_id'           => self::getTargetEntity(),
                'is_recursive'          => 0,
                'users_id'              => Session::getLoginUserID(),
                // A customer entity: the prospect is already a customer, with its logo
                'converted_entities_id' => $entities_id,
                'logo_documents_id'     => $entities_id > 0 ? self::getEntityLogo($entities_id) : 0,
            ];
            $client->check(-1, CREATE, $values);
            if (!$client->add($values)) {
                return ['id' => 0, 'errors' => ['name' => __('The prospect could not be saved.', 'manageentities')], 'values' => $values + $posted_contacts + $source];
            }
            $id = self::createProposal($client, $origins_id);
        }

        $proposal = self::loadProposal($id);
        self::saveContacts($proposal, $posted_contacts);

        $logo_error = self::saveLogo($client, $files['logo'] ?? null);
        if ($logo_error !== null) {
            return ['id' => $id, 'errors' => ['logo' => $logo_error], 'values' => $values + $posted_contacts + $source];
        }

        return ['id' => $id, 'errors' => [], 'values' => $values];
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public static function validateClient(array $values): array
    {
        $errors = [];
        foreach ([
            'name'     => __('Company name', 'manageentities'),
            'address'  => __('Address'),
            'postcode' => __('Postal code'),
            'town'     => __('City'),
        ] as $field => $label) {
            if (($values[$field] ?? '') === '') {
                $errors[$field] = sprintf(__('%s is mandatory', 'manageentities'), $label);
            }
        }
        if (!isset($errors['postcode']) && !preg_match(self::POSTCODE_PATTERN, $values['postcode'] ?? '')) {
            $errors['postcode'] = __('Invalid postal code', 'manageentities');
        }
        if (($values['email'] ?? '') !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = __('Invalid email address', 'manageentities');
        }

        return $errors;
    }

    /**
     * Draft proposal of a prospect, created at step 1
     */
    private static function createProposal(PresaleClient $client, int $origins_id = 0): int
    {
        $proposal = new PresaleProposal();
        $input    = [
            'entities_id'                             => $client->fields['entities_id'],
            'is_recursive'                            => 0,
            'plugin_manageentities_presaleclients_id' => $client->getID(),
            'plugin_manageentities_presaleorigins_id' => $origins_id,
            'name'                                    => $client->fields['name'],
            'status'                                  => PresaleProposal::STATUS_DRAFT,
            'step'                                    => self::STEP_CLIENT,
            'proposal_date'                           => date('Y-m-d'),
            'users_id'                                => Session::getLoginUserID(),
        ];
        // A known prospect keeps its contacts: its main one receives the proposal
        $contacts = (new PresaleContact())->find(
            ['plugin_manageentities_presaleclients_id' => $client->getID()],
            ['is_default DESC', 'id'],
            1,
        );
        if ($contacts !== []) {
            $input['plugin_manageentities_presalecontacts_id'] = (int) array_key_first($contacts);
        }
        $proposal->check(-1, CREATE, $input);

        return (int) $proposal->add($input);
    }

    /**
     * Optional logo of the prospect: PNG or JPEG, 2 MB at most, type checked on the content
     *
     * @param PresaleClient $client
     * @param array|null    $file   entry of $_FILES
     *
     * @return string|null error message, null when saved or nothing uploaded
     */
    private static function saveLogo(PresaleClient $client, ?array $file): ?string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return __('The logo could not be uploaded.', 'manageentities');
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return __('The logo must not exceed 2 MB.', 'manageentities');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return __('The logo must be a PNG or JPEG image.', 'manageentities');
        }

        $extension = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $prefix    = uniqid('', true) . '_';
        $filename  = $prefix . 'logo.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], GLPI_UPLOAD_DIR . '/' . $filename)) {
            return __('The logo could not be uploaded.', 'manageentities');
        }

        $document = new \Document();
        $input    = [
            'entities_id'           => $client->fields['entities_id'],
            'is_recursive'          => 0,
            'name'                  => sprintf(__('Logo of %s', 'manageentities'), $client->fields['name']),
            'documentcategories_id' => (int) (Config::getInstance()->fields['presale_documentcategories_id'] ?? 0),
            'upload_file'           => $filename,
            '_prefix_filename'      => [$prefix],
            '_no_message'           => true,
            'itemtype'              => PresaleClient::class,
            'items_id'              => $client->getID(),
        ];
        if (!$document->can(-1, CREATE, $input) || !($documents_id = $document->add($input))) {
            @unlink(GLPI_UPLOAD_DIR . '/' . $filename);
            return __('The logo could not be uploaded.', 'manageentities');
        }

        // The previous logo stays in the documents of the prospect: only the reference moves
        $client->update(['id' => $client->getID(), 'logo_documents_id' => $documents_id]);

        return null;
    }

    /**
     * Contacts posted with the client, the blocks left empty dropped
     *
     * @return array{contacts: list<array<string, string>>, is_default: int}
     */
    private static function readContacts(array $input): array
    {
        $values = [
            'contacts'   => [],
            'is_default' => (int) ($input['is_default'] ?? 0),
        ];
        foreach (array_values(is_array($input['contacts'] ?? null) ? $input['contacts'] : []) as $posted) {
            $contact = [];
            foreach (['id', 'name', 'firstname', 'role', 'email', 'phone', 'mobile', 'contacts_id'] as $field) {
                $contact[$field] = trim((string) ($posted[$field] ?? ''));
            }
            // A block left empty is ignored
            if (implode('', array_diff_key($contact, ['id' => true, 'contacts_id' => true])) === '') {
                continue;
            }
            $values['contacts'][] = $contact;
        }
        if (!isset($values['contacts'][$values['is_default']])) {
            $values['is_default'] = 0;
        }

        return $values;
    }

    /**
     * At least one contact, each with a last name and a valid email address
     *
     * @param array{contacts: list<array<string, string>>, is_default: int} $values
     *
     * @return array<string, string>
     */
    private static function validateContacts(array $values): array
    {
        $errors = [];
        if ($values['contacts'] === []) {
            $errors['contacts'] = __('At least one contact is required.', 'manageentities');
        }
        foreach ($values['contacts'] as $idx => $contact) {
            if ($contact['name'] === '') {
                $errors['contacts.' . $idx . '.name'] = sprintf(
                    __('Contact #%d: the last name is mandatory', 'manageentities'),
                    $idx + 1,
                );
            }
            if ($contact['email'] !== '' && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['contacts.' . $idx . '.email'] = sprintf(
                    __('Contact #%d: invalid email address', 'manageentities'),
                    $idx + 1,
                );
            }
        }

        return $errors;
    }

    /**
     * The contacts of the prospect, replaced by the checked list (validateContacts()), the main
     * one being the recipient of the proposal when none was chosen yet. Saves step 1.
     *
     * @param array{contacts: list<array<string, string>>, is_default: int} $values
     */
    private static function saveContacts(PresaleProposal $proposal, array $values): void
    {
        $clients_id = (int) $proposal->fields['plugin_manageentities_presaleclients_id'];
        $link       = new PresaleContact();
        $existing   = $link->find(['plugin_manageentities_presaleclients_id' => $clients_id]);
        $kept       = [];
        $default_id = 0;
        foreach ($values['contacts'] as $idx => $contact) {
            $data = [
                'name'       => WizardController::formatLastName($contact['name']),
                'firstname'  => WizardController::formatFirstName($contact['firstname']),
                'role'       => $contact['role'],
                'email'      => $contact['email'],
                'phone'      => WizardController::formatPhoneNumber($contact['phone']),
                'mobile'     => WizardController::formatPhoneNumber($contact['mobile']),
                'is_default' => $idx === $values['is_default'] ? 1 : 0,
            ];
            // Contact of the customer entity it was prefilled from (GLPI contact)
            if ((int) ($contact['contacts_id'] ?? 0) > 0) {
                $data['contacts_id'] = (int) $contact['contacts_id'];
            }
            $contacts_id = (int) $contact['id'];
            // Only a contact of this prospect is updated: any other posted id creates a new one
            if ($contacts_id > 0 && isset($existing[$contacts_id])) {
                $link->update(['id' => $contacts_id] + $data);
            } else {
                $contacts_id = (int) $link->add(['plugin_manageentities_presaleclients_id' => $clients_id] + $data);
            }
            $kept[] = $contacts_id;
            if ($data['is_default']) {
                $default_id = $contacts_id;
            }
        }
        foreach (array_diff(array_keys($existing), $kept) as $removed) {
            $link->delete(['id' => $removed], true);
        }

        // The recipient of the proposal follows the main contact while none was chosen, or
        // when the chosen one was just removed
        $recipient = (int) $proposal->fields['plugin_manageentities_presalecontacts_id'];
        if ($recipient === 0 || !in_array($recipient, $kept, true)) {
            $recipient = $default_id;
        }
        self::updateProposal($proposal, self::STEP_CLIENT, [
            'plugin_manageentities_presalecontacts_id' => $recipient,
        ]);
    }

    /**
     * Step 2: the sales representative (mandatory) and the project manager (optional), posted
     * under sales[] and pm[]
     *
     * @return array{id: int, errors: array<string, string>, values: array}
     */
    public static function saveTeam(PresaleProposal $proposal, array $input): array
    {
        $errors = [];
        $values = [];
        $fields = [];
        foreach (['sales' => PresaleUser::TYPE_SALES, 'pm' => PresaleUser::TYPE_PROJECT_MANAGER] as $prefix => $type) {
            $checked          = self::checkPerson(is_array($input[$prefix] ?? null) ? $input[$prefix] : [], $type);
            $values[$prefix] = $checked['values'];
            foreach ($checked['errors'] as $field => $message) {
                $errors[$prefix . '.' . $field] = $message;
            }
            foreach ($checked['values'] as $field => $value) {
                $fields[$prefix . '_' . $field] = $field === 'phone' ? WizardController::formatPhoneNumber($value) : $value;
            }
        }
        if ($errors !== []) {
            return ['id' => $proposal->getID(), 'errors' => $errors, 'values' => $values];
        }

        self::updateProposal($proposal, self::STEP_TEAM, $fields);

        return ['id' => $proposal->getID(), 'errors' => [], 'values' => $values];
    }

    /**
     * One person of step 2: one of the declared users or typed details. The project manager is
     * optional: no name means no project manager at all.
     *
     * @param int $type PresaleUser::TYPE_*
     *
     * @return array{errors: array<string, string>, values: array{users_id: int, name: string, role: string, email: string, phone: string}}
     */
    private static function checkPerson(array $input, int $type): array
    {
        $values = ['users_id' => (int) ($input['users_id'] ?? 0)];
        foreach (['name', 'role', 'email', 'phone'] as $field) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }

        $errors = [];
        // Only one of the declared users can be chosen
        if ($values['users_id'] > 0
            && !in_array($values['users_id'], array_column(PresaleUser::getUsers($type), 'users_id'), true)) {
            $errors['users_id'] = __('This user is not declared for the pre-sales.', 'manageentities');
        }
        if ($type === PresaleUser::TYPE_SALES && $values['name'] === '') {
            $errors['name'] = __('The name of the sales representative is mandatory.', 'manageentities');
        }
        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = __('Invalid email address', 'manageentities');
        }
        if ($errors === [] && $values['name'] === '') {
            $values = ['users_id' => 0, 'name' => '', 'role' => '', 'email' => '', 'phone' => ''];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Type of contract and internal label deduced from the annexes, for the ones still following
     * the annexes: empty, or equal to what the previous annexes gave. A value typed at step 4
     * differs from it and is kept.
     *
     * @param list<string> $before names of the annexes before the save (PresaleProposal_Annex::namesOf())
     * @param list<string> $after  names of the annexes saved
     *
     * @return array<string, string> fields of the proposal to update
     */
    private static function deduceFromAnnexes(PresaleProposal $proposal, array $before, array $after): array
    {
        $client   = (string) self::loadClient($proposal)->fields['name'];
        $old_type = PresaleProposal_Annex::deduceContractType($before);
        $new_type = PresaleProposal_Annex::deduceContractType($after);
        $type     = (string) $proposal->fields['proposal_type'];
        $name     = (string) $proposal->fields['name'];

        $fields = [];
        if ($type === '' || $type === $old_type) {
            $fields['proposal_type'] = $new_type;
        }
        if (in_array($name, ['', $client, PresaleProposal_Annex::deduceLabel($client, $old_type)], true)) {
            $fields['name'] = PresaleProposal_Annex::deduceLabel($client, $new_type);
        }

        return $fields;
    }

    /**
     * Step 4: general information of the proposal. Type of contract and internal label come
     * deduced from the annexes (step 3), and stay editable.
     *
     * @return array{id: int, errors: array<string, string>, values: array}
     */
    public static function saveGeneral(PresaleProposal $proposal, array $input): array
    {
        $values = [
            'proposal_type'                           => trim((string) ($input['proposal_type'] ?? '')),
            'proposal_date'                           => trim((string) ($input['proposal_date'] ?? '')),
            'name'                                    => trim((string) ($input['name'] ?? '')),
            'summary'                                 => (string) ($input['summary'] ?? ''),
            'plugin_manageentities_presalecontacts_id' => (int) ($input['plugin_manageentities_presalecontacts_id'] ?? 0),
        ];

        $errors = [];
        if ($values['proposal_type'] === '') {
            $errors['proposal_type'] = __('The type of contract is mandatory.', 'manageentities');
        }
        $date = \DateTime::createFromFormat('!Y-m-d', $values['proposal_date']);
        if ($date === false || $date->format('Y-m-d') !== $values['proposal_date']) {
            $errors['proposal_date'] = __('Invalid date of the contract.', 'manageentities');
        }
        if (!isset(self::getContactChoices($proposal)[$values['plugin_manageentities_presalecontacts_id']])) {
            $errors['plugin_manageentities_presalecontacts_id'] = __('Choose the recipient of the proposal.', 'manageentities');
        }
        if ($errors !== []) {
            return ['id' => $proposal->getID(), 'errors' => $errors, 'values' => $values];
        }

        if ($values['name'] === '') {
            $values['name'] = PresaleProposal_Annex::deduceLabel(
                (string) self::loadClient($proposal)->fields['name'],
                $values['proposal_type'],
            );
        }
        self::updateProposal($proposal, self::STEP_GENERAL, $values);

        return ['id' => $proposal->getID(), 'errors' => [], 'values' => $values];
    }

    // -------------------------------------------------------------------------
    // Step 3: annexes
    // -------------------------------------------------------------------------

    /**
     * Annex models offered: the active ones, plus the inactive ones already retained by this
     * proposal (a model disabled afterwards must not silently vanish from a draft)
     *
     * @return array<int, array> rows of the models, by id, in name order
     */
    private static function getAnnexModels(array $retained_models = []): array
    {
        $criteria = ['is_active' => 1];
        if ($retained_models !== []) {
            $criteria = ['OR' => ['is_active' => 1, 'id' => $retained_models]];
        }
        $models = [];
        foreach ((new PresaleAnnexType())->find(
            $criteria + getEntitiesRestrictCriteria(PresaleAnnexType::getTable(), '', '', true),
            ['name'],
        ) as $model) {
            // Subscription annexes are only offered while the subscriptions are enabled
            if ($model['is_subscription'] && !Config::useEditorSubscriptions()) {
                continue;
            }
            $models[(int) $model['id']] = $model;
        }
        return $models;
    }

    /**
     * Annexes of a proposal as edited by step 3, in document order
     *
     * @return list<array> annexes with their model and their lines
     */
    private static function loadAnnexes(PresaleProposal $proposal): array
    {
        $annexes = [];
        foreach ((new PresaleProposal_Annex())->find([
            'plugin_manageentities_presaleproposals_id' => $proposal->getID(),
        ], ['rank', 'id']) as $annex) {
            $lines = [];
            foreach ((new PresaleAnnexLine())->find([
                'plugin_manageentities_presaleproposals_annexes_id' => $annex['id'],
            ], ['rank', 'id']) as $line) {
                $lines[] = [
                    'designation' => $line['designation'],
                    'quantity'    => self::formatDecimal((float) $line['quantity']),
                    'unit_price'  => self::formatDecimal((float) $line['unit_price']),
                    'months'      => $line['months'] !== null ? self::formatDecimal((float) $line['months']) : '',
                ];
            }
            $annexes[] = [
                'id'          => (int) $annex['id'],
                'models_id'   => (int) $annex['plugin_manageentities_presaleannextypes_id'],
                // The number is given by the position, only the label is edited
                'title'       => PresaleProposal_Annex::stripNumber((string) $annex['title']),
                'objet'       => $annex['objet'],
                'description' => $annex['description'],
                'estimate'    => PresaleEstimate::decode($annex['estimate'] ?? null),
                'vat_rate'    => self::formatDecimal((float) $annex['vat_rate']),
                'lines'       => $lines,
            ];
        }
        return $annexes;
    }

    /**
     * Number shown in an input: no trailing zeros, decimal comma
     */
    private static function formatDecimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        return str_replace('.', ',', $text);
    }

    /**
     * New annex of a model, as the wizard starts it: the texts and the lines of the model
     *
     * @param array $model row of the model
     */
    private static function newAnnex(array $model): array
    {
        return [
            'id'          => 0,
            'models_id'   => (int) $model['id'],
            'title'       => PresaleProposal_Annex::stripNumber((string) $model['name']),
            'objet'       => $model['objet'] ?? '',
            'description' => $model['description'] ?? '',
            // Estimate configured on the model, regular annexes only
            'estimate'    => $model['is_subscription'] ? [] : PresaleEstimate::decode($model['default_estimate'] ?? null),
            'vat_rate'    => self::formatDecimal((float) ($model['default_vat'] ?? Config::getDefaultPresaleVat())),
            // Lines configured on the model, changed here at will
            'lines'       => self::defaultLines($model),
        ];
    }

    /**
     * Lines a new annex of a model starts with. A cloud annex takes the monthly price of its
     * subscription level when one is set, and runs for one year by default.
     *
     * @param array $model row of the model
     *
     * @return list<array{designation: string, quantity: string, unit_price: string, months: string}>
     */
    private static function defaultLines(array $model): array
    {
        $lines = [];
        foreach (PresaleAnnexType::getDefaultLines($model) as $line) {
            $lines[] = $line;
        }
        if (PresaleAnnexType::getPricingMode($model) !== PresalePricing::MODE_CLOUD) {
            return $lines;
        }

        if ($lines === []) {
            $lines[] = [
                'designation' => PresaleProposal_Annex::stripNumber((string) $model['name']),
                'quantity'    => '',
                'unit_price'  => '',
                'months'      => '',
            ];
        }
        // The monthly price is the one of the level (read only in the wizard)
        $price = PresaleAnnexType::getCloudPrice($model);
        foreach ($lines as &$line) {
            if ($price !== null) {
                $line['unit_price'] = self::formatDecimal($price);
            }
            if ($line['months'] === '') {
                $line['months'] = (string) PresalePricing::DEFAULT_MONTHS;
            }
        }
        unset($line);

        return $lines;
    }

    /**
     * Block of step 3 for an annex: the annex and what its model says about it
     *
     * @param array $annex annex of loadAnnexes() / newAnnex(), or as posted
     * @param array $model row of its model
     */
    private static function annexBlock(array $annex, array $model): array
    {
        $annex['lines'] = array_values(is_array($annex['lines'] ?? null) ? $annex['lines'] : []);
        // At least one line to type in, the "Add a line" button adds more
        if ($annex['lines'] === []) {
            $annex['lines'][] = ['designation' => '', 'quantity' => '', 'unit_price' => '', 'months' => ''];
        }

        return [
            'models_id'       => (int) $model['id'],
            'model_name'      => PresaleProposal_Annex::stripNumber((string) $model['name']),
            'is_subscription' => (bool) $model['is_subscription'],
            'is_inactive'     => !$model['is_active'],
            // How the lines are priced, given by the model (its subscription level)
            'mode'            => PresaleAnnexType::getPricingMode($model),
            // Cloud subscription: monthly price of the level, read only in the lines
            'level_price'     => PresaleAnnexType::getPricingMode($model) === PresalePricing::MODE_CLOUD
                ? PresaleAnnexType::getCloudPrice($model)
                : null,
            'level_name'      => $model['is_subscription']
                ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int) $model['plugin_manageentities_subscriptionlevels_id'])
                : '',
        ] + $annex + ['id' => 0, 'title' => '', 'objet' => '', 'description' => '', 'vat_rate' => '', 'estimate' => []]
            + ['estimate_total' => PresaleEstimate::formatTotal(PresaleEstimate::validate($annex['estimate'] ?? [])['rows'])];
    }

    /**
     * "Add" of step 3: the step is shown again with what was typed, unchecked, and the annex of
     * the chosen model appended, started from that model. Nothing is saved until "Next".
     */
    private static function addAnnex(int $id, array $input): never
    {
        $proposal = self::loadProposal($id);
        if (!self::isEditable($proposal)) {
            throw new AccessDeniedHttpException();
        }
        $annexes   = array_values(array_filter(is_array($input['annexes'] ?? null) ? $input['annexes'] : [], 'is_array'));
        $models_id = (int) ($input['add_annex_models_id'] ?? 0);
        $model     = self::getAnnexModels()[$models_id] ?? null;
        $errors    = [];
        if ($model !== null) {
            $annexes[] = self::newAnnex($model);
        } else {
            $errors['add_annex'] = __('Choose the annex model to add.', 'manageentities');
        }
        // Shown again as after a failed post, without its error messages when the annex is added
        $_SESSION[self::SESSION_KEY] = ['step' => self::STEP_ANNEXES, 'errors' => $errors, 'values' => ['annexes' => $annexes]];
        Html::redirect(self::getFormUrl() . '?id=' . $proposal->getID() . '&step=' . self::STEP_ANNEXES . '#presale-annex-' . (count($annexes) - 1));
    }

    /**
     * Summary of the annex models, shown when one is chosen in the list of step 3: comment,
     * purpose as plain text, VAT rate, subscription level and its price, default lines
     *
     * @param array<int, array> $models rows of the models, by id
     *
     * @return array<int, array{comment: string, objet: string, vat: string, level: string, price: string, lines: list<string>}>
     */
    private static function getModelSummaries(array $models): array
    {
        $summaries = [];
        foreach ($models as $models_id => $model) {
            $objet = RichText::toPlainText((string) ($model['objet'] ?? ''));
            $price = PresaleAnnexType::getCloudPrice($model);
            $lines = [];
            foreach (PresaleAnnexType::getDefaultLines($model) as $line) {
                $amounts = array_filter([$line['quantity'], $line['unit_price'] !== '' ? $line['unit_price'] . ' €' : '']);
                $lines[] = $line['designation'] . ($amounts !== [] ? ' (' . implode(' × ', $amounts) . ')' : '');
            }
            $summaries[$models_id] = [
                'comment' => (string) ($model['comment'] ?? ''),
                'objet'   => mb_strlen($objet) > 200 ? mb_substr($objet, 0, 200) . '…' : $objet,
                'vat'     => self::formatDecimal((float) ($model['default_vat'] ?? 0)),
                'level'   => $model['is_subscription']
                    ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int) $model['plugin_manageentities_subscriptionlevels_id'])
                    : '',
                'price'   => $price !== null
                    ? sprintf(__('%s € excl. VAT per subscription per month', 'manageentities'), self::formatDecimal($price))
                    : '',
                'lines'   => $lines,
            ];
        }

        return $summaries;
    }

    private static function buildAnnexesVars(?PresaleProposal $proposal, ?array $values): array
    {
        $saved  = $proposal !== null ? self::loadAnnexes($proposal) : [];
        $models = self::getAnnexModels(array_column($saved, 'models_id'));

        // Values of a failed post, else the saved annexes, in document order
        $annexes = is_array($values['annexes'] ?? null) ? array_values($values['annexes']) : $saved;

        $blocks = [];
        foreach ($annexes as $annex) {
            $model = $models[(int) ($annex['models_id'] ?? 0)] ?? null;
            if ($model !== null) {
                $blocks[] = self::annexBlock($annex, $model);
            }
        }

        // Models that can be added: the active ones
        $choices = [];
        foreach ($models as $models_id => $model) {
            if ($model['is_active']) {
                $choices[$models_id] = PresaleProposal_Annex::stripNumber((string) $model['name']);
            }
        }

        return [
            'annexes'           => $blocks,
            'models'            => $choices,
            'summaries'         => self::getModelSummaries(array_intersect_key($models, $choices)),
            // Estimate by the project manager
            'estimate_status'   => (int) ($proposal?->fields['estimate_status'] ?? 0),
            'estimate_statuses' => PresaleProposal::getEstimateStatuses(),
            'estimate_brief'    => (string) ($proposal?->fields['estimate_brief'] ?? ''),
            'estimate_comment'  => (string) ($proposal?->fields['estimate_comment'] ?? ''),
            'pm_name'           => (string) ($proposal?->fields['pm_name'] ?? ''),
            'has_pm'            => (int) ($proposal?->fields['pm_users_id'] ?? 0) > 0,
            'estimate_url'      => $proposal !== null ? self::getEstimateUrl($proposal->getID()) : '',
            'title_format'      => PresaleProposal_Annex::TITLE_FORMAT,
            'models_url'        => PresaleAnnexType::getSearchURL(),
            'can_manage_models' => PresaleAnnexType::canCreate(),
        ];
    }

    /**
     * Step 3: the annexes of the proposal, replaced by the posted list in its order, numbered
     * from 1 in that order, and the totals of the proposal recomputed
     *
     * @return array{id: int, errors: array<string, string>, values: array}
     */
    public static function saveAnnexes(PresaleProposal $proposal, array $input): array
    {
        // Document order: the order of the blocks in the page, as posted
        $posted = array_values(array_filter(
            is_array($input['annexes'] ?? null) ? $input['annexes'] : [],
            'is_array',
        ));
        $saved  = self::loadAnnexes($proposal);
        $models = self::getAnnexModels(array_column($saved, 'models_id'));

        $values   = ['annexes' => $posted];
        $errors   = [];
        $retained = [];
        foreach ($posted as $idx => $annex) {
            $models_id = (int) ($annex['models_id'] ?? 0);
            if (!isset($models[$models_id])) {
                $errors['annexes.' . $idx] = __('Unknown annex model', 'manageentities');
                continue;
            }
            $model = $models[$models_id];
            $label = PresaleProposal_Annex::stripNumber(trim((string) ($annex['title'] ?? '')))
                ?: PresaleProposal_Annex::stripNumber((string) $model['name']);
            $title = PresaleProposal_Annex::numberTitle($idx + 1, $label);

            $vat_rate = PresalePricing::parseVatRate((string) ($annex['vat_rate'] ?? ''));
            if ($vat_rate === null) {
                $errors['annexes.' . $idx . '.vat_rate'] = sprintf(
                    __('%s: the VAT rate must be between 0 and 100', 'manageentities'),
                    $title,
                );
            }

            $mode = PresaleAnnexType::getPricingMode($model);

            // Cloud subscription: the monthly price is the one of its level, whatever is posted
            $lines       = is_array($annex['lines'] ?? null) ? $annex['lines'] : [];
            $level_price = $mode === PresalePricing::MODE_CLOUD ? PresaleAnnexType::getCloudPrice($model) : null;
            if ($level_price !== null) {
                foreach ($lines as &$line) {
                    if (is_array($line)) {
                        $line['unit_price'] = self::formatDecimal($level_price);
                    }
                }
                unset($line);
            }
            $checked = PresalePricing::validateLines($lines, $mode);
            if ($checked['lines'] === [] && empty($input['_skip_days_check'])) {
                $errors['annexes.' . $idx . '.lines'] = sprintf(
                    __('%s: at least one line is required', 'manageentities'),
                    $title,
                );
            }
            // Saved by the estimate screen: the priced lines are completed afterwards by the
            // sales representative, their quantity or price may still be missing
            foreach (empty($input['_skip_days_check']) ? $checked['errors'] : [] as $line_idx => $message) {
                $errors['annexes.' . $idx . '.lines.' . $line_idx] = sprintf(
                    __('%1$s, line %2$d: %3$s', 'manageentities'),
                    $title,
                    $line_idx + 1,
                    $message,
                );
            }

            // Estimate: regular annexes only
            $estimate = ['rows' => [], 'errors' => []];
            if (!$model['is_subscription']) {
                $estimate = PresaleEstimate::validate($annex['estimate'] ?? []);
                foreach ($estimate['errors'] as $message) {
                    $errors['annexes.' . $idx . '.estimate'] = $title . ' : ' . $message;
                }
                // The priced days are the ones of the estimate: same total
                $estimate_days = PresaleEstimate::total($estimate['rows']);
                $priced_days   = PresaleEstimate::pricedDays($checked['lines']);
                if (empty($input['_skip_days_check']) && $estimate['rows'] !== [] && $estimate['errors'] === [] && $checked['errors'] === []
                    && abs($estimate_days - $priced_days) > 0.001) {
                    $errors['annexes.' . $idx . '.estimate'] = sprintf(
                        __('%1$s: the priced lines total %2$s days, the estimate %3$s days', 'manageentities'),
                        $title,
                        self::formatDecimal($priced_days),
                        self::formatDecimal($estimate_days),
                    );
                }
            }

            $retained[] = [
                'id'          => (int) ($annex['id'] ?? 0),
                'models_id'   => $models_id,
                'title'       => $title,
                'objet'       => (string) ($annex['objet'] ?? ''),
                'description' => (string) ($annex['description'] ?? ''),
                'estimate'    => PresaleEstimate::encode($estimate['rows']),
                'vat_rate'    => (float) $vat_rate,
                'lines'       => $checked['lines'],
                'mode'        => $mode,
                // Subscription: level and cloud flag come from the model, copied for the creation
                // of the subscription of the customer once the proposal is won. The dates and the
                // customer account at the publisher are only known then.
                'plugin_manageentities_subscriptionlevels_id' => $model['is_subscription']
                    ? (int) $model['plugin_manageentities_subscriptionlevels_id']
                    : 0,
                'cloud_client' => PresaleAnnexType::isCloud($model) ? 1 : 0,
            ];
        }

        if ($posted === []) {
            $errors['annexes'] = __('Retain at least one annex.', 'manageentities');
        }
        if ($errors !== []) {
            return ['id' => $proposal->getID(), 'errors' => $errors, 'values' => $values];
        }

        // Replace the annexes of the proposal: a posted annex of this proposal keeps its row (its
        // id is stable for the history), its lines are rewritten. Any other id creates a new one.
        $annex_link = new PresaleProposal_Annex();
        $line_link  = new PresaleAnnexLine();
        $existing   = array_flip(array_column($saved, 'id'));

        $totals = ['ht' => 0.0, 'vat' => 0.0, 'ttc' => 0.0];
        foreach ($retained as $rank => $annex) {
            $data = [
                'rank'                                       => $rank,
                'plugin_manageentities_presaleannextypes_id' => $annex['models_id'],
            ] + array_intersect_key($annex, array_flip([
                'title', 'objet', 'description', 'estimate', 'vat_rate',
                'plugin_manageentities_subscriptionlevels_id', 'cloud_client',
            ]));

            if (isset($existing[$annex['id']])) {
                $annexes_id = $annex['id'];
                $annex_link->update(['id' => $annexes_id] + $data);
                $line_link->deleteByCriteria(['plugin_manageentities_presaleproposals_annexes_id' => $annexes_id], true);
                unset($existing[$annexes_id]);
            } else {
                $annexes_id = (int) $annex_link->add([
                    'plugin_manageentities_presaleproposals_id' => $proposal->getID(),
                ] + $data);
            }

            foreach ($annex['lines'] as $idx => $line) {
                $line_link->add([
                    'plugin_manageentities_presaleproposals_annexes_id' => $annexes_id,
                    'rank'        => $idx,
                    'designation' => $line['designation'],
                    'quantity'    => PresalePricing::parseNumber($line['quantity']) ?? 0,
                    'unit_price'  => PresalePricing::parseNumber($line['unit_price']) ?? 0,
                    // Cloud: the months typed, one year when left empty
                    'months'      => $annex['mode'] === PresalePricing::MODE_CLOUD
                        ? PresalePricing::months($line, $annex['mode'])
                        : null,
                ]);
            }

            $annex_totals = PresalePricing::annexTotals($annex['lines'], $annex['vat_rate'], $annex['mode']);
            foreach ($totals as $key => $amount) {
                $totals[$key] = round($amount + $annex_totals[$key], 2);
            }
        }
        // Annexes no longer retained, with their lines (cleanDBonPurge)
        foreach (array_keys($existing) as $annexes_id) {
            $annex_link->delete(['id' => $annexes_id], true);
        }

        self::updateProposal($proposal, self::STEP_ANNEXES, [
            'total_ht'  => $totals['ht'],
            'total_vat' => $totals['vat'],
            'total_ttc' => $totals['ttc'],
        ] + self::deduceFromAnnexes($proposal, PresaleProposal_Annex::namesOf($saved), PresaleProposal_Annex::namesOf($retained)));

        return ['id' => $proposal->getID(), 'errors' => [], 'values' => $values];
    }

    // -------------------------------------------------------------------------
    // Step 5: summary, generation, outcome
    // -------------------------------------------------------------------------

    private static function buildGenerateVars(?PresaleProposal $proposal): array
    {
        if ($proposal === null) {
            return [];
        }
        $client    = self::loadClient($proposal);
        $recipient = new PresaleContact();
        $recipient->getFromDB((int) $proposal->fields['plugin_manageentities_presalecontacts_id']);

        // Generated versions, newest first (the logo of the prospect is a document of the
        // prospect, not of the proposal)
        $versions = [];
        foreach ((new \Document_Item())->find([
            'itemtype' => PresaleProposal::class,
            'items_id' => $proposal->getID(),
        ], ['id DESC']) as $link) {
            $document = new \Document();
            if (!$document->getFromDB((int) $link['documents_id'])) {
                continue;
            }
            $versions[] = [
                'name'    => $document->fields['name'],
                'date'    => $document->fields['date_creation'],
                'url'     => self::getDocumentUrl((int) $document->getID(), $proposal->getID()),
                'is_last' => (int) $document->getID() === (int) $proposal->fields['documents_id'],
            ];
        }

        return [
            'proposal'     => $proposal->fields,
            'client'       => $client->fields,
            'recipient'    => $recipient->isNewItem()
                ? ''
                : trim($recipient->fields['firstname'] . ' ' . $recipient->fields['name']),
            'annexes'      => $proposal->getAnnexesSummary(),
            'versions'     => $versions,
            'action_url'   => self::getActionUrl(),
            // Estimate requested from the project manager and not validated yet: shown, not blocking
            'estimate_pending' => (int) $proposal->fields['estimate_status'] === PresaleProposal::ESTIMATE_REQUESTED,
            'estimate_url'     => self::getEstimateUrl($proposal->getID()),
            // Templates to upload before generating, shown with the button
            'missing_templates' => (new PresaleGenerator($proposal))->getMissingTemplates(),
            'templates_url'     => PresaleTemplate::getConfigUrl(),
            'can_generate' => self::isEditable($proposal)
                && countElementsInTable(PresaleProposal_Annex::getTable(), [
                    'plugin_manageentities_presaleproposals_id' => $proposal->getID(),
                ]) > 0,
        ];
    }

    /**
     * Download link of a generated document: served by the core, which checks the rights on
     * the document or on the proposal it is linked to
     */
    public static function getDocumentUrl(int $documents_id, int $proposals_id): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query([
            'docid'    => $documents_id,
            'itemtype' => PresaleProposal::class,
            'items_id' => $proposals_id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Estimate by the project manager (front/presaleestimate.form.php)
    // -------------------------------------------------------------------------

    /**
     * Customer wizard prefilled from a won proposal (path from the root of GLPI)
     */
    public static function getCustomerWizardPath(int $proposals_id): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/addelements.form.php?from_proposal=' . $proposals_id;
    }

    public static function getEstimateUrl(int $proposals_id): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presaleestimate.form.php?id=' . $proposals_id;
    }

    /**
     * Proposals whose estimate is requested from the connected user (its project manager)
     *
     * @return list<array> rows of getOpenProposals(), with the url of the estimate screen
     */
    public static function getEstimatesToDo(): array
    {
        $list = self::getOpenProposals([
            PresaleProposal::getTable() . '.estimate_status' => PresaleProposal::ESTIMATE_REQUESTED,
            PresaleProposal::getTable() . '.pm_users_id'     => (int) Session::getLoginUserID(),
        ]);
        foreach ($list as &$row) {
            $row['url'] = self::getEstimateUrl($row['id']);
        }
        unset($row);

        return $list;
    }

    /**
     * Estimate screen: the note of the sales representative, the annexes of the proposal with
     * the estimate of the regular ones. Annexes can be removed or ordered, not added (the sales
     * representative chooses them); prices are not shown.
     */
    public static function renderEstimate(int $id): void
    {
        $proposal = self::loadProposal($id, READ);
        $failed   = self::pullFailedPost(self::STEP_ESTIMATE);
        $vars     = self::buildAnnexesVars($proposal, $failed['values']);
        $fields   = $proposal->fields;

        TemplateRenderer::getInstance()->display('@manageentities/presale/estimate_form.html.twig', $vars + [
            'proposal'    => $fields,
            'client_name' => self::loadClient($proposal)->fields['name'],
            'statuses'    => PresaleProposal::getEstimateStatuses(),
            'brief'       => $fields['estimate_brief'] ?? '',
            'comment'     => $failed['values']['estimate_comment'] ?? ($fields['estimate_comment'] ?? ''),
            'validated_by' => (int) $fields['estimate_users_id'] > 0 ? getUserName((int) $fields['estimate_users_id']) : '',
            'errors'      => $failed['errors'],
            'readonly'    => !self::isEditable($proposal) || !$proposal->can($id, UPDATE),
            'form_url'    => self::getEstimateUrl($id),
            'proposal_url' => PresaleProposal::getFormURLWithID($id),
            'wizard_url'  => self::getFormUrl() . '?id=' . $id . '&step=' . self::STEP_ANNEXES,
        ]);
    }

    /**
     * Saves the estimate screen: the annexes (scope and estimates, the priced lines of the
     * annexes kept as they are), and the comment. "validate" validates the estimate.
     */
    public static function handleEstimatePost(array $input): never
    {
        $id       = (int) ($input['id'] ?? 0);
        $proposal = self::loadProposal($id);
        if (!self::isEditable($proposal)) {
            throw new AccessDeniedHttpException();
        }

        $result = self::saveEstimate($proposal, $input);
        if ($result['errors'] !== []) {
            self::keepFailedPost(self::STEP_ESTIMATE, $result['errors'], $result['values']);
            Html::redirect(self::getEstimateUrl($id));
        }

        if (isset($input['validate'])) {
            $proposal->update([
                'id'                => $id,
                'estimate_status'   => PresaleProposal::ESTIMATE_VALIDATED,
                'estimate_date'     => $_SESSION['glpi_currenttime'],
                'estimate_users_id' => Session::getLoginUserID(),
            ]);
            Session::addMessageAfterRedirect(__('The estimate is validated: the sales representative can price the proposal.', 'manageentities'));
            Html::redirect(self::getFormUrl());
        }
        Session::addMessageAfterRedirect(__('The estimate has been saved.', 'manageentities'));
        Html::redirect(self::getEstimateUrl($id));
    }

    /**
     * Annexes posted by the estimate screen: their estimate, their order, the removed ones.
     * The fields the screen does not show (texts, VAT, priced lines) come from the saved annex;
     * an annex the proposal does not hold is ignored. The days of the lines are not checked
     * against the estimate here, the sales representative prices them afterwards.
     *
     * @return array{id: int, errors: array<string, string>, values: array}
     */
    private static function saveEstimate(PresaleProposal $proposal, array $input): array
    {
        $saved  = [];
        foreach (self::loadAnnexes($proposal) as $annex) {
            $saved[$annex['id']] = $annex;
        }
        $models  = self::getAnnexModels(array_column($saved, 'models_id'));
        $posted  = array_values(array_filter(is_array($input['annexes'] ?? null) ? $input['annexes'] : [], 'is_array'));
        $comment = trim((string) ($input['estimate_comment'] ?? ''));
        $values  = ['annexes' => $posted, 'estimate_comment' => $comment];

        $annexes = [];
        $errors  = [];
        foreach ($posted as $idx => $annex) {
            $model = $models[(int) ($annex['models_id'] ?? 0)] ?? null;
            if ($model === null) {
                $errors['annexes.' . $idx] = __('Unknown annex model', 'manageentities');
                continue;
            }
            // Saved annex only: the screen adds none, and posts neither texts nor prices
            $base = $saved[(int) ($annex['id'] ?? 0)] ?? null;
            if ($base === null) {
                continue;
            }
            if (!$model['is_subscription']) {
                $checked = PresaleEstimate::validate($annex['estimate'] ?? []);
                foreach ($checked['errors'] as $message) {
                    $errors['annexes.' . $idx . '.estimate'] = $message;
                }
                $base['estimate'] = $checked['rows'];
            }
            $annexes[] = [
                'id'                    => (int) ($base['id'] ?? 0),
                'models_id'             => (int) $model['id'],
                'title'                 => (string) $base['title'],
                'objet'                 => (string) $base['objet'],
                'description'           => (string) $base['description'],
                'estimate'              => $base['estimate'] ?? [],
                'vat_rate'              => (string) $base['vat_rate'],
                'lines'                 => $base['lines'],
            ];
        }
        if ($annexes === [] && $errors === []) {
            $errors['annexes'] = __('Retain at least one annex.', 'manageentities');
        }
        if ($errors !== []) {
            return ['id' => $proposal->getID(), 'errors' => $errors, 'values' => $values];
        }

        // Same saving as step 3, without its check of the days: the estimate leads, the
        // sales representative adapts the priced lines afterwards
        $result = self::saveAnnexes($proposal, ['annexes' => $annexes, '_skip_days_check' => true]);
        if ($result['errors'] !== []) {
            return ['id' => $proposal->getID(), 'errors' => $result['errors'], 'values' => $values];
        }
        $proposal->update(['id' => $proposal->getID(), 'estimate_comment' => $comment]);

        return ['id' => $proposal->getID(), 'errors' => [], 'values' => $values];
    }

    /**
     * Request of the estimate from step 3: the note of the sales representative, the status
     */
    private static function requestEstimate(PresaleProposal $proposal, array $input): void
    {
        if ((int) $proposal->fields['pm_users_id'] <= 0) {
            Session::addMessageAfterRedirect(
                __('Choose a project manager declared in the configuration (step 2) to request the estimate.', 'manageentities'),
                false,
                ERROR,
            );
            return;
        }
        $proposal->update([
            'id'              => $proposal->getID(),
            'estimate_status' => PresaleProposal::ESTIMATE_REQUESTED,
            'estimate_brief'  => trim((string) ($input['estimate_brief'] ?? '')),
            'estimate_date'   => $_SESSION['glpi_currenttime'],
        ]);
        Session::addMessageAfterRedirect(sprintf(
            __('The estimate is requested from %s.', 'manageentities'),
            (string) $proposal->fields['pm_name'],
        ));
    }

    /**
     * Target of the actions of the last step and of the proposal form
     */
    public static function getActionUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/presaleproposal.form.php';
    }

    /**
     * Actions of the last step, of the proposal form and of the list: generate, won, lost,
     * reactivate, delete
     *
     * @param array $input $_POST
     */
    public static function handleAction(array $input): never
    {
        $proposal = self::loadProposal((int) ($input['id'] ?? 0));
        $id       = $proposal->getID();
        $status   = (int) $proposal->fields['status'];

        switch ($input['presale_action'] ?? '') {
            case 'generate':
                if (!self::isEditable($proposal)) {
                    throw new AccessDeniedHttpException();
                }
                $generator = new PresaleGenerator($proposal);
                $missing   = $generator->getMissingTemplates();
                if ($missing !== []) {
                    Session::addMessageAfterRedirect(
                        sprintf(
                            __('Upload these Word templates in the configuration (Pre-sales tab) first: %s.', 'manageentities'),
                            implode(', ', $missing),
                        ),
                        false,
                        ERROR,
                    );
                    Html::redirect(self::getFormUrl() . '?id=' . $id . '&step=' . self::STEP_GENERATE);
                }
                try {
                    $generator->generate();
                    $proposal->getFromDB($id);
                    \NotificationEvent::raiseEvent(NotificationTargetPresaleProposal::EVENT_GENERATED, $proposal);
                    Session::addMessageAfterRedirect(__('The proposal has been generated.', 'manageentities'));
                } catch (\Throwable $e) {
                    \Toolbox::logInFile('manageentities', 'Generation of the proposal ' . $id . ' failed: ' . $e->getMessage() . "
");
                    Session::addMessageAfterRedirect(
                        __('The generation of the proposal failed. Check the Word templates of the configuration.', 'manageentities'),
                        false,
                        ERROR,
                    );
                }
                Html::redirect(self::getFormUrl() . '?id=' . $id . '&step=' . self::STEP_GENERATE);

            case 'won':
                // Only a proposal generated at least once can be won: the customer signed a
                // document. A change after the generation made it a draft again: it is won on
                // its last generated version.
                if (!self::isEditable($proposal) || (int) $proposal->fields['version'] === 0) {
                    throw new AccessDeniedHttpException();
                }
                $proposal->update(['id' => $id, 'status' => PresaleProposal::STATUS_WON]);
                $proposal->getFromDB($id);
                \NotificationEvent::raiseEvent(NotificationTargetPresaleProposal::EVENT_WON, $proposal);
                Session::addMessageAfterRedirect(__('The proposal is won: create the customer from it.', 'manageentities'));
                // Next: the customer wizard, prefilled from the proposal
                Html::redirect(self::getCustomerWizardPath($id));

                // no break
            case 'lost':
                if (!self::isEditable($proposal)) {
                    throw new AccessDeniedHttpException();
                }
                $proposal->update([
                    'id'           => $id,
                    'status'       => PresaleProposal::STATUS_LOST,
                    'archive_date' => $_SESSION['glpi_currenttime'],
                ]);
                self::refreshClientArchive((int) $proposal->fields['plugin_manageentities_presaleclients_id']);
                $proposal->getFromDB($id);
                \NotificationEvent::raiseEvent(NotificationTargetPresaleProposal::EVENT_LOST, $proposal);
                Session::addMessageAfterRedirect(__('The proposal is lost: it is archived.', 'manageentities'));
                break;

            case 'reactivate':
                if ($status !== PresaleProposal::STATUS_LOST) {
                    throw new AccessDeniedHttpException();
                }
                // Back to a draft: the document has to be generated again
                $proposal->update([
                    'id'           => $id,
                    'status'       => PresaleProposal::STATUS_DRAFT,
                    'archive_date' => 'NULL',
                ]);
                self::refreshClientArchive((int) $proposal->fields['plugin_manageentities_presaleclients_id']);
                Session::addMessageAfterRedirect(__('The proposal is back to draft.', 'manageentities'));
                break;

            case 'duplicate':
                // Any proposal, archived ones included: a new draft for the same prospect
                if (!PresaleProposal::canCreate()) {
                    throw new AccessDeniedHttpException();
                }
                $copy = self::duplicateProposal($proposal);
                if ($copy === 0) {
                    Session::addMessageAfterRedirect(__('The proposal could not be duplicated.', 'manageentities'), false, ERROR);
                    break;
                }
                Session::addMessageAfterRedirect(__('The proposal has been duplicated: a new draft.', 'manageentities'));
                Html::redirect(self::getFormUrl() . '?id=' . $copy . '&step=' . self::STEP_ANNEXES);

                // no break
            case 'delete':
                // A proposal in progress, never a won one: it made a customer
                if (!self::isEditable($proposal) || !$proposal->can($id, PURGE)) {
                    throw new AccessDeniedHttpException();
                }
                $clients_id = (int) $proposal->fields['plugin_manageentities_presaleclients_id'];
                $proposal->delete(['id' => $id], true);
                self::removeUnusedClient($clients_id);
                Session::addMessageAfterRedirect(__('The proposal has been deleted.', 'manageentities'));
                Html::redirect(self::getFormUrl());

                // no break
            default:
                throw new AccessDeniedHttpException();
        }

        // Back to the list of the section when the action was made from there
        Html::redirect(!empty($input['back_to_home']) ? self::getFormUrl() : PresaleProposal::getFormURLWithID($id));
    }

    /**
     * New draft proposal copied from another one: same prospect, team, recipient, texts and
     * annexes with their lines and estimate. No reference, version, document, estimate status
     * or outcome. A prospect archived (all its proposals lost) is active again.
     *
     * @return int id of the copy, 0 on failure
     */
    private static function duplicateProposal(PresaleProposal $source): int
    {
        $fields = $source->fields;
        $input  = array_intersect_key($fields, array_flip([
            'entities_id', 'is_recursive', 'plugin_manageentities_presaleclients_id',
            'plugin_manageentities_presalecontacts_id', 'plugin_manageentities_presaleorigins_id', 'proposal_type', 'summary',
            'sales_users_id', 'sales_name', 'sales_role', 'sales_email', 'sales_phone',
            'pm_users_id', 'pm_name', 'pm_role', 'pm_email', 'pm_phone',
            'total_ht', 'total_vat', 'total_ttc',
        ]));
        $input += [
            'name'          => sprintf(__('%s (copy)', 'manageentities'), (string) $fields['name']),
            'status'        => PresaleProposal::STATUS_DRAFT,
            // Every step is filled: the copy opens on the annexes, then goes to its generation
            'step'          => self::STEP_GENERAL,
            'proposal_date' => date('Y-m-d'),
            'users_id'      => Session::getLoginUserID(),
        ];
        $copy = new PresaleProposal();
        if (!$copy->can(-1, CREATE, $input) || !($copies_id = (int) $copy->add($input))) {
            return 0;
        }

        $annex_link = new PresaleProposal_Annex();
        $line_link  = new PresaleAnnexLine();
        foreach ($annex_link->find(['plugin_manageentities_presaleproposals_id' => $source->getID()], ['rank', 'id']) as $annex) {
            $data = array_diff_key($annex, array_flip(['id']));
            $data['plugin_manageentities_presaleproposals_id'] = $copies_id;
            $annexes_id = (int) $annex_link->add($data);
            foreach ($line_link->find(['plugin_manageentities_presaleproposals_annexes_id' => $annex['id']], ['rank', 'id']) as $line) {
                $line_data = array_diff_key($line, array_flip(['id']));
                $line_data['plugin_manageentities_presaleproposals_annexes_id'] = $annexes_id;
                $line_link->add($line_data);
            }
        }
        self::refreshClientArchive((int) $fields['plugin_manageentities_presaleclients_id']);

        return $copies_id;
    }

    /**
     * A prospect left without proposal by a deletion goes with it, contacts included, unless it
     * is a customer already (made from a customer entity, or converted): it keeps its details
     */
    private static function removeUnusedClient(int $clients_id): void
    {
        $client = new PresaleClient();
        if (
            !$client->getFromDB($clients_id)
            || (int) $client->fields['converted_entities_id'] > 0
            || countElementsInTable(PresaleProposal::getTable(), ['plugin_manageentities_presaleclients_id' => $clients_id]) > 0
        ) {
            return;
        }
        if ($client->can($clients_id, PURGE)) {
            $client->delete(['id' => $clients_id], true);
        }
    }

    /**
     * A prospect is archived once all its proposals are lost, and back as soon as one is not
     */
    private static function refreshClientArchive(int $clients_id): void
    {
        $open = countElementsInTable(PresaleProposal::getTable(), [
            'plugin_manageentities_presaleclients_id' => $clients_id,
            'status'                                  => ['!=', PresaleProposal::STATUS_LOST],
        ]);
        $client = new PresaleClient();
        if ($client->getFromDB($clients_id)) {
            $client->update(['id' => $clients_id, 'is_archived' => $open === 0 ? 1 : 0]);
        }
    }
}
