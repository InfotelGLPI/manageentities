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

use GlpiPlugin\Servicecatalog\Main;
use GlpiPlugin\Manageentities\Entity;

if (!isset($_GET["id"])) {
    $_GET["id"] = 0;
}

// The class instantiated below is the core \TicketTask, not the plugin one: without an
// import the short name resolved to the core class anyway, and the plugin class of the
// same name is only a hook carrier (preItemForm/preItemAdd/postForm), with neither
// showForm() nor defineTabs(), so it cannot be displayed. The leading backslash makes
// that resolution explicit instead of accidental.
// The guard, however, was checking the core "task" right, which is decorrelated from the
// screen actually served: a profile holding core task READ but nothing on this plugin got
// in, while a plugin manager without it was refused. Check the business right of the
// plugin, the same one the rest of the portal requires, and keep the core class checks
// underneath as the object-level boundary.
Session::checkRight('plugin_manageentities', READ);

$cri = new \TicketTask();

if (Session::getCurrentInterface() == 'central') {
    Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);
} else {
    if (Plugin::isPluginActive('servicecatalog')) {
        Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
    } else {
        Html::helpHeader(__('Entities portal', 'manageentities'));
    }
}

$cri->display($_GET);

if (Session::getCurrentInterface() != 'central'
    && Plugin::isPluginActive('servicecatalog')) {

    Main::showNavBarFooter('manageentities');
}

if (Session::getCurrentInterface() == 'central') {
    Html::footer();
} else {
    Html::helpFooter();
}
