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

use CommonDBChild;
use DBConnection;
use Migration;

/**
 * Annex retained in a proposal (step 3 of the wizard). Title, purpose and details are copied
 * from the annex model so that they can be adapted without changing the model; the
 * subscription fields are only filled for a publisher subscription annex.
 *
 * The title is stored numbered, "Annexe N : label", N being the rank of the annex in the
 * proposal: the wizard renumbers the annexes on each save.
 */
class PresaleProposal_Annex extends CommonDBChild
{
    public static string $rightname = 'plugin_manageentities_presale';

    public static string $itemtype = PresaleProposal::class;
    public static string $items_id = 'plugin_manageentities_presaleproposals_id';

    /**
     * Name of each annex in the name of the proposal (deduceContractType()): the internal name
     * of its model when set, else the first word of its label
     *
     * @param list<array{title: string, models_id: int}> $annexes
     *
     * @return list<string>
     */
    public static function namesOf(array $annexes): array
    {
        $internal = [];
        $ids      = array_filter(array_map('intval', array_column($annexes, 'models_id')));
        if ($ids !== []) {
            foreach ((new PresaleAnnexType())->find(['id' => $ids]) as $model) {
                if (trim((string) ($model['internal_name'] ?? '')) !== '') {
                    $internal[(int) $model['id']] = trim((string) $model['internal_name']);
                }
            }
        }
        $names = [];
        foreach ($annexes as $annex) {
            $models_id = (int) ($annex['models_id'] ?? 0);
            $names[]   = $internal[$models_id]
                ?? (string) strtok(self::stripNumber((string) ($annex['title'] ?? '')), " \t");
        }

        return $names;
    }

    /** Numbered title, in the language of the Word documents (French, as their templates) */
    public const TITLE_FORMAT = 'Annexe %d : %s';

    public static function getTypeName($nb = 0)
    {
        return _n('Annex', 'Annexes', $nb, 'manageentities');
    }

    /**
     * Title of the annex of rank $number (from 1) in the document
     */
    public static function numberTitle(int $number, string $label): string
    {
        return sprintf(self::TITLE_FORMAT, $number, self::stripNumber($label));
    }

    /**
     * Label of a title without its "Annexe N :" prefix: the one of a numbered title, or the one
     * typed in the name of an annex model ("Annexe 1 : Expertise GLPI")
     */
    public static function stripNumber(string $title): string
    {
        return trim((string) preg_replace('/^\s*annexe?\s*\d+\s*[:\-–]\s*/iu', '', $title));
    }

    /**
     * Type of contract deduced from the names of the annexes (namesOf()), once each, in their
     * order: "Expertise" + "Souscription" => "Expertise & Souscription"
     *
     * @param list<string> $names
     */
    public static function deduceContractType(array $names): string
    {
        $words = [];
        foreach ($names as $name) {
            $word = trim($name);
            if ($word !== '' && !isset($words[mb_strtolower($word)])) {
                $words[mb_strtolower($word)] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
            }
        }

        return implode(' & ', $words);
    }

    /**
     * Internal label deduced from the prospect and the type of contract: "APRR - Expertise & Souscription"
     */
    public static function deduceLabel(string $client_name, string $contract_type): string
    {
        return $contract_type === '' ? $client_name : $client_name . ' - ' . $contract_type;
    }

    /**
     * How the lines of an annex are priced (PresalePricing::MODE_*): given by its annex model
     *
     * @param array $annex row of the annex
     */
    public static function getPricingMode(array $annex): string
    {
        $model = new PresaleAnnexType();
        if (!$model->getFromDB((int) $annex['plugin_manageentities_presaleannextypes_id'])) {
            return PresalePricing::MODE_DAYS;
        }

        return PresaleAnnexType::getPricingMode($model->fields);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            PresaleAnnexLine::class,
        ]);
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
                            `plugin_manageentities_presaleproposals_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `plugin_manageentities_presaleannextypes_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `rank` int NOT NULL DEFAULT '0',
                            `title` varchar(255) DEFAULT NULL,
                            `objet` mediumtext,
                            `description` mediumtext,
                            `estimate` text COMMENT 'JSON estimate printed under the purpose: [{type: title|line, label, days}]',
                            `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '0 = VAT not applicable',
                            `plugin_manageentities_subscriptionlevels_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_subscriptionlevels (id)',
                            `cloud_client` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            KEY `plugin_manageentities_presaleproposals_id` (`plugin_manageentities_presaleproposals_id`),
                            KEY `plugin_manageentities_presaleannextypes_id` (`plugin_manageentities_presaleannextypes_id`),
                            KEY `plugin_manageentities_subscriptionlevels_id` (`plugin_manageentities_subscriptionlevels_id`),
                            KEY `rank` (`rank`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Estimate of the regular annexes (added after the first release)
        if (!$DB->fieldExists($table, 'estimate')) {
            $migration->addField($table, 'estimate', 'text', [
                'after'   => 'description',
                'comment' => 'JSON estimate printed under the purpose: [{type: title|line, label, days}]',
            ]);
            $migration->migrationOneTable($table);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
