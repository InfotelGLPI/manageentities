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
use GlpiPlugin\Manageentities\CriPrice;

if (Session::haveRight("plugin_manageentities", UPDATE)) {
   $criprice = new CriPrice();

   if (isset($_POST["add"])) {
      // Object-level check like the update branch: the global "contract CREATE" right is
      // not scoped by entity, so enforce CREATE with the posted entities_id before insert.
      $criprice->check(-1, CREATE, $_POST);
      $criprice->add($_POST);

      Html::back();

   } elseif (isset($_POST["update"])) {
      $criprice->check($_POST["id"], UPDATE);
      $criprice->update($_POST);

      Html::back();

   } elseif (isset($_POST["delete"])) {
      // Forced purge below: enforce the per-row PURGE right (existence + entity access)
      // instead of the global "contract DELETE" right, mirroring the update branch.
      $criprice->check((int) $_POST["id"], PURGE);
      $criprice->delete($_POST, 1);

      Html::back();
   }

} else {
    throw new AccessDeniedHttpException();
}
