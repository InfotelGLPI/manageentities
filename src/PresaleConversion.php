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

use Document;
use Document_Item;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Session;

/**
 * Won proposal → customer (§7.1 of docs/avant-vente.md).
 *
 * prefill() starts the customer wizard (WizardController) from the proposal: a new entity from
 * the prospect, or a new contract on the entity of an existing customer; contacts, publisher
 * subscription and contract are prefilled, every step stays editable. The proposal id stays in
 * the wizard session (presale_proposals_id).
 *
 * afterCommit() runs once the wizard has written the customer: the prospect and its contacts
 * remember what they became, the sales representative and the project manager are linked to
 * the entity, the logo is copied (converted to JPEG: the entity logos are JPEG only) and the
 * generated document is linked to the contract.
 */
class PresaleConversion
{
    /** Key of the wizard session holding the proposal */
    public const SESSION_PROPOSAL = 'presale_proposals_id';

    /**
     * Won proposal the user can read, null otherwise
     */
    public static function getWonProposal(int $id): ?PresaleProposal
    {
        $proposal = new PresaleProposal();
        if (
            !$proposal->getFromDB($id)
            || !$proposal->can($id, READ)
            || (int) $proposal->fields['status'] !== PresaleProposal::STATUS_WON
        ) {
            return null;
        }

        return $proposal;
    }

    /**
     * Wizard session started from the proposal
     *
     * @param array $session default session of the wizard (WizardController::buildDefaultSession())
     */
    public static function prefill(PresaleProposal $proposal, array $session): array
    {
        $client = new PresaleClient();
        $client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id']);
        $entities_id = (int) $client->fields['converted_entities_id'];

        $session[self::SESSION_PROPOSAL] = $proposal->getID();
        $session['step']                 = 1;

        if ($entities_id > 0) {
            // Existing customer: its entity, whose contacts already exist. Out of reach, the
            // wizard is refused rather than creating the customer a second time.
            if (!Session::haveAccessToEntity($entities_id)) {
                return [];
            }
            $session['wizard_mode'] = 'existing_entity';
            $session['entities_id'] = $entities_id;
        } else {
            $session['wizard_mode'] = 'new_entity';
            $session['entity_data'] = self::entityData($client);
            $session['contacts_data'] = self::contactsData($client);
        }

        // Subscription annexes: the subscription of the customer is created, or renewed when it
        // has one already; several annexes (cloud and on-premise), the user picks the one
        $choices = self::subscriptionChoices($proposal, $entities_id);
        if ($choices !== []) {
            $session['subscription_choices'] = $choices;
            $session['subscription_data']    = reset($choices)['data'];
            // Only subscription annexes: no contract, the wizard ends after the subscription
            if (!self::hasRegularAnnex($proposal)) {
                $session['subscription_only'] = 1;
            }
        }
        $session['contract_prefill']   = self::contractData($proposal);
        // Kept for the whole wizard (contract_prefill is consumed by the first display of the
        // contract step): a contract template loaded later does not overwrite these fields
        $session['proposal_contract']  = $session['contract_prefill'];
        $session['interventions_data'] = self::interventionsData($proposal);

        return $session;
    }

    /**
     * Subscription of an existing customer, renewed instead of created (one per entity)
     */
    public static function getExistingSubscriptionId(int $entities_id): int
    {
        if ($entities_id <= 0) {
            return 0;
        }

        return (int) (EditorSubscription::getForEntity($entities_id)['id'] ?? 0);
    }

    /**
     * Whether the proposal holds an annex that is not a publisher subscription (a contract to make)
     */
    public static function hasRegularAnnex(PresaleProposal $proposal): bool
    {
        return countElementsInTable(PresaleProposal_Annex::getTable(), [
            'plugin_manageentities_presaleproposals_id'   => $proposal->getID(),
            'plugin_manageentities_subscriptionlevels_id' => 0,
        ]) > 0;
    }

    private static function entityData(PresaleClient $client): array
    {
        $fields = $client->fields;
        $data   = ['entities_id' => (int) (Config::getInstance()->fields['wizard_default_entities_id'] ?? 0)];
        foreach (['name', 'comment', 'phonenumber', 'email', 'website', 'address', 'postcode', 'town', 'state', 'country'] as $field) {
            $data[$field] = (string) ($fields[$field] ?? '');
        }
        $data['fax'] = '';

        return $data;
    }

    /**
     * Contacts of the prospect, the main one as manager. Indexes from 1, as the wizard.
     */
    private static function contactsData(PresaleClient $client): array
    {
        $contacts = [];
        $idx      = 1;
        foreach ((new PresaleContact())->find(
            ['plugin_manageentities_presaleclients_id' => $client->getID()],
            ['is_default DESC', 'id'],
        ) as $row) {
            $contacts[$idx++] = [
                'name'            => (string) $row['name'],
                'firstname'       => (string) $row['firstname'],
                'phone'           => (string) $row['phone'],
                'phone2'          => '',
                'mobile'          => (string) $row['mobile'],
                'fax'             => '',
                'email'           => (string) $row['email'],
                'address'         => '',
                'postcode'        => '',
                'town'            => '',
                'state'           => '',
                'country'         => '',
                // Their role at the customer, as typed in the proposal
                'comment'         => (string) $row['role'],
                'is_recursive'    => 0,
                'contacttypes_id' => 0,
                'usertitles_id'   => 0,
                'is_manager'      => (int) $row['is_default'],
                // Back link, kept by the wizard session for afterCommit()
                'presale_contacts_id' => (int) $row['id'],
            ];
        }

        return $contacts;
    }

    /**
     * Publisher subscription of each subscription annex of the proposal: its level, cloud or
     * not, for a year. A new subscription starts on the date of the proposal; the existing one
     * of a customer is renewed: its name and account kept, from its end when still running.
     *
     * @return array<int, array{label: string, data: array}> by id of the annex, in their order
     */
    public static function subscriptionChoices(PresaleProposal $proposal, int $entities_id = 0): array
    {
        if (!Config::useEditorSubscriptions()) {
            return [];
        }
        $annexes = (new PresaleProposal_Annex())->find([
            'plugin_manageentities_presaleproposals_id'   => $proposal->getID(),
            'plugin_manageentities_subscriptionlevels_id' => ['>', 0],
        ], ['rank', 'id']);
        if ($annexes === []) {
            return [];
        }

        $existing = $entities_id > 0 ? EditorSubscription::getForEntity($entities_id) : [];
        $begin    = (string) ($proposal->fields['proposal_date'] ?: date('Y-m-d'));
        // Renewal: from the day after the end of the current subscription, when later
        $end = substr((string) ($existing['end_date'] ?? ''), 0, 10);
        if ($end !== '' && $end >= $begin) {
            $begin = date('Y-m-d', (int) strtotime($end . ' +1 day'));
        }

        $choices = [];
        foreach ($annexes as $annex) {
            $level = (int) $annex['plugin_manageentities_subscriptionlevels_id'];
            $cloud = (int) $annex['cloud_client'];
            $choices[(int) $annex['id']] = [
                'label' => sprintf(
                    '%s (%s - %s)',
                    (string) $annex['title'],
                    $cloud ? __('Cloud', 'manageentities') : __('Editor', 'manageentities'),
                    \Dropdown::getDropdownName(SubscriptionLevel::getTable(), $level),
                ),
                'data'  => [
                    'name'                                        => (string) ($existing['name'] ?? ''),
                    'customer_account_id'                         => (string) ($existing['customer_account_id'] ?? ''),
                    'active_editor_suscription'                   => 1,
                    'cloud_client'                                => $cloud,
                    'internet_publication'                        => $cloud ?: (int) ($existing['internet_publication'] ?? 0),
                    'plugin_manageentities_subscriptionlevels_id' => $level,
                    'begin_date'                                  => $begin,
                    'end_date'                                    => date('Y-m-d', (int) strtotime($begin . ' +1 year -1 day')),
                    'comment'                                     => (string) $annex['title'],
                ],
            ];
        }

        return $choices;
    }

    /**
     * Periods of contract of the regular annexes: one per annex, its days at its daily rate.
     * The wizard takes a single rate per period: the rate of the lines when they share it,
     * else their average (amount / days), rounded to the cent.
     *
     * @return array<int, array{fields: array, criprices: list<array>, stakeholders: array}> indexed from 1
     */
    public static function interventionsData(PresaleProposal $proposal): array
    {
        $config   = Config::getInstance();
        $begin    = (string) ($proposal->fields['proposal_date'] ?: date('Y-m-d'));
        $end      = date('Y-m-d', (int) strtotime($begin . ' +12 months'));
        $lines    = new PresaleAnnexLine();
        $periods  = [];
        $idx      = 1;
        foreach ((new PresaleProposal_Annex())->find([
            'plugin_manageentities_presaleproposals_id'   => $proposal->getID(),
            'plugin_manageentities_subscriptionlevels_id' => 0,
        ], ['rank', 'id']) as $annex) {
            $days   = 0.0;
            $amount = 0.0;
            foreach ($lines->find(['plugin_manageentities_presaleproposals_annexes_id' => $annex['id']]) as $line) {
                $days   += (float) $line['quantity'];
                $amount += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $fields = [
                'name'                                    => (string) $annex['title'],
                'plugin_manageentities_contractstates_id' => (int) ($config->fields['wizard_contractstate_id'] ?? 0),
                'begin_date'                              => $begin,
                'end_date'                                => $end,
                'nbday'                                   => round($days, 2),
                'report'                                  => 0.0,
                'charged'                                 => 0,
                'comment'                                 => '',
            ];
            if ((int) ($config->fields['wizard_contract_type'] ?? 0) > 0) {
                $fields['contract_type'] = (int) $config->fields['wizard_contract_type'];
            }
            $periods[$idx++] = [
                'fields'       => $fields,
                'criprices'    => $days > 0 ? [[
                    'plugin_manageentities_critypes_id' => (int) ($config->fields['wizard_critype_id'] ?? 0),
                    'price'                             => round($amount / $days, 2),
                    'is_default'                        => 1,
                ]] : [],
                'stakeholders' => [],
            ];
        }

        return $periods;
    }

    private static function contractData(PresaleProposal $proposal): array
    {
        $fields = $proposal->fields;

        return [
            'name'       => (string) ($fields['proposal_type'] ?: $fields['name']),
            'num'        => (string) $fields['reference'],
            'begin_date' => (string) ($fields['proposal_date'] ?: date('Y-m-d')),
            'comment'    => sprintf(__('From the proposal %s', 'manageentities'), (string) ($fields['reference'] ?: $fields['name'])),
        ];
    }

    /**
     * After the wizard wrote the customer: links back, people of the customer, logo, document
     *
     * @param array    $session      wizard session, before it is cleared
     * @param array<int|string, int> $contact_ids contacts created, by index of contacts_data
     */
    public static function afterCommit(array $session, int $entities_id, array $contact_ids, int $contracts_id): void
    {
        $proposals_id = (int) ($session[self::SESSION_PROPOSAL] ?? 0);
        $proposal     = new PresaleProposal();
        if ($proposals_id <= 0 || $entities_id <= 0 || !$proposal->getFromDB($proposals_id)) {
            return;
        }
        $client = new PresaleClient();
        if (!$client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])) {
            return;
        }

        // The proposal is converted: it leaves the won proposals waiting for their customer,
        // whatever the wizard made (a subscription only makes no contract)
        $proposal->update(['id' => $proposals_id, 'conversion_date' => $_SESSION['glpi_currenttime']]);

        // The prospect became this customer, its contacts these contacts
        $client->update(['id' => $client->getID(), 'converted_entities_id' => $entities_id]);
        $link = new PresaleContact();
        foreach ($contact_ids as $idx => $contacts_id) {
            $presale_contacts_id = (int) ($session['contacts_data'][$idx]['presale_contacts_id'] ?? 0);
            if ($presale_contacts_id > 0) {
                $link->update(['id' => $presale_contacts_id, 'contacts_id' => (int) $contacts_id]);
            }
        }

        // Sales representative and project manager of the proposal, in the administrative data
        foreach ([
            BusinessContact::class => (int) $proposal->fields['sales_users_id'],
            ProjectManager::class  => (int) $proposal->fields['pm_users_id'],
        ] as $class => $users_id) {
            if ($users_id <= 0 || countElementsInTable($class::getTable(), ['users_id' => $users_id, 'entities_id' => $entities_id]) > 0) {
                continue;
            }
            $item  = new $class();
            $input = ['users_id' => $users_id, 'entities_id' => $entities_id];
            if ($item->can(-1, CREATE, $input)) {
                $item->add($input);
            }
        }

        if ($session['wizard_mode'] !== 'existing_entity') {
            self::copyLogo($client, $entities_id);
        }

        // The signed proposal goes with its contract
        $documents_id = (int) $proposal->fields['documents_id'];
        if ($contracts_id > 0 && $documents_id > 0
            && countElementsInTable(Document_Item::getTable(), [
                'documents_id' => $documents_id,
                'itemtype'     => \Contract::class,
                'items_id'     => $contracts_id,
            ]) === 0) {
            (new Document_Item())->add([
                'documents_id' => $documents_id,
                'itemtype'     => \Contract::class,
                'items_id'     => $contracts_id,
                'entities_id'  => $entities_id,
            ]);
        }

        Session::addMessageAfterRedirect(sprintf(
            $session['wizard_mode'] === 'existing_entity'
                ? __('The customer has been updated from the proposal %s.', 'manageentities')
                : __('The customer has been created from the proposal %s.', 'manageentities'),
            (string) ($proposal->fields['reference'] ?: $proposal->fields['name']),
        ));
    }

    /**
     * The logo of the prospect becomes the logo of the entity. EntityLogo takes JPEG only: a PNG
     * is converted (white under its transparency). The logo of the prospect is left as it is.
     */
    private static function copyLogo(PresaleClient $client, int $entities_id): void
    {
        $document = new Document();
        if (
            (int) $client->fields['logo_documents_id'] <= 0
            || !$document->getFromDB((int) $client->fields['logo_documents_id'])
        ) {
            return;
        }
        $source = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        $jpeg   = self::toJpeg($source);
        if ($jpeg === null) {
            Session::addMessageAfterRedirect(__('The logo of the prospect could not be copied to the customer.', 'manageentities'), false, WARNING);
            return;
        }

        // EntityLogo::addLogo() takes a file of GLPI_TMP_DIR, by its name
        $name = 'presale_logo_' . bin2hex(random_bytes(6)) . '.jpg';
        if (!rename($jpeg, GLPI_TMP_DIR . '/' . $name)) {
            @unlink($jpeg);
            return;
        }
        $logo = new EntityLogo();
        if ($logo->can(-1, CREATE)) {
            $logo->addLogo(['entities_id' => $entities_id, '_filename' => [$name]]);
        }
        @unlink(GLPI_TMP_DIR . '/' . $name);
    }

    /**
     * JPEG copy of an image (temporary file), null when it cannot be read
     */
    public static function toJpeg(string $file): ?string
    {
        $info = is_file($file) ? @getimagesize($file) : false;
        if ($info === false) {
            return null;
        }
        $target = tempnam(GLPI_TMP_DIR, 'logo');
        if ($target === false) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            return copy($file, $target) ? $target : null;
        }
        $image = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file) : false;
        if ($image === false) {
            @unlink($target);
            return null;
        }
        // White background under the transparency of the PNG
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        $saved = imagejpeg($canvas, $target, 90);

        return $saved ? $target : null;
    }

    /**
     * Whether the customer was already made from the proposal: the customer wizard started from
     * it went through (afterCommit() sets its conversion date)
     */
    public static function isConverted(PresaleProposal $proposal): bool
    {
        return !empty($proposal->fields['conversion_date']);
    }

    /**
     * Conversion date of the won proposals converted before the column existed, from what
     * afterCommit() left: the document linked to a contract, or, without document, the prospect
     * that became a customer. Run once, when the column is added.
     */
    public static function initConversionDates(): void
    {
        global $DB;

        $proposals = PresaleProposal::getTable();
        $client    = new PresaleClient();
        foreach (
            $DB->request([
                'SELECT' => ['id', 'documents_id', 'plugin_manageentities_presaleclients_id', 'date_mod'],
                'FROM'   => $proposals,
                'WHERE'  => ['status' => PresaleProposal::STATUS_WON],
            ]) as $data
        ) {
            // The prospect is only looked at without document: a proposal made for an existing
            // customer has its entity from the start, converted or not
            if ((int) $data['documents_id'] > 0) {
                $converted = countElementsInTable(Document_Item::getTable(), [
                    'documents_id' => (int) $data['documents_id'],
                    'itemtype'     => \Contract::class,
                ]) > 0;
            } else {
                $converted = $client->getFromDB((int) $data['plugin_manageentities_presaleclients_id'])
                    && (int) $client->fields['converted_entities_id'] > 0;
            }
            if ($converted) {
                // Direct update: no history nor date_mod change for a data migration
                $DB->update($proposals, ['conversion_date' => $data['date_mod']], ['id' => (int) $data['id']]);
            }
        }
    }

    /**
     * Won proposals whose customer is still to be made: the customer wizard was left before its
     * end, or not started yet
     *
     * @param array $criteria extra criteria, on the proposals or their prospect
     *
     * @return list<array{id: int, url: string, name: string, client: string, client_url: string,
     *                    date_mod: string, total_ht: float, customer_url: string}>
     */
    public static function getProposalsToConvert(array $criteria = []): array
    {
        global $DB;

        $proposals = PresaleProposal::getTable();
        $clients   = PresaleClient::getTable();
        $can_convert = Session::haveRight(Contract::$rightname, UPDATE);

        $list = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    $proposals . '.id',
                    $proposals . '.name',
                    $proposals . '.total_ht',
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
                    $proposals . '.status'          => PresaleProposal::STATUS_WON,
                    $proposals . '.conversion_date' => null,
                ] + $criteria + getEntitiesRestrictCriteria($proposals, '', '', true),
                'ORDERBY'   => [$proposals . '.date_mod DESC'],
            ]) as $data
        ) {
            $list[] = [
                'id'           => (int) $data['id'],
                'url'          => PresaleProposal::getFormURLWithID((int) $data['id']),
                'name'         => (string) ($data['name'] ?: $data['client_name']),
                'client'       => (string) $data['client_name'],
                'client_url'   => PresaleClient::getFormURLWithID((int) $data['clients_id']),
                'date_mod'     => (string) $data['date_mod'],
                'total_ht'     => (float) $data['total_ht'],
                'customer_url' => $can_convert ? PresaleWizard::getCustomerWizardPath((int) $data['id']) : '',
            ];
        }

        return $list;
    }

    /**
     * Whether a contact of a prospect belongs to the proposal the wizard was started from
     */
    public static function isContactOf(array $session, int $presale_contacts_id): bool
    {
        $proposal = new PresaleProposal();
        $contact  = new PresaleContact();

        return (int) ($session[self::SESSION_PROPOSAL] ?? 0) > 0
            && $proposal->getFromDB((int) $session[self::SESSION_PROPOSAL])
            && $contact->getFromDB($presale_contacts_id)
            && (int) $contact->fields['plugin_manageentities_presaleclients_id']
                === (int) $proposal->fields['plugin_manageentities_presaleclients_id'];
    }

    /**
     * Details of the entity compared with the proposal (§ administrative data)
     *
     * @return array<string, string> field => label
     */
    private static function getEntityFieldLabels(): array
    {
        return [
            'address'     => __('Address'),
            'postcode'    => __('Postal code'),
            'town'        => __('City'),
            'state'       => _n('State', 'States', 1),
            'country'     => __('Country'),
            'phonenumber' => __('Phone'),
            'email'       => _n('Email', 'Emails', 1),
            'website'     => __('Website'),
        ];
    }

    /**
     * Contact fields compared with the proposal: GLPI contact field => prospect contact field
     *
     * @return array<string, string>
     */
    private static function getContactFieldMap(): array
    {
        return [
            'name'      => 'name',
            'firstname' => 'firstname',
            'email'     => 'email',
            'phone'     => 'phone',
            'mobile'    => 'mobile',
            'comment'   => 'role',
        ];
    }

    /**
     * Differences between the won proposal of an existing customer and its entity: the
     * administrative details and the contacts typed in the proposal (new or changed). Each one
     * is offered by step 1 of the wizard, unchecked, and applied by applyCustomerChanges().
     *
     * @return array{
     *     entity: list<array{field: string, label: string, current: string, proposed: string}>,
     *     contacts: list<array{presale_contacts_id: int, contacts_id: int, label: string,
     *                          changes: list<array{label: string, current: string, proposed: string}>}>
     * }
     */
    public static function getCustomerChanges(array $session, int $entities_id): array
    {
        $changes  = ['entity' => [], 'contacts' => []];
        $proposal = new PresaleProposal();
        $client   = new PresaleClient();
        $entity   = new \Entity();
        if (
            (int) ($session[self::SESSION_PROPOSAL] ?? 0) <= 0
            || ($session['wizard_mode'] ?? '') !== 'existing_entity'
            || $entities_id <= 0
            || !$proposal->getFromDB((int) $session[self::SESSION_PROPOSAL])
            || !$client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])
            || !$entity->getFromDB($entities_id)
        ) {
            return $changes;
        }

        // Administrative details, only when typed in the proposal: an empty field is no change
        foreach (self::getEntityFieldLabels() as $field => $label) {
            $proposed = trim((string) ($client->fields[$field] ?? ''));
            $current  = trim((string) ($entity->fields[$field] ?? ''));
            if ($proposed !== '' && $proposed !== $current) {
                $changes['entity'][] = ['field' => $field, 'label' => $label, 'current' => $current, 'proposed' => $proposed];
            }
        }

        // Contacts of the proposal: a new one (no GLPI contact yet, or no longer reachable), or
        // one whose details differ from its GLPI contact
        $labels = [
            'name'      => __('Last name'),
            'firstname' => __('First name'),
            'email'     => _n('Email', 'Emails', 1),
            'phone'     => __('Phone'),
            'mobile'    => __('Mobile phone'),
            'comment'   => __('Role', 'manageentities'),
        ];
        foreach ((new PresaleContact())->find(
            ['plugin_manageentities_presaleclients_id' => $client->getID()],
            ['is_default DESC', 'id'],
        ) as $row) {
            $label   = trim($row['firstname'] . ' ' . $row['name']);
            $contact = new \Contact();
            $contacts_id = (int) $row['contacts_id'];
            if ($contacts_id <= 0 || !$contact->getFromDB($contacts_id) || $contact->fields['is_deleted']) {
                $changes['contacts'][] = [
                    'presale_contacts_id' => (int) $row['id'],
                    'contacts_id'         => 0,
                    'label'               => $label,
                    'changes'             => [],
                ];
                continue;
            }
            $diff = [];
            foreach (self::getContactFieldMap() as $glpi_field => $presale_field) {
                $proposed = trim((string) $row[$presale_field]);
                $current  = trim((string) $contact->fields[$glpi_field]);
                if ($proposed !== '' && $proposed !== $current) {
                    $diff[] = ['label' => $labels[$glpi_field], 'current' => $current, 'proposed' => $proposed];
                }
            }
            if ($diff !== []) {
                $changes['contacts'][] = [
                    'presale_contacts_id' => (int) $row['id'],
                    'contacts_id'         => $contacts_id,
                    'label'               => $label,
                    'changes'             => $diff,
                ];
            }
        }

        return $changes;
    }

    /**
     * Changes of the customer kept by step 1, the ones posted checked among getCustomerChanges()
     *
     * @param array $input posted step 1: apply_entity[field] and apply_contacts[presale_contacts_id]
     *
     * @return array{entity: list<string>, contacts: list<int>}
     */
    public static function readCustomerChanges(array $session, int $entities_id, array $input): array
    {
        $available = self::getCustomerChanges($session, $entities_id);
        $entity    = array_column($available['entity'], 'field');
        $contacts  = array_column($available['contacts'], 'presale_contacts_id');

        return [
            'entity'   => array_values(array_intersect($entity, array_keys(array_filter((array) ($input['apply_entity'] ?? []))))),
            'contacts' => array_values(array_intersect($contacts, array_map('intval', array_keys(array_filter((array) ($input['apply_contacts'] ?? [])))))),
        ];
    }

    /**
     * Applies the changes kept at step 1 (customer_changes of the session) to the existing
     * customer: its administrative details, and its contacts created or updated from the
     * proposal. Each write checks the core right of the object.
     */
    public static function applyCustomerChanges(array $session, int $entities_id): void
    {
        $kept = $session['customer_changes'] ?? [];
        if (($kept['entity'] ?? []) === [] && ($kept['contacts'] ?? []) === []) {
            return;
        }
        $proposal = new PresaleProposal();
        $client   = new PresaleClient();
        if (
            !$proposal->getFromDB((int) ($session[self::SESSION_PROPOSAL] ?? 0))
            || !$client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])
        ) {
            return;
        }

        $entity_input = ['id' => $entities_id];
        foreach (array_intersect($kept['entity'] ?? [], array_keys(self::getEntityFieldLabels())) as $field) {
            $entity_input[$field] = trim((string) $client->fields[$field]);
        }
        $entity = new \Entity();
        if (count($entity_input) > 1) {
            if (!$entity->can($entities_id, UPDATE)) {
                throw new AccessDeniedHttpException();
            }
            $entity->update($entity_input);
        }

        $link = new PresaleContact();
        foreach ($kept['contacts'] ?? [] as $presale_contacts_id) {
            if (
                !$link->getFromDB((int) $presale_contacts_id)
                || (int) $link->fields['plugin_manageentities_presaleclients_id'] !== $client->getID()
            ) {
                continue;
            }
            $input = [];
            foreach (self::getContactFieldMap() as $glpi_field => $presale_field) {
                $value = trim((string) $link->fields[$presale_field]);
                if ($value !== '') {
                    $input[$glpi_field] = $value;
                }
            }

            $contact     = new \Contact();
            $contacts_id = (int) $link->fields['contacts_id'];
            if ($contacts_id > 0 && $contact->getFromDB($contacts_id) && !$contact->fields['is_deleted']) {
                if (!$contact->can($contacts_id, UPDATE)) {
                    throw new AccessDeniedHttpException();
                }
                $contact->update(['id' => $contacts_id] + $input);
                continue;
            }

            $input['entities_id'] = $entities_id;
            if (!$contact->can(-1, CREATE, $input)) {
                throw new AccessDeniedHttpException();
            }
            $contacts_id = (int) $contact->add($input);
            if ($contacts_id > 0) {
                // Linked to the entity (Contacts card), never as its manager: the current one stays
                (new Contact())->add(['contacts_id' => $contacts_id, 'entities_id' => $entities_id, 'is_default' => 0]);
                $link->update(['id' => $link->getID(), 'contacts_id' => $contacts_id]);
            }
        }
    }

    /**
     * Banner of the wizard when it is started from a proposal
     *
     * @return array{name: string, url: string}|null
     */
    public static function getBanner(array $session): ?array
    {
        $proposal = new PresaleProposal();
        $id       = (int) ($session[self::SESSION_PROPOSAL] ?? 0);
        if ($id <= 0 || !$proposal->getFromDB($id)) {
            return null;
        }

        return [
            'name' => (string) ($proposal->fields['reference'] ?: $proposal->fields['name']),
            'url'  => PresaleProposal::getFormURLWithID($id),
        ];
    }
}
