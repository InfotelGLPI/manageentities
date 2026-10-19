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
use CronTask;
use DateTimeImmutable;
use DateTimeZone;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Migration;
use RuntimeException;
use Session;
use Throwable;
use Toolbox;

/**
 * Local copy of the leads the publisher shares with us in Kiflo.
 *
 * The dashboards read this table only, never the API: it is refreshed by the KifloSync
 * automatic action, or on demand from the configuration and the leads tab.
 */
class KifloLead extends CommonDBTM
{
    public static string $rightname = 'plugin_manageentities';

    /** Statuses of a lead still to be worked on */
    public const OPEN_STATUSES = ['Pending', 'Accepted', 'Unassigned', 'Reassigned'];

    public static function getTypeName($nb = 0)
    {
        return _n('Kiflo lead', 'Kiflo leads', $nb, 'manageentities');
    }

    public static function getIcon()
    {
        return 'ti ti-target-arrow';
    }

    public static function canView(): bool
    {
        return Config::useKiflo() && Session::haveRight(self::$rightname, READ);
    }

    /** Filled by the synchronization only */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /**
     * Labels of the Kiflo statuses
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'Pending'    => __('Pending', 'manageentities'),
            'Accepted'   => __('Accepted', 'manageentities'),
            'Unassigned' => __('Unassigned', 'manageentities'),
            'Reassigned' => __('Reassigned', 'manageentities'),
            'Refused'    => __('Refused', 'manageentities'),
            'Converted'  => __('Converted', 'manageentities'),
            'Abandoned'  => __('Abandoned', 'manageentities'),
        ];
    }

    /**
     * Tabler color of the badge of a status
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'Pending'                 => 'orange',
            'Accepted', 'Reassigned' => 'blue',
            'Unassigned'              => 'purple',
            'Converted'               => 'green',
            'Refused', 'Abandoned'   => 'secondary',
            default                   => 'secondary',
        };
    }

    public static function getStatusLabel(string $status): string
    {
        return self::getStatuses()[$status] ?? $status;
    }

    /**
     * Leads to display, most recent first: those still present in Kiflo, the open ones only
     * unless $all
     *
     * @return list<array<string, mixed>>
     */
    public static function getLeads(bool $all = false): array
    {
        global $DB;

        $where = ['is_deleted_kiflo' => 0];
        if (!$all) {
            $where['status'] = self::OPEN_STATUSES;
        }

        $leads = [];
        foreach (
            $DB->request([
                'FROM'    => self::getTable(),
                'WHERE'   => $where,
                'ORDERBY' => ['date_kiflo DESC', 'id DESC'],
            ]) as $row
        ) {
            $row['status_label']     = self::getStatusLabel((string) $row['status']);
            $row['status_color']     = self::getStatusColor((string) $row['status']);
            $row['extra_properties'] = self::getExtraProperties((string) $row['properties']);
            $leads[] = $row;
        }

        return $leads;
    }

    /** Properties of a lead already stored in their own column */
    private const MAPPED_PROPERTIES = ['companyName', 'firstName', 'lastName', 'email', 'phoneNumber', 'origine', 'commentaires'];

    /**
     * Custom properties of a lead not stored in a column of their own: the publisher may add
     * some in Kiflo at any time, the detail of the lead shows them as they come
     *
     * @return array<string, string>
     */
    public static function getExtraProperties(string $json): array
    {
        $properties = json_decode($json, true);
        if (!is_array($properties)) {
            return [];
        }

        $extra = [];
        foreach ($properties as $key => $value) {
            if (in_array($key, self::MAPPED_PROPERTIES, true) || $value === null || $value === '') {
                continue;
            }
            $extra[(string) $key] = is_scalar($value)
                ? (string) $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $extra;
    }

    /**
     * Leads tab of the clients management, from the dashboard widget
     */
    public static function getTabUrl(): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php?forcetab='
            . urlencode(Entity::class . '$' . Entity::TAB_KIFLO_LEADS);
    }

    /**
     * Number of leads by status, of the leads still present in Kiflo
     *
     * @return array<string, int>
     */
    public static function countByStatus(): array
    {
        global $DB;

        $counts = [];
        foreach (
            $DB->request([
                'SELECT'  => ['status', 'COUNT' => 'id AS cpt'],
                'FROM'    => self::getTable(),
                'WHERE'   => ['is_deleted_kiflo' => 0],
                'GROUPBY' => 'status',
            ]) as $row
        ) {
            $counts[(string) $row['status']] = (int) $row['cpt'];
        }

        return $counts;
    }

    /**
     * Leads tab of the clients management (Entity::TAB_KIFLO_LEADS)
     */
    public static function showLeadsTab(bool $all = false): void
    {
        $counts = self::countByStatus();
        $badges = [];
        foreach (self::getStatuses() as $status => $label) {
            if (isset($counts[$status])) {
                $badges[] = ['label' => $label, 'count' => $counts[$status], 'color' => self::getStatusColor($status)];
            }
        }

        $config = Config::getInstance()->fields;
        TemplateRenderer::getInstance()->display('@manageentities/entity/kiflo_leads_tab.html.twig', [
            'leads'      => self::getLeads($all),
            'badges'     => $badges,
            'all'        => $all,
            'last_sync'  => $config['kiflo_last_sync'] ?? null,
            'last_error' => $config['kiflo_last_error'] ?? '',
            'filter_url' => PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
            // Same pair of rights as front/config.form.php, which runs the synchronization
            'sync_url'   => Config::canManageConfig() ? Config::getFormURL() : '',
        ]);
    }

    /**
     * Fetches every lead from Kiflo and refreshes the local copy. The leads no longer returned
     * are flagged, not deleted, so that a transient empty answer cannot wipe the table.
     *
     * The outcome (date or error) is stored in the configuration, for the tab and the setup.
     *
     * @return int number of leads received
     *
     * @throws RuntimeException with a message safe to display
     */
    public static function synchronize(?KifloClient $client = null): int
    {
        global $DB;

        try {
            $leads = ($client ?? KifloClient::fromConfig())->getLeads();
        } catch (RuntimeException $e) {
            Config::setKifloSyncResult($e->getMessage());
            Toolbox::logInFile('manageentities-kiflo', $e->getMessage() . "\n");
            throw $e;
        }

        $table = self::getTable();
        $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $seen  = [];
        foreach ($leads as $lead) {
            $row = self::toRow($lead);
            if ($row === null) {
                continue;
            }
            $DB->updateOrInsert(
                $table,
                $row + ['is_deleted_kiflo' => 0, 'date_sync' => $now, 'date_mod' => $now],
                ['kiflo_id' => $row['kiflo_id']],
            );
            $seen[] = $row['kiflo_id'];
        }

        // The tab and the widget only show the leads still present in Kiflo
        $DB->update(
            $table,
            ['is_deleted_kiflo' => 1, 'date_mod' => $now],
            $seen === []
                ? ['is_deleted_kiflo' => 0]
                : ['is_deleted_kiflo' => 0, 'NOT' => ['kiflo_id' => $seen]],
        );
        // Newly inserted rows: their creation date is the first synchronization seeing them
        $DB->update($table, ['date_creation' => $now], ['date_creation' => null]);

        Config::setKifloSyncResult();

        return count($seen);
    }

    /**
     * Row of the local table built from a lead of the API
     *
     * @param array<string, mixed> $lead
     *
     * @return array<string, mixed>|null null without a Kiflo identifier
     */
    public static function toRow(array $lead): ?array
    {
        $kiflo_id = (int) ($lead['id'] ?? 0);
        if ($kiflo_id <= 0) {
            return null;
        }

        $properties = is_array($lead['properties'] ?? null) ? $lead['properties'] : [];
        $owner      = is_array($lead['owner'] ?? null) ? $lead['owner'] : [];
        $string     = static fn($value, int $length = 255): ?string => is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, $length)
            : null;

        $contact = trim(($string($properties['firstName'] ?? null) ?? '') . ' ' . ($string($properties['lastName'] ?? null) ?? ''));

        return [
            'kiflo_id'           => $kiflo_id,
            'name'               => $string($properties['companyName'] ?? null) ?? ($contact !== '' ? $contact : '#' . $kiflo_id),
            'contact_name'       => $contact !== '' ? $contact : null,
            'email'              => $string($properties['email'] ?? null),
            'phone'              => $string($properties['phoneNumber'] ?? null),
            'origin'             => $string($properties['origine'] ?? null),
            'comment'            => $string($properties['commentaires'] ?? null, 65535),
            'status'             => $string($lead['status'] ?? null, 50) ?? '',
            'source'             => $string($lead['source'] ?? null, 50),
            'partner_name'       => $string($lead['partnerName'] ?? null),
            'owner_name'         => $string(trim(($owner['firstName'] ?? '') . ' ' . ($owner['lastName'] ?? ''))),
            'owner_email'        => $string($owner['email'] ?? null),
            'deal_name'          => $string($lead['dealName'] ?? null),
            'customer_name'      => $string($lead['customerName'] ?? null),
            'kiflo_customers_id' => (int) ($lead['customerId'] ?? 0),
            'date_kiflo'         => self::toLocalDate($lead['creationDate'] ?? null),
            'deadline'           => self::toLocalDate($lead['deadLine'] ?? null),
            'accepted_date'      => self::toLocalDate($lead['acceptedDate'] ?? null),
            'converted_date'     => self::toLocalDate($lead['convertedDate'] ?? null),
            'properties'         => json_encode($properties, JSON_UNESCAPED_UNICODE) ?: null,
        ];
    }

    /**
     * ISO 8601 UTC date of the API (2015-03-25T12:00:00Z) to a date of the database, in the
     * time zone of PHP
     */
    public static function toLocalDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * Give localized information about the automatic action.
     *
     * @param string $name Cron task name
     *
     * @return array
     */
    public static function cronInfo($name)
    {
        switch ($name) {
            case 'KifloSync':
                return ['description' => __('Synchronize the leads of Kiflo', 'manageentities')];
        }
        return [];
    }

    /**
     * Hourly automatic action: refresh the local copy of the Kiflo leads
     *
     * @param CronTask|null $task
     *
     * @return int 0 = nothing done, 1 = done, -1 = error
     */
    public static function cronKifloSync($task = null)
    {
        if (!Config::useKiflo() || Config::getKifloToken() === '') {
            return 0;
        }

        try {
            $count = self::synchronize();
        } catch (RuntimeException $e) {
            $task?->log($e->getMessage());
            return -1;
        }
        $task?->addVolume($count);

        return 1;
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `kiflo_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'id of the lead in Kiflo',
                            `name` varchar(255) DEFAULT NULL COMMENT 'company name of the lead',
                            `contact_name` varchar(255) DEFAULT NULL,
                            `email` varchar(255) DEFAULT NULL,
                            `phone` varchar(255) DEFAULT NULL,
                            `origin` varchar(255) DEFAULT NULL,
                            `comment` text,
                            `status` varchar(50) NOT NULL DEFAULT '' COMMENT 'Pending, Accepted, Refused, Converted...',
                            `source` varchar(50) DEFAULT NULL,
                            `partner_name` varchar(255) DEFAULT NULL,
                            `owner_name` varchar(255) DEFAULT NULL COMMENT 'owner of the lead at the publisher',
                            `owner_email` varchar(255) DEFAULT NULL,
                            `deal_name` varchar(255) DEFAULT NULL,
                            `customer_name` varchar(255) DEFAULT NULL,
                            `kiflo_customers_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'id of the customer in Kiflo, once converted',
                            `date_kiflo` timestamp NULL DEFAULT NULL COMMENT 'creation date in Kiflo',
                            `deadline` timestamp NULL DEFAULT NULL,
                            `accepted_date` timestamp NULL DEFAULT NULL,
                            `converted_date` timestamp NULL DEFAULT NULL,
                            `properties` text COMMENT 'JSON of the custom properties of the lead',
                            `is_deleted_kiflo` tinyint NOT NULL DEFAULT '0' COMMENT 'no longer returned by Kiflo',
                            `date_sync` timestamp NULL DEFAULT NULL,
                            `date_creation` timestamp NULL DEFAULT NULL,
                            `date_mod` timestamp NULL DEFAULT NULL,
                            PRIMARY KEY  (`id`),
                            UNIQUE KEY `kiflo_id` (`kiflo_id`),
                            KEY `status` (`status`),
                            KEY `kiflo_customers_id` (`kiflo_customers_id`),
                            KEY `date_kiflo` (`date_kiflo`),
                            KEY `is_deleted_kiflo` (`is_deleted_kiflo`),
                            KEY `date_creation` (`date_creation`),
                            KEY `date_mod` (`date_mod`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }

        // Hourly automatic action (register() is idempotent), idle while Kiflo is disabled
        CronTask::register(self::class, 'KifloSync', HOUR_TIMESTAMP);
    }

    public static function uninstall()
    {
        global $DB;

        // The automatic action is removed with the other ones by plugin_manageentities_uninstall()
        $DB->dropTable(self::getTable(), true);
    }
}
