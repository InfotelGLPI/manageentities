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

use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\CriDetail;

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();
Session::checkLoginUser();
// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    Html::displayRightError();
}

$contractdays_id = (int) ($_POST['contractdays_id'] ?? 0);

if ($contractdays_id <= 0) {
    echo json_encode(['remaining' => null]);
    exit;
}

$contractDay = new ContractDay();
if (!$contractDay->getFromDB($contractdays_id)) {
    echo json_encode(['remaining' => null]);
    exit;
}

$contractDay->fields['contractdays_id'] = $contractDay->fields['id'];
$result = CriDetail::getCriDetailData($contractDay->fields);
$remaining = $result['resultOther']['reste'];

echo json_encode([
    'remaining'  => $remaining,
    'begin_date' => $contractDay->fields['begin_date'] ?? '',
    'end_date'   => $contractDay->fields['end_date']   ?? '',
    'comment'    => $contractDay->fields['comment']    ?? '',
]);
