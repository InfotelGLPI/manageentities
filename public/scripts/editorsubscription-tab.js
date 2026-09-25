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


// Search box and "Expired only" filter of the publisher subscriptions tab of an entity
// (templates/entity/editorsubscription_tab.html.twig). The tab is loaded asynchronously, hence the
// delegated listeners; the filter state lives in the aria-pressed attribute of its button. The CSV
// export link follows the filter: its data-me-sub-export attribute carries both URLs as { all, expired }.

function applyFilters(card) {
    const search_box = card.querySelector('[data-me-sub-search]');
    const expired_button = card.querySelector('[data-me-sub-expired]');
    const search = search_box !== null ? search_box.value.trim().toLowerCase() : '';
    const expired_only = expired_button?.getAttribute('aria-pressed') === 'true';

    for (const row of card.querySelectorAll('tbody tr')) {
        const matches_expired = !expired_only || row.dataset.expired === '1';
        const matches_search = search === '' || row.textContent.toLowerCase().includes(search);
        row.hidden = !(matches_expired && matches_search);
    }

    const export_link = card.querySelector('[data-me-sub-export]');
    if (export_link !== null) {
        try {
            const urls = JSON.parse(export_link.dataset.meSubExport) ?? {};
            const url = expired_only ? urls.expired : urls.all;
            if (url) {
                export_link.href = url;
            }
        } catch {
            // Keep the rendered href
        }
    }
}

document.addEventListener('input', (event) => {
    const search_box = event.target.closest?.('[data-me-sub-search]');
    const card = search_box?.closest('[data-me-sub-tab]');
    if (card) {
        applyFilters(card);
    }
});

document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-me-sub-expired]');
    const card = button?.closest('[data-me-sub-tab]');
    if (!card) {
        return;
    }
    const expired_only = button.getAttribute('aria-pressed') !== 'true';
    button.setAttribute('aria-pressed', expired_only ? 'true' : 'false');
    button.classList.toggle('btn-outline-warning', !expired_only);
    button.classList.toggle('btn-warning', expired_only);
    applyFilters(card);
});
