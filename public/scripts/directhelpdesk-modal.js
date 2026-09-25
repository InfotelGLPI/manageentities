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

// Unbilled intervention modal (templates/directhelpdesk_modal.html.twig): the alert about the
// contracts of the selected entity follows the entity dropdown.
//
// The dropdown is a select2 widget, which only fires jQuery events: its on_change relays a native
// "manageentities:change" event (CriDetail::CHANGE_EVENT_JS). The modal is loaded on demand, after
// this module ran: it is looked up when the event occurs.

document.addEventListener('manageentities:change', async (event) => {
    const field = event.target;
    if (!(field instanceof HTMLSelectElement) || field.name !== 'entities_id') {
        return;
    }
    const modal = field.closest('[data-me-entity-alert]');
    const alert = modal?.querySelector('#entity_alert');
    if (!alert) {
        return;
    }

    const response = await fetch(modal.dataset.meEntityAlert, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({entities_id: field.value}),
    });
    if (!response.ok) {
        throw new Error(`Entity alert request failed (HTTP ${response.status})`);
    }
    // Server-rendered alert (Contract::displayAlertforEntity())
    alert.replaceChildren(document.createRange().createContextualFragment(await response.text()));
});
