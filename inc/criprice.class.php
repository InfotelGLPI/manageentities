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

class PluginManageentitiesCriPrice extends CommonDBTM {
   
   function getFromDBbyType($plugin_manageentities_critypes_id,$entities_id) {
      global $DB;
      
      $query = "SELECT *
      FROM `".$this->getTable()."`
      WHERE `plugin_manageentities_critypes_id` = '" . $plugin_manageentities_critypes_id . "'
      AND `entities_id` = '".$entities_id."' ";
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
  
   function addCriPrice($values) {

      if ($this->getFromDBbyType($values["plugin_manageentities_critypes_id"],
                                    $values["entities_id"])) {

         $this->update(array(
           'id'=>$this->fields['id'],
           'price'=>$values["price"],
           'entities_id'=>$values["entities_id"]));
      } else {

         $this->add(array(
           'plugin_manageentities_critypes_id'=>$values["plugin_manageentities_critypes_id"],
           'price'=>$values["price"],
           'entities_id'=>$values["entities_id"]));
      }
   }

   function checkTypeByEntity($entities_id) {
      global $DB;
      
      $types=array();
      $typecri=array();
      $notused=array();
      
      $query = "SELECT plugin_manageentities_critypes_id 
        FROM `".$this->getTable()."` 
        WHERE `entities_id` = '".$entities_id."'";
        if ($result = $DB->query($query)) {
          while ($data=$DB->fetch_array($result)) {
              $typecri[]=$data["plugin_manageentities_critypes_id"];
          }     
        }
       
      $querytype = "SELECT id 
        FROM `glpi_plugin_manageentities_critypes` ";
        if ($resulttype = $DB->query($querytype)) {
          while ($datatype=$DB->fetch_array($resulttype)) {
            $types[]=$datatype["id"];
          }     
        }
      
      $notused=array_diff($types, $typecri);
      
      return $notused;
   }

   static function showform($plugin_manageentities_critypes_id, $entities_id=0) {
      global $DB,$LANG,$CFG_GLPI;

      $target = $CFG_GLPI['root_doc']."/plugins/manageentities/front/config.form.php";
      $used=array();
      if($entities_id=='0'){
         $condition = getEntitiesRestrictRequest(" AND ","glpi_plugin_manageentities_criprices",'','',false);
      } else {
         $condition = " AND `glpi_plugin_manageentities_criprices`.`entities_id` = '".$entities_id."' ";
      }
      $query = "SELECT `glpi_plugin_manageentities_criprices`.*,
                     `glpi_plugin_manageentities_critypes`.`name`
         FROM `glpi_plugin_manageentities_criprices`,`glpi_plugin_manageentities_critypes`
         WHERE `glpi_plugin_manageentities_critypes`.`id` = `glpi_plugin_manageentities_criprices`.`plugin_manageentities_critypes_id` 
        AND `glpi_plugin_manageentities_critypes`.`id` = '".$plugin_manageentities_critypes_id."' "
      . $condition;
      $query.= " ORDER BY `glpi_plugin_manageentities_critypes`.`name`,`glpi_plugin_manageentities_criprices`.`entities_id`";

      if ($result = $DB->query($query)) {
         $number = $DB->numrows($result);
         if ($number != 0) {
            $rand=mt_rand();
            echo "<form method='post' name='massiveaction_form_pricecri$rand' 
                                    id='massiveaction_form_pricecri$rand' action=\"$target\">";
            echo "<div align='center'>";
            echo "<table class='tab_cadre_fixe' cellpadding='5'>";
            echo "<tr><th></th>";
            if (Session::isMultiEntitiesMode())
               echo "<th>".$LANG['entity'][0]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][15]."</th>";
            echo "</tr>";

            while($ligne= mysql_fetch_array($result)) {
            
               $ID=$ligne["id"];
               $used[]=$ligne["plugin_manageentities_critypes_id"];
               
               echo "<tr class='tab_bg_1'>";
               
               echo "<td>";
               echo "<input type='hidden' name='id' value='$ID'>";
               echo "<input type='checkbox' name='item_price[$ID]' value='1'>";
               echo "</td>";
               
               
               if (Session::isMultiEntitiesMode())
                  echo "<td class='center'>".Dropdown::getDropdownName("glpi_entities",
                                                                     $ligne['entities_id'])."</td>";
               echo "<td>".Dropdown::getDropdownName("glpi_plugin_manageentities_critypes",
                                          $ligne["plugin_manageentities_critypes_id"])."</td>";
               echo "<td>".Html::formatNumber($ligne["price"],true)."</td>";
               
            }
            
            Html::openArrowMassives("massiveaction_form_pricecri$rand", true);
            Html::closeArrowMassives(array('delete_price' => $LANG['buttons'][6]));
            
            echo "</table>";
            echo "</div>";
            Html::closeForm();
         }
      }
      $self = new self();
      if($entities_id=='0'){
         $entities_id = $_SESSION["glpiactive_entity"];
      }
      if (!$self->getFromDBbyType($plugin_manageentities_critypes_id,$entities_id)) {
         echo "<form method='post'  action=\"$target\">";
         echo "<table class='tab_cadre_fixe' cellpadding='5'>";
         echo "<tr><th>".$LANG['plugin_manageentities']['setup'][4]."</th></tr>";
         echo "<tr class='tab_bg_1 center'>";
         echo "<td>";
         echo "<input type='hidden' name='plugin_manageentities_critypes_id' value='".$plugin_manageentities_critypes_id."'>";
         echo "<input type='text' name='price' size='16'>";
         echo "<input type='hidden' name=\"entities_id\" value='".$_SESSION["glpiactive_entity"]."'></td>";
         echo "</tr>";
         echo "<tr class='tab_bg_2 center'>";
         echo "<td>";
         echo "<input type='submit' name='add_price' value=\"".$LANG['buttons'][2]."\" class='submit' ></div></td></tr>";
         echo "</table>";
         Html::closeForm();
      }
   }

}

?>