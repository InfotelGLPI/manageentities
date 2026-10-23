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
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\DatOsTemplate;
use GlpiPlugin\Manageentities\DexOsTemplate;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\KifloConfig;
use GlpiPlugin\Manageentities\PresaleTemplate;
use GlpiPlugin\Manageentities\PresaleUser;

if (Plugin::isPluginActive("manageentities")) {
    // Saving has always required config UPDATE on top of the plugin right: require it to
    // display the form too, instead of showing a form whose submission is then refused.
    if (Config::canManageConfig()) {
        $config = new Config();

        $presale_user = new PresaleUser();

        if (isset($_POST["update_config"])) {
            $config->update($_POST);
            // Word templates of the pre-sales tab, stored in files/_plugins/manageentities/presale
            PresaleTemplate::handleUpload($_FILES, $_POST);
            // Models of the DAT sections, posted by the DAT tab only
            if (isset($_POST['dat_models']) && is_array($_POST['dat_models'])) {
                DatOsTemplate::saveAll($_POST['dat_models']);
            }
            // Models of the DEX sections, posted by the DEX tab only
            if (isset($_POST['dex_models']) && is_array($_POST['dex_models'])) {
                DexOsTemplate::saveAll($_POST['dex_models']);
            }
            Html::back();
        } elseif (isset($_POST["kiflo_test"])) {
            KifloConfig::testConnection();
            Html::back();
        } elseif (isset($_POST["kiflo_sync"])) {
            KifloConfig::synchronize();
            Html::back();
        } elseif (isset($_POST["add_presaleuser"])) {
            $presale_user->add([
                'users_id' => (int) ($_POST['users_id'] ?? 0),
                'type'     => (int) ($_POST['type'] ?? 0),
            ]);
            Html::back();
        } elseif (isset($_POST["update_presaleuser"])) {
            $presale_user->update([
                'id'         => (int) ($_POST['presaleuser_id'] ?? 0),
                'role_label' => $_POST['role_label'] ?? '',
                'phone'      => $_POST['phone'] ?? '',
            ]);
            Html::back();
        } elseif (isset($_POST["default_presaleuser"])) {
            $presale_user->setAsDefault((int) ($_POST['presaleuser_id'] ?? 0));
            Html::back();
        } elseif (isset($_POST["delete_presaleuser"])) {
            $presale_user->delete(['id' => (int) ($_POST['presaleuser_id'] ?? 0)], true);
            Html::back();
        } else {
            Html::header(__('Entities portal', 'manageentities'), '', "management", Entity::class);
            $config->getFromDB(1);
            $config->display(['id' => 1]);
            Html::footer();
        }
    } else {
        throw new AccessDeniedHttpException();
    }
} else {
    Html::header(__s('Setup'), '', "config", "plugin");
    TemplateRenderer::getInstance()->display('@manageentities/plugin_inactive.html.twig', [
        'message' => __('Please activate the plugin', 'manageentities'),
    ]);
    Html::footer();
}
