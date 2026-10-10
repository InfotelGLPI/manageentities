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

use GlpiPlugin\Manageentities\PresaleEstimate;
use PHPUnit\Framework\TestCase;

/**
 * Estimate of an annex in man-days: titles and lines, total, comparison with the priced lines
 */
class PresaleEstimateTest extends TestCase
{
    private static function title(string $label): array
    {
        return ['type' => PresaleEstimate::TYPE_TITLE, 'label' => $label, 'days' => ''];
    }

    private static function line(string $label, string $days): array
    {
        return ['type' => PresaleEstimate::TYPE_LINE, 'label' => $label, 'days' => $days];
    }

    public function testValidateDropsTheRowsWithoutLabel(): void
    {
        $result = PresaleEstimate::validate([
            ['type' => 'title', 'label' => ' Audit ', 'days' => ''],
            ['type' => 'line', 'label' => 'Interviews', 'days' => '2,5'],
            ['type' => 'line', 'label' => '', 'days' => '3'],
            'not a row',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([self::title('Audit'), self::line('Interviews', '2,5')], $result['rows']);
    }

    public function testATitleHasNoDays(): void
    {
        $result = PresaleEstimate::validate([['type' => 'title', 'label' => 'Audit', 'days' => '4']]);

        $this->assertSame([self::title('Audit')], $result['rows']);
    }

    public function testAnUnknownTypeIsALine(): void
    {
        $result = PresaleEstimate::validate([['type' => 'other', 'label' => 'Setup', 'days' => '1']]);

        $this->assertSame([self::line('Setup', '1')], $result['rows']);
    }

    public function testValidateReportsInvalidDays(): void
    {
        $result = PresaleEstimate::validate([
            ['type' => 'line', 'label' => 'Setup', 'days' => 'two'],
            ['type' => 'line', 'label' => 'Tests', 'days' => '-1'],
            // Empty days: allowed, the project manager fills them in later
            ['type' => 'line', 'label' => 'Training', 'days' => ''],
        ]);

        $this->assertSame([
            'Estimate, "Setup": invalid number of days',
            'Estimate, "Tests": invalid number of days',
        ], $result['errors']);
        // Kept for the redisplay
        $this->assertCount(3, $result['rows']);
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        $rows = [self::title('Étude'), self::line('Ateliers', '1,5')];

        $json = PresaleEstimate::encode($rows);

        $this->assertIsString($json);
        // Readable in the database
        $this->assertStringContainsString('Étude', $json);
        $this->assertSame($rows, PresaleEstimate::decode($json));
    }

    public function testAnEmptyEstimateIsStoredAsNull(): void
    {
        $this->assertNull(PresaleEstimate::encode([]));
        $this->assertSame([], PresaleEstimate::decode(null));
        $this->assertSame([], PresaleEstimate::decode(''));
        $this->assertSame([], PresaleEstimate::decode('{broken'));
    }

    public function testTotalCountsTheLinesOnly(): void
    {
        $rows = [
            self::title('Audit'),
            self::line('Interviews', '2,5'),
            self::line('Report', '1.25'),
            self::line('To be estimated', ''),
            self::title('Setup'),
            self::line('Install', '3'),
        ];

        $this->assertSame(6.75, PresaleEstimate::total($rows));
        $this->assertSame('6,75', PresaleEstimate::formatTotal($rows));
    }

    public function testFormatTotalHasNoUselessDecimals(): void
    {
        $this->assertSame('3', PresaleEstimate::formatTotal([self::line('Install', '3,00')]));
        $this->assertSame('0', PresaleEstimate::formatTotal([]));
    }

    public function testPricedDaysSumTheQuantitiesOfTheLines(): void
    {
        $lines = [
            ['quantity' => '2,5'],
            ['quantity' => '4'],
            ['quantity' => ''],
        ];

        $this->assertSame(6.5, PresaleEstimate::pricedDays($lines));
    }
}
