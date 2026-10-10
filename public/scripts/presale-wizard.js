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

// Pre-sales wizard (templates/presale/*). The steps are plain forms posted to
// front/presale.form.php: this module reloads step 1 on the choice of a known client, adds and
// removes its contact blocks,
// copies the details of the chosen users at step 2, and handles the annexes of step 3
// (lines, order and numbering, removal, totals while typing). The field formatting
// (data-me-wizard-format) is the one of public/scripts/wizard.js. No-op outside #presale-wizard.

// Index of a block in the field names: a number, or the placeholder of the <template>
const CONTACT_INDEX = /contacts\[(?:\d+|__IDX__)\]/;

// Renumbers the contact blocks (names, radio values, ids, titles) after an addition or a removal,
// so that the posted indexes stay contiguous and the main contact radio keeps pointing at its block
function renumberContacts(container) {
    container.querySelectorAll('[data-presale-contact]').forEach((block, idx) => {
        block.querySelectorAll('[name]').forEach((el) => {
            el.name = el.name.replace(CONTACT_INDEX, `contacts[${idx}]`);
        });
        const radio = block.querySelector('input[name="is_default"]');
        if (radio !== null) {
            radio.value = String(idx);
            radio.id = `presale_default_${idx}`;
            block.querySelector('label[for^="presale_default_"]')?.setAttribute('for', radio.id);
        }
        const number = block.querySelector('[data-presale-contact-number]');
        if (number !== null) {
            number.textContent = String(idx + 1);
        }
    });
}

function addContact(wizard) {
    const container = wizard.querySelector('[data-presale-contacts]');
    const template = wizard.querySelector('template[data-presale-contact-template]');
    if (container === null || template === null) {
        return;
    }
    const fragment = template.content.cloneNode(true);
    const block = fragment.querySelector('[data-presale-contact]');
    container.append(fragment);
    renumberContacts(container);
    // A single block is necessarily the main contact
    if (container.querySelectorAll('[data-presale-contact]').length === 1) {
        block.querySelector('input[name="is_default"]').checked = true;
    }
    block.querySelector('input[type="text"]')?.focus();
}

function removeContact(button) {
    const container = button.closest('[data-presale-contacts]');
    const block = button.closest('[data-presale-contact]');
    if (container === null || block === null) {
        return;
    }
    const was_default = block.querySelector('input[name="is_default"]')?.checked;
    block.remove();
    renumberContacts(container);
    // The main contact was removed: the first remaining one takes over
    if (was_default) {
        const first = container.querySelector('input[name="is_default"]');
        if (first !== null) {
            first.checked = true;
        }
    }
}

// Step 2: copy the details of the chosen user into the editable fields of its block. The select is
// handled by select2, which only triggers jQuery events: listen to both.
function fillPerson(select) {
    const card = select.closest('[data-presale-person]');
    if (card === null) {
        return;
    }
    let prefill = {};
    try {
        prefill = JSON.parse(card.dataset.presalePerson) ?? {};
    } catch {
        prefill = {};
    }
    const person = prefill[select.value];
    if (person === undefined) {
        return;
    }
    card.querySelectorAll('[data-presale-person-field]').forEach((input) => {
        input.value = person[input.dataset.presalePersonField] ?? '';
    });
}

// -------------------------------------------------------------------------
// Step 3: annexes
// -------------------------------------------------------------------------

// Same parsing as PresalePricing::parseNumber(): spaces are thousands separators, comma decimal
function parseNumber(text) {
    const value = String(text ?? '').replace(/[\s  ]/g, '').replace(',', '.');
    return value === '' || Number.isNaN(Number(value)) ? null : Number(value);
}

const MONEY = new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: 'EUR' });

function round2(value) {
    return Math.round(value * 100) / 100;
}

// Total of a priced line, shown in its cell: null while its quantity or price is missing
function refreshLine(line, mode) {
    const quantity = parseNumber(line.querySelector('[data-presale-amount="quantity"]')?.value);
    const price = parseNumber(line.querySelector('[data-presale-amount="unit_price"]')?.value);
    // Cloud lines run over a number of months, one year when empty (PresalePricing::months())
    const months = mode === 'cloud'
        ? (parseNumber(line.querySelector('[data-presale-amount="months"]')?.value) ?? 12)
        : 1;
    const cell = line.querySelector('[data-presale-line-total]');
    if (quantity === null || price === null) {
        if (cell !== null) {
            cell.textContent = '';
        }
        return null;
    }
    // Quantity × unit price (× months), as PresalePricing::lineAmounts()
    const line_ht = round2(quantity * price * months);
    if (cell !== null) {
        cell.textContent = MONEY.format(line_ht);
    }
    return line_ht;
}

// Default lines of an annex model: the total of each line
function refreshDefaultLines(container) {
    container.querySelectorAll('[data-presale-line]').forEach((line) => refreshLine(line, container.dataset.presaleMode));
}

// Totals shown while typing; the server recomputes them on save (PresalePricing)
function refreshTotals(wizard) {
    const sums = { ht: 0, vat: 0, ttc: 0 };
    wizard.querySelectorAll('[data-presale-annex]').forEach((annex) => {
        const rate = parseNumber(annex.querySelector('[data-presale-amount="vat_rate"]')?.value) ?? 0;
        let ht = 0;
        let vat = 0;
        annex.querySelectorAll('[data-presale-line]').forEach((line) => {
            const line_ht = refreshLine(line, annex.dataset.presaleMode);
            if (line_ht !== null) {
                ht += line_ht;
                vat += round2(line_ht * rate / 100);
            }
        });
        ht = round2(ht);
        vat = round2(vat);
        const total = annex.querySelector('[data-presale-annex-total]');
        if (total !== null) {
            total.textContent = ht > 0 ? `${MONEY.format(ht)} HT` : '';
        }
        sums.ht += ht;
        sums.vat += vat;
        sums.ttc += ht + vat;
        checkDays(annex);
    });
    Object.entries(sums).forEach(([key, value]) => {
        const el = wizard.querySelector(`[data-presale-sum="${key}"]`);
        if (el !== null) {
            el.textContent = MONEY.format(round2(value));
        }
    });
}

// Line names carry their index: renumber after a removal so that they stay contiguous
// Container of a line table: an annex of the wizard, or the default lines of an annex model
const LINES_CONTAINER = '[data-presale-annex], [data-presale-default-lines]';

function renumberLines(annex) {
    annex.querySelectorAll('[data-presale-line]').forEach((line, idx) => {
        line.querySelectorAll('[name]').forEach((el) => {
            el.name = el.name.replace(/(\[lines\]|^default_lines)\[(?:\d+|__LINE__)\]/, `$1[${idx}]`);
        });
    });
}

function addLine(button) {
    const annex = button.closest(LINES_CONTAINER);
    const template = annex.querySelector('template[data-presale-line-template]');
    annex.querySelector('[data-presale-lines]').append(template.content.cloneNode(true));
    renumberLines(annex);
    annex.querySelector('[data-presale-line]:last-child input')?.focus();
}

function removeLine(button) {
    const annex = button.closest(LINES_CONTAINER);
    button.closest('[data-presale-line]').remove();
    renumberLines(annex);
}

// The position of a block is the number of its annex in the document ("Annexe N"): the names
// carry it (annexes[N]), the server numbers the titles in the posted order. The editors are
// left in place: their textareas are renamed, TinyMCE writes its content back to them on submit.
function renumberAnnexes(container) {
    const format = container.dataset.presaleTitleFormat ?? '%d';
    container.querySelectorAll('[data-presale-annex]').forEach((annex, idx) => {
        annex.id = `presale-annex-${idx}`;
        annex.querySelectorAll('[name^="annexes["]').forEach((el) => {
            el.name = el.name.replace(/^annexes\[\d+\]/, `annexes[${idx}]`);
        });
        annex.querySelectorAll('[data-presale-estimate]').forEach((estimate) => {
            estimate.dataset.prefix = estimate.dataset.prefix.replace(/^annexes\[\d+\]/, `annexes[${idx}]`);
            estimate.querySelectorAll('template').forEach((template) => {
                template.content.querySelectorAll('[name^="annexes["]').forEach((el) => {
                    el.name = el.name.replace(/^annexes\[\d+\]/, `annexes[${idx}]`);
                });
            });
        });
        // The line template carries the index too
        annex.querySelectorAll('template[data-presale-line-template]').forEach((template) => {
            template.content.querySelectorAll('[name^="annexes["]').forEach((el) => {
                el.name = el.name.replace(/^annexes\[\d+\]/, `annexes[${idx}]`);
            });
        });
        const prefix = format.replace('%d', String(idx + 1)).replace('%s', '').trim();
        const number = annex.querySelector('[data-presale-annex-number]');
        if (number !== null) {
            number.textContent = prefix.replace(/\s*:$/, '');
        }
        const title_prefix = annex.querySelector('[data-presale-annex-prefix]');
        if (title_prefix !== null) {
            title_prefix.textContent = prefix;
        }
    });
}

// A TinyMCE editor lives in an iframe, emptied when the block is moved in the page: its content
// is written back to the textarea and the editor removed before the move, then started again
// with the configuration GLPI keeps for it (tinymce_editor_configs)
function moveAnnex(button) {
    const annex = button.closest('[data-presale-annex]');
    const sibling = button.dataset.direction === 'up' ? annex.previousElementSibling : annex.nextElementSibling;
    if (sibling === null) {
        return;
    }
    const editors = [annex, sibling].flatMap((block) => [...block.querySelectorAll('textarea[id]')])
        .filter((textarea) => window.tinymce?.get(textarea.id));
    editors.forEach((textarea) => {
        const editor = window.tinymce.get(textarea.id);
        editor.save();
        editor.remove();
    });
    if (button.dataset.direction === 'up') {
        sibling.before(annex);
    } else {
        sibling.after(annex);
    }
    editors.forEach((textarea) => {
        const config = window.tinymce_editor_configs?.[textarea.id];
        if (config !== undefined) {
            window.tinymce.init(config);
        }
    });
    renumberAnnexes(annex.parentElement);
    annex.scrollIntoView({ block: 'nearest' });
}

function removeAnnex(button) {
    const annex = button.closest('[data-presale-annex]');
    const container = annex.parentElement;
    // Its editors go with it
    annex.querySelectorAll('textarea[id]').forEach((textarea) => {
        window.tinymce?.get(textarea.id)?.remove();
    });
    annex.remove();
    renumberAnnexes(container);
}

// -------------------------------------------------------------------------
// Estimate of a regular annex (templates/presale/estimate_table.html.twig): annex models and
// annexes of the wizard
// -------------------------------------------------------------------------

// Row names carry their index: renumbered after an addition, a removal or a move
function renumberEstimate(estimate) {
    const prefix = estimate.dataset.prefix;
    estimate.querySelectorAll('[data-presale-estimate-row]').forEach((row, idx) => {
        row.querySelectorAll('[name]').forEach((el) => {
            const field = el.name.slice(el.name.lastIndexOf('['));
            el.name = `${prefix}[${idx}]${field}`;
        });
    });
}

function estimateTotal(estimate) {
    let total = 0;
    estimate.querySelectorAll('[data-presale-estimate-days]').forEach((input) => {
        total += parseNumber(input.value) ?? 0;
    });
    return round2(total);
}

function refreshEstimateTotal(estimate) {
    const cell = estimate.querySelector('[data-presale-estimate-total]');
    if (cell !== null) {
        cell.textContent = String(estimateTotal(estimate)).replace('.', ',');
    }
    const annex = estimate.closest('[data-presale-annex]');
    if (annex !== null) {
        checkDays(annex);
    }
}

// A regular annex with an estimate: the days of its priced lines must total the same (the
// server refuses the step otherwise)
function checkDays(annex) {
    const warning = annex.querySelector('[data-presale-days-mismatch]');
    const estimate = annex.querySelector('[data-presale-estimate]');
    if (warning === null || estimate === null) {
        return;
    }
    const has_rows = estimate.querySelector('[data-presale-estimate-days]') !== null;
    let days = 0;
    annex.querySelectorAll('[data-presale-line] [data-presale-amount="quantity"]').forEach((input) => {
        days += parseNumber(input.value) ?? 0;
    });
    days = round2(days);
    const total = estimateTotal(estimate);
    const mismatch = has_rows && days !== total;
    warning.hidden = !mismatch;
    if (mismatch) {
        warning.textContent = warning.dataset.message
            .replace('%1$s', String(days).replace('.', ','))
            .replace('%2$s', String(total).replace('.', ','));
    }
}

function addEstimateRow(button) {
    const estimate = button.closest('[data-presale-estimate]');
    const template = estimate.querySelector(`template[data-presale-estimate-template="${button.dataset.type}"]`);
    estimate.querySelector('[data-presale-estimate-rows]').append(template.content.cloneNode(true));
    renumberEstimate(estimate);
    estimate.querySelector('[data-presale-estimate-row]:last-child input[type="text"]')?.focus();
}

function removeEstimateRow(button) {
    const estimate = button.closest('[data-presale-estimate]');
    button.closest('[data-presale-estimate-row]').remove();
    renumberEstimate(estimate);
    refreshEstimateTotal(estimate);
}

function moveEstimateRow(button) {
    const estimate = button.closest('[data-presale-estimate]');
    const row = button.closest('[data-presale-estimate-row]');
    if (button.dataset.direction === 'up') {
        row.previousElementSibling?.before(row);
    } else {
        row.nextElementSibling?.after(row);
    }
    renumberEstimate(estimate);
}

const ACTIONS = {
    'add-estimate-row': (el) => addEstimateRow(el),
    'remove-estimate-row': (el) => removeEstimateRow(el),
    'move-estimate-row': (el) => moveEstimateRow(el),
    'add-contact': (el, wizard) => addContact(wizard),
    'remove-contact': (el) => removeContact(el),
    'add-line': (el) => addLine(el),
    'remove-line': (el, wizard) => {
        removeLine(el);
        refreshTotals(wizard);
    },
    'move-annex': (el) => moveAnnex(el),
    // Step 4: put back the type of contract or the label given by the annexes
    'use-deduced': (el) => {
        const input = document.getElementById(el.dataset.target);
        if (input !== null) {
            input.value = el.dataset.value;
            el.closest('.form-text')?.remove();
        }
    },
    'remove-annex': (el, wizard) => {
        removeAnnex(el);
        refreshTotals(wizard);
    },
};

document.addEventListener('click', (event) => {
    const el = event.target.closest('[data-presale-action]');
    // The wizard, or the default lines of the annex model form
    const wizard = el?.closest('#presale-wizard, [data-presale-default-lines], [data-presale-estimate]');
    if (!el || !wizard) {
        return;
    }
    const handler = ACTIONS[el.dataset.presaleAction];
    if (handler !== undefined) {
        event.preventDefault();
        handler(el, wizard);
    }
});

// Buttons that need a confirmation (archive a proposal as lost, remove an annex). Capture
// phase: a refusal stops the click before the actions above.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-presale-confirm]');
    if (button !== null && !window.confirm(button.dataset.presaleConfirm)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

function onUserChange(event) {
    const select = event.target;
    if (select instanceof HTMLSelectElement && select.name.endsWith('[users_id]') && select.closest('#presale-wizard')) {
        fillPerson(select);
    }
}

document.addEventListener('change', onUserChange);

document.addEventListener('change', (event) => {
    const wizard = event.target.closest?.('#presale-wizard');
    if (!wizard) {
        return;
    }
    if (event.target.matches('[data-presale-amount]')) {
        refreshTotals(wizard);
    }
});

document.addEventListener('input', (event) => {
    const wizard = event.target.closest?.('#presale-wizard');
    if (wizard && event.target.matches('[data-presale-amount]')) {
        refreshTotals(wizard);
    }
    const estimate = event.target.closest?.('[data-presale-estimate]');
    if (estimate && event.target.matches('[data-presale-estimate-days]')) {
        refreshEstimateTotal(estimate);
    }
    const defaults = event.target.closest?.('[data-presale-default-lines]');
    if (defaults && event.target.matches('[data-presale-amount]')) {
        refreshDefaultLines(defaults);
    }
});

function initAnnexes() {
    const wizard = document.getElementById('presale-wizard');
    if (wizard === null || wizard.querySelector('[data-presale-annexes]') === null) {
        return;
    }
    refreshTotals(wizard);
}

// Step 1 of a new proposal: choosing a known prospect or a customer reloads the step with its
// details and contacts, which stay editable. Choosing one empties the other list.
function onSourceChange(select) {
    const source = select.closest('[data-presale-source]');
    if (source === null) {
        return;
    }
    const params = new URLSearchParams();
    if (select.value !== '' && select.value !== '0') {
        params.set(select.name === 'prospects_id' ? 'prospects_id' : 'entities_id', select.value);
    }
    const query = params.toString();
    window.location.href = source.dataset.presaleSource + (query !== '' ? `&${query}` : '');
}

// Step 3: summary of the annex model chosen in the "Add an annex" list
function showModelSummary(select) {
    document.querySelectorAll('#presale-wizard [data-presale-model-summary]').forEach((summary) => {
        summary.hidden = summary.dataset.presaleModelSummary !== select.value;
    });
}

// Annex model form: the default estimate is for the regular annexes only. The yes / no select
// is a select2 widget: its on_change relays "manageentities:change" (CriDetail::CHANGE_EVENT_JS).
document.addEventListener('manageentities:change', (event) => {
    const select = event.target;
    if (!(select instanceof HTMLSelectElement) || select.name !== 'is_subscription' || select.form === null) {
        return;
    }
    select.form.querySelectorAll('[data-presale-regular-only]').forEach((row) => {
        row.classList.toggle('d-none', select.value === '1');
    });
});

function onProspectChange(event) {
    const select = event.target;
    if (select instanceof HTMLSelectElement && ['prospects_id', 'customer_entities_id'].includes(select.name)) {
        onSourceChange(select);
    } else if (select instanceof HTMLSelectElement && select.name === 'add_annex_models_id') {
        showModelSummary(select);
    }
}
document.addEventListener('change', onProspectChange);

function init() {
    initAnnexes();
    refreshForms();
}

// Estimates and default lines of the annex model form, also when its tab is loaded afterwards
function refreshForms() {
    document.querySelectorAll('[data-presale-estimate]').forEach(refreshEstimateTotal);
    document.querySelectorAll('[data-presale-default-lines]').forEach(refreshDefaultLines);
}

// Module scripts are deferred: the document may already be parsed
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
// select2 only triggers jQuery events
if (window.jQuery) {
    window.jQuery('main').on('glpi.tab.loaded', refreshForms);
    window.jQuery(document).on('change', '#presale-wizard select[name$="[users_id]"]', onUserChange);
    window.jQuery(document).on('change', '#presale-wizard select[name="prospects_id"], #presale-wizard select[name="customer_entities_id"], #presale-wizard select[name="add_annex_models_id"]', onProspectChange);
}
