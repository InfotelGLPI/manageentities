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

// Prices of a contract period (templates/criprice_list.html.twig) and their edition form
// (templates/criprice_form.html.twig), configured by data-me-criprice / data-me-criprice-form.
//
// - a click on a price row, or on "Add a new price", loads the form from ajax/viewsubitem.php;
// - the intervention type dropdown reloads the "Select an existing price" dropdown from
//   ajax/criprice.php, whose choice fills the price field.
//
// Both responses are GLPI forms built by the server (select2 widgets and their init scripts):
// they are inserted through a contextual fragment, which runs those scripts. The dropdowns
// relay a native "manageentities:change" event (CriDetail::CHANGE_EVENT_JS).
//
// The lists are loaded in tabs, after this module ran: every lookup happens when an event occurs.

function parseData(element, key) {
    try {
        return JSON.parse(element.dataset[key]);
    } catch {
        return null;
    }
}

async function postHtml(url, params) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams(params),
    });
    if (!response.ok) {
        throw new Error(`Request to ${url} failed (HTTP ${response.status})`);
    }
    return response.text();
}

function replaceWithServerHtml(container, html) {
    container.replaceChildren(document.createRange().createContextualFragment(html));
}

function report(error) {
    console.error(error);
}

async function openEditor(card, items_id) {
    const config = parseData(card, 'meCriprice');
    const editor = card.querySelector('[data-me-criprice-editor]');
    if (config === null || editor === null) {
        return;
    }
    const html = await postHtml(config.url, {
        type: config.type,
        parenttype: config.parenttype,
        [config.parent_field]: config.parents_id,
        id: items_id,
    });
    replaceWithServerHtml(editor, html);
    editor.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}

document.addEventListener('click', (event) => {
    const card = event.target.closest('[data-me-criprice]');
    if (card === null) {
        return;
    }
    if (event.target.closest('[data-me-criprice-add]') !== null) {
        openEditor(card, -1).catch(report);
        return;
    }
    // Rows open their edition form, except on the massive action checkbox
    const row = event.target.closest('tr[data-id]');
    if (row === null || event.target.closest('input, a, label, button') !== null) {
        return;
    }
    openEditor(card, row.dataset.id).catch(report);
});

document.addEventListener('manageentities:change', (event) => {
    const form = event.target.closest('[data-me-criprice-form]');
    if (form === null) {
        return;
    }
    const field = event.target;

    if (field.name === 'plugin_manageentities_critypes_id') {
        const config    = parseData(form, 'meCripriceForm');
        const container = form.querySelector('[data-me-criprice-prices]');
        if (config === null || container === null) {
            return;
        }
        postHtml(config.ajax_url, {
            action: 'loadPrice',
            critypes_id: field.value,
            entities_id: config.entities_id,
        }).then((html) => replaceWithServerHtml(container, html)).catch(report);
    } else if (field.name === 'select_critype') {
        const price = form.querySelector('input[name="price"]');
        const value = parseFloat(field.value);
        if (price !== null && !Number.isNaN(value)) {
            price.value = value.toFixed(2);
        }
    }
});
