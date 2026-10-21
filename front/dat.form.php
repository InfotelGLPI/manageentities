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
use GlpiPlugin\Manageentities\Dat;
use GlpiPlugin\Manageentities\DatWizard;
use GlpiPlugin\Manageentities\Entity;

if (!Plugin::isPluginActive('manageentities') || !DatWizard::canUse()) {
    throw new AccessDeniedHttpException();
}

// Each step posts here (CSRF checked by the core listener): saved, then redirected
if (isset($_POST['save_step']) || isset($_POST['previous']) || isset($_POST['generate']) || isset($_POST['apply_model'])) {
    DatWizard::handlePost($_POST);
}

Html::header(Dat::getTypeName(1), '', 'management', Entity::class);
DatWizard::renderStep((int) ($_GET['entities_id'] ?? 0), (int) ($_GET['step'] ?? DatWizard::STEP_GENERAL));
Html::footer();
