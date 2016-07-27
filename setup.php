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

// Init the hooks of the plugins -Needed
function plugin_init_manageentities() {
   global $PLUGIN_HOOKS,$CFG_GLPI,$LANG;
   
   $PLUGIN_HOOKS['csrf_compliant']['manageentities'] = true;
   $PLUGIN_HOOKS['change_profile']['manageentities'] = array('PluginManageentitiesProfile','changeProfile');
   
   $PLUGIN_HOOKS['pre_item_purge']['manageentities'] = array('Profile'=>array('PluginManageentitiesProfile', 'purgeProfiles'),
                                                             'Entity'=>'plugin_pre_item_purge_manageentities',
                                                             'Ticket'=>'plugin_pre_item_purge_manageentities',
                                                             'Contract'=>'plugin_pre_item_purge_manageentities',
                                                             'Contact'=>'plugin_pre_item_purge_manageentities',
                                                             'Document'=>'plugin_pre_item_purge_manageentities',
                                                             'TaskCategory'=>'plugin_pre_item_purge_manageentities');
   $PLUGIN_HOOKS['pre_item_update']['manageentities'] = array('Document'=>array('PluginManageentitiesEntity', 'preUpdateDocument'));
   $PLUGIN_HOOKS['item_update']['manageentities'] = array('Document'=>array('PluginManageentitiesEntity', 'UpdateDocument'));

   //TODO work in progress
   $PLUGIN_HOOKS['item_transfer']['manageentities'] ='plugin_item_transfer_manageentities';
   
   if (Session::getLoginUserID()) {
      
      Plugin::registerClass('PluginManageentitiesProfile',
                         array('addtabon' => 'Profile'));
      
      Plugin::registerClass('PluginManageentitiesPreference',
                         array('addtabon' => 'Preference'));

      Plugin::registerClass('PluginManageentitiesContract',
                         array('addtabon' => 'Contract'));
      
      Plugin::registerClass('PluginManageentitiesCriDetail',
                         array('addtabon' => 'Ticket'));

      Plugin::registerClass('PluginManageentitiesTaskCategory',
                         array('addtabon' => 'TaskCategory'));
                                           
      // Display a menu entry ?
      if (plugin_manageentities_haveRight("manageentities","r") || Session::haveRight("config","w")) {

         $PLUGIN_HOOKS['menu_entry']['manageentities'] = 'front/entity.php';
         $PLUGIN_HOOKS['helpdesk_menu_entry']['manageentities'] = '/front/entity.php';
         $PLUGIN_HOOKS['submenu_entry']['manageentities']['search'] = 'front/entity.php';
         $PLUGIN_HOOKS['submenu_entry']['manageentities']['config'] = 'front/config.form.php';
         // Reports
         $PLUGIN_HOOKS['reports']['manageentities'] = array('front/report.form.php'=>$LANG['plugin_manageentities']['onglet'][3]);

         $plugin = new Plugin();
         if (isset($_SESSION["glpi_plugin_manageentities_loaded"]) 
            && $_SESSION["glpi_plugin_manageentities_loaded"] == 0
            && class_exists('PluginManageentitiesContract')
            && $plugin->isActivated("manageentities")) {
            $_SESSION["glpi_plugin_manageentities_loaded"] = 1;
            Html::redirect(GLPI_ROOT."/plugins/manageentities/front/entity.php");
         }
     }

      if (plugin_manageentities_haveRight("manageentities","w") || Session::haveRight("config","w")) {
         $PLUGIN_HOOKS['config_page']['manageentities'] = 'front/config.form.php';
      }

      // Add specific files to add to the header : javascript or css
      //$PLUGIN_HOOKS['add_javascript']['manageentities']="manageentities.js";
      $PLUGIN_HOOKS['add_css']['manageentities']="manageentities.css";

      // Config page
      if (plugin_manageentities_haveRight("manageentities","w") || Session::haveRight("config","w"))
         $PLUGIN_HOOKS['config_page']['manageentities'] = 'front/config.form.php';
      
   }
}

// Get the name and the version of the plugin - Needed

function plugin_version_manageentities() {
   global $LANG;

   return array (
      'name' => $LANG['plugin_manageentities']['title'][1],
      'version' => '1.9.0',
      'oldname' => 'manageentity',
      'author'=>'Xavier Caillaud, Faustine Crespel',
      'license' => 'GPLv2+',
      'oldname' => 'manageentity',
      'homepage'=>'https://forge.indepnet.net/projects/show/manageentities',
      'minGlpiVersion' => '0.83.3',// For compatibility / no install in version < 0.83
   );

}

// Optional : check prerequisites before install : may print errors or add to message after redirect
function plugin_manageentities_check_prerequisites() {
   if (version_compare(GLPI_VERSION,'0.83.3','lt') || version_compare(GLPI_VERSION,'0.84','ge')) {
      echo "This plugin requires GLPI >= 0.83.3";
      return false;
   }
   return true;
}

// Uninstall process for plugin : need to return true if succeeded : may display messages or add to message after redirect
function plugin_manageentities_check_config() {
   return true;
}

function plugin_manageentities_haveRight($module,$right) {
   $matches=array(
         ""  => array("","r","w"), // ne doit pas arriver normalement
         "r" => array("r","w"),
         "w" => array("w"),
         "1" => array("1"),
         "0" => array("0","1"), // ne doit pas arriver non plus
            );
   if (isset($_SESSION["glpi_plugin_manageentities_profile"][$module])
      &&in_array($_SESSION["glpi_plugin_manageentities_profile"][$module],$matches[$right]))
      return true;
   else return false;
}

?>