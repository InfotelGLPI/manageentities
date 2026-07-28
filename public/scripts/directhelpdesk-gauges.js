/*
 -------------------------------------------------------------------------
 manageentities plugin for GLPI
 Copyright (C) 2017-2026 by the manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of manageentities.

 manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.

 manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// Data-driven ECharts gauges for the DirectHelpdesk dashboard.
// The card grid is rendered by templates/directhelpdesk_dashboard.html.twig, which
// exposes the per-entity gauge config in a data-me-gauge attribute and the (localized)
// hour/hours labels on the wrapper. Nothing here is user-controlled markup.
//
// This file is loaded via the ADD_JAVASCRIPT hook, which GLPI 11 emits at the END of
// <body> (see templates/layout/parts/page_footer.html.twig). In the helpdesk interface
// the dashboard markup can be injected AFTER this script runs (servicecatalog loads the
// page content asynchronously / deferred render), so the wrapper may not exist yet on the
// first pass. We therefore try immediately and, if the wrapper is not present, watch the
// DOM with a MutationObserver until it appears (or a safety timeout elapses).
//
// Returns true once the dashboard wrapper has been found and its gauges initialised.
function initManageentitiesGauges() {
    if (typeof echarts === 'undefined') {
        return false;
    }

    var wrapper = document.querySelector('[data-me-gauges]');
    if (!wrapper) {
        return false;
    }
    var hour    = wrapper.getAttribute('data-me-hour') || '';
    var hours   = wrapper.getAttribute('data-me-hours') || '';

    function format(data) {
        return parseFloat(data).toFixed(2);
    }

    document.querySelectorAll('[data-me-gauge]').forEach(function (dom) {
        // Skip containers already rendered (observer may fire more than once).
        if (echarts.getInstanceByDom(dom)) {
            return;
        }
        var cfg;
        try {
            cfg = JSON.parse(dom.getAttribute('data-me-gauge'));
        } catch (e) {
            return;
        }

        var myChart = echarts.init(dom, null, {
            renderer: 'canvas',
            useDirtyRect: false
        });

        var option = {
            tooltip: {
                valueFormatter: function (value) {
                    return format(((value * 14400) / 0.5) / 3600) + ' ' + hours;
                }
            },
            series: [
                {
                    name: cfg.name,
                    type: 'gauge',
                    startAngle: 180,
                    endAngle: 0,
                    center: ['50%', '75%'],
                    radius: '95%',
                    min: 0,
                    max: 0.5,
                    splitNumber: 8,
                    axisLine: {
                        lineStyle: {
                            width: 6,
                            color: [
                                [0.25, '#279539'],
                                [0.5, '#206bc4'],
                                [0.75, '#e6e75f'],
                                [1, '#f75454']
                            ]
                        }
                    },
                    pointer: {
                        icon: 'path://M12.8,0.7l12,40.1H0.7L12.8,0.7z',
                        length: '12%',
                        width: 20,
                        offsetCenter: [0, '-60%'],
                        itemStyle: {
                            color: 'auto'
                        }
                    },
                    axisTick: {
                        length: 12,
                        lineStyle: {
                            color: 'auto',
                            width: 2
                        }
                    },
                    splitLine: {
                        length: 20,
                        lineStyle: {
                            color: 'auto',
                            width: 5
                        }
                    },
                    axisLabel: {
                        color: '#464646',
                        fontSize: 13,
                        distance: -30,
                        rotate: 'tangential',
                        formatter: function () {
                            return '';
                        }
                    },
                    title: {
                        offsetCenter: [0, '25%'],
                        fontSize: 12
                    },
                    detail: {
                        fontSize: 16,
                        offsetCenter: [0, '-5%'],
                        valueAnimation: true,
                        formatter: function (value) {
                            if (value > 0.375 && value < 0.5) {
                                return '< 4 ' + hours;
                            } else if (value > 0.25 && value <= 0.375) {
                                return '>= 3 ' + hours;
                            } else if (value > 0.125 && value <= 0.25) {
                                return '>= 2 ' + hours;
                            } else if (value <= 0.125) {
                                return '>= 1 ' + hour;
                            } else if (value >= 0.5) {
                                return '>= 4 ' + hours;
                            }
                        },
                        color: 'inherit'
                    },
                    data: [{value: cfg.value}]
                }
            ]
        };

        myChart.setOption(option);
        window.addEventListener('resize', myChart.resize);
    });

    return true;
}

// Bootstrap: try immediately, then keep watching the DOM until the dashboard wrapper is
// injected (helpdesk interface renders the page content asynchronously). The observer
// disconnects as soon as the gauges are drawn, or after a safety timeout so it never
// lingers on pages that will never contain a dashboard.
function bootstrapManageentitiesGauges() {
    if (initManageentitiesGauges()) {
        return;
    }

    var observer = new MutationObserver(function () {
        if (initManageentitiesGauges()) {
            observer.disconnect();
        }
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });

    // Stop observing after 20s to avoid a permanent observer on dashboard-less pages.
    window.setTimeout(function () {
        observer.disconnect();
    }, 20000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrapManageentitiesGauges);
} else {
    bootstrapManageentitiesGauges();
}
