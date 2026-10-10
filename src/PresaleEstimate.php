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
 * Estimate of a regular annex: rows of a table printed under the purpose of the annex, each
 * one either a section title (spanning the two columns) or a line with a description and a
 * load in man-days, then the total of the days. It describes the work: the priced lines of the
 * annex stay apart.
 *
 * Stored as JSON (annex models: default_estimate, proposal annexes: estimate):
 * [{type: "title"|"line", label: string, days: string}]
 */
class PresaleEstimate
{
    public const TYPE_TITLE = 'title';
    public const TYPE_LINE  = 'line';

    /**
     * Rows of a stored estimate
     *
     * @return list<array{type: string, label: string, days: string}>
     */
    public static function decode(?string $json): array
    {
        $rows = json_decode((string) $json, true);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = self::row($row);
            }
        }

        return $result;
    }

    /**
     * Rows posted by a form, the empty ones dropped, the loads checked
     *
     * @param mixed $posted list of [type, label, days]
     *
     * @return array{rows: list<array{type: string, label: string, days: string}>, errors: list<string>}
     */
    public static function validate($posted): array
    {
        $rows   = [];
        $errors = [];
        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = self::row($row);
            if ($row['label'] === '') {
                continue;
            }
            if ($row['type'] === self::TYPE_TITLE) {
                $row['days'] = '';
            } elseif ($row['days'] !== '') {
                $days = PresalePricing::parseNumber($row['days']);
                if ($days === null || $days < 0) {
                    $errors[] = sprintf(__('Estimate, "%s": invalid number of days', 'manageentities'), $row['label']);
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public static function encode(array $rows): ?string
    {
        return $rows === [] ? null : json_encode(array_values($rows), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Total of the days of the lines
     *
     * @param list<array{type: string, label: string, days: string}> $rows
     */
    public static function total(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            if ($row['type'] === self::TYPE_LINE) {
                $total += (float) (PresalePricing::parseNumber($row['days']) ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * Days of the priced lines of a regular annex (their quantities), to compare with the
     * total of its estimate
     *
     * @param array<int, array{quantity: string}> $lines
     */
    public static function pricedDays(array $lines): float
    {
        $days = 0.0;
        foreach ($lines as $line) {
            $days += (float) (PresalePricing::parseNumber((string) $line['quantity']) ?? 0);
        }

        return round($days, 2);
    }

    /**
     * Total of the days as shown in the forms: decimal comma, no useless decimals
     *
     * @param list<array{type: string, label: string, days: string}> $rows
     */
    public static function formatTotal(array $rows): string
    {
        return str_replace('.', ',', (string) self::total($rows));
    }

    /**
     * @return array{type: string, label: string, days: string}
     */
    private static function row(array $row): array
    {
        return [
            'type'  => ($row['type'] ?? '') === self::TYPE_TITLE ? self::TYPE_TITLE : self::TYPE_LINE,
            'label' => trim((string) ($row['label'] ?? '')),
            'days'  => trim((string) ($row['days'] ?? '')),
        ];
    }
}
