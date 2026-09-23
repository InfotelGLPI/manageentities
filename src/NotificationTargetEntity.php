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

use Migration;
use NotificationTarget;

/**
 * Notification target for the "add elements" wizard.
 *
 * GLPI resolves the target class by namespace convention
 * (NotificationTarget::getInstanceClass()): for the itemtype
 * GlpiPlugin\Manageentities\Entity it looks up
 * GlpiPlugin\Manageentities\NotificationTargetEntity. The plugin Entity is a
 * CommonGLPI without a table of its own, which is enough here: the object passed to
 * NotificationEvent::raiseEvent() only carries the itemtype, every value of the mail
 * comes from the options, exactly as it does for the two other notifications of the
 * plugin.
 *
 * The wizard writes one of three combinations -- entity + subscription + contract,
 * contract alone (existing entity), or entity + subscription alone (finish at step 3)
 * -- and a single event covers all of them: what was actually written is the list of
 * items the mail carries, so the combination needs no event of its own.
 *
 * Profile and group recipients are provided for free by the base class
 * (addNotificationTargets() calls addProfilesToTargets() + addGroupsToTargets()),
 * so it is not overridden here.
 */
class NotificationTargetEntity extends NotificationTarget
{
    public const WizardCreation = "WizardCreation";

    /**
     * @return array
     */
    public function getEvents()
    {
        return [
            self::WizardCreation => __('Elements added through the wizard', 'manageentities'),
        ];
    }

    /**
     * @param       $event
     * @param array $options
     */
    public function addDataForTemplate($event, $options = [])
    {
        $this->data['##wizard.action##'] = __('Elements added through the wizard', 'manageentities');

        $this->data['##wizard.entity##'] = $options['entity_name'] ?? '';
        $this->data['##wizard.author##'] = $options['author'] ?? '';
        $this->data['##wizard.date##']   = $options['date'] ?? '';

        // Column labels (##lang.wizard.*## and ##lang.item.*##)
        $this->data['##lang.wizard.entity##'] = __('Entity', 'manageentities');
        $this->data['##lang.wizard.author##'] = __('Author');
        $this->data['##lang.wizard.date##']   = __('Creation date');
        $this->data['##lang.item.type##']     = __('Type');
        $this->data['##lang.item.label##']    = __('Name');

        // One row per written element. The wizard already builds exactly this list for
        // the confirmation modal (WizardController::buildFinishSummaryFromSession()),
        // so the mail and the screen cannot describe two different things.
        if (isset($options['items'])) {
            foreach ($options['items'] as $item) {
                $this->data['items'][] = [
                    '##item.type##'  => $item['type'] ?? '',
                    '##item.label##' => $item['label'] ?? '',
                ];
            }
        }
    }

    /**
     * Register available tags for the notification template editor.
     */
    public function getTags()
    {
        $tags = [
            'wizard.entity' => __('Entity', 'manageentities'),
            'wizard.author' => __('Author'),
            'wizard.date'   => __('Creation date'),
            'item.type'     => __('Type'),
            'item.label'    => __('Name'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        $this->addTagToList([
            'tag'     => 'items',
            'label'   => __('Elements added through the wizard', 'manageentities'),
            'value'   => false,
            'foreach' => true,
            'events'  => [self::WizardCreation],
        ]);

        asort($this->tag_descriptions);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_notificationtemplates',
            'WHERE' => ['itemtype' => Entity::class],
        ])->current();

        if ((int) ($exists['cpt'] ?? 0) === 0) {
            $DB->insert(
                'glpi_notificationtemplates',
                [
                    'name'     => 'Alert Wizard Creation',
                    'itemtype' => Entity::class,
                ],
            );
        }
    }
}
