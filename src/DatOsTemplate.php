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
 * Model of the sections of a DAT for an operating system (Debian, Ubuntu, Red Hat, Windows): one
 * row per system, its sections in JSON as Dat::$fields['sections']. Edited in the DAT tab of the
 * configuration; the wizard fills the empty sections of a DAT with the model of the system of
 * its first server, and replaces them all on demand ("Apply the model").
 */
class DatOsTemplate extends CommonDBTM
{
    public static string $rightname = 'plugin_manageentities';

    public const OS_DEBIAN  = 'debian';
    public const OS_UBUNTU  = 'ubuntu';
    public const OS_REDHAT  = 'redhat';
    public const OS_WINDOWS = 'windows';

    public static function getTypeName($nb = 0)
    {
        return _n('DAT model', 'DAT models', $nb, 'manageentities');
    }

    /**
     * @return array<string, string>
     */
    public static function getSystems(): array
    {
        return [
            self::OS_DEBIAN  => 'Debian',
            self::OS_UBUNTU  => 'Ubuntu',
            self::OS_REDHAT  => 'Red Hat',
            self::OS_WINDOWS => 'Windows',
        ];
    }

    /**
     * Sections of the model of a system, by placeholder (those of the steps only, CLIENT
     * excluded); empty for an unknown system
     *
     * @return array<string, string>
     */
    public static function getSectionsFor(string $os): array
    {
        if (!isset(self::getSystems()[$os])) {
            return [];
        }
        $model = new self();
        $rows  = $model->find(['os' => $os], [], 1);
        $row   = reset($rows);
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
        foreach (array_keys(self::getSystems()) as $os) {
            $models[$os] = self::getSectionsFor($os);
        }

        return $models;
    }

    /**
     * Saves the models posted by the DAT tab of the configuration: dat_models[os][placeholder].
     * Unknown systems and placeholders are dropped.
     */
    public static function saveAll(array $posted): void
    {
        global $DB;

        foreach (array_keys(self::getSystems()) as $os) {
            if (!isset($posted[$os]) || !is_array($posted[$os])) {
                continue;
            }
            $sections = [];
            foreach (self::getPlaceholders() as $placeholder) {
                $value = (string) ($posted[$os][$placeholder] ?? '');
                $sections[$placeholder] = DatWizard::getSectionKind($placeholder) === DatWizard::KIND_CODE
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
        return array_merge(...array_values(DatWizard::getStepSections()));
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
        $debian = static fn(string $system, string $php_fpm): array => [
            'SYSTEM_INFORMATIONS'  => '<p>' . $system . ' (64 bits)</p>',
            'PAQUETS_INSTALLATION' => "apt update && apt full-upgrade -y\napt install -y apache2 mariadb-server php php-fpm php-cli php-mysql php-ldap php-imap php-curl php-gd php-intl php-mbstring php-xml php-zip php-bz2 php-apcu php-bcmath",
            'APACHE_INFORMATIONS'  => '<p>Apache 2.4, PHP en PHP-FPM, virtual host dédié à GLPI (racine : /var/www/glpi/public).</p>',
            'APACHE_INSTALLATION'  => "a2enmod rewrite proxy_fcgi setenvif\na2enconf {$php_fpm}\nvi /etc/apache2/sites-available/glpi.conf\na2ensite glpi.conf\na2dissite 000-default.conf\nsystemctl restart apache2",
            'PHP_INFORMATIONS'     => '<p>PHP-FPM, configuration dans /etc/php/&lt;version&gt;/fpm/php.ini.</p>',
            'PHP_INSTALLATION'     => "memory_limit = 256M\nupload_max_filesize = 20M\npost_max_size = 20M\nmax_execution_time = 600\nsession.cookie_httponly = On\nsession.cookie_secure = On\ndate.timezone = Europe/Paris",
            'MYSQL_INFORMATIONS'   => '<p>MariaDB, base glpi en utf8mb4, utilisateur dédié.</p>',
            'MYSQL_INSTALLATION'   => "mysql_secure_installation\nmysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql\nmysql -u root -e \"CREATE DATABASE glpi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\nmysql -u root -e \"CREATE USER 'glpi'@'localhost' IDENTIFIED BY '********';\"\nmysql -u root -e \"GRANT ALL PRIVILEGES ON glpi.* TO 'glpi'@'localhost'; GRANT SELECT ON mysql.time_zone_name TO 'glpi'@'localhost';\"",
            'CRON_INSTALLATION'    => '* * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php &>/dev/null',
            'LOGS_INSTALLATION'    => "/var/log/apache2/\n/var/www/glpi/files/_log/",
            'HTTPS_INSTALLATION'   => "a2enmod ssl\nvi /etc/apache2/sites-available/glpi-ssl.conf\na2ensite glpi-ssl.conf\nsystemctl reload apache2",
            'NTP_INSTALLATION'     => "apt install -y systemd-timesyncd\nvi /etc/systemd/timesyncd.conf\ntimedatectl set-ntp true\ntimedatectl status",
            'FILES_INSTALLATION'   => "vi /etc/php/<version>/fpm/php.ini\nvi /etc/apache2/sites-available/glpi.conf",
            'SSO_TECHNOLOGY'       => '',
            'SSO_INSTALLATION'     => '',
        ];

        $redhat = [
            'SYSTEM_INFORMATIONS'  => '<p>Red Hat Enterprise Linux (64 bits), SELinux actif.</p>',
            'PAQUETS_INSTALLATION' => "dnf update -y\ndnf install -y httpd mariadb-server php php-fpm php-cli php-mysqlnd php-ldap php-imap php-gd php-intl php-mbstring php-xml php-zip php-bcmath php-pecl-apcu php-sodium",
            'APACHE_INFORMATIONS'  => '<p>Apache 2.4 (httpd), PHP en PHP-FPM, virtual host dédié à GLPI (racine : /var/www/glpi/public).</p>',
            'APACHE_INSTALLATION'  => "vi /etc/httpd/conf.d/glpi.conf\nsystemctl enable --now httpd php-fpm\nfirewall-cmd --permanent --add-service=http --add-service=https\nfirewall-cmd --reload\nsetsebool -P httpd_can_network_connect on\nsetsebool -P httpd_can_network_connect_db on\nsemanage fcontext -a -t httpd_sys_rw_content_t \"/var/www/glpi/files(/.*)?\"\nrestorecon -Rv /var/www/glpi",
            'PHP_INFORMATIONS'     => '<p>PHP-FPM, configuration dans /etc/php.ini.</p>',
            'PHP_INSTALLATION'     => "memory_limit = 256M\nupload_max_filesize = 20M\npost_max_size = 20M\nmax_execution_time = 600\nsession.cookie_httponly = On\nsession.cookie_secure = On\ndate.timezone = Europe/Paris",
            'MYSQL_INFORMATIONS'   => '<p>MariaDB, base glpi en utf8mb4, utilisateur dédié.</p>',
            'MYSQL_INSTALLATION'   => "systemctl enable --now mariadb\nmysql_secure_installation\nmysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql\nmysql -u root -e \"CREATE DATABASE glpi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\nmysql -u root -e \"CREATE USER 'glpi'@'localhost' IDENTIFIED BY '********';\"\nmysql -u root -e \"GRANT ALL PRIVILEGES ON glpi.* TO 'glpi'@'localhost'; GRANT SELECT ON mysql.time_zone_name TO 'glpi'@'localhost';\"",
            'CRON_INSTALLATION'    => '* * * * * apache /usr/bin/php /var/www/glpi/front/cron.php &>/dev/null',
            'LOGS_INSTALLATION'    => "/var/log/httpd/\n/var/www/glpi/files/_log/",
            'HTTPS_INSTALLATION'   => "dnf install -y mod_ssl\nvi /etc/httpd/conf.d/ssl.conf\nsystemctl reload httpd",
            'NTP_INSTALLATION'     => "dnf install -y chrony\nvi /etc/chrony.conf\nsystemctl enable --now chronyd\nchronyc sources",
            'FILES_INSTALLATION'   => "vi /etc/php.ini\nvi /etc/httpd/conf.d/glpi.conf",
            'SSO_TECHNOLOGY'       => '',
            'SSO_INSTALLATION'     => '',
        ];

        $windows = [
            'SYSTEM_INFORMATIONS'  => '<p>Windows Server (64 bits).</p>',
            'PAQUETS_INSTALLATION' => "Apache Lounge (httpd 2.4, VS17) : C:\\Apache24\nPHP thread safe (VS17 x64) : C:\\php\nMariaDB (installeur MSI) : C:\\Program Files\\MariaDB\nVisual C++ Redistributable",
            'APACHE_INFORMATIONS'  => '<p>Apache 2.4 installé en service Windows, virtual host dédié à GLPI (racine : C:\\Apache24\\htdocs\\glpi\\public).</p>',
            'APACHE_INSTALLATION'  => "notepad C:\\Apache24\\conf\\httpd.conf\nnotepad C:\\Apache24\\conf\\extra\\httpd-vhosts.conf\nC:\\Apache24\\bin\\httpd.exe -k install\nnet start Apache2.4",
            'PHP_INFORMATIONS'     => '<p>PHP chargé par Apache (php8apache2_4.dll), configuration dans C:\\php\\php.ini.</p>',
            'PHP_INSTALLATION'     => "memory_limit = 256M\nupload_max_filesize = 20M\npost_max_size = 20M\nmax_execution_time = 600\nsession.cookie_httponly = On\nsession.cookie_secure = On\ndate.timezone = Europe/Paris\nextension=curl, fileinfo, gd, intl, ldap, mbstring, mysqli, openssl, zip, bz2, sodium",
            'MYSQL_INFORMATIONS'   => '<p>MariaDB installé en service Windows, base glpi en utf8mb4, utilisateur dédié.</p>',
            'MYSQL_INSTALLATION'   => "mysql -u root -p -e \"CREATE DATABASE glpi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"\nmysql -u root -p -e \"CREATE USER 'glpi'@'localhost' IDENTIFIED BY '********';\"\nmysql -u root -p -e \"GRANT ALL PRIVILEGES ON glpi.* TO 'glpi'@'localhost';\"",
            'CRON_INSTALLATION'    => "schtasks /create /tn \"GLPI cron\" /sc minute /mo 1 /ru SYSTEM /tr \"C:\\php\\php.exe C:\\Apache24\\htdocs\\glpi\\front\\cron.php\"",
            'LOGS_INSTALLATION'    => "C:\\Apache24\\logs\\\nC:\\Apache24\\htdocs\\glpi\\files\\_log\\",
            'HTTPS_INSTALLATION'   => "notepad C:\\Apache24\\conf\\extra\\httpd-ssl.conf\nLoadModule ssl_module modules/mod_ssl.so\nInclude conf/extra/httpd-ssl.conf\nnet stop Apache2.4 && net start Apache2.4",
            'NTP_INSTALLATION'     => "w32tm /config /manualpeerlist:\"pool.ntp.org\" /syncfromflags:manual /update\nw32tm /resync\nw32tm /query /status",
            'FILES_INSTALLATION'   => "notepad C:\\php\\php.ini\nnotepad C:\\Apache24\\conf\\extra\\httpd-vhosts.conf",
            'SSO_TECHNOLOGY'       => '',
            'SSO_INSTALLATION'     => '',
        ];

        return [
            self::OS_DEBIAN  => $debian('Debian 12 (Bookworm)', 'php8.2-fpm'),
            self::OS_UBUNTU  => $debian('Ubuntu Server 24.04 LTS', 'php8.3-fpm'),
            self::OS_REDHAT  => $redhat,
            self::OS_WINDOWS => $windows,
        ];
    }
}
