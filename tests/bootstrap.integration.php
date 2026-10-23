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

// Bootstrap GLPI's test environment (DB connection, session, etc.)
require_once dirname(__DIR__, 3) . '/tests/bootstrap.php';

// Register manageentities classes into the already-loaded Composer autoloader
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->addPsr4('GlpiPlugin\\Manageentities\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('GlpiPlugin\\Manageentities\\Tests\\', dirname(__DIR__) . '/tests/');
$loader->addPsr4('GlpiPlugin\\Manageentities\\Tests\\Integration\\', dirname(__DIR__) . '/tests/integration/');

// The test environment only looks for plugins under tests/fixtures: Plugin::getPhpDir() does not
// find this one, so its directory is set before setup.php defines it from there
if (!defined('PLUGIN_MANAGEENTITIES_DIR')) {
    define('PLUGIN_MANAGEENTITIES_DIR', str_replace('\\', '/', dirname(__DIR__)));
}
if (!defined('PLUGIN_MANAGEENTITIES_WEBDIR')) {
    define('PLUGIN_MANAGEENTITIES_WEBDIR', '/plugins/manageentities');
}

// Install the plugin in the test DB, or bring it up to date: plugin_manageentities_install() is
// idempotent and replays every migration, so the test DB always matches the code under test
// (a check on the newest table or field missed the migrations of existing rows).
if (!defined('PLUGIN_MANAGEENTITIES_VERSION')) {
    require_once dirname(__DIR__) . '/setup.php';
}
require_once dirname(__DIR__) . '/hook.php';
plugin_manageentities_install();
