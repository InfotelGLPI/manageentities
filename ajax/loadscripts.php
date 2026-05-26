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

Html::header_nocache();
Session::checkLoginUser();
header("Content-Type: text/html; charset=UTF-8");

//if (isset($_POST['action'])) {
//   switch ($_POST['action']) {
//      case "load" :
//         if (Session::haveRight("task", CommonITILTask::UPDATEALL)
//             && Session::haveRight("task", CommonITILTask::ADDALLITEM)
//             && strpos($_SERVER['HTTP_REFERER'], "ticket.form.php") !== false
//             && strpos($_SERVER['HTTP_REFERER'], 'id=') !== false
//             && Session::getCurrentInterface() == "central"
//             && Session::haveRight("plugin_manageentities", READ)) {
//
//            echo "<script type='text/javascript'>showCloneTicketTask(" . json_encode(['root_doc' => $CFG_GLPI['root_doc']]) . ");</script>";
//         }
//         break;
//   }
//}
