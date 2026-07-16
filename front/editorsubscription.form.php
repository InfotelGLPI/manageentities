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

use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\EditorSubscriptionWizard;
use GlpiPlugin\Manageentities\Entity;

if (!Plugin::isPluginActive('manageentities')
    || !Session::haveRightsOr('plugin_manageentities', [CREATE, UPDATE])) {
    Html::header(__('Setup'), '', 'config', 'plugin');
    echo "<div class='alert alert-warning d-flex'>";
    echo "<b>" . __("You don't have permission to perform this action.") . "</b></div>";
    Html::footer();
    exit;
}

// Publisher subscriptions can be disabled in the plugin configuration
if (!Config::useEditorSubscriptions()) {
    Html::header(__('Publisher subscription', 'manageentities'), '', 'management', Entity::class);
    echo "<div class='alert alert-warning d-flex'>";
    echo "<b>" . __('Publisher subscriptions are disabled in the plugin configuration.', 'manageentities') . "</b></div>";
    Html::footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $result = EditorSubscriptionWizard::saveAndReturn($_POST);
        if ($result['success']) {
            Session::addMessageAfterRedirect(
                __('Publisher subscription saved successfully.', 'manageentities'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                $result['message'] ?? __('An error occurred while saving.', 'manageentities'),
                true,
                ERROR
            );
        }
        Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php');
        exit;
    }

    if ($action === 'delete') {
        if (!Session::haveRight('plugin_manageentities', DELETE)) {
            Session::addMessageAfterRedirect(__("You don't have permission to perform this action."), true, ERROR);
            Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php');
            exit;
        }
        $result = EditorSubscriptionWizard::deleteAndReturn($_POST);
        if ($result['success']) {
            Session::addMessageAfterRedirect(
                __('Publisher subscription deleted successfully.', 'manageentities'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                $result['message'] ?? __('An error occurred while deleting.', 'manageentities'),
                true,
                ERROR
            );
        }
        Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php');
        exit;
    }
}

Html::header(__('Publisher subscription', 'manageentities'), '', 'management', Entity::class);
EditorSubscriptionWizard::render();
Html::footer();
