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

function plugin_manageentities_install() {
   global $LANG,$DB;
   
   include_once (GLPI_ROOT."/plugins/manageentities/inc/profile.class.php");
   include_once (GLPI_ROOT."/plugins/manageentities/inc/preference.class.php");
   include_once (GLPI_ROOT."/plugins/manageentities/inc/config.class.php");
   include_once (GLPI_ROOT."/plugins/manageentities/inc/cridetail.class.php");

   $update = false;
   $update190 = false;
   if (!TableExists("glpi_plugin_manageentities_profiles") && !TableExists("glpi_plugin_manageentities_contractstates")) {

      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/empty-1.9.0.sql");
      
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('1', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][0]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('2', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][1]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('3', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][2]."');";
      $DB->query($query);
   
   } else if (TableExists("glpi_plugin_manageentity_profiles") && !TableExists("glpi_plugin_manageentity_preference")) {
      
      $update = true;
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.4.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.5.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.5.1.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.6.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.9.0.sql");
      $update190 = true;
      
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('1', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][0]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('2', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][1]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('3', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][2]."');";
      $DB->query($query);

   } else if (TableExists("glpi_plugin_manageentity_profiles") && FieldExists("glpi_plugin_manageentity_profiles","interface")) {
      
      $update = true;
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.5.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.5.1.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.6.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.9.0.sql");
      $update190 = true;
      
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('1', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][0]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('2', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][1]."');";
      $DB->query($query);
      $query="INSERT INTO `glpi_plugin_manageentities_critypes` ( `id`, `name`) VALUES ('3', '".$LANG['plugin_manageentities']['infoscompactivitesreport'][2]."');";
      $DB->query($query);

   } else if (TableExists("glpi_plugin_manageentity_config") && !FieldExists("glpi_plugin_manageentity_config","hourbyday")) {
      
      $update = true;
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.5.1.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.6.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.9.0.sql");
      $update190 = true;

   } else if (TableExists("glpi_plugin_manageentity_profiles") && !TableExists("glpi_plugin_manageentities_profiles")) {
      
      $update = true;
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.6.0.sql");
      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.9.0.sql");

   } else if (TableExists("glpi_plugin_manageentities_profiles") && !TableExists("glpi_plugin_manageentities_contractstates")) {

      $DB->runFile(GLPI_ROOT ."/plugins/manageentities/sql/update-1.9.0.sql");
      $update190 = true;
   }
   
   if ($update) {
      
      $index=array(
      'FK_contracts' => array('glpi_plugin_manageentities_contracts'),
      'FK_contracts_2' => array('glpi_plugin_manageentities_contracts'),
      'FK_entities' => array('glpi_plugin_manageentities_contracts','glpi_plugin_manageentities_contacts'),
      'FK_entity' => array('glpi_plugin_manageentities_contracts','glpi_plugin_manageentities_contacts'),
      'FK_contacts' => array('glpi_plugin_manageentities_contacts'),
      'FK_contacts_2' => array('glpi_plugin_manageentities_contacts'));


      foreach ($index as $oldname => $newnames) {
         foreach ($newnames as $table) {
            if (isIndex($table, $oldname)) {
               $query="ALTER TABLE `$table` DROP INDEX `$oldname`;";
               $result=$DB->query($query);
            }
         }
      }
      
      $query_="SELECT *
            FROM `glpi_plugin_manageentities_profiles` ";
      $result_=$DB->query($query_);
      if ($DB->numrows($result_)>0) {

         while ($data=$DB->fetch_array($result_)) {
            $query="UPDATE `glpi_plugin_manageentities_profiles`
                  SET `profiles_id` = '".$data["id"]."'
                  WHERE `id` = '".$data["id"]."';";
            $result=$DB->query($query);

         }
      }
      
      $query="ALTER TABLE `glpi_plugin_manageentities_profiles`
               DROP `name` ;";
      $result=$DB->query($query);
   }
   
   if($update190){
      $config = PluginManageentitiesConfig::getInstance();
      if ($config->fields["backup"]==1) {

         $criDetail = new PluginManageentitiesCriDetail();

         $query = "SELECT `glpi_documents`.`id` AS doc_id,
                          `glpi_documents`.`tickets_id` AS doc_tickets_id,
                          `glpi_plugin_manageentities_cridetails`.`id` AS cri_id,
                          `glpi_plugin_manageentities_cridetails`.`tickets_id` AS cri_tickets_id
              FROM `glpi_documents`
              LEFT JOIN `glpi_plugin_manageentities_cridetails`
                  ON (`glpi_documents`.`id` = `glpi_plugin_manageentities_cridetails`.`documents_id`)
              WHERE `glpi_documents`.`documentcategories_id` = '".
                  $config->fields["documentcategories_id"]."' ";

         $result = $DB->query($query);
         $number = $DB->numrows($result);

         if ($number !="0") {
            while ($data=$DB->fetch_array($result)) {
               if($data['cri_tickets_id'] == '0'){
                  $criDetail->update(array('id'=> $data['cri_id'],
                     'tickets_id'=>$data['doc_tickets_id']));
               }
            }
         }
      }
   }
   
   $rep_files_manageentities = GLPI_PLUGIN_DOC_DIR."/manageentities";
   if (!is_dir($rep_files_manageentities))
      mkdir($rep_files_manageentities);
  
   PluginManageentitiesProfile::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
  
   $pref_ID=PluginManageentitiesPreference::checkIfPreferenceExists(Session::getLoginUserID());
   if ($pref_ID) {
      $pref_value=PluginManageentitiesPreference::checkPreferenceValue(Session::getLoginUserID());
      if ($pref_value==1) {
         $_SESSION["glpi_plugin_manageentities_loaded"]=0;
      }
   }

   return true;
}

function plugin_manageentities_uninstall() {
   global $DB;

   $tables = array("glpi_plugin_manageentities_contracts",
               "glpi_plugin_manageentities_contacts",
               "glpi_plugin_manageentities_profiles",
               "glpi_plugin_manageentities_preferences",
               "glpi_plugin_manageentities_configs",
               "glpi_plugin_manageentities_critypes",
               "glpi_plugin_manageentities_criprices",
               "glpi_plugin_manageentities_contractdays",
               "glpi_plugin_manageentities_critechnicians",
               "glpi_plugin_manageentities_cridetails",
               "glpi_plugin_manageentities_contractstates",
               "glpi_plugin_manageentities_taskcategories");

   foreach($tables as $table)
      $DB->query("DROP TABLE IF EXISTS `$table`;");
   
   //old versions   
   $tables = array("glpi_plugin_manageentity_contracts",
               "glpi_plugin_manageentity_documents",
               "glpi_plugin_manageentity_contacts",
               "glpi_plugin_manageentity_profiles",
               "glpi_plugin_manageentity_preference",
               "glpi_plugin_manageentity_config",
               "glpi_dropdown_plugin_manageentity_critype",
               "glpi_plugin_manageentity_criprice",
               "glpi_plugin_manageentity_dayforcontract",
               "glpi_plugin_manageentity_critechnicians",
               "glpi_plugin_manageentity_cridetails");

   foreach($tables as $table)
      $DB->query("DROP TABLE IF EXISTS `$table`;");
      
   $rep_files_manageentities = GLPI_PLUGIN_DOC_DIR."/manageentities";

   Toolbox::deleteDir($rep_files_manageentities);
   
   return true;
}

function plugin_manageentities_addLeftJoin($type,$ref_table,$new_table,$linkfield,&$already_link_tables) {

   switch ($new_table) {
      
      case "glpi_plugin_manageentities_criprices" :
         $out=" LEFT JOIN `$new_table` ON (`$ref_table`.`id` = `$new_table`.`plugin_manageentities_critypes_id` AND `$new_table`.`entities_id` = '".$_SESSION["glpiactive_entity"]."') ";
         return $out;
         break;
   }

   return "";
}

function plugin_manageentities_forceGroupBy($type) {

   return true;
   switch ($type) {
      case 'PluginManageentitiesCriType' :
         return true;
         break;

   }
   return false;
}

function plugin_manageentities_giveItem($type,$ID,$data,$num) {

   $searchopt=&Search::getOptions($type);
   $table=$searchopt[$ID]["table"];
   $field=$searchopt[$ID]["field"]; 
   
   switch ($type) {
      case 'PluginManageentitiesCriType':
         switch ($table.'.'.$field) {
            case "glpi_plugin_manageentities_criprices.price" :
               $out = Html::formatnumber($data["ITEM_$num"],2);
               return $out;
               break;
          
         }
      break;
   }
   return "";
}

// Hook done on purge item case
function plugin_pre_item_purge_manageentities($item) {
  
  $PluginManageentitiesConfig=new PluginManageentitiesConfig();
  $PluginManageentitiesCriDetail=new PluginManageentitiesCriDetail();
  $PluginManageentitiesEntity=new PluginManageentitiesEntity();
  
   switch (get_class($item)) {
      case 'Entity' :
         $temp = new PluginManageentitiesContract();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));
         
         $temp = new PluginManageentitiesContact();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));

         $temp = new PluginManageentitiesCriPrice();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));

         $temp = new PluginManageentitiesContractDay();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));
         
         $temp = new PluginManageentitiesCriDetail();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));

         $temp = new PluginManageentitiesContractState();
         $temp->deleteByCriteria(array('entities_id' => $item->getField('id')));
         break;
      case 'Ticket' :
         $temp = new PluginManageentitiesCriTechnician();
         $temp->deleteByCriteria(array('tickets_id' => $item->getField('id')));
         break;
      case 'Contract' :
         $temp = new PluginManageentitiesContract();
         $temp->deleteByCriteria(array('contracts_id' => $item->getField('id')));

         $temp = new PluginManageentitiesContractDay();
         $temp->deleteByCriteria(array('contracts_id' => $item->getField('id')));

         $temp = new PluginManageentitiesCriDetail();
         $temp->deleteByCriteria(array('contracts_id' => $item->getField('id')));
         break;
      case 'Contact' :
         $temp = new PluginManageentitiesContact();
         $temp->deleteByCriteria(array('contacts_id' => $item->getField('id')));
         break;
      case 'Document' :
         $plugin = new Plugin();
         if ($item->getField('id') && $plugin->isActivated("manageentities") && $PluginManageentitiesConfig->GetfromDB(1)) {
            if ($PluginManageentitiesConfig->fields["documentcategories_id"]==$item->getField("documentcategories_id")) {
               $PluginManageentitiesCriDetail->deleteByCriteria(array('documents_id' => $item->getField('id')));
            }
         }
         break;
      case 'TaskCategory' :
         $temp = new PluginManageentitiesTaskCategory();
         $temp->deleteByCriteria(array('taskcategories_id' => $item->getField('id')));
         break;
   }
}

// Hook done on transfered item case
function plugin_item_transfer_manageentities($parm) {
//TODO work in progress
//   Session::addMessageAfterRedirect("Transfer Manageentities Hook ".$parm['type']." ".$parm['id']." -> ".
//                                     $parm['newID']);
//
//   switch ($parm['type']) {
//      case 'Contract' :
//         $contract = new Contract();
//         $contract->getFromDB($parm['id']);
//         $pluginContract = new PluginManageentitiesContract();
//         $contractDay = new PluginManageentitiesContractDay();
//         $criDetail =new PluginManageentitiesCriDetail();
//         $document =new Document();//voir avec XACA
//         Toolbox::logDebug($contract);
//
//         $restrict = "`glpi_plugin_manageentities_contracts`.`contracts_id` = '".$parm['id']."'";
//         $allPluginContracts = getAllDatasFromTable('glpi_plugin_manageentities_contracts',$restrict);
//         if(!empty($allPluginContracts)){
//            foreach($allPluginContracts as $onePluginContract){
//               $pluginContract->update(array('id'=> $onePluginContract['id'],
//                                             'entities_id'=>$contract->fields['entities_id']));
//
//            }
//         }
//         $temp = new PluginManageentitiesContractDay();
//         $temp->deleteByCriteria(array('contracts_id' => $item->getField('id')));
//
//         $temp = new PluginManageentitiesCriDetail();
//         $temp->deleteByCriteria(array('contracts_id' => $item->getField('id')));
 //        break;
//   }
}

// Define dropdown relations
function plugin_manageentities_getDatabaseRelations() {

   $plugin = new Plugin();

   if ($plugin->isActivated("manageentities"))
      return array("glpi_plugin_manageentities_critypes"=>array("glpi_plugin_manageentities_criprices"=>"plugin_manageentities_critypes_id",
                                                                "glpi_plugin_manageentities_cridetails"=>"plugin_manageentities_critypes_id",
                                                                "glpi_plugin_manageentities_contractdays"=>"plugin_manageentities_critypes_id"),
                     "glpi_contracts"=>array("glpi_plugin_manageentities_contracts"=>"contracts_id",
                                             "glpi_plugin_manageentities_contractdays"=>"contracts_id",
                                             "glpi_plugin_manageentities_cridetails"=>"contracts_id"),
                     "glpi_contacts"=>array("glpi_plugin_manageentities_contacts"=>"contacts_id"),
                     "glpi_users"=>array("glpi_plugin_manageentities_preferences"=>"users_id",
                                          "glpi_plugin_manageentities_critechnicians"=>"users_id"),
                     "glpi_documents"=>array("glpi_plugin_manageentities_cridetails"=>"documents_id"),
                     "glpi_documentcategories"=>array("glpi_plugin_manageentities_configs"=>"documentcategories_id"),
                     "glpi_tickets"=>array("glpi_plugin_manageentities_critechnicians"=>"tickets_id"),
                     "glpi_profiles" => array ("glpi_plugin_manageentities_profiles" => "profiles_id"),
                     "glpi_entities"=>array("glpi_plugin_manageentities_contracts"=>"entities_id",
                                            "glpi_plugin_manageentities_contacts"=>"entities_id",
                                            "glpi_plugin_manageentities_criprices"=>"entities_id",
                                            "glpi_plugin_manageentities_contractdays"=>"entities_id",
                                            "glpi_plugin_manageentities_cridetails"=>"entities_id",
                                            "glpi_plugin_manageentities_contractstates"=>"entities_id"),
                     "glpi_plugin_manageentities_contractstates"=>array("glpi_plugin_manageentities_contractdays"=>"plugin_manageentities_contractstates_id"),
                     "glpi_taskcategories"=>array("glpi_plugin_manageentities_taskcategories"=>"taskcategories_id"));
   else
      return array();
}

// Define Dropdown tables to be manage in GLPI :
function plugin_manageentities_getDropdown() {
   global $LANG;

   $plugin = new Plugin();

   if ($plugin->isActivated("manageentities"))
      return array('PluginManageentitiesCriType'=>$LANG['plugin_manageentities'][14],
                   'PluginManageentitiesContractState'=>$LANG['plugin_manageentities'][2]);
   else
      return array();
}

?>