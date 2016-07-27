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

class PluginManageentitiesEntity extends CommonDBTM {
   
   function canView() {
      return plugin_manageentities_haveRight("manageentities","r");
   }
   
   function canCreate() {
      return plugin_manageentities_haveRight("manageentities","w");
   }
   
   // Hook done on before update document - keeps document date if it's a CRI
   static function preUpdateDocument($item) {

      // Manipulate data if needed
      $config=new PluginManageentitiesConfig();

      if ($item->getField('id') && $config->GetfromDB(1)) {

         $_SESSION["glpi_plugin_manageentities_date_mod"]=$item->getField("date_mod");

         if ($config->fields["documentcategories_id"]!=$item->getField("documentcategories_id"))
            $_SESSION["glpi_plugin_manageentities_date_mod"]=$_SESSION["glpi_currenttime"];

      }
   }

   // Hook done on after update document - change document date if it's not a CRI

   static function UpdateDocument($item) {
      global $DB;

         $config=new PluginManageentitiesConfig();
         if ($item->getField('id') && $config->GetfromDB(1)) {

            $query = "UPDATE `glpi_documents`
                     SET `date_mod` = '".$_SESSION["glpi_plugin_manageentities_date_mod"]."'
                     WHERE `id` ='".$item->getField('id')."' ";

            $result = $DB->query($query);
         }

         return true;
   }
   
   function showDescription($instID) {
      global $DB,$CFG_GLPI, $LANG;

      $entdata=new EntityData();
      $entdata->getFromDB($instID);
      $entity=new Entity();
      $entity->getFromDB($instID);

      echo "<div align='center'><table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_2'>";
      echo "<td></td>";
      echo "</tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td class='center'><span class='plugin_manageentities_color'>";
      echo $LANG['plugin_manageentities'][1]." ".$_SESSION["glpiactive_entity_name"]."</span></td>";
      echo "</tr>";
      echo "<tr class='tab_bg_2'>";
      echo "<td></td>";
      echo "</tr>";
      echo "</table></div>";

      echo "<br>";

      echo "<form>";
      echo "<div align='center'><table class='tab_cadre_fixe center'>";
      echo "<tr class='tab_bg_1'>";
      echo "<td class='top'>".$LANG['common'][16].":   </td>";
      echo "<td class='top'>";
      if ($instID!=0)
         echo $entity->fields["name"];
      else
         echo $LANG['entity'][2];
      if ($instID!=0) echo " (".$entity->fields["completename"].")";
      echo "</td>";
      if (isset($entity->fields["comment"])) {
         echo "<td class='top'>";
         echo $LANG['common'][25].":   </td>";
         echo "<td class='top center'>".nl2br($entity->fields["comment"]);
         echo "</td>";
      } else {
         echo "<td colspan='2'>&nbsp;</td>";
      }
      echo "</tr>";

      echo "<tr class='tab_bg_1'><td>".$LANG['help'][35].": </td>";
      echo "<td>";
      if (isset($entdata->fields["phonenumber"]))
         echo $entdata->fields["phonenumber"];
      echo "</td>";
      echo "<td>".$LANG['financial'][30].": </td><td>";
      if (isset($entdata->fields["fax"]))
         echo $entdata->fields["fax"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>".$LANG['financial'][45].": </td>";
      echo "<td>";
      if (isset($entdata->fields["website"]))
         echo $entdata->fields["website"];
      echo "</td>";

      echo "<td>".$LANG['setup'][14].": </td><td>";
      if (isset($entdata->fields["email"]))
         echo $entdata->fields["email"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td  rowspan='4'>".$LANG['financial'][44].": </td>";
      echo "<td class='left' rowspan='4'>";
      if (isset($entdata->fields["address"]))
         echo nl2br($entdata->fields["address"]);
      echo "<td>".$LANG['financial'][100]."</td>";
      echo "<td>";
      if (isset($entdata->fields["postcode"]))
         echo $entdata->fields["postcode"];
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['financial'][101].": </td><td>";
      if (isset($entdata->fields["town"]))
         echo $entdata->fields["town"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['financial'][102].": </td><td>";
      if (isset($entdata->fields["state"]))
         echo $entdata->fields["state"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".$LANG['financial'][103].": </td><td>";
      if (isset($entdata->fields["country"]))
         echo $entdata->fields["country"];
      echo "</td></tr>";

      echo "</table></div>";
      Html::closeForm();
   }
  
   function showTickets($instID) {
      global $DB,$CFG_GLPI, $LANG;

      if (!Session::haveRight("show_all_ticket","1") 
            && !Session::haveRight("show_assign_ticket","1")
         && $_SESSION['glpiactiveprofile']['interface'] != 'helpdesk') return false;

      $config = PluginManageentitiesConfig::getInstance();
      $and='';
      if($config->fields['needvalidationforcri'] == 1) {
         $and=" AND `glpi_tickets`.`global_validation` = 'accepted' ";
      }

      echo "<div align='spaced'><table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>".$LANG['plugin_manageentities'][5]."</th></tr>";

      echo "<tr><th>".$LANG['plugin_manageentities'][31];

      echo "</th><th>".$LANG['plugin_manageentities'][32];

      if (Session::haveRight("show_all_ticket","1")
      || Session::haveRight("show_assign_ticket","1")
      || $_SESSION['glpiactiveprofile']['interface'] == 'helpdesk') {
         echo " <a href='".$CFG_GLPI["root_doc"]."/front/ticket.php?is_deleted=0&field[0]=12&searchtype[0]=equals&contains[0]=notold&itemtype=Ticket&start=0'>";
         echo $LANG['plugin_manageentities'][9]."</a>";
      }

      echo "</th></tr>";

      //Tickets solved or closed with CRI
      echo "<tr class='tab_bg_1'><td width='50%' valign='top'>";

      //avec CRI
//      AND `glpi_documents`.`documentcategories_id` = '".
//         $config->fields["documentcategories_id"]."'

      $query = "SELECT `glpi_tickets`.`id`
        FROM `glpi_tickets`
        LEFT JOIN `glpi_documents` ON (`glpi_documents`.`tickets_id`
                     = `glpi_tickets`.`id`)
        WHERE `glpi_tickets`.`entities_id` = ".$instID."
        AND (`status` = 'solved' OR `status` = 'closed')
        AND `glpi_tickets`.`is_deleted` = '0'
         $and
        ORDER BY date DESC
        LIMIT 10";

      $result = $DB->query($query);
      $i = 0;
      $number = $DB->numrows($result);

      if ($number > 0) {
         echo "<table>";

         echo "<tr><th></th>";
         echo "<th>".$LANG['common'][57]."</th>";
         echo "<th width='75px'>".$LANG['job'][4]."</th>";
         echo "<th>".$LANG['joblist'][0]."</th>";
         echo "<th>".$LANG['joblist'][6]."</th></tr>";
         Session::initNavigateListItems("Ticket");

         while ($i < $number) {
            $ID = $DB->result($result, $i, "id");
            Session::addToNavigateListItems("Ticket",$ID);
            $this->showJobVeryShort($ID);
            $i++;
         }
         echo "</table>";
      }

      //Tickets assign, plan, new or waiting
      echo "</td><td width='50%' valign='top'>";

      $query = "SELECT `id`
        FROM `glpi_tickets`
        WHERE `entities_id` = ".$instID."
        AND (`status` = 'new' OR `status` = 'plan' OR `status` = 'assign' OR `status` = 'waiting')
        AND `is_deleted` = '0'
        ORDER BY date DESC
        LIMIT 10";

      $result = $DB->query($query);
      $i = 0;
      $number = $DB->numrows($result);

      if ($number > 0) {
         echo "<table>";

         echo "<tr><th></th>";
         echo "<th>".$LANG['common'][57]."</th>";
         echo "<th width='75px'>".$LANG['job'][4]."</th>";
         echo "<th>".$LANG['joblist'][0]."</th>";
         echo "<th>".$LANG['joblist'][6]."</th></tr>";
//         Session::initNavigateListItems("Ticket");
         while ($i < $number) {
            $ID = $DB->result($result, $i, "id");
//            Session::addToNavigateListItems("Ticket",$ID);
            $this->showJobVeryShort($ID);
            $i++;
         }
         echo "</table>";
      }

      echo "</td></tr>";
      echo "</table></div>";
   }

   function showJobVeryShort($ID) {
      // Prints a job in short form
      // Should be called in a <table>-segment
      // Print links or not in case of user view

      global $CFG_GLPI, $LANG;

      // Make new job object and fill it from database, if success, print it
      $job = new Ticket;
      $viewusers=Session::haveRight("user","r");
      if ($job->getfromDBwithData($ID,0)) {
         $bgcolor=$CFG_GLPI["priority_".$job->fields["priority"]];

         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' bgcolor='$bgcolor' >id: ".$job->fields["id"]."</td>";

         echo "<td>";
         echo "<a href=\"".$CFG_GLPI["root_doc"]."/front/ticket.form.php?id=".$job->fields["id"]."\">";
         echo $job->fields["name"]."</a></td>";

         echo "<td class='center b'>";
         $users = $job->getUsers(Ticket::REQUESTER);
         if (count($users)) {
            foreach ($users as $d) {
               $userdata = getUserName($d['users_id'],2);
               echo "<strong>".$userdata['name']."</strong>&nbsp;";
               if ($viewusers) {
                  Html::showToolTip($userdata["comment"], array('link' => $userdata["link"]));
               }
               echo "<br>";
            }
         }

         $groups = $job->getGroups(Ticket::REQUESTER);
         if (count($groups)) {
            foreach ($groups as $k => $d) {
               echo Dropdown::getDropdownName("glpi_groups", $k);
               echo "<br>";
            }
         }

         echo "</td>";
         echo "<td class='center'>".Ticket::getStatus($job->fields["status"])."</td>";

         echo "<td>".Html::resume_text($job->fields["content"],$CFG_GLPI["cut"]);
         echo "</td>";
         // Finish Line
         echo "</tr>";
         
      } else {
         echo "<tr class='tab_bg_2'><td colspan='6' ><i>".$LANG['joblist'][16]."</i></td></tr>";
      }
   }
}

?>