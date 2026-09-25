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

// "Duplicate" button under a ticket task (templates/tickettask_duplicate_row.html.twig),
// configured by data-me-clone: copies the task to the date picked next to the button through
// ajax/tickettask.php, then reloads the page to show the new task.
//
// Task forms are loaded in the timeline after this module ran: the click is delegated.

function parseData(element) {
    try {
        return JSON.parse(element.dataset.meClone);
    } catch {
        return null;
    }
}

async function cloneTicketTask(button) {
    const options = parseData(button);
    if (options === null) {
        return;
    }
    const date_input = button.closest('td')?.querySelector('input[name="new_date"]');

    button.disabled = true;
    try {
        const response = await fetch(options.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                action: 'cloneTicketTask',
                tickets_id: options.tickets_id,
                tickettasks_id: options.tickettasks_id,
                new_date_value: date_input?.value ?? '',
            }),
        });
        if (!response.ok) {
            throw new Error(`Request to ${options.url} failed (HTTP ${response.status})`);
        }
        const json = await response.json();
        if (json.tickettasks_id !== undefined) {
            window.location.reload();
            return;
        }
    } catch (error) {
        console.error(error);
    }
    button.disabled = false;
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-me-clone]');
    if (button !== null) {
        event.preventDefault();
        cloneTicketTask(button);
    }
});
