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
use Glpi\Exception\Http\AccessDeniedHttpException;
use Session;

/**
 * "Customer sheet" tab on the core entity: administrative data, logo, contacts,
 * business contacts and tech leads of one client, i.e. the "Data administrative"
 * tab of the portal, reachable from the entity form itself.
 */
class CustomerSheet extends CommonGLPI
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('Customer sheet', 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-id';
    }

    /**
     * Whether the tab is offered for this entity
     *
     * @param \Entity $item
     *
     * @return bool
     */
    private static function isAvailableFor(\Entity $item): bool
    {
        return !$item->isNewItem()
            && Session::getCurrentInterface() === 'central'
            && Session::haveRight(self::$rightname, READ)
            && Session::haveAccessToEntity($item->getID());
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof \Entity || !self::isAvailableFor($item)) {
            return '';
        }
        $nb = 0;
        if ($_SESSION['glpishow_count_on_tabs']) {
            $nb = countElementsInTable(TechLead::getTable(), ['entities_id' => $item->getID()]);
        }
        return self::createTabEntry(self::getTypeName(), $nb, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // displayStandardTab() does not check that the tab was offered: replay the same guard
        if (!$item instanceof \Entity || !self::isAvailableFor($item)) {
            throw new AccessDeniedHttpException();
        }
        (new Entity())->showDescription([$item->getID()]);

        return true;
    }
}
