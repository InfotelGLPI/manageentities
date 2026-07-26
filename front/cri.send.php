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
      $send     = false;
      $filename = $splitter[2];
      // Path traversal hardening: on Windows "\" is also a directory separator, so a
      // segment such as "..\..\..\config\config_db.php" survives the "/" split as a single
      // segment and the raw value used to be concatenated straight into readfile(). Rebuild
      // the path from a sanitized basename, confined under GLPI_DOC_DIR/_plugins/manageentities,
      // and verify it with realpath() before serving.
      if (
         ($splitter[0] === "_plugins")
         && ($splitter[1] === "manageentities")
         && $filename === basename($filename)
         && strpbrk($filename, "/\\") === false
         && strpos($filename, "..") === false
         && Session::haveRight("plugin_manageentities_cri_create", READ)
      ) {
         $base      = GLPI_DOC_DIR . "/_plugins/manageentities";
         $candidate = $base . "/" . $filename;
         $realBase  = realpath($base);
         $real      = realpath($candidate);
         if ($real !== false && $realBase !== false
             && str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            $send = $candidate;
         }
      }
      $cri = new Cri();
      if ($send && file_exists($send)) {
         $doc                     = new Document();
         $doc->fields['filepath'] = "_plugins/manageentities/" . $filename;
         $doc->fields['mime']     = 'application/pdf';
         $doc->fields['filename'] = $filename;
         $cri->send($doc);
      } else {
          throw new BadRequestHttpException(__('Unauthorized access to this file'), true);
      }

   } else {
       throw new BadRequestHttpException(__('Invalid filename'), true);
   }
}
