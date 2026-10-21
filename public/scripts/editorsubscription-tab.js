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


// Search box and badge filters of the publisher subscriptions tab of an entity
// (templates/entity/editorsubscription_tab.html.twig). The search box alone is reused by the
// contracts tab (templates/entity/contracts_tab.html.twig) and by the
// unbilled interventions table of the dashboard (templates/entity/unbilled_tab.html.twig). The tab is loaded asynchronously, hence the
// delegated listeners. A badge (data-me-sub-badge) keeps the rows whose data-me-sub-filters list
// its key; one badge at a time, its state in aria-pressed. The CSV export link follows the
// expired badges: its data-me-sub-export attribute carries both URLs as { all, expired }.

const EXPIRED_BADGES = ['expired', 'noproposal'];

function applyFilters(card) {
    const search_box = card.querySelector('[data-me-sub-search]');
    const search = search_box !== null ? search_box.value.trim().toLowerCase() : '';
    const badge = card.querySelector('[data-me-sub-badge][aria-pressed="true"]')?.dataset.meSubBadge ?? '';

    for (const row of card.querySelectorAll('tbody tr')) {
        const filters = (row.dataset.meSubFilters ?? '').split(' ');
        const matches_badge = badge === '' || filters.includes(badge);
        const matches_search = search === '' || row.textContent.toLowerCase().includes(search);
        row.hidden = !(matches_badge && matches_search);
    }
    const expired_only = EXPIRED_BADGES.includes(badge);

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
    const button = event.target.closest?.('[data-me-sub-badge]');
    const card = button?.closest('[data-me-sub-tab]');
    if (!card) {
        return;
    }
    // A second click on the active badge clears the filter
    const active = button.getAttribute('aria-pressed') !== 'true';
    for (const badge of card.querySelectorAll('[data-me-sub-badge]')) {
        const pressed = active && badge === button;
        badge.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        badge.classList.toggle('border-primary', pressed);
    }
    applyFilters(card);
});
