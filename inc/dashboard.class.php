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

class PluginManageentitiesDashboard extends CommonGLPI {

   public  $widgets = array();
   private $options;
   private $datas, $form;

   function __construct($options = array()) {
      $this->options = $options;
   }

   function init() {


   }

   function getWidgetsForItem() {
      return array(
         $this->getType() . "1" => __("Remaining days number by opened client contracts", "manageentities"),//Nombre de jours restants par contrat client
         $this->getType() . "2" => __("Client annuary", "manageentities"),
         $this->getType() . "3" => __("Tickets without CRI", "manageentities"),
         $this->getType() . "4" => __("Interventions with old contract", "manageentities"),
         $this->getType() . "5" => __("Opened contract prestations without remaining days", "manageentities"),

      );
   }

   function getWidgetContentForItem($widgetId) {
      global $CFG_GLPI, $DB;

      if (empty($this->form))
         $this->init();
      switch ($widgetId) {
         case $this->getType() . "1":

            $plugin = new Plugin();
            $widget = new PluginMydashboardHtml();
            if ($plugin->isActivated("manageentities")) {
               $link_contract     = Toolbox::getItemTypeFormURL("Contract");
               $link_contract_day = Toolbox::getItemTypeFormURL("PluginManageentitiesContractDay");
               $entity            = new Entity();
               $contracts         = PluginManageentitiesFollowUp::queryFollowUp($_SESSION['glpiactiveentities'], array());
               $datas             = array();
               if (!empty($contracts)) {
                  foreach ($contracts as $key => $contract_data) {
                     if (is_integer($key)) {

                        if (!is_null($contract_data['contract_begin_date'])
                            && $contract_data['show_on_global_gantt'] > 0) {

                           foreach ($contract_data['days'] as $key => $days) {
                              if ($days['contract_is_closed']) {
                                 unset($contract_data['days'][$key]);
                              }
                           }

                           if (!empty($contract_data['days'])) {

                              foreach ($contract_data['days'] as $day_data) {

                                 $entity->getFromDB($contract_data['entities_id']);
                                 $data["parent"] = getTreeLeafValueName("glpi_entities", $entity->fields['entities_id']);

                                 $data["entities_id"] = $contract_data['entities_name'];

                                 $name_contract        = "<a href='" . $link_contract . "?id=" . $contract_data["contracts_id"] . "' target='_blank'>";
                                 $name_contract        .= $contract_data['name'] . "</a>";
                                 $data["contracts_id"] = $name_contract;

                                 $name_contract_day = "<a href='" . $link_contract_day . "?id=" . $day_data['contractdays_id'] . "' target='_blank'>";
                                 $name_contract_day .= $day_data['contractdayname'] . "</a>";
                                 $data["days"]      = $name_contract_day;

                                 $data["total"] = $day_data['credit'];
                                 $data["reste"] = $day_data['reste'];
                                 $datas[]       = $data;
                              }
                           }
                        }
                     }
                  }
               }

               $headers = array(__('Team', 'manageentities'), __('Entity'), __('Contract'), __('Prestation', 'manageentities'), __('Total'), __('Total remaining', 'manageentities'));

               $widget = new PluginMydashboardDatatable();

               $widget->setTabNames($headers);
               $widget->setTabDatas($datas);
               $widget->toggleWidgetRefresh();


            } else {
               $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
            }

            $widget->setWidgetTitle(__("Remaining days number by opened client contracts", "manageentities"));

            return $widget;
            break;
         case $this->getType() . "2":
            $plugin = new Plugin();
            $widget = new PluginMydashboardHtml();

            if ($plugin->isActivated("manageentities")) {

               $query = "SELECT `glpi_entities`.`name` AS client,`glpi_contacts`.`firstname`, `glpi_contacts`.`name`, `glpi_contacts`.`phone`, `glpi_contacts`.`mobile`
                           FROM `glpi_contacts`
                           LEFT JOIN `glpi_plugin_manageentities_contacts` ON (`glpi_plugin_manageentities_contacts`.`contacts_id` = `glpi_contacts`.`id`)
                           LEFT JOIN `glpi_entities` ON (`glpi_plugin_manageentities_contacts`.`entities_id` = `glpi_entities`.`id`)
                           WHERE `glpi_contacts`.`is_deleted` = '0'
                           AND NOT `glpi_entities`.`name` = ''
                           AND ((NOT `glpi_contacts`.`phone` = ''
                           AND `glpi_contacts`.`phone` IS NOT NULL)
                           OR (NOT `glpi_contacts`.`mobile` = ''
                           AND `glpi_contacts`.`mobile` IS NOT NULL))
                           AND `glpi_entities`.`name` IS NOT NULL 
                           " . getEntitiesRestrictRequest("AND", "glpi_contacts", "entities_id", '', true) . "
                           ORDER BY `glpi_entities`.`name`,`glpi_contacts`.`name`, `glpi_contacts`.`firstname` ASC";

               $widget  = PluginMydashboardHelper::getWidgetsFromDBQuery('table', $query);
               $headers = array(_n('Client', 'Clients', 1, 'manageentities'), __('First name'), __('Name'), __('Phone'), __('Mobile phone'));
               $widget->setTabNames($headers);
               $widget->toggleWidgetRefresh();
            } else {
               $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
            }
            $widget->setWidgetTitle(__("Client annuary", "manageentities"));

            return $widget;
            break;
         case $this->getType() . "3":
            $plugin = new Plugin();
            $widget = new PluginMydashboardHtml();
            if ($plugin->isActivated("manageentities")) {
               $link_contract_day = Toolbox::getItemTypeFormURL("PluginManageentitiesContractDay");
               $link_ticket       = Toolbox::getItemTypeFormURL("Ticket");

               $query = "SELECT `glpi_entities`.`name` AS entity, 
                                    `glpi_tickets`.`date`,
                                    `glpi_tickets`.`id` AS tickets_id, 
                                    `glpi_tickets`.`name` AS title, 
                                    `glpi_plugin_manageentities_contractdays`.`name`, 
                                    `glpi_plugin_manageentities_contractdays`.`id`
                           FROM `glpi_plugin_manageentities_cridetails`
                           LEFT JOIN `glpi_tickets` ON (`glpi_tickets`.`id` = `glpi_plugin_manageentities_cridetails`.`tickets_id`)
                           LEFT JOIN `glpi_entities` ON (`glpi_tickets`.`entities_id` = `glpi_entities`.`id`)
                           LEFT JOIN `glpi_plugin_manageentities_contractdays` ON (`glpi_plugin_manageentities_contractdays`.`id` = `glpi_plugin_manageentities_cridetails`.`plugin_manageentities_contractdays_id`)
                           WHERE `glpi_tickets`.`is_deleted` = '0' AND `glpi_plugin_manageentities_cridetails`.`documents_id` = 0 AND `glpi_plugin_manageentities_cridetails`.`plugin_manageentities_contractdays_id` != 0
                                 AND `glpi_tickets`.`status` NOT IN (" . CommonITILObject::SOLVED . "," . CommonITILObject::CLOSED . ")
                           ORDER BY `glpi_tickets`.`date` DESC";

               $widget  = PluginMydashboardHelper::getWidgetsFromDBQuery('table', $query);
               $headers = array(__('Opening date'), _n('Client', 'Clients', 1, 'manageentities'), __('Title'), __('Prestation', 'manageentities'));
               $widget->setTabNames($headers);

               $result = $DB->query($query);
               $nb     = $DB->numrows($result);

               $datas = array();
               $i     = 0;
               if ($nb) {
                  while ($data = $DB->fetch_assoc($result)) {


                     $datas[$i]["date"] = Html::convDateTime($data['date']);

                     $datas[$i]["entity"] = $data['entity'];

                     $name_ticket        = "<a href='" . $link_ticket . "?id=" . $data['tickets_id'] . "' target='_blank'>";
                     $name_ticket        .= $data['title'] . "</a>";
                     $datas[$i]["title"] = $name_ticket;

                     $name_contract     = "<a href='" . $link_contract_day . "?id=" . $data['id'] . "' target='_blank'>";
                     $name_contract     .= $data['name'] . "</a>";
                     $datas[$i]["name"] = $name_contract;

                     $i++;
                  }

               }

               $widget->setTabDatas($datas);
               $widget->setOption("bSort", false);
               $widget->toggleWidgetRefresh();

            } else {
               $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
            }

            $widget->setWidgetTitle(__("Tickets without CRI", "manageentities"));

            return $widget;
            break;
         case $this->getType() . "4":

            $year  = date("Y");
            $month = date('m', mktime(12, 0, 0, date("m"), 0, date("Y")));
            $date  = $year . "-" . $month . "-01";

            $link_contract_day = Toolbox::getItemTypeFormURL("PluginManageentitiesContractDay");
            $link_ticket       = Toolbox::getItemTypeFormURL("Ticket");

            $query = PluginManageentitiesContractDay::queryOldContractDaywithInterventions($date);

            $widget  = PluginMydashboardHelper::getWidgetsFromDBQuery('table', $query);
            $headers = array(__('Creation date'), _n('Client', 'Clients', 1, 'manageentities'), __('Ticket'), __('Prestation', 'manageentities'), __('End date'));
            $widget->setTabNames($headers);

            $result = $DB->query($query);
            $nb     = $DB->numrows($result);

            $datas = array();
            $i     = 0;
            if ($nb) {
               while ($data = $DB->fetch_assoc($result)) {


                  $datas[$i]["date"] = Html::convDateTime($data['cridetails_date']);

                  $datas[$i]["entity"] = $data['entities_name'];

                  $name_ticket               = "<a href='" . $link_ticket . "?id=" . $data['tickets_id'] . "' target='_blank'>";
                  $name_ticket               .= $data['tickets_name'] . "</a>";
                  $datas[$i]["tickets_name"] = $name_ticket;

                  $name_contract     = "<a href='" . $link_contract_day . "?id=" . $data['id'] . "' target='_blank'>";
                  $name_contract     .= $data['name'] . "</a>";
                  $datas[$i]["name"] = $name_contract;

                  $datas[$i]["end_date"] = Html::convDateTime($data['end_date']);

                  $i++;
               }
            }

            $widget->setTabDatas($datas);
            $widget->setOption("bSort", false);
            $widget->toggleWidgetRefresh();
            $widget->setWidgetTitle(__("Interventions with old contract", "manageentities"));

            return $widget;
            break;
         case $this->getType() . "5":

            $plugin = new Plugin();
            $widget = new PluginMydashboardHtml();
            if ($plugin->isActivated("manageentities")) {
               $link_contract     = Toolbox::getItemTypeFormURL("Contract");
               $link_contract_day = Toolbox::getItemTypeFormURL("PluginManageentitiesContractDay");
               $entity            = new Entity();
               $contracts         = PluginManageentitiesFollowUp::queryFollowUp($_SESSION['glpiactiveentities'], array());
               $datas             = array();
               if (!empty($contracts)) {
                  foreach ($contracts as $key => $contract_data) {
                     if (is_integer($key)) {

                        if (!is_null($contract_data['contract_begin_date'])
                            && $contract_data['show_on_global_gantt'] > 0) {

                           foreach ($contract_data['days'] as $key => $days) {
                              if ($days['contract_is_closed']) {
                                 unset($contract_data['days'][$key]);
                              }
                              if ($days['reste'] > 0) {
                                 unset($contract_data['days'][$key]);
                              }
                           }

                           if (!empty($contract_data['days'])) {
                              $data = array();
                              foreach ($contract_data['days'] as $day_data) {

                                 $entity->getFromDB($contract_data['entities_id']);
                                 $data["parent"] = getTreeLeafValueName("glpi_entities", $entity->fields['entities_id']);

                                 $data["entities_id"] = $contract_data['entities_name'];

                                 $name_contract        = "<a href='" . $link_contract . "?id=" . $contract_data["contracts_id"] . "' target='_blank'>";
                                 $name_contract        .= $contract_data['name'] . "</a>";
                                 $data["contracts_id"] = $name_contract;

                                 $name_contract_day = "<a href='" . $link_contract_day . "?id=" . $day_data['contractdays_id'] . "' target='_blank'>";
                                 $name_contract_day .= $day_data['contractdayname'] . "</a>";
                                 $data["days"]      = $name_contract_day;

                                 $data["total"] = $day_data['credit'];
                                 $data["reste"] = $day_data['reste'];
                                 $datas[]       = $data;
                              }
                           }
                        }
                     }
                  }
               }

               $headers = array(__('Team', 'manageentities'), __('Entity'), __('Contract'), __('Prestation', 'manageentities'), __('Total'), __('Total remaining', 'manageentities'));

               $widget = new PluginMydashboardDatatable();

               $widget->setTabNames($headers);
               $widget->setTabDatas($datas);
               $widget->toggleWidgetRefresh();


            } else {
               $widget->setWidgetHtmlContent(__('Plugin is not activated', 'manageentities'));
            }

            $widget->setWidgetTitle(__("Opened contract prestations without remaining days", "manageentities"));

            return $widget;
            break;
      }
   }

}