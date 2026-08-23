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

CREATE TABLE IF NOT EXISTS `glpi_plugin_manageentities_subscriptionlevels` (
   `id`                int unsigned NOT NULL auto_increment,
   `name`              varchar(255) collate utf8mb4_unicode_ci default NULL,
   `comment`           text collate utf8mb4_unicode_ci,
   `subscription_type` tinyint NOT NULL DEFAULT '0' COMMENT '0=all, 1=on_premise, 2=cloud',
   PRIMARY KEY (`id`),
   KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `glpi_plugin_manageentities_editorsubscriptions` (
   `id`                        int unsigned NOT NULL auto_increment,
   `entities_id`               int unsigned NOT NULL default '0' COMMENT 'RELATION to glpi_entities (id)',
   `name`                      varchar(255) collate utf8mb4_unicode_ci default NULL COMMENT 'Referenced name at the publisher',
   `customer_account_id`       varchar(255) collate utf8mb4_unicode_ci default NULL COMMENT 'Publisher customer account ID',
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

-- Migrate existing boolean values from contracts (deduplicated per entity)
INSERT INTO `glpi_plugin_manageentities_editorsubscriptions`
    (`entities_id`, `active_editor_suscription`, `cloud_client`, `internet_publication`)
SELECT
    c.`entities_id`,
    MAX(mc.`active_editor_suscription`),
    MAX(mc.`cloud_client`),
    MAX(mc.`internet_publication`)
FROM `glpi_plugin_manageentities_contracts` mc
JOIN `glpi_contracts` c ON c.`id` = mc.`contracts_id`
WHERE c.`is_deleted` = 0
GROUP BY c.`entities_id`
ON DUPLICATE KEY UPDATE
    `active_editor_suscription` = VALUES(`active_editor_suscription`),
    `cloud_client`              = VALUES(`cloud_client`),
    `internet_publication`      = VALUES(`internet_publication`);

-- Force internet_publication = 1 for cloud clients
UPDATE `glpi_plugin_manageentities_editorsubscriptions`
SET `internet_publication` = 1
WHERE `cloud_client` = 1 AND `internet_publication` = 0;
