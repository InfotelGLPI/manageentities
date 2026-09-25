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

// "Configuration daily or hourly" setting (templates/config_options_form.html.twig): each mode
// has its dependent field in a block marked data-me-hourorday="<mode>". Selecting a mode shows
// its block and disables the fields of the other one, so that only the visible block is posted.
// The dropdown is a select2 widget: its on_change relays a native "manageentities:change" event
// (CriDetail::CHANGE_EVENT_JS). The listener is delegated, so it is a no-op on any other page.

document.addEventListener('manageentities:change', (event) => {
    const select = event.target;
    if (select.name !== 'hourorday' || select.form === null) {
        return;
    }
    for (const block of select.form.querySelectorAll('[data-me-hourorday]')) {
        const active = block.dataset.meHourorday === select.value;
        block.classList.toggle('d-none', !active);
        for (const field of block.querySelectorAll('input, select')) {
            field.disabled = !active;
        }
    }
});
