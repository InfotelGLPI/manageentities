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

/**
 * Priced lines of the pre-sales annexes: parsing, validation and totals, as in the proof of
 * concept (AnnexeLines). The VAT rate applies to the whole annex and its amount is the sum of
 * the VAT of each line. A line is always a quantity at a unit price excluding VAT; only the
 * wording changes with the kind of annex:
 * - days: a number of days at a daily rate (the regular annexes);
 * - flat: a quantity at a price (an on premise publisher subscription);
 * - cloud: a number of subscriptions (users) at a monthly price per subscription, over a number
 *   of months (a cloud publisher subscription, priced by its subscription level).
 * The mode is not stored: it follows from the annex model.
 */
class PresalePricing
{
    public const MODE_DAYS  = 'days';
    public const MODE_FLAT  = 'flat';
    public const MODE_CLOUD = 'cloud';

    /** Months of a cloud subscription when none is typed: one year */
    public const DEFAULT_MONTHS = 12;

    public static function getMode(bool $is_subscription, bool $is_cloud = false): string
    {
        if (!$is_subscription) {
            return self::MODE_DAYS;
        }
        return $is_cloud ? self::MODE_CLOUD : self::MODE_FLAT;
    }

    /**
     * Factor of the months of a line: its number of months for a cloud line, 1 otherwise
     *
     * @param array{months?: float|string|null} $line
     */
    public static function months(array $line, string $mode): float
    {
        if ($mode !== self::MODE_CLOUD) {
            return 1.0;
        }
        return (float) (self::parseNumber((string) ($line['months'] ?? '')) ?? self::DEFAULT_MONTHS);
    }

    /**
     * Number typed in French or English: spaces (regular, non-breaking, narrow) are thousands
     * separators, the comma is the decimal one
     *
     * @return float|null null when the text is not a number
     */
    public static function parseNumber(string $text): ?float
    {
        $text = str_replace([' ', "\u{00a0}", "\u{202f}"], '', trim($text));
        $text = str_replace(',', '.', $text);
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        return (float) $text;
    }

    /**
     * Posted lines of an annex, the fully empty ones dropped
     *
     * @param mixed  $posted list of [designation, quantity, unit_price]
     * @param string $mode   self::MODE_*
     *
     * @return array{lines: array<int, array{designation: string, quantity: string, unit_price: string, months: string}>,
     *               errors: array<int, string>}
     *         the lines as typed (kept for the redisplay) and an error message per invalid line
     */
    public static function validateLines($posted, string $mode = self::MODE_DAYS): array
    {
        $lines  = [];
        $errors = [];
        foreach (is_array($posted) ? $posted : [] as $line) {
            $line = [
                'designation' => trim((string) ($line['designation'] ?? '')),
                'quantity'    => trim((string) ($line['quantity'] ?? '')),
                'unit_price'  => trim((string) ($line['unit_price'] ?? '')),
                // Cloud only: the other modes post no months
                'months'      => $mode === self::MODE_CLOUD ? trim((string) ($line['months'] ?? '')) : '',
            ];
            if (implode('', array_diff_key($line, ['months' => true])) === '') {
                continue;
            }
            $idx     = count($lines);
            $lines[] = $line;

            $quantity   = self::parseNumber($line['quantity']);
            $unit_price = self::parseNumber($line['unit_price']);
            if ($line['designation'] === '') {
                $errors[$idx] = __('the designation is mandatory', 'manageentities');
            } elseif ($quantity === null || $quantity <= 0) {
                $errors[$idx] = match ($mode) {
                    self::MODE_DAYS  => __('the number of days must be greater than 0', 'manageentities'),
                    self::MODE_CLOUD => __('the number of subscriptions must be greater than 0', 'manageentities'),
                    default          => __('the quantity must be greater than 0', 'manageentities'),
                };
            } elseif ($unit_price === null || $unit_price < 0) {
                $errors[$idx] = $mode === self::MODE_DAYS
                    ? __('the daily rate must be a positive amount', 'manageentities')
                    : __('the price must be a positive amount', 'manageentities');
            } elseif ($mode === self::MODE_CLOUD && $line['months'] !== ''
                && ((self::parseNumber($line['months']) ?? 0) <= 0)) {
                $errors[$idx] = __('the number of months must be greater than 0', 'manageentities');
            }
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * VAT rate typed for an annex: empty means 0
     *
     * @return float|null null when not a number between 0 and 100
     */
    public static function parseVatRate(string $text): ?float
    {
        if (trim($text) === '') {
            return 0.0;
        }
        $rate = self::parseNumber($text);
        if ($rate === null || $rate < 0 || $rate > 100) {
            return null;
        }
        return round($rate, 2);
    }

    /**
     * Amounts of a line: quantity × unit price, × months for a cloud line
     *
     * @return array{ht: float, vat: float, ttc: float}
     */
    public static function lineAmounts(float $quantity, float $unit_price, float $vat_rate, float $months = 1.0): array
    {
        $ht  = round($quantity * $unit_price * $months, 2);
        $vat = round($ht * $vat_rate / 100, 2);

        return ['ht' => $ht, 'vat' => $vat, 'ttc' => round($ht + $vat, 2)];
    }

    /**
     * Totals of an annex: the VAT is the sum of the VAT of the lines, not the VAT of the total
     *
     * @param array<int, array{quantity: float|string, unit_price: float|string, months?: float|string|null}> $lines
     * @param string                                                                                      $mode self::MODE_*
     *
     * @return array{ht: float, vat: float, ttc: float}
     */
    public static function annexTotals(array $lines, float $vat_rate, string $mode = self::MODE_DAYS): array
    {
        $ht  = 0.0;
        $vat = 0.0;
        foreach ($lines as $line) {
            $amounts = self::lineAmounts(
                (float) (self::parseNumber((string) $line['quantity']) ?? 0),
                (float) (self::parseNumber((string) $line['unit_price']) ?? 0),
                $vat_rate,
                self::months($line, $mode),
            );
            $ht  += $amounts['ht'];
            $vat += $amounts['vat'];
        }

        return ['ht' => round($ht, 2), 'vat' => round($vat, 2), 'ttc' => round($ht + $vat, 2)];
    }
}
