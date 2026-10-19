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
use RuntimeException;
use Session;
use Toolbox;

/**
 * "Kiflo" tab of the plugin configuration: activation, URL and key of the Kiflo API
 */
class KifloConfig extends CommonGLPI
{
    public static string $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Kiflo', 'manageentities');
    }

    public static function getIcon()
    {
        return KifloLead::getIcon();
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
        $config = Config::getInstance()->fields;
        TemplateRenderer::getInstance()->display('@manageentities/kiflo/config_form.html.twig', [
            'form_url'    => Toolbox::getItemTypeFormURL(Config::class),
            'config'      => $config,
            'default_url' => KifloClient::DEFAULT_URL,
            'has_token'   => ($config['kiflo_token'] ?? '') !== '',
            'leads_count' => count(KifloLead::getLeads(true)),
        ]);
    }

    /**
     * Connection test of the configuration: counts the leads the key can read
     */
    public static function testConnection(): void
    {
        try {
            $count = KifloClient::fromConfig()->countLeads();
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                _n(
                    'Connection to Kiflo succeeded: %d lead available.',
                    'Connection to Kiflo succeeded: %d leads available.',
                    $count,
                    'manageentities',
                ),
                $count,
            )));
        } catch (RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }
    }

    /**
     * Synchronization on demand, from this tab or from the leads tab
     */
    public static function synchronize(): void
    {
        try {
            $count = KifloLead::synchronize();
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                _n('%d Kiflo lead synchronized.', '%d Kiflo leads synchronized.', $count, 'manageentities'),
                $count,
            )));
        } catch (RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        }
    }

    /**
     * Kiflo tab of the configuration
     */
    public static function getConfigUrl(): string
    {
        return Config::getFormURLWithID(1) . '&forcetab=' . urlencode(self::class . '$1');
    }
}
