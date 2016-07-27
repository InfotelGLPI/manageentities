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

class PluginManageentitiesContact extends CommonDBTM {
   
   function canView() {
      return plugin_manageentities_haveRight("manageentities","r");
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight("manageentities","w");
   }
   
   function addContactByDefault($contacts_id,$entities_id) {

      global $DB;

      $query = "SELECT *
        FROM `".$this->getTable()."`
        WHERE `entities_id` = '".$entities_id."' ";
      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number) {
         while ($data=$DB->fetch_array($result)) {

            $query_nodefault = "UPDATE `".$this->getTable()."`
            SET `is_default` = '0' WHERE `id` = '".$data["id"]."' ";
            $result_nodefault = $DB->query($query_nodefault);
         }
      }

      $query_default = "UPDATE `".$this->getTable()."`
        SET `is_default` = '1' WHERE `id` ='".$contacts_id."' ";
      $result_default = $DB->query($query_default);
   }

   function addContact($contacts_id,$entities_id) {

      $this->add(array('contacts_id'=>$contacts_id,'entities_id'=>$entities_id));

   }

   function deleteContact($ID) {

      $this->delete(array('id'=>$ID));
   }
  
   function showContacts($instID) {
      global $DB,$CFG_GLPI, $LANG;


      $query = "SELECT `glpi_contacts`.*, `".$this->getTable()."`.`id` as contacts_id, `".$this->getTable()."`.`is_default`
        FROM `".$this->getTable()."`, `glpi_contacts`
        WHERE `".$this->getTable()."`.`contacts_id`=`glpi_contacts`.`id`
        AND `".$this->getTable()."`.`entities_id` = '$instID'
        ORDER BY `glpi_contacts`.`name`";

      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number) {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadre_fixe center'>";
         echo "<tr><th colspan='9'>".$LANG['plugin_manageentities'][7]."</th></tr>";
         echo "<tr><th>".$LANG['common'][16]."</th>";
         echo "<th>".$LANG['help'][35]."</th>";
         echo "<th>".$LANG['help'][35]." 2</th>";
         echo "<th>".$LANG['common'][42]."</th>";
         echo "<th>".$LANG['financial'][30]."</th>";
         echo "<th>".$LANG['setup'][14]."</th>";
         echo "<th>".$LANG['common'][17]."</th>";
         echo "<th>".$LANG['plugin_manageentities'][12]."</th>";
         if ($this->canCreate())
            echo "<th>&nbsp;</th>";
         echo "</tr>";

         while ($data=$DB->fetch_array($result)) {
            $ID=$data["contacts_id"];
            echo "<tr class='tab_bg_1'>";
            echo "<td class='left'><a href='".$CFG_GLPI["root_doc"]."/front/contact.form.php?id=".$data["id"]."'>".$data["name"]." ".$data["firstname"]."</a></td>";
            echo "<td class='center' width='100'>".$data["phone"]."</td>";
            echo "<td class='center' width='100'>".$data["phone2"]."</td>";
            echo "<td class='center' width='100'>".$data["mobile"]."</td>";
            echo "<td class='center' width='100'>".$data["fax"]."</td>";
            echo "<td class='center'><a href='mailto:".$data["email"]."'>".$data["email"]."</a></td>";
            echo "<td class='center'>".Dropdown::getDropdownName("glpi_contacttypes",$data["contacttypes_id"])."</td>";
            echo "<td class='center'>";
            
            if ($data["is_default"]) {
               echo $LANG['choice'][1];
            } else {
               Html::showSimpleForm($CFG_GLPI['root_doc'].'/plugins/manageentities/front/entity.php',
                                    'contactbydefault',
                                    $LANG['choice'][0],
                                    array('contacts_id' => $ID,'entities_id' => $instID));
            }
            echo "</td>";

            if ($this->canCreate()) {
               echo "<td class='center' class='tab_bg_2'>";
               Html::showSimpleForm($CFG_GLPI['root_doc'].'/plugins/manageentities/front/entity.php',
                                    'deletecontacts',
                                    $LANG['buttons'][6],
                                    array('id' => $ID));
               echo "</td>";
            }
            echo "</tr>";

         }
         
         if ($this->canCreate()) {
            echo "<tr class='tab_bg_1'><td colspan='8' class='center'>";
            echo "<input type='hidden' name='entities_id' value='$instID'>";
            Dropdown::show('Contact', array('name' => "contacts_id"));
            echo "</td><td class='center'><input type='submit' name='addcontacts' value=\"".$LANG['buttons'][8]."\" class='submit'></td>";
            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();
         
      } else {

         if ($this->canCreate()) {
            echo "<form method='post' action=\"./entity.php\">";
            echo "<table class='tab_cadre_fixe center' width='95%'>";

            echo "<tr class='tab_bg_1'><th colspan='2'>".$LANG['plugin_manageentities'][7]."</tr><tr><td class='tab_bg_2 center'>";
            echo "<input type='hidden' name='entities_id' value='$instID'>";
            Dropdown::show('Contact', array('name' => "contacts_id"));
            echo "</td><td class='center tab_bg_2'>";
            echo "<input type='submit' name='addcontacts' value=\"".$LANG['buttons'][8]."\" class='submit'>";
            echo "</td></tr>";

            echo "</table></div>";
            Html::closeForm();
         }
      }
   }
}

?>