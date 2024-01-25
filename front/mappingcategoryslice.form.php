<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2017 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

$mapping = new PluginManageentitiesMappingCategorySlice();

if (isset($_POST["add"])) {
   $mapping->check(-1, UPDATE);
   $newID = $mapping->add($_POST);
   Html::back();

} else if (isset($_POST["delete"])) {
   $mapping->check($_POST["id"], UPDATE);
   $mapping->delete($_POST);
   Html::back();

} else if (isset($_POST["update"])) {
   $mapping->check($_POST["id"], UPDATE);
   $mapping->update($_POST);
   Html::back();

} else {
   Html::back();
}