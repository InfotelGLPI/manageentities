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

use CommonDBChild;
use Migration;

/**
 * Snapshot of a generated document (Dat, Dex), taken at each successful generation in its
 * generated_snapshot field: author, date, system, sections and servers, as printed in the Word
 * document. Discarding a draft of the next version restores the document from it, exactly as it
 * was generated; the version and the last document are left as they are.
 *
 * A document generated before the snapshot existed has none (NULL): it cannot be restored until
 * its next generation.
 */
trait GeneratedSnapshotTrait
{
    /**
     * New server of the document (DatServer, DexServer)
     */
    abstract protected static function newServer(): CommonDBChild;

    /**
     * Field of the date printed in the document
     */
    abstract protected static function getDateField(): string;

    /**
     * Fields of a server kept by the snapshot
     */
    private const SNAPSHOT_SERVER_FIELDS = ['rank', 'name', 'internal_name', 'ip', 'role'];

    /**
     * JSON snapshot of the document as it is now: what the generation prints
     */
    public function buildGeneratedSnapshot(): string
    {
        $server  = static::newServer();
        $servers = [];
        foreach ($server->find([$server::$items_id => $this->getID()], ['rank', 'id']) as $row) {
            $server = [];
            foreach (self::SNAPSHOT_SERVER_FIELDS as $field) {
                $server[$field] = $field === 'rank' ? (int) $row[$field] : $row[$field];
            }
            $servers[] = $server;
        }

        return (string) json_encode([
            'author_users_id'       => (int) $this->fields['author_users_id'],
            static::getDateField()  => $this->fields[static::getDateField()],
            'os'                    => (string) $this->fields['os'],
            'sections'              => $this->getSections(),
            'servers'               => $servers,
        ]);
    }

    /**
     * Snapshot of the last generation, null when none (generated before the snapshot existed,
     * or never generated) or unreadable
     *
     * @return array{author_users_id: int, date: ?string, os: string, sections: array<string, string>, servers: list<array<string, mixed>>}|null
     */
    public function getGeneratedSnapshot(): ?array
    {
        $snapshot = json_decode((string) ($this->fields['generated_snapshot'] ?? ''), true);
        if (
            !is_array($snapshot)
            || !isset($snapshot['author_users_id'], $snapshot['os'])
            || !array_key_exists(static::getDateField(), $snapshot)
            || !is_array($snapshot['sections'] ?? null)
            || !is_array($snapshot['servers'] ?? null)
        ) {
            return null;
        }

        $servers = [];
        foreach ($snapshot['servers'] as $row) {
            if (!is_array($row)) {
                return null;
            }
            $server = [];
            foreach (self::SNAPSHOT_SERVER_FIELDS as $field) {
                $server[$field] = $field === 'rank' ? (int) ($row[$field] ?? 0) : $row[$field] ?? null;
            }
            $servers[] = $server;
        }

        return [
            'author_users_id' => (int) $snapshot['author_users_id'],
            'date'            => $snapshot[static::getDateField()] !== null ? (string) $snapshot[static::getDateField()] : null,
            'os'              => (string) $snapshot['os'],
            'sections'        => array_map('strval', $snapshot['sections']),
            'servers'         => $servers,
        ];
    }

    /**
     * Restores the document as it was at its last generation: author, date, system, sections,
     * servers, then the status "generated" and the last step. The version and the last document
     * are kept.
     *
     * @param int $step step of the wizard reached by a generation
     */
    public function restoreGeneratedSnapshot(int $step): bool
    {
        $snapshot = $this->getGeneratedSnapshot();
        if ($snapshot === null) {
            return false;
        }

        // Servers first: the rows of the draft replaced by the generated ones
        $server = static::newServer();
        foreach ($server->find([$server::$items_id => $this->getID()]) as $row) {
            if (!$server->delete(['id' => $row['id']], true)) {
                return false;
            }
        }
        foreach ($snapshot['servers'] as $row) {
            if ($server->add([$server::$items_id => $this->getID()] + $row) === false) {
                return false;
            }
        }

        return $this->update([
            'id'                   => $this->getID(),
            'author_users_id'      => $snapshot['author_users_id'],
            static::getDateField() => $snapshot['date'],
            'os'                   => $snapshot['os'],
            // Every section given: the ones of the draft are all replaced
            'sections'             => $snapshot['sections'],
            'step'                 => $step,
            'status'               => self::STATUS_GENERATED,
        ]);
    }

    /**
     * Upgrade: snapshot of the last generation, added after the first release. The documents
     * already generated get none until their next generation.
     */
    private static function installGeneratedSnapshot(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();
        if (!$DB->fieldExists($table, 'generated_snapshot')) {
            $migration->addField($table, 'generated_snapshot', 'mediumtext', [
                'after'   => 'sections',
                'comment' => 'JSON snapshot of the last generation: author, date, os, sections, servers',
            ]);
            $migration->migrationOneTable($table);
        }
    }
}
