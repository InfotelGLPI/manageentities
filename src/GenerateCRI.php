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

namespace GlpiPlugin\Manageentities;

use CommonGLPI;
use CommonITILObject;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\RichText\RichText;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contract;
use GlpiPlugin\Manageentities\Entity;
use Group;
use Html;
use ITILCategory;
use Session;
use TaskCategory;
use TaskTemplate;
use Ticket;
use Ticket_Ticket;
use Ticket_User;
use TicketTask;
use Toolbox;
use User;

/**
 * Class GenerateCRI
 */
class GenerateCRI extends CommonGLPI
{
    public static $rightname = "ticket";

    public const TASK_TO_DO = 1;
    public const TASK_DONE = 2;
    public const MINUTE = 60;
    public const HOUR = 3600;
    public const DAY = 86400;

    /**
     * @param int $nb
     *
     * @return string|\translated
     * @see CommonDBTM::getTypeName($nb)
     *
     */
    public static function getMenuName($nb = 0)
    {
        return __('Generate report intervention', 'manageentities');
    }

    /**
     * @return array
     */
    public static function getMenuContent()
    {
        $menu = [];

        $menu['title'] = self::getMenuName();
        $menu['page'] = PLUGIN_MANAGEENTITIES_WEBDIR . "/front/generatecri.php";
        $menu['links']['search'] = self::getSearchURL(false);
        $menu['icon'] = self::getIcon();

        return $menu;
    }

    /**
     * @return string
     */
    public static function getIcon()
    {
        return "ti ti-clipboard-text";
    }

    /**
     * @param Ticket $ticket
     * @param int    $entities entity of the client the report is generated for, already
     *                         checked against the perimeter of the session by the caller
     *
     */
    public function showWizard($ticket, $entities)
    {
        $entities = (int) $entities;
        $rand = mt_rand();
        $rand_user = mt_rand();
        $tasktemplate = 0;
        $countTasks = [];
        $config = Config::getInstance();

        $values = [
            'itilcategories_id' => 0,
            'type' => \Entity::getUsedConfig(
                'tickettype',
                $entities,
                '',
                Ticket::INCIDENT_TYPE,
            ),
            'content' => '',
            'name' => '',
            'entities_id' => $entities,
            'status' => CommonITILObject::PLANNED,
            'urgency' => 3,
            'impact' => 3,
            'priority' => (int) Ticket::computePriority(3, 3),
            '_tasktemplates_id' => [],
            'users_intervenor' => [Session::getLoginUserID()],
        ];

        // Get default values from posted values on reload form
        if (isset($_POST)) {
            $options = $_POST;
        }

        if (isset($options['name'])) {
            $order = ["\\'", '\\"', "\\\\"];
            $replace = ["'", '"', "\\"];
            $options['name'] = str_replace($order, $replace, $options['name']);
        }

        if (isset($options['content'])) {
            // Clean new lines to be fix encoding
            $order = ['\\r', '\\n', "\\'", '\\"', "\\\\"];
            $replace = ["", "", "'", '"', "\\"];
            $options['content'] = str_replace($order, $replace, $options['content']);
        }

        // Restore saved value or override with page parameter
        $saved = $this->restoreInput();
        foreach ($values as $name => $value) {
            if (!isset($options[$name])) {
                if (isset($saved[$name])) {
                    $options[$name] = $saved[$name];
                } else {
                    $options[$name] = $value;
                }
            }
        }
        // The entity comes from the caller, not from the posted or the saved values
        $options['entities_id'] = $entities;
        // Check category / type validity
        if ($options['itilcategories_id']) {
            $cat = new ITILCategory();
            if ($cat->getFromDB($options['itilcategories_id'])) {
                switch ($options['type']) {
                    case Ticket::INCIDENT_TYPE:
                        if (!$cat->getField('is_incident')) {
                            $options['itilcategories_id'] = 0;
                        }
                        break;

                    case Ticket::DEMAND_TYPE:
                        if (!$cat->getField('is_request')) {
                            $options['itilcategories_id'] = 0;
                        }
                        break;

                    default:
                        break;
                }
            }
        }

        // Load ticket template if available :
        $tt = $ticket->getITILTemplateToUse(
            0,
            $options['type'],
            $options['itilcategories_id'],
            $entities,
        );

        // Predefined fields from template : reset them
        if (isset($options['_predefined_fields'])) {
            $options['_predefined_fields']
                = Toolbox::decodeArrayFromInput($options['_predefined_fields']);
        } else {
            $options['_predefined_fields'] = [];
        }

        Entity::showManageentitiesHeader(__('Generate Intervention report', 'manageentities'));

        // Predefined + hidden template fields, posted back as hidden inputs
        $hidden_inputs     = [];
        $predefined_fields = [];
        $tpl_key = Ticket::getTemplateFormFieldName();
        // override default ticket by predefined fields into ticket & task template
        if (count($tt->predefined) > 0) {
            foreach ($tt->predefined as $predeffield => $predefvalue) {
                if (isset($values[$predeffield])) {
                    if ($predeffield == '_tasktemplates_id') {
                        $tasktemplate = new TaskTemplate();
                        $array_task_template = $tt->predefined['_tasktemplates_id'];
                        foreach ($array_task_template as $id_task_template) {
                            $tasktemplate->getFromDB($id_task_template);
                        }
                    } elseif (((count($options['_predefined_fields']) == 0)
                            && ($options[$predeffield] == $values[$predeffield]))
                        || (isset($options['_predefined_fields'][$predeffield])
                            && ($options[$predeffield] == $options['_predefined_fields'][$predeffield]))
                        || (isset($options[$tpl_key])
                            && ($options[$tpl_key] != $tt->getID()))) {
                        // Load template data
                        $options[$predeffield] = $predefvalue;
                        $predefined_fields[$predeffield] = $predefvalue;
                    }
                } else {
                    $hidden_inputs = array_merge($hidden_inputs, self::flattenHiddenInput($predeffield, $predefvalue));
                }
            }
        }

        // override default ticket by hidden fields into ticket
        if (count($tt->hidden) > 0) {
            foreach ($tt->hidden as $key_hidden => $value_hidden) {
                if (!array_key_exists($key_hidden, $options)) {
                    $hidden_inputs = array_merge($hidden_inputs, self::flattenHiddenInput($key_hidden, $value_hidden));
                }
            }
        }

        // Type dropdown: its AJAX observer reloads the category dropdown
        $type_rand = mt_rand();
        $category_reload = [
            'type'            => '__VALUE__',
            'entity_restrict' => $entities,
            'value'           => $options['itilcategories_id'],
            'currenttype'     => $options['type'],
        ];

        $conditions = [];
        switch ($options['type']) {
            case Ticket::INCIDENT_TYPE:
                $conditions['is_incident'] = 1;
                break;
            case Ticket::DEMAND_TYPE:
                $conditions['is_request'] = 1;
                break;
            default:
                break;
        }

        $opt_categories = [
            'name'      => 'itilcategories_id',
            'condition' => $conditions,
            'on_change' => 'this.form.submit()',
            'value'     => $options['itilcategories_id'],
            'entity'    => $options["entities_id"],
        ];
        if ($tt->isMandatoryField("itilcategories_id")
            && ($options["itilcategories_id"] > 0)) {
            $opt_categories['display_emptychoice'] = false;
        }

        // Contract link rows (only when the CRI PDF is generated by GLPI itself)
        $show_contract = ($entities && !$config->getField('get_pdf_cri'));
        $contract_data = [];
        if ($show_contract) {
            $contract_data = CriDetail::getContractLinkDropdownData([], $entities, 'ticket', 'rows')['template'];
        }

        // Urgency / Impact / Priority (shown depending on template flags)
        $show_urgency = ($tt->isMandatoryField('urgency') || $tt->isPredefinedField('urgency')
            && $tt->isHiddenField('urgency'));
        $show_impact = ($tt->isMandatoryField('impact') || $tt->isPredefinedField('impact')
            && !$tt->isHiddenField('impact'));
        $show_priority = ($tt->isMandatoryField('priority') || $tt->isPredefinedField('priority')
            && !$tt->isHiddenField('priority'));

        // Technician (multiple). The list used to be every non-deleted account of the instance:
        // it named the technicians of every other client, and the ticket creation trusted
        // whatever came back.
        $techs = self::getSelectableTechnicians($entities);

        // Predefined task info block
        $tasktemplate_info = null;
        if ($tasktemplate) {
            $group_name = null;
            if ($tasktemplate->getField('groups_id_tech') > 0) {
                $group = new Group();
                $group->getFromDB($tasktemplate->getField('groups_id_tech'));
                $group_name = (string) $group->getField('name');
            }
            $tasktemplate_info = [
                'id'          => (int) $tasktemplate->fields['id'],
                // Task template content is stored as HTML: only its text is shown
                'description' => RichText::getTextFromHtml((string) $tasktemplate->getField('content')),
                'duration'    => self::formatDuration($tasktemplate->getField('actiontime')),
                'group_name'  => $group_name,
            ];
        }

        // Default begin of the accomplished task
        $heure = intval(date('H'));
        if ($heure < 12) {
            $date = strtotime(date('Y-m-d') . '+' . $config->getField("default_time_am") . ' sec');
        } else {
            $date = strtotime(date('Y-m-d') . '+' . $config->getField("default_time_pm") . ' sec');
        }
        $date = date('Y-m-d H:i:s', $date);

        // Accomplished task editor, filled before the nl2br() pass below as it used to be
        $task_description = $options['description'] ?? '';

        // Task list restore (from session or reloaded POST values)
        $task_stored = json_encode([]);
        foreach ($options as $key => $value) {
            if (strpos($key, "description") !== false) {
                $options[$key] = str_replace('\r\n', '', nl2br($value));
            }
        }
        if (count($saved) > 0) {
            foreach ($saved as $key => $value) {
                if (strpos($key, 'begin') !== false && substr($key, strrpos($key, 'n') + 1) !== '') {
                    $countTasks [] = substr($key, strrpos($key, 'n') + 1);
                }
            }
            if (count($countTasks) > 0) {
                $task_stored = json_encode(self::returnTasksStoreSession($countTasks, $saved), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            }
        } else {
            foreach ($options as $key => $value) {
                if (strpos($key, 'begin') !== false && substr($key, strrpos($key, 'n') + 1) !== '') {
                    $countTasks [] = substr($key, strrpos($key, 'n') + 1);
                }
            }
            if (count($countTasks) > 0) {
                $task_stored = json_encode(self::returnTasksStoreSession($countTasks, $options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            }
        }

        // Same values as the former Html::hidden('has_task', ['value' => bool])
        $hidden_inputs[] = ['name' => 'has_task', 'value' => count($countTasks) > 0 ? '1' : ''];

        // Consumed by public/scripts/generatecri-tasks.js through the data-me-config
        // attribute of #tasks (auto-escaped by Twig, never parsed as HTML).
        $tasks_config = [
            'stored_tasks'      => json_decode($task_stored, true),
            'user_name_url'     => PLUGIN_MANAGEENTITIES_WEBDIR . '/ajax/getUserTechName.php',
            'category_required' => $config->fields['hourorday'] == Config::HOUR,
            'labels'            => [
                'mandatory'       => __('Content, end and begin date are mandatory for a task !', 'manageentities'),
                'category'        => __('Task category must be defined', 'manageentities'),
                'end_after_begin' => __('End date must be after the begin date !', 'manageentities'),
                'task'            => __('Task'),
                'description'     => __('Description'),
                'technician'      => __('Technician as assigned', 'manageentities'),
                'begin_date'      => __('Begin date'),
                'end_date'        => __('End date'),
                'duration'        => __('Duration'),
            ],
        ];

        // Stamped with the modification time of the file, as for gantt.js, so that a
        // browser does not keep an outdated copy between two builds of the same release.
        $tasks_js    = __DIR__ . '/../public/scripts/generatecri-tasks.js';
        $tasks_stamp = PLUGIN_MANAGEENTITIES_VERSION;
        if (file_exists($tasks_js)) {
            $tasks_stamp .= '.' . filemtime($tasks_js);
        }
        echo Html::script(
            'plugins/manageentities/scripts/generatecri-tasks.js',
            ['version' => $tasks_stamp],
            false,
        );

        TemplateRenderer::getInstance()->display('@manageentities/generatecri_wizard.html.twig', [
            'form_action'       => self::getFormUrl(),
            'hidden_inputs'     => $hidden_inputs,
            'values'            => $options,
            // Template flags: the fields are rendered by the Twig macros
            'hidden'            => [
                'name' => $tt->isHiddenField('name'),
            ],
            'mandatory'         => [
                'itilcategories_id'  => $tt->isMandatoryField('itilcategories_id'),
                'name'               => $tt->isMandatoryField('name'),
                'content'            => $tt->isMandatoryField('content'),
                'urgency'            => $tt->isMandatoryField('urgency'),
                'impact'             => $tt->isMandatoryField('impact'),
                'priority'           => $tt->isMandatoryField('priority'),
                'description'        => $tt->isMandatoryField('description'),
                'description_undone' => $tt->isMandatoryField('description-undone'),
            ],
            'entity_rand'       => $rand,
            'type_rand'         => $type_rand,
            'category_reload'   => $category_reload,
            'category_options'  => $opt_categories,
            'show_contract'     => $show_contract,
            'contract_data'     => $contract_data,
            'show_title'        => (!$tt->isHiddenField('name') || $tt->isPredefinedField('name')),
            'show_urgency'      => $show_urgency,
            'show_impact'       => $show_impact,
            'show_priority'     => $show_priority,
            'techs'             => $techs,
            'tasktemplate'      => $tasktemplate_info,
            'task_begin'        => $date,
            'default_duration'  => $config->getField("default_duration"),
            'duration_rand'     => mt_rand(),
            'duration_max'      => 50 * HOUR_TIMESTAMP,
            'task_user_options' => [
                'name'   => "users_id_tech",
                'right'  => "own_ticket",
                'rand'   => $rand_user,
                'value'  => Session::getLoginUserID(),
                'entity' => $options["entities_id"],
                'width'  => '80%',
            ],
            'taskcategories_id' => $options['taskcategories_id'] ?? 0,
            'tasks_config'      => $tasks_config,
            'show_undone'       => (bool) $config->getField("non_accomplished_tasks"),
            'task_description'  => $task_description,
            'undone_description' => $options['description-undone'] ?? '',
        ]);
    }

    /**
     * @param $input
     *
     * @return bool|int
     */
    /**
     * Hidden inputs of a template field, as Html::hidden() used to render them: an array value
     * gives one "name[key]" input per entry.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function flattenHiddenInput(string $name, mixed $value): array
    {
        if (is_array($value)) {
            $inputs = [];
            foreach ($value as $key => $sub_value) {
                $inputs = array_merge($inputs, self::flattenHiddenInput($name . '[' . $key . ']', $sub_value));
            }
            return $inputs;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '';
        }
        return [['name' => $name, 'value' => (string) $value]];
    }

    /**
     * Technicians the caller may assign on an intervention of a given entity.
     *
     * Single source of truth for the criteria: showWizard() builds its dropdown from this list,
     * and the ticket creations below replay it on the ids that come back. getSqlSearchResult() is
     * the core helper the User dropdown itself relies on, so the perimeter is the same one GLPI
     * would have offered.
     *
     * @param int|int[] $entity_restrict entity the intervention belongs to
     * @param string    $right           the same right the matching dropdown was built with
     *
     * @return array<int, string> user id => display name
     */
    public static function getSelectableTechnicians($entity_restrict, string $right = 'all'): array
    {
        $dbu   = new DbUtils();
        $techs = [];
        foreach (User::getSqlSearchResult(false, $right, $entity_restrict) as $data) {
            $techs[(int) $data['id']] = $dbu->getUserName($data['id']);
        }

        return $techs;
    }

    /**
     * Whether a posted task category belongs to the perimeter of the ticket entity.
     *
     * Replays what TaskCategory::dropdown(['entity' => ...]) offered: a category of the entity
     * itself, or a recursive category of one of its ancestors. Zero means "no category", which
     * the dropdown allows.
     *
     * @param int $taskcategories_id the posted value
     * @param int $entities_id       entity of the ticket the task is attached to
     *
     * @return bool
     */
    private static function isSelectableTaskCategory(int $taskcategories_id, int $entities_id): bool
    {
        if ($taskcategories_id <= 0) {
            return true;
        }

        $category = new TaskCategory();
        if (!$category->getFromDB($taskcategories_id)) {
            return false;
        }

        $category_entities_id = (int) $category->fields['entities_id'];
        if ($category_entities_id === $entities_id) {
            return true;
        }

        return (bool) $category->fields['is_recursive']
            && array_key_exists($category_entities_id, getAncestorsOf('glpi_entities', $entities_id));
    }

    /**
     * Keep, among posted assignee ids, those the wizard could actually have offered.
     *
     * @param mixed $posted       the users_intervenor field, as posted
     * @param int   $entities_id  entity the ticket was created in
     *
     * @return int[]
     */
    private static function filterIntervenors($posted, int $entities_id): array
    {
        $selectable = self::getSelectableTechnicians($entities_id);

        return array_values(array_intersect(
            array_map('intval', (array) $posted),
            array_keys($selectable),
        ));
    }

    public static function createTicketAndAssociateContract($input)
    {
        $ticket = new Ticket();
        $allowed_fields = [
            'type',
            'itilcategories_id',
            'name',
            'content',
            'urgency',
            'entities_id',
            'impact',
            'status',
            'priority',
            'locations_id',
            '_groups_id_assign',
            '_groups_id_requester',
            '_groups_id_observer',
            '_users_id_requester',
            '_users_id_observer',
            '_groups_id_assign',
            'requesttypes_id',
            //         'internal_time_to_own',
            //         'olas_id_tto',
            //         'olas_id_ttr',
            //         'internal_time_to_resolve'
        ];

        $inputs = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $inputs[$key] = $value;
            }
        }
        $inputs['status'] = CommonITILObject::PLANNED;

        // entities_id is one of the posted fields copied above, and add() applies no right of its
        // own: the caller only held the global ticket CREATE right, which says nothing about the
        // entity. can(-1, CREATE, $inputs) runs Ticket::canCreateItem(), that is the entity
        // boundary, against the very input about to be written.
        if (!$ticket->can(-1, CREATE, $inputs)) {
            throw new AccessDeniedHttpException();
        }

        $ticketId = $ticket->add($inputs);

        if ($ticketId) {
            // The assignee ids are posted as well. Keep only the technicians the wizard could
            // have offered for this entity, so the form cannot assign an account of another
            // client to an intervention.
            $intervenors = self::filterIntervenors(
                $input['users_intervenor'] ?? [],
                (int) $ticket->fields['entities_id'],
            );
            foreach ($intervenors as $user_assign) {
                $user_ticket = new Ticket_User();
                if (!$user_ticket->getFromDBByCrit([
                    'tickets_id' => $ticketId,
                    'users_id' => $user_assign,
                    'type' => Ticket_User::ASSIGN,
                ])) {
                    $user_ticket->add([
                        'tickets_id' => $ticketId,
                        'users_id' => $user_assign,
                        'type' => Ticket_User::ASSIGN,
                    ]);
                }
            }
            return $ticketId;
        }
    }

    public static function createTicketTaskUndone($input, $tickets_id)
    {
        $ticket = new Ticket();
        $ticket_ticket = new Ticket_Ticket();

        $allowed_fields = [
            'type',
            'itilcategories_id',
            'name',
            'content',
            'urgency',
            'entities_id',
            'impact',
            'status',
            'priority',
            'locations_id',
            '_groups_id_assign',
            '_groups_id_requester',
            '_groups_id_observer',
            '_users_id_requester',
            '_users_id_observer',
            '_groups_id_assign',
            'requesttypes_id',
            //         'internal_time_to_own',
            //         'olas_id_tto',
            //         'olas_id_ttr',
            //         'internal_time_to_resolve'
        ];

        $inputs = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                switch ($key) {
                    default:
                        $inputs[$key] = $value;
                        break;
                }
            }
        }
        $inputs['status'] = CommonITILObject::INCOMING;

        // Same posted entity, same absence of right in add(): see createTicketAndAssociateContract().
        if (!$ticket->can(-1, CREATE, $inputs)) {
            throw new AccessDeniedHttpException();
        }

        $ticketId = $ticket->add($inputs);

        $ticket_ticket->add(['tickets_id_1' => $ticketId, 'tickets_id_2' => $tickets_id, 'link' => '3']);
        if ($ticketId) {
            $intervenors = self::filterIntervenors(
                $input['users_intervenor'] ?? [],
                (int) $ticket->fields['entities_id'],
            );
            foreach ($intervenors as $user_assign) {
                $user_ticket = new Ticket_User();
                if (!$user_ticket->getFromDBByCrit([
                    'tickets_id' => $ticketId,
                    'users_id' => $user_assign,
                    'type' => Ticket_User::ASSIGN,
                ])) {
                    $user_ticket->add([
                        'tickets_id' => $ticketId,
                        'users_id' => $user_assign,
                        'type' => Ticket_User::ASSIGN,
                    ]);
                }
            }
            return $ticketId;
        }
    }

    /**
     * @param $inputs
     * @param $ticket_id
     *
     * @return bool
     */
    public static function createTasks($inputs, $ticket_id)
    {
        // The template id reaches this method through a hidden field of the wizard form, and its
        // content, duration, technician and group are copied verbatim into the task below. Check
        // it is a template the caller may actually read instead of loading it by id.
        $task_template    = new TaskTemplate();
        $task_template_id = (int) ($inputs['predefined-task'] ?? 0);
        if ($task_template_id > 0 && $task_template->can($task_template_id, READ)) {
            $ticket_task = new TicketTask();
            $user_ticket_task = $task_template->getField('users_id_tech') > 0 ?
                $task_template->getField('users_id_tech') : Session::getLoginUserID();

            $input = [
                'tasktemplates_id' => $task_template_id,
                'taskcategories_id' => $task_template->getField('tasktemplates_id'),
                'tickets_id' => $ticket_id,
                'users_id' => Session::getLoginUserID(),
                'users_id_tech' => $user_ticket_task,
                'content' => $task_template->getField('content'),
                'state' => $task_template->getField('state'),
                'groups_id_tech' => $task_template->getField('groups_id_tech'),
                'actiontime' => $task_template->getField('actiontime'),
                'is_private' => $task_template->getField('is_private'),
            ];

            $ticket_task->add($input);
        }

        // Every task below is written with the users_id_tech and taskcategories_id posted in the
        // hidden fields of the wizard form, one pair per task. The right held on the ticket says
        // nothing about the VALUE posted in a dropdown, so the criteria the two dropdowns were
        // built with are replayed here: User::dropdown(right own_ticket, entity of the ticket)
        // and TaskCategory::dropdown(entity of the ticket).
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id)) {
            return false;
        }
        $task_entities_id = (int) $ticket->fields['entities_id'];
        $selectable_techs = array_keys(self::getSelectableTechnicians($task_entities_id, 'own_ticket'));

        $inputs['_plan'] = [];
        $hasDuration = false;
        $hasBegin = false;
        $hasEnd = false;
        $hasDescription = false;
        $hasTech = false;
        unset($inputs['description-undone']);
        if ($inputs['has_task'] == "true") {
            unset($inputs['description']);
        }

        $countTasks = [];

        foreach ($inputs as $key => $value) {
            if (strpos($key, 'description') !== false) {
                if ($key == "description") {
                    $countTasks[] = 0;
                    $inputs['description0'] = $value;
                    $inputs['duration0'] = $inputs['plan']['_duration'];
                    $inputs['begin0'] = $inputs['plan']['begin'];
                    $inputs['users_id_tech0'] = $inputs['users_id_tech'];
                    $inputs['taskcategories_id0'] = $inputs['taskcategories_id'];
                    $inputs['end0'] = isset($inputs['plan']['end']) ? $inputs['plan']['end'] : "undefined";
                } else {
                    $countTasks [] = substr($key, strrpos($key, 'n') + 1);
                }
            }
        }

        foreach ($countTasks as $countTask) {
            foreach ($inputs as $key => $value) {
                if (strpos($key, 'description') !== false) {
                    if ($key == 'description' . $countTask) {
                        $inputs['description'] = $inputs['description' . $countTask];
                        $hasDescription = true;
                    }
                }

                if (strpos($key, 'duration') !== false) {
                    if ($key == 'duration' . $countTask) {
                        $inputs['plan']['_duration'] = $inputs['duration' . $countTask];
                        $hasDuration = true;
                    }
                }

                if (strpos($key, 'users_id_tech') !== false) {
                    if ($key == 'users_id_tech' . $countTask) {
                        $inputs['users_id_tech'] = $inputs['users_id_tech' . $countTask];
                        $hasTech = true;
                    }
                }

                if (strpos($key, 'taskcategories_id') !== false) {
                    if ($key == 'taskcategories_id' . $countTask) {
                        $inputs['taskcategories_id'] = $inputs['taskcategories_id' . $countTask];
                        $hasTech = true;
                    }
                }

                if (strpos($key, 'begin') !== false) {
                    if ($key == 'begin' . $countTask) {
                        $new_date = date('d-m-Y H:i', strtotime($inputs['begin' . $countTask]));
                        $inputs['plan']['begin'] = $inputs['begin' . $countTask];
                        $inputs['_plan']['begin'] = $new_date;
                        $hasBegin = true;
                    }
                }

                if (strpos($key, 'end') !== false) {
                    if ($key == 'end' . $countTask) {
                        if ($inputs['end' . $countTask] != 'undefined') {
                            $new_date = date('d-m-Y H:i', strtotime($inputs['end' . $countTask]));
                            $inputs['plan']['end'] = $inputs['end' . $countTask];
                            $inputs['_plan']['end'] = $new_date;
                        }
                        $hasEnd = true;
                    }
                }

                if ($hasBegin && $hasDuration && $hasEnd && $hasDescription && $hasTech) {
                    $users_id_tech     = (int) $inputs['users_id_tech'];
                    $taskcategories_id = (int) $inputs['taskcategories_id'];
                    if (
                        !in_array($users_id_tech, $selectable_techs, true)
                        || !self::isSelectableTaskCategory($taskcategories_id, $task_entities_id)
                    ) {
                        throw new AccessDeniedHttpException();
                    }

                    $ticket_task = new TicketTask();
                    $ticket_task->add([
                        'tickets_id' => $ticket_id,
                        'users_id' => Session::getLoginUserID(),
                        'users_id_tech' => $users_id_tech,
                        'taskcategories_id' => $taskcategories_id,
                        '_plan' => $inputs['_plan'],
                        'plan' => $inputs['plan'],
                        'content' => $inputs['description'],
                        'state' => self::TASK_DONE,
                    ]);
                    $hasDuration = false;
                    $hasDescription = false;
                    $hasBegin = false;
                    $hasEnd = false;
                    $hasTech = false;
                }
            }
        }
        return true;
    }

    /**
     * @param $ticket_id
     *
     * @return string
     * @throws \GlpitestSQLError
     */
    public static function getDescriptionFromTasks($ticket_id)
    {
        global $DB, $CFG_GLPI;

        $config = Config::getInstance();

        /*
         * Additional information for the global description of the report,
         * prefilled with the public followups.
         */
        $desc = "";
        $criteria = [
            'SELECT' => [
                'begin',
                'content',
                'end',
            ],
            'FROM' => 'glpi_tickettasks',

            'WHERE' => [
                'tickets_id' => $ticket_id,
            ],
        ];

        if ($config->fields['use_publictask'] == Config::HOUR) {
            $criteria['WHERE'] = $criteria['WHERE'] + ['is_private' => 0];
        }

        if ($config->fields['hourorday'] == Config::HOUR) {
            $criteria['LEFT JOIN'] = $criteria['LEFT JOIN'] + [
                'LEFT JOIN' => [
                    'glpi_plugin_manageentities_taskcategories' => [
                        'ON' => [
                            'glpi_plugin_manageentities_taskcategories' => 'taskcategories_id',
                            'glpi_tickettasks' => 'taskcategories_id',
                        ],
                    ],
                ],
            ];
            $criteria['WHERE'] = $criteria['WHERE'] + ['glpi_plugin_manageentities_taskcategories.is_usedforcount' => 1];
        }

        $iterator = $DB->request($criteria);
        if (count($iterator) > 0) {
            foreach ($iterator as $data) {
                $desc .= $data["content"] . "\n\n";
            }
        }

        return $desc;
    }

    /**
     * @param $inputs
     * @param $ticket_id
     * @param $Cri
     */
    public static function generateCri($inputs, $ticket_id, $Cri)
    {
        global $DB, $CFG_GLPI;

        $config = Config::getInstance();
        if (!$config->getField("get_pdf_cri")) {
            $CriPrice = new CriPrice();
            $desc = self::getDescriptionFromTasks($ticket_id);
            $critypes = '';
            if (isset($inputs['plugin_manageentities_contractdays_id'])
                && $inputs['plugin_manageentities_contractdays_id'] > 0) {
                $critypes = $CriPrice->getItems($inputs['plugin_manageentities_contractdays_id']);
            }
            $critypes_default = 0;

            if (!empty($critypes)) {
                foreach ($critypes as $value) {
                    $critypes_default = $value['plugin_manageentities_critypes_id'];
                }
            }

            $desc = substr($desc, 0, strlen($desc) - 2);

            $input['REPORT_ID'] = $ticket_id;
            $input['users_id'] = Session::getLoginUserID();
            $input['CONTRAT'] = $inputs['contracts_id'] ?? 0;
            $input['CONTRACTDAY'] = $inputs['plugin_manageentities_contractdays_id'] ?? 0;
            $input['WITHOUTCONTRACT'] = !((isset($inputs['contracts_id']) && $inputs['contracts_id']) > 0);
            $input['REPORT_ACTIVITE'] = $critypes_default;
            $input['REPORT_DESCRIPTION'] = $desc;
            $input['entities_id'] = $inputs['entities_id'] ?? 0;
            $input['enregistrement'] = true;
            $Cri->generatePdf($input);
        } else {
            $ticket = new Ticket();
            $ticket->getFromDB($ticket_id);

            Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", Generatecri::class);
            CriDetail::displayTabContentForItem($ticket);
        }
    }

    /**
     * Save the input data in the Session
     *
     * @return void
     **@since 0.84
     *
     */
    protected function saveInput($input)
    {
        $_SESSION['saveInput'][$this->getType()] = $input;
    }

    /**
     * Clear the saved data stored in the session
     *
     * @return void
     **@since 0.84
     *
     */
    protected function clearSavedInput()
    {
        unset($_SESSION['saveInput'][$this->getType()]);
    }

    /**
     * @param $input
     *
     * @return bool
     */
    public function checkMandatoryFields($input)
    {
        $msg = [];
        $checkKo = false;

        $this->saveInput($input);
        // check if categories and at least one tech for tasks. check if the customer entity are at least contract even
        //if we don't choose one
        $mandatory_fields = [
            'itilcategories_id' => __('Category'),
            'users_intervenor' => __('Technician as assigned'),
            'description' => __('Description'),
            'has_task' => __('Task'),
        ];

        foreach ($input as $key => $value) {
            if (array_key_exists($key, $mandatory_fields)) {
                if ($key == 'has_task' && !array_key_exists('predefined-task', $input) && !array_key_exists(
                    'description',
                    $input,
                )) {
                    if ($value == 'false') {
                        $msg[] = $mandatory_fields[$key];
                        $checkKo = true;
                    }
                } else {
                    if (empty($value) && !array_key_exists('description1', $input)) {
                        $msg[] = $mandatory_fields[$key];
                        $checkKo = true;
                    }
                }
            }
        }

        if (!array_key_exists('users_intervenor', $input)) {
            $msg[] = $mandatory_fields['users_intervenor'];
            $checkKo = true;
        }

        $config = Config::getInstance();
        if ($input['taskcategories_id'] == 0 && $config->fields['hourorday'] == Config::HOUR) {
            $msg[] = _n('Task category', 'Task categories', 1);
            $checkKo = true;
        }

        if ($checkKo) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __("Mandatory fields are not filled. Please correct: %s"),
                    implode(', ', $msg),
                ),
                false,
                ERROR,
            );
            return true;
        }
        return $checkKo;
    }

    /**
     * Format a duration into a human-readable time.
     *
     * @param float $duration
     *   Duration in seconds, with fractional component.
     *
     * @return string
     */
    public static function formatDuration($duration)
    {
        if ($duration >= self::DAY * 2) {
            return gmdate('z \d\a\y\s H:i:s', $duration);
        }
        if ($duration > self::DAY) {
            return gmdate('\1 \d\a\y H:i:s', $duration);
        }
        if ($duration > self::HOUR) {
            return gmdate("H:i:s", $duration);
        }
        if ($duration > self::MINUTE) {
            return gmdate("i:s", $duration);
        }
        return round($duration, 3, PHP_ROUND_HALF_UP) . 's';
    }

    /**
     * Get the data saved in the session
     *
     * @param array $default Array of value used if session is empty
     *
     * @return array Array of value
     **@since 0.84
     *
     */
    protected function restoreInput(array $default = [])
    {
        if (isset($_SESSION['saveInput'][$this->getType()])) {
            $saved = $_SESSION['saveInput'][$this->getType()];

            // clear saved data when restored (only need once)
            $this->clearSavedInput();

            return $saved;
        }

        return $default;
    }

    protected function returnTasksStoreSession($countTasks, $inputs)
    {
        foreach ($countTasks as $countTask) {
            foreach ($inputs as $key => $value) {
                if (strpos($key, 'description') !== false) {
                    if ($key == 'description' . $countTask) {
                        $task_stored[$countTask]['description'] = $inputs['description' . $countTask];
                    }
                }

                if (strpos($key, 'duration') !== false) {
                    if ($key == 'duration' . $countTask) {
                        $task_stored[$countTask]['duration'] = $inputs['duration' . $countTask];
                    }
                }

                if (strpos($key, 'begin') !== false) {
                    if ($key == 'begin' . $countTask) {
                        $task_stored[$countTask]['begin'] = $inputs['begin' . $countTask];
                    }
                }

                if (strpos($key, 'end') !== false) {
                    if ($key == 'end' . $countTask) {
                        $task_stored[$countTask]['end'] = $inputs['end' . $countTask];
                    }
                }

                if (strpos($key, 'state') !== false) {
                    if ($key == 'state' . $countTask) {
                        $task_stored[$countTask]['state'] = $inputs['state' . $countTask];
                    }
                }

                if (strpos($key, 'users_id_tech') !== false) {
                    if ($key == 'users_id_tech' . $countTask) {
                        $task_stored[$countTask]['users_id_tech'] = $inputs['users_id_tech' . $countTask];
                    }
                }
                if (strpos($key, 'taskcategories_id') !== false) {
                    if ($key == 'taskcategories_id' . $countTask) {
                        $task_stored[$countTask]['taskcategories_id'] = $inputs['taskcategories_id' . $countTask];
                    }
                }
            }
        }
        return $task_stored;
    }

}
