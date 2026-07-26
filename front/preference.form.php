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

use GlpiPlugin\Manageentities\Preference;

Session::checkLoginUser();

//Save user preferences
if (isset ($_POST['update_user_preferences_manageentities'])) {
   $pref = new Preference();
   // IDOR guard: only allow updating the current user's own preference row. Reload the
   // targeted row and reject any posted id that does not belong to the logged-in user,
   // instead of trusting $_POST['id'].
   if (isset($_POST['id'])
       && $pref->getFromDB((int) $_POST['id'])
       && (int) $pref->fields['users_id'] === (int) Session::getLoginUserID()) {
       $pref->update($_POST);
   }
   Html::back();
}
