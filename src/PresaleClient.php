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
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Migration;

/**
 * Prospect of the pre-sales section (step 1 of the wizard, with its contacts). Kept apart from the GLPI entities
 * until a proposal is won: the new customer wizard is then prefilled from it, and the created
 * entity is stored in converted_entities_id. A prospect whose proposals were all lost is
 * archived (hidden), never deleted, so that it can be reused.
 */
class PresaleClient extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities_presale';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Prospect', 'Prospects', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-building-store';
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
            'links' => [
                'search' => self::getSearchURL(false),
                'add'    => PresaleWizard::getFormUrl() . '?step=' . PresaleWizard::STEP_CLIENT,
            ],
        ];
    }

    /**
     * The archived prospects (all their proposals lost) are hidden by default
     */
    public static function getDefaultSearchRequest(): array
    {
        return [
            'criteria' => [
                [
                    'field'      => 9, // Archived
                    'searchtype' => 'equals',
                    'value'      => 0,
                ],
            ],
            'sort'     => 1,
            'order'    => 'ASC',
        ];
    }

    public function rawSearchOptions()
    {
        $tab   = parent::rawSearchOptions();
        $table = $this->getTable();

        $tab[] = [
            'id'            => '2',
            'table'         => $table,
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];
        foreach ([
            3 => ['town', __('City')],
            4 => ['postcode', __('Postal code')],
            5 => ['phonenumber', __('Phone')],
            6 => ['email', __('Email')],
        ] as $id => [$field, $label]) {
            $tab[] = [
                'id'       => (string) $id,
                'table'    => $table,
                'field'    => $field,
                'name'     => $label,
                'datatype' => 'string',
            ];
        }
        $tab[] = [
            'id'            => '7',
            'table'         => PresaleContact::getTable(),
            'field'         => 'name',
            'name'          => __('Main contact', 'manageentities'),
            'datatype'      => 'itemlink',
            'forcegroupby'  => true,
            'massiveaction' => false,
            'joinparams'    => [
                'jointype'  => 'child',
                'condition' => ['NEWTABLE.is_default' => 1],
            ],
        ];
        $tab[] = [
            'id'            => '8',
            'table'         => PresaleProposal::getTable(),
            'field'         => 'id',
            'name'          => _n('Financial proposal', 'Financial proposals', 2, 'manageentities'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'child'],
        ];
        $tab[] = [
            'id'            => '9',
            'table'         => $table,
            'field'         => 'is_archived',
            'name'          => __('Archived', 'manageentities'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '10',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'linkfield'     => 'converted_entities_id',
            'name'          => __('Existing customer', 'manageentities'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
            // The root entity has id 0 too: no join for a prospect not converted
            'joinparams'    => ['condition' => ['REFTABLE.converted_entities_id' => ['>', 0]]],
        ];
        $tab[] = [
            'id'            => '19',
            'table'         => $table,
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '121',
            'table'         => $table,
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(\Document_Item::class, $ong, $options);
        $this->addStandardTab(\Log::class, $ong, $options);

        return $ong;
    }

    /**
     * Details, contacts and proposals of the prospect. The details are edited here; the
     * contacts with a new proposal (step 1 of the wizard).
     */
    public function showForm($ID, array $options = [])
    {
        global $CFG_GLPI;

        $this->initForm($ID, $options);

        $contacts = (new PresaleContact())->find(
            ['plugin_manageentities_presaleclients_id' => $ID],
            ['is_default DESC', 'name'],
        );
        $converted = (int) $this->fields['converted_entities_id'];
        // A new proposal starts from this prospect, or from its customer entity
        $new_url = PresaleWizard::getFormUrl() . '?step=' . PresaleWizard::STEP_CLIENT . '&'
            . ($converted > 0 ? 'entities_id=' . $converted : 'prospects_id=' . $ID);

        TemplateRenderer::getInstance()->display('@manageentities/presale/client_form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'contacts'     => array_values($contacts),
            'open'         => PresaleWizard::getOpenProposals([PresaleProposal::getTable() . '.plugin_manageentities_presaleclients_id' => $ID]),
            'others'       => $this->getClosedProposals(),
            'action_url'   => PresaleProposal::canUpdate() ? PresaleWizard::getActionUrl() : '',
            'can_purge'    => PresaleProposal::canPurge(),
            'new_url'      => PresaleWizard::canUse() && !$this->fields['is_archived'] ? $new_url : '',
            'entity_name'  => $converted > 0 ? \Dropdown::getDropdownName('glpi_entities', $converted) : '',
            'entity_url'   => $converted > 0 ? \Entity::getFormURLWithID($converted) : '',
            'logo_url'     => !empty($this->fields['logo_documents_id'])
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $this->fields['logo_documents_id']
                : '',
        ]);

        return true;
    }

    /**
     * Won and lost proposals of the prospect, last changed first
     *
     * @return list<array{name: string, url: string, status: string, date_mod: string}>
     */
    private function getClosedProposals(): array
    {
        $list = [];
        foreach ((new PresaleProposal())->find([
            'plugin_manageentities_presaleclients_id' => $this->getID(),
            'NOT'                                     => ['status' => PresaleProposal::getOpenStatuses()],
        ], ['date_mod DESC']) as $row) {
            $list[] = [
                'name'     => $row['name'],
                'url'      => PresaleProposal::getFormURLWithID((int) $row['id']),
                'status'   => PresaleProposal::getStatuses()[(int) $row['status']] ?? '',
                'date_mod' => (string) $row['date_mod'],
            ];
        }

        return $list;
    }

    /**
     * The details are typed as in the wizard: same checks, same formatting
     */
    public function prepareInputForUpdate($input)
    {
        $fields = ['name', 'address', 'postcode', 'town', 'state', 'country', 'phonenumber', 'email', 'website'];
        if (array_intersect_key($input, array_flip($fields)) === []) {
            return $input;
        }
        $values = array_map(
            static fn($value) => trim((string) $value),
            array_intersect_key($input, array_flip($fields)) + array_intersect_key($this->fields, array_flip($fields)),
        );
        $errors = PresaleWizard::validateClient($values);
        if ($errors !== []) {
            foreach ($errors as $message) {
                \Session::addMessageAfterRedirect($message, false, ERROR);
            }
            return false;
        }
        foreach (array_intersect_key($values, $input) as $field => $value) {
            $input[$field] = $value;
        }
        if (isset($input['phonenumber'])) {
            $input['phonenumber'] = WizardController::formatPhoneNumber($input['phonenumber']);
        }
        if (isset($input['website'])) {
            $input['website'] = WizardController::formatWebsite($input['website']);
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            PresaleContact::class,
        ]);
        // The proposals are not children of the prospect (CommonDBTM): purge them one by one
        // so that each one cleans its own annexes and lines
        (new PresaleProposal())->deleteByCriteria([
            'plugin_manageentities_presaleclients_id' => $this->getID(),
        ], true);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
                            `is_recursive` tinyint NOT NULL DEFAULT '0',
                            `name` varchar(255) DEFAULT NULL,
                            `address` text,
                            `postcode` varchar(255) DEFAULT NULL,
                            `town` varchar(255) DEFAULT NULL,
                            `state` varchar(255) DEFAULT NULL,
                            `country` varchar(255) DEFAULT NULL,
                            `phonenumber` varchar(255) DEFAULT NULL,
                            `email` varchar(255) DEFAULT NULL,
                            `website` varchar(255) DEFAULT NULL,
                            `logo_documents_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id)',
                            `comment` text,
                            `converted_entities_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — entity created once a proposal is won',
                            `is_archived` tinyint NOT NULL DEFAULT '0' COMMENT 'all its proposals were lost',
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author',
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            KEY `name` (`name`),
                            KEY `entities_id` (`entities_id`),
                            KEY `is_recursive` (`is_recursive`),
                            KEY `logo_documents_id` (`logo_documents_id`),
                            KEY `converted_entities_id` (`converted_entities_id`),
                            KEY `is_archived` (`is_archived`),
                            KEY `users_id` (`users_id`),
                            KEY `date_creation` (`date_creation`),
                            KEY `date_mod` (`date_mod`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Default columns of the list: town, main contact, proposals, existing customer, update
        if (countElementsInTable('glpi_displaypreferences', ['itemtype' => self::class, 'users_id' => 0]) === 0) {
            foreach ([3, 7, 8, 10, 19] as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype'  => self::class,
                    'num'       => $num,
                    'rank'      => $rank + 1,
                    'users_id'  => 0,
                    'interface' => 'central',
                ]);
            }
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
