<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

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

define('PLUGIN_MANAGEENTITIES_VERSION', '4.0.3');

if (!defined("PLUGIN_MANAGEENTITIES_DIR")) {
   define("PLUGIN_MANAGEENTITIES_DIR", Plugin::getPhpDir("manageentities"));
   define("PLUGIN_MANAGEENTITIES_NOTFULL_DIR", Plugin::getPhpDir("manageentities",false));
   define("PLUGIN_MANAGEENTITIES_WEBDIR", Plugin::getWebDir("manageentities"));
   define("PLUGIN_MANAGEENTITIES_NOTFULL_WEBDIR", Plugin::getWebDir("manageentities",false));
}

include_once PLUGIN_MANAGEENTITIES_DIR . "/vendor/autoload.php";

// Init the hooks of the plugins -Needed
function plugin_init_manageentities() {
   global $PLUGIN_HOOKS, $CFG_GLPI;

   $PLUGIN_HOOKS['csrf_compliant']['manageentities'] = true;
   $PLUGIN_HOOKS['change_profile']['manageentities'] = ['PluginManageentitiesProfile', 'initProfile'];

   $PLUGIN_HOOKS['pre_item_purge']['manageentities'] = ['Entity'       => 'plugin_pre_item_purge_manageentities',
                                                             'Ticket'       => 'plugin_pre_item_purge_manageentities',
                                                             'Contract'     => 'plugin_pre_item_purge_manageentities',
                                                             'Contact'      => 'plugin_pre_item_purge_manageentities',
                                                             'TaskCategory' => 'plugin_pre_item_purge_manageentities'];

   $PLUGIN_HOOKS['pre_item_update']['manageentities'] = ['Document' => ['PluginManageentitiesEntity', 'preUpdateDocument']];
   $PLUGIN_HOOKS['item_update']['manageentities']     = ['Document' => ['PluginManageentitiesEntity', 'UpdateDocument']];

   $PLUGIN_HOOKS['item_transfer']['manageentities'] = 'plugin_item_transfer_manageentities';

   if (Session::getLoginUserID()) {
      Plugin::registerClass('PluginManageentitiesProfile', ['addtabon' => 'Profile']);
      Plugin::registerClass('PluginManageentitiesContract', ['addtabon' => 'Contract']);
      Plugin::registerClass('PluginManageentitiesCriDetail', ['addtabon'       => 'Ticket',
                                                                   'planning_types' => true]);


      Plugin::registerClass('PluginManageentitiesTaskCategory', ['addtabon' => 'TaskCategory']);
      Plugin::registerClass('PluginManageentitiesInterventionSkateholder', ['addtabon' => 'PluginManageentitiesContractDay']);
      Plugin::registerClass('PluginManageentitiesCriPrice', ['addtabon' => 'PluginManageentitiesContractDay']);

      if (Plugin::isPluginActive('servicecatalog')) {
         $PLUGIN_HOOKS['servicecatalog']['manageentities'] = ['PluginManageentitiesServicecatalog'];
      }

      if (Session::haveRight("ticket", CREATE)
          && Session::haveRight("plugin_manageentities_cri_create", CREATE)) {
         $PLUGIN_HOOKS["menu_toadd"]['manageentities']['helpdesk']  = 'PluginManageentitiesGenerateCRI';
      }

      if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])
          && !Plugin::isPluginActive('servicecatalog')) {
         $PLUGIN_HOOKS['helpdesk_menu_entry']['manageentities'] = PLUGIN_MANAGEENTITIES_NOTFULL_DIR."/front/entity.php";
         $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['manageentities'] = PluginManageentitiesEntity::getIcon();
      }
      if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])) {
         Plugin::registerClass('PluginManageentitiesPreference',['addtabon' => 'Preference']); //See #413
         $PLUGIN_HOOKS['menu_toadd']['manageentities']['management'] = 'PluginManageentitiesEntity';

         // Reports
         $PLUGIN_HOOKS['reports']['manageentities'] = ['front/report.form.php'            => _n('Intervention report', 'Intervention reports', 2, 'manageentities'),
                                                            'front/report_moving.form.php'     => __('Report on the movement of technicians', 'manageentities'),
                                                            'front/report_occupation.form.php' => __('Report concerning the occupation of the technicians', 'manageentities')];


         if (isset($_SESSION["glpi_plugin_manageentities_loaded"])
             && $_SESSION["glpi_plugin_manageentities_loaded"] == 0
             && Plugin::isPluginActive("manageentities")) {
            $_SESSION["glpi_plugin_manageentities_loaded"] = 1;
            Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php");
         }
      }

      if (Session::haveRight("plugin_manageentities", UPDATE)) {
         $PLUGIN_HOOKS['config_page']['manageentities'] = 'front/config.form.php';
      }

      if (Plugin::isPluginActive('servicecatalog')) {
         $PLUGIN_HOOKS['mydashboard']['manageentities'] = ["PluginManageentitiesDashboard"];
      }

      $PLUGIN_HOOKS['post_item_form']['manageentities'] = ['PluginManageentitiesTicketTask', 'postForm'];
      // Add specific files to add to the header : javascript or css
      $PLUGIN_HOOKS['add_css']['manageentities'] = ["manageentities.css", "style.css"];

      if (isset($_SESSION['glpiactiveprofile']['interface'])
          && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
         $PLUGIN_HOOKS['add_javascript']['manageentities'] = ['scripts/scripts-manageentities.js',
                                                              'scripts/jquery.form.js'];
      }
      // Ticket task duplication
//      if (Session::haveRight("task", CommonITILTask::UPDATEALL)
//          && Session::haveRight("task", CommonITILTask::ADDALLITEM)
//          && strpos($_SERVER['REQUEST_URI'], "ticket.form.php") !== false
//          && strpos($_SERVER['REQUEST_URI'], 'id=') !== false
//          && Session::haveRight("plugin_manageentities", READ)) {
//
//         $PLUGIN_HOOKS['add_javascript']['manageentities'][] = 'scripts/manageentities_load_scripts.js';
//      }
      $PLUGIN_HOOKS['post_init']['manageentities'] = 'plugin_manageentities_postinit';
   }
}

// Get the name and the version of the plugin - Needed
function plugin_version_manageentities() {

   return [
      'name'           => __('Entities portal', 'manageentities'),
      'version'        => PLUGIN_MANAGEENTITIES_VERSION,
      'oldname'        => 'manageentity',
      'author'         => "<a href='http://blogglpi.infotel.com'>Infotel</a>",
      'license'        => 'GPLv2+',
      'homepage'       => 'https://github.com/InfotelGLPI/manageentities',
      'requirements'   => [
         'glpi' => [
            'min' => '10.0',
            'max' => '11.0',
            'dev' => false
         ]
      ]
   ];

}

/**
 * @return bool
 */
function plugin_manageentities_check_prerequisites() {

   if (!is_readable(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
      echo "Run composer install --no-dev in the plugin directory<br>";
      return false;
   }

   return true;
}
