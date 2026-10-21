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

use GlpiPlugin\Manageentities\CustomerSummary;
use PHPUnit\Framework\TestCase;

/**
 * Badges of the summary card of the simplified interface
 */
class CustomerSummaryTest extends TestCase
{
    public function testContractIsRedBelowHalfADay(): void
    {
        $this->assertSame('red', CustomerSummary::getContractColor(0.49));
        $this->assertSame('red', CustomerSummary::getContractColor(-2));
        $this->assertSame('green', CustomerSummary::getContractColor(0.5));
        $this->assertSame('red', CustomerSummary::getContractColor(null));
    }

    public function testSubscriptionIsRedOnceExpired(): void
    {
        $this->assertSame('red', CustomerSummary::getSubscriptionColor('2026-10-17 00:00:00', '2026-10-18'));
        $this->assertSame('green', CustomerSummary::getSubscriptionColor('2026-10-18', '2026-10-18'));
        $this->assertSame('green', CustomerSummary::getSubscriptionColor('', '2026-10-18'));
        $this->assertSame('red', CustomerSummary::getSubscriptionColor(null, '2026-10-18'));
    }

    public function testUnbilledIsRedBeyondThreeHours(): void
    {
        $this->assertSame('green', CustomerSummary::getUnbilledColor(0));
        $this->assertSame('orange', CustomerSummary::getUnbilledColor(3 * 3600));
        $this->assertSame('red', CustomerSummary::getUnbilledColor(3 * 3600 + 60));
    }

    public function testUnbilledTimeInManDays(): void
    {
        $this->assertSame(0.5, CustomerSummary::toManDays(4 * 3600, 8));
        $this->assertSame(1.38, CustomerSummary::toManDays(11 * 3600, 8));
        // Hours of a day not configured: no division by zero
        $this->assertSame(0.0, CustomerSummary::toManDays(3600, 0));
    }
}
