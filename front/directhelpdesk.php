<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Servicecatalog\Main;

Session::checkLoginUser();

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
    if (namecheck == 'checkbox3') {
      var param = document.getElementById('checkbox3').checked ? '1' : '0';
      window.location.href = '?checkbox3=' + param;
    }
    if (namecheck == 'checkbox2') {
      var param = document.getElementById('checkbox2').checked ? '1' : '0';
      window.location.href = '?checkbox2=' + param;
    }
    }");

if (!isset($_GET['checkbox3'])) {
    $_GET['checkbox3'] = 1;
}

$checkbox2State = isset($_GET['checkbox2']) ? $_GET['checkbox2'] : '0';
$checkbox3State = isset($_GET['checkbox3']) ? $_GET['checkbox3'] : '0';

echo "<div class='center' style='margin-top: 10px;margin-bottom: 20px;'>";
echo "<form>";

echo "<label>";
$checked2 = $checkbox2State === '1' ? 'checked' : '';
echo "<input type='checkbox' id='checkbox2' onclick='reloadPageWithParam(\"checkbox2\")' $checked2 >";
echo __("2 hours minimum", "manageentities");
echo "</label>";


echo " <label>";
$checked3 = $checkbox3State === '1' ? 'checked' : '';
echo "<input type='checkbox' id='checkbox3' onclick='reloadPageWithParam(\"checkbox3\")' $checked3 >";
echo __("3 hours minimum", "manageentities");
echo "</label>";
echo "</form>";
echo "</div>";

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
