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

// Publisher subscription fields (templates/editorsubscription_fields.html.twig), shared by the
// subscription page and step 3 of the entity wizard. Their container carries
// data-me-subscription = { required, lookup_url, labels }.
//
// - the subscription type radios fill the two posted flags, force the internet publication of a
//   cloud client and restrict the levels to those of the chosen type;
// - with a lookup_url (subscription page opened without an entity), picking an entity loads its
//   existing subscription into the form (ajax/getSubscription.php);
// - when required, the level is checked before the form is submitted.
//
// The entity dropdown is a select2 widget: its on_change relays a native "manageentities:change"
// event (CriDetail::CHANGE_EVENT_JS). Values are written through .value / .textContent only.

const TYPE_ALL = 0;
const TYPE_ON_PREMISE = 1;
const TYPE_CLOUD = 2;

function parseConfig(container) {
    try {
        return JSON.parse(container.dataset.meSubscription) ?? {};
    } catch {
        return {};
    }
}

function levelSelect(container) {
    return container.querySelector('select[name="plugin_manageentities_subscriptionlevels_id"]');
}

function internetCheckbox(container) {
    return container.querySelector('input[type="checkbox"][name="internet_publication"]');
}

function filterLevels(container, type) {
    const select = levelSelect(container);
    if (select === null) {
        return;
    }
    const target_type = type === 'cloud' ? TYPE_CLOUD : TYPE_ON_PREMISE;
    for (const option of select.options) {
        if (option.value === '0') {
            continue;
        }
        const option_type = parseInt(option.dataset.subType, 10);
        const visible = option_type === TYPE_ALL || option_type === target_type;
        option.hidden = !visible;
        option.disabled = !visible;
    }
    const selected = select.options[select.selectedIndex];
    if (selected === undefined || selected.hidden) {
        select.value = '0';
    }
}

function syncType(container) {
    const checked = container.querySelector('input[name="subscription_type"]:checked');
    if (checked === null) {
        return;
    }
    const is_cloud = checked.value === 'cloud';
    container.querySelector('input[type="hidden"][name="active_editor_suscription"]').value = checked.value === 'editor' ? '1' : '0';
    container.querySelector('input[type="hidden"][name="cloud_client"]').value = is_cloud ? '1' : '0';

    // A cloud client is always published on the internet
    const inet = internetCheckbox(container);
    if (inet !== null) {
        if (is_cloud) {
            inet.checked = true;
        }
        inet.style.pointerEvents = is_cloud ? 'none' : '';
        inet.style.opacity = is_cloud ? '0.6' : '';
    }
    filterLevels(container, checked.value);
}

function setFormMode(form, labels, is_new) {
    const title = form.querySelector('[data-me-sub-title]');
    if (title !== null) {
        title.textContent = is_new ? labels.create_title : labels.update_title;
    }
    const submit_label = form.querySelector('[data-me-sub-submit-label]');
    if (submit_label !== null) {
        submit_label.textContent = is_new ? labels.add : labels.update;
    }
    const delete_button = form.querySelector('[data-me-sub-delete]');
    if (delete_button !== null) {
        delete_button.classList.toggle('d-none', is_new);
    }
}

function setValue(container, name, value) {
    const input = container.querySelector(`[name="${name}"]:not([type="hidden"])`);
    if (input !== null) {
        input.value = value ?? '';
    }
}

async function loadSubscription(container, config, entity_select) {
    const form = container.closest('form');
    const sub_id = form.querySelector('input[name="sub_id"]');
    const entities_id = entity_select.value;

    if (!entities_id || entities_id === '0') {
        sub_id.value = '0';
        setFormMode(form, config.labels, true);
        return;
    }

    const response = await fetch(config.lookup_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({ entities_id }),
    });
    if (!response.ok) {
        throw new Error(`Request to ${config.lookup_url} failed (HTTP ${response.status})`);
    }
    const data = await response.json();

    if (!data.found) {
        sub_id.value = '0';
        // Suggest the entity short name while the referenced name is still empty
        const name_input = container.querySelector('input[name="name"]');
        const option = entity_select.options[entity_select.selectedIndex];
        if (name_input !== null && name_input.value.trim() === '' && option !== undefined) {
            name_input.value = option.text.trim().split(' > ').pop().trim();
        }
        setFormMode(form, config.labels, true);
        return;
    }

    sub_id.value = data.sub_id;
    setValue(container, 'name', data.name);
    setValue(container, 'customer_account_id', data.customer_account_id);
    setValue(container, 'begin_date', data.begin_date);
    setValue(container, 'end_date', data.end_date);
    setValue(container, 'comment', data.comment);
    const radio = container.querySelector(
        `input[name="subscription_type"][value="${data.cloud_client ? 'cloud' : 'editor'}"]`,
    );
    if (radio !== null) {
        radio.checked = true;
    }
    const inet = internetCheckbox(container);
    if (inet !== null) {
        inet.checked = Boolean(data.internet_publication);
    }
    syncType(container);
    setValue(container, 'plugin_manageentities_subscriptionlevels_id', String(data.plugin_manageentities_subscriptionlevels_id));
    setFormMode(form, config.labels, false);
}

function checkRequiredLevel(container, config, event) {
    const select = levelSelect(container);
    if (select === null) {
        return;
    }
    if (select.value && select.value !== '0') {
        select.classList.remove('is-invalid');
        return;
    }
    event.preventDefault();
    select.classList.add('is-invalid');
    if (select.parentNode.querySelector('.invalid-feedback') === null) {
        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.textContent = config.labels.level_required;
        select.parentNode.appendChild(feedback);
    }
    select.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

for (const container of document.querySelectorAll('[data-me-subscription]')) {
    const config = parseConfig(container);

    container.addEventListener('change', (event) => {
        if (event.target.name === 'subscription_type') {
            syncType(container);
        }
    });
    syncType(container);

    // The wizard step posts its fields itself (wizard.js): only the subscription page has a form
    const form = container.closest('form');
    if (form === null) {
        continue;
    }
    if (config.required) {
        form.addEventListener('submit', (event) => checkRequiredLevel(container, config, event));
    }
    if (config.lookup_url) {
        form.addEventListener('manageentities:change', (event) => {
            if (event.target.name === 'entities_id') {
                loadSubscription(container, config, event.target).catch((error) => console.error(error));
            }
        });
    }
}

// "Delete permanently" submits its own form, placed after the edition form (form="..." attribute):
// confirm, then post the subscription currently displayed, which may have been loaded after the
// page was built.
document.addEventListener('submit', (event) => {
    const delete_form = event.target.closest('[data-me-sub-delete-form]');
    if (delete_form === null) {
        return;
    }
    if (!window.confirm(delete_form.dataset.meSubDeleteForm)) {
        event.preventDefault();
        return;
    }
    const sub_id = document.querySelector('#me-sub-form input[name="sub_id"]');
    if (sub_id !== null) {
        delete_form.querySelector('input[name="sub_id"]').value = sub_id.value;
    }
});
