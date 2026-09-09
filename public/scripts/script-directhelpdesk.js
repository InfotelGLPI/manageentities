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

// Racine web du plugin, miroir de PLUGIN_MANAGEENTITIES_WEBDIR (setup.php). GLPI
// expose les deux variables dans le <head> (config_js) avant tout script de
// plugin : aucune interpolation cote serveur n'est necessaire ici.
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

    // état initial
    updateButtonState();
    // Les traductions arrivent en AJAX (locales_js) ; si elles atterrissent
    // apres ce point, on reapplique le libelle des la fin des requetes.
    $(document).one('ajaxStop', updateButtonState);

    newDiv.appendChild(newButton);

    // Insérer avant le bouton existant
    const existingButton = document.querySelector('.trigger-fuzzy');
    if (typeof existingButton !== "undefined" && existingButton !== null) {
        existingButton.parentNode.insertBefore(newDiv, existingButton);
    }
    // Préparer la modal
    const page = document.querySelector("div.page");
    const modalContainer = document.createElement('div');
    modalContainer.id = 'directhelpdeskmodalcontainer';
    if (typeof page !== "undefined" && page !== null) {
        page.append(modalContainer);
    }
    // clic sur le bouton
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

    // Fermer la modal si clic en dehors
    $(document).on('click', function(event) {
        const modal = document.getElementById('directhelpdesk-modal');
        if (modal && event.target === modal) {
            $(modal).modal('hide');
        }
    });

    // Réagir au toggle effectif de la classe navbar-collapsed sur <body>
    new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.attributeName === 'class') {
                updateButtonState();
            }
        });
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
});

