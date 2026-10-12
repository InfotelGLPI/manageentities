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
 * "DAT" tab of the plugin configuration: Word template and document category of the
 * technical architecture documents
 */
class DatConfig extends CommonGLPI
{
    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('DAT', 'manageentities');
    }

    public static function getIcon()
    {
        return Dat::getIcon();
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
        TemplateRenderer::getInstance()->display('@manageentities/dat/config_form.html.twig', [
            'form_url' => Toolbox::getItemTypeFormURL(Config::class),
            'config'   => Config::getInstance()->fields,
            'template' => [
                'kind'     => PresaleTemplate::DAT,
                'label'    => PresaleTemplate::getAllKinds()[PresaleTemplate::DAT],
                'uploaded' => PresaleTemplate::isUploaded(PresaleTemplate::DAT),
                'url'      => PresaleTemplate::getDownloadUrl(PresaleTemplate::DAT),
            ],
        ]);
    }

    /**
     * DAT tab of the configuration, where the template is uploaded
     */
    public static function getConfigUrl(): string
    {
        return Config::getFormURLWithID(1) . '&forcetab=' . urlencode(self::class . '$1');
    }
}
