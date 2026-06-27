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

use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\CriPrice;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
$AJAX_INCLUDE = 1;
Session::checkLoginUser();

if (!isset($_POST['type'])) {
    throw new NotFoundHttpException();
}
if (!isset($_POST['parenttype'])) {
    throw new NotFoundHttpException();
}

$allowed_types = [CriPrice::class, ContractDay::class];
if (!in_array($_POST['type'], $allowed_types, true) || !in_array($_POST['parenttype'], $allowed_types, true)) {
    http_response_code(400);
    return;
}

$dbu = new DbUtils();
if (($item = $dbu->getItemForItemtype($_POST['type']))
    && ($parent = $dbu->getItemForItemtype($_POST['parenttype']))) {
   if (isset($_POST[$parent->getForeignKeyField()])
       && isset($_POST["id"])
       && $parent->getFromDB($_POST[$parent->getForeignKeyField()])) {
      $item->showForm($_POST["id"], ['parent' => $parent]);

   } else {
      echo __('Access denied');
   }
}

