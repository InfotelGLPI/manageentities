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

// Delegated listeners replacing the inline event handlers that the Content Security Policy of
// GLPI blocks. Bound once, on the document: they also cover the content loaded later over AJAX
// (tabs, modals). No-op on the pages without the attributes below.
//
// - data-manageentities-change-event: dropdowns built on Dropdown::showFromArray()
//   (CriDetail::CHANGE_EVENT_DATA). Their change is relayed as the native
//   "manageentities:change" event, as CriDetail::CHANGE_EVENT_JS does for the AJAX dropdowns.
// - data-manageentities-submit-on-change: the form of the dropdown is submitted on change.
// - data-manageentities-toggle-display="<id>": checkbox showing the element <id> while checked.
//
// The dropdowns are select2 widgets, which only fire jQuery events, never seen by a native
// listener: hence jQuery for the first two. `this` is the <select>, as in the former on_change.

$(document).on('change', '[data-manageentities-change-event]', function () {
    this.dispatchEvent(new Event('manageentities:change', {bubbles: true}));
});

$(document).on('change', '[data-manageentities-submit-on-change]', function () {
    this.form.submit();
});

document.addEventListener('change', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const checkbox = event.target.closest('[data-manageentities-toggle-display]');
    if (checkbox === null) {
        return;
    }

    const target = document.getElementById(checkbox.dataset.manageentitiesToggleDisplay);
    if (target !== null) {
        target.style.display = checkbox.checked ? '' : 'none';
    }
});
