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
use GlpiPlugin\Manageentities\TechLeadRule;

// Provider-side page, like the "Tech lead by clients" tab it is reached from
if (Session::getCurrentInterface() != 'central' || !TechLeadRule::canView()) {
    throw new AccessDeniedHttpException();
}

if (isset($_POST['create_rule']) || isset($_POST['create_all_rules'])) {
    if (!TechLeadRule::canCreate()) {
        throw new AccessDeniedHttpException();
    }
    $created = isset($_POST['create_all_rules'])
        ? TechLeadRule::createMissingRules()
        : (int) TechLeadRule::createRule((int) ($_POST['entities_id'] ?? 0));
    Session::addMessageAfterRedirect(
        $created > 0
            ? sprintf(_n('%d rule created', '%d rules created', $created, 'manageentities'), $created)
            : __('No rule created', 'manageentities'),
        false,
        $created > 0 ? INFO : WARNING,
    );
    Html::back();
}

Html::header(__('Assignment rules', 'manageentities'), '', 'management', Entity::class);
TechLeadRule::showList($_SESSION['glpiactiveentities']);
Html::footer();
