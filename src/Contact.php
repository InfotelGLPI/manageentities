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
use Glpi\Application\View\TemplateRenderer;
use Html;
use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Contact extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    /**
     * Add a contact ba default
     *
     * @param type $contacts_id
     * @param type $entities_id
     *
     * @global type $DB
     *
     */
    function addContactByDefault($contacts_id, $entities_id)
    {
        $contacts = $this->find(['entities_id' => $entities_id]);

        if (count($contacts) > 0) {
            foreach ($contacts as $data) {
                $this->update(['is_default' => 0, 'id' => $data["id"]]);
            }
        }
        $this->update(['is_default' => 1, 'id' => $contacts_id]);
    }

    /**
     *
     * @param type $instID
     *
     * @global type $CFG_GLPI
     *
     * @global type $DB
     */
    function buildContactsForTemplate(array $instID, string $root_doc): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'   => [
                'glpi_contacts.*',
                $this->getTable() . '.contacts_id',
                $this->getTable() . '.is_default',
            ],
            'FROM'     => $this->getTable(),
            'LEFT JOIN' => [
                'glpi_contacts' => [
                    'ON' => [
                        $this->getTable() => 'contacts_id',
                        'glpi_contacts'   => 'id',
                    ],
                ],
            ],
            'WHERE'   => [
                'glpi_contacts.is_deleted'          => 0,
                $this->getTable() . '.entities_id'  => $instID,
            ],
            'ORDERBY' => ['glpi_contacts.name'],
        ]);

        $contacts = [];
        foreach ($iterator as $data) {
            $contacts[] = [
                'link_id'      => $data['contacts_id'],
                'url'          => $root_doc . '/front/contact.form.php?id=' . $data['id'],
                'name'         => htmlspecialchars($data['name']),
                'firstname'    => htmlspecialchars($data['firstname']),
                'phone'        => $data['phone'] ?? '',
                'mobile'       => $data['mobile'] ?? '',
                'email'        => $data['email'] ?? '',
                'contact_type' => \Dropdown::getDropdownName('glpi_contacttypes', $data['contacttypes_id']),
                'is_default'   => (bool)$data['is_default'],
            ];
        }
        return $contacts;
    }

    function showContacts($instID)
    {
        global $CFG_GLPI;

        $can_edit  = $this->canCreate();
        $is_single = count($instID) === 1;
        $interface = Session::getCurrentInterface();

        $entity_action_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php';

        $contacts = $this->buildContactsForTemplate($instID, $CFG_GLPI['root_doc']);

        $contact_dropdown_html = '';
        if ($can_edit && $is_single) {
            ob_start();
            \Dropdown::show('Contact', ['name' => 'contacts_id']);
            $contact_dropdown_html = ob_get_clean();
        }

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/contacts_card.html.twig',
            [
                'entity_id'            => $_SESSION['glpiactive_entity'],
                'entity_action_url'    => $entity_action_url,
                'contact_form_url'     => $CFG_GLPI['root_doc'] . '/front/contact.form.php',
                'contacts'             => $contacts,
                'can_edit_contacts'    => $can_edit,
                'is_single'            => $is_single,
                'interface'            => $interface,
                'contact_dropdown_html'=> $contact_dropdown_html,
            ]
        );
    }
}
