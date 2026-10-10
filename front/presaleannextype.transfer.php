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
use GlpiPlugin\Manageentities\Entity;
use GlpiPlugin\Manageentities\PresaleAnnexType;
use GlpiPlugin\Manageentities\PresaleAnnexTypeTransfer;

// Export (GET export[]) and import (POST file) of the annex models as JSON
if (!Plugin::isPluginActive('manageentities') || !PresaleAnnexType::canView()) {
    throw new AccessDeniedHttpException();
}

if (isset($_GET['export'])) {
    return PresaleAnnexTypeTransfer::getExportResponse(array_map('intval', (array) $_GET['export']));
}

if (isset($_POST['import'])) {
    if (!PresaleAnnexType::canCreate()) {
        throw new AccessDeniedHttpException();
    }
    PresaleAnnexTypeTransfer::import($_FILES['file'] ?? null);
    Html::redirect(PresaleAnnexType::getSearchURL());
}

if (!PresaleAnnexType::canCreate()) {
    throw new AccessDeniedHttpException();
}
Html::header(PresaleAnnexType::getTypeName(2), '', 'management', Entity::class, 'presaleproposal');
PresaleAnnexTypeTransfer::showImportForm();
Html::footer();
