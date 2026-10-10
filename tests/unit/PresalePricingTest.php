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

use GlpiPlugin\Manageentities\PresalePricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Amounts of the pre-sales proposals: numbers typed in French or English, lines, VAT
 */
class PresalePricingTest extends TestCase
{
    public static function numberProvider(): iterable
    {
        yield 'integer'                  => ['12', 12.0];
        yield 'decimal point'            => ['12.5', 12.5];
        yield 'decimal comma'            => ['12,5', 12.5];
        yield 'regular space thousands'  => ['1 250,50', 1250.5];
        yield 'non-breaking space'       => ["1\u{00a0}250", 1250.0];
        yield 'narrow no-break space'    => ["6\u{202f}300,00", 6300.0];
        yield 'surrounding blanks'       => ['  7 ', 7.0];
        yield 'zero'                     => ['0', 0.0];
        yield 'negative'                 => ['-3', -3.0];
        yield 'empty'                    => ['', null];
        yield 'blank'                    => ['   ', null];
        yield 'text'                     => ['abc', null];
        yield 'two decimal separators'   => ['1,2,3', null];
        yield 'unit'                     => ['12 €', null];
    }

    #[DataProvider('numberProvider')]
    public function testParseNumber(string $text, ?float $expected): void
    {
        $this->assertSame($expected, PresalePricing::parseNumber($text));
    }

    public static function vatRateProvider(): iterable
    {
        yield 'empty is 0'     => ['', 0.0];
        yield 'zero'           => ['0', 0.0];
        yield 'french decimal' => ['5,5', 5.5];
        yield 'rounded'        => ['19.999', 20.0];
        yield 'max'            => ['100', 100.0];
        yield 'above 100'      => ['100,01', null];
        yield 'negative'       => ['-1', null];
        yield 'not a number'   => ['twenty', null];
    }

    #[DataProvider('vatRateProvider')]
    public function testParseVatRate(string $text, ?float $expected): void
    {
        $this->assertSame($expected, PresalePricing::parseVatRate($text));
    }

    public function testModeFollowsTheAnnexModel(): void
    {
        $this->assertSame(PresalePricing::MODE_DAYS, PresalePricing::getMode(false));
        // A regular annex stays in days even when flagged cloud
        $this->assertSame(PresalePricing::MODE_DAYS, PresalePricing::getMode(false, true));
        $this->assertSame(PresalePricing::MODE_FLAT, PresalePricing::getMode(true));
        $this->assertSame(PresalePricing::MODE_CLOUD, PresalePricing::getMode(true, true));
    }

    public function testMonthsOnlyCountForCloudLines(): void
    {
        $this->assertSame(1.0, PresalePricing::months(['months' => '24'], PresalePricing::MODE_DAYS));
        $this->assertSame(1.0, PresalePricing::months(['months' => '24'], PresalePricing::MODE_FLAT));
        $this->assertSame(24.0, PresalePricing::months(['months' => '24'], PresalePricing::MODE_CLOUD));
        $this->assertSame(
            (float) PresalePricing::DEFAULT_MONTHS,
            PresalePricing::months(['months' => ''], PresalePricing::MODE_CLOUD),
        );
        $this->assertSame((float) PresalePricing::DEFAULT_MONTHS, PresalePricing::months([], PresalePricing::MODE_CLOUD));
    }

    public function testLineAmounts(): void
    {
        $this->assertSame(
            ['ht' => 3750.0, 'vat' => 750.0, 'ttc' => 4500.0],
            PresalePricing::lineAmounts(5, 750, 20),
        );
    }

    public function testLineAmountsWithoutVat(): void
    {
        $this->assertSame(
            ['ht' => 1500.0, 'vat' => 0.0, 'ttc' => 1500.0],
            PresalePricing::lineAmounts(2, 750, 0),
        );
    }

    public function testCloudLineIsMultipliedByTheMonths(): void
    {
        // 21 € per subscription per month × 25 subscriptions × 12 months
        $this->assertSame(
            ['ht' => 6300.0, 'vat' => 1260.0, 'ttc' => 7560.0],
            PresalePricing::lineAmounts(25, 21, 20, 12),
        );
    }

    public function testLineAmountsAreRoundedToTheCent(): void
    {
        // 3 × 33.333 = 99.999 → 100.00; VAT 5.5 % of 100.00 = 5.50
        $this->assertSame(
            ['ht' => 100.0, 'vat' => 5.5, 'ttc' => 105.5],
            PresalePricing::lineAmounts(3, 33.333, 5.5),
        );
    }

    public function testAnnexVatIsTheSumOfTheVatOfTheLines(): void
    {
        // Each line: 0.03 HT, VAT 20 % = 0.006 → 0.01 per line, 0.03 in all. The VAT of the
        // total (0.09 × 20 % = 0.018 → 0.02) would differ.
        $lines = [
            ['quantity' => '1', 'unit_price' => '0,03'],
            ['quantity' => '1', 'unit_price' => '0,03'],
            ['quantity' => '1', 'unit_price' => '0,03'],
        ];

        $this->assertSame(
            ['ht' => 0.09, 'vat' => 0.03, 'ttc' => 0.12],
            PresalePricing::annexTotals($lines, 20),
        );
    }

    public function testAnnexTotalsParseTheTypedNumbers(): void
    {
        $lines = [
            ['quantity' => '2,5', 'unit_price' => '1 000'],
            ['quantity' => '1', 'unit_price' => '500.50'],
            // Left empty in a default line: counts for 0
            ['quantity' => '', 'unit_price' => ''],
        ];

        $this->assertSame(
            ['ht' => 3000.5, 'vat' => 600.1, 'ttc' => 3600.6],
            PresalePricing::annexTotals($lines, 20),
        );
    }

    public function testCloudAnnexTotalsUseTheMonthsOfEachLine(): void
    {
        $lines = [
            ['quantity' => '25', 'unit_price' => '21', 'months' => '12'],
            // No months typed: a year
            ['quantity' => '10', 'unit_price' => '10', 'months' => ''],
        ];

        $this->assertSame(
            ['ht' => 7500.0, 'vat' => 1500.0, 'ttc' => 9000.0],
            PresalePricing::annexTotals($lines, 20, PresalePricing::MODE_CLOUD),
        );
    }

    public function testValidateLinesDropsEmptyLinesAndKeepsTheTypedValues(): void
    {
        $result = PresalePricing::validateLines([
            ['designation' => ' Audit ', 'quantity' => '2', 'unit_price' => '750'],
            ['designation' => '', 'quantity' => '', 'unit_price' => ''],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            [['designation' => 'Audit', 'quantity' => '2', 'unit_price' => '750', 'months' => '']],
            $result['lines'],
        );
    }

    public function testValidateLinesIgnoresAPostThatIsNotAList(): void
    {
        $this->assertSame(['lines' => [], 'errors' => []], PresalePricing::validateLines('nope'));
    }

    public static function invalidLineProvider(): iterable
    {
        yield 'no designation' => [
            ['designation' => '', 'quantity' => '1', 'unit_price' => '1'],
            PresalePricing::MODE_DAYS,
            'the designation is mandatory',
        ];
        yield 'zero days' => [
            ['designation' => 'Audit', 'quantity' => '0', 'unit_price' => '750'],
            PresalePricing::MODE_DAYS,
            'the number of days must be greater than 0',
        ];
        yield 'zero quantity, flat' => [
            ['designation' => 'Licence', 'quantity' => '0', 'unit_price' => '750'],
            PresalePricing::MODE_FLAT,
            'the quantity must be greater than 0',
        ];
        yield 'no subscription, cloud' => [
            ['designation' => 'Cloud', 'quantity' => 'x', 'unit_price' => '21', 'months' => '12'],
            PresalePricing::MODE_CLOUD,
            'the number of subscriptions must be greater than 0',
        ];
        yield 'negative daily rate' => [
            ['designation' => 'Audit', 'quantity' => '1', 'unit_price' => '-1'],
            PresalePricing::MODE_DAYS,
            'the daily rate must be a positive amount',
        ];
        yield 'price not a number, flat' => [
            ['designation' => 'Licence', 'quantity' => '1', 'unit_price' => 'free'],
            PresalePricing::MODE_FLAT,
            'the price must be a positive amount',
        ];
        yield 'zero months, cloud' => [
            ['designation' => 'Cloud', 'quantity' => '25', 'unit_price' => '21', 'months' => '0'],
            PresalePricing::MODE_CLOUD,
            'the number of months must be greater than 0',
        ];
    }

    #[DataProvider('invalidLineProvider')]
    public function testValidateLinesReportsTheFirstErrorOfALine(array $line, string $mode, string $expected): void
    {
        $result = PresalePricing::validateLines([$line], $mode);

        $this->assertSame([0 => $expected], $result['errors']);
        // Kept for the redisplay
        $this->assertCount(1, $result['lines']);
    }

    public function testValidateLinesAcceptsAFreeLine(): void
    {
        $result = PresalePricing::validateLines([
            ['designation' => 'Gesture', 'quantity' => '1', 'unit_price' => '0'],
        ]);

        $this->assertSame([], $result['errors']);
    }

    public function testValidateLinesKeepsTheMonthsOfCloudLinesOnly(): void
    {
        $line = ['designation' => 'Cloud', 'quantity' => '25', 'unit_price' => '21', 'months' => '24'];

        $this->assertSame('24', PresalePricing::validateLines([$line], PresalePricing::MODE_CLOUD)['lines'][0]['months']);
        $this->assertSame('', PresalePricing::validateLines([$line], PresalePricing::MODE_FLAT)['lines'][0]['months']);
        // Empty months of a cloud line: a year, not an error
        $line['months'] = '';
        $this->assertSame([], PresalePricing::validateLines([$line], PresalePricing::MODE_CLOUD)['errors']);
    }
}
