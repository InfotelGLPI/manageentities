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

use GlpiPlugin\Manageentities\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End date of a contract in the contracts tab of the clients management
 */
class ContractEndDateTest extends TestCase
{
    public static function endDateProvider(): iterable
    {
        yield 'a year' => ['2026-01-01', 12, '2026-12-31'];
        yield 'datetime start' => ['2025-03-15 00:00:00', 6, '2025-09-14'];
        yield 'no duration' => ['2026-01-01', 0, ''];
        yield 'no start' => [null, 12, ''];
        yield 'empty start' => ['', 12, ''];
    }

    #[DataProvider('endDateProvider')]
    public function testEndDateIsTheStartPlusTheDurationMinusADay(?string $begin, int $duration, string $expected): void
    {
        $this->assertSame($expected, Contract::getEndDate($begin, $duration));
    }
}
