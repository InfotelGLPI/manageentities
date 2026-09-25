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

use CommonDBTM;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Session;

class TicketTask extends CommonDBTM
{
    public $dohistory = false;

    public static $rightname = "plugin_manageentities";

    public static function preItemForm(array $params): void
    {
        $item = $params['item'];
        if ($item->getType() !== 'TicketTask') {
            return;
        }

        $tickets_id = $item->fields['tickets_id'] ?? 0;
        if (!$tickets_id) {
            return;
        }

        if (!self::hasNoRemainingDays($tickets_id)) {
            return;
        }

        TemplateRenderer::getInstance()->display('@manageentities/tickettask_blocked_alert.html.twig');
    }

    private static function hasNoRemainingDays(int $tickets_id): bool
    {
        $dbu = new DbUtils();
        $cridetails = $dbu->getAllDataFromTable(
            'glpi_plugin_manageentities_cridetails',
            [
                'tickets_id' => $tickets_id,
                ['NOT' => ['plugin_manageentities_contractdays_id' => 0]],
            ],
        );
        $cridetail = reset($cridetails);

        if (empty($cridetail)) {
            return false;
        }

        $contractDay = new ContractDay();
        if (!$contractDay->getFromDB($cridetail['plugin_manageentities_contractdays_id'])) {
            return false;
        }

        $contractDay->fields['contractdays_id'] = $contractDay->fields['id'];
        $result = CriDetail::getCriDetailData($contractDay->fields);

        return $result['resultOther']['reste'] <= 0;
    }

    public static function preItemAdd(\TicketTask $item): void
    {
        $tickets_id = $item->input['tickets_id'] ?? 0;
        if (!$tickets_id) {
            return;
        }

        if (!self::hasNoRemainingDays($tickets_id)) {
            return;
        }

        Session::addMessageAfterRedirect(
            __('No days remaining on this contract period. Task addition is blocked.', 'manageentities'),
            false,
            ERROR,
        );
        $item->input = [];
    }

    /**
     * Refresh the remaining days of the contracts the task's ticket consumes on
     * (task added, updated or purged: duration, privacy or dates may have changed).
     */
    public static function refreshRemainingDays(\TicketTask $item): void
    {
        Contract::updateRemainingDaysForTicket((int) ($item->fields['tickets_id'] ?? 0));
    }

    /**
     * Refresh the remaining days when the validation status of the ticket changes:
     * it decides whether its tasks are consumed (needvalidationforcri).
     */
    public static function refreshTicketRemainingDaysOnUpdate(\Ticket $item): void
    {
        if (!in_array('global_validation', $item->updates, true)) {
            return;
        }

        Contract::updateRemainingDaysForTicket((int) $item->getID());
    }

    /**
     * Refresh the remaining days when the ticket is trashed or restored: tasks of
     * deleted tickets are not consumed.
     */
    public static function refreshTicketRemainingDays(\Ticket $item): void
    {
        Contract::updateRemainingDaysForTicket((int) $item->getID());
    }

    public static function postForm($params): void
    {
        global $CFG_GLPI;

        $tickettask = $params['item'];
        if ($tickettask->getType() !== 'TicketTask') {
            return;
        }

        $value = $tickettask->fields['date'];
        if (!empty($tickettask->fields['begin'])) {
            $value = date('Y-m-d H:i:s', strtotime($tickettask->fields['begin'] . ' + 1 DAY'));
        }

        // Keep the proposed time within the planning hours
        if (!empty($value) && str_contains($value, ' ')) {
            [$date_value, $hour_value] = explode(' ', $value, 2);
            $hour_value = max($hour_value, $CFG_GLPI['planning_begin']);
            $hour_value = min($hour_value, $CFG_GLPI['planning_end']);
            $value      = $date_value . ' ' . $hour_value;
        }

        TemplateRenderer::getInstance()->display('@manageentities/tickettask_duplicate_row.html.twig', [
            'new_date'      => $value,
            'tickettask_id' => (int) $tickettask->fields['id'],
            // Read by public/scripts/tickettask-clone.js
            'clone'         => [
                'url'            => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/tickettask.php',
                'tickets_id'     => (int) $tickettask->fields['tickets_id'],
                'tickettasks_id' => (int) $tickettask->fields['id'],
            ],
        ]);
    }
}
