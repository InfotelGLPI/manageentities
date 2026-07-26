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

use GlpiPlugin\Manageentities\TaskCategory;

// This is a write endpoint: require the UPDATE right, not READ. update() itself enforces
// no right, so a read-only "dropdown" right would otherwise be enough to write.
Session::checkRight("dropdown", UPDATE);

$taskCategory = new TaskCategory();

//Save profile
if (isset ($_POST['update'])) {
   $taskCategory->check((int) $_POST['id'], UPDATE);
   $taskCategory->update($_POST);
   Html::back();
}
