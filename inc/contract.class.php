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

class PluginManageentitiesContract extends CommonDBTM {

   const MANAGEMENT_NONE = 0;
   const MANAGEMENT_QUARTERLY = 1;
   const MANAGEMENT_ANNUAL = 2;

   const TYPE_NONE=0;
   const TYPE_HOUR = 1;
   const TYPE_INTERVENTION = 2;
   const TYPE_UNLIMITED = 3;

   static function getTypeName(){
      global $LANG;

      return $LANG['plugin_manageentities']['title'][4];
   }

   function canCreate() {
      return plugin_manageentities_haveRight("manageentities","w");
   }

   function canView() {
      return plugin_manageentities_haveRight("manageentities","r");
   }
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      if ($item->getType()=='Contract') {
         return $LANG['plugin_manageentities']['title'][3];
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      if (get_class($item)=='Contract') {

         self::showForContract($item);

         if(plugin_manageentities_haveRight('manageentities','w')){
            PluginManageentitiesContractDay::addNewContractDay($item);
         }
         if(plugin_manageentities_haveRight('manageentities', 'r')){
            PluginManageentitiesContractDay::showForContract($item);
         }
      }
      return true;
   }

   function addContractByDefault($id,$entities_id) {
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
        SET `is_default` = '1' WHERE `id` = '".$id."' ";
      $result_default = $DB->query($query_default);
   }

   function addContract($contracts_id,$entities_id) {

      $this->add(array('contracts_id'=>$contracts_id,'entities_id'=>$entities_id));

   }

   function deleteContract($ID) {

      $this->delete(array('id'=>$ID));
   }


   static function showForContract(Contract $contract){
      global $LANG;

      $rand=mt_rand();
      $canView = $contract->can($contract->fields['id'], 'r');
      $canEdit = $contract->can($contract->fields['id'], 'w');
      $config=PluginManageentitiesConfig::getInstance();

      if(!$canView) return false;

      $restrict = "`glpi_plugin_manageentities_contracts`.`entities_id` = '".
                        $contract->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_contracts`.`contracts_id` = '".
                        $contract->fields['id']."'";
      $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
      $pluginContract = reset($pluginContracts);

      if($canEdit){
         echo "<form method='post' name='contract_form$rand' id='contract_form$rand'
               action='".Toolbox::getItemTypeFormURL('PluginManageentitiesContract')."'>";
      }

      echo "<div align='spaced'><table class='tab_cadre_fixe center'>";

      echo "<tr><th colspan='4'>".PluginManageentitiesContract::getTypeName(0)."</th></tr>";

      echo "<tr class='tab_bg_1'><td>".$LANG['plugin_manageentities']['contract'][0]."</td>";
      echo "<td>";
      Html::showDateFormItem("date_signature",$pluginContract['date_signature']);
      echo "</td><td>".$LANG['plugin_manageentities']['contract'][1]."</td><td>";
      Html::showDateFormItem("date_renewal", $pluginContract['date_renewal']);
      echo "</td></tr>";

      if($config->fields['hourorday'] == '1'){
         echo "<tr class='tab_bg_1'><td>".$LANG['plugin_manageentities']['contract'][2]."</td>";
         echo "<td>";
         PluginManageentitiesContract::dropdownContractManagement("management", $pluginContract['management']);
         echo "</td><td>".$LANG['plugin_manageentities']['contract'][3]."</td><td>";
         PluginManageentitiesContract::dropdownContractType("contract_type", $pluginContract['contract_type']);
         echo "</td></tr>";
      }

      echo "<tr class='tab_bg_1'>";
      echo "<input type='hidden' name='contracts_id' value='".$contract->fields['id']."'>";
      echo "<input type='hidden' name='entities_id' value='".$contract->fields['entities_id']."'>";
      echo "<input type='hidden' name='is_default' value='0'>";

      if($canEdit){
         if(empty($pluginContract)){
            echo "<td class='center' colspan='4'>";
            echo "<input type='submit' name='addcontract' value=\"".$LANG['buttons'][8]."\" class='submit'>";
         } else {
            echo "<input type='hidden' name='id' value='".$pluginContract['id']."'>";
            echo "<td class='center' colspan='2'>";
            echo "<input type='submit' name='updatecontract' value='".$LANG['buttons'][7]."' class='submit'>";
            echo "</td><td class='center' colspan='2'>";
            echo "<input type='submit' name='delcontract' value='".$LANG['buttons'][6]."' class='submit'>";
         }
         echo "</td>";
      }
      echo "</tr>";
      echo "</table></div>";
      if($canEdit){
         Html::closeForm();
      }
   }


   function showContracts($instID) {
      global $DB,$CFG_GLPI, $LANG;

      $config = PluginManageentitiesConfig::getInstance();

      $query = "SELECT `glpi_contracts`.*,
                       `".$this->getTable()."`.`contracts_id`,
                       `".$this->getTable()."`.`management`,
                       `".$this->getTable()."`.`contract_type`,
                       `".$this->getTable()."`.`is_default`,
                       `".$this->getTable()."`.`id` as myid
        FROM `".$this->getTable()."`, `glpi_contracts`
        WHERE `".$this->getTable()."`.`contracts_id` = `glpi_contracts`.`id`
        AND `".$this->getTable()."`.`entities_id` = '$instID'
        ORDER BY `glpi_contracts`.`begin_date`, `glpi_contracts`.`name`";
      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number) {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadre_fixe center'>";
         echo "<tr><th colspan='7'>".$LANG['plugin_manageentities'][3]."</th></tr>";
         echo "<tr><th>".$LANG['common'][16]."</th>";
         echo "<th>".$LANG['financial'][4]."</th>";
         echo "<th>".$LANG['common'][25]."</th>";
         if($config->fields['hourorday'] == '1'){
            echo "<th>".$LANG['plugin_manageentities']['contract'][2]."</th>";
            echo "<th>".$LANG['plugin_manageentities']['contract'][3]."</th>";
         } else if($config->fields['hourorday'] == '0' && $config->fields['useprice'] == '1') {
            echo "<th>".$LANG['plugin_manageentities']['contract'][3]."</th>";
         }
         echo "<th>".$LANG['plugin_manageentities'][13]."</th>";
         if ($this->canCreate())
            echo "<th>&nbsp;</th>";
         echo "</tr>";

         $used = array();

         while ($data=$DB->fetch_array($result)) {
            $used[]= $data["contracts_id"];

            echo "<tr class='tab_bg_1".($data["is_deleted"]=='1'?"_2":"")."'>";
            echo "<td><a href=\"".$CFG_GLPI["root_doc"]."/front/contract.form.php?id=".$data["contracts_id"]."\">".$data["name"]."";
            if ($_SESSION["glpiis_ids_visible"]||empty($data["name"])) echo " (".$data["contracts_id"].")";
               echo "</a></td>";
            echo "<td class='center'>".$data["num"]."</td>";
            echo "<td class='center'>".nl2br($data["comment"])."</td>";
            if($config->fields['hourorday'] == '1'){
               echo "<td class='center'>".self::getContractManagement($data["management"])."</td>";
               echo "<td class='center'>".self::getContractType($data['contract_type'])."</td>";
            } else if($config->fields['hourorday'] == '0' && $config->fields['useprice'] == '1'){
               echo "<td class='center'>".self::getContractType($data['contract_type'])."</td>";
            }
            echo "<td class='center'>";
            if ($data["is_default"]) {
               echo $LANG['choice'][1];
            } else {
               Html::showSimpleForm($CFG_GLPI['root_doc'].'/plugins/manageentities/front/entity.php',
                                    'contractbydefault',
                                    $LANG['choice'][0],
                                    array('myid' => $data["myid"],'entities_id' => $instID));
            }
            echo "</td>";
            
            if ($this->canCreate()) {
               echo "<td class='center' class='tab_bg_2'>";
               Html::showSimpleForm($CFG_GLPI['root_doc'].'/plugins/manageentities/front/entity.php',
                                    'deletecontracts',
                                    $LANG['buttons'][6],
                                    array('id' => $data["myid"]));

               echo "</td>";
            }
         
            echo "</tr>";

         }

         if ($this->canCreate()) {
            if($config->fields['hourorday'] == '1'){
               echo "<tr class='tab_bg_1'><td colspan='6' class='center'>";
            } else if($config->fields['hourorday'] == '0' && $config->fields['useprice'] == '1'){
               echo "<tr class='tab_bg_1'><td colspan='5' class='center'>";
            } else {
               echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            }
            echo "<input type='hidden' name='entities_id' value='$instID'>";
            Dropdown::show('Contract', array('name' => "contracts_id",
                                             'used' => $used));
            echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"".$LANG['buttons'][8]."\" class='submit'></td>";
            echo "</tr>";
         }
         echo "</table></div>" ;
         Html::closeForm();
         
      }  else {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadre_fixe center'>";
         echo "<tr><th colspan='3'>".$LANG['plugin_manageentities'][3].":</th></tr>";
         echo "<tr><th>".$LANG['common'][16]."</th>";
         echo "<th>".$LANG['financial'][4]."</th>";
         echo "<th>".$LANG['common'][25]."</th>";

         echo "</tr>";
         if ($this->canCreate()) {
            echo "<tr class='tab_bg_1'><td class='center'>";
            echo "<input type='hidden' name='entities_id' value='$instID'>";
            Dropdown::show('Contract', array('name' => "contracts_id"));
            echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"".$LANG['buttons'][8]."\" class='submit'>";
            echo "</td><td></td>";
            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();
      }
   }

   static function dropdownContractManagement($name, $value = 0) {
      global $LANG;

      $contractManagements = array( self::MANAGEMENT_NONE => Dropdown::EMPTY_VALUE,
                                    self::MANAGEMENT_QUARTERLY=>$LANG['plugin_manageentities'][25],
                                    self::MANAGEMENT_ANNUAL=>$LANG['plugin_manageentities'][26]);

      if (!empty($contractManagements)) {

         return Dropdown::showFromArray($name, $contractManagements, array('value'  => $value));
      } else {
         return false;
      }
   }

   static function getContractManagement($value) {
      global $LANG;

      switch ($value) {
         case self::MANAGEMENT_NONE :
            return Dropdown::EMPTY_VALUE;
         case self::MANAGEMENT_QUARTERLY :
            return $LANG['plugin_manageentities'][25];
         case self::MANAGEMENT_ANNUAL :
            return $LANG['plugin_manageentities'][26];
         default :
            return "";
      }
   }

   static function dropdownContractType($name, $value = 0) {
      global $LANG;

      $contractTypes = array( self::TYPE_NONE => Dropdown::EMPTY_VALUE,
                              self::TYPE_HOUR=>$LANG['plugin_manageentities']['setup'][7],
                              self::TYPE_INTERVENTION=>$LANG['plugin_manageentities'][8],
                              self::TYPE_UNLIMITED=>$LANG['plugin_manageentities'][30]);

      if (!empty($contractTypes)) {

         return Dropdown::showFromArray($name, $contractTypes, array('value'  => $value));
      } else {
         return false;
      }
   }

   static function getContractType($value) {
      global $LANG;

      switch ($value) {
         case self::TYPE_NONE :
            return Dropdown::EMPTY_VALUE;
         case self::TYPE_HOUR :
            return $LANG['plugin_manageentities']['setup'][7];
         case self::TYPE_INTERVENTION :
            return $LANG['plugin_manageentities'][8];
         case self::TYPE_UNLIMITED :
            return $LANG['plugin_manageentities'][30];
         default :
            return "";
      }
   }

   static function getUnitContractType($config,$value){
      global $LANG;

      if($config->fields['hourorday'] == '1'){
         switch ($value) {
            case self::TYPE_HOUR :
               return $LANG['plugin_manageentities'][28];
            case self::TYPE_INTERVENTION :
               return $LANG['plugin_manageentities'][29];
            case self::TYPE_UNLIMITED :
               return $LANG['plugin_manageentities'][30];
         }
      } else {
         return $LANG['plugin_manageentities'][17];
      }

   }



}

?>