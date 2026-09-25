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

// Display filters of the direct helpdesk dashboard (templates/directhelpdesk_dashboard_filter.html.twig):
// each checkbox marked with data-me-dh-filter reloads the page with every filter as a GET parameter.
// The listener is delegated, so it is a no-op on any other page.

document.addEventListener('change', (event) => {
    const checkbox = event.target.closest?.('[data-me-dh-filter]');
    if (checkbox === null || checkbox === undefined) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    for (const filter of document.querySelectorAll('[data-me-dh-filter]')) {
        params.set(filter.dataset.meDhFilter, filter.checked ? '1' : '0');
    }
    window.location.href = `?${params.toString()}`;
});
