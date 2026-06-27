<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

use GlpiPlugin\Manageentities\Config;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();

if (!defined('GLPI_ROOT')) {
   die("Can not acces directly to this file");
}

Session::checkLoginUser();
Session::checkRight("plugin_manageentities", READ);

if (isset($_POST["action"])) {
   switch ($_POST["action"]) {
      case 'title_show_hourorday' :
         $config = Config::getInstance();
         switch ($_POST["hourorday"]) {
            case Config::DAY :
               echo __('Number of hours by day', 'manageentities');

               break;
            case Config::HOUR :
               echo __('Only ticket accepted are taking into account for consumption calculation', 'manageentities');

               break;
         }
         break;
      case 'value_show_hourorday' :
         $config = Config::getInstance();
         switch ($_POST["hourorday"]) {
            case Config::DAY :
               echo Html::input('hourbyday', ['value' => $config->fields["hourbyday"], 'size' => 5]);
               echo Html::hidden('needvalidationforcri', ['value' => 0]);
               break;
            case Config::HOUR :
               Dropdown::showYesNo("needvalidationforcri", $config->fields["needvalidationforcri"]);
               echo Html::hidden('hourbyday', ['value' => 0]);
               break;
         }
         break;
   }
}
