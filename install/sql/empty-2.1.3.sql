DROP TABLE IF EXISTS `glpi_plugin_manageentities_contracts`;
CREATE TABLE `glpi_plugin_manageentities_contracts` (
   `id` int(11) NOT NULL auto_increment,
   `contracts_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_contracts (id)',
   `entities_id` int(11) NOT NULL default '0',
   `is_default` tinyint(1) NOT NULL default '0',
   `management` tinyint(1) NOT NULL default '0' COMMENT 'for the management mode (quarterly or annual or not)',
   `contract_type` tinyint(1) NOT NULL default '0' COMMENT 'for the contract type (hour, intervention, unlimited or not)',
   `date_signature` date default NULL,
   `date_renewal` date default NULL,
   `contract_added` tinyint(1) NOT NULL default '0',
   `show_on_global_gantt` tinyint(1) NOT NULL DEFAULT '0',
   `refacturable_costs` tinyint(1) NOT NULL default '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`contracts_id`,`entities_id`),
   KEY `contracts_id` (`contracts_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contacts`;
CREATE TABLE `glpi_plugin_manageentities_contacts` (
   `id` int(11) NOT NULL auto_increment,
   `contacts_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_contacts (id)',
   `entities_id` int(11) NOT NULL default '0',
   `is_default` tinyint(1) NOT NULL default '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`entities_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_businesscontacts`;
CREATE TABLE `glpi_plugin_manageentities_businesscontacts` (
   `id` int(11) NOT NULL auto_increment,
   `users_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
   `entities_id` int(11) NOT NULL default '0',
   `is_default` tinyint(1) NOT NULL default '0',
   PRIMARY KEY  (`id`),
   UNIQUE KEY `unicity` (`users_id`,`entities_id`),
   KEY `users_id` (`users_id`),
   KEY `entities_id` (`entities_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_preferences`;
CREATE TABLE `glpi_plugin_manageentities_preferences` (
   `id` int(11) NOT NULL auto_increment,
   `users_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
   `show_on_load` int(11) NOT NULL default '0',
   `contract_states` text default NULL,
   `business_id` text default NULL,
   `companies_id` text default NULL,
   PRIMARY KEY  (`id`),
   KEY `users_id` (`users_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_configs`;
CREATE TABLE `glpi_plugin_manageentities_configs` (
   `id` int(11) NOT NULL auto_increment,
   `backup` int(11) NOT NULL default '0',
   `documentcategories_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_documentcategories (id)',
   `useprice` tinyint(1) NOT NULL default '1' COMMENT 'default for yes',
   `hourorday` tinyint(1) NOT NULL default '0' COMMENT 'default for day',
   `hourbyday` int(11) NOT NULL default '0' COMMENT 'if hourorday == 0 then must be different of 0',
   `needvalidationforcri` tinyint(1) NOT NULL default '0' COMMENT 'only CRI with validated ticket are taking into account for consumption calculation',
   `use_publictask` tinyint(1) NOT NULL default '0' COMMENT 'default for no',
   `allow_same_periods` tinyint(1) NOT NULL default '0' COMMENT 'allow interventions on the same interval of dates',
   `contract_states` text default NULL,
   `business_id` text default NULL,
   `choice_intervention` int(11) default NULL,
   `comment` tinyint(1) NOT NULL default '1' COMMENT 'display comments in the CRI',
   PRIMARY KEY  (`id`),
   KEY `documentcategories_id` (`documentcategories_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `glpi_plugin_manageentities_configs` (`id`,`backup`,`documentcategories_id`,`hourorday`,`hourbyday`,`needvalidationforcri`) VALUES ('1', '0','-1','0','8','0');

DROP TABLE IF EXISTS `glpi_plugin_manageentities_critypes`;
CREATE TABLE `glpi_plugin_manageentities_critypes` (
   `id` int(11) NOT NULL auto_increment,
   `name` varchar(255) collate utf8_unicode_ci default NULL,
   `comment` text collate utf8_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_criprices`;
CREATE TABLE `glpi_plugin_manageentities_criprices` (
   `id` int(11) NOT NULL auto_increment,
   `entities_id` int(11) NOT NULL default '0',
   `plugin_manageentities_critypes_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `plugin_manageentities_contractdays_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
   `price` decimal(20,4) NOT NULL default '0.0000',
   `is_default` tinyint(1) NOT NULL default '0',
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contractdays`;
CREATE TABLE `glpi_plugin_manageentities_contractdays` (
   `id` int(11) NOT NULL auto_increment,
   `entities_id` int(11) NOT NULL default '0',
   `name` varchar(255) collate utf8_unicode_ci default NULL,
   `plugin_manageentities_critypes_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `contracts_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_contracts (id)',
   `plugin_manageentities_contractstates_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id)',
   `begin_date` date default NULL,
   `end_date` date default NULL,
   `nbday` decimal(20,2) default '0.00',
   `report` decimal(20,2) default '0.00',
   `charged` tinyint(1) NOT NULL DEFAULT '0',
   `comment` text,
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `contracts_id` (`contracts_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`),
   KEY `plugin_manageentities_contractstates_id` (`plugin_manageentities_contractstates_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_critechnicians`;
CREATE TABLE `glpi_plugin_manageentities_critechnicians` (
   `id` int(11) NOT NULL auto_increment,
   `tickets_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_tickets (id)',
   `users_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
   PRIMARY KEY  (`id`),
   KEY `tickets_id` (`tickets_id`),
   KEY `users_id` (`users_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;



DROP TABLE IF EXISTS `glpi_plugin_manageentities_interventionskateholders`;
CREATE TABLE `glpi_plugin_manageentities_interventionskateholders` (
   `id` int(11) NOT NULL auto_increment,
   `users_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
   `number_affected_days` double NOT NULL default '0' COMMENT 'Number of days affected to the user to an intervention',
   `plugin_manageentities_contractdays_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
   PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;



DROP TABLE IF EXISTS `glpi_plugin_manageentities_cridetails`;
CREATE TABLE `glpi_plugin_manageentities_cridetails` (
   `id` int(11) NOT NULL auto_increment,
   `entities_id` int(11) NOT NULL default '0',
   `date` date default NULL,
   `documents_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_documents (id)',
   `plugin_manageentities_contractdays_id` int(11) NOT NULL default '0',
   `plugin_manageentities_critypes_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id)',
   `withcontract` int(11) NOT NULL default '0',
   `contracts_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_contracts (id)',
   `realtime` decimal(20,2) default '0.00',
   `technicians` varchar(255) collate utf8_unicode_ci default NULL,
   `tickets_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_tickets (id)',
   PRIMARY KEY  (`id`),
   KEY `entities_id` (`entities_id`),
   KEY `documents_id` (`documents_id`),
   KEY `plugin_manageentities_critypes_id` (`plugin_manageentities_critypes_id`),
   KEY `plugin_manageentities_contractdays_id` (`plugin_manageentities_contractdays_id`),
   KEY `tickets_id` (`tickets_id`),
   KEY `contracts_id` (`contracts_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_contractstates`;
CREATE TABLE `glpi_plugin_manageentities_contractstates` (
   `id` int(11) NOT NULL auto_increment,
   `name` varchar(255) collate utf8_unicode_ci default NULL,
   `is_active` tinyint(1) NOT NULL default '0',
   `is_closed` tinyint(1) NOT NULL default '0',
   `color` varchar(7) default '#F2F2F2',
   `comment` text collate utf8_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `name` (`name`),
   KEY `is_active` (`is_active`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_taskcategories`;
CREATE TABLE `glpi_plugin_manageentities_taskcategories` (
   `id` int(11) NOT NULL auto_increment,
   `taskcategories_id` int(11) NOT NULL default '0' COMMENT 'RELATION to  glpi_taskcategories (id)',
   `is_usedforcount` tinyint(1) NOT NULL default '0',
   PRIMARY KEY  (`id`),
   KEY `taskcategories_id` (`taskcategories_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_companies`;
CREATE TABLE `glpi_plugin_manageentities_companies` (
   `id` int(11) NOT NULL auto_increment,
   `name` varchar(255) collate utf8_unicode_ci default NULL,
   `address` text collate utf8_unicode_ci COMMENT 'address of the company shown on CRI',
   `entity_id` text default NULL,
   `recursive` int(11) default 0,
   `logo_id` int(11) default 0 COMMENT 'RELATION to glpi_documents',
   `comment` text collate utf8_unicode_ci,
   PRIMARY KEY  (`id`),
   KEY `logo_id` (`logo_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `glpi_plugin_manageentities_entitylogos`;
CREATE TABLE `glpi_plugin_manageentities_entitylogos` (
   `id` int(11) NOT NULL auto_increment,
   `entities_id` int(11) NOT NULL default '0',
   `logos_id` int(11) default 0 COMMENT 'RELATION to glpi_documents',
   PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;