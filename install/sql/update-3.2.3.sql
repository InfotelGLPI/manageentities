CREATE TABLE `glpi_plugin_manageentities_contractpoints`
(
    `id`                 int unsigned NOT NULL auto_increment,
    `contracts_id`       int unsigned NOT NULL default '0' COMMENT ' RELATION to glpi_contracts (id)',
    `entities_id`        int unsigned NOT NULL default '0',
    `renewal_number`     int(11) NOT NULL default '0',
    `initial_credit`     int(11) NOT NULL default '0',
    `current_credit`     int(11) NOT NULL default '0',
    `credit_consumed`    int(11) NOT NULL default '0',
    `contract_type`      int(11) NOT NULL default '0',
    `minutes_slice`      int(11) NOT NULL default '0',
    `points_slice`       int(11) NOT NULL default '0',
    `contact_email`      varchar(255) NOT NULL default '',
    `contract_cancelled` tinyint(1) NOT NULL default '0',
    `threshold`          int(11) NOT NULL default '0',
    `picture_logo`       varchar(255) NOT NULL default '',
    `footer`             TEXT,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unicity` (`contracts_id`,`entities_id`),
    KEY                  `contracts_id` (`contracts_id`),
    KEY                  `entities_id` (`entities_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE `glpi_plugin_manageentities_mappingcategoryslices`
(
    `id`                                      int unsigned NOT NULL auto_increment,
    `taskcategories_id`                       int unsigned NOT NULL default '0' COMMENT ' RELATION to glpi_taskcategories (id)',
    `plugin_manageentities_contractpoints_id` int unsigned NOT NULL default '0' COMMENT ' RELATION to glpi_plugin_manageentities_contractpoints (id)',
    `minutes_slice`                           int(11) NOT NULL default '0',
    `points_slice`                            int(11) NOT NULL default '0',

    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `date_to_generate_contract` int(11) NOT NULL default '1';
ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `picture_logo` varchar(255) NOT NULL default '';
ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `footer` TEXT;
ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `category_outOfContract` int(11) NOT NULL default '0';
ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `email_billing_destination` varchar(255) NOT NULL default '';
