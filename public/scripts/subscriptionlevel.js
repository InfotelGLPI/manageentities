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

// Subscription level form (SubscriptionLevel): the monthly price per user only applies to the
// cloud levels. Its row, marked data-me-cloud-only, follows the subscription type; the server
// empties the price of the other levels on save. The dropdown is a select2 widget: its
// on_change relays a native "manageentities:change" event (CriDetail::CHANGE_EVENT_JS).

// SubscriptionLevel::TYPE_CLOUD
const TYPE_CLOUD = '2';

document.addEventListener('manageentities:change', (event) => {
    const select = event.target;
    if (select.name !== 'subscription_type' || select.form === null) {
        return;
    }
    for (const row of select.form.querySelectorAll('[data-me-cloud-only]')) {
        row.classList.toggle('d-none', select.value !== TYPE_CLOUD);
    }
});
