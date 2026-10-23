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

// DAT and DEX wizards (templates/dat/*, templates/dex/*). The steps are plain forms posted to
// front/dat.form.php or front/dex.form.php: this module adds, removes and moves the server rows of
// step 2 (shared template dat/step_system.html.twig), keeping their posted indexes contiguous, and
// confirms leaving the wizard. No-op outside #dat-wizard and #dex-wizard.

// The wizards this module drives
const WIZARDS = '#dat-wizard, #dex-wizard';

// Index of a row in the field names: a number, or the placeholder of the <template>
const SERVER_INDEX = /servers\[(?:\d+|__IDX__)\]/;

function renumberServers(container) {
    container.querySelectorAll('[data-dat-server]').forEach((row, idx) => {
        row.querySelectorAll('[name]').forEach((el) => {
            el.name = el.name.replace(SERVER_INDEX, `servers[${idx}]`);
        });
        const number = row.querySelector('[data-dat-server-number]');
        if (number !== null) {
            number.textContent = String(idx + 1);
        }
    });
}

function addServer(wizard) {
    const container = wizard.querySelector('[data-dat-servers]');
    const template = wizard.querySelector('template[data-dat-server-template]');
    if (container === null || template === null) {
        return;
    }
    const fragment = template.content.cloneNode(true);
    const row = fragment.querySelector('[data-dat-server]');
    container.append(fragment);
    renumberServers(container);
    row.querySelector('input[type="text"]')?.focus();
}

function moveServer(button, direction) {
    const row = button.closest('[data-dat-server]');
    const container = row?.parentElement;
    if (row === null || container === null) {
        return;
    }
    if (direction < 0 && row.previousElementSibling !== null) {
        container.insertBefore(row, row.previousElementSibling);
    } else if (direction > 0 && row.nextElementSibling !== null) {
        container.insertBefore(row.nextElementSibling, row);
    }
    renumberServers(container);
    button.focus();
}

function removeServer(button) {
    const row = button.closest('[data-dat-server]');
    const container = row?.parentElement;
    if (row === null || container === null) {
        return;
    }
    row.remove();
    renumberServers(container);
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-dat-action]');
    const wizard = button?.closest(WIZARDS);
    if (button === null || wizard === null) {
        return;
    }
    switch (button.dataset.datAction) {
        case 'server-add':
            addServer(wizard);
            break;
        case 'server-up':
            moveServer(button, -1);
            break;
        case 'server-down':
            moveServer(button, 1);
            break;
        case 'server-remove':
            removeServer(button);
            break;
    }
});

// Leaving the wizard without saving the current page, applying a model, or deleting a draft from
// the DAT or DEX card of the customer sheet
document.addEventListener('click', (event) => {
    const link = event.target.closest(
        '#dat-wizard [data-dat-confirm], #dex-wizard [data-dat-confirm], [data-dat-card] [data-dat-confirm], [data-dex-card] [data-dat-confirm]',
    );
    if (link !== null && !window.confirm(link.dataset.datConfirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

// A new DAT or DEX without server starts with one empty server row
function init() {
    const wizard = document.querySelector(WIZARDS);
    const container = wizard?.querySelector('[data-dat-servers]');
    if (container && container.querySelector('[data-dat-server]') === null) {
        addServer(wizard);
    }
}

// Module scripts are deferred: the document may already be parsed
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
