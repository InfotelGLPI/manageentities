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

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Manageentities\Cri;

Session::checkLoginUser();

if (isset($_GET["file"])) { // for other file
   $splitter = explode("/", $_GET["file"]);

   if (count($splitter) == 3) {
      $send = false;
      if (
         ($splitter[1] == "manageentities")
         && Session::haveRight("plugin_manageentities_cri_create", READ)
      ) {
         $send = GLPI_DOC_DIR . "/" . $_GET["file"];
      }
      $cri = new Cri();
      if ($send && file_exists($send)) {
         $doc                     = new Document();
         $doc->fields['filepath'] = $_GET["file"];
         $doc->fields['mime']     = 'application/pdf';
         $doc->fields['filename'] = $splitter[2];
         $cri->send($doc);
      } else {
          throw new BadRequestHttpException(__('Unauthorized access to this file'), true);
      }

   } else {
       throw new BadRequestHttpException(__('Invalid filename'), true);
   }
}
