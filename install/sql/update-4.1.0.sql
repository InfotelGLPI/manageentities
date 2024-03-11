ALTER TABLE `glpi_plugin_manageentities_companies` CHANGE `logo_id` `documents_id` INT UNSIGNED NULL DEFAULT '0';
ALTER TABLE `glpi_plugin_manageentities_companies` DROP INDEX `logo_id`, ADD INDEX `documents_id` (`documents_id`) USING BTREE;

CREATE TABLE `glpi_plugin_manageentities_contractpoints_bills`
(
    `id`                                      int unsigned NOT NULL auto_increment,
    `plugin_manageentities_contractpoints_id` int unsigned NOT NULL default '0' COMMENT ' RELATION to glpi_plugin_manageentities_contractpoints (id)',
    `documents_id` int unsigned NOT NULL default '0' COMMENT ' RELATION to glpi_documents (id)',
    `date` date NOT NULL,
    `pre_bill_points` int(11) NOT NULL,
    `post_bill_points` int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
