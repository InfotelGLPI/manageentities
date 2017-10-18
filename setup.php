<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2017 by the Manageentities Development Team.

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

// Init the hooks of the plugins -Needed
function plugin_init_manageentities() {
   global $PLUGIN_HOOKS, $CFG_GLPI;

   $PLUGIN_HOOKS['csrf_compliant']['manageentities'] = true;
   $PLUGIN_HOOKS['change_profile']['manageentities'] = array('PluginManageentitiesProfile', 'initProfile');

   $PLUGIN_HOOKS['pre_item_purge']['manageentities'] = array('Entity'       => 'plugin_pre_item_purge_manageentities',
                                                             'Ticket'       => 'plugin_pre_item_purge_manageentities',
                                                             'Contract'     => 'plugin_pre_item_purge_manageentities',
                                                             'Contact'      => 'plugin_pre_item_purge_manageentities',
                                                             'TaskCategory' => 'plugin_pre_item_purge_manageentities');

   $PLUGIN_HOOKS['pre_item_update']['manageentities'] = array('Document' => array('PluginManageentitiesEntity', 'preUpdateDocument'));
   $PLUGIN_HOOKS['item_update']['manageentities']     = array('Document' => array('PluginManageentitiesEntity', 'UpdateDocument'));

   $PLUGIN_HOOKS['item_transfer']['manageentities'] = 'plugin_item_transfer_manageentities';

   if (Session::getLoginUserID()) {
      Plugin::registerClass('PluginManageentitiesProfile', array('addtabon' => 'Profile'));
      Plugin::registerClass('PluginManageentitiesContract', array('addtabon' => 'Contract'));
      Plugin::registerClass('PluginManageentitiesCriDetail', array('addtabon'       => 'Ticket',
                                                                   'planning_types' => true));


      Plugin::registerClass('PluginManageentitiesTaskCategory', array('addtabon' => 'TaskCategory'));
      Plugin::registerClass('PluginManageentitiesInterventionSkateholder', array('addtabon' => 'PluginManageentitiesContractDay'));
      Plugin::registerClass('PluginManageentitiesCriPrice', array('addtabon' => 'PluginManageentitiesContractDay'));

      if (class_exists('PluginServicecatalogMain')) {
         $PLUGIN_HOOKS['servicecatalog']['manageentities'] = array('PluginManageentitiesServicecatalog');
      }

      if (session::haveRightsOr('plugin_manageentities', array(READ, UPDATE))
          && !class_exists('PluginServicecatalogMain')) {
         $PLUGIN_HOOKS['helpdesk_menu_entry']['manageentities'] = "/front/entity.php";
      }
      if (session::haveRightsOr('plugin_manageentities', array(READ, UPDATE))) {
         Plugin::registerClass('PluginManageentitiesPreference', array('addtabon' => 'Preference')); //See #413
         $PLUGIN_HOOKS['menu_toadd']['manageentities'] = array('management' => 'PluginManageentitiesEntity');

         // Reports
         $PLUGIN_HOOKS['reports']['manageentities'] = array('front/report.form.php'            => _n('Intervention report', 'Intervention reports', 2, 'manageentities'),
                                                            'front/report_moving.form.php'     => __('Report on the movement of technicians', 'manageentities'),
                                                            'front/report_occupation.form.php' => __('Report concerning the occupation of the technicians', 'manageentities'));

         $plugin = new Plugin();
         if (isset($_SESSION["glpi_plugin_manageentities_loaded"])
             && $_SESSION["glpi_plugin_manageentities_loaded"] == 0
             && class_exists('PluginManageentitiesContract')
             && $plugin->isActivated("manageentities")) {
            $_SESSION["glpi_plugin_manageentities_loaded"] = 1;
            Html::redirect($CFG_GLPI['root_doc'] . "/plugins/manageentities/front/entity.php");
         }
      }

      if (Session::haveRight("plugin_manageentities", UPDATE)) {
         $PLUGIN_HOOKS['config_page']['manageentities'] = 'front/config.form.php';
      }

      if (class_exists('PluginMydashboardMenu')) {
         $PLUGIN_HOOKS['mydashboard']['manageentities'] = array("PluginManageentitiesDashboard");
      }

      // Add specific files to add to the header : javascript or css
      $PLUGIN_HOOKS['add_css']['manageentities'] = array("manageentities.css",
                                                         "style.css");

      $PLUGIN_HOOKS['add_javascript']['manageentities'] = array('scripts/scripts-manageentities.js',
                                                                'scripts/jquery.form.js',);
      // Ticket task duplication
      if (Session::haveRight("task", CommonITILTask::UPDATEALL)
          && Session::haveRight("task", CommonITILTask::ADDALLITEM)
          && strpos($_SERVER['REQUEST_URI'], "ticket.form.php") !== false
          && strpos($_SERVER['REQUEST_URI'], 'id=') !== false
          && Session::haveRight("plugin_manageentities", READ)) {

         $PLUGIN_HOOKS['add_javascript']['manageentities'][] = 'scripts/manageentities_load_scripts.js';
      }
      $PLUGIN_HOOKS['post_init']['manageentities'] = 'plugin_manageentities_postinit';
   }
}

// Get the name and the version of the plugin - Needed
function plugin_version_manageentities() {

   return array(
      'name'           => __('Entities portal', 'manageentities'),
      'version'        => '2.3.0',
      'oldname'        => 'manageentity',
      'author'         => 'Infotel',
      'license'        => 'GPLv2+',
      'homepage'       => 'https://github.com/InfotelGLPI/manageentities',
      'minGlpiVersion' => '9.2',
   );

}

// Optional : check prerequisites before install : may print errors or add to message after redirect
function plugin_manageentities_check_prerequisites() {
   if (version_compare(GLPI_VERSION, '9.2', 'lt') || version_compare(GLPI_VERSION, '9.3', 'ge')) {
      echo __('This plugin requires GLPI >= 9.2');
      return false;
   }
   return true;
}

// Uninstall process for plugin : need to return true if succeeded : may display messages or add to message after redirect
function plugin_manageentities_check_config() {
   return true;
}

?>