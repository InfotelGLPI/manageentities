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
    ADD `wizard_contractstate_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — default intervention state in wizard';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_contract_type` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — default intervention type in wizard';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_critype_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_critypes (id) — default CriType for rate in wizard';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_documentcategories_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_documentcategories (id) — default document category in wizard';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_contacttypes_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_contacttypes (id) — default contact type in wizard';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_default_entities_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_entities (id) — parent entity pre-selected and locked in wizard step 1';

ALTER TABLE `glpi_plugin_manageentities_contracts`
    ADD `active_editor_suscription` tinyint NOT NULL DEFAULT '0',
    ADD `cloud_client`              tinyint NOT NULL DEFAULT '0',
    ADD `internet_publication`      tinyint NOT NULL DEFAULT '0';
    
ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `wizard_archive_entities_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_entities (id) — entity used to archive customers';
