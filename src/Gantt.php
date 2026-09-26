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
use Glpi\Application\View\TemplateRenderer;
use Html;
use Infocom;
use Session;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\Entity;
use Toolbox;

class Gantt extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return __('GANTT');
    }

    public static function getIcon()
    {
        return "ti ti-align-box-left-stretch";
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    /**
     * Show the GANTT diagram of the contracts of the active entities.
     *
     * The chart is drawn by the FullCalendar bundle shipped by GLPI core
     * (public/lib/fullcalendar.js) in its resourceTimeline view: one parent row per
     * contract, one child row per contract day. This tab is served through
     * ajax/common.tabs.php, which emits no page footer, so Html::requireJs() would be
     * silently dropped; the assets are echoed here instead, exactly as the library they
     * replace used to be. jQuery evaluates them in document order once the container
     * below is in the DOM, so scripts/gantt.js always finds both FullCalendar and its
     * payload.
     *
     * @param array $values
     *
     * @return void
     */
    public static function showGantt($values = [])
    {
        Entity::showManageentitiesHeader(__('GANTT', 'manageentities'));

        $todisplay = self::getDataToDisplayOnGantt($_SESSION["glpiactiveentities"], true);

        if (count($todisplay) == 0) {
            echo htmlescape(__('Nothing to display', 'manageentities'));
            return;
        }

        // Fallbacks for the rows carrying no date, kept from the jQuery Gantt version.
        $now           = strtotime($_SESSION['glpi_currenttime']);
        $default_start = strtotime('-1 year', $now);

        $resources    = [];
        $events       = [];
        $contract_ids = [];

        foreach ($todisplay as $val) {
            $begin = self::toTimestamp($val['from'] ?? null, $default_start);
            $end   = self::toTimestamp($val['to'] ?? null, $now);
            if ($end < $begin) {
                $end = $begin;
            }

            $event = [
                'start'         => date('Y-m-d', $begin),
                // FullCalendar reads the end of an all-day range as exclusive: without this
                // extra day the last day of the row would not be painted.
                'end'           => date('Y-m-d', strtotime('+1 day', $end)),
                'title'         => $val['bar_label'],
                'extendedProps' => ['tooltip' => $val['tooltip']],
            ];
            if ($val['url'] !== null) {
                $event['url'] = $val['url'];
            }

            switch ($val['type']) {
                case 'contract':
                    $resource_id                    = 'contract_' . $val['id'];
                    $contract_ids[(int) $val['id']] = true;

                    $resources[] = [
                        'id'    => $resource_id,
                        'title' => $val['title'],
                    ];

                    $event['resourceId'] = $resource_id;
                    $event['classNames'] = ['manageentities-gantt-contract'];
                    break;

                case 'contractday':
                    // A day whose contract was filtered out would point at a row that does
                    // not exist; drop it rather than emit a dangling parentId.
                    if (!isset($contract_ids[(int) $val['dep']])) {
                        continue 2;
                    }

                    $resource_id = 'contractday_' . $val['id'];

                    $resources[] = [
                        'id'       => $resource_id,
                        'parentId' => 'contract_' . $val['dep'],
                        'title'    => $val['title'],
                    ];

                    $event['resourceId']               = $resource_id;
                    $event['classNames']               = ['manageentities-gantt-day'];
                    $event['extendedProps']['percent'] = round((float) $val['percent'], 2);
                    break;

                default:
                    continue 2;
            }

            $events[] = $event;
        }

        if (count($events) == 0) {
            echo htmlescape(__('Nothing to display', 'manageentities'));
            return;
        }

        echo Html::css('lib/fullcalendar.css');
        echo Html::script('lib/fullcalendar.js');
        $locale_file = self::getFullCalendarLocaleFile();
        if ($locale_file !== null) {
            echo Html::script($locale_file);
        }
        // Stamped with the modification time of the file on top of the version of the
        // plugin: Html::script() otherwise falls back to GLPI_VERSION, and the version of
        // the plugin alone does not move between two builds of the same release, so a
        // browser would keep drawing the chart from a script it no longer matches.
        $script      = __DIR__ . '/../public/scripts/gantt.js';
        $script_stamp = PLUGIN_MANAGEENTITIES_VERSION;
        if (file_exists($script)) {
            $script_stamp .= '.' . filemtime($script);
        }
        echo Html::script(
            'plugins/manageentities/scripts/gantt.js',
            ['version' => $script_stamp],
            false,
        );

        TemplateRenderer::getInstance()->display('@manageentities/gantt.html.twig', [
            'config' => [
                'resources'      => $resources,
                'events'         => $events,
                'resource_label' => _n('Contract', 'Contracts', 2),
                // The timeline opens on the current month rather than on the oldest
                // contract, so the days being consumed are the ones on screen.
                'today'          => date('Y-m-d', $now),
            ],
        ]);
    }

    /**
     * Turn one of the dates built by the two collectors below into a timestamp.
     *
     * @param mixed $value    date as "Y/n/j", or null when the row carries none
     * @param int   $fallback timestamp to use when the date is missing or unreadable
     *
     * @return int
     */
    private static function toTimestamp($value, int $fallback): int
    {
        if (empty($value)) {
            return $fallback;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? $fallback : $timestamp;
    }

    /**
     * Locale file of the core FullCalendar bundle for the current language, if any.
     *
     * Mirrors what Html::requireJs('fullcalendar') does, which this tab cannot use: its
     * content is served by ajax/common.tabs.php, with no footer to honour the request.
     *
     * @return string|null path relative to the public directory, null when unavailable
     */
    private static function getFullCalendarLocaleFile(): ?string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $language = $_SESSION['glpilanguage'] ?? null;
        if ($language === null || !isset($CFG_GLPI['languages'][$language])) {
            return null;
        }

        foreach ([2, 3] as $index) {
            if (!isset($CFG_GLPI['languages'][$language][$index])) {
                continue;
            }

            $filename = 'lib/fullcalendar/core/locales/'
                . strtolower((string) $CFG_GLPI['languages'][$language][$index]) . '.js';
            if (file_exists(GLPI_ROOT . '/public/' . $filename)) {
                return $filename;
            }
        }

        return null;
    }

    /**
     * Get data to display on GANTT
     *
     * Every string returned here is plain text: it travels as JSON inside a data
     * attribute, and FullCalendar escapes the resource and event titles itself. Escaping
     * it here as well would show the entities twice.
     *
     * @param $entity    array     ids of the entities to read
     * @param $showall   boolean   show all sub items (contract days) (true by default)
     *
     * @return array
     */
    public static function getDataToDisplayOnGantt($entity, $showall = true)
    {
        $contracts = Followup::queryFollowUp($entity, []);
        $todisplay = [];

        // The form of a contract is out of reach from the simplified interface, so the
        // row is only made clickable for the central one.
        $is_central = Session::getCurrentInterface() == 'central';

        if (!empty($contracts)) {
            foreach ($contracts as $key => $contract_data) {
                if (is_integer($key)) {
                    if (!is_null($contract_data['contract_begin_date'])
                        && $contract_data['show_on_global_gantt'] > 0
                    ) {
                        foreach ($contract_data['days'] as $day_key => $days) {
                            if ($days['contract_is_closed']) {
                                unset($contract_data['days'][$day_key]);
                            }
                        }
                        if (!empty($contract_data['days'])) {
                            $real_begin = date('Y/n/j', strtotime($contract_data['contract_begin_date']) + 86400);
                            $tmp        = Infocom::getWarrantyExpir(
                                $contract_data['contract_begin_date'],
                                $contract_data["duration"],
                                0,
                                false,
                            );
                            $real_end   = date('Y/n/j', strtotime($tmp) + 86400);

                            $title = $contract_data['entities_name'] . ' > ' . $contract_data['name'];

                            // Shown by the browser as the title attribute of the bar, hence
                            // the newlines where the removed library wanted <br/>.
                            $tooltip = __('Name') . ' : ' . $title;
                            if (!empty($contract_data['contract_num'])) {
                                $tooltip .= "\n" . _x('phone', 'Number') . ' : ' . $contract_data['contract_num'];
                            }
                            if (!empty($contract_data['contract_added'])) {
                                $tooltip .= "\n" . __('Contract present', 'manageentities')
                                    . ' : ' . $contract_data['contract_added'];
                            }
                            if (!empty($contract_data['date_signature'])) {
                                $tooltip .= "\n" . __('Date of signature', 'manageentities')
                                    . ' : ' . $contract_data['date_signature'];
                            }
                            if (!empty($contract_data['date_renewal'])) {
                                $tooltip .= "\n" . __('Date of renewal', 'manageentities')
                                    . ' : ' . $contract_data['date_renewal'];
                            }

                            //Add current contract
                            $todisplay[$real_begin . '#' . $real_end . '#task' . $contract_data['contracts_id']]
                                = [
                                    'id'        => $contract_data['contracts_id'],
                                    'type'      => 'contract',
                                    'title'     => $title,
                                    'bar_label' => $contract_data['name'],
                                    'tooltip'   => $tooltip,
                                    'url'       => $is_central
                                        ? Toolbox::getItemTypeFormURL("Contract")
                                            . '?id=' . $contract_data['contracts_id']
                                        : null,
                                    'percent'   => 0,
                                    'from'      => $real_begin,
                                    'to'        => $real_end,
                                    'dep'       => false,
                                ];

                            if ($showall) {
                                //Add current tasks
                                $todisplay += self::getDataToDisplayOnGanttForContract(
                                    $contract_data['days'],
                                    $is_central,
                                );
                            }
                        }
                    }
                }
            }
        }

        return $todisplay;
    }

    /**
     * Get data to display on GANTT for the days of one contract
     *
     * @param $days        array     rows returned by Followup::queryFollowUp()
     * @param $is_central  boolean   whether the contract day form is reachable
     *
     * @return array
     */
    public static function getDataToDisplayOnGanttForContract($days, $is_central = true)
    {
        $todisplay = [];

        if (!empty($days)) {
            foreach ($days as $day_data) {
                $real_begin = null;
                $real_end   = null;
                // Use real if set
                if (!is_null($day_data['begin_date'])) {
                    $real_begin = date('Y/n/j', strtotime($day_data['begin_date']) + 86400);
                }
                if (!is_null($day_data['end_date'])) {
                    $real_end = date('Y/n/j', strtotime($day_data['end_date']) + 86400);
                }

                // contractdayname is the raw period name: this tab hands plain text to
                // FullCalendar and gets the link back through 'url'.
                $title   = $day_data['contractdayname'];
                $tooltip = __('Name') . ' : ' . $title;

                if (isset($day_data['contract_type'])
                    && $day_data['contract_type'] == Contract::CONTRACT_TYPE_FORFAIT) {
                    $percent = 100;
                } else {
                    $tooltip .= "\n" . __('Initial credit', 'manageentities') . ' : ' . $day_data['credit']
                        . "\n" . __('Total consummated', 'manageentities') . ' : ' . $day_data['conso'];
                    if ($day_data['credit'] == 0) {
                        $percent = 0;
                    } else {
                        $percent = ($day_data['conso'] * 100) / $day_data['credit'];
                    }
                }
                $percentview = Html::formatNumber($percent, false, 2) . ' %';

                // Add current task
                $todisplay[$real_begin . '#' . $real_end . '#task' . $day_data['contractdays_id']]
                    = [
                        'id'        => $day_data['contractdays_id'],
                        'type'      => 'contractday',
                        'title'     => $title,
                        'bar_label' => $percentview,
                        'tooltip'   => $tooltip,
                        'url'       => $is_central
                            ? Toolbox::getItemTypeFormURL(ContractDay::class)
                                . '?id=' . $day_data['contractdays_id'] . '&showFromPlugin=1'
                            : null,
                        'dep'       => $day_data['contracts_id'],
                        'percent'   => $percent,
                        'from'      => $real_begin,
                        'to'        => $real_end,
                    ];
            }
        }

        return $todisplay;
    }
}
