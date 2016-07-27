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

class PluginManageentitiesContractDay extends CommonDBTM {

   // From CommonDBTM
   public $dohistory=true;

   static function getTypeName($nb=0) {
      global $LANG;

      if ($nb>1) {
         return $LANG['plugin_manageentities'][27];
      }
      return $LANG['plugin_manageentities']['title'][5];
   }

   function canCreate() {
      return plugin_manageentities_haveRight('manageentities', 'w');
   }

   function canView() {
      return plugin_manageentities_haveRight('manageentities', 'r');
   }
   
   
   function getSearchOptions() {
      global $LANG;

      $tab = array();
      $tab['common']=$LANG['plugin_manageentities']['title'][5];

      $tab[1]['table'] = $this->getTable();
      $tab[1]['field'] = 'name';
      $tab[1]['name'] = $LANG['common'][16];;
      $tab[1]['datatype']='itemlink';
      $tab[1]['itemlink_type'] = $this->getType();

      $tab[2]['table']     = $this->getTable();
      $tab[2]['field']     = 'begin_date';
      $tab[2]['name']      = $LANG['search'][8];
      $tab[2]['datatype']  = 'date';

      $tab[3]['table']     = $this->getTable();
      $tab[3]['field']     = 'end_date';
      $tab[3]['name']      = $LANG['search'][9];
      $tab[3]['datatype']  = 'date';

      $tab[4]['table']     = $this->getTable();
      $tab[4]['field']     = 'nbday';
      $tab[4]['name']      = $LANG['plugin_manageentities']['contractday'][4];
      $tab[4]['datatype']  = 'decimal';

      $tab[5]['table'] = 'glpi_plugin_manageentities_critypes';
      $tab[5]['field'] = 'name';
      $tab[5]['name']  = $LANG['plugin_manageentities'][14];

      $tab[6]['table']     = $this->getTable();
      $tab[6]['field']     = 'report';
      $tab[6]['name']      = $LANG['plugin_manageentities']['contractday'][1];
      $tab[6]['datatype']  = 'decimal';

      $tab[7]['table'] = 'glpi_contracts';
      $tab[7]['field'] = 'name';
      $tab[7]['name']  = $LANG['financial'][1];

      $tab[8]['table'] = 'glpi_plugin_manageentities_contractstates';
      $tab[8]['field'] = 'name';
      $tab[8]['name']  = $LANG['plugin_manageentities'][2];

      $tab[30]['table'] = $this->getTable();
      $tab[30]['field'] = 'id';
      $tab[30]['name'] = $LANG['common'][2];

      if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
         $tab[80]['table'] = 'glpi_entities';
         $tab[80]['field'] = 'completename';
         $tab[80]['name'] = $LANG['entity'][0];
      }

      return $tab;
   }
   
   /**
    * Display tab for each contractDay
    **/
   function defineTabs($options=array()) {

      $ong = array();

      $this->addStandardTab('PluginManageentitiesCriDetail',$ong,$options);
      $this->addStandardTab('Document',$ong,$options);
      $this->addStandardTab('Log',$ong,$options);

      return $ong;
   }

   function getFromDBbyTypeAndContract($plugin_manageentities_critypes_id,$contracts_id,$entities_id) {
      global $DB;
      
      $query = "SELECT *
      FROM `".$this->getTable()."`
      WHERE `plugin_manageentities_critypes_id` = '" . $plugin_manageentities_critypes_id . "'
      AND `contracts_id` = '" . $contracts_id . "'
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
   
   function addNbDay($values) {

      if ($this->getFromDBbyTypeAndContract($values["plugin_manageentities_critypes_id"],$values["contracts_id"],$values["entities_id"])) {

         $this->update(array(
           'id'=>$this->fields['id'],
           'nbday'=>$values["nbday"],
           'entities_id'=>$values["entities_id"]));
      } else {

         $this->add(array(
           'plugin_manageentities_critypes_id'=>$values["plugin_manageentities_critypes_id"],
           'contracts_id'=>$values["contracts_id"],
           'nbday'=>$values["nbday"],
           'entities_id'=>$values["entities_id"]));
      }
   }

   static function calConso(PluginManageentitiesContractDay $contractDay){
      global $DB;

      $conso = 0;
      $reste = 0;
      $depass = 0;
      $forfait =0;
      $reste_montant=0;
      $entities_id = $contractDay->fields['entities_id'];
      $contract_id=$contractDay->fields['contracts_id'];
      $criTypeID = $contractDay->fields['plugin_manageentities_critypes_id'];
      $PDF = new PluginManageentitiesCriPDF('P', 'mm', 'A4');
      $obj=new PluginManageentitiesCriPrice();
      if ($obj->getFromDBbyType($criTypeID,$entities_id))
         $pricecri=$obj->fields["price"];

      $config = PluginManageentitiesConfig::getInstance();

      // unit for consumption = days
      if($config->fields['useprice']=='1'){

         $totalallcri=0;
         $pricecri=0;
         $query_cri = "SELECT `plugin_manageentities_critypes_id`,
                              `realtime`,
                              `documents_id`,
                              `tickets_id` "            
            ." FROM `glpi_plugin_manageentities_cridetails` "
            ." WHERE `contracts_id` = '".$contract_id."' AND `entities_id` = '".$entities_id."'";

         if(!empty($contractDay->fields['begin_date'])){
            $query_cri.= "AND `glpi_plugin_manageentities_cridetails`.`date` >= '".
               $contractDay->fields['begin_date']."' ";
         }
         if(!empty($contractDay->fields['end_date'])){
            $query_cri.= " AND `glpi_plugin_manageentities_cridetails`.`date` <= ADDDATE('".
                     $contractDay->fields['end_date']."', INTERVAL 1 DAY)";
         }

         if ($result_cri = $DB->query($query_cri)) {
            $number_cri = $DB->numrows($result_cri);
            if ($number_cri != 0) {
               while($ligne_cri= $DB->fetch_array($result_cri)) {
                  if($ligne_cri["documents_id"]!=0){
                     $totalcri=$pricecri*$ligne_cri["realtime"];
                     $conso+=$ligne_cri["realtime"];
                     $totalallcri+=$totalcri;
                  } else {
                     $queryTask = "SELECT COUNT(*) AS NUMBER, SUM(`actiontime`) AS actiontime
                             FROM `glpi_tickettasks`
                             WHERE `tickets_id` = '".$ligne_cri['tickets_id']."'";

                     $ticket = new Ticket();
                     $ticket->getFromDB($ligne_cri['tickets_id']);

                     if(!empty($contractDay->fields['begin_date'])){
                        $queryTask.= " AND `begin` >= '".$contractDay->fields['begin_date']."'
                              AND `end`  >= '".$contractDay->fields['begin_date']."' ";
                     }
                     if(!empty($contractDay->fields['end_date'])){
                        $queryTask.= " AND `begin` <= ADDDATE('".
                           $contractDay->fields['end_date']."', INTERVAL 1 DAY)
                     AND `end` <= ADDDATE('".
                           $contractDay->fields['end_date']."', INTERVAL 1 DAY)";
                     }

                     $resultTask = $DB->query($queryTask);
                     $numberTask = $DB->numrows($resultTask);
                     if($numberTask!='0'){

                        while ($dataTask=$DB->fetch_array($resultTask)) {
                           if ($dataTask['NUMBER']!='0') {
                              //configuration by day
                              if($config->fields['hourorday'] == 0) {
                                 $tmp = $dataTask['actiontime']/3600/$config->fields["hourbyday"];
                                 $ligne_cri['realtime'] = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                              } else if($config->fields['needvalidationforcri'] == 1
                                 && $ticket->fields['global_validation']!='accepted'){
                                 $ligne_cri['realtime'] = 0;
                              } else {
                                 //configuration by hour
                                 $restrict = "`glpi_plugin_manageentities_contracts`.`entities_id` = '".
                                    $entities_id."' AND `glpi_plugin_manageentities_contracts`.`contracts_id` = '".
                                    $contract_id."'";
                                 $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
                                 $pluginContract = reset($pluginContracts);
                                 if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_INTERVENTION){
                                    $ligne_cri['realtime'] = $dataTask['NUMBER'];
                                 } else if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_HOUR
                                    || $pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_UNLIMITED){
                                    $tmp = $dataTask['actiontime']/3600;
                                    $ligne_cri['realtime'] = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                                 } else {
                                    $display['realtime'] = 0;
                                 }
                              }
                           }
                        }
                     }
                     $totalcri=$pricecri*$ligne_cri["realtime"];
                     $conso+=$ligne_cri["realtime"];
                     $totalallcri+=$totalcri;

                  }               }
            }
         }

         //forfait annuel garanti
         //$PluginManageentitiesCriPrice=new PluginManageentitiesCriPrice();
         //if ($PluginManageentitiesCriPrice->getFromDBbyType($criTypeID,$entities_id)) {

            $forfait=($contractDay->fields["nbday"]+$contractDay->fields["report"])*$pricecri;
         //nombre restant
         $reste=($contractDay->fields["nbday"]+$contractDay->fields["report"])-$conso;
         if($reste<0){
            $depass = abs($reste);
            $reste = 0;
         }

         //restant_montant
         $reste_montant=$forfait-$totalallcri;


      } else {

         //le calcul intervention, horaire, illimité

         $restrict = "`glpi_plugin_manageentities_contracts`.`entities_id` = '".
            $entities_id."' AND `glpi_plugin_manageentities_contracts`.`contracts_id` = '".
            $contract_id."'";
         $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
         $pluginContract = reset($pluginContracts);
         $and='';
         if($config->fields['needvalidationforcri'] == 1) {
            $and=" AND `glpi_tickets`.`global_validation` = 'accepted' ";
         }

         $query = "SELECT  `glpi_tickets`.`id` AS tickets_id,
                           `glpi_documents`.`is_deleted`,
                           `glpi_plugin_manageentities_cridetails`.`technicians`,
                           `glpi_tickets`.`date`,
                           `glpi_tickets`.`type`,
                           `glpi_tickets`.`name`,
                           `glpi_tickets`.`global_validation`
              FROM `glpi_plugin_manageentities_cridetails`
              LEFT JOIN `glpi_documents` ON (`glpi_documents`.`id`
                     = `glpi_plugin_manageentities_cridetails`.`documents_id`)
              LEFT JOIN `glpi_tickets` ON (`glpi_plugin_manageentities_cridetails`.`tickets_id`
                     = `glpi_tickets`.`id`)
              WHERE `glpi_tickets`.`entities_id` = '".$entities_id."'
                  AND `glpi_plugin_manageentities_cridetails`.`contracts_id` = '".$contract_id."'
                  $and";

         $result = $DB->query($query);
         $number = $DB->numrows($result);

         if ($number !="0") {
            while ($data=$DB->fetch_array($result)) {
               //récuperer l'ensemble des taches d'un ticket

               $queryTask = "SELECT COUNT(*) AS NUMBER, SUM(`actiontime`) AS actiontime
                             FROM `glpi_tickettasks`
                              LEFT JOIN `glpi_plugin_manageentities_taskcategories`
                              ON (`glpi_plugin_manageentities_taskcategories`.`taskcategories_id` =
                              `glpi_tickettasks`.`taskcategories_id`)
                             WHERE `tickets_id` = '".$data['tickets_id']."'
                             AND `glpi_plugin_manageentities_taskcategories`.`is_usedforcount` = 1";
               if(!empty($contractDay->fields['begin_date'])){
                  $queryTask.= " AND `begin` >= '".$contractDay->fields['begin_date']."'
                              AND `end`  >= '".$contractDay->fields['begin_date']."' ";
               }
               if(!empty($contractDay->fields['end_date'])){
                  $queryTask.= " AND `begin` <= ADDDATE('".
                     $contractDay->fields['end_date']."', INTERVAL 1 DAY)
                     AND `end` <= ADDDATE('".
                     $contractDay->fields['end_date']."', INTERVAL 1 DAY)";
               }

               $resultTask = $DB->query($queryTask);
               $numberTask = $DB->numrows($resultTask);
               if($numberTask!='0'){

                  while ($dataTask=$DB->fetch_array($resultTask)) {
                     if($config->fields['hourorday'] == 0) {
                        $tmp = $dataTask['actiontime']/3600/$config->fields["hourbyday"];
                        $conso+= $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                     } else {
                        //configuration by hour
                        //calcul à l'intervention
                        //décompté 1 intervention par tache du ticket
                        if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_INTERVENTION){
                           $conso+= $dataTask['NUMBER'];
                        } else if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_HOUR
                           || $pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_UNLIMITED){
                           //type illimité pas de soustraction
                           //compter le temps de toutes les taches
                           //calcul à l'heure
                           //soustrait au total
                           //décompté en fonction du temps pour toutes les taches
                           $tmp = $dataTask['actiontime']/3600;
                           $conso+= $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                        }
                     }
                  }
               }
            }
         }
         if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
            $reste=($contractDay->fields["nbday"]+$contractDay->fields["report"])-$conso;
            if($reste<0){
               $depass = abs($reste);
               $reste = 0;
            }
         }
      }

      $result = array('conso' =>$conso,
                      'reste'=>$reste,
                      'depass'=>$depass,
                      'forfait'=>$forfait,
                      'reste_montant'=>$reste_montant);

      //return array
      return $result;
   }

   /**
    * Display the contractday form
    *
    * @param $ID integer ID of the item
    * @param $options array
    *     - target filename : where to go when done.
    *     - withtemplate boolean : template or basic item
    *
    *@return boolean item found
    **/
   function showForm($ID, $options=array("")) {
      global $LANG, $CFG_GLPI;

      //validation des droits
      if (!$this->canView()) return false;
      $config = PluginManageentitiesConfig::getInstance();

      $contract_id = 0;
      $contract = new Contract();
      if (isset($options['contract_id'])) {
         $contract_id = $options['contract_id'];
      }

      if ($ID > 0) {
         $this->check($ID,'r');
         $contract_id=$this->fields["contracts_id"];
         $contract->getFromDB($contract_id);
      } else {
         // Create item
         $input=array('contract_id'=>$contract_id);
         $this->check(-1,'w',$input);
         $contract->getFromDB($contract_id);
         $options['entities_id']=$contract->fields['entities_id'];
      }

      $restrict = "`glpi_plugin_manageentities_contracts`.`entities_id` = '".
         $contract->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_contracts`.`contracts_id` = '".
         $contract->fields['id']."'";
      $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
      $pluginContract = reset($pluginContracts);

      $unit = PluginManageentitiesContract::getUnitContractType($config,$pluginContract['contract_type']);

      $this->showTabs($options);
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['financial'][1]."</td>";
      $link = Toolbox::getItemTypeFormURL('Contract');
      $contract_name = "<a href='".$link."?id=".$contract->fields['id']."'>".
         $contract->fields['name']."</a>";
      echo "<td>".$contract_name."</td><td></td><td></td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['plugin_manageentities']['title'][5]."</td>";
      echo "<td>";
      Html::autocompletionTextField($this,"name",array('value' => $this->fields["name"]));
      echo "</td>";

      if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
         echo "<td>".$LANG['plugin_manageentities']['contractday'][1]."</td>";
         echo "<td><input type='text' name='report' value='".
            Html::formatNumber($this->fields["report"], true)."'size='5'>";
         echo "&nbsp;".$unit;
         echo "</td>";
      } else {
         echo "<td></td><td></td>";
      }
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['plugin_manageentities']['contractday'][2]."</td>";
      echo "<td>";
      Html::showDateFormItem("begin_date",$this->fields["begin_date"],true,true);
      echo "</td>";
      echo "<td>".$LANG['plugin_manageentities']['contractday'][3]."</td><td>";
      Html::showDateFormItem("end_date",$this->fields["end_date"],true,true);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['plugin_manageentities']['contractday'][4]."</td>";
      if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
         echo "<td><input type='text' name='nbday' value='".
            Html::formatNumber($this->fields["nbday"], true)."'size='5'>";
      } else {
         echo "<td>";
      }
      echo "&nbsp;".$unit;
      echo "</td>";
      echo "<td>".$LANG['plugin_manageentities'][2]."</td><td>";
      Dropdown::show('PluginManageentitiesContractState',
                     array('value' => $this->fields['plugin_manageentities_contractstates_id'],
                           'entity' => $this->fields["entities_id"]));
      echo "</td></tr>";

      echo "<input type='hidden' name='contracts_id' value='".$contract_id."'>";
      echo "<input type='hidden' name='entities_id' value='".$contract->fields['entities_id']."'>";

      $result = PluginManageentitiesContractDay::calConso($this);

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['plugin_manageentities']['contractday'][5]."</td>";
      echo "<td>";
      echo Html::formatNumber($result['conso']);
      if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
         echo "&nbsp;".$unit;
      } else {
         echo "&nbsp;".PluginManageentitiesContract::getUnitContractType($config,
            PluginManageentitiesContract::TYPE_HOUR);;
      }
      echo "</td>";

      if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
         echo "<td>".$LANG['plugin_manageentities']['contractday'][6]."</td>";
         echo "<td>";
         echo Html::formatNumber($result['reste']);
         echo "&nbsp;".$unit;
         echo "</td>";
         echo "</tr>";

         echo "<tr class='tab_bg_1'>";
         echo "<td>".$LANG['plugin_manageentities']['contractday'][7]."</td>";
         echo "<td>";
         echo Html::formatNumber($result['depass']);
         echo "&nbsp;".$unit;
         echo "</td>";
      }

      echo "<td></td><td></td></tr>";

      if($config->fields['useprice']=='1'){
         echo "<tr class='tab_bg_1'><td>".$LANG['plugin_manageentities'][6]."</td><td>";
         Dropdown::show('PluginManageentitiesCriType',
            array('value' => $this->fields['plugin_manageentities_critypes_id'],
               'entity' => $this->fields["entities_id"]));
         echo "</td>";

         echo "<td>".$LANG['plugin_manageentities'][18]."</td>";
         echo "<td>";
         echo Html::formatNumber($result['forfait']);
         echo "</td></tr>";
         echo "<tr class='tab_bg_1'>";
         echo "<td>".$LANG['plugin_manageentities'][19]."</td>";
         echo "<td>";
         echo Html::formatNumber($result['reste_montant']);
         echo "</td><td></td><td></td>";
         echo "</tr>";

      }

      $this->showFormButtons($options);
      $this->addDivForTabs();

      return true;
   }


   static function addNewContractDay(Contract $contract){
      global $LANG;

      $contract_id=$contract->fields['id'];

      $canEdit = $contract->can($contract_id, 'w');

      if (plugin_manageentities_haveRight('manageentities', 'w') && $canEdit) {

         echo "<div align='center'>";
         echo "<a href='".Toolbox::getItemTypeFormURL('PluginManageentitiesContractDay')."?contract_id=".
            $contract_id."' >".$LANG['plugin_manageentities']['contractday'][0]."</a></div>";
         echo "</div>";
      }
   }

   static function showForContract(Contract $contract){
      global $LANG;

      $rand=mt_rand();
      $canView = $contract->can($contract->fields['id'], 'r');
      $canEdit = $contract->can($contract->fields['id'], 'w');

      if(!$canView) return false;

      $restrict = "`entities_id` = '".$contract->fields['entities_id']."'
                  AND `contracts_id` = '".$contract->fields['id']."'";
      $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
      $pluginContract = reset($pluginContracts);

      $pluginContractDays = getAllDatasFromTable("glpi_plugin_manageentities_contractdays", $restrict,
                              '', "`begin_date` ASC, `name`");
      if(!empty($pluginContractDays)){
         if($canEdit){
            echo "<form method='post' name='contractDays_form$rand' id='contractDays_form$rand'
               action='".Toolbox::getItemTypeFormURL('PluginManageentitiesContractDay')."'>";
         }

         echo "<div align='spaced'><table class='tab_cadre_fixe center'>";

         echo "<tr><th colspan='10'>".$LANG['plugin_manageentities'][27]."</th></tr><tr>";

         if($canEdit){
            echo "<th>&nbsp;</th>";
         }

         echo "<th>".$LANG['plugin_manageentities']['title'][5]."</th>";
         echo "<th>".$LANG['plugin_manageentities']['contractday'][2]."</th>";
         echo "<th>".$LANG['plugin_manageentities']['contractday'][3]."</th>";
         if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
            echo "<th>".$LANG['plugin_manageentities']['contractday'][4]."</th>";
         }
         echo "<th>".$LANG['plugin_manageentities'][2]."</th>";
         if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
            echo "<th>".$LANG['plugin_manageentities']['contractday'][1]."</th>";
         }
         echo "<th>".$LANG['plugin_manageentities']['contractday'][5]."</th>";
         if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
            echo "<th>".$LANG['plugin_manageentities']['contractday'][6]."</th>";
            echo "<th>".$LANG['plugin_manageentities']['contractday'][7]."</th>";
         }
         echo "</tr>";
         Session::initNavigateListItems("PluginManageentitiesContractDay",$contract->getName());

         foreach($pluginContractDays as $pluginContractDay) {
            $ID="";
            if ($_SESSION["glpiis_ids_visible"]||empty($pluginContractDay["name"]))
               $ID= " (".$pluginContractDay["id"].")";
            $link=Toolbox::getItemTypeFormURL('PluginManageentitiesContractDay');
            $name= "<a href=\"".$link."?id=".$pluginContractDay["id"]."\">"
               .$pluginContractDay["name"]."$ID</a>";

            Session::addToNavigateListItems("PluginManageentitiesContractDay",$pluginContractDay["id"]);

            echo "<tr class='tab_bg_1'>";

            if ($canEdit) {
               echo "<td width='10'>";
               $sel="";
               if (isset($_GET["select"])&&$_GET["select"]=="all") $sel="checked";
               echo "<input type='checkbox' name='item[".$pluginContractDay["id"]."]' value='1' $sel>";
               echo "</td>";
            }

            $contractDay = new PluginManageentitiesContractDay();
            $contractDay->getFromDB($pluginContractDay['id']);
            $result = PluginManageentitiesContractDay::calConso($contractDay);

            echo "<td class='left'>".$name."</td>";
            echo "<td class='center'>".Html::convDate($pluginContractDay['begin_date']);
            echo "</td><td class='center'>";
            echo Html::convDate($pluginContractDay['end_date']);
            echo "</td><td class='center'>";
            if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
               echo Html::formatNumber($pluginContractDay['nbday']);
               echo "</td><td class='center'>";
            }
            echo Dropdown::getDropdownName('glpi_plugin_manageentities_contractstates',
               $pluginContractDay['plugin_manageentities_contractstates_id']);
            if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
               echo "</td><td class='center'>";
               echo Html::formatNumber($pluginContractDay['report']);
            }
            echo "</td><td class='center'>";
            echo Html::formatNumber($result['conso']);
            if($pluginContract['contract_type'] != PluginManageentitiesContract::TYPE_UNLIMITED){
               echo "</td><td class='center'>";
               echo Html::formatNumber($result['reste']);
               echo "</td><td class='center'>";
               echo Html::formatNumber($result['depass']);
            }
            echo "</td class='center'>";
            echo "<input type='hidden' name='id' value='".$pluginContractDay['id']."'>";
            echo "</tr>";
         }

         echo "</table></div>" ;

         if ($canEdit) {

            Html::openArrowMassives("contractDays_form$rand",true);
            Html::closeArrowMassives(array('deleteAll'=> $LANG['buttons'][6]));
         }

         Html::closeForm();
      }
   }

   static function showform_old($target,Contract $contract) {
      global $DB,$LANG,$CFG_GLPI;

//      $contract = new contract;
//      $contract->getFromDB($contracts_id);
      $entities_id=$contract->fields["entities_id"];
      $used=array();
      $number=0;
      $query = "SELECT `glpi_plugin_manageentities_contractdays`.*,
                       `glpi_plugin_manageentities_contracts`.*
            FROM `glpi_plugin_manageentities_contractdays`
               LEFT JOIN `glpi_contracts`
                  ON (`glpi_contracts`.`id` = `glpi_plugin_manageentities_contractdays`.`contracts_id`)
               LEFT JOIN `glpi_plugin_manageentities_contracts`
                  ON (`glpi_contracts`.`id` = `glpi_plugin_manageentities_contracts`.`contracts_id`)
            WHERE `glpi_plugin_manageentities_contractdays`.`entities_id` = '".$entities_id."' 
            AND `glpi_plugin_manageentities_contractdays`.`contracts_id` = '".$contract_id."'";
      
      $restant = 0;
      if ($result = $DB->query($query)) {
         $number = $DB->numrows($result);
         if ($number != 0) {
            $rand=mt_rand();
            echo "<form method='post' name='massiveaction_form_nbdaycri$rand' id='massiveaction_form_nbdaycri$rand' action=\"$target\">";
            echo "<div align='center'>";
            echo "<table class='tab_cadre_fixe' cellpadding='5'>";
            echo "<tr>";
            echo "<th></th>";
            echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][16]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][18]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][20]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][19]."</th>";
            echo "</tr>";
            
            
            while($ligne= mysql_fetch_array($result)) {

               $ID=$ligne["id"];
               $critype = $ligne["plugin_manageentities_critypes_id"];
               $used[]= $critype;

               $totalcri=0;
               $totalallcri=0;
               $totalrealtime=0;
               $forfait=0;
               $pricecri=0;
               $query_cri = "SELECT `plugin_manageentities_critypes_id`, `realtime` "
               ." FROM `glpi_plugin_manageentities_cridetails` "
               ." WHERE `contracts_id` = '".$contract_id."' AND `entities_id` = '".$entities_id."' ";
               $query_cri;
               $result_cri = $DB->query($query_cri);

               if ($result_cri = $DB->query($query_cri)) {
                  $number_cri = $DB->numrows($result_cri);
                  if ($number_cri != 0) {
                     while($ligne_cri= mysql_fetch_array($result_cri)) {
                        $obj=new PluginManageentitiesCriPrice();
                        if ($obj->getFromDBbyType($critype,$entities_id))
                           $pricecri=$obj->fields["price"];
                        $totalcri=$pricecri*$ligne_cri["realtime"];
                        $totalrealtime+=$ligne_cri["realtime"];
                        $totalallcri+=$totalcri;
                     }
                  }
               }

               echo "<tr class='tab_bg_1'>";
               
               echo "<td>";
               echo "<input type='hidden' name='id' value='$ID'>";
               echo "<input type='checkbox' name='item_nbday[$ID]' value='1'>";
               echo "</td>";
               
               //interventon type
               $type=new PluginManageentitiesCriType();
               $type->getFromDB($critype);
               echo "<td>".$type->getLink()."</td>";
               
               //Nb jours total
               echo "<td>".Html::formatnumber($ligne["nbday"],true)." ".$LANG['plugin_manageentities'][17]."</td>";

               //forfait total
               echo "<td>";
               $PluginManageentitiesCriPrice=new PluginManageentitiesCriPrice();
               if ($PluginManageentitiesCriPrice->getFromDBbyType($critype,$entities_id)) {
                  $forfait=$ligne["nbday"]*$PluginManageentitiesCriPrice->fields["price"];
                  echo Html::formatnumber($forfait,true);
               }
               echo "</td>";
               //nb jours restants
               echo "<td>";

               $nbdayrestant=$ligne["nbday"]-$totalrealtime;
               echo Html::formatnumber($nbdayrestant,true)." ".$LANG['plugin_manageentities'][17];

               echo "</td>";
               //forfait restant
               echo "<td>";
               $restant=$forfait-$totalallcri;
               echo Html::formatnumber($restant,true);
               echo "</td>";
               
            }

            Html::openArrowMassives("massiveaction_form_nbdaycri$rand", true);
            Html::closeArrowMassives(array('delete_nbday' => $LANG['buttons'][6]));
            
            echo "</table>";
            echo "</div>";
            Html::closeForm();
            //old version
//            PluginManageentitiesCriPrice::showform($critype);
         }
      

         $querydisplay = "SELECT `glpi_plugin_manageentities_cridetails`.*, 
                                 `glpi_plugin_manageentities_critypes`.`name` AS name_plugin_manageentities_critypes_id,
                                  `glpi_documents`.`id` AS documents_id, `glpi_documents`.`is_deleted`
            FROM `glpi_plugin_manageentities_cridetails`
            LEFT JOIN `glpi_plugin_manageentities_critypes` 
               ON (`glpi_plugin_manageentities_critypes`.`id` = `glpi_plugin_manageentities_cridetails`.`plugin_manageentities_critypes_id`)
            LEFT JOIN `glpi_documents` 
               ON (`glpi_plugin_manageentities_cridetails`.`documents_id` = `glpi_documents`.`id`)
            WHERE `glpi_plugin_manageentities_cridetails`.`entities_id` ='".$entities_id."' 
            AND `glpi_plugin_manageentities_cridetails`.`contracts_id` = '".$contract_id."'
            ORDER BY `glpi_documents`.`date_mod` ";

         if ($resultdisplay = $DB->query($querydisplay)) {
            $numberdisplay = $DB->numrows($resultdisplay);
            
            if ($numberdisplay != 0) {
               $totalannuel=0;
               echo "<div align='center'>";
               echo "<table class='tab_cadre_fixe' cellpadding='5'>";
               echo "<tr>";
               echo "<th>".$LANG['common'][27]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
               echo "<th>".$LANG["document"][2]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['cri'][18]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][4]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][21]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][22]."</th>";
               echo "</tr>";

               while($display= mysql_fetch_array($resultdisplay)) {
                  echo "<tr class='tab_bg_1".($display["is_deleted"]=='1'?"_2":"")."'>";
                  echo "<td>".Html::convdate($display['date'])."</td>";
                  echo "<td>".$display['name_plugin_manageentities_critypes_id']."</td>";
                  $doc = new Document();
                  $doc->getFromDB($display["documents_id"]);
                  echo "<td class='center'  width='100px'>".$doc->getDownloadLink()."</td>";
                  echo "<td>".Html::formatnumber($display['realtime'],true)."</td>";
                  echo "<td>".$display['technicians']."</td>";
                  
                  $criprice=0;
                  $PluginManageentitiesCriPrice=new PluginManageentitiesCriPrice();
                  if ($PluginManageentitiesCriPrice->getFromDBbyType($display["plugin_manageentities_critypes_id"],$entities_id)) {
                     $criprice=$PluginManageentitiesCriPrice->fields["price"];
                  }
                  if ($criprice) {
                     echo "<td>";
                     echo Html::formatnumber($criprice,true);
                     echo "</td>";
                     echo "<td>".Html::formatnumber($criprice*$display['realtime'],true)."</td>";
                  } else {
                     echo "<td colspan='2'>";
                     echo "</td>";
                  }
                  echo "</tr>";
                  
                  $totalannuel+=$criprice*$display['realtime'];
               }
               echo "<tr class='tab_bg_2'>";
               if ($criprice) {
                  echo "<td colspan='6' class='right'><b>".$LANG['plugin_manageentities'][23]." : </b></td>";
                  echo "<td><b>".Html::formatnumber($totalannuel,true)."</b></td>";
                  $nbtheoricaldays=$restant/$criprice;
                  echo "<tr class='tab_bg_2'>";
                  echo "<td colspan='6' align='right'><b>".$LANG['plugin_manageentities'][24]." : </b></td>";
                  echo "<td><b>".Html::formatnumber($nbtheoricaldays,true)."</b></td>";
                  echo "</tr>";
               }
            }
            
            echo "</table>";
            echo "</div>";
         }

         if ($number < 1) {
               echo "<form method='post'  action=\"$target\">";
               echo "<table class='tab_cadre_fixe' cellpadding='5'>";
               echo "<tr><th colspan='3'>".$LANG['plugin_manageentities']['setup'][5]."</th></tr>";
               echo "<tr class='tab_bg_1'><td>";
               $q="SELECT COUNT(*)
                  FROM `glpi_plugin_manageentities_critypes` ";
               $result = $DB->query($q);
               $nb = $DB->result($result,0,0);
               if ($nb>count($used))
                  Dropdown::show('PluginManageentitiesCriType', array('name' => 'plugin_manageentities_critypes_id','entity' => $entities_id,'used' => $used));
               echo "</td><td>";
               echo "&nbsp;x&nbsp;<input type='text' name='nbday' size='16'>&nbsp;".$LANG['plugin_manageentities'][17];
               echo "<input type='hidden' name=\"entities_id\" value='".$entities_id."'>";
               echo "<input type='hidden' name=\"contracts_id\" value='".$contract_id."'>";
               echo "<td>";
               echo "<div align='center'><input type='submit' name='add_nbday' value=\"".$LANG['buttons'][2]."\" class='submit' ></div></td></tr>";
               echo "</table>";
               Html::closeForm();
         }
      }
   }
   
}

?>