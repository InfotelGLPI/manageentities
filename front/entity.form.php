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

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Manageentities\EntityLogo;

$logo = new EntityLogo();

global $CFG_GLPI;

if (isset($_POST["add"])) {
    $logo->check(-1, CREATE);

    if (isset($_POST["_filename"]) && count($_POST["_filename"]) > 0) {
        $logo->addLogo($_POST);
    } else {
        Session::addMessageAfterRedirect(__('No picture uploaded', 'manageentities'), false, ERROR);
    }

    Html::back();

} elseif (isset($_POST["update"])
           && isset($_POST["entities_id"])) {

    // The posted value used to be concatenated as is: extra parameters, and a second forcetab,
    // could be smuggled into the query string of the target page. The stray "&amps" was a mangled
    // "&amp;" left over from an HTML context.
    $redirect_entities_id = (int) $_POST["entities_id"];
    if ($redirect_entities_id <= 0) {
        throw new BadRequestHttpException();
    }

    Html::redirect($CFG_GLPI["root_doc"] . "/front/entity.form.php?id=" . $redirect_entities_id . "&forcetab=EntityData$1");

}
