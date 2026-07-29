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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Servicecatalog\Main;

Session::checkLoginUser();
// The dashboard below (aggregated intervention time / billing data) is emitted before
// Search::show() runs its own right check, so enforce the read right up-front — otherwise
// an authenticated user without plugin_manageentities_directhelpdesk could read the
// dashboard before the later 403 (same guard as front/company.php).
Session::checkRight('plugin_manageentities_directhelpdesk', READ);

if (Session::getCurrentInterface() == 'central') {
    Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", DirectHelpdesk::class);
} else {
    if (Plugin::isPluginActive('servicecatalog')) {
        Main::showDefaultHeaderHelpdesk(__('Entities portal', 'manageentities'));
    } else {
        Html::helpHeader(__('Entities portal', 'manageentities'));
    }
}

echo Html::scriptBlock("
    function reloadPageWithParam(namecheck) {
        var params = new URLSearchParams(window.location.search);
        params.set('checkbox2', document.getElementById('checkbox2').checked ? '1' : '0');
        params.set('checkbox3', document.getElementById('checkbox3').checked ? '1' : '0');
        window.location.href = '?' + params.toString();
    }");

if (!isset($_GET['checkbox3'])) {
    $_GET['checkbox3'] = 1;
}

$checkbox2State = $_GET['checkbox2'] ?? '0';
$checkbox3State = $_GET['checkbox3'] ?? '0';

TemplateRenderer::getInstance()->display('@manageentities/directhelpdesk_dashboard_filter.html.twig', [
    'checkbox2' => $checkbox2State === '1',
    'checkbox3' => $checkbox3State === '1',
]);

if ($checkbox3State === '1') {
    $min = DirectHelpdesk::THREE_HOUR;
} else if ($checkbox2State === '1') {
    $min = DirectHelpdesk::TWO_HOUR;
} else {
    $min = 0;
}

DirectHelpdesk::showDashboard($min);

Search::show(DirectHelpdesk::class);

if (Session::getCurrentInterface() != 'central'
    && Plugin::isPluginActive('servicecatalog')) {
    Main::showNavBarFooter('manageentities');
}

if (Session::getCurrentInterface() == 'central') {
    Html::footer();
} else {
    Html::helpFooter();
}
