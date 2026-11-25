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
use Migration;

/**
 * Model of the sections of a DAT for an operating system (Debian, Ubuntu, Red Hat, Windows): one
 * row per system, its sections in JSON as Dat::$fields['sections']. Edited in the DAT tab of the
 * configuration; the wizard fills the empty sections of a DAT with the model of the system of
 * its first server, and replaces them all on demand ("Apply the model").
 */
class DatOsTemplate extends CommonDBTM
{
    public static string $rightname = 'plugin_manageentities';

    public const OS_DEBIAN  = 'debian';
    public const OS_UBUNTU  = 'ubuntu';
    public const OS_REDHAT  = 'redhat';
    public const OS_WINDOWS = 'windows';

    /**
     * The models are part of the configuration: read with its rights, written by saveAll()
     * from the configuration form only, never through the generic endpoints
     */
    public static function canView(): bool
    {
        return Config::canManageConfig();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('DAT model', 'DAT models', $nb, 'manageentities');
    }

    /**
     * @return array<string, string>
     */
    public static function getSystems(): array
    {
        return [
            self::OS_DEBIAN  => 'Debian',
            self::OS_UBUNTU  => 'Ubuntu',
            self::OS_REDHAT  => 'Red Hat',
            self::OS_WINDOWS => 'Windows',
        ];
    }

    /**
     * Sections of the model of a system, by placeholder (those of the steps only, CLIENT
     * excluded); empty for an unknown system
     *
     * @return array<string, string>
     */
    public static function getSectionsFor(string $os): array
    {
        if (!isset(self::getSystems()[$os])) {
            return [];
        }
        $model = new self();
        $rows  = $model->find(['os' => $os], [], 1);
        $row   = reset($rows);
        $stored = $row !== false ? json_decode((string) $row['sections'], true) : null;
        $stored = is_array($stored) ? $stored : [];

        $sections = [];
        foreach (self::getPlaceholders() as $placeholder) {
            $sections[$placeholder] = (string) ($stored[$placeholder] ?? '');
        }

        return $sections;
    }

    /**
     * Sections of every model, for the configuration tab
     *
     * @return array<string, array<string, string>>
     */
    public static function getAll(): array
    {
        $models = [];
        foreach (array_keys(self::getSystems()) as $os) {
            $models[$os] = self::getSectionsFor($os);
        }

        return $models;
    }

    /**
     * Saves the models posted by the DAT tab of the configuration: dat_models[os][placeholder].
     * Unknown systems and placeholders are dropped.
     */
    public static function saveAll(array $posted): void
    {
        global $DB;

        foreach (array_keys(self::getSystems()) as $os) {
            if (!isset($posted[$os]) || !is_array($posted[$os])) {
                continue;
            }
            $sections = [];
            foreach (self::getPlaceholders() as $placeholder) {
                $value = (string) ($posted[$os][$placeholder] ?? '');
                $sections[$placeholder] = DatWizard::getSectionKind($placeholder) === DatWizard::KIND_CODE
                    ? rtrim(str_replace("\r\n", "\n", $value))
                    : trim($value);
            }
            $DB->updateOrInsert(
                self::getTable(),
                ['sections' => json_encode($sections)],
                ['os' => $os],
            );
        }
    }

    /**
     * Placeholders a model fills: the sections of the wizard steps
     *
     * @return list<string>
     */
    public static function getPlaceholders(): array
    {
        return array_merge(...array_values(DatWizard::getStepSections()));
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
                            `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'debian, ubuntu, redhat, windows',
                            `sections` mediumtext COMMENT 'JSON text of each section, by placeholder',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `os` (`os`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // No model is created: the installation ships no prefilled content, each model is
        // written in the DAT tab of the configuration
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
