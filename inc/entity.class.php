<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2016 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
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

class PluginManageentitiesEntity extends CommonGLPI {

   static $rightname = 'plugin_manageentities';

   static function getTypeName($nb = 0) {
      return _n('Client', 'Clients', $nb, 'manageentities');
   }

   static function canView() {
      return Session::haveRight(self::$rightname, READ);
   }

   static function canCreate() {
      return Session::haveRightsOr(self::$rightname, array(CREATE, UPDATE, DELETE));
   }

   function defineTabs($options = array()) {

      $ong = array();
      $this->addStandardTab(__CLASS__, $ong, $options);

      return $ong;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

      if ($item->getType() == __CLASS__) {
         $plugin                  = new Plugin();
         $followUp                = new PluginManageentitiesFollowUp();
         $monthly                 = new PluginManageentitiesMonthly();
         $gantt                   = new PluginManageentitiesGantt();
         $PluginManageentitiesCri = new PluginManageentitiesCri();
         $config                  = new PluginManageentitiesConfig();

         if ($followUp->canView()) {
            $tabs[1] = __('General follow-up', 'manageentities');
         }

         if ($monthly->canView() && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
            $tabs[2] = __('Monthly follow-up', 'manageentities');
         }

         if ($gantt->canView()) {
            $tabs[3] = __('GANTT');
         }

         $tabs[4] = __('Data administrative', 'manageentities');

         if (Session::haveRight("contract", READ)) {
            $tabs[5] = _n('Contract', 'Contracts', 2);
         }

         if (!Session::haveRight("ticket", Ticket::READALL)
             && !Session::haveRight("ticket", Ticket::READASSIGN)
             && $_SESSION['glpiactiveprofile']['interface'] != 'helpdesk') {
            $tabs[6] = __('Client planning', 'manageentities');
         }

         // ajout de la configuration du plugin
         $config = PluginManageentitiesConfig::getInstance();
         if (($_SESSION['glpiactiveprofile']['interface'] == 'central') || ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk' && $config->fields['choice_intervention'] == PluginManageentitiesConfig::REPORT_INTERVENTION)) {
            if ($PluginManageentitiesCri->canView()) {
               $tabs[7] = __('Interventions reports', 'manageentities');
            }
         } elseif ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk' && $config->fields['choice_intervention'] == PluginManageentitiesConfig::PERIOD_INTERVENTION) {
            $tabs[7] = _n('Period of contract', 'Periods of contract', 2, 'manageentities');
         }

         if (Session::haveRight("document", UPDATE)) {
            $tabs[8] = _n('Document', 'Documents', 2);
         }

         if ($plugin->isActivated('webapplications')) {
            if (Session::haveRight("plugin_webapplications", READ)) {
               $tabs[9] = __('Web Applications', 'manageentities');
            }
         }

         if ($plugin->isActivated('accounts')) {
            if (Session::haveRight("plugin_accounts", READ)) {
               $tabs[10] = __('Accounts', 'manageentities');
            }
         }

         if ($_SESSION['glpiactiveprofile']['interface'] != 'helpdesk' && $this->canview()) {
            $tabs[11] = __('References', 'manageentities');
         }
         return $tabs;
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      if ($item->getType() == __CLASS__) {
         $PluginManageentitiesEntity          = new PluginManageentitiesEntity();
         $PluginManageentitiesContact         = new PluginManageentitiesContact();
         $PluginManageentitiesBusinessContact = new PluginManageentitiesBusinessContact();
         $PluginManageentitiesContract        = new PluginManageentitiesContract();
         $PluginManageentitiesCriDetail       = new PluginManageentitiesCriDetail();
         $config                              = new PluginManageentitiesConfig();
         $followUp                            = new PluginManageentitiesFollowUp();
         $monthly                             = new PluginManageentitiesMonthly();
         $entity                              = new Entity();

         switch ($tabnum) {
            case 1 :
               $followUp->showCriteriasForm($_GET);
               PluginManageentitiesFollowUp::showFollowUp($_GET);
               break;
            case 2 :
               $monthly->showHeader($_GET);
               PluginManageentitiesMonthly::showMonthly($_GET);
               break;
            case 3 :
               PluginManageentitiesGantt::showGantt($_GET);
               break;
            case 4 :
               $PluginManageentitiesEntity->showDescription($_SESSION["glpiactiveentities"]);
               break;
            case 5 :
               $PluginManageentitiesContract->showContracts($_SESSION["glpiactiveentities"]);
               break;
            case 6:
               $PluginManageentitiesEntity->showTickets($_SESSION["glpiactiveentities"]);
               break;
            case 7:
               $config = PluginManageentitiesConfig::getInstance();
               if (($_SESSION['glpiactiveprofile']['interface'] == 'central') || ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk' && $config->fields['choice_intervention'] == PluginManageentitiesConfig::REPORT_INTERVENTION)) {
                  $PluginManageentitiesCriDetail->showReports(0, 0, $_SESSION["glpiactiveentities"], array('condition' => "`glpi_plugin_manageentities_contractstates`.`is_closed` != 1 "));
               } elseif ($_SESSION['glpiactiveprofile']['interface'] == 'helpdesk' && $config->fields['choice_intervention'] == PluginManageentitiesConfig::PERIOD_INTERVENTION) {
                  $PluginManageentitiesCriDetail->showPeriod(0, 0, $_SESSION["glpiactiveentities"]);
               }

               break;
            case 8:
               $entity->getFromDB($_SESSION["glpiactive_entity"]);
               Document_Item::showForItem($entity);
               break;
            case 9:
               $entity->getFromDB($_SESSION["glpiactive_entity"]);
               PluginWebapplicationsWebapplication_Item::showForItem($entity);
               break;
            case 10:
               $entity->getFromDB($_SESSION["glpiactive_entity"]);
               PluginAccountsAccount_Item::showForItem($entity);
               break;
            case 11:
               $PluginManageentitiesEntity->showReferences($_SESSION["glpiactiveentities"]);
               break;
            default :
               break;
         }
      }
      return true;
   }

   // Hook done on before update document - keeps document date if it's a CRI
   static function preUpdateDocument($item) {

      // Manipulate data if needed
      $config = new PluginManageentitiesConfig();

      if ($item->getField('id') && $config->GetfromDB(1)) {

         $_SESSION["glpi_plugin_manageentities_date_mod"] = $item->getField("date_mod");

         if ($config->fields["documentcategories_id"] != $item->getField("documentcategories_id"))
            $_SESSION["glpi_plugin_manageentities_date_mod"] = $_SESSION["glpi_currenttime"];
      }
   }

   // Hook done on after update document - change document date if it's not a CRI

   static function UpdateDocument($item) {
      global $DB;

      $config = new PluginManageentitiesConfig();
      if ($item->getField('id') && $config->GetfromDB(1)) {

         $query = "UPDATE `glpi_documents`
                     SET `date_mod` = '" . $_SESSION["glpi_plugin_manageentities_date_mod"] . "'
                     WHERE `id` ='" . $item->getField('id') . "' ";

         $result = $DB->query($query);
      }

      return true;
   }

   static function showManageentitiesHeader($subtitle = '') {
      echo "<div class='plugin_manageentities_color'>";
      echo __('Portal', 'manageentities') . " " . $_SESSION["glpiactive_entity_name"];
      echo '<br/>' . $subtitle;
      echo "</div>";
   }

   function showDescription($instID) {
      global $DB, $CFG_GLPI;

      $PluginManageentitiesContact         = new PluginManageentitiesContact();
      $PluginManageentitiesBusinessContact = new PluginManageentitiesBusinessContact();
      $entity                              = new Entity();
      $entity->getFromDB($_SESSION["glpiactive_entity"]);

      self::showManageentitiesHeader(__('Data administrative', 'manageentities'));

      echo "<div align='center'><table width='100%'><tr><td colspan='2'>";

      echo "<form method='post' action='entity.form.php'>";
      echo "<table class='tab_cadre' width='80%'>";
      echo "<tr class='tab_bg_1'>";
      echo "<td style='padding-top:16px;'>";
      _e('Logo');
      echo "</td>";
      echo "<td>";

      $query = "SELECT * 
                FROM `glpi_plugin_manageentities_entitylogos` 
                WHERE `entities_id` = '" . $entity->fields["id"] . "';";

      if ($result = $DB->query($query)) {
         $number = $DB->numrows($result);
         if ($number != 0) {
            while ($ligne = $DB->fetch_assoc($result)) {
               echo "<img height='50px' alt=\"" . __s('Picture') . "\" src='" . $CFG_GLPI["root_doc"] . "/front/document.send.php?docid=" . $ligne["logos_id"] . "'>";
            }
         }
      }
      echo "</td>";


      if (sizeof($instID) == 1
          && $_SESSION['glpiactiveprofile']['interface'] != 'helpdesk') {
         echo "<td style='padding-top:16px;'>";
         _e('Logo (format JPG or JPEG)', 'manageentities');
         echo Html::file();

         echo "</td><td class='left'>";
         echo "(" . Document::getMaxUploadSize() . ")&nbsp;";
         echo "<br><input type='hidden' name='entities_id' value='" . $entity->fields["id"] . "'>";
         echo "<input type='submit' name='add' value=\"" . _sx('button', 'Update logo', 'manageentities') .
              "\" class='submit' >";
         echo "</td>";
      } else {
         echo "<td colspan='2'>";
         echo "</td>";
      }

      echo "</tr>";
      echo "<tr class='tab_bg_1'>";
      echo "<td class='top'>" . __('Name') . " </td>";
      echo "<td class='top'>";
      if ($_SESSION["glpiactive_entity"] != 0)
         echo $entity->fields["name"];
      else
         echo __('Root entity');
      if ($_SESSION["glpiactive_entity"] != 0)
         echo " (" . $entity->fields["completename"] . ")";
      echo "</td>";
      if (isset($entity->fields["comment"])) {
         echo "<td class='top'>";
         echo __('Comments') . ":   </td>";
         echo "<td class='top center'>" . nl2br($entity->fields["comment"]);
         echo "</td>";
      } else {
         echo "<td colspan='2'>&nbsp;</td>";
      }
      echo "</tr>";

      echo "<tr class='tab_bg_1'><td>" . __('Phone') . " </td>";
      echo "<td>";
      if (isset($entity->fields["phonenumber"]))
         echo $entity->fields["phonenumber"];
      echo "</td>";
      echo "<td>" . __('Fax') . " </td><td>";
      if (isset($entity->fields["fax"]))
         echo $entity->fields["fax"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __('Website') . " </td>";
      echo "<td>";
      if (isset($entity->fields["website"]))
         echo $entity->fields["website"];
      echo "</td>";

      echo "<td>" . __('Email address') . " </td><td>";
      if (isset($entity->fields["email"]))
         echo $entity->fields["email"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td  rowspan='4'>" . __('Address') . " </td>";
      echo "<td class='left' rowspan='4'>";
      if (isset($entity->fields["address"]))
         echo nl2br($entity->fields["address"]);
      echo "<td>" . __('Postal code') . "</td>";
      echo "<td>";
      if (isset($entity->fields["postcode"]))
         echo $entity->fields["postcode"];
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('City') . " </td><td>";
      if (isset($entity->fields["town"]))
         echo $entity->fields["town"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>" . _x('location', 'State') . " </td><td>";
      if (isset($entity->fields["state"]))
         echo $entity->fields["state"];
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Country') . " </td><td>";
      if (isset($entity->fields["country"]))
         echo $entity->fields["country"];
      echo "</td></tr>";
      if (sizeof($instID) == 1
          && $_SESSION['glpiactiveprofile']['interface'] != 'helpdesk') {
         echo "<tr class='tab_bg_1'>";
         echo "<td class='center' colspan='4'>";
         echo "<input type='hidden' name='entities_id' value='" . $entity->fields["id"] . "'>";
         echo "<input type='submit' name='update' value='" . _sx('button', 'Update administrative datas', 'manageentities') . "' class='submit'>";
         echo "</td></tr>";
      }
      echo "</table>";
      Html::closeForm();

      echo "</td></tr><tr><td width='40%' valign='top'>";
      $PluginManageentitiesContact->showContacts($_SESSION["glpiactiveentities"]);
      echo "</td><td width='40%' valign='top'>";
      $PluginManageentitiesBusinessContact->showBusiness($_SESSION["glpiactiveentities"]);
      echo "</td></tr></table></div>";

   }

   function showTickets($instID) {
      global $DB, $CFG_GLPI;

      PluginManageentitiesEntity::showManageentitiesHeader(__('Associated interventions', 'manageentities'));

      $instID = "'" . implode("', '", $instID) . "'";

      if (!Session::haveRight("ticket", Ticket::READALL)
          && !Session::haveRight("ticket", Ticket::READASSIGN)
          && $_SESSION['glpiactiveprofile']['interface'] != 'helpdesk') {
         return false;
      }

      $config = PluginManageentitiesConfig::getInstance();
      $and    = '';
      if ($config->fields['needvalidationforcri'] == 1) {
         $and = " AND `glpi_tickets`.`global_validation` = 'accepted' ";
      }

      echo "<div align='spaced'><table class='tab_cadrehov'>";
      echo "<tr><th>" . __('Processed interventions', 'manageentities');
      echo "</th><th>" . __('To be processed interventions', 'manageentities');

      if (Session::haveRight("ticket", Ticket::READALL)
          || Session::haveRight("ticket", Ticket::READASSIGN)
          || $_SESSION['glpiactiveprofile']['interface'] == 'helpdesk') {
         echo " <a href='" . $CFG_GLPI["root_doc"] . "/front/ticket.php?is_deleted=0&field[0]=12&searchtype[0]=equals&contains[0]=notold&itemtype=Ticket&start=0'>";
         echo __('All reports', 'manageentities') . "</a>";
      }

      echo "</th></tr>";

      //Tickets solved or closed with CRI
      echo "<tr class='tab_bg_1'><td width='50%' valign='top'>";

      //avec CRI
      $query = "SELECT `glpi_tickets`.`id`
        FROM `glpi_tickets`
        LEFT JOIN `glpi_documents` ON (`glpi_documents`.`tickets_id`
                     = `glpi_tickets`.`id`)
        WHERE `glpi_tickets`.`entities_id` IN (" . $instID . ")
        AND (`status` = '" . Ticket::SOLVED . "' OR `status` = '" . Ticket::CLOSED . "')
        AND `glpi_tickets`.`is_deleted` = '0'
         $and
        GROUP BY `id`
        ORDER BY date DESC
        LIMIT 10";

      $result = $DB->query($query);
      $i      = 0;
      $number = $DB->numrows($result);

      if ($number > 0) {
         echo "<table class='plugin_manageentities_tab_cadrehov' width='100%'>";

         echo "<tr><th></th>";
         echo "<th>" . __('Title') . "</th>";
         echo "<th width='75px'>" . __('Requester') . "</th>";
         echo "<th>" . __('Status') . "</th>";
         echo "<th>" . __('Description') . "</th></tr>";
         Session::initNavigateListItems("Ticket");

         while ($i < $number) {
            $ID = $DB->result($result, $i, "id");
            Session::addToNavigateListItems("Ticket", $ID);
            $this->showJobVeryShort($ID);
            $i++;
         }
         echo "</table>";
      }

      //Tickets assign, plan, new or waiting
      echo "</td><td width='50%' valign='top'>";

      $query = "SELECT `id`
        FROM `glpi_tickets`
        WHERE `entities_id` IN (" . $instID . ")
        AND (`status` = '" . Ticket::INCOMING . "' 
            OR `status` = '" . Ticket::PLANNED . "' 
            OR `status` = '" . Ticket::ASSIGNED . "' 
            OR `status` = '" . Ticket::WAITING . "')
        AND `is_deleted` = '0'
        
        ORDER BY date DESC
        LIMIT 10";

      $result = $DB->query($query);
      $i      = 0;
      $number = $DB->numrows($result);

      if ($number > 0) {
         echo "<table class='plugin_manageentities_tab_cadrehov' width='100%'>";

         echo "<tr><th></th>";
         echo "<th>" . __('Title') . "</th>";
         echo "<th width='75px'>" . __('Requester') . "</th>";
         echo "<th>" . __('Status') . "</th>";
         echo "<th>" . __('Description') . "</th></tr>";
         while ($i < $number) {
            $ID = $DB->result($result, $i, "id");
            $this->showJobVeryShort($ID);
            $i++;
         }
         echo "</table>";
      }

      echo "</td></tr>";
      echo "</table></div>";
   }

   function showJobVeryShort($ID) {
      global $CFG_GLPI;

      // Prints a job in short form
      // Should be called in a <table>-segment
      // Print links or not in case of user view

      // Make new job object and fill it from database, if success, print it
      $job       = new Ticket;
      $viewusers = Session::haveRight("user", READ);
      if ($job->getfromDBwithData($ID, 0)) {
         $bgcolor = $CFG_GLPI["priority_" . $job->fields["priority"]];

         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' bgcolor='$bgcolor' >id: " . $job->fields["id"] . "</td>";

         echo "<td>";
         echo "<a href=\"" . $CFG_GLPI["root_doc"] . "/front/ticket.form.php?id=" . $job->fields["id"] . "\">";
         echo $job->fields["name"] . "</a></td>";

         echo "<td class='center b'>";
         $users = $job->getUsers(CommonItilActor::REQUESTER);
         if (count($users)) {
            foreach ($users as $d) {
               $userdata = getUserName($d['users_id'], 2);
               echo "<strong>" . $userdata['name'] . "</strong>&nbsp;";
               if ($viewusers) {
                  Html::showToolTip($userdata["comment"], array('link' => $userdata["link"]));
               }
               echo "<br>";
            }
         }

         $groups = $job->getGroups(CommonItilActor::REQUESTER);
         if (count($groups)) {
            foreach ($groups as $k => $d) {
               echo Dropdown::getDropdownName("glpi_groups", $k);
               echo "<br>";
            }
         }

         echo "</td>";
         echo "<td class='center'>" . Ticket::getStatus($job->fields["status"]) . "</td>";

         echo "<td>" . Html::resume_text($job->fields["content"], $CFG_GLPI["cut"]);
         echo "</td>";
         // Finish Line
         echo "</tr>";
      } else {
         echo "<tr class='tab_bg_2'><td colspan='6' ><i>" . __('No ticket in progress.') . "</i></td></tr>";
      }
   }

   static function getMenuContent() {
      $plugin_page = "/plugins/manageentities/front/entity.php";
      $menu        = array();
      //Menu entry in tools
      $menu['title']           = self::getTypeName();
      $menu['page']            = $plugin_page;
      $menu['links']['search'] = $plugin_page;
      if (Session::haveRightsOr("plugin_manageentities", array(CREATE, UPDATE)) || Session::haveRight("config", UPDATE)) {
         //Entry icon in breadcrumb
         $menu['links']['config'] = PluginManageentitiesConfig::getFormURL(false);
         //Link to config page in admin plugins list
         $menu['config_page']  = PluginManageentitiesConfig::getFormURL(false);
         $menu['links']['add'] = '/plugins/manageentities/front/addelements.form.php';
      }

      $menu['options']['contractday']['title'] = PluginManageentitiesContractDay::getTypeName(2);
      $menu['options']['contractday']['page']  = '/plugins/manageentities/front/contractday.php';
      //      $menu['options']['contractday']['add']    = '/plugins/manageentities/front/contractday.form.php';
      //      $menu['options']['contractday']['links']['add'] = '/plugins/manageentities/front/contractday.form.php';
      $menu['options']['contractday']['search']          = '/plugins/manageentities/front/contractday.php';
      $menu['options']['contractday']['links']['search'] = '/plugins/manageentities/front/contractday.php';

      $menu['options']['company']['title']           = PluginManageentitiesCompany::getTypeName(2);
      $menu['options']['company']['page']            = '/plugins/manageentities/front/company.php';
      $menu['options']['company']['add']             = '/plugins/manageentities/front/company.form.php';
      $menu['options']['company']['links']['add']    = '/plugins/manageentities/front/company.form.php';
      $menu['options']['company']['search']          = '/plugins/manageentities/front/company.php';
      $menu['options']['company']['links']['search'] = '/plugins/manageentities/front/company.php';

      return $menu;
   }

   function getRights($interface = 'central') {

      $values = array(CREATE => __('Create'),
                      READ   => __('Read'),
                      UPDATE => __('Update'),
                      PURGE  => array('short' => __('Purge'),
                                      'long'  => _x('button', 'Delete permanently')));

      return $values;
   }

   function showReferences($instID) {
      global $DB, $CFG_GLPI;

      $entity = new Entity();
      $entity->getFromDB($_SESSION["glpiactive_entity"]);

      self::showManageentitiesHeader(__('References', 'manageentities'));

      //echo "<div align='center'><table width='100%'><tr><td colspan='2'>";

      echo "<table class='tab_cadre' width='60%'>";

      $logos = array();
      $logos = getAllDatasFromTable("glpi_plugin_manageentities_entitylogos");

      //glpi_plugin_manageentities_contracts
      //$query = "SELECT * 
      //          FROM `glpi_plugin_manageentities_entitylogos`;";

      $cpt = count($logos);

      $dates = array();
      $grp   = array();
      if ($cpt > 0) {
         foreach ($logos as $logo) {
            $ID = $logo["entities_id"];

            $contracts = array();
            $contracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", "entities_id ='" . $ID . "'");

            if (count($contracts) > 0) {
               foreach ($contracts as $contract) {
                  $dates[$ID][] = $contract["date_signature"];
               }
            }
         }
      }

      if ($cpt > 0) {
         $grp = $logos;
         foreach ($logos as $logo) {
            $ID = $logo["entities_id"];

            $grp[$logo["id"]]["date_signature"] = (isset($dates[$ID][0]) ? date("Y", strtotime($dates[$ID][0])) : null);
         }
      }
      $years = array();
      if (count($grp) > 0) {
         foreach ($grp as $k => $v) {
            $years[$v['date_signature']][$k] = $v;
         }
      }
      krsort($years);
      if (count($years) > 0) {

         foreach ($years as $name => $year) {
            $i  = 0;
            $ct = count($year);
            echo "<tr>";
            echo "<th colspan='4'>" . $name . "</th>";
            echo "</tr>";
            foreach ($year as $client) {
               $ID     = $client["entities_id"];
               $entity = new Entity();
               $entity->getFromDB($ID);
               if ($i % 2 == 0 && $ct > 1) {
                  echo "<tr>";
               }
               if ($ct == 1) {
                  echo "<tr>";
               }

               echo "<td>" . $entity->getName() . "</td>";
               echo "<td><img height='50px' alt=\"" . __s('Picture') . "\" src='" . $CFG_GLPI["root_doc"] . "/front/document.send.php?docid=" . $client["logos_id"] . "'></td>";

               $i++;
               if (($i == $ct)
                   && ($ct % 2 != 0)
                   && $ct > 1) {
                  echo "<td>&nbsp;</td>";
                  echo "<td>&nbsp;</td>";
                  echo "</tr>";
               }
            }
         }
      }
      echo "</table>";

      if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
         echo "<br><table class='tab_cadre'>";
         echo "<tr>";
         echo "<th colspan='2'>" . __('DEBUG') . "</th>";
         echo "</tr>";

         echo "<tr>";
         echo "<th>" . __('Entity') . "</th>";
         echo "<th>" . __('Date of signature', 'manageentities') . "</th>";
         echo "</tr>";
         /*$query = "SELECT *
                         FROM `glpi_plugin_timelineticket_assigngroups`
                         WHERE `tickets_id` = '".$ticket->getID()."'";

         $result    = $DB->query($query);
         while ($data = $DB->fetch_assoc($result)) {

            echo "<tr class='tab_bg_1'>";
            echo "<td>".$data['id']."</td>";
            echo "<td>".Html::convDateTime($data['date'])."</td>";
            echo "<td>".Dropdown::getDropdownName("glpi_groups", $data['groups_id'])."</td>";
            echo "<td>".Html::timestampToString($data['begin'])."</td>";
            echo "<td>".Html::timestampToString($data['delay'])."</td>";
            echo "</tr>";

         }*/

         $clients = array();
         $clients = getAllDatasFromTable("glpi_entities", "id NOT IN (SELECT entities_id
                         FROM `glpi_plugin_manageentities_entitylogos`) AND entities_id ='57' ORDER BY id");

         //glpi_plugin_manageentities_contracts
         //$query = "SELECT * 
         //          FROM `glpi_plugin_manageentities_entitylogos`;";

         $cpt = count($clients);

         $dates = array();
         $grp   = array();
         if ($cpt > 0) {
            foreach ($clients as $client) {

               $ID     = $client["id"];
               $entity = new Entity();
               $entity->getFromDB($ID);
               echo "<tr class='tab_bg_1'>";
               echo "<td>" . $entity->getName() . "</td>";

               $contracts = array();
               $contracts = getAllDatasFromTable("glpi_plugin_manageentities_contracts", "entities_id ='" . $ID . "'");
               echo "<td>";
               if (count($contracts) > 0) {
                  foreach ($contracts as $contract) {
                     echo Html::convDateTime($contract["date_signature"]) . "<br>";
                  }
               }
               echo "</td>";
               echo "</tr>";
            }
         }
         echo "</table>";
      }
   }
}

?>