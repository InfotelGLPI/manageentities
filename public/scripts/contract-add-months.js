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

// "+ 12 months" button of the contract form, configured by the data-me-add-months marker that
// Contract::postItemForm() emits (templates/contract_add_months.html.twig).
//
// The duration is a select2 AJAX dropdown (Dropdown::showNumber()): its options are not in the
// DOM, so the one wanted is built from the label that its own endpoint returns, then selected with
// a native "change" event, which select2 listens to.
//
// The form is loaded in a tab, after this module ran: it is looked up at once and then on each
// change of the document.

function encodeParams(search, value, prefix) {
    if (value === null || value === undefined) {
        return;
    }
    if (typeof value === 'object') {
        for (const [key, item] of Object.entries(value)) {
            encodeParams(search, item, prefix === '' ? key : `${prefix}[${key}]`);
        }
        return;
    }
    search.append(prefix, String(value));
}

async function fetchLabel(select, target) {
    const config = window.select2_configs?.[select.id];
    if (!config?.url) {
        return String(target);
    }

    const body = new URLSearchParams();
    encodeParams(body, {...config.params, searchText: String(target), page: 1, page_limit: 200}, '');
    const response = await fetch(config.url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body,
    });
    if (!response.ok) {
        return String(target);
    }
    const data  = await response.json();
    const found = (data?.results ?? []).find((result) => parseInt(result.id, 10) === target);
    return found ? found.text : String(target);
}

function attachButton(marker) {
    const form   = marker.closest('form') ?? document;
    const select = form.querySelector('select[name="duration"]');
    if (select === null || form.querySelector('[data-me-add-months-button]') !== null) {
        return;
    }

    let config;
    try {
        config = JSON.parse(marker.dataset.meAddMonths);
    } catch {
        return;
    }

    const button = document.createElement('button');
    button.type      = 'button';
    button.className = 'btn btn-sm btn-outline-secondary ms-2';
    button.title     = config.title;
    button.dataset.meAddMonthsButton = '';
    const icon = document.createElement('i');
    icon.className = 'ti ti-calendar-plus me-1';
    button.append(icon, config.label);

    button.addEventListener('click', async () => {
        let current = parseInt(select.value, 10);
        if (Number.isNaN(current) || current < 1) {
            current = 0;
        }
        const target = Math.min(current + 12, config.max);

        const option = new Option(await fetchLabel(select, target), String(target), true, true);
        select.replaceChildren(option);
        select.dispatchEvent(new Event('change', {bubbles: true}));
    });

    // select2 puts its widget right after the select
    const anchor = select.parentNode.querySelector('.select2-container') ?? select;
    anchor.after(button);
}

function scan() {
    document.querySelectorAll('[data-me-add-months]').forEach(attachButton);
}

scan();
new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
