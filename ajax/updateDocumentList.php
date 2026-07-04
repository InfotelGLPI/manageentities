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

use GlpiPlugin\Manageentities\WizardController;

header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkCentralAccess();

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

global $DB;

$session      = WizardController::getSession();
$contracts_id = (int)($session['contracts_id'] ?? 0);
$root_manage  = PLUGIN_MANAGEENTITIES_WEBDIR;

$val  = "<tr class='tab_bg_1' id='tr_add_contract' style='display:table-row;'>";
$val .= "<td>" . __("Add a document") . "</td>";
$val .= "<td colspan='5'>";
$val .= "<a onclick=\"showFormAddPDFContract('{$root_manage}', '"
    . __("Add a document", "manageentities") . "','"
    . _sx('button', 'Add') . "','"
    . _sx('button', 'Cancel') . "');\" class='pointer'>";
$val .= "<i class=\"ti ti-square-plus\" style=\"font-size:2em;\"></i></a>";
$val .= "</td></tr>";

if ($contracts_id > 0) {
    $docs = $DB->request([
        'SELECT'    => [
            'doc.id',
            'doc.name',
            'doc.filename',
            'doc.mime',
            'doc.date_creation',
            'dc.name AS category',
        ],
        'FROM'      => 'glpi_documents AS doc',
        'LEFT JOIN' => [
            'glpi_documents_items AS di' => [
                'ON' => [
                    'di' => 'documents_id',
                    'doc' => 'id',
                ],
            ],
            'glpi_documentcategories AS dc' => [
                'ON' => [
                    'dc' => 'id',
                    'doc' => 'documentcategories_id',
                ],
            ],
        ],
        'WHERE'     => [
            'di.itemtype' => \Contract::class,
            'di.items_id' => $contracts_id,
            'doc.is_deleted' => 0,
        ],
        'ORDER'     => 'doc.date_creation DESC',
    ]);

    foreach ($docs as $row) {
        $doc_url = \Document::getFormURLWithID($row['id']);
        $val .= "<tr class='tab_bg_1'>";
        $val .= "<td><a href='{$doc_url}'>" . htmlspecialchars($row['name']) . "</a></td>";
        $val .= "<td>" . htmlspecialchars($row['filename']) . "</td>";
        $val .= "<td>" . htmlspecialchars($row['category'] ?? '') . "</td>";
        $val .= "<td>" . Html::convDate($row['date_creation']) . "</td>";
        $val .= "<td></td><td></td>";
        $val .= "</tr>";
    }
}

echo $val;
