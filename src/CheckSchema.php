<?php

/*
 -------------------------------------------------------------------------
 metademands plugin for GLPI
 Copyright (C) 2018-2026 by the metademands Development Team.

 https://github.com/InfotelGLPI/metademands
 -------------------------------------------------------------------------

 LICENSE

 This file is part of metademands.

 metademands is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 metademands is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with metademands. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonGLPI;
use Glpi\System\Diagnostic\DatabaseSchemaIntegrityChecker;
use Plugin;

class CheckSchema extends CommonDBTM {

    static $rightname = 'plugin_manageentities';
    private $table = "";

    /**
     * functions mandatory
     * getTypeName(), canCreate(), canView()
     *
     * @param int $nb
     *
     * @return string
     */
    static function getTypeName($nb = 0)
    {
        return __('Schema check', 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-check";
    }

    public static function getTable($classname = null) {
        return "glpi_plugin_manageentities_configs";
    }

    /**
     * @param \CommonGLPI $item
     * @param int         $withtemplate
     *
     * @return string
     * @see CommonGLPI::getTabNameForItem()
     */
    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {

        if ($item->getType() == Config::class) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    /**
     * @param \CommonGLPI $item
     * @param int         $tabnum
     * @param int         $withtemplate
     *
     * @return bool
     * @see CommonGLPI::displayTabContentForItem()
     */
    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        if ($item->getType() == Config::class) {
            $self = new self();
            $self->checkSchema(PLUGIN_MANAGEENTITIES_VERSION);
        }
        return true;
    }

    /**
     * Get the path to the empty SQL schema file
     *
     * @return string|null
     */
    function getSchemaPath(string $version): ?string {

        if ($version === null) {
            $version = PLUGIN_MANAGEENTITIES_VERSION;
        }
        return Plugin::getPhpDir('manageentities') . "/install/sql/empty.sql";
    }

    /**
     * Check the schema of all tables of the plugin against the expected schema of the given version
     *
     * @return boolean
     */
    public function checkSchema(
        string $version,
        bool   $strict = false,
        bool   $ignore_innodb_migration = true,
        bool   $ignore_timestamps_migration = true,
        bool   $ignore_utf8mb4_migration = true,
        bool   $ignore_dynamic_row_format_migration = true,
        bool   $ignore_unsigned_keys_migration = true
    ): bool {
        global $DB;

        $schemaFile = $this->getSchemaPath($version);

        $checker = new DatabaseSchemaIntegrityChecker(
            $DB,
            $strict,
            $ignore_innodb_migration,
            $ignore_timestamps_migration,
            $ignore_utf8mb4_migration,
            $ignore_dynamic_row_format_migration,
            $ignore_unsigned_keys_migration
        );

        try {
            $differences = $checker->checkCompleteSchema($schemaFile, true, 'plugin:manageentities');
//            Toolbox::logInfo($differences);
        } catch (\Throwable $e) {
            $message = __('Failed to check the sanity of the tables!', 'manageentities');
            //            if (isCommandLine()) {
            echo $message . PHP_EOL;
            //            } else {
            //                Session::addMessageAfterRedirect($message, false, ERROR);
            //            }
            return false;
        }

        if (count($differences) > 0) {
            echo "<table class='tab_cadre'>";
            foreach ($differences as $difference) {
                echo "<tr><td>";
                echo "<pre>".$difference['diff']."</pre>";
                echo "</td></tr>";
            }
            return false;
        } else {
            echo __('The plugin schema is good', 'manageentities');
            return true;
        }

        return true;
    }
}
