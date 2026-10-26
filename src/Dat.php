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
use CommonDBTM;
use DBConnection;
use Migration;
use Session;

/**
 * Technical architecture document (DAT) of a client: one per entity, versioned. The wizard
 * fills the sections step by step (draft saved in the database); each generation stores a new
 * version of the Word document as a GLPI document linked to the entity. See docs/dat.md.
 */
class Dat extends CommonDBTM
{
    use GeneratedSnapshotTrait;

    public static $rightname = 'plugin_manageentities_dat';

    public $dohistory = true;

    /** The snapshot of the last generation is technical: not in the history */
    public $history_blacklist = ['generated_snapshot'];

    public const STATUS_DRAFT     = 1;
    public const STATUS_GENERATED = 2;

    /** Text placeholders of the Word template, stored in the sections field */
    public const SECTIONS = [
        'CLIENT',
        'SYSTEM_INFORMATIONS',
        'PAQUETS_INSTALLATION',
        'APACHE_INFORMATIONS',
        'APACHE_INSTALLATION',
        'PHP_INFORMATIONS',
        'PHP_INSTALLATION',
        'MYSQL_INFORMATIONS',
        'MYSQL_INSTALLATION',
        'CRON_INSTALLATION',
        'LOGS_INSTALLATION',
        'HTTPS_INSTALLATION',
        'NTP_INSTALLATION',
        'FILES_INSTALLATION',
        'SSO_TECHNOLOGY',
        'SSO_INSTALLATION',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Technical architecture document', 'Technical architecture documents', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-file-settings';
    }

    /**
     * @return array<int, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT     => __('Draft', 'manageentities'),
            self::STATUS_GENERATED => __('Generated', 'manageentities'),
        ];
    }

    protected static function newServer(): CommonDBChild
    {
        return new DatServer();
    }

    protected static function getDateField(): string
    {
        return 'dat_date';
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            DatServer::class,
        ]);
    }

    /**
     * Text of each section, by placeholder: the known ones only, empty when not filled yet
     *
     * @return array<string, string>
     */
    public function getSections(): array
    {
        $stored = json_decode((string) ($this->fields['sections'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];

        $sections = [];
        foreach (self::SECTIONS as $placeholder) {
            $sections[$placeholder] = (string) ($stored[$placeholder] ?? '');
        }

        return $sections;
    }

    /**
     * The sections are posted as an array, by placeholder: unknown keys are dropped, the known
     * ones merged into the stored sections, then encoded as JSON
     *
     * @param array $input
     *
     * @return array
     */
    private function encodeSections(array $input): array
    {
        if (!isset($input['sections']) || !is_array($input['sections'])) {
            unset($input['sections']);
            return $input;
        }

        $sections = $this->isNewItem() ? array_fill_keys(self::SECTIONS, '') : $this->getSections();
        foreach (self::SECTIONS as $placeholder) {
            if (array_key_exists($placeholder, $input['sections'])) {
                $sections[$placeholder] = (string) $input['sections'][$placeholder];
            }
        }
        $input['sections'] = json_encode($sections);

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        // One DAT per client (unique key): a new version updates the existing one
        if (self::getForEntity((int) ($input['entities_id'] ?? 0)) !== null) {
            Session::addMessageAfterRedirect(
                __('This client already has a technical architecture document.', 'manageentities'),
                false,
                ERROR,
            );
            return false;
        }

        $input = $this->encodeSections($input);
        $input['users_id'] ??= Session::getLoginUserID();

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // One DAT per client: it never moves to another entity
        unset($input['entities_id']);

        return $this->encodeSections($input);
    }

    /**
     * DAT of an entity, if any
     */
    public static function getForEntity(int $entities_id): ?self
    {
        $dat = new self();

        return $dat->getFromDBByCrit(['entities_id' => $entities_id]) ? $dat : null;
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
                            `version` int NOT NULL DEFAULT '0' COMMENT 'last generated version',
                            `status` tinyint NOT NULL DEFAULT '1' COMMENT '1=draft, 2=generated',
                            `step` tinyint NOT NULL DEFAULT '0' COMMENT 'last validated step of the wizard',
                            `author_users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author printed in the document',
                            `dat_date` date DEFAULT NULL,
                            `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'operating system, a DatOsTemplate::OS_* model prefilling the sections',
                            `documents_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id) — last generated version',
                            `sections` mediumtext COMMENT 'JSON text of each section, by placeholder',
                            `generated_snapshot` mediumtext COMMENT 'JSON snapshot of the last generation: author, date, os, sections, servers',
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — creator',
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `entities_id` (`entities_id`),
                            KEY `status` (`status`),
                            KEY `author_users_id` (`author_users_id`),
                            KEY `documents_id` (`documents_id`),
                            KEY `users_id` (`users_id`),
                            KEY `date_creation` (`date_creation`),
                            KEY `date_mod` (`date_mod`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Upgrade: operating system of the DAT, chosen once for all its servers; it first lived
        // on each server (DatServer.os), the system of the first server is taken over
        if (!$DB->fieldExists($table, 'os')) {
            $migration->addField($table, 'os', "varchar(50) NOT NULL DEFAULT ''", [
                'after'   => 'dat_date',
                'comment' => 'operating system, a DatOsTemplate::OS_* model prefilling the sections',
            ]);
            $migration->migrationOneTable($table);

            $servers = DatServer::getTable();
            if ($DB->fieldExists($servers, 'os')) {
                foreach ($DB->request(['FROM' => $table, 'SELECT' => ['id']]) as $row) {
                    $first = $DB->request([
                        'SELECT' => ['os'],
                        'FROM'   => $servers,
                        'WHERE'  => [DatServer::$items_id => $row['id'], 'NOT' => ['os' => '']],
                        'ORDER'  => ['rank', 'id'],
                        'LIMIT'  => 1,
                    ])->current();
                    if ($first !== null) {
                        $DB->update($table, ['os' => $first['os']], ['id' => $row['id']]);
                    }
                }
            }
        }

        self::installGeneratedSnapshot($migration);
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
