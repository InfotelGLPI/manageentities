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

define('PLUGIN_MANAGEENTITIES_VERSION', '4.2.19');

global $CFG_GLPI;

use Glpi\Plugin\Hooks;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\ContractDay;
use GlpiPlugin\Manageentities\CustomerSheet;
use GlpiPlugin\Manageentities\CriDetail;
use GlpiPlugin\Manageentities\CriPrice;
use GlpiPlugin\Manageentities\Dashboard;
use GlpiPlugin\Manageentities\DirectHelpdesk;
use GlpiPlugin\Manageentities\DirectHelpdesk_Ticket;
use GlpiPlugin\Manageentities\GenerateCRI;
use GlpiPlugin\Manageentities\InterventionStakeholder;
use GlpiPlugin\Manageentities\Preference;
use GlpiPlugin\Manageentities\Profile;
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\Servicecatalog;
use GlpiPlugin\Manageentities\EditorSubscription;
use GlpiPlugin\Manageentities\SubscriptionLevel;
use GlpiPlugin\Manageentities\TaskCategory;
use GlpiPlugin\Manageentities\TicketTask;

if (!defined("PLUGIN_MANAGEENTITIES_DIR")) {
    define("PLUGIN_MANAGEENTITIES_DIR", Plugin::getPhpDir("manageentities"));
    $root = $CFG_GLPI['root_doc'] . '/plugins/manageentities';
    define("PLUGIN_MANAGEENTITIES_WEBDIR", $root);
}

include_once PLUGIN_MANAGEENTITIES_DIR . "/vendor/autoload.php";

// Init the hooks of the plugins -Needed
function plugin_init_manageentities()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CHANGE_PROFILE]['manageentities'] = [Profile::class, 'initProfile'];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['manageentities'] = [
        'Entity' => 'plugin_pre_item_purge_manageentities',
        'Ticket' => 'plugin_pre_item_purge_manageentities',
        'Contract' => 'plugin_pre_item_purge_manageentities',
        'Contact' => 'plugin_pre_item_purge_manageentities',
        'TaskCategory' => 'plugin_pre_item_purge_manageentities',
        'User' => 'plugin_pre_item_purge_manageentities',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['manageentities'] = [
        'Document' => [
            Entity::class,
            'preUpdateDocument',
        ],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['manageentities'] = [
        'Document'   => [Entity::class, 'UpdateDocument'],
        'Contract'   => 'plugin_manageentities_contract_item_update',
        'TicketTask' => [TicketTask::class, 'refreshRemainingDays'],
        'Ticket'     => [TicketTask::class, 'refreshTicketRemainingDaysOnUpdate'],
    ];

    // The remaining days shown in the contract list (remaining_days) are denormalized
    // and computed from the ticket tasks: keep them in sync with every task and ticket
    // change, including those made without a session (mail collector, crons).
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['manageentities']['TicketTask']     = [TicketTask::class, 'refreshRemainingDays'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['manageentities']['TicketTask']   = [TicketTask::class, 'refreshRemainingDays'];
    $PLUGIN_HOOKS[Hooks::ITEM_DELETE]['manageentities']['Ticket']      = [TicketTask::class, 'refreshTicketRemainingDays'];
    $PLUGIN_HOOKS[Hooks::ITEM_RESTORE]['manageentities']['Ticket']     = [TicketTask::class, 'refreshTicketRemainingDays'];

    $PLUGIN_HOOKS[Hooks::ITEM_TRANSFER]['manageentities'] = 'plugin_item_transfer_manageentities';

    if (Session::getLoginUserID()) {
        Plugin::registerClass(EditorSubscription::class, [
            'addtabon'                  => Entity::class,
            'notificationtemplates_types' => true,
        ]);
        // No addtabon here: Entity is registered only so its wizard creation
        // notification template is selectable in Configuration > Notifications.
        Plugin::registerClass(Entity::class, ['notificationtemplates_types' => true]);
        Plugin::registerClass(SubscriptionLevel::class);
        Plugin::registerClass(CustomerSheet::class, ['addtabon' => 'Entity']);
        Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);
        Plugin::registerClass(Contract::class, [
            'addtabon'                    => 'Contract',
            'notificationtemplates_types' => true,
        ]);
        Plugin::registerClass(CriDetail::class, [
            'addtabon' => 'Ticket',
            'planning_types' => true,
        ]);
        Plugin::registerClass(DirectHelpdesk_Ticket::class, ['addtabon' => 'Ticket']);

        Plugin::registerClass(TaskCategory::class, ['addtabon' => 'TaskCategory']);
        Plugin::registerClass(
            InterventionStakeholder::class,
            ['addtabon' => ContractDay::class],
        );
        Plugin::registerClass(CriPrice::class, ['addtabon' =>  ContractDay::class]);

        if (Plugin::isPluginActive('servicecatalog')) {
            $PLUGIN_HOOKS['servicecatalog']['manageentities'] = [Servicecatalog::class];
        }
        if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])) {
            $PLUGIN_HOOKS[Hooks::MENU_TOADD]['manageentities'] = [
                'helpdesk' => [
                    GenerateCRI::class,
                    DirectHelpdesk::class,
                ],
            ];
        }
        if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])
            && !Plugin::isPluginActive('servicecatalog')) {
            $PLUGIN_HOOKS[Hooks::HELPDESK_MENU_ENTRY]['manageentities'] = PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php";
            $PLUGIN_HOOKS[Hooks::HELPDESK_MENU_ENTRY_ICON]['manageentities'] = Entity::getIcon();
        }
        if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])) {
            Plugin::registerClass(Preference::class, ['addtabon' => 'Preference']); //See #413
            $PLUGIN_HOOKS[Hooks::MENU_TOADD]['manageentities']['management'] = Entity::class;

            // Reports
            $PLUGIN_HOOKS['reports']['manageentities'] = [
                'front/report.form.php' => _n('Intervention report', 'Intervention reports', 2, 'manageentities'),
                'front/report_moving.form.php' => __('Report on the movement of technicians', 'manageentities'),
                'front/report_occupation.form.php' => __(
                    'Report concerning the occupation of the technicians',
                    'manageentities',
                ),
            ];

            if (isset($_SESSION["glpi_plugin_manageentities_loaded"])
                && $_SESSION["glpi_plugin_manageentities_loaded"] == 0
                && Plugin::isPluginActive("manageentities")) {
                $_SESSION["glpi_plugin_manageentities_loaded"] = 1;
                Html::redirect(PLUGIN_MANAGEENTITIES_WEBDIR . "/front/entity.php");
            }
        }

        if (Session::haveRight("plugin_manageentities", UPDATE)) {
            $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['manageentities'] = 'front/config.form.php';
        }

        $PLUGIN_HOOKS['mydashboard']['manageentities'] = [Dashboard::class];

        $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['manageentities'] = [
            'TicketTask' => [TicketTask::class, 'preItemAdd'],
        ];
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['manageentities']['Ticket_Contract'] = [CriDetail::class, 'autoLinkTicketToActiveContractDay'];
        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['manageentities'] = 'plugin_manageentities_post_item_form';
        // Add specific files to add to the header : javascript or css
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['manageentities'] = ["css/manageentities.css"];

        // DirectHelpdesk dashboard gauges (data-driven; harmless when no gauge is present).
        // Registered unconditionally so the dashboard also renders in the helpdesk
        // interface, where the central-only scripts below are not loaded. ECharts itself
        // is the core bundle: DirectHelpdesk::showDashboard() requests it through
        // Html::requireJs('charts'), and the script below loads it on the AJAX tab path,
        // where no footer is emitted to honour that request.
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['manageentities'][] = 'scripts/directhelpdesk-gauges.js';
        // "Associate to a contract" card of the CRI detail (native ES module, no-op elsewhere)
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['manageentities'][] = 'scripts/cridetail-contract.js';
        // Prices of a contract period and their edition form (native ES module, no-op elsewhere)
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['manageentities'][] = 'scripts/criprice.js';

        if (isset($_SESSION['glpiactiveprofile']['interface'])
            && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['manageentities'] = array_merge(
                $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['manageentities'] ?? [],
                [
                    'scripts/scripts-manageentities.js',
                    'scripts/wizard.js',
                ],
            );
            // Stakeholders tab of a contract day (native ES module, no jQuery)
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['manageentities'][] = 'scripts/interventionstakeholder.js';
            // "+ 12 months" button of the contract form (native ES module, no-op elsewhere)
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['manageentities'][] = 'scripts/contract-add-months.js';
            if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])) {
                $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['manageentities'][] = 'scripts/script-directhelpdesk.js';
                // Contract alert of the unbilled intervention modal (native ES module)
                $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT_MODULE]['manageentities'][] = 'scripts/directhelpdesk-modal.js';
            }
        }

        if (class_exists(DirectHelpdesk::class)) { // only if plugin activated
            $PLUGIN_HOOKS['plugin_datainjection_populate']['manageentities']
                = 'plugin_datainjection_populate_manageentities';
        }

        $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['manageentities'] = true;
        $PLUGIN_HOOKS[Hooks::POST_INIT]['manageentities'] = 'plugin_manageentities_postinit';
        if (Session::haveRightsOr('plugin_manageentities', [READ, UPDATE])
            && isset($_SESSION['glpiactiveprofile']['interface'])
            && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
            $PLUGIN_HOOKS[Hooks::PRE_ITEM_FORM]['manageentities'] = 'plugin_manageentities_pre_item_form';
            $PLUGIN_HOOKS[Hooks::PRE_ITEM_LIST]['manageentities'] = [Contract::class, 'preItemForm'];
        }
    }
}

// Get the name and the version of the plugin - Needed
function plugin_version_manageentities()
{
    return [
        'name' => __('Entities portal', 'manageentities'),
        'version' => PLUGIN_MANAGEENTITIES_VERSION,
        'oldname' => 'manageentity',
        'author' => "<a href='https://blogglpi.infotel.com'>Infotel</a>, Xavier CAILLAUD",
        'license' => 'GPLv3+',
        'homepage' => 'https://github.com/InfotelGLPI/manageentities',
        'requirements' => [
            'glpi' => [
                'min' => '11.0',
                'max' => '12.0',
                'dev' => false,
            ],
        ],
    ];
}

/**
 * @return bool
 */
function plugin_manageentities_check_prerequisites()
{
    if (!is_readable(__DIR__ . '/vendor/autoload.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
        echo "Run composer install --no-dev in the plugin directory<br>";
        return false;
    }

    return true;
}
