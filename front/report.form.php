<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\Entity;

// Enforce the access control BEFORE rendering anything: Html::header()/Report::title()
// used to be emitted first, leaking the page chrome to users without the right.
$Entity = new \Entity();
if (!$Entity->canView() && !Session::haveRight("config", UPDATE)) {
    throw new AccessDeniedHttpException();
}

Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);

if (isset($_GET)) {
    $tab = $_GET;
}
if (empty($tab) && isset($_POST)) {
    $tab = $_POST;
}
if (!isset($_POST["tech_num"]) || empty($_POST["tech_num"])) {
    $owner = Session::getLoginUserID();
} else {
    $owner = $_POST["tech_num"];
}
if (!isset($_GET["usertype"])) {
    $_GET["usertype"] = "user";
}
if (empty($_POST["date1"]) && empty($_POST["date2"])) {
    $lastday = cal_days_in_month(CAL_GREGORIAN, date("m"), date("Y"));
    if (date("d") == $lastday) {
        $_POST["date2"] = date("Y-m-d", mktime(0, 0, 0, date("m"), date("d"), date("Y")));
        $_POST["date1"] = date("Y-m-d", mktime(0, 0, 0, date("m"), 1, date("Y")));
    } else {
        $month          = date("m");
        $lastday        = $month == 1 ? 31 : cal_days_in_month(CAL_GREGORIAN, $month - 1, date("Y"));
        $_POST["date2"] = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, $lastday, date("Y")));
        $_POST["date1"] = date("Y-m-d", mktime(0, 0, 0, date("m") - 1, 1, date("Y")));
    }
}
if ($_POST["date1"] != "" && $_POST["date2"] != "" && strcmp($_POST["date2"], $_POST["date1"]) < 0) {
    $tmp            = $_POST["date1"];
    $_POST["date1"] = $_POST["date2"];
    $_POST["date2"] = $tmp;
}

Report::title();

// The submitted usertype (POST) wins; otherwise fall back to the GET/default value.
$usertype = $_POST["usertype"] ?? ($_GET["usertype"] ?? "user");

// Capture GLPI's own field/dropdown HTML so the Twig template can inject it (|raw).
$date1_field = Html::showDateField("date1", ['value' => $_POST["date1"], 'display' => false]);
$date2_field = Html::showDateField("date2", ['value' => $_POST["date2"], 'display' => false]);

ob_start();
User::dropdown([
    'name'   => "tech_num",
    'value'  => $owner,
    'entity' => $_SESSION["glpiactive_entity"],
    'right'  => 'all',
]);
$tech_dropdown = ob_get_clean();

TemplateRenderer::getInstance()->display('@manageentities/report_search_form.html.twig', [
    'form_url'      => $_SERVER['REQUEST_URI'],
    'date1_field'   => $date1_field,
    'date2_field'   => $date2_field,
    'tech_dropdown' => $tech_dropdown,
    'usertype'      => $usertype,
]);

if (isset($_POST["choice_tech"])) {
    $CriDetail = new CriDetail();
    $CriDetail->showHelpdeskReports($_POST["usertype"], $owner, $_POST["date1"], $_POST["date2"]);
}

Html::footer();
