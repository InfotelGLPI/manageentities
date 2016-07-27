<?php
/*
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2003-2012 by the Manageentities Development Team.

 https://forge.indepnet.net/projects/manageentities
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

class PluginManageentitiesContractState extends CommonDropdown {

   static function getTypeName() {
      global $LANG;

      return $LANG['plugin_manageentities'][2];
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight('manageentities', 'w');
   }

   function canView() {
      return plugin_manageentities_haveRight('manageentities', 'r');
   }

   function getAdditionalFields() {
      global $LANG;

      return array( array( 'name'  => 'is_active',
                           'label' => $LANG['common'][60],
                           'type'  => 'bool'),
      );
   }

   function getSearchOptions() {
      global $LANG;

      $tab = parent::getSearchOptions();

      $tab[14]['table']         = $this->getTable();
      $tab[14]['field']         = 'is_active';
      $tab[14]['name']          = $LANG['common'][60];
      $tab[14]['datatype']      = 'bool';

      return $tab;
   }
}

?>