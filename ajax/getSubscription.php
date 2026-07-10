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

use GlpiPlugin\Manageentities\EditorSubscription;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();
Session::checkLoginUser();
// Authorization: plugin access or ticket-creation rights (shared by admin pages and the CRI generation page)
if (!Session::haveRight('plugin_manageentities', READ) && !Session::haveRight('ticket', CREATE)) {
    Html::displayRightError();
}

$entities_id = (int)($_POST['entities_id'] ?? 0);

if ($entities_id <= 0) {
    echo json_encode(['found' => false]);
    exit;
}

$sub = EditorSubscription::getForEntity($entities_id);

if (empty($sub)) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'                     => true,
    'sub_id'                    => (int)$sub['id'],
    'name'                      => $sub['name'] ?? '',
    'customer_account_id'       => $sub['customer_account_id'] ?? '',
    'active_editor_suscription' => (int)($sub['active_editor_suscription'] ?? 0),
    'cloud_client'              => (int)($sub['cloud_client'] ?? 0),
    'internet_publication'      => (int)($sub['internet_publication'] ?? 0),
    'plugin_manageentities_subscriptionlevels_id'     => (int)($sub['plugin_manageentities_subscriptionlevels_id'] ?? 0),
    'begin_date'                => $sub['begin_date'] ? substr($sub['begin_date'], 0, 10) : '',
    'end_date'                  => $sub['end_date']   ? substr($sub['end_date'],   0, 10) : '',
    'comment'                   => $sub['comment'] ?? '',
]);
