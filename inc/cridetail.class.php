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

class PluginManageentitiesCriDetail extends CommonDBTM {
   
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      if ($item->getType()=='Ticket' && plugin_manageentities_haveRight("cri_create","r")) {
            return $LANG['plugin_manageentities']['report'][0];

      } else if($item->getType()=='PluginManageentitiesContractDay'){
         return $LANG['plugin_manageentities']['cri'][41];
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {
      global $CFG_GLPI, $DB, $LANG;

      $config = PluginManageentitiesConfig::getInstance();
      $and="";
      $join="";

      if ($item->getType()=='Ticket') {

//         if($config->fields['needvalidationforcri']=='1'){
//            if($item->fields['global_validation']!='accepted'){
//               echo $LANG['plugin_manageentities']['cri'][42];
//               echo "<br>";
//               return '';
//            }
//         }

         if($config->fields['use_publictask']=='1'){
            $and = " AND `is_private` = false ";
         }

         if($config->fields['useprice']=='0'){
            $join=" LEFT JOIN `glpi_plugin_manageentities_taskcategories`
                        ON (`glpi_plugin_manageentities_taskcategories`.`taskcategories_id` =
                        `glpi_tickettasks`.`taskcategories_id`)";
            $and=" AND `glpi_plugin_manageentities_taskcategories`.`is_usedforcount` = 1";
         }


         $cpt=0;
         $query = "SELECT COUNT(*) AS cpt
                  FROM `glpi_tickettasks` $join
                  WHERE `glpi_tickettasks`.`tickets_id` = '".$item->getField('id')."' $and";
         $result = $DB->query($query);
         while ($data = $DB->fetch_array($result)) {
            $cpt= $data["cpt"];
         }
         if ($cpt!=0) {
            if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
               self::showForTicket($item);
            }
            self::addReports($item);
            self::showReports(get_class($item),$item->getField('id'));

         } else {
            echo $LANG['plugin_manageentities']['cri'][38];
            echo "<br>";
         }
      } else if($item->getType()=='PluginManageentitiesContractDay'){

         echo self::showForContractDay($item);
      }
      return true;
   }
   
   function prepareInputForUpdate($input){
      global $LANG;
      //si un document lié ne pas permettre l'update via le form self::showForTicket($item);
      if(isset($input['updatecridetail'])){

         $criDetail = new PluginManageentitiesCriDetail();
         $criDetail->getFromDB($input['id']);

         if($criDetail->fields['documents_id']!='0'){
            Session::addMessageAfterRedirect($LANG['plugin_manageentities'][33], ERROR, true);
            return false;
         }
      }

      return $input;
   }

   function pre_deleteItem(){
      global $LANG;
      //si un document lié ne pas permettre le delete via le form self::showForTicket($item);

      if(isset($this->input['delcridetail'])){

         if($this->fields['documents_id']!='0'){
            Session::addMessageAfterRedirect($LANG['plugin_manageentities'][33], ERROR, true);
            return false;
         }
      }

      return true;

   }

   //Shows CRI from check date - report.form.php function
   function showHelpdeskReports($usertype,$technum,$date1,$date2) {
      global $DB,$CFG_GLPI, $LANG;

      $config= new PluginManageentitiesConfig();
      $config->GetFromDB(1);

      $query = "SELECT `glpi_documents`.*,`glpi_tickets_users`.`users_id`, `glpi_entities`.`id` AS entity, `".$this->getTable()."`.`date`, `".$this->getTable()."`.`technicians`, `".$this->getTable()."`.`plugin_manageentities_critypes_id`, `".$this->getTable()."`.`withcontract`, `".$this->getTable()."`.`contracts_id`, `".$this->getTable()."`.`realtime` "
      ." FROM `glpi_documents` "
      ." LEFT JOIN `glpi_entities` ON (`glpi_documents`.`entities_id` = `glpi_entities`.`id`)"
      ." LEFT JOIN `glpi_tickets` ON (`glpi_documents`.`tickets_id` = `glpi_tickets`.`id`)"
      ." LEFT JOIN `glpi_tickets_users` ON (`glpi_tickets_users`.`tickets_id` = `glpi_tickets`.`id`)"
      ." LEFT JOIN `glpi_plugin_manageentities_cridetails` ON (`glpi_documents`.`id` = `".$this->getTable()."`.`documents_id`) "
      ." LEFT JOIN `glpi_plugin_manageentities_critechnicians` ON (`glpi_documents`.`tickets_id` = `glpi_plugin_manageentities_critechnicians`.`tickets_id`) "
      ." WHERE `glpi_tickets_users`.`type` = ".Ticket::ASSIGN." AND `documentcategories_id` = '".$config->fields["documentcategories_id"]."' AND `".$this->getTable()."`.`date` >= '". $date1 ."' AND `".$this->getTable()."`.`date` <= '". $date2 ."' ";
      if ($usertype!="group")
        $query.= " AND (`glpi_tickets_users`.`users_id` ='".$technum."' OR `glpi_plugin_manageentities_critechnicians`.`users_id` ='".$technum."') ";

      $query .= getEntitiesRestrictRequest(" AND","glpi_documents",'','',true);
      
      //if ($usertype=="group")
         $query.= " GROUP BY `glpi_documents`.`tickets_id` ";
      $query.= "ORDER BY `".$this->getTable()."`.`date` ASC";

      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if (Session::isMultiEntitiesMode()) {
        $colsup=1;
      } else {
        $colsup=0;
      }

      if ($number !="0") {

        echo "<form method='post' action=\"./front/entity.php\">";
        echo "<div align='center'><table class='tab_cadre center' width='95%'>";
        echo "<tr><th colspan='".(12+$colsup)."'>".$LANG['plugin_manageentities']["onglet"][3];
        if ($usertype!="group")
            echo " -".getusername($technum);
        echo " ".$LANG['plugin_manageentities']['report'][4]." ".Html::convdate($date1)." ".$LANG['plugin_manageentities']['report'][5]." ".Html::convdate($date2)."</th></tr>";
        echo "<tr>";
        if (Session::isMultiEntitiesMode())
            echo "<th>".$LANG["entity"][0]."</th>";
        echo "<th>".$LANG["common"][27]."</th>";
        echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][4]."</th>";
         if($config->fields['useprice'] == 1){
            echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
         }
        echo "<th>".$LANG['plugin_manageentities']['cri'][18]."</th>";
        echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][0]."</th>";
        echo "<th>".$LANG['plugin_manageentities']['cri'][16]."</th>";
        echo "<th>".$LANG['plugin_manageentities']['report'][6]."</th>";
        echo "<th>".$LANG["common"][16]."</th>";
        echo "<th width='100px'>".$LANG["document"][2]."</th>";
        echo "</tr>";
        $i=0;
         while ($data=$DB->fetch_array($result)) {
            $i++;
            $class=" class='tab_bg_2 ";
            if ($i%2) {
               $class=" class='tab_bg_1 ";
            }
            echo "<tr $class".($data["is_deleted"]=='1'?"_2":"")."'>";

            if (Session::isMultiEntitiesMode())
               echo "<td class='center'>".Dropdown::getDropdownName("glpi_entities",$data['entity'])."</td>";
            echo "<td class='center'>".Html::convdate($data["date"])."</td>";
            echo "<td class='center'>".$data["technicians"]."</td>";
            if($config->fields['useprice'] == 1){
               echo "<td class='center'>".Dropdown::getDropdownName("glpi_plugin_manageentities_critypes",$data['plugin_manageentities_critypes_id'])."</td>";
            }
            echo "<td class='center'>".$data["realtime"]."</td>";
            echo "<td class='center'>".Dropdown::getYesNo($data["withcontract"])."</td>";
            $num_contract="";
            if ($data["withcontract"]) {
               $contract = new Contract();
               $contract->getFromDB($data["contracts_id"]);
               $num_contract=$contract->fields["num"];
            }
            echo "<td class='center'>".$num_contract."</td>";
            echo "<td class='center'>";
            if ($data["tickets_id"]>0)
               echo "<a href=\"".$CFG_GLPI["root_doc"]."/front/ticket.form.php?id=".$data["tickets_id"]."\">".$data["tickets_id"]."</a>";
            echo "</td>";
            echo "<td class='left'><a href='".$CFG_GLPI["root_doc"]."/front/document.form.php?id=".$data["id"]."'><b>".$data["name"];
            if ($_SESSION["glpiis_ids_visible"]) echo " (".$data["id"].")";
            echo "</b></a></td>";
            $doc = new Document();
            $doc->getFromDB($data["id"]);
            echo "<td class='center'  width='100px'>".$doc->getDownloadLink()."</td>";

            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();
      }
   }
   
   static function addReports(Ticket $ticket) {
      global $CFG_GLPI, $LANG;

      $restrict = "`glpi_plugin_manageentities_cridetails`.`entities_id` = '".
         $ticket->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_cridetails`.`tickets_id` = '".
         $ticket->fields['id']."'";
      $cridetails = getAllDatasFromTable("glpi_plugin_manageentities_cridetails", $restrict);
      $cridetail = reset($cridetails);


      if (plugin_manageentities_haveRight("cri_create","w")
         && (empty($cridetail)
               || $cridetail['documents_id'] == '0')) {
         echo "<br><div align='center'>";
         echo "<input type='button' name='submit' value=\"".$LANG['plugin_manageentities']['title'][2]."\" 
         class='submit' onClick=\"window.open('".$CFG_GLPI["root_doc"].
            "/plugins/manageentities/front/cri.form.php?popup=export&amp;job=".$ticket->fields['id']."' ,
         'glpipopup', 'height=1000, width=1000, top=100, left=100, scrollbars=yes' )\">";
         echo "</div><br>";
      }
   }
   
   //shows CRI from ticket or from entity portal
   static function showReports($type,$instID,$entity=-1) {
      global $DB,$CFG_GLPI, $LANG;

      $config= new PluginManageentitiesConfig();
      if ($config->getFromDB(1)) {
         if ($config->fields["backup"]==1) {

            $query = "SELECT `glpi_documents`.*, 
                           `glpi_plugin_manageentities_cridetails`.`date`, 
                           `glpi_plugin_manageentities_cridetails`.`technicians`, 
                           `glpi_plugin_manageentities_cridetails`.`plugin_manageentities_critypes_id`, 
                           `glpi_plugin_manageentities_cridetails`.`withcontract`, 
                           `glpi_plugin_manageentities_cridetails`.`contracts_id`, 
                           `glpi_plugin_manageentities_cridetails`.`realtime`
              FROM `glpi_documents`
              LEFT JOIN `glpi_plugin_manageentities_cridetails` ON (`glpi_documents`.`id` = `glpi_plugin_manageentities_cridetails`.`documents_id`)
              WHERE `glpi_documents`.`documentcategories_id` = '".$config->fields["documentcategories_id"]."' ";
            if   ($entity!=-1)
               $query .= " AND `glpi_documents`.`entities_id` = '".$entity."' ";
            else
               $query .= " AND `glpi_documents`.`tickets_id` = '".$instID."' ";
            $query .= " ORDER BY `glpi_documents`.`name` DESC LIMIT 10";

            $result = $DB->query($query);
            $number = $DB->numrows($result);

            if ($number !="0") {

               echo "<table class='tab_cadre_fixe'>";
               echo "<tr><th colspan='8'>".$LANG['plugin_manageentities']['cri'][0];
               
               if (Session::haveRight("document", "r")) {
                  echo " <a href='".$CFG_GLPI["root_doc"]."/front/document.php?contains%5B0%5D=cri&amp;field%5B0%5D=1&amp;sort=19&amp;deleted=0&amp;start=0'>";
                  echo $LANG['plugin_manageentities'][9]."</a>";
               }
               echo "</th></tr>";
               echo "<tr>";
               echo "<th>".$LANG['common'][27]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][4]."</th>";
               if($config->fields['useprice']=='1'){
                  echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
               }
               echo "<th>".$LANG['plugin_manageentities']['cri'][18]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][0]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['cri'][16]."</th>";
               echo "<th>".$LANG["common"][16]."</th>";
               echo "<th width='100px'>".$LANG["document"][2]."</th>";
               echo "</tr>";

               while ($data=$DB->fetch_array($result)) {

                  echo "<tr class='tab_bg_1".($data["is_deleted"]=='1'?"_2":"")."'>";
                  echo "<td class='center'>".Html::convdate($data["date"])."</td>";
                  echo "<td class='center'>".$data["technicians"]."</td>";
                  if($config->fields['useprice']=='1'){
                     echo "<td class='center'>".Dropdown::getDropdownName("glpi_plugin_manageentities_critypes",$data['plugin_manageentities_critypes_id'])."</td>";
                  }
                  echo "<td class='center'>".$data["realtime"]."</td>";
                  echo "<td class='center'>".Dropdown::getYesNo($data["withcontract"])."</td>";
                  $num_contract="";
                  if ($data["withcontract"]) {
                     $contract = new contract;
                     $contract->getFromDB($data["contracts_id"]);
                     $num_contract=$contract->fields["num"];
                  }
                  echo "<td class='center'>".$num_contract."</td>";
                  echo "<td class='center'>";
                  if (Session::haveRight("document", "r")) {
                     echo "<a href='".$CFG_GLPI["root_doc"]."/front/document.form.php?id=".$data["id"]."'>";
                  }
                  echo "<b>".$data["name"];
                  if ($_SESSION["glpiis_ids_visible"]) echo " (".$data["id"].")";
                  echo "</b>";
                  if (Session::haveRight("document", "r")) {
                     echo "</a>";
                  }
                  echo "</td>";
                  $doc = new Document();
                  $doc->getFromDB($data["id"]);
                  echo "<td class='center' width='100px'>".$doc->getDownloadLink()."</td>";

                  echo "</tr>";

               }
               echo "</table>";
            }
         }
      }
   }

   static function showForContractDay(PluginManageentitiesContractDay $contractDay) {
      global $PDF,$DB,$LANG,$CFG_GLPI;

      $contract = new Contract();
      $contract->getFromDB($contractDay->fields['contracts_id']);
      $contract_id=$contract->fields['id'];
      $entities_id=$contract->fields["entities_id"];
      $config = PluginManageentitiesConfig::getInstance();
      $restrict = "`glpi_plugin_manageentities_contracts`.`entities_id` = '".
         $entities_id."' AND `glpi_plugin_manageentities_contracts`.`contracts_id` = '".
         $contract_id."'";
      $pluginContracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", $restrict);
      $pluginContract = reset($pluginContracts);
      $PDF = new PluginManageentitiesCriPDF('P', 'mm', 'A4');

      if($config->fields['useprice']=='1'){
         if($contractDay->fields['plugin_manageentities_critypes_id']!=0){
            PluginManageentitiesCriPrice::showform($contractDay->fields['plugin_manageentities_critypes_id'],
               $entities_id);
         }

         $querydisplay = "SELECT `glpi_plugin_manageentities_cridetails`.*,
                                 `glpi_plugin_manageentities_critypes`.`name` AS name_plugin_manageentities_critypes_id,
                                 `glpi_tickets`.`id` AS tickets_id,
                                 `glpi_documents`.`id` AS documents_id,
                                 `glpi_documents`.`is_deleted`,
                                 `glpi_tickets`.`type`,
                                 `glpi_tickets`.`name` AS tickets_name,
                                 `glpi_tickets`.`global_validation`
                          FROM `glpi_plugin_manageentities_cridetails`
                          LEFT JOIN `glpi_plugin_manageentities_critypes`
                              ON (`glpi_plugin_manageentities_critypes`.`id` =
                                    `glpi_plugin_manageentities_cridetails`.`plugin_manageentities_critypes_id`)
                          LEFT JOIN `glpi_documents`
                              ON (`glpi_plugin_manageentities_cridetails`.`documents_id` =
                                    `glpi_documents`.`id`)
                          LEFT JOIN `glpi_tickets` ON (`glpi_plugin_manageentities_cridetails`.`tickets_id`
                                       = `glpi_tickets`.`id`)
                          WHERE `glpi_plugin_manageentities_cridetails`.`entities_id` ='".$entities_id."'
                              AND `glpi_plugin_manageentities_cridetails`.`contracts_id` = '".
                                 $contract_id."'";
         if(!empty($contractDay->fields['begin_date'])){
            $querydisplay.= "AND `glpi_plugin_manageentities_cridetails`.`date` >= '".
               $contractDay->fields['begin_date']."' ";
         }
         if(!empty($contractDay->fields['end_date'])){
            $querydisplay.= " AND `glpi_plugin_manageentities_cridetails`.`date` <= ADDDATE('".
               $contractDay->fields['end_date']."', INTERVAL 1 DAY)";
         }
         $querydisplay.= " ORDER BY `glpi_documents`.`date_mod` ";

         if ($resultdisplay = $DB->query($querydisplay)) {
            $numberdisplay = $DB->numrows($resultdisplay);

            if ($numberdisplay != 0) {
               $totalannuel=0;
               echo "<div align='center'>";
               echo "<table class='tab_cadre_fixe' cellpadding='5'>";
               echo "<tr>";
               echo "<tr>";
               echo "<th colspan='8'>".$LANG['plugin_manageentities']['cri'][41]."</th></tr>";
               echo "<tr>";
               echo "<th>".$LANG['common'][27]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['contractday'][8]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
               echo "<th>".$LANG["document"][2]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['cri'][18]."</th>";
               echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][4]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][21]."</th>";
               echo "<th>".$LANG['plugin_manageentities'][22]."</th>";
               echo "</tr>";

               while($display= $DB->fetch_array($resultdisplay)) {
                  echo "<tr class='tab_bg_1".($display["is_deleted"]=='1'?"_2":"")."'>";
                  $link = Toolbox::getItemTypeFormURL("Ticket");
                  $name_ticket = "<a href='".$link."?id=".$display["tickets_id"]."'>";

                  if ($display["tickets_name"] == NULL && $display["tickets_id"]!= NULL){
                     $name_ticket.="(".$display["tickets_id"].")";
                  } else {
                     $name_ticket.=$display["tickets_name"];
                  }
                  $name_ticket.="</a>";

                  echo "<td>".Html::convdate($display['date'])."</td>";
                  echo "<td>".$name_ticket."</td>";
                  if($display["documents_id"]!=0){
                     echo "<td>".$display['name_plugin_manageentities_critypes_id']."</td>";
                     $doc = new Document();
                     $doc->getFromDB($display["documents_id"]);
                     echo "<td class='center'  width='100px'>".$doc->getDownloadLink()."</td>";
                     echo "<td>".Html::formatnumber($display['realtime'],true)."</td>";
                     echo "<td>".$display['technicians']."</td>";
                  } else {
                     echo "<td>".Dropdown::getDropdownName('glpi_plugin_manageentities_critypes',
                        $contractDay->fields['plugin_manageentities_critypes_id'])."</td>";
                     echo "<td class='center'  width='100px'></td>";

                     $queryTask = "SELECT COUNT(*) AS NUMBER, SUM(`actiontime`) AS actiontime
                             FROM `glpi_tickettasks`
                             WHERE `tickets_id` = '".$display['tickets_id']."'";

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
                                 $display['realtime'] = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                              } else if($config->fields['needvalidationforcri'] == 1
                                 && $display['global_validation']!='accepted'){
                                 $display['realtime'] = "<div class = 'red'>".$LANG['plugin_manageentities']['contractday'][11]."</div>";
                              } else {
                                 //configuration by hour
                                 if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_INTERVENTION){
                                    $display['realtime'] = $dataTask['NUMBER'];
                                 } else if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_HOUR
                                    || $pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_UNLIMITED){
                                    $tmp = $dataTask['actiontime']/3600;
                                    $display['realtime'] = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                                 } else {
                                    $display['realtime'] = "<div class = 'red'>".$LANG['plugin_manageentities']['contractday'][10]."</div>";
                                 }
                              }

                              if($display["technicians"]== NULL){
                                 $job = new Ticket();
                                 $job->getfromDB($display["tickets_id"]);

                                 $users = $job->getUsers(Ticket::ASSIGN);

                                 if (count($users)) {
                                    foreach ($users as $d) {
                                       $userdata = getUserName($d['users_id'],2);
                                       $tech = $userdata['name'];
                                       $tech.= "<br>";
                                    }
                                 }
                              } else {
                                 $tech = $display["technicians"];
                              }

                           }
                        }
                     }
                     echo "<td>".Html::formatnumber($display['realtime'],true)."</td>";
                     echo "<td>".$tech."</td>";

                  }

                  $criprice=0;
                  $PluginManageentitiesCriPrice=new PluginManageentitiesCriPrice();
                  
                  if($display["documents_id"]!=0){
                     if ($PluginManageentitiesCriPrice->getFromDBbyType($display["plugin_manageentities_critypes_id"],$entities_id)) {
                        $criprice=$PluginManageentitiesCriPrice->fields["price"];
                     }
                  } else {
                     if ($PluginManageentitiesCriPrice->getFromDBbyType($contractDay->fields['plugin_manageentities_critypes_id'],$entities_id)) {
                        $criprice=$PluginManageentitiesCriPrice->fields["price"];
                     }
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

               $result = PluginManageentitiesContractDay::calConso($contractDay);

               echo "<tr class='tab_bg_2'>";
               if ($criprice) {
                  echo "<td colspan='7' class='right'><b>".$LANG['plugin_manageentities'][23]." : </b></td>";
                  echo "<td><b>".Html::formatnumber($totalannuel,true)."</b></td>";
                  $nbtheoricaldays=$result['reste_montant']/$criprice;
                  echo "<tr class='tab_bg_2'>";
                  echo "<td colspan='7' align='right'><b>".$LANG['plugin_manageentities'][24]." : </b></td>";
                  echo "<td><b>".Html::formatnumber($nbtheoricaldays,true)."</b></td>";
                  echo "</tr>";
               }
            }

            echo "</table>";
            echo "</div>";
         }
      } else {

//         $and='';
//         if($config->fields['needvalidationforcri'] == 1) {
//            $and=" AND `glpi_tickets`.`global_validation` = 'accepted' ";
//         }

         $query = "SELECT  `glpi_tickets`.`id` AS tickets_id,
                           `glpi_documents`.`is_deleted`,
                           `glpi_plugin_manageentities_cridetails`.`technicians`,
                           `glpi_tickets`.`date`,
                           `glpi_tickets`.`itilcategories_id`,
                           `glpi_tickets`.`name`,
                           `glpi_tickets`.`global_validation`
              FROM `glpi_plugin_manageentities_cridetails`
              LEFT JOIN `glpi_documents` ON (`glpi_documents`.`id`
                     = `glpi_plugin_manageentities_cridetails`.`documents_id`)
              LEFT JOIN `glpi_tickets` ON (`glpi_plugin_manageentities_cridetails`.`tickets_id`
                     = `glpi_tickets`.`id`)
              WHERE `glpi_tickets`.`entities_id` = '".$entities_id."'
                  AND `glpi_plugin_manageentities_cridetails`.`contracts_id` = '".$contract_id."'";

         $result = $DB->query($query);
         $number = $DB->numrows($result);
         if ($number !="0") {

            echo "<div align='center'>";
            echo "<table class='tab_cadre_fixe' cellpadding='5'>";
            echo "<tr>";
            echo "<th colspan='5'>".$LANG['plugin_manageentities']['cri'][41]."</th></tr>";
            echo "<tr>";
            echo "<th>".$LANG['common'][27]."</th>";
            echo "<th>".$LANG['plugin_manageentities'][14]."</th>";
            echo "<th>".$LANG['plugin_manageentities']['contractday'][8]."</th>";
            echo "<th>".$LANG['plugin_manageentities']['infoscompreport'][4]."</th>";
            echo "<th>".$LANG['plugin_manageentities']['contractday'][9]."</th>";
            echo "</tr>";

            while ($data=$DB->fetch_array($result)) {

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
                  $conso = 0;

                  while ($dataTask=$DB->fetch_array($resultTask)) {
                     if ($dataTask['NUMBER']!='0') {
                        //configuration by day
                        if($config->fields['hourorday'] == 0) {
                           $tmp = $dataTask['actiontime']/3600/$config->fields["hourbyday"];
                           $conso = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                        } else if($config->fields['needvalidationforcri'] == 1
                           && $data['global_validation']!='accepted'){
                              $conso = "<div class = 'red'>".$LANG['plugin_manageentities']['contractday'][11]."</div>";
                        } else {
                           //configuration by hour
                           if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_INTERVENTION){
                              $conso = $dataTask['NUMBER'];
                           } else if($pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_HOUR
                              || $pluginContract['contract_type'] == PluginManageentitiesContract::TYPE_UNLIMITED){
                              $tmp = $dataTask['actiontime']/3600;
                              $conso = $PDF->TotalTpsPassesArrondis(round($tmp, 2));
                           } else {
                              $conso = "<div class = 'red'>".$LANG['plugin_manageentities']['contractday'][10]."</div>";
                           }
                        }

                        $link = Toolbox::getItemTypeFormURL("Ticket");
                        $name_ticket = "<a href='".$link."?id=".$data["tickets_id"]."' target='_blank'>";

                        if ($data["name"] == NULL){
                           $name_ticket.="(".$data["tickets_id"].")";
                        } else {
                           $name_ticket.=$data["name"];
                        }
                        $name_ticket.="</a>";

                        if($data["technicians"]== NULL){
                           $job = new Ticket();
                           $job->getfromDB($data["tickets_id"]);

                           $users = $job->getUsers(Ticket::ASSIGN);

                           if (count($users)) {
                              foreach ($users as $d) {
                                 $userdata = getUserName($d['users_id'],2);
                                 $tech = $userdata['name'];
                                 $tech.= "<br>";
                              }
                           }
                        } else {
                           $tech = $data["technicians"];
                        }

                        echo "<tr class='tab_bg_1".($data["is_deleted"]=='1'?"_2":"")."'>";
                        echo "<td class='center'>".Html::convdate($data["date"])."</td>";
                        echo "<td class='center'>".Dropdown::getDropdownName('glpi_itilcategories',$data['itilcategories_id'])."</td>";
                        echo "<td class='center'>".$name_ticket."</td>";
                        echo "<td class='center'>".$tech."</td>";
                        echo "<td class='center'>".$conso."</td>";

                        echo "</tr>";
                     }
                  }
               }
            }

            echo "</table>";
            echo "</div>";
         }
      }
   }

   static function showForTicket(Ticket $ticket){
      global $DB,$LANG;

      $rand=mt_rand();
      $canView = $ticket->can($ticket->fields['id'], 'r');
      $canEdit = $ticket->can($ticket->fields['id'], 'w');
      $date = $ticket->fields['date'];
      $config = PluginManageentitiesConfig::getInstance();
      $contract = new Contract();
      
      if(!$canView) return false;
      
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
                     $config->fields["documentcategories_id"]."'
                 AND `glpi_documents`.`tickets_id` = '".$ticket->fields['id']."'";

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


      $restrict = "`glpi_plugin_manageentities_cridetails`.`entities_id` = '".
         $ticket->fields['entities_id']."'
                  AND `glpi_plugin_manageentities_cridetails`.`tickets_id` = '".
         $ticket->fields['id']."'";
      $cridetails = getAllDatasFromTable("glpi_plugin_manageentities_cridetails", $restrict);
      $cridetail = reset($cridetails);

      if($canEdit){
         echo "<form method='post' name='cridetail_form$rand' id='cridetail_form$rand'
               action='".Toolbox::getItemTypeFormURL('PluginManageentitiesCri')."'>";
      }

      echo "<div align='spaced'><table class='tab_cadre_fixe center'>";

      echo "<tr><th colspan='2'>".$LANG['plugin_manageentities']['cri'][45]."</th></tr>";

      echo "<tr class='tab_bg_1'><td class='center' colspan='2'>";
      echo $LANG['plugin_manageentities']['infoscompreport'][0]." : ";

      echo "<select name='contracts_id'>";
      echo "<option value='0'>".Dropdown::EMPTY_VALUE."</option>";
      $query = "SELECT DISTINCT(`glpi_contracts`.`id`),
                        `glpi_contracts`.`name`,
                        `glpi_contracts`.`num`,
                       `glpi_plugin_manageentities_contracts`.`id` as ID_us,
                       `glpi_plugin_manageentities_contracts`.`is_default` as is_default
          FROM `glpi_contracts`
          LEFT JOIN `glpi_plugin_manageentities_contracts`
               ON (`glpi_plugin_manageentities_contracts`.`contracts_id` = `glpi_contracts`.`id`)
          LEFT JOIN `glpi_plugin_manageentities_contractdays`
               ON (`glpi_plugin_manageentities_contractdays`.`contracts_id` = `glpi_contracts`.`id`)
          LEFT JOIN `glpi_plugin_manageentities_contractstates`
               ON (`glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id`
               = `glpi_plugin_manageentities_contractstates`.`id`)
          WHERE `glpi_plugin_manageentities_contracts`.`entities_id` = '".
               $ticket->fields['entities_id']."'
               AND `glpi_plugin_manageentities_contractstates`.`is_active` = 1
          ORDER BY `glpi_contracts`.`name` ";

      $result = $DB->query($query);
      $number = $DB->numrows($result);
      $selected = false;

      if ($number) {
         while ($data=$DB->fetch_array($result)) {

            echo "<option value='".$data["id"]."'";
            if ($cridetail['contracts_id']==$data["id"]){
               echo "selected='selected'";
               $selected = true;
            } else if($data["is_default"]=='1' && !$selected) {
               echo "selected='selected'";
            }
            echo ">".$data["name"]." - ".$data["num"]."</option>";
         }
      }
      echo "</select>";
      
      if(!empty($cridetail) && $cridetail['contracts_id']!='0'){
         $contract->getFromDB($cridetail['contracts_id']);
         echo '&nbsp;';
         Html::showToolTip($contract->fields['comment'],
            array('link'=>$contract->getLinkURL(),
               'linktarget'=> '_blank'));
      }

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<input type='hidden' name='tickets_id' value='".$ticket->fields['id']."'>";
      echo "<input type='hidden' name='entities_id' value='".$ticket->fields['entities_id']."'>";
      echo "<input type='hidden' name='is_default' value='0'>";
      echo "<input type='hidden' name='documents_id' value='0'>";
      echo "<input type='hidden' name='date' value='$date'>";
      echo "<input type='hidden' name='plugin_manageentities_critypes_id' value='0'>";

      if($canEdit){
         if(empty($cridetail)){
            echo "<td class='center' colspan='2'>";
            echo "<input type='submit' name='addcridetail' value=\"".$LANG['buttons'][8]."\" class='submit'>";
         } else {
            echo "<input type='hidden' name='id' value='".$cridetail['id']."'>";
//            if($cridetail['documents_id'] == '0'){
               echo "<td class='center'>";
               echo "<input type='submit' name='updatecridetail' value='".$LANG['buttons'][7]."' class='submit'>";
               echo "</td><td class='center'>";
               echo "<input type='submit' name='delcridetail' value='".$LANG['buttons'][6]."' class='submit'>";
//            }
         }
         echo "</td>";
      }
      echo "</tr>";
      echo "</table></div>";
      if($canEdit){
         Html::closeForm();
      }
   }
}

?>