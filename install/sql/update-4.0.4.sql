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
