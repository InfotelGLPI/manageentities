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

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class EditorSubscription extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 0)
    {
        return _n('Publisher subscription', 'Publisher subscriptions', $nb, 'manageentities');
    }

    static function getIcon()
    {
        return 'ti ti-certificate';
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    // -----------------------------------------------------------------------
    // Tab on plugin Entity
    // -----------------------------------------------------------------------

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        // Tab is registered directly in Entity::getTabNameForItem() as tab 6
        // to ensure correct ordering before Interventions reports (tab 7).
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        return true;
    }

    // -----------------------------------------------------------------------
    // Data helpers
    // -----------------------------------------------------------------------

    /**
     * Return the subscription row for a given entity, or [].
     */
    static function getForEntity(int $entities_id): array
    {
        if ($entities_id <= 0) {
            return [];
        }
        $sub = new self();
        $rows = $sub->find(['entities_id' => $entities_id], [], 1);
        return $rows ? reset($rows) : [];
    }

    // -----------------------------------------------------------------------
    // CSV export
    // -----------------------------------------------------------------------

    static function exportCsv(): void
    {
        global $DB;

        Session::checkLoginUser();
        if (!self::canView()) {
            \Html::displayRightError();
            exit;
        }

        if (Session::getCurrentInterface() === 'helpdesk') {
            $entity_ids = [$_SESSION['glpiactive_entity']];
        } else {
            $entity_ids = $_SESSION['glpiactiveentities'];
        }

        $config              = Config::getInstance();
        $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_entities_id > 0) {
            $archive_sons = getSonsOf('glpi_entities', $archive_entities_id);
            unset($archive_sons[$archive_entities_id]);
            $entity_ids = array_values(array_diff($entity_ids, array_keys($archive_sons)));
        }

        $now          = date('Y-m-d');
        $only_expired = !empty($_GET['sub_expired']);
        $rows         = [];

        if (!empty($entity_ids)) {
            $where = ['s.entities_id' => $entity_ids];
            if ($only_expired) {
                $where[] = ['s.end_date' => ['<', $now . ' 00:00:00']];
                $where[] = ['NOT' => ['s.end_date' => null]];
            }

            $iterator = $DB->request([
                'SELECT'    => ['s.*', 'e.completename AS entity_completename'],
                'FROM'      => self::getTable() . ' AS s',
                'LEFT JOIN' => [
                    'glpi_entities AS e' => ['FKEY' => ['s' => 'entities_id', 'e' => 'id']],
                ],
                'WHERE' => $where,
                'ORDER' => ['e.completename ASC'],
            ]);

            foreach ($iterator as $row) {
                $row['level_name'] = !empty($row['plugin_manageentities_subscriptionlevels_id'])
                    ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int)$row['plugin_manageentities_subscriptionlevels_id'])
                    : '';
                $row['type_label'] = $row['cloud_client']
                    ? __('Cloud client', 'manageentities')
                    : __('Editor subscription', 'manageentities');
                $rows[] = $row;
            }
        }

        $filename = ($only_expired ? 'subscriptions_expired_' : 'subscriptions_') . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        \Html::header_nocache();

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            __('Entity', 'manageentities'),
            __('Publisher customer account ID', 'manageentities'),
            __('Referenced name at the publisher', 'manageentities'),
            __('Type', 'manageentities'),
            __('Subscription level', 'manageentities'),
            __('Active editor subscription', 'manageentities'),
            __('Cloud client', 'manageentities'),
            __('Internet publication', 'manageentities'),
            __('Start date', 'manageentities'),
            __('End date', 'manageentities'),
            __('Comments'),
        ], ';');

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['entity_completename'] ?? '',
                $row['customer_account_id'] ?? '',
                $row['name'] ?? '',
                $row['type_label'],
                $row['level_name'],
                $row['active_editor_suscription'] ? '1' : '0',
                $row['cloud_client'] ? '1' : '0',
                $row['internet_publication'] ? '1' : '0',
                !empty($row['begin_date']) ? substr($row['begin_date'], 0, 10) : '',
                !empty($row['end_date'])   ? substr($row['end_date'],   0, 10) : '',
                $row['comment'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }

    // -----------------------------------------------------------------------
    // Status overview tab
    // -----------------------------------------------------------------------

    static function showStatusTab(): void
    {
        global $DB;

        $entity_ids = $_SESSION['glpiactiveentities'];
        $config     = Config::getInstance();

        $parent_id           = (int)($config->fields['wizard_default_entities_id'] ?? 0);
        $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);

        // concerned_ids: customer entities (non-archived) — used for alerts
        $concerned_ids = [];
        // all_scoped_ids: customer + archived entities — used for counters only
        $all_scoped_ids = [];

        if ($parent_id > 0 && !empty($entity_ids)) {
            $customer_sons = getSonsOf('glpi_entities', $parent_id);
            unset($customer_sons[$parent_id]);

            $archive_sons = [];
            if ($archive_entities_id > 0) {
                $archive_sons = getSonsOf('glpi_entities', $archive_entities_id);
                // also include archived sons in the counter scope
                $archive_son_ids = array_keys($archive_sons);
                unset($archive_sons[$archive_entities_id]);
            } else {
                $archive_son_ids = [];
            }

            $excluded_ids = $archive_son_ids; // includes root + all sons

            $concerned_ids = array_values(
                array_diff(
                    array_intersect($entity_ids, array_keys($customer_sons)),
                    $excluded_ids
                )
            );

            $archive_son_ids_in_session = array_values(
                array_intersect($archive_son_ids, $entity_ids)
            );
            $all_scoped_ids = array_values(
                array_unique(array_merge($concerned_ids, $archive_son_ids_in_session))
            );
        }

        $active_states = json_decode($config->fields['contract_states'] ?? '', true);
        $active_states = is_array($active_states) && !empty($active_states)
            ? array_map('intval', $active_states)
            : [];

        // Entities that have at least one active contract (matching contract_states)
        $with_active_contract = [];
        if (!empty($concerned_ids) && !empty($active_states)) {
            $iter = $DB->request([
                'SELECT'     => ['c.entities_id'],
                'DISTINCT'   => true,
                'FROM'       => 'glpi_plugin_manageentities_contractdays AS cd',
                'INNER JOIN' => [
                    'glpi_contracts AS c' => ['FKEY' => ['cd' => 'contracts_id', 'c' => 'id']],
                ],
                'WHERE' => [
                    'c.entities_id' => $concerned_ids,
                    'cd.plugin_manageentities_contractstates_id' => $active_states,
                    'c.is_deleted'  => 0,
                ],
            ]);
            $with_active_contract = array_column(iterator_to_array($iter), 'entities_id');
        }

        // Entities that have at least one subscription
        $with_subscription = [];
        if (!empty($concerned_ids)) {
            $iter = $DB->request([
                'SELECT'   => ['entities_id'],
                'DISTINCT' => true,
                'FROM'     => self::getTable(),
                'WHERE'    => ['entities_id' => $concerned_ids],
            ]);
            $with_subscription = array_column(iterator_to_array($iter), 'entities_id');
        }

        // Alert 1: active contract but no subscription
        $ids_no_sub = array_values(array_diff($with_active_contract, $with_subscription));
        $no_subscription = [];
        if (!empty($ids_no_sub)) {
            $iter = $DB->request([
                'SELECT' => ['completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $ids_no_sub],
                'ORDER'  => ['completename ASC'],
            ]);
            foreach ($iter as $row) {
                $no_subscription[] = $row['completename'];
            }
        }

        // Alert 2: subscription but no active contract, split by history
        $ids_no_contract = array_values(array_diff($with_subscription, $with_active_contract));
        $no_contract = ['had_contract' => [], 'never_contract' => []];
        if (!empty($ids_no_contract)) {
            // Entities that have at least one closed contract (any state)
            $had_iter = $DB->request([
                'SELECT'   => ['c.entities_id'],
                'DISTINCT' => true,
                'FROM'     => 'glpi_plugin_manageentities_contractdays AS cd',
                'INNER JOIN' => [
                    'glpi_contracts AS c' => ['FKEY' => ['cd' => 'contracts_id', 'c' => 'id']],
                ],
                'WHERE' => [
                    'c.entities_id' => $ids_no_contract,
                    'c.is_deleted'  => 0,
                ],
            ]);
            $ids_had_contract = array_column(iterator_to_array($had_iter), 'entities_id');
            $ids_never_contract = array_values(array_diff($ids_no_contract, $ids_had_contract));

            if (!empty($ids_had_contract)) {
                $iter = $DB->request([
                    'SELECT' => ['completename'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => $ids_had_contract],
                    'ORDER'  => ['completename ASC'],
                ]);
                foreach ($iter as $row) {
                    $no_contract['had_contract'][] = $row['completename'];
                }
            }
            if (!empty($ids_never_contract)) {
                $iter = $DB->request([
                    'SELECT' => ['completename'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => $ids_never_contract],
                    'ORDER'  => ['completename ASC'],
                ]);
                foreach ($iter as $row) {
                    $no_contract['never_contract'][] = $row['completename'];
                }
            }
        }

        // Alert 3: entities with an expired subscription, split by type
        $expired_subscription = ['cloud' => [], 'onpremise' => []];
        if (!empty($concerned_ids)) {
            $now = date('Y-m-d');
            $iter = $DB->request([
                'SELECT'   => ['e.completename', 's.cloud_client'],
                'FROM'     => self::getTable() . ' AS s',
                'LEFT JOIN' => [
                    'glpi_entities AS e' => ['FKEY' => ['s' => 'entities_id', 'e' => 'id']],
                ],
                'WHERE'    => [
                    's.entities_id' => $concerned_ids,
                    ['NOT' => ['s.end_date' => null]],
                    ['s.end_date' => ['<', $now . ' 00:00:00']],
                ],
                'ORDER'    => ['e.completename ASC'],
            ]);
            foreach ($iter as $row) {
                if ($row['cloud_client']) {
                    $expired_subscription['cloud'][] = $row['completename'];
                } else {
                    $expired_subscription['onpremise'][] = $row['completename'];
                }
            }
        }

        // Subscriptions count per level (includes archived entities)
        $level_counts = [];
        if (!empty($all_scoped_ids)) {
            $iter = $DB->request([
                'SELECT'   => [
                    'l.name AS level_name',
                    \Glpi\DBAL\QueryFunction::count('s.id', false, 'cnt'),
                ],
                'FROM'     => self::getTable() . ' AS s',
                'LEFT JOIN' => [
                    SubscriptionLevel::getTable() . ' AS l' => [
                        'FKEY' => ['s' => 'plugin_manageentities_subscriptionlevels_id', 'l' => 'id'],
                    ],
                ],
                'WHERE'   => ['s.entities_id' => $all_scoped_ids],
                'GROUPBY' => ['s.plugin_manageentities_subscriptionlevels_id'],
                'ORDER'   => ['cnt DESC'],
            ]);
            foreach ($iter as $row) {
                $level_counts[] = [
                    'name'  => $row['level_name'] ?: __('No level', 'manageentities'),
                    'count' => (int)$row['cnt'],
                ];
            }
        }

        // Subscriptions count by type (cloud vs on-premise, includes archived entities)
        $type_counts = ['cloud' => 0, 'onpremise' => 0];
        if (!empty($all_scoped_ids)) {
            $iter = $DB->request([
                'SELECT' => ['cloud_client'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['entities_id' => $all_scoped_ids],
            ]);
            foreach ($iter as $row) {
                if ($row['cloud_client']) {
                    $type_counts['cloud']++;
                } else {
                    $type_counts['onpremise']++;
                }
            }
        }

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/status_tab.html.twig',
            [
                'no_subscription'      => $no_subscription,
                'no_contract'          => $no_contract,
                'expired_subscription' => $expired_subscription,
                'level_counts'         => $level_counts,
                'type_counts'          => $type_counts,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Display
    // -----------------------------------------------------------------------

    static function showForEntity(\CommonGLPI $item): void
    {
        global $DB;

        $can_edit = Session::getCurrentInterface() !== 'helpdesk'
            && Session::haveRightsOr(self::$rightname, [CREATE, UPDATE]);

        $config = Config::getInstance();

        // Helpdesk: restrict to the user's active entity only
        if (Session::getCurrentInterface() === 'helpdesk') {
            $where = ['s.entities_id' => (int)$_SESSION['glpiactive_entity']];
        } else {
            // Central: scope to customer entities + archived entities
            $parent_id           = (int)($config->fields['wizard_default_entities_id'] ?? 0);
            $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);

            $scoped_ids = [];
            if ($parent_id > 0) {
                $sons = getSonsOf('glpi_entities', $parent_id);
                unset($sons[$parent_id]);
                $scoped_ids = array_keys($sons);
            }
            if ($archive_entities_id > 0) {
                $archive_sons = getSonsOf('glpi_entities', $archive_entities_id);
                unset($archive_sons[$archive_entities_id]);
                $scoped_ids = array_unique(array_merge($scoped_ids, array_keys($archive_sons)));
            }

            $where = !empty($scoped_ids) ? ['s.entities_id' => $scoped_ids] : [];
        }

        $now  = date('Y-m-d');
        $rows = [];

        $iterator = $DB->request([
            'SELECT'   => ['s.*', 'e.completename AS entity_completename'],
            'FROM'     => self::getTable() . ' AS s',
            'LEFT JOIN' => [
                'glpi_entities AS e' => ['FKEY' => ['s' => 'entities_id', 'e' => 'id']],
            ],
            'WHERE'  => $where,
            'ORDER'  => [new QueryExpression('ISNULL(s.end_date) DESC'), 's.end_date ASC', 'e.completename ASC'],
        ]);

        foreach ($iterator as $row) {
            $row['level_name']       = !empty($row['plugin_manageentities_subscriptionlevels_id'])
                ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int)$row['plugin_manageentities_subscriptionlevels_id'])
                : '';
            $row['end_date_expired'] = !empty($row['end_date']) && substr($row['end_date'], 0, 10) < $now;
            $rows[]                  = $row;
        }

        $wizard_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php';
        $export_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php?export=subscriptions';

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/editorsubscription_tab.html.twig',
            [
                'rows'       => $rows,
                'can_edit'   => $can_edit,
                'wizard_url' => $wizard_url,
                'export_url' => $export_url,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Input preparation
    // -----------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if ((int)($input['entities_id'] ?? 0) === 0) {
            Session::addMessageAfterRedirect(
                __('Publisher subscriptions cannot be created for the root entity.', 'manageentities'),
                false,
                ERROR
            );
            return false;
        }
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input);
    }

    private function prepareInput(array $input): array
    {
        foreach (['active_editor_suscription', 'cloud_client', 'internet_publication'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = $input[$flag] ? 1 : 0;
            }
        }
        // Cloud client always implies internet publication
        if (!empty($input['cloud_client'])) {
            $input['internet_publication'] = 1;
        }
        foreach (['begin_date', 'end_date'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }
        return $input;
    }

    // -----------------------------------------------------------------------
    // Propagate booleans to all contracts of the entity
    // -----------------------------------------------------------------------

    public function post_addItem()
    {
        $this->propagateToContracts($this->fields);
    }

    public function post_updateItem($history = true)
    {
        $this->propagateToContracts($this->fields);
    }

    private function propagateToContracts(array $fields): void
    {
        global $DB;

        $entities_id               = (int)($fields['entities_id'] ?? 0);
        $active_editor_suscription = (int)($fields['active_editor_suscription'] ?? 0);
        $cloud_client              = (int)($fields['cloud_client'] ?? 0);

        if ($entities_id <= 0) {
            return;
        }

        $DB->update(
            'glpi_plugin_manageentities_contracts',
            [
                'active_editor_suscription' => $active_editor_suscription,
                'cloud_client'              => $cloud_client,
            ],
            ['entities_id' => $entities_id]
        );
    }

    /**
     * @return array
     */
    function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id' => 'common',
            'name' => self::getTypeName(2)
        ];

        $tab[] = [
            'id' => '1',
            'table' => $this->getTable(),
            'field' => 'name',
            'name' => __('Name'),
            'datatype' => 'itemlink',
            'itemlink_type' => $this->getType(),
        ];

        $tab[] = [
            'id' => '4',
            'table' => $this->getTable(),
            'field' => 'begin_date',
            'name' => __('Begin date'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id' => '5',
            'table' => $this->getTable(),
            'field' => 'end_date',
            'name' => __('End date'),
            'datatype' => 'date',
        ];


        $tab[] = [
            'id' => '8',
            'table' => $this->getTable(),
            'field' => 'comment',
            'name' => __('Comments'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id' => '9',
            'table' => $this->getTable(),
            'field' => 'customer_account_id',
            'name' => __('Publisher customer account ID', 'manageentities'),
//            'datatype' => 'text'
        ];

        $tab[] = [
            'id' => '10',
            'table' => 'glpi_plugin_manageentities_subscriptionlevels',
            'field' => 'name',
            'name' => __('Subscription level', 'manageentities'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '11',
            'table' => $this->getTable(),
            'field' => 'active_editor_suscription',
            'name' => __('Editor subscription', 'manageentities'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id' => '12',
            'table' => $this->getTable(),
            'field' => 'cloud_client',
            'name' => __('Cloud client', 'manageentities'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id' => '13',
            'table' => $this->getTable(),
            'field' => 'internet_publication',
            'name' => __('Internet publication', 'manageentities'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id' => '30',
            'table' => $this->getTable(),
            'field' => 'id',
            'name' => __('ID'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id' => '80',
            'table' => 'glpi_entities',
            'field' => 'completename',
            'name' => _n('Entity', 'Entities', 1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id' => '81',
            'table' => 'glpi_entities',
            'field' => 'entities_id',
            'name' => _n('Entity', 'Entities', 1) . "-" . __('ID'),
        ];

        $tab[] = [
            'id' => '82',
            'table' => $this->getTable(),
            'field' => 'plugin_manageentities_subscriptionlevels_id',
            'name' => __('Subscription level', 'manageentities') . "-" . __('ID'),
        ];

        return $tab;
    }
}
