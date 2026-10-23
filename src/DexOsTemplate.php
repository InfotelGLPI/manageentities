<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use DBConnection;
use Migration;

/**
 * Model of the sections of a DEX for an operating system: the same systems as the DAT models
 * (DatOsTemplate::getSystems()), one row per system, its sections in JSON as
 * Dex::$fields['sections']. Edited in the DEX tab of the configuration; the wizard fills the empty
 * sections of a DEX with the model of its system, and replaces them all on demand ("Apply the
 * model").
 */
class DexOsTemplate extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('DEX model', 'DEX models', $nb, 'manageentities');
    }

    /**
     * Sections of the model of a system, by placeholder (those of the steps only, CLIENT
     * excluded); empty for an unknown system
     *
     * @return array<string, string>
     */
    public static function getSectionsFor(string $os): array
    {
        if (!isset(DatOsTemplate::getSystems()[$os])) {
            return [];
        }
        $model  = new self();
        $rows   = $model->find(['os' => $os], [], 1);
        $row    = reset($rows);
        $stored = $row !== false ? json_decode((string) $row['sections'], true) : null;
        $stored = is_array($stored) ? $stored : [];

        $sections = [];
        foreach (self::getPlaceholders() as $placeholder) {
            $sections[$placeholder] = (string) ($stored[$placeholder] ?? '');
        }

        return $sections;
    }

    /**
     * Sections of every model, for the configuration tab
     *
     * @return array<string, array<string, string>>
     */
    public static function getAll(): array
    {
        $models = [];
        foreach (array_keys(DatOsTemplate::getSystems()) as $os) {
            $models[$os] = self::getSectionsFor($os);
        }

        return $models;
    }

    /**
     * Saves the models posted by the DEX tab of the configuration: dex_models[os][placeholder].
     * Unknown systems and placeholders are dropped.
     */
    public static function saveAll(array $posted): void
    {
        global $DB;

        foreach (array_keys(DatOsTemplate::getSystems()) as $os) {
            if (!isset($posted[$os]) || !is_array($posted[$os])) {
                continue;
            }
            $sections = [];
            foreach (self::getPlaceholders() as $placeholder) {
                $value = (string) ($posted[$os][$placeholder] ?? '');
                $sections[$placeholder] = DexWizard::getSectionKind($placeholder) === DatWizard::KIND_CODE
                    ? rtrim(str_replace("\r\n", "\n", $value))
                    : trim($value);
            }
            $DB->updateOrInsert(
                self::getTable(),
                ['sections' => json_encode($sections)],
                ['os' => $os],
            );
        }
    }

    /**
     * Placeholders a model fills: the sections of the wizard steps
     *
     * @return list<string>
     */
    public static function getPlaceholders(): array
    {
        return array_merge(...array_values(DexWizard::getStepSections()));
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table             = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `os` varchar(50) NOT NULL DEFAULT '' COMMENT 'debian, ubuntu, redhat, windows',
                            `sections` mediumtext COMMENT 'JSON text of each section, by placeholder',
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `os` (`os`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Default content, only for a system without a model yet: an edited model is kept
        foreach (self::getDefaults() as $os => $sections) {
            if (countElementsInTable($table, ['os' => $os]) === 0) {
                $DB->insert($table, ['os' => $os, 'sections' => json_encode($sections)]);
            }
        }
    }

    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }

    /**
     * Content of the models at installation, to be adjusted in the configuration
     *
     * @return array<string, array<string, string>>
     */
    private static function getDefaults(): array
    {
        // Common to every system: what GLPI itself needs to be operated
        $folders = '<p>Dossiers de GLPI à surveiller (espace disque, droits d\'écriture du serveur web, croissance anormale) :</p>'
            . '<ul><li>files/_log : journaux de GLPI (php-errors, sql-errors, cron, mail) ;</li>'
            . '<li>files/_sessions : sessions PHP ;</li>'
            . '<li>files/_tmp et files/_cache : fichiers temporaires et cache ;</li>'
            . '<li>files/_dumps, files/_pictures, files/_uploads et les documents de files/ ;</li>'
            . '<li>config/ : configuration et clé de chiffrement glpicrypt.key.</li></ul>';
        $scripts = '<p>Tâche planifiée de GLPI (front/cron.php), exécutée chaque minute : vérifier dans Configuration &gt; Actions automatiques qu\'aucune action n\'est bloquée ou en erreur, et suivre le journal files/_log/cron.log.</p>'
            . '<p>Script de sauvegarde de la base et des fichiers : vérifier chaque jour son exécution et la taille des sauvegardes produites.</p>';
        $epurations = '<p>Épurations système : rotation des journaux du serveur web et de PHP (logrotate), suppression des sauvegardes au-delà de la durée de rétention.</p>'
            . '<p>Épurations GLPI, réglées dans l\'interface : journaux et événements (Configuration &gt; Générale &gt; Journalisation), notifications envoyées (file des notifications), fichiers temporaires (action automatique cleantemp).</p>';
        $backups = '<p>Contenu de la sauvegarde, indispensable à une restauration :</p>'
            . '<ul><li>la base de données de GLPI (dump complet) ;</li>'
            . '<li>le dossier files/ (documents, images, journaux) ;</li>'
            . '<li>le dossier config/, dont la clé glpicrypt.key : sans elle, les mots de passe chiffrés (annuaire LDAP, collecteurs, SMTP) sont perdus après une restauration ;</li>'
            . '<li>les plugins installés (dossiers plugins/ et marketplace/).</li></ul>'
            . '<p>Préciser la fréquence, la durée de rétention, le lieu de stockage et la fréquence des tests de restauration.</p>';
        $check     = '<p>Vérifier que les services du serveur web, de PHP et de la base de données sont démarrés :</p>';
        $restart_a = '<p>Redémarrage du serveur web et de PHP-FPM, par exemple après une modification de la configuration de PHP :</p>';
        $restart_m = '<p>Redémarrage du serveur de base de données (interrompt GLPI le temps du redémarrage) :</p>';
        $cron      = '<p>Lancement manuel des actions automatiques de GLPI, par exemple pour vider la file des notifications ou relancer un collecteur :</p>';
        $backup    = '<p>Sauvegarde manuelle de la base de données de GLPI, par exemple avant une montée de version :</p>';
        $maintenance = '<p>Le mode maintenance coupe l\'accès à GLPI pour tous les utilisateurs, à l\'exception des super-administrateurs (Configuration &gt; Générale &gt; Mode maintenance) ; il s\'active aussi en ligne de commande, depuis le dossier de GLPI :</p>'
            . '<ul><li>activation : php bin/console maintenance:enable ;</li>'
            . '<li>désactivation : php bin/console maintenance:disable.</li></ul>';
        $preprod = '<p>Copie des données de production vers la préproduction :</p>'
            . '<ol><li>sauvegarder la base de données, le dossier files/ et le dossier config/ (dont glpicrypt.key) de la production ;</li>'
            . '<li>restaurer la base et les dossiers sur la préproduction, avec la même version de GLPI et des plugins ;</li>'
            . '<li>adapter config/config_db.php à la base de préproduction ;</li>'
            . '<li><strong>avant toute ouverture aux utilisateurs</strong> : désactiver les notifications, les collecteurs de courriels et les actions automatiques de la préproduction, sinon elle écrit aux vrais utilisateurs et relève la vraie boîte de collecte ;</li>'
            . '<li>modifier l\'URL de l\'application (Configuration &gt; Générale) et, au besoin, anonymiser les données.</li></ol>';

        $linux = static fn(string $system, string $web, string $php_fpm, string $db, string $user, string $logs): array => [
            'SYSTEM_INFORMATIONS'          => '<p>' . $system . ' (64 bits)</p>',
            'TEXT_PROCESSUS'               => '<p>Processus utilisés par GLPI, à superviser :</p>',
            'LIST_PROCESSUS'               => "{$web} (serveur web)\n{$php_fpm} (PHP-FPM)\nmariadbd (base de données)\ncron (tâches planifiées)",
            'TEXT_FOLDERS'                 => $folders . '<p>Journaux système : ' . $logs . '.</p>',
            'TEXT_SCRIPTS'                 => $scripts,
            'TEXT_EPURATIONS'              => $epurations,
            'TEXT_BACKUPS'                 => $backups,
            'TEXT_CHECK_PROCESSUS'         => $check,
            'COMMAND_CHECK_PROCESSUS'      => "ps aux | grep -E \"{$web}|mariadbd|php-fpm\"\nsystemctl status {$web} {$php_fpm} {$db}",
            'TEXT_RESTART_APACHE'          => $restart_a,
            'COMMAND_RESTART_APACHE'       => "systemctl restart {$php_fpm}\nsystemctl restart {$web}",
            'TEXT_RESTART_MYSQL'           => $restart_m,
            'COMMAND_RESTART_MYSQL'        => "systemctl restart {$db}",
            'TEXT_LAUNCH_CRON'             => $cron,
            'COMMAND_LAUNCH_CRON'          => "cd /var/www/glpi\nsudo -u {$user} php front/cron.php\nsudo -u {$user} php front/cron.php queuednotification",
            'TEXT_LAUNCH_BACKUP'           => $backup,
            'COMMAND_LAUNCH_BACKUP'        => "mysqldump --single-transaction --routines --triggers -u root -p glpi | gzip > /var/backups/glpi_$(date +%Y%m%d_%H%M).sql.gz\ntar czf /var/backups/glpi_files_$(date +%Y%m%d_%H%M).tar.gz -C /var/www/glpi files config",
            'TEXT_LAUNCH_MAINTENANCE_MODE' => $maintenance,
            'TEXT_PREPROD_COPY_DATAS'      => $preprod,
        ];

        $windows = [
            'SYSTEM_INFORMATIONS'          => '<p>Windows Server (64 bits).</p>',
            'TEXT_PROCESSUS'               => '<p>Processus et services utilisés par GLPI, à superviser :</p>',
            'LIST_PROCESSUS'               => "httpd.exe (service Apache2.4)\nmysqld.exe (service MariaDB)\nphp.exe (tâche planifiée GLPI cron)",
            'TEXT_FOLDERS'                 => $folders . '<p>Journaux système : C:\\Apache24\\logs\\ et l\'observateur d\'événements Windows.</p>',
            'TEXT_SCRIPTS'                 => $scripts,
            'TEXT_EPURATIONS'              => $epurations,
            'TEXT_BACKUPS'                 => $backups,
            'TEXT_CHECK_PROCESSUS'         => $check,
            'COMMAND_CHECK_PROCESSUS'      => "Get-Service Apache2.4, MariaDB\nGet-Process httpd, mysqld",
            'TEXT_RESTART_APACHE'          => $restart_a,
            'COMMAND_RESTART_APACHE'       => "net stop Apache2.4\nnet start Apache2.4",
            'TEXT_RESTART_MYSQL'           => $restart_m,
            'COMMAND_RESTART_MYSQL'        => "net stop MariaDB\nnet start MariaDB",
            'TEXT_LAUNCH_CRON'             => $cron,
            'COMMAND_LAUNCH_CRON'          => "cd C:\\Apache24\\htdocs\\glpi\nC:\\php\\php.exe front\\cron.php\nschtasks /run /tn \"GLPI cron\"",
            'TEXT_LAUNCH_BACKUP'           => $backup,
            'COMMAND_LAUNCH_BACKUP'        => "mysqldump --single-transaction --routines --triggers -u root -p glpi > D:\\Backup\\glpi.sql\nCompress-Archive -Path C:\\Apache24\\htdocs\\glpi\\files, C:\\Apache24\\htdocs\\glpi\\config -DestinationPath D:\\Backup\\glpi_files.zip",
            'TEXT_LAUNCH_MAINTENANCE_MODE' => $maintenance,
            'TEXT_PREPROD_COPY_DATAS'      => $preprod,
        ];

        return [
            DatOsTemplate::OS_DEBIAN  => $linux('Debian 12 (Bookworm)', 'apache2', 'php8.2-fpm', 'mariadb', 'www-data', '/var/log/apache2/'),
            DatOsTemplate::OS_UBUNTU  => $linux('Ubuntu Server 24.04 LTS', 'apache2', 'php8.3-fpm', 'mariadb', 'www-data', '/var/log/apache2/'),
            DatOsTemplate::OS_REDHAT  => $linux('Red Hat Enterprise Linux (64 bits), SELinux actif', 'httpd', 'php-fpm', 'mariadb', 'apache', '/var/log/httpd/'),
            DatOsTemplate::OS_WINDOWS => $windows,
        ];
    }
}
