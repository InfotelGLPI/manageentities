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

// "Associate to a contract" card of the CRI detail of a ticket (templates/cridetail_for_ticket.html.twig),
// configured by its data-me-cridetail attribute.
//
// - the contract section follows the "Contract type" dropdown;
// - ajax/dropdownContract.php rebuilds #show_contractdays when the contract changes, with the
//   details of the contract as JSON in data-me-contract-info (templates/contract_info.html.twig);
// - the remaining days of the selected period come from ajax/getRemainingDays.php.
//
// The dropdowns are select2 widgets, which only fire jQuery events: their on_change relays a
// native "manageentities:change" event (CriDetail::CHANGE_EVENT_JS). Every value coming from the
// server is displayed through textContent, never as HTML.
//
// The card is loaded in a ticket tab, after this module ran: it is looked up when an event
// occurs, and the #show_contractdays refreshes are caught by an observer on the document.

function getConfig() {
    const card = document.querySelector('[data-me-cridetail]');
    if (card === null) {
        return null;
    }
    try {
        return JSON.parse(card.dataset.meCridetail);
    } catch {
        return null;
    }
}

function formatDateDMY(raw) {
    if (!raw) {
        return '';
    }
    const parts = String(raw).substring(0, 10).split('-');
    return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : raw;
}

function toggleBlock(block_id, text_id, text) {
    const block   = document.getElementById(block_id);
    const content = document.getElementById(text_id);
    if (block === null || content === null) {
        return null;
    }
    content.textContent = text;
    block.style.display = text ? '' : 'none';
    return content;
}

function icon(classes) {
    const i = document.createElement('i');
    i.className = `ti ${classes}`;
    return i;
}

function updateContractdayFields(data) {
    toggleBlock('contractday_begin_date_block', 'contractday_begin_date_text', formatDateDMY(data.begin_date || ''));

    const raw_end  = data.end_date || '';
    const end_text = toggleBlock('contractday_end_date_block', 'contractday_end_date_text', formatDateDMY(raw_end));
    if (end_text !== null) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const is_past = raw_end !== '' && new Date(raw_end) < today;
        end_text.classList.toggle('text-danger', is_past);
        end_text.classList.toggle('fw-bold', is_past);
    }

    toggleBlock('contractday_comment_block', 'contractday_comment_text', data.comment || '');
}

function renderRemaining(alert, remaining, labels) {
    const body  = document.createElement('div');
    const value = document.createElement('strong');

    if (remaining <= 0) {
        alert.className = 'alert alert-danger d-flex align-items-center gap-2 mb-0';
        const title = document.createElement('strong');
        title.textContent = labels.no_days_left;
        const detail = document.createElement('span');
        detail.className = 'text-muted';
        value.textContent = '0';
        detail.append(`${labels.remaining_days}: `, value);
        body.append(title, document.createElement('br'), detail);
        alert.replaceChildren(icon('ti-alert-triangle fs-4'), body);
        return;
    }

    const label = document.createElement('span');
    label.textContent = `${labels.remaining_days}:`;
    value.id = 'remaining_days_value';
    value.textContent = remaining.toFixed(1);
    body.append(label, ' ', value);

    if (remaining <= 1) {
        alert.className = 'alert alert-warning d-flex align-items-center gap-2 mb-0';
        const hint = document.createElement('span');
        hint.className = 'text-muted fst-italic';
        hint.textContent = labels.anticipate;
        body.append(document.createElement('br'), hint);
        alert.replaceChildren(icon('ti-alert-circle fs-4'), body);
    } else {
        alert.className = 'alert alert-success d-flex align-items-center gap-2 mb-0';
        alert.replaceChildren(icon('ti-calendar-stats fs-4'), body);
    }
}

let last_contractdays_id = null;

async function updateRemainingDays(config, contractdays_id) {
    // select2 mutates the period container several times per refresh: query once per value
    if (contractdays_id === last_contractdays_id) {
        return;
    }
    last_contractdays_id = contractdays_id;

    const block = document.getElementById('remaining_days_block');
    const alert = document.getElementById('remaining_days_alert');
    if (block === null || alert === null) {
        return;
    }

    if (!contractdays_id || contractdays_id === '0') {
        block.style.display = 'none';
        updateContractdayFields({});
        return;
    }

    const response = await fetch(config.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({contractdays_id: contractdays_id}),
    });
    if (!response.ok) {
        throw new Error(`Remaining days request failed (HTTP ${response.status})`);
    }
    const data = await response.json();

    updateContractdayFields(data);
    if (data.remaining === null) {
        block.style.display = 'none';
        return;
    }
    block.style.display = '';
    renderRemaining(alert, parseFloat(data.remaining), config.labels);
}

function updateContractStatus(config, container) {
    const node = container.querySelector('[data-me-contract-info]');
    let info = {};
    if (node !== null) {
        try {
            info = JSON.parse(node.dataset.meContractInfo);
        } catch {
            info = {};
        }
    }

    const badge = document.getElementById('contract_status_badge');
    if (badge !== null) {
        if (!info.status) {
            badge.replaceChildren();
        } else {
            const is_closed = config.closed_state_id > 0 && info.states_id === config.closed_state_id;
            const status = document.createElement('span');
            status.className = `badge fs-6 ${is_closed ? 'bg-danger-lt' : 'bg-success-lt'}`;
            status.append(icon(`${is_closed ? 'ti-circle-x' : 'ti-circle-check'} me-1`), info.status);
            badge.replaceChildren(status);
        }
    }

    toggleBlock('contract_comment_block', 'contract_comment_text', (info.comment || '').trim());
    toggleBlock('contract_end_date_block', 'contract_end_date_text', formatDateDMY(info.end_date || ''));

    // Plugin contract flags: internet publication only
    const flags = document.getElementById('contract_flags_block');
    const inet  = document.getElementById('contract_inet_badge');
    if (flags !== null && inet !== null) {
        inet.textContent    = info.inet ? config.labels.internet_publication : '';
        inet.className      = info.inet ? 'badge bg-cyan-lt' : 'badge bg-secondary-lt';
        inet.style.display  = info.inet ? '' : 'none';
        flags.style.display = info.inet ? '' : 'none';
    }
}

function report(error) {
    console.error(error);
}

document.addEventListener('manageentities:change', (event) => {
    const config = getConfig();
    if (config === null) {
        return;
    }
    const field = event.target;
    if (field.name === 'withcontract') {
        const section = document.getElementById('contract');
        if (section !== null) {
            section.style.display = field.value !== '0' ? '' : 'none';
        }
    } else if (field.name === 'plugin_manageentities_contractdays_id') {
        updateRemainingDays(config, field.value).catch(report);
    }
});

// The period dropdown is rebuilt by the Ajax refresh of the contract dropdown (on load and on
// change): read the preselected period and the contract details once the DOM settles.
new MutationObserver((mutations) => {
    const container = document.getElementById('show_contractdays');
    if (container === null || !mutations.some((m) => container.contains(m.target))) {
        return;
    }
    const config = getConfig();
    if (config === null) {
        return;
    }
    const period = container.querySelector('select[name="plugin_manageentities_contractdays_id"]');
    if (period !== null) {
        updateRemainingDays(config, period.value).catch(report);
    }
    updateContractStatus(config, container);
}).observe(document.body, {childList: true, subtree: true});
