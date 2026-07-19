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

use Glpi\Event;
use GlpiPlugin\Manageentities\Cri;
use GlpiPlugin\Manageentities\CriDetail;

Session::checkLoginUser();
if (!isset($_POST["cri"])) $_POST["cri"] = "";
if (!isset($_GET["action"])) $_GET["action"] = "";

Html::popHeader(__('Generation of the intervention report', 'manageentities'));

$Cri           = new Cri();
$criDetail                         = new CriDetail();

if (isset($_POST["addcridetail"])) {
   if ($Cri->canCreate()) {
      $criDetail->add($_POST);
   }
   if(strpos($_SERVER['HTTP_REFERER'] ?? '', "generatecri.form.php") > 0){
      Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR."/front/generatecri.form.php?download=1&tickets_id=".(int)$_POST['tickets_id']);
   } else{
      Html::back();
   }

} else if (isset($_POST["updatecridetail"])) {
   if ($Cri->canCreate()) {
      if (isset($_POST['withcontract']) && !$_POST['withcontract']) {
         $_POST['contracts_id']                          = 0;
         $_POST['plugin_manageentities_contractdays_id'] = 0;
      }
      $criDetail->update($_POST);
   }
   Html::back();

} else if (isset($_POST["delcridetail"])) {
   if ($Cri->canCreate()) {
      $criDetail->delete($_POST);
   }
   Html::back();

} else if (isset($_POST["purgedoc"])) {
   $doc            = new Document();
   $documents_id   = (int) $_POST['documents_id'];
   // This branch permanently purges a core Document. checkLoginUser() is not
   // authorization on GLPI 11 and delete() enforces no right by itself, so gate on
   // the object: can(PURGE) checks the core document right, entity access and
   // existence before destroying anything. Without it any logged-in user could
   // wipe arbitrary documents by iterating ids.
   if ($doc->can($documents_id, PURGE)) {
      $input['id'] = $documents_id;
      if ($doc->delete($input, 1)) {
         Event::log($input['id'], "documents", 4, "document", $_SESSION["glpiname"] . " " . __('Delete permanently'));
      }
   }
   Html::back();

}

else {
    $Cri->showForm($_GET["job"], ['action' => $_GET["action"]]);
}

Html::popFooter();
