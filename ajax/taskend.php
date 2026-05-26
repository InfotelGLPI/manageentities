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
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

$AJAX_INCLUDE = 1;

// Send UTF8 Headers
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

Session::checkLoginUser();

if (isset($_POST['duration']) && ($_POST['duration'] == 0)
   && isset($_POST['name'])) {
   if (!isset($_POST['global_begin'])) {
      $_POST['global_begin'] = '';
   }
   if (!isset($_POST['global_end'])) {
      $_POST['global_end'] = '';
   }
   Html::showDateTimeField($_POST['name'], [
      'timestep'   => -1,
      'maybeempty' => false,
      'canedit'    => true,
      'mindate'    => '',
      'maxdate'    => '',
      'mintime'    => $_POST['global_begin'],
      'maxtime'    => $_POST['global_end']]);
}
