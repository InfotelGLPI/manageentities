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

function switchElementsEnableFromCbko(currentCb, idToHide) {
    if (currentCb.checked == true) {
        document.getElementById(idToHide).disabled = true;
    } else {
        document.getElementById(idToHide).disabled = false;
    }
}


function switchElementsEnableFromCb(currentCb, idToHide) {
    if (currentCb.checked == true) {
        document.getElementById(idToHide).style.visibility = "hidden";
    } else {
        document.getElementById(idToHide).style.visibility = "visible";
    }
}


function getUrlParam(url, name) {
    var results = new RegExp('[?&]?' + name + '=([^&]+)(&|$)').exec(url);
    if (results != null) {
        return results[1] || 0;
    }

    return undefined;
}

function cloneTicketTask(options) {
    $.ajax({
        url: options.root_doc + '/ajax/tickettask.php',
        type: "POST",
        dataType: "json",
        data: {
            'tickets_id': options.tickets_id,
            'new_date_value': $('input[name=new_date]').val(),
            'tickettasks_id': options.tickettasks_id,
            'action': 'cloneTicketTask'
        },
        success: function (json, opts) {
            if (json.tickettasks_id != undefined) {
                window.location.reload();
            }
        }
    });
}

function manageentities_loadCriForm(action, modal, params) {
    var formInput;

    // TinyMCE keeps the rich-text content inside its own iframe and only writes it back to the
    // underlying <textarea> on save. Force that sync (same idiom as GLPI core RendererController)
    // so form.serialize() below picks up the user's live edits (e.g. the "Detail of the realized
    // works" field) instead of the prefilled value.
    var manageentitiesTinyMce = window.tinymce || window.tinyMCE;
    if (manageentitiesTinyMce !== undefined) {
        manageentitiesTinyMce.get().forEach(function (editor) {
            editor.save();
        });
    }

    if (params.form != undefined) {
        formInput = getManageentitiesFormData($('form[name="' + params.form + '"]'));
    }

    $.ajax({
        url: params.root_doc + '/ajax/cri.php',
        type: "POST",
        dataType: "html",
        data: {
            'action': action,
            'params': params,
            'pdf_action': params.pdf_action,
            'formInput': formInput,
            'modal': modal
        },
        success: function (response, opts) {
            try {
                var json = $.parseJSON(response);
                if (!json.success) {
                    $("#manageentities_cri_error").html(json.message).show().delay(2000).fadeOut('slow');
                }

            } catch (err) {
                $('#' + modal).html(response);

                switch (action) {

                    case 'saveCri':
                        window.location.reload();
                        break;
                    default:
                        glpi_html_dialog({
                            title: __('Interventions reports', 'manageentities'),
                            body: response,
                            id: action,
                        })
                        break;
                }
            }
        }
    });
}

function getManageentitiesFormData(form) {
    var unindexed_array = form.serialize();
    var indexed_array = {};

    $.map(unindexed_array.split('&'), function (n, i) {
        indexed_array[n.split('=')[0]] = n.split('=')[1];
    });

    return JSON.stringify(indexed_array);
}

/**
 * Period picker of the monthly follow-up.
 *
 * The bar itself is rendered by templates/monthly_period_nav.html.twig; the only thing left
 * here is turning a click on one of its buttons into a search over that period. The listener
 * is delegated from the document because the script is emitted at the end of the body, after
 * the markup, and the bar is also reachable from content loaded later.
 */
document.addEventListener('click', function (event) {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest('[data-manageentities-period-nav] button[data-year]');
    if (button === null) {
        return;
    }

    event.preventDefault();

    const nav = button.closest('[data-manageentities-period-nav]');
    const form = document.forms[nav.dataset.form];
    if (form === undefined) {
        return;
    }

    const year = parseInt(button.dataset.year, 10);
    const month = parseInt(button.dataset.month, 10);

    // Month is 1 based in the markup and 0 based in Date: the first day of the month asked
    // for, and day 0 of the next one, which is the last day of that month.
    setFormDate(form, 'begin_date', new Date(year, month - 1, 1));
    setFormDate(form, 'end_date', new Date(year, month, 0));

    const current = form.elements.year_current;
    if (current !== undefined) {
        current.value = year;
    }

    form.requestSubmit();
});

/**
 * Write a date into one of the flatpickr fields of the criteria form, in the ISO shape the
 * controller reads back.
 *
 * @param {HTMLFormElement} form
 * @param {string}          name
 * @param {Date}            date
 */
function setFormDate(form, name, date) {
    const field = form.elements[name];
    if (field === undefined) {
        return;
    }

    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    field.value = date.getFullYear() + '-' + month + '-' + day;
}
