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

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\GenerateCRI;
use GlpiPlugin\Servicecatalog\Main;

Session::checkLoginUser();

if (Session::getCurrentInterface() == 'central') {
   Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", GenerateCRI::class);
} else {
   if (Plugin::isPluginActive('servicecatalog')) {
      Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
   } else {
      Html::helpHeader(__('Entities portal', 'manageentities'));
   }
}
if (Session::haveRight("ticket", CREATE)) {
   $generatecri = new GenerateCRI();
   $generatecri->showWizard($ticket = new Ticket(), $_SESSION['glpiactive_entity']);
} else {
    throw new AccessDeniedHttpException();
}

if (Session::getCurrentInterface() != 'central'
    && Plugin::isPluginActive('servicecatalog')) {

   Main::showNavBarFooter('manageentities');
}

if (Session::getCurrentInterface() == 'central') {
   Html::footer();
} else {
   Html::helpFooter();
}
