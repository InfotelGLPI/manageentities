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
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Report;

// Enforce the access control BEFORE rendering anything: Html::header()/Report::title()
// used to be emitted first, leaking the page chrome to users without the right
// (aligned with report.form.php, including the explicit plugin right and the deliberate
// "config" UPDATE fallback).
if (!Session::haveRight(Entity::$rightname, READ) && !Session::haveRight("config", UPDATE)) {
    throw new AccessDeniedHttpException();
}

Html::header(__('Entities portal', 'manageentities'), '', "plugins", "manageentities");

if (isset($_GET)) {
    $tab = $_GET;
}
if (empty($tab) && isset($_POST)) {
    $tab = $_POST;
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
\Report::title();

// Technician list restricted to users of the caller's active entities. Report replays these
// same criteria on the posted ids, so the two cannot drift apart.
$techs = Report::getSelectableTechnicians();

TemplateRenderer::getInstance()->display('@manageentities/report_occupation_form.html.twig', [
    'form_url'       => $_SERVER['REQUEST_URI'],
    'date1'          => $_POST["date1"],
    'date2'          => $_POST["date2"],
    'techs'          => $techs,
    'techs_selected' => $_POST['techs'] ?? [],
]);

if (isset($_POST["send"]) && isset($_POST['techs'])) {
    $report = new Report();
    $report->showOccupationReports($_POST['techs'], $_POST["date1"], $_POST["date2"]);
}

Html::footer();
