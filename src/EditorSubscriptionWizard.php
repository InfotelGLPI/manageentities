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

use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Session;

/**
 * Single-form wizard for EditorSubscription.
 * No session-based multi-step: entity selector + subscription fields in one POST.
 */
class EditorSubscriptionWizard
{
    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public static function render(): void
    {
        $config    = Config::getInstance();
        $forced_id = (int) ($config->fields['wizard_default_entities_id'] ?? 0);

        // entities_id may come from GET (existing_entity shortcut from tab)
        $entities_id = (int) ($_GET['entities_id'] ?? 0);

        // Entity scope (anti-IDOR): saveAndReturn() and deleteAndReturn() already refuse an
        // entity outside the caller's scope, but the read path did not, and the controller
        // only checks the global CREATE/UPDATE right. Iterating on entities_id displayed the
        // subscription of every client: customer account id, level, dates and comments.
        if ($entities_id > 0 && !Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
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

        $completename = '';
        $entity_name  = '';
        if ($entities_id > 0) {
            $completename = Dropdown::getDropdownName('glpi_entities', $entities_id);
            $parts        = explode(' > ', $completename);
            $entity_name  = trim(end($parts));
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
                'entity_completename'       => $completename,
                'entity_condition'          => $condition,
                'change_event_js'           => CriDetail::CHANGE_EVENT_JS,
                'lookup_url'                => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/getSubscription.php',
                'sub_id'                    => $sub['id'] ?? 0,
                'is_new_sub'                => empty($sub),
                'sub_name'                  => $sub_name,
                'customer_account_id'       => $sub['customer_account_id'] ?? '',
                'active_editor_suscription' => (int) ($sub['active_editor_suscription'] ?? 0),
                'cloud_client'              => (int) ($sub['cloud_client'] ?? 0),
                'internet_publication'      => (int) ($sub['internet_publication'] ?? 0),
                'plugin_manageentities_subscriptionlevels_id'     => (int) ($sub['plugin_manageentities_subscriptionlevels_id'] ?? 0),
                'begin_date'                => $sub['begin_date'] ?? '',
                'end_date'                  => $sub['end_date'] ?? '',
                'end_date_expired'          => $end_date_expired,
                'comment'                   => $sub['comment'] ?? '',
                'all_levels'                => $all_levels,
                'rand'                      => $rand,
                // Transfer button: existing subscription of a known entity only
                'transfer_url'              => !empty($sub) && $entities_id > 0 && self::canTransfer()
                    ? $page_url . '?transfer=' . (int) $sub['id']
                    : '',
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Save (single POST)
    // -----------------------------------------------------------------------

    public static function saveAndReturn(array $input = []): array
    {
        $entities_id = (int) ($input['entities_id'] ?? 0);
        if ($entities_id <= 0) {
            return ['success' => false, 'message' => __('No entity selected.', 'manageentities')];
        }

        // Entity scope (anti-IDOR): the controller only checked the global CREATE/UPDATE
        // right. entities_id comes from the POST and drives add()/update() (via
        // getForEntity), so require access to that entity - otherwise a user could
        // create or overwrite another entity's subscription. Same guard the rest of the
        // plugin applies on its write/disclosure paths.
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }

        $begin = trim($input['begin_date'] ?? '');
        $end   = trim($input['end_date'] ?? '');

        // Mandatory fields
        $subscription_type = $input['subscription_type'] ?? '';
        if (!in_array($subscription_type, ['editor', 'cloud'], true)) {
            return ['success' => false, 'message' => __('Subscription type is required', 'manageentities')];
        }
        if ((int) ($input['plugin_manageentities_subscriptionlevels_id'] ?? 0) <= 0) {
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
            'plugin_manageentities_subscriptionlevels_id'      => (int) ($input['plugin_manageentities_subscriptionlevels_id'] ?? 0),
            'begin_date'                 => $begin !== '' ? $begin : null,
            'end_date'                   => $end !== '' ? $end : null,
            'comment'                    => trim($input['comment'] ?? ''),
        ];

        $sub      = new EditorSubscription();
        $existing = EditorSubscription::getForEntity($entities_id);

        // The controller accepts CREATE or UPDATE: require the right matching the
        // operation, so CREATE alone cannot overwrite an existing subscription
        if (!Session::haveRight(Contract::$rightname, !empty($existing) ? UPDATE : CREATE)) {
            throw new AccessDeniedHttpException();
        }

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
    // Transfer
    // -----------------------------------------------------------------------

    /**
     * Move a subscription to another client entity (e.g. after a merger or an entity
     * re-creation). An entity holds at most one subscription (unique key on entities_id),
     * so the target must not have one yet.
     *
     * @return array{success: bool, message?: string, entities_id?: int}
     */
    public static function transferAndReturn(array $input = []): array
    {
        if (!self::canTransfer()) {
            throw new AccessDeniedHttpException();
        }

        $sub_id    = (int) ($input['sub_id'] ?? 0);
        $target_id = (int) ($input['target_entities_id'] ?? 0);

        $sub = new EditorSubscription();
        if ($sub_id <= 0 || !$sub->getFromDB($sub_id)) {
            return ['success' => false, 'message' => __('No subscription found.', 'manageentities')];
        }

        // Both ends are attacker-supplied: the caller must reach the entity the subscription
        // leaves and the one it goes to
        $source_id = (int) $sub->fields['entities_id'];
        if (!Session::haveAccessToEntity($source_id) || !Session::haveAccessToEntity($target_id)) {
            throw new AccessDeniedHttpException();
        }

        if ($target_id <= 0) {
            return ['success' => false, 'message' => __('No entity selected.', 'manageentities')];
        }
        if ($target_id === $source_id) {
            return ['success' => false, 'message' => __('The subscription already belongs to this entity.', 'manageentities')];
        }
        // Same perimeter as the entity dropdown of the form: a client under the configured root
        $allowed = self::getAllowedEntityIds();
        if ($allowed !== null && !in_array($target_id, $allowed, true)) {
            return ['success' => false, 'message' => __('Entity not found', 'manageentities')];
        }
        if (!empty(EditorSubscription::getForEntity($target_id))) {
            return ['success' => false, 'message' => __('The target entity already has a publisher subscription.', 'manageentities')];
        }

        // post_updateItem() propagates the subscription flags to the contracts of the new entity
        if (!$sub->update(['id' => $sub_id, 'entities_id' => $target_id])) {
            return ['success' => false, 'message' => __('An error occurred while saving.', 'manageentities')];
        }

        return ['success' => true, 'entities_id' => $target_id];
    }

    /**
     * Moving a subscription is an entity transfer: it takes the core transfer right, as
     * the core transfer action does, on top of the plugin right to modify a subscription.
     */
    public static function canTransfer(): bool
    {
        return Session::haveRight(Contract::$rightname, UPDATE)
            && Session::haveRight(\Transfer::$rightname, READ);
    }

    /**
     * Transfer page of a subscription: its entity, its name and the target entity dropdown.
     */
    public static function renderTransfer(int $sub_id): void
    {
        if (!self::canTransfer()) {
            throw new AccessDeniedHttpException();
        }

        $sub = new EditorSubscription();
        if ($sub_id <= 0 || !$sub->getFromDB($sub_id)) {
            throw new NotFoundHttpException();
        }
        $entities_id = (int) $sub->fields['entities_id'];
        if (!Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }

        $condition = ['NOT' => ['id' => $entities_id]];
        $allowed   = self::getAllowedEntityIds();
        if ($allowed !== null) {
            $condition['id'] = $allowed !== [] ? $allowed : [-1];
        }

        TemplateRenderer::getInstance()->display('@manageentities/editorsubscription_transfer.html.twig', [
            'page_url'            => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php',
            'back_url'            => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php?entities_id=' . $entities_id,
            'sub_id'              => $sub_id,
            'sub_name'            => (string) $sub->fields['name'],
            'entity_completename' => Dropdown::getDropdownName('glpi_entities', $entities_id),
            'transfer_condition'  => $condition,
            'rand'                => mt_rand(),
        ]);
    }

    /**
     * Client entities a subscription may belong to: the children of the configured wizard
     * root, or every entity when none is configured.
     *
     * @return int[]|null ids, null meaning "no restriction"
     */
    private static function getAllowedEntityIds(): ?array
    {
        $forced_id = (int) (Config::getInstance()->fields['wizard_default_entities_id'] ?? 0);
        if ($forced_id <= 0) {
            return null;
        }
        $sons = getSonsOf('glpi_entities', $forced_id);
        unset($sons[$forced_id]);

        return array_map('intval', array_keys($sons));
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public static function deleteAndReturn(array $input = []): array
    {
        $sub_id = (int) ($input['sub_id'] ?? 0);
        if ($sub_id <= 0) {
            return ['success' => false, 'message' => __('No subscription found.', 'manageentities')];
        }

        $sub = new EditorSubscription();
        if (!$sub->getFromDB($sub_id)) {
            return ['success' => false, 'message' => __('No subscription found.', 'manageentities')];
        }

        // Entity scope (anti-IDOR): the sub_id is attacker-supplied and getForEntity is
        // not involved on delete, so re-check access to the subscription's own entity
        // before purging - a user with the global DELETE right must not be able to
        // iterate sub_id and wipe another entity's subscription.
        if (!Session::haveAccessToEntity((int) $sub->fields['entities_id'])) {
            throw new AccessDeniedHttpException();
        }

        $result = $sub->delete(['id' => $sub_id], true);

        return $result
            ? ['success' => true]
            : ['success' => false, 'message' => __('An error occurred while deleting.', 'manageentities')];
    }
}
