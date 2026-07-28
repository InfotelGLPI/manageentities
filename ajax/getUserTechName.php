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

use Glpi\Exception\Http\AccessDeniedHttpException;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();
// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['user_id_tech']) && (int) $_POST['user_id_tech'] > 0) {
   $user_id = (int) $_POST['user_id_tech'];
   // Only disclose the name of a user who has a profile assignment in one of the
   // caller's active entities (same scoping as the technician User::dropdown).
   // Without this, an arbitrary id could be resolved to a real name, letting a
   // caller enumerate the whole user directory across entities.
   $visible = countElementsInTable(
       'glpi_profiles_users',
       ['users_id' => $user_id] + getEntitiesRestrictCriteria('glpi_profiles_users')
   );
   if ($visible > 0) {
      echo json_encode(getUserName($user_id));
   } else {
      echo json_encode('');
   }
}
