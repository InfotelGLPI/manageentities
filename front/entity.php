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

define('GLPI_ROOT', '../../..');
include (GLPI_ROOT."/inc/includes.php");

$PluginManageentitiesContract=new PluginManageentitiesContract();
$PluginManageentitiesContact=new PluginManageentitiesContact();
$PluginManageentitiesEntity=new PluginManageentitiesEntity();
$PluginManageentitiesCri=new PluginManageentitiesCri();
$followUp = new PluginManageentitiesFollowUp();

if (isset($_GET)) $tab = $_GET;
if (empty($tab) && isset($_POST)) $tab = $_POST;
if (!isset($tab["id"])) $tab["id"] = "";
if (!isset($_POST["entities_id"])) $_POST["entities_id"] = "";

if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
   Html::header($LANG['plugin_manageentities']['title'][1],'',"plugins","manageentities");
} else {
   Html::helpHeader($LANG['plugin_manageentities']['title'][1]);
}

if ($PluginManageentitiesEntity->canView() || Session::haveRight("config","w")) {

   if (isset($_POST["addcontracts"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContract->addContract($_POST["contracts_id"],$_POST["entities_id"]);
      Html::back();
   }
   else if (isset($_POST["deletecontracts"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContract->deleteContract($_POST["id"]);
      Html::back();

   } else if (isset($_POST["contractbydefault"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContract->addContractByDefault($_POST["myid"],$_POST["entities_id"]);
      Html::back();
      
   } else if (isset($_POST["addcontacts"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContact->addContact($_POST["contacts_id"],$_POST["entities_id"]);
      Html::back();
      
   } else if (isset($_POST["deletecontacts"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContact->deleteContact($_POST["id"]);
      Html::back();

   } else if (isset($_POST["contactbydefault"])) {

      if ($PluginManageentitiesEntity->canCreate())
         $PluginManageentitiesContact->addContactByDefault($_POST["contacts_id"],$_POST["entities_id"]);
      Html::back();

   } else {

   // Manage entity change
      if (isset($_GET["active_entity"])) {
         if (!isset($_GET["is_recursive"])) {
            $_GET["is_recursive"]=0;
         }
         Session::changeActiveEntities($_GET["active_entity"],$_GET["is_recursive"]);
         if ($_GET["active_entity"]==$_SESSION["glpiactive_entity"]) {
            Html::redirect(preg_replace("/entities_id.*/","",$CFG_GLPI["root_doc"]."/plugins/manageentities/front/entity.php"));
         }
      } else if (isset($_POST["choice_entity"]) && $_POST["entities_id"]!=0) {

         Html::redirect($CFG_GLPI["root_doc"]."/plugins/manageentities/front/entity.php?active_entity=".$_POST["entities_id"]."");

      } else {

         // show "my view" in first
         if (!isset($_SESSION['glpi_plugin_manageentities_tab'])) $_SESSION['glpi_plugin_manageentities_tab']="description";
            if (isset($_GET['onglet'])) {
               $_SESSION['glpi_plugin_manageentities_tab']=$_GET['onglet'];
               //      Html::back();
            }
         if (isset($_POST["searchcontract"])) {

            $options = "&begin_date=".$_POST['begin_date']."&end_date=".$_POST['end_date'].
               "&contractstates_id=".$_POST['plugin_manageentities_contractstates_id'].
               "&entities_id=".$_POST['entities_id'];

         } else {
            $options = "&begin_date=NULL&end_date=NULL&contractstates_id=0&entities_id=-1";
         }

         $tabs['follow-up']=array('title'=>$LANG['plugin_manageentities']['onglet'][0],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=follow-up".$options);

         $tabs['description']=array('title'=>$LANG['plugin_manageentities']['onglet'][1],
         'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
         'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=description");

         if (Session::haveRight("contract","r")) {
            $tabs['contract']=array('title'=>$LANG['plugin_manageentities']['onglet'][5],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=contract");
         }
         if (Session::haveRight("show_all_ticket","1")
            || Session::haveRight("show_assign_ticket","1")
            || $_SESSION['glpiactiveprofile']['interface'] == 'helpdesk') {
            $tabs['tickets']=array('title'=>$LANG['plugin_manageentities']['onglet'][2],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=tickets");
         }
         if ($PluginManageentitiesCri->canView()) {
            $tabs['reports']=array('title'=>$LANG['plugin_manageentities']['onglet'][3],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=reports");
         }

         if (Session::haveRight("document","r")) {
            $tabs['documents']=array('title'=>$LANG['plugin_manageentities']['onglet'][4],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=documents");
         }
         $plugin = new Plugin();
         
         if ($plugin->isActivated("webapplications") 
               && plugin_webapplications_haveRight("webapplications","r")) {
            $tabs['webapplications']=array('title'=>$LANG['plugin_manageentities']['onglet'][6],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=webapplications");
         }
         if ($plugin->isActivated("accounts") 
               && plugin_accounts_haveRight("accounts","r")) {
            $tabs['accounts']=array('title'=>$LANG['plugin_manageentities']['onglet'][7],
            'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
            'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=accounts");
         }
         $tabs['all']=array('title'=>$LANG['common'][66],
         'url'=>$CFG_GLPI['root_doc']."/plugins/manageentities/ajax/entity.tabs.php",
         'params'=>"target=".$_SERVER['PHP_SELF']."&plugin_manageentities_tab=all");

         echo "<div id='tabspanel' class='center-h'></div>";
         Ajax::createTabs('tabspanel','tabcontent',$tabs,'PluginManageentitiesEntity');
         $PluginManageentitiesEntity->addDivForTabs();

      }
   }
} else {
   Html::displayRightError();
}

if ($_SESSION['glpiactiveprofile']['interface'] == 'central') {
   Html::footer();
} else {
   Html::helpFooter();
}

?>