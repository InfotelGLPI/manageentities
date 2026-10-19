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

// Order of the tabs of the clients management, in the preferences of the plugin
// (templates/preference_form.html.twig): the list marked with data-me-tabs-order is sorted by
// drag and drop (html5sortable, loaded by the core on every page) and its order is written to
// the hidden input data-me-tabs-order-input. The preferences tab is loaded through AJAX, after
// this module: lists are set up as they appear. No-op on any other page.

const setUp = (list) => {
    if (list.dataset.meTabsOrderReady === '1' || typeof window.sortable !== 'function') {
        return;
    }
    list.dataset.meTabsOrderReady = '1';

    const input = list.parentElement.querySelector('[data-me-tabs-order-input]');
    window.sortable(list, {
        items: 'li',
        placeholderClass: 'list-group-item bg-light',
    });
    list.addEventListener('sortupdate', () => {
        input.value = [...list.querySelectorAll('li[data-tab]')].map((item) => item.dataset.tab).join(',');
    });
};

const setUpAll = (root) => {
    root.querySelectorAll?.('[data-me-tabs-order]').forEach(setUp);
};

setUpAll(document);
new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) {
                setUpAll(node);
            }
        });
    }
}).observe(document.body, {childList: true, subtree: true});
