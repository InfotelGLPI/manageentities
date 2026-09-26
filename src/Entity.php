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

    public const TAB_CONTRACTS = 5;
    public const TAB_DOCUMENTS = 9;
    public const TAB_TICKETS   = 15;

    /**
     * Tabs of the client management dashboard a user can choose to display, by tab number.
     *
     * @return array<int, string>
     */
    public static function getDashboardTabLabels(): array
    {
        $labels = [
            1                   => __('General follow-up', 'manageentities'),
            2                   => __('Monthly follow-up', 'manageentities'),
            3                   => __('GANTT'),
            4                   => __('Data administrative', 'manageentities'),
            self::TAB_CONTRACTS => _n('Contract', 'Contracts', 2),
        ];
        if (Config::useEditorSubscriptions()) {
            $labels[6] = _n('Publisher subscription', 'Publisher subscriptions', 2, 'manageentities');
            $labels[7] = __('Contracts status overview', 'manageentities');
        }
        $labels[8]                   = __('Interventions reports', 'manageentities');
        $labels[self::TAB_DOCUMENTS] = _n('Document', 'Documents', 2);
        $labels[12]                  = __('References', 'manageentities');
        $labels[13]                  = __('Unbilled interventions', 'manageentities');
        $labels[14]                  = __('Tech lead by clients', 'manageentities');
        $labels[self::TAB_TICKETS]   = __('Ongoing tickets', 'manageentities');

        return $labels;
    }

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

            // showDescription() issues two queries and renders one Html::file() per entity, so a
            // parent entity multiplies the cost by the size of its subtree for a page that is
            // only ever read one customer at a time.
            if ($is_single_entity) {
                $tabs[4] = self::createTabEntry(__('Data administrative', 'manageentities'));
            }

            if (Session::haveRight("contract", READ)) {
                $tabs[self::TAB_CONTRACTS] = Contract::createTabEntry(_n('Contract', 'Contracts', 2));
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
                    __('Unbilled interventions', 'manageentities'),
                    0,
                    self::class,
                    DirectHelpdesk::getIcon(),
                );
            }

            // Workload of the tech leads: central only, like the other provider-side overviews
            if (Session::getCurrentInterface() == 'central' && TechLead::canView()) {
                $tabs[14] = TechLead::createTabEntry(
                    __('Tech lead by clients', 'manageentities'),
                    0,
                    self::class,
                    TechLead::getIcon(),
                );
            }

            // Open tickets of every customer: central only, and reserved to the users seeing all tickets
            if (Session::getCurrentInterface() == 'central' && TicketOverview::canView()) {
                $tabs[self::TAB_TICKETS] = self::createTabEntry(
                    __('Ongoing tickets', 'manageentities'),
                    0,
                    self::class,
                    'ti ti-ticket',
                );
            }

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
                $tabs[self::TAB_DOCUMENTS] = Document::createTabEntry(_n('Document', 'Documents', 2));
            }

            if (Session::getCurrentInterface() != 'helpdesk' && $this->canview()) {
                $tabs[12] = self::createTabEntry(__('References', 'manageentities'));
            }

            // Tabs the user chose not to display, from the preferences of the plugin. Only the
            // central interface has these preferences. A hidden tab is refused as well, since
            // displayTabContentForItem() only serves the tabs offered here. A choice leaving no
            // tab at all is ignored rather than rendering an empty dashboard.
            if (Session::getCurrentInterface() == 'central' && Session::getLoginUserID()) {
                $displayed = array_diff_key(
                    $tabs,
                    array_flip(Preference::getHiddenDashboardTabs((int) Session::getLoginUserID())),
                );
                if ($displayed !== []) {
                    $tabs = $displayed;
                }
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

                        // Both panels are rendered by legacy methods which echo their output
                        ob_start();
                        Followup::showFollowUp($_GET);
                        $followup_html = ob_get_clean();

                        $directhelpdesk_html = '';
                        if ($items) {
                            ob_start();
                            DirectHelpdesk::showDashboard();
                            DirectHelpdesk_Ticket::selectDirectHeldeskForTicket($entities);
                            $directhelpdesk_html = ob_get_clean();
                        }

                        TemplateRenderer::getInstance()->display('@manageentities/entity/followup_helpdesk.html.twig', [
                            'followup_html'       => $followup_html,
                            'directhelpdesk_title' => DirectHelpdesk::getTypeName(2),
                            'directhelpdesk_html' => $directhelpdesk_html,
                        ]);
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
                            TemplateRenderer::getInstance()->display('@manageentities/entity/documents_heading.html.twig', [
                                'entity_name' => $entity->fields['completename'],
                            ]);
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
                case 14:
                    TechLead::showClientsByTech($entities);
                    break;
                case self::TAB_TICKETS:
                    TicketOverview::showOverview(
                        $entities,
                        $_GET['stale_weeks'] ?? TicketOverview::DEFAULT_STALE_WEEKS,
                        $_GET['ticket_type'] ?? TicketOverview::ALL_TYPES,
                    );
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
        TemplateRenderer::getInstance()->display('@manageentities/entity/portal_header.html.twig', [
            'entity_name' => $_SESSION["glpiactive_entity_name"] ?? '',
            'subtitle'    => $subtitle,
        ]);
    }

    public function showDescription($entities)
    {
        global $CFG_GLPI;

        $contact         = new Contact();
        $businessContact = new BusinessContact();
        $techLead        = new TechLead();
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


            $entities_data[] = [
                'entity_id'           => $f['id'],
                'entity_name'         => $f['name'],
                'entity_completename' => $f['completename'],
                'entity_comment'      => $f['comment'] ?? '',
                'entity_phonenumber'  => $f['phonenumber'] ?? '',
                'entity_fax'          => $f['fax'] ?? '',
                'entity_website'      => $f['website'] ?? '',
                // Only http(s) URLs become a link: the field is free text (javascript: scheme)
                'entity_website_is_url' => \Toolbox::isValidWebUrl($f['website'] ?? ''),
                'entity_email'        => $f['email'] ?? '',
                'entity_address'      => $f['address'] ?? '',
                'entity_postcode'     => $f['postcode'] ?? '',
                'entity_town'         => $f['town'] ?? '',
                'entity_state'        => $f['state'] ?? '',
                'entity_country'      => $f['country'] ?? '',
                'is_root_entity'      => ($instID == 0),
                'logos'               => $logos,

                'max_upload'          => Document::getMaxUploadSize(),
            ];
        }

        // Contacts and business are fetched once for ALL entities (like the original)
        $contacts_data = $contact->buildContactsForTemplate($entities, $CFG_GLPI['root_doc']);
        $business_data = $businessContact->buildBusinessForTemplate($entities, $CFG_GLPI['root_doc']);
        // Tech leads are a provider-side information: never shown on the simplified interface
        $techlead_data = $interface === 'helpdesk'
            ? []
            : $techLead->buildTechLeadsForTemplate($entities, $CFG_GLPI['root_doc']);

        $techlead_used = ($can_edit && $is_single)
            ? array_column($techLead->find(['entities_id' => $entities]), 'users_id')
            : [];

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
                'techleads'            => $techlead_data,
                'can_edit_techleads'   => $can_edit && $techLead->canCreate(),
                // The main tech lead switch requires UPDATE, not any of the canCreate() bits
                'can_update_techleads' => $can_edit && Session::haveRight(TechLead::$rightname, UPDATE),
                'techlead_entities'    => $entities,
                'techlead_used'        => $techlead_used,
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
        global $DB;

        self::showManageentitiesHeader(__('References', 'manageentities'));

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

        $years       = [];
        $debug       = [];
        $entity_logo = new EntityLogo();
        $entity      = new \Entity();

        foreach ($iterator as $data) {
            if (!$entity->getFromDB($data['entities_id'])) {
                continue;
            }
            $debug[] = [
                'name'      => $entity->getName(),
                'signature' => Html::convDate($data['signature']),
            ];
            $years[$data['year']][] = [
                'name'  => $entity->getName(),
                'logos' => array_column($entity_logo->find(['entities_id' => $data['entities_id']]), 'logos_id'),
            ];
        }

        TemplateRenderer::getInstance()->display('@manageentities/entity/references.html.twig', [
            'years' => $years,
            'debug' => $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE ? $debug : [],
        ]);
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
