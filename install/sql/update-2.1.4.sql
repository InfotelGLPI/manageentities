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

ALTER TABLE `glpi_plugin_manageentities_contracts` ADD `moving_management` tinyint(1) NOT NULL DEFAULT '0';
ALTER TABLE `glpi_plugin_manageentities_contracts` ADD `duration_moving` decimal(20,2) NOT NULL default '0';
ALTER TABLE `glpi_plugin_manageentities_cridetails` ADD  `number_moving` int(11) NOT NULL default '0' COMMENT 'Number of movements';
