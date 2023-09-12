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

include("../../../inc/includes.php");
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

if (!defined('GLPI_ROOT')) {
   die("Can not acces directly to this file");
}

if (isset($_POST["action"])) {
   switch ($_POST["action"]) {
      case 'title_show_hourorday' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {
            case PluginManageentitiesConfig::DAY :
               echo __('Number of hours by day', 'manageentities');

               break;
            case PluginManageentitiesConfig::HOUR :
               echo __('Only ticket accepted are taking into account for consumption calculation', 'manageentities');

               break;
            case PluginManageentitiesConfig::POINTS :
               echo __('Day to generate intervention report', 'manageentities');

               break;
         }
         break;
      case 'title_show_logo' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               echo __('Logo to show in report', 'manageentities');

               break;
         }
         break;
      case 'title_show_footer' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               echo __('Text in report footer', 'manageentities');

               break;
         }
         break;
      case 'title_category_outOfContract' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               echo __('Category for Task out of contract', 'manageentities');

               break;
         }
         break;
      case 'title_email_billing_destination' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               echo __('Email destination for Out ouf contract billing', 'manageentities');

               break;
         }
         break;
      case 'value_show_hourorday' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {
            case PluginManageentitiesConfig::DAY :
               Html::autocompletionTextField($config, "hourbyday", ['size' => "5"]);
               echo "<input type='hidden' name='needvalidationforcri' value='0'>";

               break;
            case PluginManageentitiesConfig::HOUR :
               Dropdown::showYesNo("needvalidationforcri", $config->fields["needvalidationforcri"]);
               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
            case PluginManageentitiesConfig::POINTS :
               Dropdown::showNumber("date_to_generate_contract", ['value' => $config->fields["date_to_generate_contract"],'min' => 1,'max' => 31]);
//               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
         }
         break;
      case 'value_show_logo' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               Html::file(['name' => 'picture_logo','value' => $config->fields["picture_logo"],'onlyimages' => true]);
//               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
         }
         break;
      case 'value_show_footer' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               Html::textarea(['name' => 'footer','value' => $config->fields["footer"]]);
//               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
         }
         break;
      case 'value_category_outOfContract' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
               TaskCategory::dropdown(['name' => 'category_outOfContract','value' => $config->fields["category_outOfContract"]]);
//               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
         }
         break;
      case 'value_email_billing_destination' :
         $config = PluginManageentitiesConfig::getInstance();
         switch ($_POST["hourorday"]) {

            case PluginManageentitiesConfig::POINTS :
              echo Html::input('email_billing_destination',['value' => $config->fields['email_billing_destination']]);
//               echo "<input type='hidden' name='hourbyday' value='0'>";

               break;
         }
         break;
   }
}
