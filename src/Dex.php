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
 * Operating document (DEX, "document d'exploitation") of a client: one per entity, versioned,
 * as the DAT. The wizard fills the sections step by step (draft saved in the database); each
 * generation stores a new version of the Word document as a GLPI document linked to the entity.
 * On creation, the servers and the system of the DAT of the client are taken over. See
 * docs/dex.md.
 */
class Dex extends CommonDBTM
{
    use GeneratedSnapshotTrait;

    public static $rightname = 'plugin_manageentities_dex';

    public $dohistory = true;

    /** The snapshot of the last generation is technical: not in the history */
    public $history_blacklist = ['generated_snapshot'];

    public const STATUS_DRAFT     = 1;
    public const STATUS_GENERATED = 2;

    /**
     * Text placeholders of the Word template, stored in the sections field. The list of the
     * processes is LIST_PROCESSUS in the reference template; PROCESSUS is filled with it too.
     */
    public const SECTIONS = [
        'CLIENT',
        'SYSTEM_INFORMATIONS',
        'TEXT_PROCESSUS',
        'LIST_PROCESSUS',
        'TEXT_FOLDERS',
        'TEXT_SCRIPTS',
        'TEXT_EPURATIONS',
        'TEXT_BACKUPS',
        'TEXT_CHECK_PROCESSUS',
        'COMMAND_CHECK_PROCESSUS',
        'TEXT_RESTART_APACHE',
        'COMMAND_RESTART_APACHE',
        'TEXT_RESTART_MYSQL',
        'COMMAND_RESTART_MYSQL',
        'TEXT_LAUNCH_CRON',
        'COMMAND_LAUNCH_CRON',
        'TEXT_LAUNCH_BACKUP',
        'COMMAND_LAUNCH_BACKUP',
        'TEXT_LAUNCH_MAINTENANCE_MODE',
        'TEXT_PREPROD_COPY_DATAS',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Operating document', 'Operating documents', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-file-power';
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
        return new DexServer();
    }

    protected static function getDateField(): string
    {
        return 'dex_date';
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            DexServer::class,
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
        // One DEX per client (unique key): a new version updates the existing one
        if (self::getForEntity((int) ($input['entities_id'] ?? 0)) !== null) {
            Session::addMessageAfterRedirect(
                __('This client already has an operating document.', 'manageentities'),
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
        // One DEX per client: it never moves to another entity
        unset($input['entities_id']);

        return $this->encodeSections($input);
    }

    /**
     * DEX of an entity, if any
     */
    public static function getForEntity(int $entities_id): ?self
    {
        $dex = new self();

        return $dex->getFromDBByCrit(['entities_id' => $entities_id]) ? $dex : null;
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
                            `dex_date` date DEFAULT NULL,
                            `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'operating system, a DexOsTemplate model prefilling the sections',
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

        self::installGeneratedSnapshot($migration);
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
