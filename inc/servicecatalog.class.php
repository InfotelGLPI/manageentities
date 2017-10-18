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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}


class PluginManageentitiesServicecatalog extends CommonGLPI {

   static $rightname = 'plugin_manageentities';

   var $dohistory = false;

   static function canUse() {
      $PluginManageentitiesEntity = new PluginManageentitiesEntity();
      return $PluginManageentitiesEntity->canView();
   }

   static function getMenuLogo() {
      global $CFG_GLPI;

      return "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/manageentities/front/entity.php'>
      <img class=\"bt-img-responsive\" src=\"" . $CFG_GLPI['root_doc'] . "/plugins/servicecatalog/img/contracts.png\" alt=\"Advanced request\" width=\"190\" height=\"100\"></a>";

   }

   static function getMenuTitle() {
      global $CFG_GLPI;

      return "<a href='" . $CFG_GLPI['root_doc'] . "/plugins/manageentities/front/entity.php' class='de-em'>
      <span class='de-em'>" . __('Manage', 'servicecatalog') . " </span><span class='em'>" . __('your contracts', 'manageentities') . "</span></a>";

   }


   static function getMenuComment() {

      echo __('Manage your contracts', 'manageentities');
   }

   static function getLinkList() {
      return "";
   }

   static function getList() {
      return "";
   }
}