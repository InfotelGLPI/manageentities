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

namespace GlpiPlugin\Manageentities;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Toolbox;

/**
 * "Pre-sales" tab of the plugin configuration: settings of the section, sales
 * representatives and project managers offered by the wizard, annex models.
 */
class PresaleConfig extends CommonGLPI
{
    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Pre-sales', 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-file-dollar';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate && $item->getType() === Config::class) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Config::class) {
            self::showForm($item);
        }
        return true;
    }

    /**
     * @param CommonGLPI $config
     *
     * @return void
     */
    public static function showForm(CommonGLPI $config): void
    {
        $fields      = Config::getInstance()->fields;
        $entities_id = PresaleUser::getReferenceEntity();

        $users = [];
        $used  = [];
        foreach (array_keys(PresaleUser::getTypes()) as $type) {
            $users[$type] = PresaleUser::getUsers($type);
            $used[$type]  = array_column($users[$type], 'users_id');
        }

        TemplateRenderer::getInstance()->display('@manageentities/config_presale_form.html.twig', [
            'form_url'             => Toolbox::getItemTypeFormURL(Config::class),
            'config'               => $fields,
            'customers_entity'     => $entities_id > 0
                ? \Dropdown::getDropdownName('glpi_entities', $entities_id)
                : '',
            'user_types'           => PresaleUser::getTypes(),
            'users'                => $users,
            'used'                 => $used,
            // The pickers offer the population replayed by PresaleUser::prepareInputForAdd()
            'users_entity'         => $entities_id,
            'annex_models_url'     => PresaleAnnexType::getSearchURL(),
            'import_url'           => PresaleAnnexType::canCreate() ? PresaleAnnexTypeTransfer::getImportUrl() : '',
            'annex_models_count'   => countElementsInTable(PresaleAnnexType::getTable(), ['is_active' => 1]),
            'use_subscriptions'    => Config::useEditorSubscriptions(),
            'templates'            => self::getTemplates(),
        ]);
    }

    /**
     * Word templates shown in the configuration: label, help, uploaded or shipped, download link
     *
     * @return array<string, array{label: string, helper: string, uploaded: bool, url: string}>
     */
    private static function getTemplates(): array
    {
        $helpers   = PresaleTemplate::getHelpers();
        $templates = [];
        foreach (PresaleTemplate::getKinds() as $kind => $label) {
            $templates[$kind] = [
                'label'    => $label,
                'helper'   => $helpers[$kind],
                'uploaded' => PresaleTemplate::isUploaded($kind),
                'url'      => PresaleTemplate::getDownloadUrl($kind),
            ];
        }

        return $templates;
    }
}
