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

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

/**
 * Single-form wizard for EditorSubscription.
 * No session-based multi-step: entity selector + subscription fields in one POST.
 */
class EditorSubscriptionWizard
{

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    static function render(): void
    {
        $config    = Config::getInstance();
        $forced_id = (int)($config->fields['wizard_default_entities_id'] ?? 0);

        // entities_id may come from GET (existing_entity shortcut from tab)
        $entities_id = (int)($_GET['entities_id'] ?? 0);
        $page_url    = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php';
        $rand        = mt_rand();

        // Entity dropdown
        if ($forced_id > 0) {
            $sons = getSonsOf('glpi_entities', $forced_id);
            unset($sons[$forced_id]);
            $condition = !empty($sons) ? ['id' => array_keys($sons)] : ['id' => [-1]];
        } else {
            $condition = [];
        }

        if ($entities_id > 0) {
            $completename        = Dropdown::getDropdownName('glpi_entities', $entities_id);
            $entity_dropdown_html = '<input type="hidden" name="entities_id" value="' . $entities_id . '">'
                . '<input type="text" class="form-control" value="' . htmlspecialchars($completename) . '" readonly disabled>';
            $parts       = explode(' > ', $completename);
            $entity_name = trim(end($parts));
        } else {
            ob_start();
            Dropdown::show(\Entity::class, [
                'name'      => 'entities_id',
                'rand'      => $rand,
                'value'     => 0,
                'condition' => $condition,
            ]);
            $entity_dropdown_html = ob_get_clean();
            $entity_name          = '';
        }

        // Existing subscription pre-fill
        $sub = $entities_id > 0 ? EditorSubscription::getForEntity($entities_id) : [];

        // All subscription levels with their type — passed as JSON for JS filtering
        $all_levels = SubscriptionLevel::getAllForJS();

        $now              = date('Y-m-d');
        $end_date_expired = !empty($sub['end_date']) && substr($sub['end_date'], 0, 10) < $now;
        $sub_name         = !empty($sub['name']) ? $sub['name'] : $entity_name;

        TemplateRenderer::getInstance()->display(
            '@manageentities/editorsubscription_wizard.html.twig',
            [
                'page_url'                  => $page_url,
                'entity_list_url'           => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                'entities_id'               => $entities_id,
                'entity_dropdown_html'      => $entity_dropdown_html,
                'sub_id'                    => $sub['id'] ?? 0,
                'is_new_sub'                => empty($sub),
                'name'                      => $sub_name,
                'customer_account_id'       => $sub['customer_account_id'] ?? '',
                'active_editor_suscription' => (int)($sub['active_editor_suscription'] ?? 0),
                'cloud_client'              => (int)($sub['cloud_client'] ?? 0),
                'internet_publication'      => (int)($sub['internet_publication'] ?? 0),
                'plugin_manageentities_subscriptionlevels_id'     => (int)($sub['plugin_manageentities_subscriptionlevels_id'] ?? 0),
                'begin_date'                => $sub['begin_date'] ?? '',
                'end_date'                  => $sub['end_date'] ?? '',
                'end_date_expired'          => $end_date_expired,
                'comment'                   => $sub['comment'] ?? '',
                'all_levels'                => $all_levels,
                'rand'                      => $rand,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Save (single POST)
    // -----------------------------------------------------------------------

    static function saveAndReturn(array $input = []): array
    {
        $entities_id = (int)($input['entities_id'] ?? 0);
        if ($entities_id <= 0) {
            return ['success' => false, 'message' => __('No entity selected.', 'manageentities')];
        }

        $begin = trim($input['begin_date'] ?? '');
        $end   = trim($input['end_date'] ?? '');

        // Mandatory fields
        $subscription_type = $input['subscription_type'] ?? '';
        if (!in_array($subscription_type, ['editor', 'cloud'], true)) {
            return ['success' => false, 'message' => __('Subscription type is required', 'manageentities')];
        }
        if ((int)($input['plugin_manageentities_subscriptionlevels_id'] ?? 0) <= 0) {
            return ['success' => false, 'message' => __('Subscription level is required', 'manageentities')];
        }
        if ($begin === '') {
            return ['success' => false, 'message' => __('Begin date is required', 'manageentities')];
        }
        if ($end === '') {
            return ['success' => false, 'message' => __('End date is required', 'manageentities')];
        }

        $cloud_client = empty($input['cloud_client']) ? 0 : 1;
        $data = [
            'entities_id'               => $entities_id,
            'name'                       => trim($input['name'] ?? ''),
            'customer_account_id'        => trim($input['customer_account_id'] ?? ''),
            'active_editor_suscription'  => empty($input['active_editor_suscription']) ? 0 : 1,
            'cloud_client'               => $cloud_client,
            'internet_publication'       => $cloud_client ? 1 : (empty($input['internet_publication']) ? 0 : 1),
            'plugin_manageentities_subscriptionlevels_id'      => (int)($input['plugin_manageentities_subscriptionlevels_id'] ?? 0),
            'begin_date'                 => $begin !== '' ? $begin : null,
            'end_date'                   => $end !== '' ? $end : null,
            'comment'                    => trim($input['comment'] ?? ''),
        ];

        $sub      = new EditorSubscription();
        $existing = EditorSubscription::getForEntity($entities_id);

        if (!empty($existing)) {
            $data['id'] = $existing['id'];
            $result     = $sub->update($data);
        } else {
            $result = $sub->add($data);
        }

        return $result
            ? ['success' => true]
            : ['success' => false, 'message' => __('An error occurred while saving.', 'manageentities')];
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    static function deleteAndReturn(array $input = []): array
    {
        $sub_id = (int)($input['sub_id'] ?? 0);
        if ($sub_id <= 0) {
            return ['success' => false, 'message' => __('No subscription found.', 'manageentities')];
        }

        $sub = new EditorSubscription();
        if (!$sub->getFromDB($sub_id)) {
            return ['success' => false, 'message' => __('No subscription found.', 'manageentities')];
        }

        $result = $sub->delete(['id' => $sub_id], true);

        return $result
            ? ['success' => true]
            : ['success' => false, 'message' => __('An error occurred while deleting.', 'manageentities')];
    }
}
