CREATE TABLE `glpi_plugin_manageentities_directhelpdesks` (
    `id` int unsigned NOT NULL auto_increment,
    `users_id` int unsigned NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
    `entities_id` int unsigned NOT NULL default '0',
    `name` varchar(255) collate utf8mb4_unicode_ci default NULL,
    `comment` text collate utf8mb4_unicode_ci,
    `is_billed` tinyint NOT NULL default '0',
    `date` timestamp NULL DEFAULT NULL,
    `actiontime` int NOT NULL DEFAULT '0',
    `tickets_id` int unsigned NOT NULL default '0' COMMENT 'RELATION to glpi_tickets (id)',
    `date_mod` timestamp NULL DEFAULT NULL,
    `date_creation` timestamp NULL DEFAULT NULL,
    PRIMARY KEY  (`id`),
    KEY `entities_id` (`entities_id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `glpi_plugin_manageentities_directhelpdesks_tickets` (
    `id` int unsigned NOT NULL auto_increment,
    `tickets_id` int unsigned NOT NULL default '0' COMMENT 'RELATION to glpi_tickets (id)',
    `plugin_manageentities_directhelpdesks_id` int unsigned NOT NULL default '0',
    PRIMARY KEY  (`id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `plugin_manageentities_directhelpdesks_id` (`plugin_manageentities_directhelpdesks_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
