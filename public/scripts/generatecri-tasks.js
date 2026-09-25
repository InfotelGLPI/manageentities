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

// Task list of the CRI generation wizard (templates/generatecri_wizard.html.twig).
// The configuration (stored tasks, labels, AJAX URL) is read from the data-me-config
// attribute of #tasks. Task blocks are built with DOM nodes: the description and the
// technician name are never parsed as HTML in the page.
//
// removeBlockTask() and addTaskOnView() stay global: they are called from onclick attributes.

function generateCriConfig() {
    const container = document.getElementById('tasks');
    if (container === null || !container.dataset.meConfig) {
        return null;
    }
    try {
        return JSON.parse(container.dataset.meConfig);
    } catch (e) {
        return null;
    }
}

function removeBlockTask(taskcount) {
    $('#task_' + taskcount).remove();
    const taskCountDone = $('#tasks').children('div').last().attr('data-index');

    if (taskCountDone === undefined) {
        $('[name="has_task"]').val('false');
    }
}

function addTaskOnView(isOnRefresh, storedTasks = {}) {
    const config = generateCriConfig();
    if (config === null) {
        return;
    }
    const labels = config.labels;

    if (Object.keys(storedTasks).length) {
        $('#tab-tasks').show();
        $.each(storedTasks, function (taskcount, value) {
            let taskCount = taskcount;
            // First element
            if (taskCount === undefined) {
                taskCount = 0;
            }
            $('#tasks').append(getBlockTask(
                taskCount,
                value['description'],
                value['users_id_tech'],
                value['begin'],
                value['end'],
                value['duration'],
                secondsToHm(value['duration']),
                value['taskcategories_id'],
            ));
            getUserName(value['users_id_tech'], taskCount);
        });
    } else if (!isOnRefresh) {
        $('#tab-tasks').show();
        const editor        = tinyMCE.get($('textarea[name="description"]')[0].id);
        const description   = editor.getContent();
        const duration      = $('[name="plan[_duration]"]').val();
        const begin         = $('[name="plan[begin]"]').val();
        const end           = $('[name="plan[end]"]').val();
        const userIdTech    = $('[name="users_id_tech"]').val();
        const tasksCategory = $('[name="taskcategories_id"]').val();

        if (description == '' || begin == '' || userIdTech == 0 || end === undefined && duration == 0) {
            alert(labels.mandatory);
        } else if (tasksCategory == 0 && config.category_required) {
            alert(labels.category);
        } else if (end <= begin) {
            alert(labels.end_after_begin);
        } else {
            let taskCount = $('#tasks').children('div').last().attr('data-index');
            // First element
            if (taskCount === undefined) {
                taskCount = 0;
            }
            taskCount++;

            $('#tasks').append(getBlockTask(
                taskCount,
                description,
                userIdTech,
                begin,
                end,
                duration,
                secondsToHm(duration),
                tasksCategory,
            ));
            getUserName(userIdTech, taskCount);
            $('[name="has_task"]').val('true');

            editor.setContent('');
        }
    }
}

function getBlockTask(taskCount, description, userIdTech, begin, end, duration, durationDisplay, tasksCategory) {
    const labels = generateCriConfig().labels;

    const block = document.createElement('div');
    block.id = 'task_' + taskCount;
    block.dataset.index = taskCount;
    block.style.cssText = 'margin: 10px; padding: 10px; border: dashed;';

    const remove = document.createElement('a');
    remove.style.cursor = 'pointer';
    remove.addEventListener('click', function () {
        removeBlockTask(taskCount);
    });
    const icon = document.createElement('i');
    icon.className = 'ti ti-circle-minus';
    icon.style.float = 'right';
    remove.appendChild(icon);
    block.appendChild(remove);

    const title = document.createElement('span');
    title.style.cssText = 'font-weight: bold; font-size: 15px;';
    title.textContent = labels.task + ' :';
    block.appendChild(title);
    block.appendChild(document.createElement('br'));

    const addLine = function (label, value, valueId = null) {
        const name = document.createElement('span');
        name.style.fontWeight = 'bold';
        name.textContent = label + ' : ';
        const content = document.createElement('span');
        if (valueId !== null) {
            content.id = valueId;
        }
        content.textContent = value;
        block.append(name, content, document.createElement('br'));
    };

    // The description is rich text: only its text is displayed, the markup travels
    // untouched in the hidden field. DOMParser never runs scripts nor loads resources.
    const descriptionText = new DOMParser().parseFromString(String(description), 'text/html').body.textContent;
    addLine(labels.description, descriptionText);
    addLine(labels.technician, '', 'user_tech_name' + taskCount);
    addLine(labels.begin_date, dateToYMD(begin));
    if (end !== undefined && end !== 'undefined' && end !== null && end !== '') {
        addLine(labels.end_date, dateToYMD(end));
    }
    if (duration > 0) {
        addLine(labels.duration, durationDisplay);
    }

    const hidden = {
        duration: duration,
        begin: begin,
        end: end,
        description: description,
        users_id_tech: userIdTech,
        taskcategories_id: tasksCategory,
    };
    $.each(hidden, function (name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name + taskCount;
        input.value = value === undefined ? 'undefined' : value;
        block.appendChild(input);
    });

    return block;
}

function dateToYMD(dateToConvert) {
    const newDate = new Date(dateToConvert);
    const pad = function (n) {
        return n <= 9 ? '0' + n : '' + n;
    };
    return pad(newDate.getDate()) + '-' + pad(newDate.getMonth() + 1) + '-' + newDate.getFullYear() + ' '
        + pad(newDate.getHours()) + ':' + pad(newDate.getMinutes()) + ':' + pad(newDate.getSeconds());
}

function secondsToHm(d) {
    d = Number(d);
    const h = Math.floor(d / 3600);
    const m = Math.floor(d % 3600 / 60);

    const hDisplay = h > 0 ? h + ' h ' : '';
    const mDisplay = m > 0 ? m + ' m ' : '';
    return hDisplay + mDisplay;
}

function getUserName(userIdTech, taskCount) {
    const config = generateCriConfig();
    return $.ajax({
        url: config.user_name_url,
        type: 'POST',
        data: {
            'user_id_tech': userIdTech,
        },
        success: function (data) {
            $('#user_tech_name' + taskCount).text(JSON.parse(data));
        },
    });
}

$(function () {
    const config = generateCriConfig();
    if (config !== null) {
        addTaskOnView(true, config.stored_tasks);
    }
});
