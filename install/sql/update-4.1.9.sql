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

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `closed_contractstate_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — state applied to contract periods when closing';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `closed_glpi_state_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_states (id) — GLPI contract state that triggers period closure and is set when all periods are closed';
