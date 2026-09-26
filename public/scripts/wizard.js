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

/* global getAjaxCsrfToken, bootstrap */

// Navigation and dynamic blocks of the AddElements wizard (templates/wizard/*). Buttons carry a
// data-me-wizard-action attribute, dispatched by a single delegated click listener, plus the
// data-step / data-idx / data-id / data-rand / data-mode / data-block they need. The AJAX URL
// and the settings come from the data-me-wizard attribute of #wizard-addelements. No-op elsewhere.

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

/**
 * Wizard settings (url, i18n, mode, id, use_subscriptions), carried as JSON by the
 * data-me-wizard attribute of #wizard-addelements (templates/wizard/layout and mode_choice).
 */
function wizardConfig() {
    const root = document.getElementById('wizard-addelements');
    let config = {};
    if (root?.dataset.meWizard) {
        try {
            config = JSON.parse(root.dataset.meWizard) ?? {};
        } catch {
            config = {};
        }
    }
    return {
        url: config.url || '',
        i18n: config.i18n || {},
        mode: config.mode || '',
        id: config.id || '',
        use_subscriptions: config.use_subscriptions !== false,
    };
}

// Scripts injected through insertAdjacentHTML/innerHTML are inert: re-create them so the
// dropdowns rendered by the server initialise.
function execScripts(container) {
    container.querySelectorAll('script').forEach((old_script) => {
        const new_script = document.createElement('script');
        Array.from(old_script.attributes).forEach((attr) => {
            new_script.setAttribute(attr.name, attr.value);
        });
        new_script.textContent = old_script.textContent;
        old_script.replaceWith(new_script);
    });
}

function wizardFetch(url, body) {
    const headers = {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
    };
    let init;
    if (body instanceof FormData) {
        init = { method: 'POST', headers, body };
    } else {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        init = { method: 'POST', headers, body: new URLSearchParams(body).toString() };
    }
    return fetch(url, init).then((response) => {
        if (!response.ok) {
            return Promise.reject(new Error(`HTTP ${response.status} ${response.statusText}`));
        }
        return response;
    });
}

function networkErrMsg(err) {
    return err?.message ? `Network error: ${err.message}` : 'Network error';
}

function makeElement(tag, class_name, text) {
    const el = document.createElement(tag);
    if (class_name) {
        el.className = class_name;
    }
    if (text !== undefined) {
        el.textContent = text;
    }
    return el;
}

function spinner() {
    return makeElement('span', 'spinner-border spinner-border-sm me-1');
}

// Replaces the content of a button with a Tabler icon followed by a text label
function setIconLabel(button, icon, label) {
    button.replaceChildren(makeElement('i', `ti ${icon} me-1`), document.createTextNode(label));
}

function collectSection(section_el) {
    const data = {};
    if (!section_el) {
        return data;
    }
    // Processed in document order: a hidden input precedes each checkbox and provides the '0'
    // fallback, a checked checkbox then overrides it
    section_el.querySelectorAll('[name]').forEach((el) => {
        if (el.disabled || el.type === 'file') {
            return;
        }
        if (el.type === 'checkbox') {
            if (el.checked) {
                data[el.name] = el.value;
            }
        } else {
            data[el.name] = el.value;
        }
    });
    return data;
}

function showStepErrors(step, errors) {
    const el = document.getElementById(`step${step}-errors`);
    if (!el) {
        return;
    }
    let msgs = [];
    if (errors !== null && typeof errors === 'object') {
        msgs = Object.values(errors).map(String);
    } else if (errors) {
        msgs = [String(errors)];
    }
    el.replaceChildren();
    msgs.forEach((msg, i) => {
        if (i > 0) {
            el.append(document.createElement('br'));
        }
        el.append(document.createTextNode(msg));
    });
    el.classList.remove('d-none');
}

function clearStepErrors(step) {
    const el = document.getElementById(`step${step}-errors`);
    if (el) {
        el.replaceChildren();
        el.classList.add('d-none');
    }
}

function reloadStep(step, url) {
    const wizard_id = wizardConfig().id;
    const wid_param = wizard_id ? `&wid=${encodeURIComponent(wizard_id)}` : '';
    window.location.href = `${url.replace(/wizard\.php.*/, '')}../front/addelements.form.php?step=${step}${wid_param}`;
}

// Fills a summary box with a list of { type, label } items, or with a muted message
function renderSummary(container, items, empty_message) {
    if (!items || items.length === 0) {
        container.replaceChildren(makeElement('p', 'text-muted fst-italic', empty_message));
        return;
    }
    const list = makeElement('ul', 'list-group list-group-flush');
    items.forEach((item) => {
        const li = makeElement('li', 'list-group-item d-flex align-items-start gap-2 px-0 py-1');
        const badge = makeElement('span', 'badge bg-secondary-lt flex-shrink-0', String(item.type));
        badge.style.minWidth = '130px';
        li.append(badge, makeElement('span', '', String(item.label)));
        list.append(li);
    });
    container.replaceChildren(list);
}

function loadingBox() {
    const box = makeElement('div', 'text-center py-3');
    box.append(makeElement('div', 'spinner-border spinner-border-sm'));
    return box;
}

// -------------------------------------------------------------------------
// Navigation
// -------------------------------------------------------------------------

function saveStep(step, url) {
    clearStepErrors(step);
    const card = document.querySelector('#wizard-step-content .card');

    const config = wizardConfig();
    let action_map;
    if (config.mode === 'existing_entity') {
        action_map = {
            1: 'save_entity',
            4: 'save_contract',
            5: 'save_management_type',
            6: 'save_interventions',
        };
    } else {
        action_map = {
            1: 'save_entity',
            2: 'save_contacts',
            4: 'save_contract',
            5: 'save_management_type',
            6: 'save_interventions',
        };
        if (config.use_subscriptions) {
            action_map[3] = 'save_subscription';
        }
    }

    const action = action_map[step];
    if (!action) {
        return;
    }

    // Step 4: contract fields (text) then documents (files) as a second POST
    if (step === 4) {
        const fd_contract = new FormData();
        const fd_docs = new FormData();
        fd_contract.append('action', action);
        fd_docs.append('action', 'upload_documents');
        let has_files = false;
        card?.querySelectorAll('[name]').forEach((el) => {
            if (el.disabled) {
                return;
            }
            if (el.type === 'file') {
                if (el.files?.[0]) {
                    fd_docs.append(el.name, el.files[0]);
                    has_files = true;
                }
            } else if (el.name.startsWith('documents[')) {
                // Category dropdowns of the documents go with the files
                fd_docs.append(el.name, el.value);
            } else if (el.type === 'checkbox') {
                fd_contract.append(el.name, el.checked ? el.value : '0');
            } else {
                fd_contract.append(el.name, el.value);
            }
        });
        wizardFetch(url, fd_contract)
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) {
                    handleStepResponse(step, res, url);
                    return null;
                }
                // Upload documents only if any file was selected
                if (!has_files) {
                    return res;
                }
                return wizardFetch(url, fd_docs).then((r) => r.json());
            })
            .then((res) => {
                if (res) {
                    handleStepResponse(step, res, url);
                }
            })
            .catch((err) => showStepErrors(step, networkErrMsg(err)));
        return;
    }

    const payload = { action };
    if (card) {
        Object.assign(payload, collectSection(card));
    }

    wizardFetch(url, payload)
        .then((r) => r.json())
        .then((res) => handleStepResponse(step, res, url))
        .catch((err) => showStepErrors(step, networkErrMsg(err)));
}

function handleStepResponse(step, res, url) {
    if (!res.success) {
        if (res.entity_exists && step === 1) {
            promptUseExistingEntity(res.entities_id, res.entity_name, url);
            return;
        }
        if (res.entity_archived && step === 1) {
            promptUnarchiveEntity(res.entities_id, res.entity_name, url);
            return;
        }
        showStepErrors(step, res.errors || res.message || 'Error');
        return;
    }
    // The server may skip steps (e.g. existing_entity mode jumps from step 1 to step 4)
    const max_step = 6;
    const next_step = (res.step && res.step > step) ? res.step : (step < max_step ? step + 1 : step);
    reloadStep(next_step, url);
}

// Opens a confirmation modal whose confirm button runs on_confirm; the button is cloned so a
// previous prompt does not leave its listener behind
function promptEntity(prefix, message, on_confirm) {
    const modal = document.getElementById(`${prefix}-modal`);
    const msg_el = document.getElementById(`${prefix}-msg`);
    const btn = document.getElementById(`${prefix}-confirm-btn`);
    if (!modal || !msg_el || !btn) {
        return;
    }

    msg_el.textContent = message;

    const new_btn = btn.cloneNode(true);
    btn.replaceWith(new_btn);
    new_btn.addEventListener('click', () => {
        bootstrap.Modal.getInstance(modal).hide();
        on_confirm();
    });

    new bootstrap.Modal(modal).show();
}

function stepOneResult(promise, url) {
    promise
        .then((r) => r.json())
        .then((res) => {
            if (res.success) {
                reloadStep(res.step || 3, url);
            } else {
                showStepErrors(1, res.errors || res.message || 'Error');
            }
        })
        .catch((err) => showStepErrors(1, networkErrMsg(err)));
}

function promptUseExistingEntity(entities_id, entity_name, url) {
    const message = (wizardConfig().i18n.entityExistsMsg ?? '%s').replace('%s', entity_name);
    promptEntity('wizard-entity-exists', message, () => {
        stepOneResult(
            wizardFetch(url, { action: 'choose_mode', wizard_mode: 'existing_entity' })
                .then((r) => r.json())
                .then(() => wizardFetch(url, { action: 'save_entity', entities_id })),
            url,
        );
    });
}

function promptUnarchiveEntity(entities_id, entity_name, url) {
    const message = (wizardConfig().i18n.entityArchivedMsg ?? '%s').replace('%s', entity_name);
    promptEntity('wizard-entity-archived', message, () => {
        stepOneResult(wizardFetch(url, { action: 'unarchive_entity', entities_id }), url);
    });
}

function back(step, url) {
    if (step <= 1) {
        return;
    }
    const config = wizardConfig();
    let sequence;
    if (config.mode === 'existing_entity') {
        sequence = [1, 4, 5, 6];
    } else {
        sequence = config.use_subscriptions ? [1, 2, 3, 4, 5, 6] : [1, 2, 4, 5, 6];
    }
    const idx = sequence.indexOf(step);
    reloadStep(idx > 0 ? sequence[idx - 1] : sequence[0], url);
}

function chooseMode(mode, url) {
    wizardFetch(url, { action: 'choose_mode', wizard_mode: mode })
        .then((r) => r.json())
        .then((res) => {
            if (res.success) {
                reloadStep(1, url);
            }
        });
}

/**
 * Step 3 exit that creates the entity, its contacts and the subscription only.
 * The subscription fields are saved first, carrying the skip flag the server stores in
 * the wizard session, then the regular finish modal opens on a summary that no longer
 * lists a contract, a management type nor a period of contract.
 */
function finishWithoutContract(step, url) {
    clearStepErrors(step);
    const card = document.querySelector('#wizard-step-content .card');

    const payload = card ? collectSection(card) : {};
    payload.action = 'save_subscription';
    payload.skip_contract = '1';

    wizardFetch(url, payload)
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                showStepErrors(step, res.errors || res.message || 'Error');
                return;
            }
            loadFinishSummary(url, step);
        })
        .catch((err) => showStepErrors(step, networkErrMsg(err)));
}

// Step whose error box confirmFinish() writes into: the finish modal is shared by the last
// step and by the early exit of step 3, and it is opened from either
let finish_step = 6;

function loadFinishSummary(url, step) {
    const current_step = step || 6;
    finish_step = current_step;
    const save_btn = document.getElementById('btn-save-finish');
    const restoreSaveBtn = () => {
        if (save_btn) {
            save_btn.disabled = false;
            setIconLabel(save_btn, 'ti-check', save_btn.dataset.label || 'Save and finish');
        }
    };
    if (save_btn) {
        save_btn.disabled = true;
        save_btn.replaceChildren(spinner());
    }

    wizardFetch(url, { action: 'finish_wizard' })
        .then((r) => r.json())
        .then((res) => {
            restoreSaveBtn();
            if (!res.success) {
                showStepErrors(current_step, res.errors || res.message || 'Error');
                return;
            }

            const summary_el = document.getElementById('wizard-finish-summary');
            const confirm_btn = document.getElementById('wizard-finish-confirm-btn');
            if (confirm_btn) {
                confirm_btn.disabled = false;
            }
            if (summary_el) {
                renderSummary(summary_el, res.summary, wizardConfig().i18n.nothingToDisplay ?? '');
            }

            // Open the modal only after a successful validation
            const modal_el = document.getElementById('wizard-finish-modal');
            if (modal_el) {
                bootstrap.Modal.getOrCreateInstance(modal_el).show();
            }
        })
        .catch((err) => {
            restoreSaveBtn();
            showStepErrors(current_step, networkErrMsg(err));
        });
}

function confirmFinish(url) {
    const confirm_btn = document.getElementById('wizard-finish-confirm-btn');
    const restoreConfirmBtn = () => {
        if (confirm_btn) {
            confirm_btn.disabled = false;
            setIconLabel(confirm_btn, 'ti-check', confirm_btn.dataset.label || 'Confirm');
        }
    };
    if (confirm_btn) {
        confirm_btn.disabled = true;
        confirm_btn.replaceChildren(spinner());
    }
    // Commit: write everything to the database then redirect
    wizardFetch(url, { action: 'commit_wizard' })
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                restoreConfirmBtn();
                showStepErrors(finish_step, res.errors || res.message || 'Error');
                return;
            }
            if (res.redirect_url) {
                window.location.href = res.redirect_url;
            } else {
                reloadStep(1, url);
            }
        })
        .catch((err) => {
            restoreConfirmBtn();
            showStepErrors(finish_step, networkErrMsg(err));
        });
}

function loadResetSummary(url) {
    const summary_el = document.getElementById('wizard-reset-summary');
    summary_el?.replaceChildren(loadingBox());
    wizardFetch(url, { action: 'get_reset_summary' })
        .then((r) => r.json())
        .then((res) => {
            if (!summary_el) {
                return;
            }
            if (!res.success) {
                summary_el.replaceChildren(makeElement('p', 'text-muted fst-italic', 'Unable to load summary.'));
                return;
            }
            renderSummary(summary_el, res.items, 'No unsaved data in the current session.');
        })
        .catch((err) => {
            summary_el?.replaceChildren(makeElement('p', 'text-danger', networkErrMsg(err)));
        });
}

function confirmReset(url) {
    const btn = document.getElementById('wizard-reset-confirm-btn');
    if (btn) {
        btn.disabled = true;
        btn.replaceChildren(spinner(), document.createTextNode(btn.dataset.labelDeleting || 'Deleting…'));
    }
    wizardFetch(url, { action: 'reset_and_delete' })
        .then((r) => r.json())
        .then(() => reloadStep(1, url))
        .catch(() => reloadStep(1, url));
}

// -------------------------------------------------------------------------
// Per-block intervention save
// -------------------------------------------------------------------------

function saveIntervention(idx, url) {
    const err_el = document.getElementById(`intervention-errors-${idx}`);
    if (err_el) {
        err_el.textContent = '';
    }

    const block = document.querySelector(`.intervention-block[data-idx="${CSS.escape(idx)}"]`);
    if (!block) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'save_intervention');
    fd.append('idx', idx);
    block.querySelectorAll(`[name^="interventions[${CSS.escape(idx)}]"]`).forEach((el) => {
        if (el.disabled) {
            return;
        }
        const match = el.name.match(/\[([^\]]+)\]$/);
        if (!match) {
            return;
        }
        fd.set(`intervention[${match[1]}]`, el.type === 'checkbox' ? (el.checked ? el.value : '0') : el.value);
    });

    wizardFetch(url, fd)
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                const msgs = res.errors ? Object.values(res.errors).join(', ') : (res.message || 'Error');
                if (err_el) {
                    err_el.textContent = msgs;
                }
                return;
            }
            // intervention_idx is the virtual key, there is no database id yet
            block.dataset.id = res.intervention_idx;

            const badge = block.querySelector(`#unsaved-badge-${CSS.escape(idx)}`);
            if (badge) {
                const saved = makeElement('span', 'badge bg-success-lt ms-2');
                saved.append(makeElement('i', 'ti ti-check'));
                badge.replaceWith(saved);
            }

            // Rates and stakeholders sections, rendered by the server (with their dropdown scripts)
            const sections_el = document.getElementById(`intervention-sections-${idx}`);
            if (sections_el) {
                sections_el.innerHTML = `<hr class="my-3">${res.criprices_html}<hr class="my-3">${res.stakeholders_html}`;
                execScripts(sections_el);
            }
        })
        .catch((err) => {
            if (err_el) {
                err_el.textContent = networkErrMsg(err);
            }
        });
}

// -------------------------------------------------------------------------
// Rates and stakeholders
// -------------------------------------------------------------------------

// Toggles a flex form: needed because Bootstrap's .d-flex { display:flex !important }
// overrides a plain style="display:none"
function toggleFlexForm(el, show) {
    if (show) {
        el.classList.add('d-flex');
        el.style.removeProperty('display');
    } else {
        el.classList.remove('d-flex');
        el.style.setProperty('display', 'none', 'important');
    }
}

function trashButton(action, id) {
    const btn = makeElement('button', 'btn btn-sm btn-outline-danger');
    btn.type = 'button';
    btn.dataset.meWizardAction = action;
    btn.dataset.id = id;
    btn.append(makeElement('i', 'ti ti-trash'));
    return btn;
}

function addCriPrice(intervention_idx, rand, url) {
    const critype_el = document.getElementById(`dropdown_new_critype_${intervention_idx}${rand}`);
    const price_el = document.getElementById(`new_price_${intervention_idx}`);
    const def_el = document.getElementById(`new_is_default_${intervention_idx}`);

    if (!critype_el || !price_el) {
        return;
    }
    const price = parseFloat(price_el.value);
    if (!price || price <= 0) {
        price_el.focus();
        price_el.classList.add('is-invalid');
        return;
    }
    price_el.classList.remove('is-invalid');
    const is_default = def_el?.checked === true;

    wizardFetch(url, {
        action: 'save_criprice',
        intervention_idx,
        plugin_manageentities_critypes_id: critype_el.value,
        price: price_el.value,
        is_default: is_default ? '1' : '0',
        criprice_id: '0',
    })
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                alert(res.message || 'Error');
                return;
            }
            const list_el = document.getElementById(`criprices-list-${intervention_idx}`);
            if (!list_el) {
                return;
            }
            document.getElementById(`no-criprices-${intervention_idx}`)?.remove();

            // Built node by node: the rate type label is database content
            const row = makeElement('div', 'd-flex align-items-center gap-2 mb-2 criprice-row');
            row.dataset.id = res.criprice_id;
            const option = critype_el.options[critype_el.selectedIndex];
            row.append(
                makeElement('span', 'badge bg-secondary-lt', option ? option.text : ''),
                makeElement('strong', '', price.toFixed(2)),
            );
            if (is_default) {
                row.append(makeElement('span', 'badge bg-primary-lt', def_el.dataset.labelDefault || 'Default'));
            }
            row.append(trashButton('delete-criprice', res.criprice_id));
            list_el.append(row);

            price_el.value = '';
            if (def_el) {
                def_el.checked = false;
            }
            // Only one rate per intervention: hide the add form
            const form_el = document.getElementById(`criprices-form-${intervention_idx}`);
            if (form_el) {
                toggleFlexForm(form_el, false);
            }
        });
}

function deleteCriPrice(id, btn, url) {
    wizardFetch(url, { action: 'delete_criprice', criprice_id: id })
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                return;
            }
            btn.closest('.criprice-row')?.remove();
            // Show the add form again when no rate remains
            if (res.intervention_idx !== undefined) {
                const form_el = document.getElementById(`criprices-form-${res.intervention_idx}`);
                if (form_el) {
                    toggleFlexForm(form_el, !res.has_rate);
                    if (!res.has_rate) {
                        const def_el = document.getElementById(`new_is_default_${res.intervention_idx}`);
                        if (def_el) {
                            def_el.checked = true;
                        }
                    }
                }
            }
        });
}

function updateRemainingDays(intervention_idx, remaining, credit) {
    const has_limit = credit !== undefined && credit !== null && parseFloat(credit) > 0;
    if (!has_limit || remaining === null || remaining === undefined) {
        return;
    }
    const label_el = document.getElementById(`remaining-days-${intervention_idx}`);
    if (label_el) {
        label_el.textContent = parseFloat(remaining).toFixed(2);
    }
    const days_el = document.getElementById(`new_nb_days_${intervention_idx}`);
    if (days_el) {
        days_el.max = remaining;
    }
    const form_el = document.getElementById(`stakeholders-form-${intervention_idx}`);
    if (form_el) {
        toggleFlexForm(form_el, parseFloat(remaining) > 0);
    }
}

function addStakeholder(intervention_idx, rand, url) {
    const user_el = document.getElementById(`dropdown_new_user_${intervention_idx}${rand}`);
    const days_el = document.getElementById(`new_nb_days_${intervention_idx}`);

    if (!user_el?.value) {
        return;
    }
    const nb_days = days_el ? parseFloat(days_el.value) : 0;
    if (!nb_days || nb_days <= 0) {
        alert('Please enter a number of days greater than 0');
        return;
    }

    wizardFetch(url, {
        action: 'add_stakeholder',
        intervention_idx,
        users_id: user_el.value,
        number_affected_days: nb_days,
    })
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                alert(res.message || 'Error');
                if (res.remaining_days !== undefined) {
                    updateRemainingDays(intervention_idx, res.remaining_days, res.credit);
                }
                return;
            }
            const list_el = document.getElementById(`stakeholders-list-${intervention_idx}`);
            if (list_el) {
                // Built node by node: the user name is plain text
                const row = makeElement('div', 'd-flex align-items-center gap-2 mb-1 stakeholder-row');
                row.dataset.id = res.stakeholder_id;
                row.append(
                    makeElement('span', 'badge bg-secondary-lt', res.user_name),
                    makeElement('span', 'text-muted small', `${parseFloat(res.number_affected_days).toFixed(2)} day(s)`),
                    trashButton('delete-stakeholder', res.stakeholder_id),
                );
                list_el.append(row);
                if (days_el) {
                    days_el.value = '';
                }
            }
            updateRemainingDays(intervention_idx, res.remaining_days, res.credit);
        });
}

function deleteStakeholder(id, btn, url) {
    const row = btn.closest('.stakeholder-row');
    const section = btn.closest('.wizard-stakeholders-section');
    const intervention_idx = section ? section.id.replace('stakeholders-section-', '') : null;

    wizardFetch(url, { action: 'delete_stakeholder', stakeholder_id: id })
        .then((r) => r.json())
        .then((res) => {
            if (res.success) {
                row?.remove();
                if (intervention_idx) {
                    updateRemainingDays(intervention_idx, res.remaining_days, res.credit);
                }
            }
        });
}

// -------------------------------------------------------------------------
// Contract template and documents
// -------------------------------------------------------------------------

function loadContractTemplate(rand_tpl, url) {
    const select_el = document.getElementById(`dropdown__contract_template_id${rand_tpl}`);
    if (!select_el?.value) {
        return;
    }

    wizardFetch(url, { action: 'load_contract_template', contracts_id: select_el.value })
        .then((r) => r.json())
        .then((res) => {
            if (res.success && res.redirect) {
                reloadStep(res.step || 3, url);
            }
        });
}

function deleteDocument(id, btn, url) {
    const row = btn.closest('.document-block');
    wizardFetch(url, { action: 'delete_document', document_id: id })
        .then((r) => r.json())
        .then((res) => {
            if (res.success) {
                row?.remove();
            }
        });
}

// -------------------------------------------------------------------------
// Dynamic contact, document and intervention blocks
// -------------------------------------------------------------------------

const BLOCKS = {
    contact: { action: 'add_contact_block', container: 'contacts-container', selector: '.contact-block' },
    document: { action: 'add_document_block', container: 'documents-container', selector: '.document-block' },
    intervention: { action: 'add_intervention_block', container: 'interventions-container', selector: '.intervention-block' },
};

// Last index used per block kind, seeded from the blocks rendered with the page
const block_counters = {};

function initBlockCounters() {
    for (const [kind, block] of Object.entries(BLOCKS)) {
        block_counters[kind] = document.querySelectorAll(block.selector).length;
    }
}

function addBlock(kind, url) {
    const block = BLOCKS[kind];
    block_counters[kind] = (block_counters[kind] ?? 0) + 1;
    wizardFetch(url, { action: block.action, idx: block_counters[kind] })
        .then((r) => r.text())
        .then((html) => {
            const container = document.getElementById(block.container);
            if (!container) {
                return;
            }
            container.insertAdjacentHTML('beforeend', html);
            execScripts(container.lastElementChild);
        });
}

function removeBlock(kind, idx) {
    const block = BLOCKS[kind];
    if (block) {
        document.querySelector(`${block.selector}[data-idx="${CSS.escape(idx)}"]`)?.remove();
    }
}

// -------------------------------------------------------------------------
// Delegated listeners
// -------------------------------------------------------------------------

const ACTIONS = {
    'save-step': (el, url) => saveStep(Number(el.dataset.step), url),
    'back': (el, url) => back(Number(el.dataset.step), url),
    'choose-mode': (el, url) => chooseMode(el.dataset.mode, url),
    'finish-without-contract': (el, url) => finishWithoutContract(Number(el.dataset.step), url),
    'finish-summary': (el, url) => loadFinishSummary(url, Number(el.dataset.step)),
    'confirm-finish': (el, url) => confirmFinish(url),
    'reset-summary': (el, url) => loadResetSummary(url),
    'confirm-reset': (el, url) => confirmReset(url),
    'load-template': (el, url) => loadContractTemplate(el.dataset.rand, url),
    'delete-document': (el, url) => deleteDocument(el.dataset.id, el, url),
    'add-contact': (el, url) => addBlock('contact', url),
    'add-document': (el, url) => addBlock('document', url),
    'add-intervention': (el, url) => addBlock('intervention', url),
    'remove-block': (el) => removeBlock(el.dataset.block, el.dataset.idx),
    'save-intervention': (el, url) => saveIntervention(el.dataset.idx, url),
    'add-criprice': (el, url) => addCriPrice(el.dataset.idx, el.dataset.rand, url),
    'delete-criprice': (el, url) => deleteCriPrice(el.dataset.id, el, url),
    'add-stakeholder': (el, url) => addStakeholder(el.dataset.idx, el.dataset.rand, url),
    'delete-stakeholder': (el, url) => deleteStakeholder(el.dataset.id, el, url),
};

document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-me-wizard-action]');
    if (el === null) {
        return;
    }
    const handler = ACTIONS[el.dataset.meWizardAction];
    const url = wizardConfig().url;
    if (handler === undefined || url === '') {
        return;
    }
    handler(el, url);
});

// "Movement management" switch of step 5: shows or hides the block named by data-me-wizard-toggle
document.addEventListener('change', (event) => {
    const el = event.target.closest('[data-me-wizard-toggle]');
    if (el === null) {
        return;
    }
    const target = document.getElementById(el.dataset.meWizardToggle);
    if (target !== null) {
        target.style.display = el.checked ? '' : 'none';
    }
});

// Field formatting, mirrored server side by the format*() helpers of WizardController
const FORMATTERS = {
    // Space separators only; a bare 10-digit number (or +33 and 9 digits) is split into pairs
    phone: (value) => {
        const phone = value.replace(/[\s./-]+/g, ' ').trim();
        const digits = phone.replaceAll(' ', '');
        if (/^\d{10}$/.test(digits)) {
            return digits.match(/\d{2}/g).join(' ');
        }
        const international = digits.match(/^\+33(\d)(\d{8})$/);
        if (international !== null) {
            return `+33 ${international[1]} ${international[2].match(/\d{2}/g).join(' ')}`;
        }
        return phone;
    },
    // Last name in capitals, first name with an initial capital, as in the resources plugin
    lastname: (value) => value.trim().toUpperCase(),
    firstname: (value) => {
        const firstname = value.trim();
        return firstname.charAt(0).toUpperCase() + firstname.slice(1).toLowerCase();
    },
    email: (value) => value.trim(),
    // A website without scheme gets https://
    website: (value) => {
        const website = value.trim();
        return website === '' || /^[a-z][a-z0-9+.-]*:\/\//i.test(website) ? website : `https://${website}`;
    },
};

document.addEventListener('change', (event) => {
    const el = event.target.closest('[data-me-wizard-format]');
    const formatter = FORMATTERS[el?.dataset.meWizardFormat];
    if (formatter !== undefined) {
        el.value = formatter(el.value);
    }
});

// Module scripts are deferred: the document may already be parsed
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBlockCounters);
} else {
    initBlockCounters();
}
