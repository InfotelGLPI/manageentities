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
use GlpiPlugin\Manageentities\Entity;
use Session;

class Servicecatalog extends CommonGLPI
{
    public static $rightname = 'plugin_manageentities';

    public $dohistory = false;

    public static function canUse()
    {
        $Entity = new Entity();
        return $Entity->canView();
    }

    /**
     * @return string
     */
    public static function getMenuLink()
    {
        global $CFG_GLPI;

        return PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php";
    }

    /**
     * @return string
     */
    public static function getNavBarLink()
    {
        global $CFG_GLPI;

        return PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php";
    }

    public static function getMenuLogo()
    {
        return Entity::getIcon();
    }

    public static function getMenuTitle()
    {
        return __('Manage your contracts', 'manageentities');
    }


    public static function getMenuComment()
    {
        return __('Manage your contracts', 'manageentities');
    }

    public static function getLinkList()
    {
        return "";
    }

    public static function getList()
    {
        return "";
    }

    /**
     * Top of the service catalog home page, above its widgets (called by the service catalog
     * through this hook, only when canUse() allows it).
     *
     * On the simplified interface, the summary card of the customer, as the first tab of the
     * clients management shows it: the card alone, without the other tabs.
     */
    public static function showDashboardHeader(): void
    {
        if (Session::getCurrentInterface() !== 'helpdesk') {
            return;
        }

        // Same entity as the summary tab of the simplified interface (Entity::displayTabContentForItem())
        CustomerSummary::show((int) $_SESSION['glpiactive_entity']);
    }
}
