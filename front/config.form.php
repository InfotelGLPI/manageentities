<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Entity;

if (Plugin::isPluginActive("manageentities")) {
    if (Session::haveRight("plugin_manageentities", UPDATE)) {
        $config = new Config();

        if (isset($_POST["update_config"])) {
            Session::checkRight("config", UPDATE);
            $config->update($_POST);
            Html::back();
        } else {
            Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);
            $config->getFromDB(1);
            $config->display(['id' => 1]);
            Html::footer();
        }
    } else {
        Html::header(__('Setup'), '', "config", "plugin");
        echo "<div class='alert alert-warning d-flex'>";
        echo "<b>" . __("You don't have permission to perform this action.") . "</b></div>";
        Html::footer();
    }
} else {
    Html::header(__('Setup'), '', "config", "plugin");
    echo "<div class='alert alert-warning d-flex'>";
    echo "<b>" . __('Please activate the plugin', 'manageentities') . "</b></div>";
    Html::footer();
}
