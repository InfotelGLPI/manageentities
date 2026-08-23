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
use CommonGLPI;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Migration;
use Session;
use GlpiPlugin\Manageentities\Config;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class TaskCategory extends CommonDBTM
{
    public static $rightname = 'dropdown';

    public static function getTypeName($nb = 0)
    {
        return _n('Management of task category', 'Management of task categories', $nb, 'manageentities');
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $config = Config::getInstance();

        if ($item->getType() == 'TaskCategory') {
            if ($config->fields['hourorday'] == Config::HOUR) {
                return self::createTabEntry(__('Entities portal', 'manageentities'));
            }
        }
        return '';
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if ($item->getType() == 'TaskCategory') {
            $ID = $item->getField('id');
            $self = new self();

            if (!$self->getFromDBByCrit(['taskcategories_id' => $ID])) {
                $self->createAccess($item->getField('id'));
            }
            $self->showForm($item->getField('id'), [
                'target' =>
                    PLUGIN_MANAGEENTITIES_WEBDIR . "/front/taskcategory.form.php",
            ]);
        }
        return true;
    }

    public function createAccess($ID)
    {
        $this->add([
            'taskcategories_id' => $ID,
        ]);
    }

    public function showForm($ID, $options = [])
    {
        if (!self::canView()) {
            return false;
        }

        $taskCategory = new \TaskCategory();
        $canUpdate = false;
        if ($ID) {
            $this->getFromDBByCrit(['taskcategories_id' => $ID]);
            $taskCategory->getFromDB($ID);
            $canUpdate = $taskCategory->can($ID, UPDATE);
        }

        TemplateRenderer::getInstance()->display('@manageentities/taskcategory_form.html.twig', [
            'form_url'        => $options['target'],
            'item_id'         => $this->fields["id"] ?? 0,
            'title'           => self::getTypeName(1) . " - " . ($taskCategory->fields["name"] ?? ''),
            'is_usedforcount' => $this->fields["is_usedforcount"] ?? 0,
            'can_update'      => $canUpdate,
        ]);

        return true;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `taskcategories_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to  glpi_taskcategories (id)',
                            `is_usedforcount` tinyint NOT NULL DEFAULT '0',
                            PRIMARY KEY  (`id`),
                            KEY `taskcategories_id` (`taskcategories_id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
