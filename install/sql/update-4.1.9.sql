ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `closed_contractstate_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — state applied when closing a contract period or GLPI contract';
