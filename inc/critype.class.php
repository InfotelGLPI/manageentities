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

class PluginManageentitiesCriType extends CommonDropdown {
   
   static function getTypeName() {
      global $LANG;

      return $LANG['plugin_manageentities'][14];
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight('manageentities', 'w');
   }

   function canView() {
      $config=PluginManageentitiesConfig::getInstance();
      if($config->fields['useprice']=='1'){
         return plugin_manageentities_haveRight('manageentities', 'r');
      }
   }
   
   function getSearchOptions() {
      global $LANG;

      $tab = array();
      $tab['common']=$LANG['common'][32];

      $tab[1]['table'] = $this->getTable();
      $tab[1]['field'] = 'name';
      $tab[1]['name'] = $LANG['common'][16];
      $tab[1]['datatype']='itemlink';
      $tab[1]['itemlink_type'] = $this->getType();
      
      $tab[2]['table'] = 'glpi_plugin_manageentities_criprices';
      $tab[2]['field'] = 'price';
      $tab[2]['name'] = $LANG['plugin_manageentities'][15];
      
      $tab[30]['table'] = $this->getTable();
      $tab[30]['field'] = 'id';
      $tab[30]['name'] = $LANG['common'][2];
   
      return $tab;
   }

   function defineTabs($options=array()) {

      $ong = array();
      $this->addStandardTab(__CLASS__, $ong, $options);

      return $ong;
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      if (!$withtemplate) {
         switch ($item->getType()) {
            case 'PluginManageentitiesCriType' :
               return $LANG['plugin_manageentities'][14];
         }
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      if ($item->getType()=='PluginManageentitiesCriType') {
         PluginManageentitiesCriPrice::showform($item->getID());
      }
      return true;
   }
}

?>