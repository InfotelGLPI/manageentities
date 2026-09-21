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

/**
 * GANTT tab of an entity, drawn with the FullCalendar bundle shipped by GLPI core.
 *
 * Gantt::showGantt() echoes this file inside the response of ajax/common.tabs.php, which
 * jQuery inserts with .html(): the container is already in the DOM and fullcalendar.js
 * has already been evaluated by the time this runs. That path evaluates a script through
 * a global eval, so an ES module would lose both its scope and its exports here; the
 * closure below is what keeps the helpers off the global object.
 */
(function () {
    'use strict';

    /**
     * Colour of a contract day bar, from the share of its credit already consumed.
     *
     * Reproduces the cascade of the sprites the removed library painted the bars with:
     * untouched days stayed white, then green up to half the credit, orange up to three
     * quarters, red beyond, and grey once the day is exactly used up.
     *
     * @param {number} percent
     * @returns {string}
     */
    function manageentitiesGanttBarColor(percent) {
        if (percent === 0) {
            return '#ffffff';
        }
        if (percent > 100) {
            return '#f75454';
        }
        if (percent === 100) {
            return '#adb5bd';
        }
        if (percent > 75) {
            return '#f75454';
        }
        if (percent > 50) {
            return '#f0a30a';
        }

        return '#279539';
    }

    /**
     * Locale of the core FullCalendar bundle, when Gantt::showGantt() found one to load.
     *
     * Every locale file assigns itself to window.FullCalendarLocales, and the PHP side
     * only ever emits the one of the current language.
     *
     * @returns {object|undefined}
     */
    function manageentitiesGanttLocale() {
        var locales = window.FullCalendarLocales;
        if (!locales) {
            return undefined;
        }

        var codes = Object.keys(locales);

        return codes.length > 0 ? locales[codes[0]] : undefined;
    }

    /**
     * Paint one bar and give it its tooltip.
     *
     * The tooltip is plain text set through setAttribute, so it is not an HTML sink: the
     * whole point of moving off the removed library, whose renderer built the rows by
     * concatenating unescaped strings.
     *
     * @param {object} info
     */
    function manageentitiesGanttRenderEvent(info) {
        var tooltip = info.event.extendedProps.tooltip;
        if (tooltip) {
            info.el.setAttribute('title', tooltip);
        }

        if (info.event.extendedProps.percent === undefined) {
            info.el.style.backgroundColor = '#206bc4';
            info.el.style.borderColor = '#1b5aa5';
            info.el.style.color = '#ffffff';
            return;
        }

        var percent = info.event.extendedProps.percent;
        var color = manageentitiesGanttBarColor(percent);
        var fill = Math.max(0, Math.min(percent, 100));

        // Replaces the background sprite the removed library stretched over the bar: the
        // filled share is the consumed credit, the rest is the untouched one.
        info.el.style.background = 'linear-gradient(to right, ' + color + ' 0%, ' + color
            + ' ' + fill + '%, #f4f4f4 ' + fill + '%, #f4f4f4 100%)';
        info.el.style.borderColor = '#7d7d7d';
        info.el.style.color = '#000000';
    }

    /**
     * Zoom levels of the monthly scale, from the tightest to the widest.
     *
     * FullCalendar reads the duration and the slot width off the view, not off the
     * calendar, so a zoom step is a view change: every level below is registered as its
     * own resourceTimeline view.
     */
    var MANAGEENTITIES_GANTT_ZOOM = [
        {months: 3, slot_width: 140},
        {months: 6, slot_width: 90},
        {months: 12, slot_width: 50},
        {months: 24, slot_width: 32},
        {months: 48, slot_width: 20}
    ];

    /** Level the tab opens at: two years, wide enough to read a contract history. */
    var MANAGEENTITIES_GANTT_ZOOM_DEFAULT = 3;

    /**
     * The views of the zoom levels, keyed by the name their button changes to.
     *
     * @returns {object}
     */
    function manageentitiesGanttViews() {
        var views = {};

        for (var i = 0; i < MANAGEENTITIES_GANTT_ZOOM.length; i++) {
            views['manageentitiesZoom' + i] = {
                type: 'resourceTimeline',
                duration: {months: MANAGEENTITIES_GANTT_ZOOM[i].months},
                dateAlignment: 'month',
                slotDuration: {months: 1},
                slotWidth: MANAGEENTITIES_GANTT_ZOOM[i].slot_width,
                // Numeric months: the translated names widen every column for nothing
                // once a full year has to fit next to the contract list.
                slotLabelFormat: [{year: 'numeric'}, {month: '2-digit'}]
            };
        }

        return views;
    }

    /**
     * First day of the month the given day falls in, moved by a number of months.
     *
     * @param {string} date ISO day, as the payload carries it
     * @param {number} delta Months to add, negative to go back
     * @returns {string}
     */
    function manageentitiesGanttMonthShift(date, delta) {
        var year = parseInt(date.substring(0, 4), 10);
        var month = parseInt(date.substring(5, 7), 10) - 1 + delta;

        year += Math.floor(month / 12);
        month = ((month % 12) + 12) % 12 + 1;

        return year + '-' + (month < 10 ? '0' : '') + month + '-01';
    }

    /**
     * Build the chart of one container.
     *
     * @param {HTMLElement} element
     */
    function manageentitiesGanttInit(element) {
        var config = JSON.parse(element.getAttribute('data-me-gantt'));
        var zoom = MANAGEENTITIES_GANTT_ZOOM_DEFAULT;
        var calendar;

        /**
         * Move the zoom by one level, stopping on the ends of the scale.
         *
         * @param {number} step
         */
        function changeZoom(step) {
            var target = Math.min(
                Math.max(zoom + step, 0),
                MANAGEENTITIES_GANTT_ZOOM.length - 1
            );

            if (target === zoom) {
                return;
            }

            zoom = target;
            calendar.changeView('manageentitiesZoom' + zoom);
        }

        calendar = new FullCalendar.Calendar(element, {
            // interaction carries the dragging implementation the timeline looks for in
            // initResourceAreaWidthDragging(): without it the divider between the contract
            // column and the timeline is drawn, and styled as a col-resize handle by the
            // core stylesheet, but nothing listens to it. Nothing else of the plugin comes
            // into play here, events staying read-only as long as editable is off.
            plugins: ['interaction', 'resourceTimeline'],
            schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
            locale: manageentitiesGanttLocale(),
            // A bounded height is what gives the chart its own scrollers: told to grow to
            // its content it lays them out as visible overflow, so a timeline wider than
            // the tab has no scrollbar to come back to the left with.
            height: 620,
            defaultView: 'manageentitiesZoom' + zoom,
            // Opens on a window holding today about half way in, so the months already
            // consumed are read next to the ones still to come instead of sitting off
            // screen; today keeps the core indicator and prev and next walk the rest.
            defaultDate: manageentitiesGanttMonthShift(
                config.today,
                -Math.floor(MANAGEENTITIES_GANTT_ZOOM[zoom].months / 2)
            ),
            nowIndicator: true,
            header: {
                left: 'prev,next today',
                center: 'title',
                // The view switcher stays out: a single scale is offered, and the two
                // buttons below move along it rather than between views.
                right: 'manageentitiesZoomOut,manageentitiesZoomIn'
            },
            customButtons: {
                manageentitiesZoomIn: {
                    text: '+',
                    click: function () {
                        changeZoom(-1);
                    }
                },
                manageentitiesZoomOut: {
                    text: '-',
                    click: function () {
                        changeZoom(1);
                    }
                }
            },
            resourceAreaWidth: 260,
            resourceLabelText: config.resource_label,
            resourcesInitiallyExpanded: true,
            resources: config.resources,
            events: config.events,
            views: manageentitiesGanttViews(),
            eventRender: manageentitiesGanttRenderEvent
        });

        calendar.render();

        // The chart is drawn while jQuery is still inserting the response of the tab, so
        // the first layout can be measured against a width the container does not have
        // yet: the columns are then sized for nothing and overflow.
        window.requestAnimationFrame(function () {
            calendar.updateSize();
        });
    }

    if (typeof FullCalendar === 'undefined') {
        return;
    }

    var containers = document.querySelectorAll('[data-me-gantt]');
    for (var i = 0; i < containers.length; i++) {
        // The tab can be reloaded in place, and the browser keeps the evaluated script:
        // without this mark a second pass would stack a chart over the previous one.
        if (containers[i].getAttribute('data-me-gantt-ready') === '1') {
            continue;
        }
        containers[i].setAttribute('data-me-gantt-ready', '1');
        manageentitiesGanttInit(containers[i]);
    }
})();
