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

use Migration;
use Notification;
use NotificationTarget;

/**
 * Notifications of the pre-sales proposals: generated, won, lost. Sent to the sales
 * representative, the project manager and the author of the proposal (custom targets, plus the
 * profiles and groups of the base class).
 *
 * Installed idempotently (install()): one template per event, with a French default
 * translation, and one notification per event, the won one active, the others inactive.
 */
class NotificationTargetPresaleProposal extends NotificationTarget
{
    public const EVENT_GENERATED = 'presale_generated';
    public const EVENT_WON       = 'presale_won';
    public const EVENT_LOST      = 'presale_lost';

    /** Custom targets (users of the proposal) */
    public const TARGET_SALES   = 4300;
    public const TARGET_PM      = 4301;
    public const TARGET_AUTHOR  = 4302;

    public function getEvents()
    {
        return [
            self::EVENT_GENERATED => __('Proposal generated', 'manageentities'),
            self::EVENT_WON       => __('Proposal won', 'manageentities'),
            self::EVENT_LOST      => __('Proposal lost', 'manageentities'),
        ];
    }

    public function addNotificationTargets($entity)
    {
        $this->addTarget(self::TARGET_SALES, __('Sales representative', 'manageentities'));
        $this->addTarget(self::TARGET_PM, _n('Project manager', 'Project managers', 1, 'manageentities'));
        $this->addTarget(self::TARGET_AUTHOR, __('Author', 'manageentities'));
        parent::addNotificationTargets($entity);
    }

    public function addSpecificTargets($data, $options)
    {
        if ((int) $data['type'] !== Notification::USER_TYPE) {
            return;
        }
        match ((int) $data['items_id']) {
            self::TARGET_SALES  => $this->addUserByField('sales_users_id'),
            self::TARGET_PM     => $this->addUserByField('pm_users_id'),
            self::TARGET_AUTHOR => $this->addUserByField('users_id'),
            default             => null,
        };
    }

    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;

        if (!$this->obj instanceof PresaleProposal) {
            return;
        }
        $fields = $this->obj->fields;
        $client = new PresaleClient();
        $client->getFromDB((int) $fields['plugin_manageentities_presaleclients_id']);
        $has_subscription = countElementsInTable(PresaleProposal_Annex::getTable(), [
            'plugin_manageentities_presaleproposals_id'   => $this->obj->getID(),
            'plugin_manageentities_subscriptionlevels_id' => ['>', 0],
        ]) > 0;

        $this->data['##presale.action##']       = $this->getEvents()[$event] ?? '';
        $this->data['##presale.name##']         = (string) $fields['name'];
        $this->data['##presale.reference##']    = (string) $fields['reference'];
        $this->data['##presale.client##']       = (string) ($client->fields['name'] ?? '');
        $this->data['##presale.date##']         = \Html::convDate((string) $fields['proposal_date']);
        $this->data['##presale.type##']         = (string) $fields['proposal_type'];
        $this->data['##presale.total_ht##']     = PresaleGenerator::money((float) $fields['total_ht']);
        $this->data['##presale.total_ttc##']    = PresaleGenerator::money((float) $fields['total_ttc']);
        $this->data['##presale.sales##']        = (string) $fields['sales_name'];
        $this->data['##presale.pm##']           = (string) $fields['pm_name'];
        $this->data['##presale.subscription##'] = $has_subscription ? __('Yes') : __('No');
        $this->data['##presale.url##']          = $this->formatURL(
            $options['additionnaloption']['usertype'] ?? '',
            PresaleProposal::class . '_' . $this->obj->getID(),
        );
        $this->data['##presale.document.url##'] = (int) $fields['documents_id'] > 0
            ? $CFG_GLPI['url_base'] . '/front/document.send.php?docid=' . (int) $fields['documents_id']
            : '';
        // Won: the customer wizard prefilled from the proposal
        $this->data['##presale.customer.url##'] = $CFG_GLPI['url_base'] . PresaleWizard::getCustomerWizardPath($this->obj->getID());

        foreach ($this->getTags() as $tag => $label) {
            $this->data['##lang.' . $tag . '##'] = $label;
        }
    }

    /**
     * @return array<string, string> tags of the templates and their label
     */
    public function getTags()
    {
        $tags = [
            'presale.name'         => __('Name'),
            'presale.reference'    => __('Reference', 'manageentities'),
            'presale.client'       => _n('Prospect', 'Prospects', 1, 'manageentities'),
            'presale.date'         => __('Date of the contract', 'manageentities'),
            'presale.type'         => __('Type of contract', 'manageentities'),
            'presale.total_ht'     => __('Total excluding VAT', 'manageentities'),
            'presale.total_ttc'    => __('Total incl. VAT', 'manageentities'),
            'presale.sales'        => __('Sales representative', 'manageentities'),
            'presale.pm'           => _n('Project manager', 'Project managers', 1, 'manageentities'),
            'presale.subscription' => __('Publisher subscription', 'manageentities'),
            'presale.url'          => __('URL of the proposal', 'manageentities'),
            'presale.document.url' => __('URL of the document', 'manageentities'),
            'presale.customer.url' => __('URL of the customer wizard', 'manageentities'),
            'presale.action'       => __('Event'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);

        return $tags;
    }

    /**
     * Templates, French translations and notifications of the three events, created when missing
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        $content = [
            self::EVENT_GENERATED => [
                'Proposition ##presale.reference## générée - ##presale.client##',
                "La proposition ##presale.name## (##presale.reference##) pour ##presale.client## est générée.\n\n"
                . "Type de contrat : ##presale.type##\nTotal HT : ##presale.total_ht##\nTotal TTC : ##presale.total_ttc##\n\n"
                . "Document : ##presale.document.url##\nProposition : ##presale.url##",
                false,
            ],
            self::EVENT_WON => [
                'Proposition ##presale.reference## gagnée - ##presale.client##',
                "La proposition ##presale.name## (##presale.reference##) pour ##presale.client## est gagnée.\n\n"
                . "Type de contrat : ##presale.type##\nTotal HT : ##presale.total_ht##\n"
                . "Commercial : ##presale.sales##\nChef de projet : ##presale.pm##\nSouscription éditeur : ##presale.subscription##\n\n"
                . "À faire : créer le client, son contrat et, s'il y a une annexe de souscription, sa souscription éditeur :\n"
                . "##presale.customer.url##\n\nProposition : ##presale.url##",
                true,
            ],
            self::EVENT_LOST => [
                'Proposition ##presale.reference## perdue - ##presale.client##',
                "La proposition ##presale.name## (##presale.reference##) pour ##presale.client## est perdue : elle est archivée.\n\n"
                . "Proposition : ##presale.url##",
                false,
            ],
        ];

        foreach ($content as $event => [$subject, $text, $active]) {
            $name     = 'Pre-sales - ' . $event;
            $template = $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => PresaleProposal::class, 'name' => $name],
            ])->current();
            if ($template === null) {
                $DB->insert('glpi_notificationtemplates', ['name' => $name, 'itemtype' => PresaleProposal::class]);
                $templates_id = (int) $DB->insertId();
                $DB->insert('glpi_notificationtemplatetranslations', [
                    'notificationtemplates_id' => $templates_id,
                    'language'                 => '',
                    'subject'                  => $subject,
                    'content_text'             => $text,
                    'content_html'             => self::toStoredHtml($text),
                ]);
            } else {
                $templates_id = (int) $template['id'];
                // The first versions escaped the apostrophes twice: "s&#039;il" in the mails
                $DB->update(
                    'glpi_notificationtemplatetranslations',
                    ['content_html' => self::toStoredHtml($text)],
                    [
                        'notificationtemplates_id' => $templates_id,
                        'language'                 => '',
                        'content_html'             => htmlescape('<p>' . nl2br(htmlescape($text)) . '</p>'),
                    ],
                );
            }

            $exists = countElementsInTable('glpi_notifications', ['itemtype' => PresaleProposal::class, 'event' => $event]);
            if ($exists > 0) {
                continue;
            }
            $DB->insert('glpi_notifications', [
                'name'         => $name,
                'entities_id'  => 0,
                'itemtype'     => PresaleProposal::class,
                'event'        => $event,
                'is_recursive' => 1,
                'is_active'    => $active ? 1 : 0,
            ]);
            $notifications_id = (int) $DB->insertId();
            $DB->insert('glpi_notifications_notificationtemplates', [
                'notifications_id'         => $notifications_id,
                'mode'                     => 'mailing',
                'notificationtemplates_id' => $templates_id,
            ]);
            // Sales representative and project manager by default
            foreach ([self::TARGET_SALES, self::TARGET_PM] as $target) {
                $DB->insert('glpi_notificationtargets', [
                    'notifications_id' => $notifications_id,
                    'type'             => Notification::USER_TYPE,
                    'items_id'         => $target,
                ]);
            }
        }
    }

    /**
     * HTML body of a template as GLPI stores it (escaped once), from its plain text: only &, < and >
     * are escaped in the text, the apostrophes stay as they are
     */
    private static function toStoredHtml(string $text): string
    {
        return htmlescape('<p>' . nl2br(htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')) . '</p>');
    }

    public static function uninstall(): void
    {
        global $DB;

        foreach ($DB->request(['FROM' => 'glpi_notifications', 'WHERE' => ['itemtype' => PresaleProposal::class]]) as $data) {
            (new Notification())->delete(['id' => $data['id']], true);
        }
        foreach ($DB->request(['FROM' => 'glpi_notificationtemplates', 'WHERE' => ['itemtype' => PresaleProposal::class]]) as $data) {
            (new \NotificationTemplate())->delete(['id' => $data['id']], true);
        }
    }
}
