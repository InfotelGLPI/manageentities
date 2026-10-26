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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Manageentities\GeneratedDocument;

// Last generated DAT / DEX of a client, for the users with READ on the DAT / DEX right and access
// to the entity, even without the core document right (customers of the simplified interface).
// Only the last generated document of the entity is served, never a document id from the request.
if (!Plugin::isPluginActive('manageentities') || Session::getLoginUserID() === false) {
    throw new AccessDeniedHttpException();
}

$doc = GeneratedDocument::getDownloadableDocument(
    is_string($_GET['type'] ?? null) ? $_GET['type'] : '',
    (int) ($_GET['entities_id'] ?? 0),
);
if (!file_exists(GLPI_DOC_DIR . "/" . $doc->fields['filepath'])) {
    throw new NotFoundHttpException();
}

return $doc->getAsResponse();
