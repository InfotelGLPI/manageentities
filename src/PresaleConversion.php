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
     * Whether the customer was already made from the proposal: its document is linked to a
     * contract (afterCommit()), or, without document, its prospect became a customer
     */
    public static function isConverted(PresaleProposal $proposal): bool
    {
        $documents_id = (int) $proposal->fields['documents_id'];
        if ($documents_id > 0) {
            return countElementsInTable(Document_Item::getTable(), [
                'documents_id' => $documents_id,
                'itemtype'     => \Contract::class,
            ]) > 0;
        }
        $client = new PresaleClient();

        return $client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])
            && (int) $client->fields['converted_entities_id'] > 0;
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
