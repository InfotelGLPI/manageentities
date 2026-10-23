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

namespace GlpiPlugin\Manageentities\Tests\Unit;

use GlpiPlugin\Manageentities\Preference;
use PHPUnit\Framework\TestCase;

/**
 * Order of the tabs of the clients management chosen in the preferences
 */
class PreferenceTabsOrderTest extends TestCase
{
    private const TABS = [1 => 'Follow-up', 2 => 'Monthly', 5 => 'Contracts', 15 => 'Tickets'];

    public function testTabsFollowTheOrderOfTheUser(): void
    {
        $this->assertSame([15, 1, 5, 2], array_keys(Preference::sortDashboardTabs(self::TABS, [15, 1, 5, 2])));
        // Values are kept with their tab
        $this->assertSame('Tickets', Preference::sortDashboardTabs(self::TABS, [15])[15]);
    }

    public function testNoOrderKeepsTheDefaultOne(): void
    {
        $this->assertSame([1, 2, 5, 15], array_keys(Preference::sortDashboardTabs(self::TABS, [])));
    }

    public function testTabsUnknownToTheOrderComeAfterInTheirDefaultOrder(): void
    {
        // Tab 15 added by a later version, tab 99 removed or hidden since
        $this->assertSame([5, 1, 2, 15], array_keys(Preference::sortDashboardTabs(self::TABS, [5, 99, 1])));
    }
}
