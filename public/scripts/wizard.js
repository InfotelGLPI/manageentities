/* global WIZARD_URL, getAjaxCsrfToken */
/* eslint-disable no-unused-vars */

/**
 * Wizard navigation and dynamic block management for the AddElements wizard.
 */

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

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
        showStepErrors(step, res.errors || res.message || 'Error');
        return;
    }
    // Server may skip steps (e.g. existing_entity mode jumps step 1 → step 3)
    var nextStep = (res.step && res.step > step) ? res.step : (step < 5 ? step + 1 : step);
    reloadStep(nextStep, url);
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

    wizardFetch(url, { action: 'save_interventions' })
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
                confirmBtn.dataset.redirectUrl = res.redirect_url || '';
                confirmBtn.disabled = false;
            }
            var items = res.summary || [];
            if (summaryEl) {
                if (items.length === 0) {
                    summaryEl.innerHTML = '<p class="text-muted fst-italic">Nothing to display.</p>';
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
    var redirectUrl = confirmBtn ? confirmBtn.dataset.redirectUrl : '';
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
    }
    // Clear wizard session server-side before redirecting
    wizardFetch(url, { action: 'reset' })
        .then(function () {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            } else {
                reloadStep(1, url);
            }
        })
        .catch(function () {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            } else {
                reloadStep(1, url);
            }
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
            if (!res.success || !res.items || res.items.length === 0) {
                summaryEl.innerHTML = '<p class="text-muted fst-italic">'
                    + (res.items && res.items.length === 0
                        ? 'Nothing has been created yet.'
                        : 'Unable to load summary.')
                    + '</p>';
                return;
            }
            var html = '<ul class="list-group list-group-flush">';
            res.items.forEach(function (item) {
                html += '<li class="list-group-item d-flex align-items-center gap-2 px-0 py-1">'
                    + '<span class="badge bg-outline-secondary" style="min-width:120px">' + _escHtml(item.type) + '</span>'
                    + '<span>' + _escHtml(item.label) + '</span>'
                    + '</li>';
            });
            html += '</ul>';
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
            // Update data-id on block
            block.dataset.id = res.contractday_id;

            // Replace unsaved badge with saved badge
            var badge = block.querySelector('#unsaved-badge-' + idx);
            if (badge) {
                badge.outerHTML = '<span class="badge bg-outline-success ms-2"><i class="ti ti-check"></i></span>';
            }

            // Inject criprices + stakeholders sections
            var sectionsEl = document.getElementById('intervention-sections-' + idx);
            if (sectionsEl) {
                sectionsEl.innerHTML = '<hr class="my-3">' + res.criprices_html + '<hr class="my-3">' + res.stakeholders_html;
            }
        })
        .catch(function () {
            if (errEl) errEl.textContent = 'Network error';
        });
}

// -------------------------------------------------------------------------
// CriPrice inline add
// -------------------------------------------------------------------------

function wizardAddCriPrice(contractdayId, rand, url) {
    var critypeEl = document.getElementById('dropdown_new_critype_' + contractdayId + rand);
    var priceEl   = document.getElementById('new_price_' + contractdayId);
    var defEl     = document.getElementById('new_is_default_' + contractdayId);

    if (!critypeEl || !priceEl) return;
    var priceVal = parseFloat(priceEl.value);
    if (!priceVal || priceVal <= 0) { priceEl.focus(); priceEl.classList.add('is-invalid'); return; }
    priceEl.classList.remove('is-invalid');

    wizardFetch(url, {
        action:                                 'save_criprice',
        plugin_manageentities_contractdays_id:  contractdayId,
        plugin_manageentities_critypes_id:      critypeEl.value,
        price:                                  priceEl.value,
        is_default:                             defEl && defEl.checked ? '1' : '0',
        criprice_id:                            '0',
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) { alert(res.message || 'Error'); return; }
            var listEl = document.getElementById('criprices-list-' + contractdayId);
            if (listEl) {
                var noEl = document.getElementById('no-criprices-' + contractdayId);
                if (noEl) noEl.remove();
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-2 criprice-row';
                row.dataset.id = res.criprice_id;
                row.innerHTML = '<span class="badge bg-outline-secondary">' + (critypeEl.options[critypeEl.selectedIndex] ? critypeEl.options[critypeEl.selectedIndex].text : '') + '</span>'
                    + '<strong>' + parseFloat(priceEl.value).toFixed(2) + '</strong>'
                    + (defEl && defEl.checked ? '<span class="badge bg-outline-primary">' + (defEl.dataset.labelDefault || 'Default') + '</span>' : '')
                    + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCriPrice(' + res.criprice_id + ', this, \'' + url + '\')">'
                    + '<i class="ti ti-trash"></i></button>';
                listEl.appendChild(row);
                priceEl.value = '';
                if (defEl) defEl.checked = false;
                // Hide the add form — only one rate allowed per intervention
                var formEl = document.getElementById('criprices-form-' + contractdayId);
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

function _updateRemainingDays(contractdayId, remaining, credit) {
    var hasLimit = credit !== undefined && credit !== null && parseFloat(credit) > 0;
    var labelEl = document.getElementById('remaining-days-' + contractdayId);
    if (labelEl && hasLimit && remaining !== null) {
        labelEl.textContent = parseFloat(remaining).toFixed(2);
    }
    var daysEl = document.getElementById('new_nb_days_' + contractdayId);
    if (daysEl && hasLimit && remaining !== null) daysEl.max = remaining;
    // Use visibility class — Bootstrap d-flex uses !important which overrides inline style
    if (hasLimit && remaining !== null) {
        var formEl = document.getElementById('stakeholders-form-' + contractdayId);
        if (formEl) _toggleFlexForm(formEl, parseFloat(remaining) > 0);
    }
}

function wizardAddStakeholder(contractdayId, rand, url) {
    var userEl  = document.getElementById('dropdown_new_user_' + contractdayId + rand);
    var daysEl  = document.getElementById('new_nb_days_' + contractdayId);

    if (!userEl || !userEl.value) return;
    var nbDays = daysEl ? parseFloat(daysEl.value) : 0;
    if (!nbDays || nbDays <= 0) { alert('Please enter a number of days greater than 0'); return; }

    wizardFetch(url, {
        action:               'add_stakeholder',
        contractday_id:       contractdayId,
        users_id:             userEl.value,
        number_affected_days: nbDays,
    })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                alert(res.message || 'Error');
                if (res.remaining_days !== undefined) {
                    _updateRemainingDays(contractdayId, res.remaining_days, res.credit);
                }
                return;
            }
            var listEl = document.getElementById('stakeholders-list-' + contractdayId);
            if (listEl) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-1 stakeholder-row';
                row.dataset.id = res.stakeholder_id;
                row.innerHTML = '<span class="badge bg-outline-secondary">' + res.user_name + '</span>'
                    + '<span class="text-muted small">' + parseFloat(res.number_affected_days).toFixed(2) + ' day(s)</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" onclick="wizardDeleteStakeholder(' + res.stakeholder_id + ', this, \'' + url + '\')">'
                    + '<i class="ti ti-trash"></i></button>';
                listEl.appendChild(row);
                if (daysEl) daysEl.value = '';
            }
            _updateRemainingDays(contractdayId, res.remaining_days, res.credit);
        });
}

function wizardDeleteStakeholder(id, btn, url) {
    var row = btn.closest('.stakeholder-row');
    var section = btn.closest('.wizard-stakeholders-section');
    var contractdayId = section ? section.id.replace('stakeholders-section-', '') : null;

    wizardFetch(url, { action: 'delete_stakeholder', stakeholder_id: id })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                if (row) row.remove();
                if (contractdayId) _updateRemainingDays(contractdayId, res.remaining_days, res.credit);
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
            document.getElementById('documents-container').insertAdjacentHTML('beforeend', html);
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
            document.getElementById('contacts-container').insertAdjacentHTML('beforeend', html);
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
            document.getElementById('interventions-container').insertAdjacentHTML('beforeend', html);
        });
}

function removeInterventionBlock(idx) {
    var block = document.querySelector('.intervention-block[data-idx="' + idx + '"]');
    if (block) block.remove();
}

// -------------------------------------------------------------------------
// CriPrice
// -------------------------------------------------------------------------

function addCriPriceBlock(contractdayId, interventionIdx, url) {
    wizardFetch(url, { action: 'add_criprice_block', contractday_id: contractdayId })
        .then(function (r) { return r.text(); })
        .then(function (html) {
            var container = document.getElementById('criprices-container-' + interventionIdx);
            if (container) container.insertAdjacentHTML('beforeend', html);
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
                if (res.contractday_id) {
                    var formEl = document.getElementById('criprices-form-' + res.contractday_id);
                    if (formEl) {
                        _toggleFlexForm(formEl, !res.has_rate);
                        if (!res.has_rate) {
                            var defEl = document.getElementById('new_is_default_' + res.contractday_id);
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
