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

class PluginManageentitiesConfig extends CommonDBTM {

   private static $instance;

   const DAY = 0;
   const HOUR = 1;

   function showForm () {
      global $LANG;

      echo "<form name='form' method='post' action='".
         Toolbox::getItemTypeFormURL('PluginManageentitiesConfig')."'>";

      echo "<div align='center'><table class='tab_cadre_fixe'  cellspacing='2' cellpadding='2'>";
      echo "<tr><th colspan='2'>".$LANG['plugin_manageentities']['setup'][0]."</th></tr>";

      echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][10]."</td>";
      echo "<td>";
      Dropdown::showYesNo("backup",$this->fields["backup"]);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][2]."</td>";
      echo "<td>";
      Dropdown::show('DocumentCategory', array('name' => "documentcategories_id",
                                               'value' => $this->fields["documentcategories_id"]));
      echo "</td></tr>";

      echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][9]."</td>";
      echo "<td>";
      Dropdown::showYesNo("useprice",$this->fields["useprice"]);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][3]."</td>";
      echo "<td>";
      self::dropdownConfigType("hourorday",$this->fields["hourorday"]);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][12]."</td>";
      echo "<td>";
      Dropdown::showYesNo("use_publictask",$this->fields["use_publictask"]);
      echo "</td></tr>";

      echo "<input type='hidden' name='id' value='1'>";

      echo "<tr><th colspan='2'><input type=\"submit\" name=\"update_config\" class=\"submit\"
         value=\"".$LANG["buttons"][2]."\" ></th></tr>";

      echo "</table></div>";
      Html::closeForm();
   }


   function showDetails() {
      global $LANG;

      echo "<form name='form' method='post' action='".
         Toolbox::getItemTypeFormURL('PluginManageentitiesConfig')."'>";

      echo "<div align='center'><table class='tab_cadre_fixe'  cellspacing='2' cellpadding='2'>";
      echo "<tr><th colspan='2'>".$LANG['plugin_manageentities']['setup'][11]."</th></tr>";

      switch ($this->fields["hourorday"]) {
         case self::DAY :
            echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][1]."</td>";
            echo "<td>";
            Html::autocompletionTextField($this,"hourbyday",array('size' => "5"));
             echo "</td></tr>";

            echo "<input type='hidden' name='needvalidationforcri' value='0'>";

            break;
         case self::HOUR :
            echo "<tr class='tab_bg_1 top'><td>".$LANG['plugin_manageentities']['setup'][8]."</td>";
            echo "<td>";
            Dropdown::showYesNo("needvalidationforcri",$this->fields["needvalidationforcri"]);
            echo "</td></tr>";


            echo "<input type='hidden' name='hourbyday' value='0'>";

            break;
      }

      echo "<input type='hidden' name='id' value='1'>";

      echo "<tr><th colspan='2'><input type=\"submit\" name=\"update_config\" class=\"submit\"
         value=\"".$LANG["buttons"][2]."\" ></th></tr>";

      echo "</table></div>";
      Html::closeForm();
   }

   function dropdownConfigType($name, $value = 0) {
      global $LANG;

      $configTypes = array(self::DAY => $LANG['plugin_manageentities']['setup'][6],
         self::HOUR => $LANG['plugin_manageentities']['setup'][7]);

      if (!empty($configTypes)) {

         return Dropdown::showFromArray($name, $configTypes, array('value'  => $value));
      } else {
         return false;
      }
   }

   public static function getInstance(){
      if(!isset(self::$instance)){
         $temp = new PluginManageentitiesConfig();
         $temp->getFromDB('1');
         self::$instance = $temp;
      }

      return self::$instance;
   }

//   function getConfigType($value) {
//      global $LANG;
//
//      switch ($value) {
//         case self::DAY :
//            return $LANG['plugin_manageentities']['setup'][6];
//         case self::HOUR :
//            return $LANG['plugin_manageentities']['setup'][7];
//         default :
//            return "";
//      }
//   }

}

?>