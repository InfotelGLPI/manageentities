ALTER TABLE `glpi_plugin_manageentities_contracts`
    ADD `active_editor_suscription` tinyint NOT NULL DEFAULT '0',
    ADD `cloud_client`              tinyint NOT NULL DEFAULT '0',
    ADD `internet_publication`      tinyint NOT NULL DEFAULT '0';
