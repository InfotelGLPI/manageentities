<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use GlpiPlugin\Manageentities\Config;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class TaskCategory extends CommonDBTM
{

    static $rightname = 'dropdown';

    static function getTypeName($nb = 0)
    {
        return _n('Management of task category', 'Management of task categories', $nb, 'manageentities');
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $config = Config::getInstance();

        if ($item->getType() == 'TaskCategory') {
            if ($config->fields['hourorday'] == Config::HOUR) {
                return self::createTabEntry(__('Entities portal', 'manageentities'));
            }
        }
        return '';
    }

    static function getIcon()
    {
        return "ti ti-user-pentagon";
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
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
                    PLUGIN_MANAGEENTITIES_WEBDIR . "/front/taskcategory.form.php"
            ]);
        }
        return true;
    }

    function createAccess($ID)
    {
        $this->add([
            'taskcategories_id' => $ID
        ]);
    }

    function showForm($ID, $options = [])
    {
        if (!self::canView()) {
            return false;
        }

        $taskCategory = new \TaskCategory();
        if ($ID) {
            $this->getFromDBByCrit(['taskcategories_id' => $ID]);
            $taskCategory->getFromDB($ID);
            $canUpdate = $taskCategory->can($ID, UPDATE);
        }

        $rand = mt_rand();

        echo "<form name='taskCategory_form$rand' id='taskCategory_form$rand' method='post'
            action='" . $options['target'] . "'>";

        echo "<div class='spaced'><table class='tab_cadre_fixe'>";

        echo "<tr><th colspan='2'>";

        echo __('Management of task category', 'manageentities') . " - " . $taskCategory->fields["name"];

        echo "</th></tr>";

        echo "<tr class='tab_bg_2'>";

        echo "<td>" . __('Use for calculation of intervention report', 'manageentities') . "</td><td>";
        \Dropdown::showYesNo("is_usedforcount", $this->fields["is_usedforcount"]);
        echo "</td>";
        echo "</tr>";

        echo Html::hidden('id', ['value' => $this->fields["id"]]);

        $options['canedit'] = false;

        if ($canUpdate) {
            $options['canedit'] = true;
        }
        $options['candel'] = false;
        $options['colspan'] = '1';
        $this->showFormButtons($options);
    }
}
