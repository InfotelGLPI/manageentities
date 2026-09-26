<?php

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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use Ramsey\Uuid\Uuid;

Html::header_nocache();

header("Content-Type: text/html; charset=UTF-8");

$tickettask = new TicketTask();

if (isset($_POST['tickets_id']) && isset($_POST['tickettasks_id']) && $tickettask->getFromDB($_POST['tickettasks_id'])) {

    // Access control: the only live action below creates a task, so the gate has to be a write
    // gate. The global 'task' CREATE right says nothing about WHICH ticket may receive the task,
    // and a READ on the target only proved the caller could look at it: a technician able to
    // consult another service's tickets could plant tasks on them - and trigger the notifications
    // that go with them. can($id, UPDATE) carries the object right and the entity boundary.
    Session::checkRight('task', CREATE);
    $ticket = new Ticket();
    if (!$ticket->can((int) $_POST['tickets_id'], UPDATE)
        || (int) $tickettask->fields['tickets_id'] !== (int) $_POST['tickets_id']) {
        throw new AccessDeniedHttpException();
    }

    switch ($_POST['action'] ?? '') {

        case "cloneTicketTask":
            header('Content-Type: application/json; charset=UTF-8');

            $new_date_value = $_POST['new_date_value'] ?? '';
            if (!is_string($new_date_value) || $new_date_value === '') {
                break;
            }
            // The posted date was copied as is into the planning of the new task. Only accept a
            // real date, in the formats the datetime picker of the row submits.
            $begin = null;
            foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
                $parsed = DateTime::createFromFormat('!' . $format, $new_date_value);
                if ($parsed !== false && $parsed->format($format) === $new_date_value) {
                    $begin = $parsed->format('Y-m-d H:i:s');
                    break;
                }
            }
            if ($begin === null) {
                throw new BadRequestHttpException();
            }

            // The clone used to hand the whole source row over to add(): every column of the
            // task, including the ones add() never expects from an input (author, timeline
            // position, source links...). Only the content of the task is copied, the
            // planning is rebuilt from the new date. The content is read back from the
            // database, where GLPI 10+ stores it raw, so it is not slashed again.
            $input = [
                'tickets_id'  => (int) $tickettask->fields['tickets_id'],
                'date'        => date('Y-m-d H:i:s'),
                'uuid'        => Uuid::uuid4()->toString(),
                'plan'        => [
                    'begin'     => $begin,
                    '_duration' => (int) $tickettask->fields['actiontime'],
                    'users_id'  => (int) $tickettask->fields['users_id_tech'],
                ],
            ];
            foreach (
                [
                    'content',
                    'taskcategories_id',
                    'tasktemplates_id',
                    'is_private',
                    'actiontime',
                    'state',
                    'users_id_tech',
                    'groups_id_tech',
                ] as $field
            ) {
                if (array_key_exists($field, $tickettask->fields)) {
                    $input[$field] = $tickettask->fields[$field];
                }
            }

            $clone = new TicketTask();
            if ($id = $clone->add($input)) {
                echo json_encode(['tickettasks_id' => $id]);
            }
            break;
    }
}
