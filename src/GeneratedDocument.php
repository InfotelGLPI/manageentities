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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Session;

/**
 * Last generated version of the DAT and the DEX of a client: badges of the summary card
 * (CustomerSummary) and download through front/generateddoc.send.php, for the users who have the
 * DAT / DEX right but not the core document right (customers of the simplified interface).
 *
 * Only the last generated document of the DAT / DEX of the entity is ever served: never a
 * document id taken from the request.
 */
class GeneratedDocument
{
    public const TYPE_DAT = 'dat';
    public const TYPE_DEX = 'dex';

    /** Known types, in the order of the badges */
    public const TYPES = [self::TYPE_DAT, self::TYPE_DEX];

    /**
     * Right of a known type, null for any other value
     */
    private static function getRightName(string $type): ?string
    {
        return match ($type) {
            self::TYPE_DAT => Dat::$rightname,
            self::TYPE_DEX => Dex::$rightname,
            default        => null,
        };
    }

    /**
     * Short label of a type, also the prefix of its document names (DatGenerator, DexGenerator)
     */
    private static function getShortLabel(string $type): string
    {
        return $type === self::TYPE_DAT ? 'DAT' : 'DEX';
    }

    /**
     * URL of the plugin route serving the last generated document of a type of an entity
     */
    public static function getSendUrl(string $type, int $entities_id): string
    {
        return PLUGIN_MANAGEENTITIES_WEBDIR . '/front/generateddoc.send.php?' . http_build_query([
            'type'        => $type,
            'entities_id' => $entities_id,
        ]);
    }

    /**
     * Last generated document of the DAT / DEX of an entity, no right checked: the DAT / DEX
     * exists with a generated version, its last document exists, is not deleted, is linked to
     * the entity and is named as the generator names it. Null otherwise.
     *
     * @return array{document: \Document, version: int}|null
     */
    private static function getLastGenerated(string $type, int $entities_id): ?array
    {
        if (self::getRightName($type) === null || $entities_id <= 0) {
            return null;
        }

        $source = $type === self::TYPE_DAT ? Dat::getForEntity($entities_id) : Dex::getForEntity($entities_id);
        if ($source === null || (int) $source->fields['version'] <= 0) {
            return null;
        }

        $documents_id = (int) $source->fields['documents_id'];
        $document     = new \Document();
        if (
            $documents_id <= 0
            || !$document->getFromDB($documents_id)
            || (int) $document->fields['is_deleted'] !== 0
            || !str_starts_with((string) $document->fields['name'], self::getShortLabel($type) . ' - ')
            || countElementsInTable(\Document_Item::getTable(), [
                'documents_id' => $documents_id,
                'itemtype'     => \Entity::class,
                'items_id'     => $entities_id,
            ]) === 0
        ) {
            return null;
        }

        return ['document' => $document, 'version' => (int) $source->fields['version']];
    }

    /**
     * Whether the current user may get the documents of a type of the entity: the entity among
     * the active ones and READ on the DAT / DEX right
     */
    private static function canAccess(string $type, int $entities_id): bool
    {
        $right = self::getRightName($type);

        return $right !== null
            && $entities_id > 0
            && Session::haveAccessToEntity($entities_id)
            && Session::haveRight($right, READ);
    }

    /**
     * Last generated document of the DAT / DEX of an entity, that the current user may download
     *
     * @throws NotFoundHttpException     unknown type, or no generated document
     * @throws AccessDeniedHttpException entity not accessible, or no READ on the DAT / DEX right
     */
    public static function getDownloadableDocument(string $type, int $entities_id): \Document
    {
        if (self::getRightName($type) === null) {
            throw new NotFoundHttpException();
        }
        if (!self::canAccess($type, $entities_id)) {
            throw new AccessDeniedHttpException();
        }

        $last = self::getLastGenerated($type, $entities_id);
        if ($last === null) {
            throw new NotFoundHttpException();
        }

        return $last['document'];
    }

    /**
     * Badges of the summary card: one per type whose last generated version the user may get.
     * A draft of the next version keeps the badge of the last generated one.
     *
     * @return list<array{type: string, label: string, title: string, icon: string, url: string, date: string, name: string}>
     */
    public static function getBadges(int $entities_id): array
    {
        global $CFG_GLPI;

        $badges = [];
        foreach (self::TYPES as $type) {
            if (!self::canAccess($type, $entities_id)) {
                continue;
            }
            $last = self::getLastGenerated($type, $entities_id);
            if ($last === null) {
                continue;
            }

            $document = $last['document'];
            // Central users who can read the document through the core keep its usual link; the
            // others (customers of the simplified interface) go through the plugin route
            $url = Session::getCurrentInterface() === 'central' && $document->can($document->getID(), READ)
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query([
                    'docid'    => $document->getID(),
                    'itemtype' => \Entity::class,
                    'items_id' => $entities_id,
                ])
                : self::getSendUrl($type, $entities_id);

            $badges[] = [
                'type'  => $type,
                'label' => self::getShortLabel($type) . ' v' . DatGenerator::versionLabel($last['version']),
                'title' => sprintf(
                    __('Download the last generated version: %s', 'manageentities'),
                    (string) $document->fields['name'],
                ),
                'icon'  => $type === self::TYPE_DAT ? Dat::getIcon() : Dex::getIcon(),
                'url'   => $url,
                // Generation date of the last version, shown under the label in the summary card
                'date'  => (string) $document->fields['date_creation'],
                'name'  => $type === self::TYPE_DAT ? Dat::getTypeName(1) : Dex::getTypeName(1),
            ];
        }

        return $badges;
    }
}
