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


// Stakeholders tab of a contract day (templates/interventionstakeholder_*.html.twig).
//
// No JavaScript is generated server side: the add button and the delete icons carry their
// parameters in data-me-* attributes, and ajax/interventionstakeholderactions.php answers
// with hidden <div data-me-stakeholder-action> elements holding a JSON payload, which are
// replayed here. The response is parsed with DOMParser and never inserted in the page:
// every value reaches the DOM through textContent or an attribute, never as HTML.
//
// Handlers are delegated on the document because the tab is loaded through AJAX.

async function post(url, data) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams(data),
    });
    if (!response.ok) {
        throw new Error(`Stakeholder action failed (HTTP ${response.status})`);
    }

    // DOMParser never runs scripts nor loads resources
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    doc.querySelectorAll('[data-me-stakeholder-action]').forEach((node) => {
        let payload;
        try {
            payload = JSON.parse(node.dataset.mePayload || '{}');
        } catch {
            return;
        }
        switch (node.dataset.meStakeholderAction) {
            case 'message':
                showMessage(payload);
                break;
            case 'row':
                refreshRow(payload);
                break;
            case 'form': {
                const form = document.getElementById(`global_form_content${payload.contractdays_id}`);
                if (form !== null) {
                    form.style.display = payload.visible ? '' : 'none';
                    resetDaysDropdown(form, payload.max_days);
                }
                break;
            }
        }
    });
}

// The number of days is a select2 AJAX dropdown (Dropdown::showNumber()) whose max is
// fixed when the tab is rendered: after an add or a delete, its choices are rebuilt locally
// from 0 to the balance sent back by the server, which also enforces it.
function resetDaysDropdown(form, max_days) {
    const button = form.querySelector('[data-me-stakeholder-add]');
    const field  = button === null ? null : document.getElementById(button.dataset.meNbdaysField);
    const max    = Number(max_days);
    if (field === null || !Number.isFinite(max) || window.jQuery === undefined) {
        return;
    }

    const step = Number(button.dataset.meNbdaysStep) || 1;
    const data = [];
    for (let i = 0; i <= max + 1e-9; i += step) {
        data.push({id: String(i), text: String(i)});
    }

    const select2 = window.jQuery(field);
    select2.select2('destroy');
    field.replaceChildren();
    select2.select2({width: '100', data: data}).val('0').trigger('change');
}

function showMessage(payload) {
    const is_error = payload.type === 'error';
    const label    = document.createElement('span');
    label.textContent = payload.title;
    const text = document.createElement('span');
    text.textContent = payload.message;

    // glpi_alert() writes both the title and the message as HTML: only escaped text and
    // a fixed icon markup are handed over.
    window.glpi_alert({
        title: `<i class="ti ${is_error ? 'ti-alert-triangle text-orange' : 'ti-info-circle text-green'}"></i>&nbsp;${label.innerHTML}`,
        message: text.innerHTML,
    });
}

function refreshRow(data) {
    const tbl = document.getElementById(data.table_id);
    if (tbl === null) {
        return;
    }

    if (data.to_delete) {
        document.getElementById(data.row_id)?.remove();
        if (data.is_empty && document.getElementById(data.empty_id) === null) {
            const empty_row  = tbl.tBodies[0].insertRow(-1);
            empty_row.id     = data.empty_id;
            const cell       = empty_row.insertCell(0);
            cell.colSpan     = 3;
            cell.className   = 'text-muted p-3';
            cell.textContent = tbl.dataset.meEmptyLabel;
        }
        return;
    }

    const days_cell = document.getElementById(data.cell_id);
    if (days_cell !== null) {
        // Existing stakeholder: only the number of days changes
        days_cell.textContent = data.nb_days;
        return;
    }

    document.getElementById(data.empty_id)?.remove();

    const row = tbl.tBodies[0].insertRow(-1);
    row.id    = data.row_id;

    const link       = document.createElement('a');
    link.href        = data.user_url;
    link.target      = '_blank';
    link.textContent = data.user_name;
    row.insertCell(0).appendChild(link);

    const span       = document.createElement('span');
    span.id          = data.cell_id;
    span.textContent = data.nb_days;
    row.insertCell(1).appendChild(span);

    const action_cell     = row.insertCell(2);
    action_cell.className = 'text-end';
    const icon            = document.createElement('i');
    icon.className        = 'ti ti-trash pointer';
    icon.title            = tbl.dataset.meDeleteLabel;
    icon.dataset.meStakeholderDelete = data.stakeholder_id;
    action_cell.appendChild(icon);
}

function fieldValue(id) {
    return document.getElementById(id)?.value ?? '';
}

document.addEventListener('click', (event) => {
    const add_button = event.target.closest('[data-me-stakeholder-add]');
    if (add_button !== null) {
        const btn = add_button.dataset;
        post(btn.meUrl, {
            action:          'add_user_datas',
            contractdays_id: btn.meContractdaysId,
            nb_days:         fieldValue(btn.meNbdaysField),
            users_id_tech:   fieldValue(btn.meUserField),
        }).catch((error) => console.error(error));
        return;
    }

    const delete_icon = event.target.closest('[data-me-stakeholder-delete]');
    if (delete_icon !== null) {
        const tbl = delete_icon.closest('table');
        if (tbl === null || !window.confirm(tbl.dataset.meConfirmLabel)) {
            return;
        }
        post(tbl.dataset.meUrl, {
            action:          'delete_user_datas',
            contractdays_id: tbl.dataset.meContractdaysId,
            stakeholder_id:  delete_icon.dataset.meStakeholderDelete,
        }).catch((error) => console.error(error));
    }
});
