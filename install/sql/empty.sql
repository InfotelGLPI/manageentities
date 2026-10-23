--
-- -------------------------------------------------------------------------
-- manageentities plugin for GLPI
-- Copyright (C) 2017-2026 by the manageentities Development Team.
--
-- https://github.com/InfotelGLPI/manageentities
-- -------------------------------------------------------------------------
--
-- LICENSE
--
-- This file is part of manageentities.
--
-- manageentities is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- manageentities is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with manageentities. If not, see <http://www.gnu.org/licenses/>.
-- --------------------------------------------------------------------------
--

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contracts`;
CREATE TABLE `glpi_plugin_manageentities_contracts` (
   `id` int unsigned NOT NULL auto_increment,
   `contracts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id)',
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_default` tinyint NOT NULL DEFAULT '0',
   `management` tinyint NOT NULL DEFAULT '0' COMMENT 'for the management mode (quarterly or annual or not)',
   `contract_type` tinyint NOT NULL DEFAULT '0' COMMENT 'for the contract type (hour, intervention, unlimited or not)',
   `date_signature` timestamp NULL DEFAULT NULL,
   `date_renewal` timestamp NULL DEFAULT NULL,
   `contract_added` tinyint NOT NULL DEFAULT '0',
   `show_on_global_gantt` tinyint NOT NULL DEFAULT '0',
   `refacturable_costs` tinyint NOT NULL DEFAULT '0',
   `moving_management` tinyint NOT NULL DEFAULT '0',
   `duration_moving` decimal(20,2) NOT NULL DEFAULT '0' COMMENT 'Duration of moving',
   `remaining_days` decimal(10,2) NOT NULL DEFAULT '0.00',
   `active_editor_suscription` tinyint NOT NULL DEFAULT '0',
   `cloud_client` tinyint NOT NULL DEFAULT '0',
   `internet_publication` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`contracts_id`,`entities_id`),
   KEY `contracts_id` (`contracts_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contacts`;
CREATE TABLE `glpi_plugin_manageentities_contacts` (
   `id` int unsigned NOT NULL auto_increment,
   `contacts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contacts (id)',
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`contacts_id`,`entities_id`),
   KEY `contacts_id` (`contacts_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_businesscontacts`;
CREATE TABLE `glpi_plugin_manageentities_businesscontacts` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`users_id`,`entities_id`),
   KEY `users_id` (`users_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_preferences`;
CREATE TABLE `glpi_plugin_manageentities_preferences` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `show_on_load` int unsigned NOT NULL DEFAULT '0',
   `contract_states` text DEFAULT NULL,
   `business_id` text DEFAULT NULL,
   `companies_id` text DEFAULT NULL,
   `hidden_dashboard_tabs` text DEFAULT NULL,
   `dashboard_tabs_order` text DEFAULT NULL COMMENT 'JSON tab numbers of the clients management, in the order of the user',
   PRIMARY KEY  (`id`),
   KEY `users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_configs`;
CREATE TABLE `glpi_plugin_manageentities_configs` (
   `id` int unsigned NOT NULL auto_increment,
   `backup` int unsigned NOT NULL DEFAULT '0',
   `documentcategories_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id)',
   `useprice` tinyint NOT NULL DEFAULT '1' COMMENT 'DEFAULT for yes',
   `hourorday` tinyint NOT NULL DEFAULT '0' COMMENT 'DEFAULT for day',
   `hourbyday` int unsigned NOT NULL DEFAULT '0' COMMENT 'if hourorday == 0 then must be different of 0',
   `needvalidationforcri` tinyint NOT NULL DEFAULT '0' COMMENT 'only CRI with validated ticket are taking into account for consumption calculation',
   `use_publictask` tinyint NOT NULL DEFAULT '0' COMMENT 'DEFAULT for no',
   `allow_same_periods` tinyint NOT NULL DEFAULT '0' COMMENT 'allow interventions on the same interval of dates',
   `contract_states` text DEFAULT NULL,
   `business_id` text DEFAULT NULL,
   `choice_intervention` int unsigned DEFAULT NULL,
   `comment` tinyint NOT NULL DEFAULT '1' COMMENT 'display comments in the CRI',
   `non_accomplished_tasks` tinyint NOT NULL DEFAULT '0',
   `get_pdf_cri` tinyint NOT NULL DEFAULT '0',
   `ticket_state` int unsigned NOT NULL DEFAULT '3',
   `default_duration` varchar(255) DEFAULT NULL,
   `default_time_am` varchar(255) DEFAULT NULL,
   `default_time_pm` varchar(255) DEFAULT NULL,
   `disable_date_header` tinyint NOT NULL DEFAULT '0',
   `closed_contractstate_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — state applied to contract periods when closing',
   `closed_glpi_state_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_states (id) — GLPI contract state that triggers period closure and is set when all periods are closed',
   `wizard_contractstate_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — DEFAULT intervention state in wizard',
   `wizard_contract_type` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — DEFAULT intervention type in wizard',
   `wizard_critype_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — DEFAULT CriType for rate in wizard',
   `wizard_documentcategories_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id) — DEFAULT document category in wizard',
   `wizard_contacttypes_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contacttypes (id) — DEFAULT contact type in wizard',
   `wizard_default_entities_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — parent entity pre-selected and locked in wizard step 1',
   `wizard_archive_entities_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — entity used to archive customers',
   `use_presale` tinyint NOT NULL DEFAULT '0',
   `presale_documentcategories_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id) — category of the generated proposals',
   `presale_default_vat` decimal(5,2) NOT NULL DEFAULT '20.00',
   `dat_documentcategories_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documentcategories (id) — category of the generated DAT',
   `use_kiflo` tinyint NOT NULL DEFAULT '0',
   `kiflo_url` varchar(255) NOT NULL DEFAULT 'https://api.kiflo.com/v3',
   `kiflo_token` text COMMENT 'encrypted with GLPIKey',
   `kiflo_last_sync` timestamp NULL DEFAULT NULL COMMENT 'last successful synchronization',
   `kiflo_last_error` text COMMENT 'error of the last synchronization, empty when it succeeded',
   PRIMARY KEY  (`id`),
   KEY `documentcategories_id` (`documentcategories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `glpi_plugin_manageentities_configs` (`id`,`backup`,`documentcategories_id`,`hourorday`,`hourbyday`,`needvalidationforcri`) VALUES ('1', '0','0','0','8','0');

DROP TABLE IF EXISTS `glpi_plugin_manageentities_critypes`;
CREATE TABLE `glpi_plugin_manageentities_critypes` (
   `id` int unsigned NOT NULL auto_increment,
   `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `comment` text collate utf8mb4_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_criprices`;
CREATE TABLE `glpi_plugin_manageentities_criprices` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `plugin_manageentities_critypes_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `plugin_manageentities_contractdays_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
   `price` decimal(20,4) NOT NULL DEFAULT '0.0000',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contractdays`;
CREATE TABLE `glpi_plugin_manageentities_contractdays` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `plugin_manageentities_critypes_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `contracts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id)',
   `plugin_manageentities_contractstates_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id)',
   `contract_type` tinyint NOT NULL DEFAULT '0' COMMENT 'for the contract type (hour, intervention, unlimited or not)',
   `begin_date` timestamp NULL DEFAULT NULL,
   `end_date` timestamp NULL DEFAULT NULL,
   `nbday` decimal(20,2) DEFAULT '0.00',
   `report` decimal(20,2) DEFAULT '0.00',
   `charged` tinyint NOT NULL DEFAULT '0',
   `comment` text,
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `contracts_id` (`contracts_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`),
   KEY `plugin_manageentities_contractstates_id` (`plugin_manageentities_contractstates_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_critechnicians`;
CREATE TABLE `glpi_plugin_manageentities_critechnicians` (
   `id` int unsigned NOT NULL auto_increment,
   `tickets_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   PRIMARY KEY  (`id`),
   KEY `tickets_id` (`tickets_id`),
   KEY `users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;



DROP TABLE IF EXISTS `glpi_plugin_manageentities_interventionstakeholders`;
CREATE TABLE `glpi_plugin_manageentities_interventionstakeholders` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `number_affected_days` double NOT NULL DEFAULT '0' COMMENT 'Number of days affected to the user to an intervention',
   `plugin_manageentities_contractdays_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
   PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;



DROP TABLE IF EXISTS `glpi_plugin_manageentities_cridetails`;
CREATE TABLE `glpi_plugin_manageentities_cridetails` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `date` timestamp NULL DEFAULT NULL,
   `documents_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id)',
   `plugin_manageentities_contractdays_id` int unsigned NOT NULL DEFAULT '0',
   `plugin_manageentities_critypes_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `withcontract` int unsigned NOT NULL DEFAULT '0',
   `contracts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id)',
   `realtime` decimal(20,2) DEFAULT '0.00',
   `technicians` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `tickets_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
   `number_moving` int unsigned NOT NULL DEFAULT '0' COMMENT 'Number of movements',
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `documents_id` (`documents_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`),
   KEY `plugin_manageentities_contractdays_id` (`plugin_manageentities_contractdays_id`),
   KEY `tickets_id` (`tickets_id`),
   KEY `contracts_id` (`contracts_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contractstates`;
CREATE TABLE `glpi_plugin_manageentities_contractstates` (
   `id` int unsigned NOT NULL auto_increment,
   `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `is_active` tinyint NOT NULL DEFAULT '0',
   `is_closed` tinyint NOT NULL DEFAULT '0',
   `color` varchar(7) DEFAULT '#F2F2F2',
   `comment` text collate utf8mb4_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `name` (`name`),
   KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_taskcategories`;
CREATE TABLE `glpi_plugin_manageentities_taskcategories` (
   `id` int unsigned NOT NULL auto_increment,
   `taskcategories_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to  glpi_taskcategories (id)',
   `is_usedforcount` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   KEY `taskcategories_id` (`taskcategories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_companies`;
CREATE TABLE `glpi_plugin_manageentities_companies` (
   `id` int unsigned NOT NULL auto_increment,
   `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `address` text collate utf8mb4_unicode_ci COMMENT 'address of the company shown on CRI',
   `entities_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id)',
   `is_recursive` tinyint NOT NULL DEFAULT '0',
   `logo_id` int unsigned DEFAULT 0 COMMENT 'RELATION to glpi_documents',
   `comment` text collate utf8mb4_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `is_recursive` (`is_recursive`),
   KEY `logo_id` (`logo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_entitylogos`;
CREATE TABLE `glpi_plugin_manageentities_entitylogos` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `logos_id` int unsigned DEFAULT 0 COMMENT 'RELATION to glpi_documents',
   PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `glpi_plugin_manageentities_directhelpdesks` (
    `id` int unsigned NOT NULL auto_increment,
    `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
    `entities_id` int unsigned NOT NULL DEFAULT '0',
    `name` varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
    `comment` text collate utf8mb4_unicode_ci,
    `is_billed` tinyint NOT NULL DEFAULT '0',
    `date` timestamp NULL DEFAULT NULL,
    `actiontime` int NOT NULL DEFAULT '0',
    `tickets_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
    `date_mod` timestamp NULL DEFAULT NULL,
    `date_creation` timestamp NULL DEFAULT NULL,
    PRIMARY KEY  (`id`),
    KEY `entities_id` (`entities_id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_subscriptionlevels`;
CREATE TABLE `glpi_plugin_manageentities_subscriptionlevels` (
   `id`                int unsigned NOT NULL auto_increment,
   `name`              varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL,
   `comment`           text collate utf8mb4_unicode_ci,
   `subscription_type` tinyint NOT NULL DEFAULT '0' COMMENT '0=all, 1=on_premise, 2=cloud',
   `price_per_user`    decimal(20,2) DEFAULT NULL COMMENT 'monthly price per user excl. VAT, cloud levels',
   PRIMARY KEY (`id`),
   KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_editorsubscriptions`;
CREATE TABLE `glpi_plugin_manageentities_editorsubscriptions` (
   `id`                        int unsigned NOT NULL auto_increment,
   `entities_id`               int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id)',
   `name`                      varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Referenced name at the publisher',
   `customer_account_id`       varchar(255) collate utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Publisher customer account ID',
   `active_editor_suscription` tinyint NOT NULL DEFAULT '0',
   `cloud_client`              tinyint NOT NULL DEFAULT '0',
   `plugin_manageentities_subscriptionlevels_id`     int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_subscriptionlevels (id)',
   `begin_date`                timestamp NULL DEFAULT NULL,
   `end_date`                  timestamp NULL DEFAULT NULL,
   `internet_publication`      tinyint NOT NULL DEFAULT '0' COMMENT 'Internet publication flag (migrated from contracts)',
   `comment`                   text collate utf8mb4_unicode_ci,
   PRIMARY KEY (`id`),
   UNIQUE KEY `unicity` (`entities_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_directhelpdesks_tickets`;
CREATE TABLE `glpi_plugin_manageentities_directhelpdesks_tickets` (
    `id` int unsigned NOT NULL auto_increment,
    `tickets_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_tickets (id)',
    `plugin_manageentities_directhelpdesks_id` int unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY  (`id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `plugin_manageentities_directhelpdesks_id` (`plugin_manageentities_directhelpdesks_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_techleads`;
CREATE TABLE `glpi_plugin_manageentities_techleads` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`users_id`,`entities_id`),
   KEY `users_id` (`users_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_projectmanagers`;
CREATE TABLE `glpi_plugin_manageentities_projectmanagers` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`users_id`,`entities_id`),
   KEY `users_id` (`users_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleusers`;
CREATE TABLE `glpi_plugin_manageentities_presaleusers` (
   `id` int unsigned NOT NULL auto_increment,
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `type` tinyint NOT NULL DEFAULT '1' COMMENT '1=sales representative, 2=project manager',
   `role_label` varchar(255) DEFAULT NULL COMMENT 'role printed in the proposal',
   `phone` varchar(255) DEFAULT NULL COMMENT 'phone printed in the proposal, user phone when empty',
   `is_default` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`users_id`,`type`),
   KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleannextypes`;
CREATE TABLE `glpi_plugin_manageentities_presaleannextypes` (
   `id` int unsigned NOT NULL auto_increment,
   `name` varchar(255) DEFAULT NULL,
   `comment` text,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_recursive` tinyint NOT NULL DEFAULT '1',
   `objet` mediumtext,
   `description` mediumtext,
   `default_vat` decimal(5,2) NOT NULL DEFAULT '20.00',
   `default_lines` text COMMENT 'JSON list of designation, quantity, unit_price prefilled in the wizard',
   `default_estimate` text COMMENT 'JSON estimate prefilled in the wizard: [{type: title|line, label, days}]',
   `internal_name` varchar(255) DEFAULT NULL COMMENT 'names the proposals holding this annex',
   `is_subscription` tinyint NOT NULL DEFAULT '0',
   `plugin_manageentities_subscriptionlevels_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_subscriptionlevels (id)',
   `is_active` tinyint NOT NULL DEFAULT '1',
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY  (`id`),
   KEY `name` (`name`),
   KEY `entities_id` (`entities_id`),
   KEY `is_recursive` (`is_recursive`),
   KEY `is_active` (`is_active`),
   KEY `plugin_manageentities_subscriptionlevels_id` (`plugin_manageentities_subscriptionlevels_id`),
   KEY `date_creation` (`date_creation`),
   KEY `date_mod` (`date_mod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleclients`;
CREATE TABLE `glpi_plugin_manageentities_presaleclients` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
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
   `logo_documents_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id)',
   `comment` text,
   `converted_entities_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_entities (id) — entity created once a proposal is won',
   `is_archived` tinyint NOT NULL DEFAULT '0' COMMENT 'all its proposals were lost',
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presalecontacts`;
CREATE TABLE `glpi_plugin_manageentities_presalecontacts` (
   `id` int unsigned NOT NULL auto_increment,
   `plugin_manageentities_presaleclients_id` int unsigned NOT NULL DEFAULT '0',
   `name` varchar(255) DEFAULT NULL,
   `firstname` varchar(255) DEFAULT NULL,
   `role` varchar(255) DEFAULT NULL,
   `email` varchar(255) DEFAULT NULL,
   `phone` varchar(255) DEFAULT NULL,
   `mobile` varchar(255) DEFAULT NULL,
   `is_default` tinyint NOT NULL DEFAULT '0' COMMENT 'main contact: recipient of the proposal, signs the annexes',
   `contacts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contacts (id) — contact created once the proposal is won',
   PRIMARY KEY  (`id`),
   KEY `plugin_manageentities_presaleclients_id` (`plugin_manageentities_presaleclients_id`),
   KEY `contacts_id` (`contacts_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleorigins`;
CREATE TABLE `glpi_plugin_manageentities_presaleorigins` (
   `id` int unsigned NOT NULL auto_increment,
   `name` varchar(255) DEFAULT NULL,
   `comment` text,
   `is_active` tinyint NOT NULL DEFAULT '1',
   `is_customer_default` tinyint NOT NULL DEFAULT '0' COMMENT 'default origin of the proposals for an existing customer',
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY (`id`),
   KEY `name` (`name`),
   KEY `is_active` (`is_active`),
   KEY `is_customer_default` (`is_customer_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleproposals`;
CREATE TABLE `glpi_plugin_manageentities_presaleproposals` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `is_recursive` tinyint NOT NULL DEFAULT '0',
   `plugin_manageentities_presaleclients_id` int unsigned NOT NULL DEFAULT '0',
   `plugin_manageentities_presalecontacts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'recipient of the proposal',
   `plugin_manageentities_presaleorigins_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'origin of the request, for the statistics',
   `plugin_manageentities_editorsubscriptions_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_editorsubscriptions (id) — expired subscription renewed by the proposal',
   `contracts_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_contracts (id) — expiring contract followed by the proposal',
   `name` varchar(255) DEFAULT NULL,
   `reference` varchar(32) DEFAULT NULL COMMENT 'set at the first generation, e.g. SOC260914',
   `status` tinyint NOT NULL DEFAULT '1' COMMENT '1=draft, 2=generated, 3=won, 4=lost (archived)',
   `step` tinyint NOT NULL DEFAULT '0' COMMENT 'last validated step of the wizard',
   `sales_users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `sales_name` varchar(255) DEFAULT NULL,
   `sales_role` varchar(255) DEFAULT NULL,
   `sales_email` varchar(255) DEFAULT NULL,
   `sales_phone` varchar(255) DEFAULT NULL,
   `pm_users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
   `pm_name` varchar(255) DEFAULT NULL,
   `pm_role` varchar(255) DEFAULT NULL,
   `pm_email` varchar(255) DEFAULT NULL,
   `pm_phone` varchar(255) DEFAULT NULL,
   `proposal_type` varchar(255) DEFAULT NULL COMMENT 'type of contract, free text',
   `proposal_date` date DEFAULT NULL,
   `summary` text,
   `total_ht` decimal(20,2) NOT NULL DEFAULT '0.00',
   `total_vat` decimal(20,2) NOT NULL DEFAULT '0.00',
   `total_ttc` decimal(20,2) NOT NULL DEFAULT '0.00',
   `documents_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id) — last generated version',
   `version` int NOT NULL DEFAULT '0',
   `archive_date` timestamp NULL DEFAULT NULL,
   `conversion_date` timestamp NULL DEFAULT NULL COMMENT 'won proposal: the customer wizard went through (PresaleConversion::afterCommit())',
   `estimate_status` tinyint NOT NULL DEFAULT '0' COMMENT 'estimate by the project manager: 0 none, 1 requested, 2 validated',
   `estimate_brief` text COMMENT 'note of the sales representative for the estimate',
   `estimate_comment` text COMMENT 'comment of the project manager on the estimate',
   `estimate_date` timestamp NULL DEFAULT NULL COMMENT 'last request or validation of the estimate',
   `estimate_users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — who validated the estimate',
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author',
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY  (`id`),
   UNIQUE KEY `reference` (`reference`),
   KEY `name` (`name`),
   KEY `entities_id` (`entities_id`),
   KEY `is_recursive` (`is_recursive`),
   KEY `plugin_manageentities_presaleclients_id` (`plugin_manageentities_presaleclients_id`),
   KEY `plugin_manageentities_presalecontacts_id` (`plugin_manageentities_presalecontacts_id`),
   KEY `plugin_manageentities_presaleorigins_id` (`plugin_manageentities_presaleorigins_id`),
   KEY `plugin_manageentities_editorsubscriptions_id` (`plugin_manageentities_editorsubscriptions_id`),
   KEY `contracts_id` (`contracts_id`),
   KEY `status` (`status`),
   KEY `sales_users_id` (`sales_users_id`),
   KEY `pm_users_id` (`pm_users_id`),
   KEY `estimate_status` (`estimate_status`),
   KEY `estimate_users_id` (`estimate_users_id`),
   KEY `documents_id` (`documents_id`),
   KEY `users_id` (`users_id`),
   KEY `date_creation` (`date_creation`),
   KEY `date_mod` (`date_mod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleproposals_annexes`;
CREATE TABLE `glpi_plugin_manageentities_presaleproposals_annexes` (
   `id` int unsigned NOT NULL auto_increment,
   `plugin_manageentities_presaleproposals_id` int unsigned NOT NULL DEFAULT '0',
   `plugin_manageentities_presaleannextypes_id` int unsigned NOT NULL DEFAULT '0',
   `rank` int NOT NULL DEFAULT '0',
   `title` varchar(255) DEFAULT NULL,
   `objet` mediumtext,
   `description` mediumtext,
   `estimate` text COMMENT 'JSON estimate printed under the purpose: [{type: title|line, label, days}]',
   `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '0 = VAT not applicable',
   `plugin_manageentities_subscriptionlevels_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_subscriptionlevels (id)',
   `cloud_client` tinyint NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   KEY `plugin_manageentities_presaleproposals_id` (`plugin_manageentities_presaleproposals_id`),
   KEY `plugin_manageentities_presaleannextypes_id` (`plugin_manageentities_presaleannextypes_id`),
   KEY `plugin_manageentities_subscriptionlevels_id` (`plugin_manageentities_subscriptionlevels_id`),
   KEY `rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presaleannexlines`;
CREATE TABLE `glpi_plugin_manageentities_presaleannexlines` (
   `id` int unsigned NOT NULL auto_increment,
   `plugin_manageentities_presaleproposals_annexes_id` int unsigned NOT NULL DEFAULT '0',
   `rank` int NOT NULL DEFAULT '0',
   `designation` varchar(255) DEFAULT NULL,
   `quantity` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'number of days',
   `unit_price` decimal(20,2) NOT NULL DEFAULT '0.00' COMMENT 'daily rate excluding VAT',
   `months` decimal(10,2) DEFAULT NULL COMMENT 'cloud subscription: number of months',
   PRIMARY KEY  (`id`),
   KEY `plugin_manageentities_presaleproposals_annexes_id` (`plugin_manageentities_presaleproposals_annexes_id`),
   KEY `rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_presalecounters`;
CREATE TABLE `glpi_plugin_manageentities_presalecounters` (
   `id` int unsigned NOT NULL auto_increment,
   `prefix` varchar(3) NOT NULL DEFAULT '',
   `period` char(4) NOT NULL DEFAULT '' COMMENT 'YYMM',
   `sequence` int NOT NULL DEFAULT '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`prefix`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_dats`;
CREATE TABLE `glpi_plugin_manageentities_dats` (
   `id` int unsigned NOT NULL auto_increment,
   `entities_id` int unsigned NOT NULL DEFAULT '0',
   `version` int NOT NULL DEFAULT '0' COMMENT 'last generated version',
   `status` tinyint NOT NULL DEFAULT '1' COMMENT '1=draft, 2=generated',
   `step` tinyint NOT NULL DEFAULT '0' COMMENT 'last validated step of the wizard',
   `author_users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — author printed in the document',
   `dat_date` date DEFAULT NULL,
   `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'operating system, a DatOsTemplate::OS_* model prefilling the sections',
   `documents_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_documents (id) — last generated version',
   `sections` mediumtext COMMENT 'JSON text of each section, by placeholder',
   `users_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id) — creator',
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY  (`id`),
   UNIQUE KEY `entities_id` (`entities_id`),
   KEY `status` (`status`),
   KEY `author_users_id` (`author_users_id`),
   KEY `documents_id` (`documents_id`),
   KEY `users_id` (`users_id`),
   KEY `date_creation` (`date_creation`),
   KEY `date_mod` (`date_mod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_datservers`;
CREATE TABLE `glpi_plugin_manageentities_datservers` (
   `id` int unsigned NOT NULL auto_increment,
   `plugin_manageentities_dats_id` int unsigned NOT NULL DEFAULT '0',
   `rank` int NOT NULL DEFAULT '0',
   `name` varchar(255) DEFAULT NULL COMMENT 'DNS name',
   `internal_name` varchar(255) DEFAULT NULL COMMENT 'internal host name, the HOTE A row of the DNS table',
   `ip` varchar(255) DEFAULT NULL,
   `role` varchar(255) DEFAULT NULL COMMENT 'front, database... free text',
   PRIMARY KEY  (`id`),
   KEY `plugin_manageentities_dats_id` (`plugin_manageentities_dats_id`),
   KEY `rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_datostemplates`;
CREATE TABLE `glpi_plugin_manageentities_datostemplates` (
   `id` int unsigned NOT NULL auto_increment,
   `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'debian, ubuntu, redhat, windows',
   `sections` mediumtext COMMENT 'JSON text of each section, by placeholder',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `os` (`os`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_kifloleads`;
CREATE TABLE `glpi_plugin_manageentities_kifloleads` (
   `id` int unsigned NOT NULL auto_increment,
   `kiflo_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'id of the lead in Kiflo',
   `name` varchar(255) DEFAULT NULL COMMENT 'company name of the lead',
   `contact_name` varchar(255) DEFAULT NULL,
   `email` varchar(255) DEFAULT NULL,
   `phone` varchar(255) DEFAULT NULL,
   `origin` varchar(255) DEFAULT NULL,
   `comment` text,
   `status` varchar(50) NOT NULL DEFAULT '' COMMENT 'Pending, Accepted, Refused, Converted...',
   `source` varchar(50) DEFAULT NULL,
   `partner_name` varchar(255) DEFAULT NULL,
   `owner_name` varchar(255) DEFAULT NULL COMMENT 'owner of the lead at the publisher',
   `owner_email` varchar(255) DEFAULT NULL,
   `deal_name` varchar(255) DEFAULT NULL,
   `customer_name` varchar(255) DEFAULT NULL,
   `kiflo_customers_id` int unsigned NOT NULL DEFAULT '0' COMMENT 'id of the customer in Kiflo, once converted',
   `date_kiflo` timestamp NULL DEFAULT NULL COMMENT 'creation date in Kiflo',
   `deadline` timestamp NULL DEFAULT NULL,
   `accepted_date` timestamp NULL DEFAULT NULL,
   `converted_date` timestamp NULL DEFAULT NULL,
   `properties` text COMMENT 'JSON of the custom properties of the lead',
   `is_deleted_kiflo` tinyint NOT NULL DEFAULT '0' COMMENT 'no longer returned by Kiflo',
   `date_sync` timestamp NULL DEFAULT NULL,
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY  (`id`),
   UNIQUE KEY `kiflo_id` (`kiflo_id`),
   KEY `status` (`status`),
   KEY `kiflo_customers_id` (`kiflo_customers_id`),
   KEY `date_kiflo` (`date_kiflo`),
   KEY `is_deleted_kiflo` (`is_deleted_kiflo`),
   KEY `date_creation` (`date_creation`),
   KEY `date_mod` (`date_mod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
