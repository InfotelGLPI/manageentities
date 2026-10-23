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
 * "DEX" tab of the plugin configuration: Word template, document category and models by
 * operating system of the operating documents
 */
class DexConfig extends CommonGLPI
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('DEX', 'manageentities');
    }

    public static function getIcon()
    {
        return Dex::getIcon();
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
            self::showForm();
        }
        return true;
    }

    public static function showForm(): void
    {
        TemplateRenderer::getInstance()->display('@manageentities/dex/config_form.html.twig', [
            'form_url' => Toolbox::getItemTypeFormURL(Config::class),
            'config'   => Config::getInstance()->fields,
            'template' => [
                'kind'     => PresaleTemplate::DEX,
                'label'    => PresaleTemplate::getAllKinds()[PresaleTemplate::DEX],
                'uploaded' => PresaleTemplate::isUploaded(PresaleTemplate::DEX),
                'url'      => PresaleTemplate::getDownloadUrl(PresaleTemplate::DEX),
            ],
            'systems'  => DatOsTemplate::getSystems(),
            'models'   => DexOsTemplate::getAll(),
            'sections' => array_map(
                static fn(string $placeholder): array => [
                    'key'   => $placeholder,
                    'label' => DexWizard::getSectionLabels()[$placeholder],
                    'kind'  => DexWizard::getSectionKind($placeholder),
                ],
                DexOsTemplate::getPlaceholders(),
            ),
        ]);
    }

    /**
     * DEX tab of the configuration, where the template is uploaded
     */
    public static function getConfigUrl(): string
    {
        return Config::getFormURLWithID(1) . '&forcetab=' . urlencode(self::class . '$1');
    }
}
