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
use GlpiPlugin\Manageentities\EntityLogo;

$docid = (int) ($_GET['docid'] ?? 0);

// Only a logo row whose document is linked to its entity (Document_Item), as the logo upload
// does: a row designating any other document serves nothing.
// Entity logos are meant to be visible to any user with access to the entity,
// regardless of the generic Document/Entity rights (self-service profiles
// usually have neither).
$found   = false;
$allowed = false;
foreach ((new EntityLogo())->find(['logos_id' => $docid]) as $row) {
    if (!EntityLogo::isLinkedToEntity($docid, (int) $row['entities_id'])) {
        continue;
    }
    $found = true;
    if (Session::haveAccessToEntity((int) $row['entities_id'])) {
        $allowed = true;
        break;
    }
}
if (!$found) {
    throw new NotFoundHttpException();
}
if (!$allowed) {
    throw new AccessDeniedHttpException();
}

$doc = new Document();
if (!$doc->getFromDB($docid) || !file_exists(GLPI_DOC_DIR . "/" . $doc->fields['filepath'])) {
    throw new NotFoundHttpException();
}

return $doc->getAsResponse();
