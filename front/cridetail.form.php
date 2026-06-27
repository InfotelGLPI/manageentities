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

use GlpiPlugin\Servicecatalog\Main;
use GlpiPlugin\Manageentities\Entity;

Session::checkLoginUser();

if (!isset($_GET["id"])) $_GET["id"] = 0;
if (!isset($_GET["users_id"])) {
   $users_id = Session::getLoginUserID();
} else {
   $users_id = $_GET["users_id"];
}

$cri = new TicketTask();

$cri->checkGlobal(READ);

if (Session::getCurrentInterface() == 'central') {
   Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);
} else {
   if (Plugin::isPluginActive('servicecatalog')) {
      Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
   } else {
      Html::helpHeader(__('Entities portal', 'manageentities'));
   }
}

$cri->display($_GET);

if (Session::getCurrentInterface() != 'central'
    && Plugin::isPluginActive('servicecatalog')) {

   Main::showNavBarFooter('manageentities');
}

if (Session::getCurrentInterface() == 'central') {
   Html::footer();
} else {
   Html::helpFooter();
}
