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
