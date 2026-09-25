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

// Web root of the plugin, mirror of PLUGIN_MANAGEENTITIES_WEBDIR (setup.php). GLPI
// exposes both variables in the <head> (config_js) before any plugin script:
// no server-side interpolation is needed here.
var root_manageentities_doc = ((window.CFG_GLPI && CFG_GLPI.root_doc) || '')
   + ((window.GLPI_PLUGINS_PATH && GLPI_PLUGINS_PATH.manageentities) || '/plugins/manageentities');

$(window).on("load", function() {
    const newDiv = document.createElement('div');
    newDiv.classList.add('center');
    const newButton = document.createElement('button');

    newButton.id = 'launch-directhelpdesk-modal';
    newButton.classList.add('btn', 'btn-sm', 'btn-primary', 'me-1');

    function updateButtonState() {
        const collapsed = $('body').hasClass('navbar-collapsed');
        if (collapsed) {
            newButton.style.marginLeft = '0px';
            newButton.textContent = __('A');
        } else {
            newButton.style.marginLeft = '70px';
            newButton.textContent = __('Add');
        }
    }

    // Initial state
    updateButtonState();
    // Translations are loaded through AJAX (locales_js); when they land after
    // this point, the label is applied again once the requests are done.
    $(document).one('ajaxStop', updateButtonState);

    newDiv.appendChild(newButton);

    // Insert before the existing button
    const existingButton = document.querySelector('.trigger-fuzzy');
    if (typeof existingButton !== "undefined" && existingButton !== null) {
        existingButton.parentNode.insertBefore(newDiv, existingButton);
    }
    // Prepare the modal
    const page = document.querySelector("div.page");
    const modalContainer = document.createElement('div');
    modalContainer.id = 'directhelpdeskmodalcontainer';
    if (typeof page !== "undefined" && page !== null) {
        page.append(modalContainer);
    }
    // Button click
    newButton.addEventListener('click', function() {
        if (!document.getElementById('directhelpdesk-modal')) {
            $('#directhelpdeskmodalcontainer').load(
                root_manageentities_doc + '/ajax/directhelpdesk.php',
                function() {
                    $("#directhelpdesk-modal").modal('show');
                }
            );
        } else {
            $("#directhelpdesk-modal").modal('show');
        }
    });

    // Close the modal on an outside click
    $(document).on('click', function(event) {
        const modal = document.getElementById('directhelpdesk-modal');
        if (modal && event.target === modal) {
            $(modal).modal('hide');
        }
    });

    // React to the navbar-collapsed class toggle on <body>
    new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'class') {
                updateButtonState();
            }
        });
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
});

