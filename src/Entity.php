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

use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryFunction;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Html;
use Migration;
use Notification;
use Notification_NotificationTemplate;
use NotificationTemplate;
use NotificationTemplateTranslation;
use Plugin;
use Session;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contact;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\EditorSubscription;

class Entity extends CommonGLPI
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('Client management', 'Clients management', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(__CLASS__, $ong, $options);

        return $ong;
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == __CLASS__) {
            // Every entry below is conditional, so the array has to exist up-front: it used to
            // be created by the administrative data tab, which was the only unconditional one.
            $tabs = [];

            $followUp = new Followup();
            $monthly = new Monthly();
            $gantt = new Gantt();
            $Cri = new Cri();
            $config = new Config();

            // Same perimeter displayTabContentForItem() feeds the renderers with. Two tabs below
            // render one block per entity of that perimeter and are unusably slow on a parent
            // entity, where it spans the whole subtree: they are offered on a single entity only.
            $entities = Session::getCurrentInterface() != 'helpdesk'
                ? $_SESSION["glpiactiveentities"]
                : [$_SESSION["glpiactive_entity"]];
            $is_single_entity = (count($entities) === 1);

            if ($followUp->canView()) {
                $tabs[1] = Followup::createTabEntry(
                    Session::getCurrentInterface() == 'helpdesk'
                        ? __('Contractual follow-up', 'manageentities')
                        : __('General follow-up', 'manageentities'),
                );
            }

            if ($monthly->canView() && Session::getCurrentInterface() == 'central') {
                $tabs[2] = Monthly::createTabEntry(__('Monthly follow-up', 'manageentities'));
            }

            if ($gantt->canView()) {
                $tabs[3] = Gantt::createTabEntry(__('GANTT'));
            }

            // showDescription() issues two queries and one Html::file() capture per entity, so a
            // parent entity multiplies the cost by the size of its subtree for a page that is
            // only ever read one customer at a time.
            if ($is_single_entity) {
                $tabs[4] = self::createTabEntry(__('Data administrative', 'manageentities'));
            }

            if (Session::haveRight("contract", READ)) {
                $tabs[5] = Contract::createTabEntry(_n('Contract', 'Contracts', 2));
            }

            if (Config::useEditorSubscriptions()) {
                $tabs[6] = EditorSubscription::createTabEntry(
                    _n('Publisher subscription', 'Publisher subscriptions', 2, 'manageentities'),
                    0,
                    self::class,
                    EditorSubscription::getIcon(),
                );
            }

            if (Session::getCurrentInterface() == 'central' && Config::useEditorSubscriptions()) {
                $tabs[7] = self::createTabEntry(
                    __('Contracts status overview', 'manageentities'),
                    0,
                    self::class,
                    'ti ti-clipboard-check',
                );
            }

            // Central only, like the contracts status overview above: this is a provider-side
            // management view, not something a customer reads from the simplified interface.
            if (Session::getCurrentInterface() == 'central'
                && Session::haveRight(DirectHelpdesk::$rightname, READ)) {
                $tabs[13] = DirectHelpdesk::createTabEntry(
                    __('Unplanned interventions', 'manageentities'),
                    0,
                    self::class,
                    DirectHelpdesk::getIcon(),
                );
            }

            // ajout de la configuration du plugin
            // Same reasoning as the administrative data tab above: showReports() switches to its
            // tree mode as soon as the perimeter holds more than one entity and then lists every
            // intervention document of the whole subtree, which is what makes it slow.
            $config = Config::getInstance();
            if ($is_single_entity) {
                if ((Session::getCurrentInterface() == 'central')
                    || (Session::getCurrentInterface() == 'helpdesk'
                        && $config->fields['choice_intervention'] == Config::REPORT_INTERVENTION)) {
                    if ($Cri->canView()) {
                        $tabs[8] = CriDetail::createTabEntry(
                            __("Interventions reports", 'manageentities'),
                        );
                    }
                } elseif (Session::getCurrentInterface() == 'helpdesk'
                    && $config->fields['choice_intervention'] == Config::PERIOD_INTERVENTION) {
                    $tabs[8] = CriDetail::createTabEntry(
                        _n('Period of contract', 'Periods of contract', 2, 'manageentities'),
                    );
                }
            }

            if (Session::haveRight("document", UPDATE)) {
                $tabs[9] = Document::createTabEntry(_n('Document', 'Documents', 2));
            }

            if (Session::getCurrentInterface() != 'helpdesk' && $this->canview()) {
                $tabs[12] = self::createTabEntry(__('References', 'manageentities'));
            }

            return $tabs;
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == __CLASS__) {
            // Security (authorization bypass): this class extends CommonGLPI, whose
            // $get_item_to_display_tab is false, so ajax/common.tabs.php skips the
            // can($id, READ) it performs for CommonDBTM items, and displayStandardTab()
            // forwards any requested $tabnum without ever checking that this tab was
            // offered. Every right of this tab set used to live in getTabNameForItem()
            // alone, that is, in the menu builder, so a forged _glpi_tab reached the
            // content of any tab with no right check at all.
            // Both guards below replay existing rules rather than restating them: the
            // first one is the guard of front/entity.php, the only page rendering these
            // tabs, and the second one asks the menu builder itself whether the tab is
            // currently offered, which enforces every per-tab condition exactly once and
            // cannot drift from the menu.
            if (!self::canView() && !Session::haveRight('config', UPDATE)) {
                throw new AccessDeniedHttpException();
            }

            $tabs = $item->getTabNameForItem($item, $withtemplate);
            if (!is_array($tabs) || !isset($tabs[(int) $tabnum])) {
                throw new AccessDeniedHttpException();
            }

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
                    if (Session::getCurrentInterface() == 'helpdesk') {
                        $direct = new DirectHelpdesk();
                        $items  = $direct->find(['is_billed' => 0, 'entities_id' => $entities], ['date']);

                        // Display the two panels side by side: current contracts on the
                        // left, unbilled interventions (gauge above the table) on the right.
                        echo "<div class='row g-3'>";

                        echo "<div class='col-12 " . ($items ? "col-md-6" : "") . "'>";
                        echo "<div class='card h-100'>";
                        echo "<div class='card-header' style='background-color: var(--tblr-primary-fg);'>";
                        echo "<h4 class='mb-3'>" . htmlspecialchars(__('Current contracts', 'manageentities')) . "</h4>";
                        echo "</div>";
                        echo "<div class='card-body'>";
                        Followup::showFollowUp($_GET);
                        echo "</div>";
                        echo "</div>";
                        echo "</div>";

                        if ($items) {
                            echo "<div class='col-12 col-md-6'>";
                            echo "<div class='card h-100'>";
                            echo "<div class='card-header' style='background-color: var(--tblr-primary-fg);'>";
                            echo "<h4 class='mb-3'>" . htmlspecialchars(DirectHelpdesk::getTypeName(2)) . "</h4>";
                            echo "</div>";
                            echo "<div class='card-body'>";
                            DirectHelpdesk::showDashboard();
                            DirectHelpdesk_Ticket::selectDirectHeldeskForTicket($entities);
                            echo "</div>";
                            echo "</div>";
                            echo "</div>";
                        }

                        echo "</div>";
                    } else {
                        Followup::showFollowUp($_GET);
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
                    if (Config::useEditorSubscriptions()) {
                        EditorSubscription::showForEntity($entities);
                    }
                    break;
                case 7:
                    if (Config::useEditorSubscriptions()) {
                        EditorSubscription::showStatusTab();
                    }
                    break;
                case 8:
                    $config = Config::getInstance();
                    if ((Session::getCurrentInterface() == 'central')
                        || (Session::getCurrentInterface() == 'helpdesk'
                            && $config->fields['choice_intervention'] == Config::REPORT_INTERVENTION)) {
                        $CriDetail->showReports(
                            0,
                            0,
                            $entities,
                            ['glpi_plugin_manageentities_contractstates.is_closed' => ['<>', 1]],
                        );
                    } elseif (Session::getCurrentInterface() == 'helpdesk'
                        && $config->fields['choice_intervention'] == Config::PERIOD_INTERVENTION) {
                        $CriDetail->showPeriod(0, 0, $entities);
                    }

                    break;
                case 9:
                    $is_single = (count($entities) === 1);
                    $doc_item  = new Document_Item();
                    foreach ($entities as $entity_id) {
                        $entity->getFromDB($entity_id);
                        if (!$is_single) {
                            $has_docs = count($doc_item->find([
                                'items_id' => $entity_id,
                                'itemtype' => \Entity::class,
                            ])) > 0;
                            if (!$has_docs) {
                                continue;
                            }
                            echo "<h4 class='mt-3 ms-3'><i class='ti ti-building me-1'></i>"
                                . htmlspecialchars($entity->fields['completename'])
                                . "</h4>";
                        }
                        // withtemplate=2 suppresses the add form in tree mode
                        Document_Item::showForItem($entity, $is_single ? 0 : 2);
                    }
                    break;
                case 12:
                    $ManageentitiesEntity->showReferences($entities);
                    break;
                case 13:
                    DirectHelpdesk::showUnbilledOverview();
                    break;
                default:
                    break;
            }
        }
        return true;
    }

    // Hook done on before update document - keeps document date if it's a CRI
    public static function preUpdateDocument($item)
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

    public static function UpdateDocument($item)
    {
        global $DB;

        $config = new Config();
        if ($item->getField('id')
            && $config->GetfromDB(1)) {
            $doc = new Document();
            $doc->update([
                'id' => $item->getField('id'),
                'date_mod' => $_SESSION["glpi_plugin_manageentities_date_mod"],
            ]);
        }

        return true;
    }

    public static function showManageentitiesHeader($subtitle = '')
    {
        echo "<h3><div class='alert alert-secondary' role='alert'>";
        // completename of the active entity, editable by whoever administers that entity.
        echo __('Portal', 'manageentities') . " " . htmlspecialchars((string) $_SESSION["glpiactive_entity_name"]);
        echo '<br/>' . $subtitle;
        echo "</div></h3>";
    }

    public function showDescription($entities)
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
                $logos[] = PLUGIN_MANAGEENTITIES_WEBDIR . '/front/logo.send.php?docid=' . $logo['logos_id'];
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
                'contact_dropdown_html' => $contact_dropdown_html,
                'user_dropdown_html'   => $user_dropdown_html,
                // For the add-contact/business form, use the first (or only) entity id
                'entity_id'            => $entities[array_key_first($entities)] ?? 0,
            ],
        );
    }


    public static function getMenuContent()
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


    public function getRights($interface = 'central')
    {
        $values = [
            CREATE => __('Create'),
            READ => __('Read'),
            UPDATE => __('Update'),
            PURGE => [
                'short' => __('Purge'),
                'long' => _x('button', 'Delete permanently'),
            ],
        ];

        return $values;
    }

    public function showReferences($instID)
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
                QueryFunction::year('date_signature', 'year'),
            ],
            'FROM' => 'glpi_plugin_manageentities_contracts',
            // The restriction had been left commented out, so this wall of references listed every
            // entity of the instance holding a signed contract - name, logo and signature year -
            // to anyone holding a plain read right on the plugin. Scoping it to the active
            // perimeter keeps the feature intact for central profiles while removing the
            // cross-tenant disclosure. The table has no is_recursive column, hence the default
            // (non-recursive) form of the helper.
            'WHERE' => [
                'NOT' => ['date_signature' => null],
            ] + getEntitiesRestrictCriteria('glpi_plugin_manageentities_contracts'),
            'GROUPBY' => 'entities_id',
            'ORDERBY' => 'year DESC',
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
                    'signature' => $data['signature'],
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

                // Escape: getName() returns the raw DB name (GLPI 10+ stores it
                // unescaped), so echoing it directly would be a stored XSS sink.
                echo "<td>" . htmlspecialchars((string) $entity->getName()) . "</td>";

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
            echo "<th>" . _n('Entity', 'Entities', 1) . "</th>";
            echo "<th>" . __('Date of signature', 'manageentities') . "</th>";
            echo "</tr>";


            if (count($debug) > 0) {
                foreach ($debug as $client) {
                    echo "<tr class='tab_bg_1'>";
                    // Entity name is stored raw; escape it before echo (debug view).
                    echo "<td>" . htmlspecialchars((string) $client['name'], ENT_QUOTES) . "</td>";

                    echo "<td>" . Html::convDate($client['signature']) . "</td>";
                    echo "</tr>";
                }
            }
            echo "</table>";
        }
    }

    /**
     * Seed the "Alert Wizard Creation" notification: template header, default
     * translation, notification and its mailing link.
     *
     * Entity has no table of its own, so there is no install()/uninstall() pair to
     * hang this on: hook.php calls it directly, on install and on upgrade. Fully
     * idempotent, each row being inserted only when missing.
     *
     * No automatic action here, unlike the two other notifications of the plugin: the
     * event is raised inline by WizardController when the wizard commits, so the mail
     * leaves as soon as the elements are written.
     */
    public static function installNotification(Migration $migration): void
    {
        global $DB;

        // Template header (idempotent guard inside).
        NotificationTargetEntity::install($migration);

        $template = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['itemtype' => self::class],
        ])->current();
        $templates_id = $template['id'] ?? 0;
        if (!$templates_id) {
            return;
        }

        // Canonical default translation.
        //
        // The FOREACH block MUST contain inline elements only (<strong>, <br />) and
        // never a block-level element such as <table>/<tr>/<td>: the GLPI rich-text
        // editor hoists a block-level element out of its surrounding node, which
        // strands the ##FOREACHitems## / ##ENDFOREACHitems## markers and leaves the
        // row tags unsubstituted. Same constraint as the two other notifications of
        // the plugin.
        $content_text = '##wizard.action##

##lang.wizard.entity##: ##wizard.entity##
##lang.wizard.author##: ##wizard.author##
##lang.wizard.date##: ##wizard.date##

##FOREACHitems####item.type##: ##item.label##
##ENDFOREACHitems##';

        $content_html = '&lt;p&gt;&lt;strong&gt;##wizard.action##&lt;/strong&gt;&lt;br /&gt;&lt;br /&gt;'
            . '&lt;strong&gt;##lang.wizard.entity##:&lt;/strong&gt; ##wizard.entity##&lt;br /&gt;'
            . '&lt;strong&gt;##lang.wizard.author##:&lt;/strong&gt; ##wizard.author##&lt;br /&gt;'
            . '&lt;strong&gt;##lang.wizard.date##:&lt;/strong&gt; ##wizard.date##&lt;br /&gt;&lt;br /&gt;'
            . '##FOREACHitems##'
            . '&lt;strong&gt;##item.type##:&lt;/strong&gt; ##item.label##&lt;br /&gt;'
            . '##ENDFOREACHitems##'
            . '&lt;/p&gt;';

        // Insert when missing; otherwise repair a translation whose FOREACH block has
        // been broken by an editor round-trip (empty ##FOREACH...####ENDFOREACH...##
        // with the row tags stranded outside). Detection: no ##item. tag survives
        // between the two markers. A healthy, admin-customized translation keeps at
        // least one row tag inside the block and is left untouched.
        $existing = $DB->request([
            'FROM'  => 'glpi_notificationtemplatetranslations',
            'WHERE' => ['notificationtemplates_id' => $templates_id],
        ]);

        if (count($existing) === 0) {
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templates_id,
                'language'                 => '',
                'subject'                  => '##wizard.action## - ##wizard.entity##',
                'content_text'             => $content_text,
                'content_html'             => $content_html,
            ]);
        } else {
            foreach ($existing as $translation) {
                $html = (string) ($translation['content_html'] ?? '');
                if (
                    preg_match('/##FOREACHitems##(.*?)##ENDFOREACHitems##/is', $html, $m) !== 1
                    || strpos($m[1], '##item.') === false
                ) {
                    $DB->update(
                        'glpi_notificationtemplatetranslations',
                        [
                            'content_text' => $content_text,
                            'content_html' => $content_html,
                        ],
                        ['id' => $translation['id']],
                    );
                }
            }
        }

        // Notification (only if not already present for this itemtype/event).
        $has_notification = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_notifications',
            'WHERE' => [
                'itemtype' => self::class,
                'event'    => NotificationTargetEntity::WizardCreation,
            ],
        ])->current();

        if ((int) ($has_notification['cpt'] ?? 0) === 0) {
            $DB->insert('glpi_notifications', [
                'name'         => 'Alert Wizard Creation',
                'entities_id'  => 0,
                'itemtype'     => self::class,
                'event'        => NotificationTargetEntity::WizardCreation,
                'is_recursive' => 1,
                'is_active'    => 1,
            ]);
            $notifications_id = $DB->insertId();

            $DB->insert('glpi_notifications_notificationtemplates', [
                'notifications_id'         => $notifications_id,
                'mode'                     => 'mailing',
                'notificationtemplates_id' => $templates_id,
            ]);
        }
    }

    /**
     * Remove the wizard creation notification, its template, translations and mailing
     * links. Entity owns no table, so this is not named uninstall(): there is nothing
     * to drop besides the notification chain.
     */
    public static function uninstallNotification(): void
    {
        global $DB;

        $notif = new Notification();
        foreach (
            $DB->request([
                'FROM'  => 'glpi_notifications',
                'WHERE' => ['itemtype' => self::class],
            ]) as $data
        ) {
            $notif->delete($data);
        }

        $template       = new NotificationTemplate();
        $translation    = new NotificationTemplateTranslation();
        $notif_template = new Notification_NotificationTemplate();
        foreach (
            $DB->request([
                'FROM'  => 'glpi_notificationtemplates',
                'WHERE' => ['itemtype' => self::class],
            ]) as $data
        ) {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_notificationtemplatetranslations',
                    'WHERE' => ['notificationtemplates_id' => $data['id']],
                ]) as $row
            ) {
                $translation->delete($row);
            }
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_notifications_notificationtemplates',
                    'WHERE' => ['notificationtemplates_id' => $data['id']],
                ]) as $row
            ) {
                $notif_template->delete($row);
            }
            $template->delete($data);
        }
    }
}
