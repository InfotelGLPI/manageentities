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

$AJAX_INCLUDE = 1;

// Send UTF8 Headers
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

// Align this endpoint on its AJAX peers: require the plugin READ right so a
// minimal self-service account cannot reach it (it is only used by the CRI wizard).
Session::checkRight('plugin_manageentities', READ);

if (isset($_POST['duration']) && ($_POST['duration'] == 0)
   && isset($_POST['name'])) {
    // The field name is reflected into the rendered component. The CRI wizard is the
    // only caller and always requests "plan[end]" (the wizard JS depends on that exact
    // name), so pin it to that whitelisted value rather than echoing arbitrary input.
    $name = 'plan[end]';

    // Time bounds are reflected too: only accept an empty value or a strict HH:MM.
    $global_begin = $_POST['global_begin'] ?? '';
    $global_end   = $_POST['global_end'] ?? '';
    if ($global_begin !== '' && !preg_match('/^\d{2}:\d{2}$/', (string) $global_begin)) {
        $global_begin = '';
    }
    if ($global_end !== '' && !preg_match('/^\d{2}:\d{2}$/', (string) $global_end)) {
        $global_end = '';
    }

    Html::showDateTimeField($name, [
        'timestep'   => -1,
        'maybeempty' => false,
        'canedit'    => true,
        'mindate'    => '',
        'maxdate'    => '',
        'mintime'    => $global_begin,
        'maxtime'    => $global_end]);
}
