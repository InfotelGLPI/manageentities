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

use Html;
use Migration;
use NotificationTarget;

/**
 * Notification target for contracts running out of remaining days.
 *
 * GLPI resolves the target class by namespace convention
 * (NotificationTarget::getInstanceClass()): for the itemtype
 * GlpiPlugin\Manageentities\Contract it looks up
 * GlpiPlugin\Manageentities\NotificationTargetContract. The class name is the same
 * as the core one for the core Contract itemtype, which is exactly why this file
 * declares the plugin namespace: the two never meet.
 *
 * Profile and group recipients are provided for free by the base class
 * (addNotificationTargets() calls addProfilesToTargets() + addGroupsToTargets()),
 * so it is not overridden here.
 */
class NotificationTargetContract extends NotificationTarget
{
    public const LowRemainingDaysContracts = "LowRemainingDaysContracts";

    /**
     * @return array
     */
    public function getEvents()
    {
        return [
            self::LowRemainingDaysContracts => __('Contracts running out of remaining days', 'manageentities'),
        ];
    }

    /**
     * @param       $event
     * @param array $options
     */
    public function addDataForTemplate($event, $options = [])
    {
        $this->data['##contract.action##'] = __('Contracts running out of remaining days', 'manageentities');

        // Column labels (##lang.contract.*##)
        $this->data['##lang.contract.entity##']      = __('Entity', 'manageentities');
        $this->data['##lang.contract.name##']        = __('Name');
        $this->data['##lang.contract.num##']         = __('Contract number', 'manageentities');
        $this->data['##lang.contract.begindate##']   = __('Start date');
        $this->data['##lang.contract.remaining##']   = __('Total remaining', 'manageentities');
        $this->data['##lang.contract.prestations##'] = __('Prestation', 'manageentities');

        if (isset($options['contracts'])) {
            foreach ($options['contracts'] as $contract) {
                $tmp = [];

                $tmp['##contract.entity##']    = $contract['entity_completename'] ?? '';
                $tmp['##contract.name##']      = $contract['name'] ?? '';
                $tmp['##contract.num##']       = $contract['num'] ?? '';
                $tmp['##contract.begindate##'] = !empty($contract['begin_date'])
                    ? Html::convDate($contract['begin_date'])
                    : '';
                $tmp['##contract.remaining##'] = Html::formatNumber($contract['remaining_days'] ?? 0, false, 2);

                // One line per open period, flattened into a single tag: a notification
                // template substitutes one level of FOREACH only, and the period detail
                // is what tells the reader which prestation is about to run dry.
                $tmp['##contract.prestations##'] = $contract['prestations'] ?? '';

                $this->data['contracts'][] = $tmp;
            }
        }
    }

    /**
     * Register available tags for the notification template editor.
     */
    public function getTags()
    {
        $tags = [
            'contract.entity'      => __('Entity', 'manageentities'),
            'contract.name'        => __('Name'),
            'contract.num'         => __('Contract number', 'manageentities'),
            'contract.begindate'   => __('Start date'),
            'contract.remaining'   => __('Total remaining', 'manageentities'),
            'contract.prestations' => __('Prestation', 'manageentities'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        $this->addTagToList([
            'tag'     => 'contracts',
            'label'   => __('Contracts running out of remaining days', 'manageentities'),
            'value'   => false,
            'foreach' => true,
            'events'  => [self::LowRemainingDaysContracts],
        ]);

        asort($this->tag_descriptions);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_notificationtemplates',
            'WHERE' => ['itemtype' => Contract::class],
        ])->current();

        if ((int) ($exists['cpt'] ?? 0) === 0) {
            $DB->insert(
                'glpi_notificationtemplates',
                [
                    'name'     => 'Alert Contracts Without Remaining Days',
                    'itemtype' => Contract::class,
                ],
            );
        }
    }
}
