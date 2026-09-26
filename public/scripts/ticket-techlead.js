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

// Main tech lead badge of a ticket (templates/techlead/ticket_badge.html.twig): rendered hidden
// at the end of the ticket fields, the actors block having no plugin hook, then moved under the
// "Assigned to" field.
//
// The ticket form can be loaded after this module ran (tabs, timeline reload): try now, then
// watch the DOM.

function placeBadges() {
    document.querySelectorAll('[data-me-techlead-badge].d-none').forEach((badge) => {
        const form = badge.closest('form') ?? document;
        const field = form.querySelector('select[data-actor-type="assign"]')?.closest('.form-field');
        if (field === null || field === undefined) {
            return;
        }
        field.append(badge);
        badge.classList.remove('d-none');
    });
}

placeBadges();
new MutationObserver(() => {
    if (document.querySelector('[data-me-techlead-badge].d-none') !== null) {
        placeBadges();
    }
}).observe(document.body, {childList: true, subtree: true});
