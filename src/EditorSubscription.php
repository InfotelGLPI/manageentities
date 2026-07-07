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
        if ($item instanceof Entity) {
            return self::createTabEntry(__('Publisher information', 'manageentities'), 0, $item::class, self::getIcon());
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Entity) {
            self::showForEntity($item);
        }
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
    // Display
    // -----------------------------------------------------------------------

    static function showForEntity(Entity $item): void
    {
        global $DB;

        $can_edit = Session::getCurrentInterface() !== 'helpdesk'
            && Session::haveRightsOr(self::$rightname, [CREATE, UPDATE]);

        // Fetch all subscriptions for entities visible in the current session
        if (Session::getCurrentInterface() === 'helpdesk') {
            $entity_ids = [$_SESSION['glpiactive_entity']];
        } else {
            $entity_ids = $_SESSION['glpiactiveentities'];
        }

        // Exclude archived entities (wizard_archive_entities_id config)
        $config              = Config::getInstance();
        $archive_entities_id = (int)($config->fields['wizard_archive_entities_id'] ?? 0);
        if ($archive_entities_id > 0) {
            $archive_sons = getSonsOf('glpi_entities', $archive_entities_id);
            unset($archive_sons[$archive_entities_id]);
            $entity_ids = array_values(array_diff($entity_ids, array_keys($archive_sons)));
        }

        $now  = date('Y-m-d');
        $rows = [];

        if (!empty($entity_ids)) {
            $iterator = $DB->request([
                'SELECT' => ['s.*', 'e.completename AS entity_completename'],
                'FROM'   => self::getTable() . ' AS s',
                'LEFT JOIN' => [
                    'glpi_entities AS e' => ['FKEY' => ['s' => 'entities_id', 'e' => 'id']],
                ],
                'WHERE'  => ['s.entities_id' => $entity_ids],
                'ORDER'  => ['e.completename ASC'],
            ]);

            foreach ($iterator as $row) {
                $row['level_name']       = !empty($row['plugin_manageentities_subscriptionlevels_id'])
                    ? Dropdown::getDropdownName(SubscriptionLevel::getTable(), (int)$row['plugin_manageentities_subscriptionlevels_id'])
                    : '';
                $row['end_date_expired'] = !empty($row['end_date']) && substr($row['end_date'], 0, 10) < $now;
                $rows[]                  = $row;
            }
        }

        $wizard_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/editorsubscription.form.php';

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/editorsubscription_tab.html.twig',
            [
                'rows'        => $rows,
                'can_edit'    => $can_edit,
                'wizard_url'  => $wizard_url,
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Input preparation
    // -----------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
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
