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

class PluginManageentitiesTaskCategory extends CommonDBTM {
   
   static function getTypeName() {
      global $LANG;

      return $LANG['plugin_manageentities']['taskcategory'][0];
   }
   
   function canCreate() {
      return Session::haveRight('dropdown', 'w');
   }

   function canView() {
      return Session::haveRight('dropdown', 'r');
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      $config = PluginManageentitiesConfig::getInstance();

      if ($item->getType()=='TaskCategory') {
         if($config->fields['useprice']!='1'){
            return $LANG['plugin_manageentities']['title'][1];
         }
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {
      global $CFG_GLPI;

      if ($item->getType()=='TaskCategory') {
         $ID = $item->getField('id');
         $self = new self();
         
         if (!$self->getFromDBByTaskCategory($ID)) {
            $self->createAccess($item->getField('id'));
         }
         $self->showForm($item->getField('id'), array('target' =>
                           $CFG_GLPI["root_doc"]."/plugins/manageentities/front/taskcategory.form.php"));
      }
      return true;
   }

   function getFromDBByTaskCategory($taskcategories_id) {
      global $DB;
      
      $query = "SELECT * FROM `glpi_plugin_manageentities_taskcategories`
               WHERE `taskcategories_id` = '" . $taskcategories_id . "' ";
      if ($result = $DB->query($query)) {
         if ($DB->numrows($result) != 1) {
            return false;
         }
         $this->fields = $DB->fetch_assoc($result);
         if (is_array($this->fields) && count($this->fields)) {
            return true;
         } else {
            return false;
         }
      }
      return false;
   }

   function createAccess($ID) {

      $this->add(array(
      'taskcategories_id' => $ID));
   }

   function showForm ($ID, $options=array()) {
      global $LANG;

      if (!Session::haveRight("dropdown","r")) return false;

      $taskCategory = new TaskCategory();
      if ($ID) {
         $this->getFromDBByTaskCategory($ID);
         $taskCategory->getFromDB($ID);
         $canUpdate = $taskCategory->can($ID,'w');
      }

      $rand=mt_rand();

      echo "<form name='taskCategory_form$rand' id='taskCategory_form$rand' method='post'
            action='".$options['target']."'>";

      echo "<div class='spaced'><table class='tab_cadre_fixe'>";

      echo "<tr><th colspan='2'>";

      echo $LANG['plugin_manageentities']['taskcategory'][0]." - ".$taskCategory->fields["name"];

      echo "</th></tr>";

      echo "<tr class='tab_bg_2'>";

      echo "<td>".$LANG['plugin_manageentities']['taskcategory'][1]."</td><td>";
      Dropdown::showYesNo("is_usedforcount",$this->fields["is_usedforcount"]);
      echo "</td>";
      echo "</tr>";

      echo "<input type='hidden' name='id' value=".$this->fields["id"].">";

      $options['canedit']      = false;

      if($canUpdate){
         $options['canedit'] = true;
      }
      $options['candel'] = false;
      $options['colspan'] = '1';
      $this->showFormButtons($options);

   }
}

?>