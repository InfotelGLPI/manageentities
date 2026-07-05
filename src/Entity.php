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

use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use GlpiPlugin\Accounts\Account;
use GlpiPlugin\Accounts\Account_Item;
use Html;
use Plugin;
use Session;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contact;
use GlpiPlugin\Manageentities\Contract;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Entity extends CommonGLPI
{

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 0)
    {
        return _n('Client management', 'Clients management', $nb, 'manageentities');
    }

    static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(__CLASS__, $ong, $options);

        return $ong;
    }


    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == __CLASS__) {
            $followUp = new Followup();
            $monthly = new Monthly();
            $gantt = new Gantt();
            $Cri = new Cri();
            $config = new Config();

            if ($followUp->canView()) {
                $tabs[1] = Followup::createTabEntry(__('General follow-up', 'manageentities'));
            }

            if ($monthly->canView() && Session::getCurrentInterface() == 'central') {
                $tabs[2] = Monthly::createTabEntry(__('Monthly follow-up', 'manageentities'));
            }

            if ($gantt->canView()) {
                $tabs[3] = Gantt::createTabEntry(__('GANTT'));
            }

            $tabs[4] = self::createTabEntry(__('Data administrative', 'manageentities'));

            if (Session::haveRight("contract", READ)) {
                $tabs[5] = Contract::createTabEntry(_n('Contract', 'Contracts', 2));
            }

//          if (Session::getCurrentInterface() != 'helpdesk') {
//            $tabs[6] = self::createTabEntry(__('Client planning', 'manageentities'));
//         }

            // ajout de la configuration du plugin
            $config = Config::getInstance();
            if ((Session::getCurrentInterface() == 'central')
                || (Session::getCurrentInterface() == 'helpdesk'
                    && $config->fields['choice_intervention'] == Config::REPORT_INTERVENTION)) {
                if ($Cri->canView()) {
                    $tabs[7] = CriDetail::createTabEntry(
                        __("Interventions reports", 'manageentities')
                    );
                }
            } elseif (Session::getCurrentInterface() == 'helpdesk'
                && $config->fields['choice_intervention'] == Config::PERIOD_INTERVENTION) {
                $tabs[7] = CriDetail::createTabEntry(
                    _n('Period of contract', 'Periods of contract', 2, 'manageentities')
                );
            }

            if (Session::haveRight("document", UPDATE)) {
                $tabs[8] = Document::createTabEntry(_n('Document', 'Documents', 2));
            }

            if (Plugin::isPluginActive('accounts')) {
                if (Session::haveRight("plugin_accounts", READ)) {
                    $tabs[10] = Account::createTabEntry(__('Accounts', 'manageentities'));
                }
            }

            if (Session::getCurrentInterface() != 'helpdesk' && $this->canview()) {
                $tabs[11] = self::createTabEntry(__('References', 'manageentities'));
            }
            return $tabs;
        }
        return '';
    }


    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == __CLASS__) {
            $ManageentitiesEntity = new Entity();
            $Contract = new Contract();
            $CriDetail = new CriDetail();
            $followUp = new Followup();
            $monthly = new Monthly();
            $entity = new \Entity();

            if (Session::getCurrentInterface() != 'helpdesk') {
                $entities = $_SESSION["glpiactiveentities"];
            } else {
                $entities = [$_SESSION["glpiactive_entity"]];
            }
            switch ($tabnum) {
                case 1:
                    $followUp->showCriteriasForm($_GET);
                    Followup::showFollowUp($_GET);

                    $direct = new DirectHelpdesk();
                    if (Session::getCurrentInterface() == 'helpdesk') {
                        if ($items = $direct->find(['is_billed' => 0, 'entities_id' => $entities], ['date'])) {
                            echo "<h4 style='margin-top: 10px;margin-bottom: 20px;'>";
                            echo DirectHelpdesk::getTypeName(2);
                            echo "</h4>";
                            DirectHelpdesk::showDashboard();
                            DirectHelpdesk_Ticket::selectDirectHeldeskForTicket($entities);
                        }
                    }
                    break;
                case 2:
                    $monthly->showHeader($_GET);
                    Monthly::showMonthly($_GET);
                    break;
                case 3:
                    Gantt::showGantt($_GET);
                    break;
                case 4:
                    $ManageentitiesEntity->showDescription($entities);
                    break;
                case 5:
                    $Contract->showContracts($entities);
                    break;
                case 6:
//               $ManageentitiesEntity->showTickets($entities);
                    break;
                case 7:
                    $config = Config::getInstance();
                    if ((Session::getCurrentInterface() == 'central')
                        || (Session::getCurrentInterface() == 'helpdesk'
                            && $config->fields['choice_intervention'] == Config::REPORT_INTERVENTION)) {
                        $CriDetail->showReports(
                            0,
                            0,
                            $entities,
                            ['glpi_plugin_manageentities_contractstates.is_closed' => ['<>', 1]]
                        );
                    } elseif (Session::getCurrentInterface() == 'helpdesk'
                        && $config->fields['choice_intervention'] == Config::PERIOD_INTERVENTION) {
                        $CriDetail->showPeriod(0, 0, $entities);
                    }

                    break;
                case 8:
                    foreach ($entities as $entity_id) {
                        $entity->getFromDB($entity_id);
                        Document_Item::showForItem($entity);
                    }
                    break;
                case 10:
                    foreach ($entities as $entity_id) {
                        $entity->getFromDB($entity_id);
                        Account_Item::showForAsset($entity);
                    }
                    break;
                case 11:
                    $ManageentitiesEntity->showReferences($entities);
                    break;
                default:
                    break;
            }
        }
        return true;
    }

    // Hook done on before update document - keeps document date if it's a CRI
    static function preUpdateDocument($item)
    {
        // Manipulate data if needed
        $config = new Config();

        if ($item->getField('id') && $config->GetfromDB(1)) {
            $_SESSION["glpi_plugin_manageentities_date_mod"] = $item->getField("date_mod");

            if ($config->fields["documentcategories_id"] != $item->getField("documentcategories_id")) {
                $_SESSION["glpi_plugin_manageentities_date_mod"] = $_SESSION["glpi_currenttime"];
            }
        }
    }

    // Hook done on after update document - change document date if it's not a CRI

    static function UpdateDocument($item)
    {
        global $DB;

        $config = new Config();
        if ($item->getField('id')
            && $config->GetfromDB(1)) {
            $doc = new Document();
            $doc->update([
                'id' => $item->getField('id'),
                'date_mod' => $_SESSION["glpi_plugin_manageentities_date_mod"]
            ]);
        }

        return true;
    }

    static function showManageentitiesHeader($subtitle = '')
    {
        echo "<h3><div class='alert alert-secondary' role='alert'>";
        echo __('Portal', 'manageentities') . " " . $_SESSION["glpiactive_entity_name"];
        echo '<br/>' . $subtitle;
        echo "</div></h3>";
    }

    function showDescription($entities)
    {
        global $CFG_GLPI;

        $contact         = new Contact();
        $businessContact = new BusinessContact();
        $entityObj       = new \Entity();
        $can_edit        = (Session::getCurrentInterface() !== 'helpdesk');
        $is_single       = (count($entities) === 1);
        $interface       = Session::getCurrentInterface();

        $entity_form_url   = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.form.php';
        $entity_action_url = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php';

        // Build one entry per entity for the left column
        $entities_data = [];
        foreach ($entities as $instID) {
            $entityObj->getFromDB($instID);
            $f = $entityObj->fields;

            $logos = [];
            foreach ((new EntityLogo())->find(['entities_id' => $f['id']]) as $logo) {
                $logos[] = $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . $logo['logos_id'];
            }

            $file_input_html = '';
            if ($can_edit) {
                ob_start();
                Html::file();
                $file_input_html = ob_get_clean();
            }

            $entities_data[] = [
                'entity_id'           => $f['id'],
                'entity_name'         => $f['name'],
                'entity_completename' => $f['completename'],
                'entity_comment'      => $f['comment'] ?? '',
                'entity_phonenumber'  => $f['phonenumber'] ?? '',
                'entity_fax'          => $f['fax'] ?? '',
                'entity_website'      => $f['website'] ?? '',
                'entity_email'        => $f['email'] ?? '',
                'entity_address'      => $f['address'] ?? '',
                'entity_postcode'     => $f['postcode'] ?? '',
                'entity_town'         => $f['town'] ?? '',
                'entity_state'        => $f['state'] ?? '',
                'entity_country'      => $f['country'] ?? '',
                'is_root_entity'      => ($instID == 0),
                'logos'               => $logos,
                'file_input_html'     => $file_input_html,
                'max_upload'          => Document::getMaxUploadSize(),
            ];
        }

        // Contacts and business are fetched once for ALL entities (like the original)
        $contacts_data = $contact->buildContactsForTemplate($entities, $CFG_GLPI['root_doc']);
        $business_data = $businessContact->buildBusinessForTemplate($entities, $CFG_GLPI['root_doc']);

        $contact_dropdown_html = '';
        $user_dropdown_html    = '';
        if ($can_edit && $is_single) {
            ob_start();
            \Dropdown::show('Contact', ['name' => 'contacts_id']);
            $contact_dropdown_html = ob_get_clean();

            ob_start();
            \User::dropdown(['right' => 'interface']);
            $user_dropdown_html = ob_get_clean();
        }

        TemplateRenderer::getInstance()->display(
            '@manageentities/entity/description.html.twig',
            [
                'entities_data'        => $entities_data,
                'can_edit'             => $can_edit,
                'is_single'            => $is_single,
                'interface'            => $interface,
                'entity_form_url'      => $entity_form_url,
                'entity_action_url'    => $entity_action_url,
                'contact_form_url'     => $CFG_GLPI['root_doc'] . '/front/contact.form.php',
                'user_form_url'        => $CFG_GLPI['root_doc'] . '/front/user.form.php',
                'contacts'             => $contacts_data,
                'business'             => $business_data,
                'can_edit_contacts'    => $contact->canCreate(),
                'can_edit_business'    => $businessContact->canCreate(),
                'contact_dropdown_html'=> $contact_dropdown_html,
                'user_dropdown_html'   => $user_dropdown_html,
                // For the add-contact/business form, use the first (or only) entity id
                'entity_id'            => $entities[array_key_first($entities)] ?? 0,
            ]
        );
    }


    static function getMenuContent()
    {
        $menu = [];
        //Menu entry in tools
        $menu['title'] = self::getTypeName(2);
        $menu['page'] = self::getSearchURL(false);
        $menu['links']['search'] = self::getSearchURL(false);
        if (Session::haveRightsOr("plugin_manageentities", [CREATE, UPDATE]) || Session::haveRight("config", UPDATE)) {
            //Entry icon in breadcrumb
            $menu['links']['config'] = Config::getFormURL(false);
            //Link to config page in admin plugins list
            $menu['config_page'] = Config::getFormURL(false);
            $menu['links']['add'] = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/addelements.form.php';
        }

        $menu['options']['contractday']['title'] = ContractDay::getTypeName(2);
        $menu['options']['contractday']['page'] = ContractDay::getSearchURL(false);
        $menu['options']['contractday']['search'] = ContractDay::getSearchURL(false);
        $menu['options']['contractday']['links']['search'] = ContractDay::getSearchURL(false);

        $menu['options']['company']['title'] = Company::getTypeName(2);
        $menu['options']['company']['page'] = Company::getSearchURL(false);
        $menu['options']['company']['add'] = Company::getFormURL(false);
        $menu['options']['company']['links']['add'] = Company::getFormURL(false);
        $menu['options']['company']['search'] = Company::getSearchURL(false);
        $menu['options']['company']['links']['search'] = Company::getSearchURL(false);
        $menu['icon'] = self::getIcon();

        $menu['icon'] = self::getIcon();

        return $menu;
    }


    function getRights($interface = 'central')
    {
        $values = [
            CREATE => __('Create'),
            READ => __('Read'),
            UPDATE => __('Update'),
            PURGE => [
                'short' => __('Purge'),
                'long' => _x('button', 'Delete permanently')
            ]
        ];

        return $values;
    }

    function showReferences($instID)
    {
        global $DB, $CFG_GLPI;

        $entity = new \Entity();
        $entity->getFromDB($_SESSION["glpiactive_entity"]);

        self::showManageentitiesHeader(__('References', 'manageentities'));

        echo "<table class='tab_cadre' width='60%'>";

        $iterator = $DB->request([
            'SELECT' => [
                'entities_id',
//               ['MIN' => 'date_signature AS signature'],
                QueryFunction::min('date_signature', 'signature'),
                QueryFunction::year('date_signature', 'year')
            ],
            'FROM' => 'glpi_plugin_manageentities_contracts',
            'WHERE' => [
                'NOT' => ['date_signature' => null],
//               'entities_id'  => $instID
            ],
            'GROUPBY' => 'entities_id',
            'ORDERBY' => 'year DESC'
        ]);

        $year = "";
        $debug = [];
        $entity_logo = new EntityLogo();
        $entity = new \Entity();
        $i = 0;

        foreach ($iterator as $data) {
            if ($entity->getFromDB($data['entities_id'])) {
                $debug[$data['entities_id']] = [
                    'name' => $entity->getName(),
                    'signature' => $data['signature']
                ];

                if (empty($year) || $year != $data['year']) {
                    $year = $data['year'];
                    if ($i % 2 != 0) {
                        echo "<td colspan='2'></td>";
                        echo "</tr>";
                    }

                    $i = 0;

                    echo "<tr>";
                    echo "<th colspan='4'>" . $data['year'] . "</th>";
                    echo "</tr>";
                }

                if ($i % 2 == 0) {
                    echo "<tr>";
                }

                echo "<td>" . $entity->getName() . "</td>";

                if ($logos = $entity_logo->find(['entities_id' => $data['entities_id']])) {
                    echo "<td>";
                    foreach ($logos as $logo) {
                        echo "<img height='50px' alt=\"" . __s('Picture') . "\"
                src='" . $CFG_GLPI["root_doc"] . "/front/document.send.php?docid=" . $logo["logos_id"] . "'>";
                    }

                    echo "</td>";
                } else {
                    echo "<td></td>";
                }

                $i++;
                if ($i % 2 == 0) {
                    echo "</tr>";
                }
            }
        }
        if ($i % 2 != 0) {
            echo "<td colspan='2'></td>";
            echo "</tr>";
        }
        echo "</table>";

        if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
            echo "<br><table class='tab_cadre'>";
            echo "<tr>";
            echo "<th colspan='2'>" . __('DEBUG') . "</th>";
            echo "</tr>";

            echo "<tr>";
            echo "<th>" . __('Entity') . "</th>";
            echo "<th>" . __('Date of signature', 'manageentities') . "</th>";
            echo "</tr>";


            if (count($debug) > 0) {
                foreach ($debug as $client) {
                    echo "<tr class='tab_bg_1'>";
                    echo "<td>" . $client['name'] . "</td>";

                    echo "<td>" . Html::convDate($client['signature']) . "</td>";
                    echo "</tr>";
                }
            }
            echo "</table>";
        }
    }
}
