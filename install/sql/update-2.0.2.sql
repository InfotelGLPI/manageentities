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

DROP TABLE IF EXISTS `glpi_plugin_manageentities_interventionskateholders`;
CREATE TABLE `glpi_plugin_manageentities_interventionskateholders` (
   `id` int(11) NOT NULL auto_increment,
   `users_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_users (id)',
   `number_affected_days` double NOT NULL default '0' COMMENT 'Number of days affected to the user to an intervention',
   `plugin_manageentities_contractdays_id` int(11) NOT NULL default '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
   PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `glpi_plugin_manageentities_contracts` ADD  `show_on_global_gantt` tinyint(1) NOT NULL DEFAULT '0';
ALTER TABLE `glpi_plugin_manageentities_contractdays` ADD  `charged` tinyint(1) NOT NULL DEFAULT '0';
