/* global WIZARD_URL, WIZARD_I18N, getAjaxCsrfToken */
/* eslint-disable no-unused-vars */

/**
 * Wizard navigation and dynamic block management for the AddElements wizard.
 */

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function _execScripts(container) {
    container.querySelectorAll('script').forEach(function (oldScript) {
        var newScript = document.createElement('script');
        Array.from(oldScript.attributes).forEach(function (attr) {
            newScript.setAttribute(attr.name, attr.value);
        });
        newScript.textContent = oldScript.textContent;
        oldScript.parentNode.replaceChild(newScript, oldScript);
    });
}

function wizardFetch(url, body) {
    var headers = {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
    };
    var init;
    if (body instanceof FormData) {
        init = { method: 'POST', headers: headers, body: body };
    } else {
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        init = { method: 'POST', headers: headers, body: new URLSearchParams(body).toString() };
    }
    return fetch(url, init);
}

function collectSection(sectionEl) {
    var data = {};
    if (!sectionEl) return data;
    var elements = sectionEl.querySelectorAll('[name]');
    // Process in order: hidden inputs first so checkboxes override them
    elements.forEach(function (el) {
        if (el.disabled) return;
        var name = el.name;
        if (el.type === 'checkbox') {
            // Always set: a preceding hidden input with same name provides the '0' fallback
            if (el.checked) {
                data[name] = el.value;
            }
            // If unchecked: the hidden input before it already wrote '0'
        } else if (el.type === 'file') {
            // handled separately by wizardSaveStep for step 3
        } else {
            data[name] = el.value;
        }
    });
    return data;
}

function showStepErrors(step, errors) {
    var el = document.getElementById('step' + step + '-errors');
    if (!el) return;
    var msgs = [];
    if (typeof errors === 'object') {
        Object.values(errors).forEach(function (msg) { msgs.push(msg); });
    } else if (errors) {
        msgs.push(String(errors));
    }
    el.innerHTML = msgs.join('<br>');
    el.classList.remove('d-none');
}

function clearStepErrors(step) {
    var el = document.getElementById('step' + step + '-errors');
    if (el) { el.innerHTML = ''; el.classList.add('d-none'); }
}

function reloadStep(step, url) {
    window.location.href = url.replace(/wizard\.php.*/, '') + '../front/addelements.form.php?step=' + step;
}

// -------------------------------------------------------------------------
// Navigation
// -------------------------------------------------------------------------

function wizardSaveStep(step, url) {
    clearStepErrors(step);
    var card = document.querySelector('#wizard-step-content .card');

    var actionMap = {
        1: 'save_entity',
        2: 'save_contacts',
        3: 'save_contract',
        4: 'save_management_type',
        5: 'save_interventions',
    };

    var action = actionMap[step];
    if (!action) return;

    // Step 3 — contract fields (text) then documents (files) as a second POST
    if (step === 3) {
        var fdContract = new FormData();
        var fdDocs     = new FormData();
        fdContract.append('action', action);
        fdDocs.append('action', 'upload_documents');
        var hasFiles = false;
        if (card) {
            card.querySelectorAll('[name]').forEach(function (el) {
                if (el.disabled) return;
                if (el.type === 'file') {
                    if (el.files && el.files[0]) {
                        fdDocs.append(el.name, el.files[0]);
                        // Also carry the matching category field
                        hasFiles = true;
                    }
                } else if (el.name.startsWith('documents[')) {
                    // Category dropdowns for documents go to fdDocs
                    fdDocs.append(el.name, el.value);
                } else if (el.type === 'checkbox') {
                    fdContract.append(el.name, el.checked ? el.value : '0');
                } else {
                    fdContract.append(el.name, el.value);
                }
            });
        }
        wizardFetch(url, fdContract)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { handleStepResponse(step, res, url); return null; }
                // Upload documents only if any file was selected
                if (!hasFiles) return res;
                return wizardFetch(url, fdDocs).then(function (r) { return r.json(); });
            })
            .then(function (res) {
                if (res) handleStepResponse(step, res, url);
            })
            .catch(function () { showStepErrors(step, 'Network error'); });
        return;
    }

    var payload = { action: action };
    if (card) Object.assign(payload, collectSection(card));

    wizardFetch(url, payload)
        .then(function (r) { return r.json(); })
        .then(function (res) { handleStepResponse(step, res, url); })
        .catch(function () { showStepErrors(step, 'Network error'); });
}

function handleStepResponse(step, res, url) {
    if (!res.success) {
        if (res.entity_exists && step === 1) {
            wizardPromptUseExistingEntity(res.entities_id, res.entity_name, url);
            return;
        }
        if (res.entity_archived && step === 1) {
            wizardPromptUnarchiveEntity(res.entities_id, res.entity_name, url);
            return;
        }
        showStepErrors(step, res.errors || res.message || 'Error');
        return;
    }
    // Server may skip steps (e.g. existing_entity mode jumps step 1 → step 3)
    var nextStep = (res.step && res.step > step) ? res.step : (step < 5 ? step + 1 : step);
    reloadStep(nextStep, url);
}

function wizardPromptUseExistingEntity(entitiesId, entityName, url) {
    var modal = document.getElementById('wizard-entity-exists-modal');
    var msgEl = document.getElementById('wizard-entity-exists-msg');
    var btn   = document.getElementById('wizard-entity-exists-confirm-btn');
    if (!modal || !msgEl || !btn) return;

    msgEl.textContent = WIZARD_I18N.entityExistsMsg.replace('%s', entityName);

    var newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener('click', function () {
        bootstrap.Modal.getInstance(modal).hide();
        wizardFetch(url, { action: 'choose_mode', wizard_mode: 'existing_entity' })
            .then(function (r) { return r.json(); })
            .then(function () {
                return wizardFetch(url, { action: 'save_entity', entities_id: entitiesId });
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    reloadStep(res.step || 3, url);
                } else {
                    showStepErrors(1, res.errors || res.message || 'Error');
                }
            })
            .catch(function () { showStepErrors(1, 'Network error'); });
    });

    new bootstrap.Modal(modal).show();
}

function wizardPromptUnarchiveEntity(entitiesId, entityName, url) {
    var modal  = document.getElementById('wizard-entity-archived-modal');
    var msgEl  = document.getElementById('wizard-entity-archived-msg');
    var btn    = document.getElementById('wizard-entity-archived-confirm-btn');
    if (!modal || !msgEl || !btn) return;

    msgEl.textContent = WIZARD_I18N.entityArchivedMsg.replace('%s', entityName);

    var newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener('click', function () {
        bootstrap.Modal.getInstance(modal).hide();
        wizardFetch(url, { action: 'unarchive_entity', entities_id: entitiesId })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    reloadStep(res.step || 3, url);
                } else {
                    showStepErrors(1, res.errors || res.message || 'Error');
                }
            })
            .catch(function () { showStepErrors(1, 'Network error'); });
    });

    new bootstrap.Modal(modal).show();
}

function wizardBack(step, url) {
    if (step <= 1) return;
    reloadStep(step - 1, url);
}

function wizardChooseMode(mode, url) {
    wizardFetch(url, { action: 'choose_mode', wizard_mode: mode })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.success) reloadStep(1, url); });
}

function wizardReset(url) {
    wizardFetch(url, { action: 'reset' })
        .then(function (r) { return r.json(); })
        .then(function () { reloadStep(1, url); })
        .catch(function () { reloadStep(1, url); });
}

function wizardLoadFinishSummary(url) {
    var saveBtn = document.getElementById('btn-save-finish');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    }

    wizardFetch(url, { action: 'finish_wizard' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="ti ti-check me-1"></i>' + saveBtn.dataset.label;
            }
            if (!res.success) {
                showStepErrors(5, res.errors || res.message || 'Error');
                return;
            }

            // Populate summary
            var summaryEl = document.getElementById('wizard-finish-summary');
            var confirmBtn = document.getElementById('wizard-finish-confirm-btn');
            if (confirmBtn) {
                confirmBtn.disabled = false;
            }
            var items = res.summary || [];
            if (summaryEl) {
                if (items.length === 0) {
                    summaryEl.innerHTML = '<p class="text-muted fst-italic">' + _escHtml(WIZARD_I18N.nothingToDisplay) + '</p>';
                } else {
                    var html = '<ul class="list-group list-group-flush">';
                    items.forEach(function (item) {
                        html += '<li class="list-group-item d-flex align-items-start gap-2 px-0 py-1">'
                            + '<span class="badge bg-outline-secondary flex-shrink-0" style="min-width:130px">' + _escHtml(item.type) + '</span>'
                            + '<span>' + _escHtml(item.label) + '</span>'
                            + '</li>';
                    });
                    html += '</ul>';
                    summaryEl.innerHTML = html;
                }
            }

            // Open modal only after successful validation
            var modalEl = document.getElementById('wizard-finish-modal');
            if (modalEl) {
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            }
        })
        .catch(function () {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="ti ti-check me-1"></i>' + (saveBtn.dataset.label || 'Save and finish');
            }
            showStepErrors(5, 'Network error');
        });
}

function wizardConfirmFinish(url) {
    var confirmBtn = document.getElementById('wizard-finish-confirm-btn');
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    }
    // Commit: write everything to DB then redirect
    wizardFetch(url, { action: 'commit_wizard' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="ti ti-check me-1"></i>' + (confirmBtn.dataset.label || 'Confirm');
                }
                showStepErrors(5, res.errors || res.message || 'Error');
                return;
            }
            var redirectUrl = res.redirect_url || '';
            if (redirectUrl) {
                window.location.href = redirectUrl;
            } else {
                reloadStep(1, url);
            }
        })
        .catch(function () {
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="ti ti-check me-1"></i>' + (confirmBtn.dataset.label || 'Confirm');
            }
            showStepErrors(5, 'Network error');
        });
}

function wizardLoadResetSummary(url) {
    var summaryEl = document.getElementById('wizard-reset-summary');
    if (summaryEl) {
        summaryEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
    }
    wizardFetch(url, { action: 'get_reset_summary' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!summaryEl) return;
            if (!res.success) {
                summaryEl.innerHTML = '<p class="text-muted fst-italic">Unable to load summary.</p>';
                return;
            }
            var html;
            if (!res.items || res.items.length === 0) {
                html = '<p class="text-muted fst-italic">No unsaved data in the current session.</p>';
            } else {
                html = '<ul class="list-group list-group-flush">';
                res.items.forEach(function (item) {
                    html += '<li class="list-group-item d-flex align-items-center gap-2 px-0 py-1">'
                        + '<span class="badge bg-outline-secondary" style="min-width:120px">' + _escHtml(item.type) + '</span>'
                        + '<span>' + _escHtml(item.label) + '</span>'
                        + '</li>';
                });
                html += '</ul>';
            }
            summaryEl.innerHTML = html;
        })
        .catch(function () {
            if (summaryEl) summaryEl.innerHTML = '<p class="text-danger">Network error</p>';
        });
}

function wizardConfirmReset(url) {
    var btn = document.getElementById('wizard-reset-confirm-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.labelDeleting || 'Deleting…'); }
    wizardFetch(url, { action: 'reset_and_delete' })
        .then(function (r) { return r.json(); })
        .then(function () { reloadStep(1, url); })
        .catch(function () { reloadStep(1, url); });
}

function _escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// -------------------------------------------------------------------------
// Per-block intervention save
// -------------------------------------------------------------------------

function wizardSaveIntervention(idx, url) {
    var errEl = document.getElementById('intervention-errors-' + idx);
    if (errEl) errEl.textContent = '';

    var block = document.querySelector('.intervention-block[data-idx="' + idx + '"]');
    if (!block) return;

    var intervention = {};
    block.querySelectorAll('[name^="interventions[' + idx + ']"]').forEach(function (el) {
        if (el.disabled) return;
        var match = el.name.match(/\[([^\]]+)\]$/);
        if (!match) return;
        var key = match[1];
        if (el.type === 'checkbox') {
            intervention[key] = el.checked ? el.value : '0';
        } else {
            intervention[key] = el.value;
        }
    });

    var fd = new FormData();
    fd.append('action', 'save_intervention');
    fd.append('idx', idx);
    Object.keys(intervention).forEach(function (k) {
        fd.append('intervention[' + k + ']', intervention[k]);
    });

    wizardFetch(url, fd)
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                var msgs = res.errors ? Object.values(res.errors).join(', ') : (res.message || 'Error');
                if (errEl) errEl.textContent = msgs;
                return;
            }
            // Update data-id on block (intervention_idx is the virtual key, no real DB id yet)
            block.dataset.id = res.intervention_idx;

            // Replace unsaved badge with saved badge
            var badge = block.querySelector('#unsaved-badge-' + idx);
            if (badge) {
                badge.outerHTML = '<span class="badge bg-outline-success ms-2"><i class="ti ti-check"></i></span>';
            }

            // Inject criprices + stakeholders sections
            var sectionsEl = document.getElementById('intervention-sections-' + idx);
            if (sectionsEl) {
                sectionsEl.innerHTML = '<hr class="my-3">' + res.criprices_html + '<hr class="my-3">' + res.stakeholders_html;
                _execScripts(sectionsEl);
            }
        })
        .catch(function () {
            if (errEl) errEl.textContent = 'Network error';
        });
}

// -------------------------------------------------------------------------
// CriPrice inline add
// -------------------------------------------------------------------------

function wizardAddCriPrice(interventionIdx, rand, url) {
    var critypeEl = document.getElementById('dropdown_new_critype_' + interventionIdx + rand);
    var priceEl   = document.getElementById('new_price_' + interventionIdx);
    var defEl     = document.getElementById('new_is_default_' + interventionIdx);

    if (!critypeEl || !priceEl) return;
    var priceVal = parseFloat(priceEl.value);
    if (!priceVal || priceVal <= 0) { priceEl.focus(); priceEl.classList.add('is-invalid'); return; }
    priceEl.classList.remove('is-invalid');

    wizardFetch(url, {
        action:           'save_criprice',
        intervention_idx: interventionIdx,
        plugin_manageentities_critypes_id: critypeEl.value,
        price:            priceEl.value,
        is_default:       defEl && defEl.checked ? '1' : '0',
        criprice_id:      '0',
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { alert(res.message || 'Error'); return; }
            var listEl = document.getElementById('criprices-list-' + interventionIdx);
            if (listEl) {
                var noEl = document.getElementById('no-criprices-' + interventionIdx);
                if (noEl) noEl.remove();
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-2 criprice-row';
                row.dataset.id = res.criprice_id;
                row.innerHTML = '<span class="badge bg-outline-secondary">' + (critypeEl.options[critypeEl.selectedIndex] ? critypeEl.options[critypeEl.selectedIndex].text : '') + '</span>'
                    + '<strong>' + parseFloat(priceEl.value).toFixed(2) + '</strong>'
                    + (defEl && defEl.checked ? '<span class="badge bg-outline-primary">' + (defEl.dataset.labelDefault || 'Default') + '</span>' : '')
                    + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCriPrice(\'' + res.criprice_id + '\', this, \'' + url + '\')">'
                    + '<i class="ti ti-trash"></i></button>';
                listEl.appendChild(row);
                priceEl.value = '';
                if (defEl) defEl.checked = false;
                // Hide the add form — only one rate allowed per intervention
                var formEl = document.getElementById('criprices-form-' + interventionIdx);
                if (formEl) _toggleFlexForm(formEl, false);
            }
        });
}

// -------------------------------------------------------------------------
// Stakeholder add / delete
// -------------------------------------------------------------------------

// Toggle a flex form: show=true adds d-flex and removes display:none; show=false does the opposite.
// Needed because Bootstrap's .d-flex { display:flex !important } overrides a plain style="display:none".
function _toggleFlexForm(el, show) {
    if (show) {
        el.classList.add('d-flex');
        el.style.removeProperty('display');
    } else {
        el.classList.remove('d-flex');
        el.style.setProperty('display', 'none', 'important');
    }
}

function _updateRemainingDays(interventionIdx, remaining, credit) {
    var hasLimit = credit !== undefined && credit !== null && parseFloat(credit) > 0;
    var labelEl = document.getElementById('remaining-days-' + interventionIdx);
    if (labelEl && hasLimit && remaining !== null) {
        labelEl.textContent = parseFloat(remaining).toFixed(2);
    }
    var daysEl = document.getElementById('new_nb_days_' + interventionIdx);
    if (daysEl && hasLimit && remaining !== null) daysEl.max = remaining;
    // Use visibility class — Bootstrap d-flex uses !important which overrides inline style
    if (hasLimit && remaining !== null) {
        var formEl = document.getElementById('stakeholders-form-' + interventionIdx);
        if (formEl) _toggleFlexForm(formEl, parseFloat(remaining) > 0);
    }
}

function wizardAddStakeholder(interventionIdx, rand, url) {
    var userEl  = document.getElementById('dropdown_new_user_' + interventionIdx + rand);
    var daysEl  = document.getElementById('new_nb_days_' + interventionIdx);

    if (!userEl || !userEl.value) return;
    var nbDays = daysEl ? parseFloat(daysEl.value) : 0;
    if (!nbDays || nbDays <= 0) { alert('Please enter a number of days greater than 0'); return; }

    wizardFetch(url, {
        action:               'add_stakeholder',
        intervention_idx:     interventionIdx,
        users_id:             userEl.value,
        number_affected_days: nbDays,
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                alert(res.message || 'Error');
                if (res.remaining_days !== undefined) {
                    _updateRemainingDays(interventionIdx, res.remaining_days, res.credit);
                }
                return;
            }
            var listEl = document.getElementById('stakeholders-list-' + interventionIdx);
            if (listEl) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-1 stakeholder-row';
                row.dataset.id = res.stakeholder_id;
                row.innerHTML = '<span class="badge bg-outline-secondary">' + res.user_name + '</span>'
                    + '<span class="text-muted small">' + parseFloat(res.number_affected_days).toFixed(2) + ' day(s)</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="wizardDeleteStakeholder(\'' + res.stakeholder_id + '\', this, \'' + url + '\')">'
                    + '<i class="ti ti-trash"></i></button>';
                listEl.appendChild(row);
                if (daysEl) daysEl.value = '';
            }
            _updateRemainingDays(interventionIdx, res.remaining_days, res.credit);
        });
}

function wizardDeleteStakeholder(id, btn, url) {
    var row = btn.closest('.stakeholder-row');
    var section = btn.closest('.wizard-stakeholders-section');
    var interventionIdx = section ? section.id.replace('stakeholders-section-', '') : null;

    wizardFetch(url, { action: 'delete_stakeholder', stakeholder_id: id })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                if (row) row.remove();
                if (interventionIdx) _updateRemainingDays(interventionIdx, res.remaining_days, res.credit);
            }
        });
}

// -------------------------------------------------------------------------
// Contract template pre-fill
// -------------------------------------------------------------------------

function wizardLoadContractTemplate(randTpl, url) {
    var selectEl = document.getElementById('dropdown__contract_template_id' + randTpl);
    if (!selectEl || !selectEl.value) return;

    wizardFetch(url, { action: 'load_contract_template', contracts_id: selectEl.value })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success && res.redirect) {
                reloadStep(3, url);
            }
        });
}

// -------------------------------------------------------------------------
// Dynamic document blocks (step 3)
// -------------------------------------------------------------------------

var _documentIdx = 0;

function initDocumentIdx() {
    var blocks = document.querySelectorAll('.document-block');
    _documentIdx = blocks.length;
}

function addDocumentBlock(url) {
    _documentIdx++;
    wizardFetch(url, { action: 'add_document_block', idx: _documentIdx })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var c = document.getElementById('documents-container');
            if (!c) return;
            c.insertAdjacentHTML('beforeend', html);
            _execScripts(c.lastElementChild);
        });
}

function removeDocumentBlock(idx) {
    var block = document.querySelector('.document-block[data-idx="' + idx + '"]');
    if (block) block.remove();
}

// -------------------------------------------------------------------------
// Dynamic contact blocks
// -------------------------------------------------------------------------

var _contactIdx = 0;

function initContactIdx() {
    var blocks = document.querySelectorAll('.contact-block');
    _contactIdx = blocks.length;
}

function addContactBlock(url) {
    _contactIdx++;
    wizardFetch(url, { action: 'add_contact_block', idx: _contactIdx })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var c = document.getElementById('contacts-container');
            if (!c) return;
            c.insertAdjacentHTML('beforeend', html);
            _execScripts(c.lastElementChild);
        });
}

function removeContactBlock(idx) {
    var block = document.querySelector('.contact-block[data-idx="' + idx + '"]');
    if (block) block.remove();
}

// -------------------------------------------------------------------------
// Dynamic intervention blocks
// -------------------------------------------------------------------------

var _interventionIdx = 0;

function initInterventionIdx() {
    var blocks = document.querySelectorAll('.intervention-block');
    _interventionIdx = blocks.length;
}

function addInterventionBlock(url) {
    _interventionIdx++;
    wizardFetch(url, { action: 'add_intervention_block', idx: _interventionIdx })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var c = document.getElementById('interventions-container');
            if (!c) return;
            c.insertAdjacentHTML('beforeend', html);
            _execScripts(c.lastElementChild);
        });
}

function removeInterventionBlock(idx) {
    var block = document.querySelector('.intervention-block[data-idx="' + idx + '"]');
    if (block) block.remove();
}

// -------------------------------------------------------------------------
// CriPrice
// -------------------------------------------------------------------------

function addCriPriceBlock(interventionIdx, url) {
    // Legacy — CriPrices are now added inline via wizardAddCriPrice(); this function is unused.
    wizardFetch(url, { action: 'add_criprice_block', intervention_idx: interventionIdx })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var container = document.getElementById('criprices-container-' + interventionIdx);
            if (!container) return;
            container.insertAdjacentHTML('beforeend', html);
            _execScripts(container.lastElementChild);
        });
}

function saveCriPrice(btn, url) {
    var block = btn.closest('.criprice-block');
    var data = { action: 'save_criprice' };
    block.querySelectorAll('[name]').forEach(function (el) {
        if (el.type === 'checkbox') {
            data[el.name] = el.checked ? el.value : '0';
        } else {
            data[el.name] = el.value;
        }
    });
    wizardFetch(url, data)
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                block.dataset.id = res.criprice_id;
                block.querySelector('[name="criprice_id"]').value = res.criprice_id;
                btn.classList.replace('btn-outline-primary', 'btn-outline-success');
                setTimeout(function () { btn.classList.replace('btn-outline-success', 'btn-outline-primary'); }, 1500);
            }
        });
}

function wizardDeleteDocument(id, btn, url) {
    var row = btn.closest('.document-block');
    wizardFetch(url, { action: 'delete_document', document_id: id })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success && row) row.remove();
        });
}

function deleteCriPrice(id, btn, url) {
    wizardFetch(url, { action: 'delete_criprice', criprice_id: id })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                var block = btn.closest('.criprice-block, .criprice-row');
                if (block) block.remove();
                // Show/hide add form based on remaining rates
                if (res.intervention_idx !== undefined) {
                    var formEl = document.getElementById('criprices-form-' + res.intervention_idx);
                    if (formEl) {
                        _toggleFlexForm(formEl, !res.has_rate);
                        if (!res.has_rate) {
                            var defEl = document.getElementById('new_is_default_' + res.intervention_idx);
                            if (defEl) defEl.checked = true;
                        }
                    }
                }
            }
        });
}

// -------------------------------------------------------------------------
// Init on page load
// -------------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', function () {
    initDocumentIdx();
    initContactIdx();
    initInterventionIdx();
});
