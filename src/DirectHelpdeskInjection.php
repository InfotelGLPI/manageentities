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

namespace GlpiPlugin\Manageentities;

use PluginDatainjectionCommonInjectionLib;
use PluginDatainjectionInjectionInterface;
use Search;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * Class DirectHelpdeskInjection
 */
class DirectHelpdeskInjection extends DirectHelpdesk
    implements PluginDatainjectionInjectionInterface
{

    public static function getTable($classname = null)
    {
        return DirectHelpdesk::getTable();
    }

    /**
     * @return bool
     */
    public function isPrimaryType()
    {
        return true;
    }

    /**
     * @return array
     */
    public function connectedTo()
    {
        return [];
    }

    /**
     * @param string $primary_type
     * @return array|the
     */
    public function getOptions($primary_type = '')
    {
        $tab = Search::getOptions(get_parent_class($this));

        $tab[4]['checktype'] = 'date';

        //$blacklist = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions();
        //Remove some options because some fields cannot be imported
        $notimportable = [30, 80];
        $options['ignore_fields'] = $notimportable;

        $options['displaytype'] = [
            "timestamp" => [9],
            "user" => [10],
            "multiline_text" => [8],
            "date" => [4],
            "bool" => [11]
        ];

        $tab = PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);

        return $tab;
    }


    /**
     * Standard method to add an object into glpi
     * WILL BE INTEGRATED INTO THE CORE IN 0.80
     * @param array|fields $values
     * @param array|options $options
     * @return an array of IDs of newly created objects : for example array(Computer=>1, Networkport=>10)
     * @internal param fields $values to add into glpi
     * @internal param options $options used during creation
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        $lib = new PluginDatainjectionCommonInjectionLib($this, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }

}
