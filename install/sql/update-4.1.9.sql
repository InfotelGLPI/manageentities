ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `closed_contractstate_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_plugin_manageentities_contractstates (id) — state applied to contract periods when closing';

ALTER TABLE `glpi_plugin_manageentities_configs`
    ADD `closed_glpi_state_id` int unsigned NOT NULL DEFAULT '0'
        COMMENT 'RELATION to glpi_states (id) — GLPI contract state that triggers period closure and is set when all periods are closed';
