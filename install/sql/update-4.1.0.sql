ALTER TABLE `glpi_plugin_manageentities_companies` CHANGE `logo_id` `documents_id` INT UNSIGNED NULL DEFAULT '0';
ALTER TABLE `glpi_plugin_manageentities_companies` DROP INDEX `logo_id`, ADD INDEX `documents_id` (`documents_id`) USING BTREE;
