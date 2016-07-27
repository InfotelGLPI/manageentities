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

class PluginManageentitiesFollowUp extends CommonDBTM {
   
   function canView() {
      return plugin_manageentities_haveRight("manageentities","r");
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight("manageentities","w");
   }
   
   function showFollowUp($instID, $options=array()) {
      global $DB,$LANG;

      $output_type = HTML_OUTPUT;
      $contractDays = new PluginManageentitiesContractDay();
      $config = PluginManageentitiesConfig::getInstance();

      if($options['entities_id']!= '-1'){
         $sons = getSonsOf('glpi_entities',$options['entities_id']);
         $entities = "";
         $first = true;
         if(is_array($sons)){
            foreach($sons as $son){
               if($first){
                  $entities.="'".$son."'";
                  $first=false;
               } else {
                  $entities.=",'".$son."'";
               }
            }
         }
         $condition = " `glpi_plugin_manageentities_contractdays`.`entities_id` IN (".
            $entities.") ";
      } else {
         if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
            $condition = getEntitiesRestrictRequest("","glpi_plugin_manageentities_contractdays");
         } else {
            $condition = " `glpi_plugin_manageentities_contractdays`.`entities_id` IN ('".
               $instID."') ";
         }
      }

      $beginDate='';
      $endDate='';
      $contractState='';

      if(isset($options['begin_date']) && $options['begin_date']!= 'NULL'){
         $beginDate=" AND (`glpi_plugin_manageentities_contractdays`.`end_date` >='".
            $options['begin_date']."' OR `glpi_plugin_manageentities_contractdays`.`end_date` IS NULL)";
      }

      if(isset($options['end_date']) && $options['end_date']!= 'NULL'){
         $endDate=" AND (`glpi_plugin_manageentities_contractdays`.`begin_date` <= '".
            $options['end_date']."' OR `glpi_plugin_manageentities_contractdays`.`begin_date` IS NULL)";
      }

      if($options['contractstates_id']!= '0'){
         $contractState=" AND `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id` ='".
            $options['contractstates_id']."' ";
      } else if ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk') {
         $contractState=" AND `glpi_plugin_manageentities_contractstates`.`is_active` ='1' ";
      }

      $query ="SELECT `glpi_plugin_manageentities_contractdays`.`entities_id` AS entities_id,
                      `glpi_plugin_manageentities_contractdays`.`name` AS name_contractdays,
                      `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id` AS contractstates_id,
                      `glpi_plugin_manageentities_contractdays`.`id` AS contractdays_id,
                      `glpi_plugin_manageentities_contractdays`.`report` AS report,
                      `glpi_plugin_manageentities_contractdays`.`nbday` AS nbday,
                      `glpi_plugin_manageentities_contractdays`.`end_date` AS end_date,
                      `glpi_plugin_manageentities_contractdays`.`begin_date` AS begin_date,
                      `glpi_contracts`.`name` AS name,
                      `glpi_contracts`.`num` AS num,
                      `glpi_contracts`.`id` AS contracts_id,
                      `glpi_contracts`.`begin_date` AS contract_begin_date,
                      `glpi_contracts`.`duration` AS duration,
                      `glpi_plugin_manageentities_contracts`.`management` AS management,
                      `glpi_plugin_manageentities_contracts`.`contract_type` AS contract_type,
                      `glpi_plugin_manageentities_contracts`.`date_signature` AS date_signature,
                      `glpi_plugin_manageentities_contracts`.`date_renewal` AS date_renewal,
                      `glpi_entities`.`name` AS entities_name
               FROM `glpi_plugin_manageentities_contractdays`
                  LEFT JOIN `glpi_contracts`
                     ON (`glpi_contracts`.`id`
                     = `glpi_plugin_manageentities_contractdays`.`contracts_id`)
                  LEFT JOIN `glpi_plugin_manageentities_contracts`
                     ON (`glpi_contracts`.`id`
                     = `glpi_plugin_manageentities_contracts`.`contracts_id`)
                  LEFT JOIN `glpi_plugin_manageentities_contractstates`
                     ON (`glpi_plugin_manageentities_contractstates`.`id`
                     = `glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id`)
                  LEFT JOIN `glpi_entities`
                     ON (`glpi_entities`.`id`
                     = `glpi_plugin_manageentities_contractdays`.`entities_id`)
               WHERE $condition $beginDate $endDate $contractState
               ORDER BY `glpi_entities`.`name`,
                        `glpi_plugin_manageentities_contracts`.`date_signature` ASC,
                        `glpi_plugin_manageentities_contractdays`.`end_date` ASC";

      $res = $DB->query($query);
      $nbTot = ($res ? $DB->numrows($res) : 0);

      if ($res && $nbTot >0) {

         for ($i = 0 ; $data=$DB->fetch_assoc($res); $i++) {
            $contractType = $data['contract_type'];
            $management = $data['management'];
         }

         if ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk'
            && ($config->fields['hourorday'] == '1')) {

            echo "<div align='spaced'><table class='tab_cadre'>";
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center'>";
            echo $LANG['financial'][1]." ".
               PluginManageentitiesContract::getContractType($contractType)." ".
               PluginManageentitiesContract::getContractManagement($management)."</td>";
            echo "</tr>";
            echo "</table></div>";
         }

         echo "<div align='center'>";

         $nbcols = $DB->num_fields($res);
         $nbrows = $DB->numrows($res);
         $tot_credit = 0;
         $tot_conso = 0;
         $tot_reste = 0;
         $tot_depass = 0;
         $num = 1;
         echo Search::showHeader($output_type, $nbrows, $nbcols, false);
         echo Search::showNewLine($output_type);

         if ($_SESSION['glpiactiveprofile']['interface'] == 'central')
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['follow-up'][0],$num);
         echo Search::showHeaderItem($output_type, $LANG['financial'][1],$num);
         echo Search::showHeaderItem($output_type, $LANG['financial'][4],$num);
         echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['title'][5],$num);
         if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
            if($config->fields['hourorday'] == '1'){
               echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contract'][2],$num);
               echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contract'][3],$num);
            }
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities'][2],$num);
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contract'][0],$num);
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contract'][1],$num);
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contractday'][3],$num);
         }
         echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contractday'][4],$num);
         echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contractday'][5],$num);

         if($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk'
            && $contractType==PluginManageentitiesContract::TYPE_UNLIMITED){

         } else {
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contractday'][6],$num);
            echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['contractday'][7],$num);
            if($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               echo Search::showHeaderItem($output_type, $LANG['plugin_manageentities']['follow-up'][1],$num);
            }
         }

         echo Search::showEndLine($output_type);
         Session::initNavigateListItems("PluginManageentitiesContractDay");

         mysql_data_seek ($res, 0);

         for ($row_num = 0 ; $data=$DB->fetch_assoc($res); $row_num++) {
            $num = 1;

            Session::addToNavigateListItems("PluginManageentitiesContractDay",$data["contractdays_id"]);

            echo Search::showNewLine($output_type);

            $name_contract="";

            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               echo Search::showItem($output_type, $data['entities_name'], $num,$row_num);

               $link_contract = Toolbox::getItemTypeFormURL("Contract");
               $name_contract.= "<a href='".$link_contract."?id=".$data["contracts_id"]."'>";
            }

            if ($data["name"] == NULL){
               $name_contract.="(".$data["contracts_id"].")";
            } else {
               $name_contract.=$data["name"];
            }
            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               $name_contract.="</a>";
            }

            echo Search::showItem($output_type, $name_contract, $num,$row_num);
            echo Search::showItem($output_type, $data['num'], $num,$row_num);

            $name_period="";
            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               $link_period = Toolbox::getItemTypeFormURL("PluginManageentitiesContractDay");
               $name_period = "<a href='".$link_period."?id=".$data["contractdays_id"]."'>";
            }

            if ($data["name_contractdays"] == NULL){
               $name_period.="(".$data["contractdays_id"].")";
            } else {
               $name_period.=$data["name_contractdays"];
            }

            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               $name_period.="</a>";
            }

            echo Search::showItem($output_type, $name_period, $num,$row_num);
            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){
               if($config->fields['hourorday'] == '1'){
                  echo Search::showItem($output_type, PluginManageentitiesContract::getContractManagement($data['management']), $num,$row_num);
                  echo Search::showItem($output_type, PluginManageentitiesContract::getContractType($data['contract_type']), $num,$row_num);
               }
               echo Search::showItem($output_type, Dropdown::getDropdownName('glpi_plugin_manageentities_contractstates',$data['contractstates_id']), $num,$row_num);
               echo Search::showItem($output_type, Html::convDate($data['date_signature']), $num,$row_num);
               echo Search::showItem($output_type, Html::convDate($data['date_renewal']), $num,$row_num);
               echo Search::showItem($output_type, Html::convDate($data['end_date']), $num,$row_num);
            }

            $contractDays->getFromDB($data['contractdays_id']);
            $result = PluginManageentitiesContractDay::calConso($contractDays);
            if($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk'
               && $contractType==PluginManageentitiesContract::TYPE_UNLIMITED){
               $credit = PluginManageentitiesContract::getContractType($contractType);
            } else {
               $credit = $data['nbday']+$data['report'];
               $tot_credit+=$credit;

            }
            echo Search::showItem($output_type, $credit, $num,$row_num);
            echo Search::showItem($output_type, $result['conso'], $num,$row_num);
            $tot_conso+=$result['conso'];
            if($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk'
               && $contractType==PluginManageentitiesContract::TYPE_UNLIMITED){
            } else {
               echo Search::showItem($output_type, $result['reste'], $num,$row_num);
               $tot_reste+=$result['reste'];
               echo Search::showItem($output_type, $result['depass'], $num,$row_num);
               $tot_depass+=$result['depass'];
            }

            if ($_SESSION['glpiactiveprofile']['interface'] == 'central'){

               $and = "";
               if(!empty($data['begin_date'])){
                  $and.= " AND `date` >= '".$data['begin_date']."' ";
               }

               if(!empty($data['end_date'])){
                  $and.= " AND `date` <= ADDDATE('".$data['end_date']."', INTERVAL 1 DAY) ";
               }

               
               if($config->fields['useprice']=='0'){
                  $queryTicket = "SELECT `date`
                               FROM `glpi_tickets`
                               WHERE `entities_id` = '".$data['entities_id']."'
                               $and
                               ORDER BY `date` DESC
                               LIMIT 1";
               } else {
                  
                  $queryTicket = "SELECT `date`
                                 FROM `glpi_plugin_manageentities_cridetails`
                               WHERE `entities_id` = '".$data['entities_id']."'
                               $and
                               ORDER BY `date` DESC
                               LIMIT 1";
                           
               }
               $resTicket = $DB->query($queryTicket);
               $date = NULL;
               for ($i = 0 ; $dataTicket=$DB->fetch_assoc($resTicket); $i++) {
                  $date = Html::convDate($dataTicket['date']);
               }
               echo Search::showItem($output_type, $date, $num,$row_num);
            }

            echo Search::showEndLine($output_type);
         }

         if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
            /*if ((isset($_SESSION['glpiactive_entity_recursive'])
               && $_SESSION['glpiactive_entity_recursive'])
               || (isset($_SESSION['glpishowallentities'])
                  && $_SESSION['glpishowallentities'])){*/

               echo Search::showNewLine($output_type, true);

               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               if($config->fields['hourorday'] == '1'){
                  echo Search::showItem($output_type, '', $num,$row_num);
                  echo Search::showItem($output_type, '', $num,$row_num);
               }
               echo Search::showItem($output_type, $LANG['plugin_manageentities']['follow-up'][2], $num,$row_num);
               echo Search::showItem($output_type, $LANG['plugin_manageentities']['contractday'][5], $num,$row_num);
               echo Search::showItem($output_type, $LANG['plugin_manageentities']['contractday'][6], $num,$row_num);
               echo Search::showItem($output_type, $LANG['plugin_manageentities']['contractday'][7], $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);

               echo Search::showEndLine($output_type);

               echo Search::showNewLine($output_type);

               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);
               if($config->fields['hourorday'] == '1'){
                  echo Search::showItem($output_type, '', $num,$row_num);
                  echo Search::showItem($output_type, '', $num,$row_num);
               }
               echo Search::showItem($output_type, $tot_credit, $num,$row_num);
               echo Search::showItem($output_type, $tot_conso, $num,$row_num);
               echo Search::showItem($output_type, $tot_reste, $num,$row_num);
               echo Search::showItem($output_type, $tot_depass, $num,$row_num);
               echo Search::showItem($output_type, '', $num,$row_num);

               echo Search::showEndLine($output_type);
            //}
         }

         echo Search::showFooter($output_type);

      }

      echo "</div>";
   }

   function showCriteriasForm($options=array()){
      global $LANG;

      echo "<div align='center'><table class='tab_cadre_fixe'>";

      echo "<tr class='tab_bg_2'>";
      echo "<td class='center'><span class='plugin_manageentities_color'>";
      echo $LANG['plugin_manageentities'][1]." ".$_SESSION["glpiactive_entity_name"]."</span></td>";
      echo "</tr>";
      echo "</table></div>";

      echo "<br>";
      if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {

         $rand=mt_rand();

         echo "<form method='post' name='criterias_form$rand' id='criterias_form$rand'
               action=\"./entity.php\">";

         echo "<div align='spaced'><table class='tab_cadre_fixe center'>";

         echo "<tr class='tab_bg_2'>";

         if ((isset($_SESSION['glpiactive_entity_recursive'])
            && $_SESSION['glpiactive_entity_recursive'])
            || (isset($_SESSION['glpishowallentities'])
               && $_SESSION['glpishowallentities'])){
            echo "<td class='center'>".$LANG['entity'][0]."</td>";
            echo "<td class='center'>";
            Dropdown::show('Entity', array('value' =>$options['entities_id']));
            echo "</td>";
         }

         echo "<td class='center'>".$LANG['plugin_manageentities']['contractday'][2]."</td>";
         echo "<td class='center'>";
         Html::showDateFormItem("begin_date", $options['begin_date']);
         echo "</td><td class='center'>".$LANG['plugin_manageentities']['contractday'][3]."</td>";
         echo "<td class='center'>";
         Html::showDateFormItem("end_date", $options['end_date']);
         echo "</td><td class='center'>".$LANG['plugin_manageentities'][2]."</td>";
         echo "<td class='center'>";
         Dropdown::show('PluginManageentitiesContractState',array('value' =>$options['contractstates_id']));
         echo "</td></tr>";

         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' colspan='8'>";
         echo "<input type='submit' name='searchcontract' value='".$LANG['buttons'][0]."' class='submit'>";
         echo "</td></tr>";
         echo "</table></div>";

         Html::closeForm();

         echo "<br>";
      }

   }
}

?>