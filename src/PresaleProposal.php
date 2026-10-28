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

use CommonDBTM;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Migration;

/**
 * Financial proposal made to a prospect (created at step 1 of the wizard, completed by the next ones).
 *
 * The sales representative and the project manager are copied (name, role, email, phone) at
 * the time of the proposal: the generated document must not change when a user is updated.
 */
class PresaleProposal extends CommonDBTM
{
    public static string $rightname = 'plugin_manageentities_presale';

    public bool $dohistory = true;

    public const STATUS_DRAFT     = 1;
    public const STATUS_GENERATED = 2;
    public const STATUS_WON       = 3;
    public const STATUS_LOST      = 4;
    /** Generated and sent to the prospect, waiting for its answer */
    public const STATUS_SENT      = 5;

    /** Estimate by the project manager (estimate_status) */
    public const ESTIMATE_NONE      = 0;
    public const ESTIMATE_REQUESTED = 1;
    public const ESTIMATE_VALIDATED = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Financial proposal', 'Financial proposals', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-file-dollar';
    }

    /**
     * @return array<int, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT     => __('Draft', 'manageentities'),
            self::STATUS_GENERATED => __('Generated', 'manageentities'),
            self::STATUS_SENT      => __('Sent', 'manageentities'),
            self::STATUS_WON       => __('Won', 'manageentities'),
            self::STATUS_LOST      => __('Lost (archived)', 'manageentities'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getEstimateStatuses(): array
    {
        return [
            self::ESTIMATE_NONE      => __('Not requested', 'manageentities'),
            self::ESTIMATE_REQUESTED => __('Estimate requested', 'manageentities'),
            self::ESTIMATE_VALIDATED => __('Estimate validated', 'manageentities'),
        ];
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            PresaleProposal_Annex::class,
        ]);
    }

    /**
     * Statuses still in progress, shown by default in the list
     *
     * @return int[]
     */
    public static function getOpenStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_GENERATED, self::STATUS_SENT];
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => PresaleWizard::getFormUrl() . '?step=' . PresaleWizard::STEP_CLIENT,
            ],
        ];
    }

    /**
     * The archived (lost) and won proposals are hidden by default
     */
    public static function getDefaultSearchRequest(): array
    {
        return [
            'criteria' => [
                [
                    'field'      => 5, // Status
                    'searchtype' => 'equals',
                    'value'      => 'open',
                ],
            ],
            'sort'     => 19, // Last update
            'order'    => 'DESC',
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '2',
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];
        $tab[] = [
            'id'            => '3',
            'table'         => $this->getTable(),
            'field'         => 'reference',
            'name'          => __('Reference', 'manageentities'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '4',
            'table'         => PresaleClient::getTable(),
            'field'         => 'name',
            'name'          => _n('Prospect', 'Prospects', 1, 'manageentities'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '5',
            'table'         => $this->getTable(),
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '6',
            'table'         => $this->getTable(),
            'field'         => 'proposal_type',
            'name'          => __('Type of contract', 'manageentities'),
            'datatype'      => 'string',
        ];
        $tab[] = [
            'id'            => '7',
            'table'         => $this->getTable(),
            'field'         => 'proposal_date',
            'name'          => __('Date of the contract', 'manageentities'),
            'datatype'      => 'date',
        ];
        $tab[] = [
            'id'            => '8',
            'table'         => $this->getTable(),
            'field'         => 'sales_name',
            'name'          => __('Sales representative', 'manageentities'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '9',
            'table'         => $this->getTable(),
            'field'         => 'pm_name',
            'name'          => _n('Project manager', 'Project managers', 1, 'manageentities'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '10',
            'table'         => $this->getTable(),
            'field'         => 'total_ht',
            'name'          => __('Total excluding VAT', 'manageentities'),
            'datatype'      => 'decimal',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '11',
            'table'         => $this->getTable(),
            'field'         => 'version',
            'name'          => __('Version'),
            'datatype'      => 'integer',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '12',
            'table'         => $this->getTable(),
            'field'         => 'archive_date',
            'name'          => __('Archiving date', 'manageentities'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '13',
            'table'         => $this->getTable(),
            'field'         => 'estimate_status',
            'name'          => __('Estimate', 'manageentities'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '14',
            'table'         => PresaleOrigin::getTable(),
            'field'         => 'name',
            'name'          => PresaleOrigin::getTypeName(1),
            'datatype'      => 'dropdown',
        ];
        $tab[] = [
            'id'            => '19',
            'table'         => $this->getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => \Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return htmlescape(self::getStatuses()[(int) $values[$field]] ?? '');
        }
        if ($field === 'estimate_status') {
            return htmlescape(self::getEstimateStatuses()[(int) $values[$field]] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return \Dropdown::showFromArray(
                $name,
                ['open' => __('In progress', 'manageentities')] + self::getStatuses(),
                $options,
            );
        }
        if ($field === 'estimate_status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return \Dropdown::showFromArray($name, self::getEstimateStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Search criterion on the status, called by plugin_manageentities_addWhere(): the
     * "open" pseudo status (draft or generated) has no column value of its own
     *
     * @param bool   $nott
     * @param string $searchtype
     * @param mixed  $val
     *
     * @return array criteria of the iterator, empty for the default behaviour
     */
    public static function getStatusCriteria(bool $nott, string $searchtype, $val): array
    {
        if ($val !== 'open') {
            return [];
        }
        $negate = $nott !== ($searchtype === 'notequals');

        return [self::getTable() . '.status' => $negate
            ? ['NOT IN', self::getOpenStatuses()]
            : self::getOpenStatuses(),
        ];
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(\Document_Item::class, $ong, $options);
        $this->addStandardTab(\Log::class, $ong, $options);

        return $ong;
    }

    /**
     * Annexes of the proposal with their totals, in document order
     *
     * @return array<int, array{title: string, nb_lines: int, vat_rate: float, ht: float, vat: float, ttc: float}>
     */
    public function getAnnexesSummary(): array
    {
        $annexes = [];
        foreach ((new PresaleProposal_Annex())->find(['plugin_manageentities_presaleproposals_id' => $this->getID()], ['rank', 'id']) as $annex) {
            $lines = (new PresaleAnnexLine())->find(['plugin_manageentities_presaleproposals_annexes_id' => $annex['id']]);
            $annexes[] = [
                'title'    => $annex['title'],
                'nb_lines' => count($lines),
                'vat_rate' => (float) $annex['vat_rate'],
            ] + PresalePricing::annexTotals($lines, (float) $annex['vat_rate'], PresaleProposal_Annex::getPricingMode($annex));
        }
        return $annexes;
    }

    /**
     * Summary of the proposal: everything is edited through the wizard
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $client = new PresaleClient();
        $client->getFromDB((int) $this->fields['plugin_manageentities_presaleclients_id']);
        $recipient = new PresaleContact();
        $recipient->getFromDB((int) $this->fields['plugin_manageentities_presalecontacts_id']);

        $editable = in_array((int) $this->fields['status'], self::getOpenStatuses(), true)
            && $this->can($ID, UPDATE);

        // Latest generated version, downloadable from the summary
        $document_url = '';
        if ((int) $this->fields['documents_id'] > 0) {
            $document_url = PresaleWizard::getDocumentUrl((int) $this->fields['documents_id'], $ID);
        }

        TemplateRenderer::getInstance()->display('@manageentities/presale/proposal_form.html.twig', [
            'item'       => $this,
            'proposal'   => $this->fields,
            'status'     => self::getStatuses()[(int) $this->fields['status']] ?? '',
            'client'     => $client->fields,
            'recipient'  => $recipient->isNewItem() ? '' : trim($recipient->fields['name'] . ' ' . $recipient->fields['firstname']),
            'origin_label' => PresaleOrigin::getTypeName(1),
            'renewal'    => PresaleRenewal::getSourceLabel($this->fields),
            'origin'     => (int) $this->fields['plugin_manageentities_presaleorigins_id'] > 0
                ? \Dropdown::getDropdownName(PresaleOrigin::getTable(), (int) $this->fields['plugin_manageentities_presaleorigins_id'])
                : '',
            'steps'      => PresaleWizard::getSteps(),
            'last_step'  => PresaleWizard::LAST_STEP,
            // A generated proposal opens on its summary, where it is generated and won / lost
            'edit_url'   => $editable
                ? PresaleWizard::getFormUrl() . '?id=' . $ID . '&step='
                    . ((int) $this->fields['step'] >= PresaleWizard::STEP_GENERAL ? PresaleWizard::STEP_GENERATE : PresaleWizard::STEP_CLIENT)
                : '',
            'document_url'   => $document_url,
            'can_outcome'    => $editable,
            'can_reactivate' => (int) $this->fields['status'] === self::STATUS_LOST && $this->can($ID, UPDATE),
            'can_duplicate'  => self::canCreate(),
            // Won: the customer wizard prefilled from it, until its document went with a contract
            'customer_url'   => (int) $this->fields['status'] === self::STATUS_WON
                && \Session::haveRight(Contract::$rightname, UPDATE)
                && !PresaleConversion::isConverted($this)
                ? PresaleWizard::getCustomerWizardPath($ID)
                : '',
            'action_url'     => PresaleWizard::getActionUrl(),
            'annexes'    => $this->getAnnexesSummary(),
        ]);

        return true;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `is_recursive` tinyint NOT NULL DEFAULT '0',
                            `plugin_manageentities_presaleclients_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `plugin_manageentities_presalecontacts_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'recipient of the proposal',
                            `plugin_manageentities_presaleorigins_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'origin of the request, for the statistics',
                            `plugin_manageentities_editorsubscriptions_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_editorsubscriptions (id) — expired subscription renewed by the proposal',
                            `contracts_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id) — expiring contract followed by the proposal',
                            `name` varchar(255) DEFAULT NULL,
                            `reference` varchar(32) DEFAULT NULL COMMENT 'set at the first generation, e.g. SOC261019',
                            `status` tinyint NOT NULL DEFAULT '1' COMMENT '1=draft, 2=generated, 3=won, 4=lost (archived)',
                            `step` tinyint NOT NULL DEFAULT '0' COMMENT 'last validated step of the wizard',
                            `sales_users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `sales_name` varchar(255) DEFAULT NULL,
                            `sales_role` varchar(255) DEFAULT NULL,
                            `sales_email` varchar(255) DEFAULT NULL,
                            `sales_phone` varchar(255) DEFAULT NULL,
                            `pm_users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `pm_name` varchar(255) DEFAULT NULL,
                            `pm_role` varchar(255) DEFAULT NULL,
                            `pm_email` varchar(255) DEFAULT NULL,
                            `pm_phone` varchar(255) DEFAULT NULL,
                            `proposal_type` varchar(255) DEFAULT NULL COMMENT 'type of contract, free text',
                            `proposal_date` date DEFAULT NULL,
                            `summary` text,
                            `total_ht` decimal(20,2) NOT NULL DEFAULT '0.00',
                            `total_vat` decimal(20,2) NOT NULL DEFAULT '0.00',
                            `total_ttc` decimal(20,2) NOT NULL DEFAULT '0.00',
                            `documents_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id) — last generated version',
                            `version` int NOT NULL DEFAULT '0',
                            `archive_date` timestamp NULL DEFAULT NULL,
                            `conversion_date` timestamp NULL DEFAULT NULL COMMENT 'won proposal: the customer wizard went through (PresaleConversion::afterCommit())',
                            `estimate_status` tinyint NOT NULL DEFAULT '0' COMMENT 'estimate by the project manager: 0 none, 1 requested, 2 validated',
                            `estimate_brief` text COMMENT 'note of the sales representative for the estimate',
                            `estimate_comment` text COMMENT 'comment of the project manager on the estimate',
                            `estimate_date` timestamp NULL DEFAULT NULL COMMENT 'last request or validation of the estimate',
                            `estimate_users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — who validated the estimate',
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author',
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `reference` (`reference`),
                            KEY `name` (`name`),
                            KEY `entities_id` (`entities_id`),
                            KEY `is_recursive` (`is_recursive`),
                            KEY `plugin_manageentities_presaleclients_id` (`plugin_manageentities_presaleclients_id`),
                            KEY `plugin_manageentities_presalecontacts_id` (`plugin_manageentities_presalecontacts_id`),
                            KEY `plugin_manageentities_presaleorigins_id` (`plugin_manageentities_presaleorigins_id`),
                            KEY `plugin_manageentities_editorsubscriptions_id` (`plugin_manageentities_editorsubscriptions_id`),
                            KEY `contracts_id` (`contracts_id`),
                            KEY `status` (`status`),
                            KEY `sales_users_id` (`sales_users_id`),
                            KEY `pm_users_id` (`pm_users_id`),
                            KEY `estimate_status` (`estimate_status`),
                            KEY `estimate_users_id` (`estimate_users_id`),
                            KEY `documents_id` (`documents_id`),
                            KEY `users_id` (`users_id`),
                            KEY `date_creation` (`date_creation`),
                            KEY `date_mod` (`date_mod`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Estimate by the project manager (added after the first release)
        $fields = [
            'estimate_status'   => ['tinyint NOT NULL DEFAULT \'0\'', 'estimate by the project manager: 0 none, 1 requested, 2 validated', 'archive_date'],
            'estimate_brief'    => ['text', 'note of the sales representative for the estimate', 'estimate_status'],
            'estimate_comment'  => ['text', 'comment of the project manager on the estimate', 'estimate_brief'],
            'estimate_date'     => ['timestamp NULL DEFAULT NULL', 'last request or validation of the estimate', 'estimate_comment'],
            'estimate_users_id' => ['fkey', 'RELATION to glpi_users (id) — who validated the estimate', 'estimate_date'],
            // Origin of the request, for the statistics
            'plugin_manageentities_presaleorigins_id' => ['fkey', 'origin of the request, for the statistics', 'plugin_manageentities_presalecontacts_id'],
            // Subscription or contract the proposal renews (lists of Entity::showOpenProposals())
            'plugin_manageentities_editorsubscriptions_id' => ['fkey', 'RELATION to glpi_plugin_manageentities_editorsubscriptions (id) — expired subscription renewed by the proposal', 'plugin_manageentities_presaleorigins_id'],
            'contracts_id' => ['fkey', 'RELATION to glpi_contracts (id) — expiring contract followed by the proposal', 'plugin_manageentities_editorsubscriptions_id'],
            // Won proposal converted into a customer (the wizard went through)
            'conversion_date' => ['timestamp NULL DEFAULT NULL', 'won proposal: the customer wizard went through (PresaleConversion::afterCommit())', 'archive_date'],
        ];
        $add_conversion_date = !$DB->fieldExists($table, 'conversion_date');
        $changed = false;
        foreach ($fields as $name => [$type, $comment, $after]) {
            if (!$DB->fieldExists($table, $name)) {
                $migration->addField($table, $name, $type, ['after' => $after, 'comment' => $comment]);
                $changed = true;
            }
        }
        foreach (['estimate_status', 'estimate_users_id', 'plugin_manageentities_presaleorigins_id', 'plugin_manageentities_editorsubscriptions_id', 'contracts_id'] as $key) {
            $migration->addKey($table, $key);
        }
        if ($changed) {
            $migration->migrationOneTable($table);
        }
        if ($add_conversion_date) {
            PresaleConversion::initConversionDates();
        }

        // The reference is the date of the day (PresaleCounter::next()): several proposals of a
        // client the same day share it, it is no longer unique
        foreach ($DB->doQuery("SHOW INDEX FROM `$table` WHERE `Key_name` = 'reference'") as $index) {
            if ((int) $index['Non_unique'] === 0) {
                $DB->doQuery("ALTER TABLE `$table` DROP INDEX `reference`, ADD INDEX `reference` (`reference`)");
            }
        }

        // Default columns of the list: reference, prospect, status, type, date, sales, total, update
        if (countElementsInTable('glpi_displaypreferences', ['itemtype' => self::class, 'users_id' => 0]) === 0) {
            foreach ([3, 4, 5, 6, 7, 8, 10, 19] as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype'  => self::class,
                    'num'       => $num,
                    'rank'      => $rank + 1,
                    'users_id'  => 0,
                    'interface' => 'central',
                ]);
            }
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
