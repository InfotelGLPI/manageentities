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

/**
 * class plugin_manageentities_preference
 * Load and store the preference configuration from the database
 */
class PluginManageentitiesPreference extends CommonDBTM {
  
   static function checkIfPreferenceExists($users_id) {
      global $DB;
    
      $result = $DB->query("SELECT `id`
                FROM `glpi_plugin_manageentities_preferences`
                WHERE `users_id` = '".$users_id."' ");
      if ($DB->numrows($result) > 0)
        return $DB->result($result,0,"id");
      else
        return 0;
   }

   static function addDefaultPreference($users_id) {
      
      $self = new self();
      $input["users_id"]=$users_id;
      $input["show_on_load"]=0;

      return $self->add($input);
   }

   static function checkPreferenceValue($users_id) {
      global $DB;
    
      $result = $DB->query("SELECT *
                FROM `glpi_plugin_manageentities_preferences`
                WHERE `users_id` = '".$users_id."' ");
      if ($DB->numrows($result) > 0)
         return $DB->result($result,0,"show_on_load");
      else
         return 0;
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      if ($item->getType()=='Preference') {
            return $LANG['plugin_manageentities']['title'][1];
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {
      global $CFG_GLPI;

      if (get_class($item)=='Preference') {
         $pref_ID=self::checkIfPreferenceExists(Session::getLoginUserID());
         if (!$pref_ID)
            $pref_ID=self::addDefaultPreference(Session::getLoginUserID());

         self::showForm($CFG_GLPI['root_doc']."/plugins/manageentities/front/preference.form.php",$pref_ID,Session::getLoginUserID());
      }
      return true;
   }

   static function showForm($target,$ID,$user_id) {
      global $LANG,$DB;

      $data=plugin_version_manageentities();
      $self = new self();
      $self->getFromDB($ID);
      echo "<form action='".$target."' method='post'>";
      echo "<div align='center'>";

      echo "<table class='tab_cadre_fixe' cellpadding='5'>";
      echo "<tr><th colspan='2'>" . $data['name'] . " - ". $data['version'] . "</th></tr>";

      echo "<tr class='tab_bg_1 center'><td>".$LANG['plugin_manageentities'][10]."</td>";
      echo "<td>";
      Dropdown::showYesNo("show_on_load",$self->fields["show_on_load"]);
      echo "</td></tr>";
      echo "<tr class='tab_bg_1 center'><td colspan='2'>";
      echo "<input type='submit' name='update_user_preferences_manageentities' value='".$LANG['buttons'][2]."' class='submit'>";
      echo "<input type='hidden' name='id' value='".$ID."'>";
      echo "</td></tr>";
      echo "<tr class='tab_bg_1 center'><td colspan='2'>";
      echo $LANG['plugin_manageentities'][11];
      echo "</td></tr>";
      echo "</table>";

      echo "</div>";
      Html::closeForm();

   }
}

?>