<?php

/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use Html;
use Migration;
use NotificationTarget;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * Notification target for expired publisher subscriptions.
 *
 * GLPI resolves the target class by namespace convention
 * (NotificationTarget::getInstanceClass()): for the itemtype
 * GlpiPlugin\Manageentities\EditorSubscription it looks up
 * GlpiPlugin\Manageentities\NotificationTargetEditorSubscription.
 *
 * Profile and group recipients are provided for free by the base class
 * (addNotificationTargets() calls addProfilesToTargets() + addGroupsToTargets()),
 * so it is not overridden here.
 */
class NotificationTargetEditorSubscription extends NotificationTarget
{
    public const ExpiredSubscriptions = "ExpiredSubscriptions";

    /**
     * @return array
     */
    public function getEvents()
    {
        return [
            self::ExpiredSubscriptions => __('Expired publisher subscriptions', 'manageentities'),
        ];
    }

    /**
     * @param       $event
     * @param array $options
     */
    public function addDataForTemplate($event, $options = [])
    {
        $this->data['##subscription.action##'] = __('Expired publisher subscriptions', 'manageentities');

        // Column labels (##lang.subscription.*##)
        $this->data['##lang.subscription.entity##']            = __('Entity', 'manageentities');
        $this->data['##lang.subscription.customeraccountid##'] = __('Publisher customer account ID', 'manageentities');
        $this->data['##lang.subscription.name##']              = __('Referenced name at the publisher', 'manageentities');
        $this->data['##lang.subscription.type##']              = __('Type', 'manageentities');
        $this->data['##lang.subscription.level##']             = __('Subscription level', 'manageentities');
        $this->data['##lang.subscription.begindate##']         = __('Start date', 'manageentities');
        $this->data['##lang.subscription.enddate##']           = __('End date', 'manageentities');

        if (isset($options['subscriptions'])) {
            foreach ($options['subscriptions'] as $subscription) {
                $tmp = [];

                $tmp['##subscription.entity##']            = $subscription['entity_completename'] ?? '';
                $tmp['##subscription.customeraccountid##'] = $subscription['customer_account_id'] ?? '';
                $tmp['##subscription.name##']              = $subscription['name'] ?? '';
                $tmp['##subscription.type##']              = !empty($subscription['cloud_client'])
                    ? __('Cloud client', 'manageentities')
                    : __('Editor subscription', 'manageentities');
                $tmp['##subscription.level##']     = $subscription['level_name'] ?? '';
                $tmp['##subscription.begindate##'] = !empty($subscription['begin_date'])
                    ? Html::convDate($subscription['begin_date'])
                    : '';
                $tmp['##subscription.enddate##'] = !empty($subscription['end_date'])
                    ? Html::convDate($subscription['end_date'])
                    : '';

                $this->data['subscriptions'][] = $tmp;
            }
        }
    }

    /**
     * Register available tags for the notification template editor.
     */
    public function getTags()
    {
        $tags = [
            'subscription.entity'            => __('Entity', 'manageentities'),
            'subscription.customeraccountid' => __('Publisher customer account ID', 'manageentities'),
            'subscription.name'              => __('Referenced name at the publisher', 'manageentities'),
            'subscription.type'              => __('Type', 'manageentities'),
            'subscription.level'             => __('Subscription level', 'manageentities'),
            'subscription.begindate'         => __('Start date', 'manageentities'),
            'subscription.enddate'           => __('End date', 'manageentities'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        $this->addTagToList([
            'tag'     => 'subscriptions',
            'label'   => __('Expired publisher subscriptions', 'manageentities'),
            'value'   => false,
            'foreach' => true,
            'events'  => [self::ExpiredSubscriptions],
        ]);

        asort($this->tag_descriptions);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_notificationtemplates',
            'WHERE' => ['itemtype' => EditorSubscription::class],
        ])->current();

        if ((int) ($exists['cpt'] ?? 0) === 0) {
            $DB->insert(
                'glpi_notificationtemplates',
                [
                    'name'     => 'Alert Expired Subscriptions',
                    'itemtype' => EditorSubscription::class,
                ]
            );
        }
    }
}
