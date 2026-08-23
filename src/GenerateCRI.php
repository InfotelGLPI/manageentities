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

use Ajax;
use CommonGLPI;
use CommonITILObject;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

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
     * @param $ticket
     * @param $entities
     *
     */
    public function showWizard($ticket, $entities)
    {
        $rand = mt_rand();
        $rand_user = mt_rand();
        $tasktemplate = 0;
        $countTasks = [];
        $config = Config::getInstance();

        $values = [
            'itilcategories_id' => 0,
            'type' => \Entity::getUsedConfig(
                'tickettype',
                $_SESSION['glpiactive_entity'],
                '',
                Ticket::INCIDENT_TYPE,
            ),
            'content' => '',
            'name' => '',
            'entities_id' => $_SESSION['glpiactive_entity'],
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
            false,
            $options['type'],
            $options['itilcategories_id'],
            $_SESSION["glpiactive_entity"],
        );

        // Predefined fields from template : reset them
        if (isset($options['_predefined_fields'])) {
            $options['_predefined_fields']
                = Toolbox::decodeArrayFromInput($options['_predefined_fields']);
        } else {
            $options['_predefined_fields'] = [];
        }

        Entity::showManageentitiesHeader(__('Generate Intervention report', 'manageentities'));

        // Small helper: capture the HTML echoed by a GLPI widget as a string fragment.
        $capture = static function (callable $renderer): string {
            ob_start();
            $renderer();
            return (string) ob_get_clean();
        };

        // Predefined + hidden template fields collected as hidden inputs for the form.
        $hidden_fields    = '';
        $predefined_fields = [];
        $tpl_key = Ticket::getTemplateFormFieldName();
        // override default ticket by predefined fields into ticket & task template
        if (isset($tt->predefined) && count($tt->predefined) > 0) {
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
                            && ($options[$tpl_key] != $tt->getID()))
                        // user pref for requestype can't overwrite requestype from template
                        // when change category
                        || (($predeffield == 'requesttypes_id')
                            && empty($saved))) {
                        // Load template data
                        $options[$predeffield] = $predefvalue;
                        $predefined_fields[$predeffield] = $predefvalue;
                    }
                } else {
                    $hidden_fields .= Html::hidden($predeffield, ['value' => $predefvalue]);
                }
            }
        }

        // override default ticket by hidden fields into ticket
        if (isset($tt->hidden) && count($tt->hidden) > 0) {
            foreach ($tt->hidden as $key_hidden => $value_hidden) {
                if (!array_key_exists($key_hidden, $options)) {
                    $hidden_fields .= Html::hidden($key_hidden, ['value' => $value_hidden]);
                }
            }
        }

        // Client (entity) dropdown
        $opt = [
            'name' => 'entities_id',
            'rand' => $rand,
            'on_change' => 'this.form.submit()',
            'value' => $options['entities_id'],
        ];
        $entity_dropdown = $capture(fn() => \Entity::dropdown($opt));

        // Type dropdown + AJAX category reload (keep the rand echoed by dropdownType)
        $opt['on_change'] = 'this.form.submit()';
        $opt['value'] = $options['type'];
        ob_start();
        $rand = $ticket::dropdownType('type', $opt);
        $params = [
            'type' => '__VALUE__',
            'entity_restrict' => $entities,
            'value' => $options['itilcategories_id'],
            'currenttype' => $options['type'],
        ];
        Ajax::updateItemOnSelectEvent(
            "dropdown_type$rand",
            "show_category_by_type",
            "../ajax/dropdownGenerateCriCategories.php",
            $params,
        );
        $type_dropdown = ob_get_clean();

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

        if ($tt->isMandatoryField("itilcategories_id")
            && ($options["itilcategories_id"] > 0)) {
            $opt_categories['display_emptychoice'] = false;
        }
        $opt_categories['condition'] = $conditions;
        $opt_categories['on_change'] = 'this.form.submit()';
        $opt_categories['value'] = $options['itilcategories_id'];
        $opt_categories['entity'] = $options["entities_id"];

        $label_category = sprintf(
            __('%1$s%2$s'),
            __('Category'),
            $tt->getMandatoryMark('itilcategories_id'),
        );
        ob_start();
        echo "<span id='show_category_by_type'>";
        ITILCategory::dropdown($opt_categories);
        echo "</span>";
        $category_dropdown = ob_get_clean();

        // Contract link rows (only when the CRI PDF is generated by GLPI itself)
        $show_contract = ($entities && !$config->getField('get_pdf_cri'));
        $contract_rows = '';
        if ($show_contract) {
            $contract_rows = $capture(fn() => self::showContractLinkDropdown($entities));
        }

        // Title
        $show_title  = (!$tt->isHiddenField('name') || $tt->isPredefinedField('name'));
        $title_label = '';
        $title_field = '';
        if ($show_title) {
            $title_label = sprintf(__('%1$s%2$s'), __('Title'), $tt->getMandatoryMark('name'));
            if (!$tt->isHiddenField('name')) {
                $opt = [
                    'value' => $options['name'],
                    'maxlength' => 250,
                    'size' => 80,
                ];
                if ($tt->isMandatoryField('name')) {
                    $opt['required'] = 'required';
                }
                $title_field = Html::input('name', $opt);
            } else {
                // Ticket name may be requester-controlled and is stored raw; escape it
                // when the template hides the "name" field (Html::hidden escapes on its own).
                $title_field = htmlspecialchars((string) $options['name'], ENT_QUOTES)
                    . Html::hidden('name', ['value' => $options['name']]);
            }
        }

        // Description (rich text)
        $label_content     = sprintf(__('%1$s%2$s'), __('Description'), $tt->getMandatoryMark('content'));
        $rand_text         = mt_rand();
        $rows              = 5;
        $content_id        = "content$rand";
        $content           = $options['content'];
        $content_wrapper_id = "content$rand_text";
        $content_textarea  = $capture(fn() => Html::textarea([
            'name' => 'content',
            'filecontainer' => 'content_info',
            'editor_id' => $content_id,
            'required' => $tt->isMandatoryField('content'),
            'rows' => $rows,
            'enable_richtext' => true,
            'enable_fileupload' => false,
            'enable_images' => false,
            'value' => RichText::getSafeHtml($content, true),
        ]));

        // Urgency / Impact / Priority (shown depending on template flags)
        $show_urgency = ($tt->isMandatoryField('urgency') || $tt->isPredefinedField('urgency')
            && $tt->isHiddenField('urgency'));
        $label_urgency    = '';
        $urgency_dropdown = '';
        if ($show_urgency) {
            $label_urgency    = sprintf(__('%1$s%2$s'), __('Urgency'), $tt->getMandatoryMark('urgency'));
            $urgency_dropdown = $capture(fn() => Ticket::dropdownUrgency(['value' => $options['urgency']]));
        }

        $show_impact = ($tt->isMandatoryField('impact') || $tt->isPredefinedField('impact')
            && !$tt->isHiddenField('impact'));
        $label_impact    = '';
        $impact_dropdown = '';
        if ($show_impact) {
            $label_impact    = sprintf(__('%1$s%2$s'), __('Impact'), $tt->getMandatoryMark('impact'));
            $impact_dropdown = $capture(fn() => Ticket::dropdownImpact(['value' => $options['impact']]));
        }

        $show_priority = ($tt->isMandatoryField('priority') || $tt->isPredefinedField('priority')
            && !$tt->isHiddenField('priority'));
        $label_priority    = '';
        $priority_dropdown = '';
        if ($show_priority) {
            $label_priority    = sprintf(__('%1$s%2$s'), __('Priority'), $tt->getMandatoryMark('priority'));
            $priority_dropdown = $capture(fn() => Ticket::dropdownImpact(['value' => $options['priority']]));
        }

        // Technician (multiple)
        $user = new User();
        $dbu  = new DbUtils();
        $condition = ['is_deleted' => 0];
        $users = $user->find($condition);
        $techs = [];
        foreach ($users as $data) {
            $techs[$data['id']] = $dbu->getUserName($data['id']);
        }
        $technician_dropdown = $capture(fn() => \Dropdown::showFromArray('users_intervenor', $techs, [
            'values' => $options["users_intervenor"],
            'multiple' => true,
            'entity' => $entities,
        ]));

        // Predefined task info block
        $tasktemplate_block = '';
        if ($tasktemplate) {
            ob_start();
            echo "<div style='margin: 10px; padding:10px; width:400px; border:dashed;'>";
            echo "<span style='font-weight:bold; font-size: 15px;'>" . _n('Task', 'Tasks', 1) . " : </span><br>";
            echo "<span style='font-weight:bold;'>" . __('Description') . " : </span>";
            // Task template content is stored raw; escape the plain-text extraction before echo.
            echo "<span>" . htmlspecialchars((string) RichText::getTextFromHtml($tasktemplate->getField('content')), ENT_QUOTES) . "</span><br>";
            echo "<span style='font-weight:bold;'>" . __('Duration') . " : </span>";
            echo "<span>" . self::formatDuration($tasktemplate->getField('actiontime')) . "</span><br>";
            if ($tasktemplate->getField('groups_id_tech') > 0) {
                $group = new Group();
                $group->getFromDB($tasktemplate->getField('groups_id_tech'));
                echo "<span style='font-weight:bold;'>" . __('Technician group') . ": </span>";
                // Group name is stored raw; escape before echo.
                echo "<span>" . htmlspecialchars((string) $group->getField('name'), ENT_QUOTES) . "</span><br>";
            }
            echo Html::hidden('predefined-task', ['value' => $tasktemplate->fields['id']]);
            echo "</div>";
            $tasktemplate_block = ob_get_clean();
        }

        // Accomplished task input row (description rich text)
        $rand_text = mt_rand();
        $rand      = mt_rand();
        $rows      = 5;
        $content_id = "content$rand";
        $content    = isset($options['description']) ? $options['description'] : "";
        $task_desc_wrapper_id      = "content$rand_text";
        $task_description_textarea = $capture(fn() => Html::textarea([
            'name' => 'description',
            'filecontainer' => 'content_info',
            'id' => 'description',
            'editor_id' => $content_id,
            'required' => $tt->isMandatoryField('description'),
            'rows' => $rows,
            'enable_richtext' => true,
            'enable_fileupload' => false,
            'enable_images' => false,
            'value' => RichText::getSafeHtml($content, true),
        ]));

        // Date / duration / technician / category widgets for the task
        $heure = intval(date('H'));
        if ($heure < 12) {
            $date = strtotime(date('Y-m-d') . '+' . $config->getField("default_time_am") . ' sec');
        } else {
            $date = strtotime(date('Y-m-d') . '+' . $config->getField("default_time_pm") . ' sec');
        }
        $date = date('Y-m-d H:i:s', $date);
        $task_datetime_field = $capture(fn() => Html::showDateTimeField(
            "plan[begin]",
            [
                'value' => $date,
                'timestep' => -1,
                'maybeempty' => false,
                'canedit' => true,
                'mindate' => '',
                'maxdate' => '',
            ],
        ));

        ob_start();
        $rand = \Dropdown::showTimeStamp("plan[_duration]", [
            'value' => $config->getField("default_duration"),
            'min' => 0,
            'max' => 50 * HOUR_TIMESTAMP,
            'emptylabel' => __('Specify an end date'),
        ]);
        echo "<br><div id='date_end$rand'></div>";
        $event_options = ['duration' => '__VALUE__', 'name' => "plan[end]"];
        Ajax::updateItemOnSelectEvent(
            "dropdown_plan[_duration]$rand",
            "date_end$rand",
            "../ajax/taskend.php",
            $event_options,
        );
        $task_duration_field = ob_get_clean();

        $params = [
            'name' => "users_id_tech",
            'right' => "own_ticket",
            'rand' => $rand_user,
            'value' => Session::getLoginUserID(),
            'entity' => $options["entities_id"],
            'width' => '80%',
        ];
        $task_user_dropdown = $capture(fn() => User::dropdown($params));

        $params = [
            'name' => "taskcategories_id",
            'entity' => $options["entities_id"],
            'value' => isset($options['taskcategories_id']) ? $options['taskcategories_id'] : 0,
        ];
        $task_taskcategory_dropdown = $capture(fn() => TaskCategory::dropdown($params));

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
                $task_stored = json_encode(self::returnTasksStoreSession($countTasks, $saved));
            }
        } else {
            foreach ($options as $key => $value) {
                if (strpos($key, 'begin') !== false && substr($key, strrpos($key, 'n') + 1) !== '') {
                    $countTasks [] = substr($key, strrpos($key, 'n') + 1);
                }
            }
            if (count($countTasks) > 0) {
                $task_stored = json_encode(self::returnTasksStoreSession($countTasks, $options));
            }
        }

        if (count($countTasks) > 0) {
            $hidden_fields .= Html::hidden('has_task', ['value' => true]);
        } else {
            $hidden_fields .= Html::hidden('has_task', ['value' => false]);
        }

        $root_manageentities_doc = PLUGIN_MANAGEENTITIES_WEBDIR;
        $tasks_script = "<script>
         var root_manageentities_doc = '$root_manageentities_doc';
           $(document).ready(function() {
           let storedTasks = $task_stored;
              addTaskOnView(true, storedTasks);
           });

            function removeBlockTask(taskcount) {

               $(\"#task_\" + taskcount).remove();
              let taskCountDone   = $('#tasks').children('div').last().attr('data-index');

              if (taskCountDone === undefined) {
                $('[name =\"has_task\"]').val('false');
              }
            }

            function addTaskOnView(isOnRefresh, storedTasks = []) {

              let description = '';
              let duration    = '';
              let begin       = '';
              let end         = '';
              let userIdTech  = '';
              let tasksCategory  = '';

              if (Object.keys(storedTasks).length) {
               $('#tab-tasks').show();
                  $.each(storedTasks, function(taskcount, value) {
                       description = value['description'];
                       duration    = value['duration'];
                       begin       = value['begin'];
                       end         = value['end'];
                       userIdTech  = value['users_id_tech'];
                       tasksCategory  = value['taskcategories_id'];

              let durationDisplay = secondsToHm(duration);

              let taskCount = taskcount;

            //first element
              if (taskCount === undefined) {
                  taskCount = 0;
              }

              var blocTask = getBlockTask(taskCount, description, userIdTech, begin, end, duration, durationDisplay,tasksCategory);
              getUserName(userIdTech,taskCount);

                $('#tasks').append(blocTask);
               });

           } else if (!isOnRefresh) {

               $('#tab-tasks').show();
                description = tinyMCE.get($('textarea[name =\"description\"]')[0].id).getContent();
                duration    = $('[name =\"plan[_duration]\"]').val();
                begin       = $('[name =\"plan[begin]\"]').val();
                end         = $('[name =\"plan[end]\"]').val();
                userIdTech  = $('[name =\"users_id_tech\"]').val();
                tasksCategory  = $('[name =\"taskcategories_id\"]').val();

                if (description == '' || begin == ''  || userIdTech == 0 || end === undefined  && duration == 0) {
                    alert('" . __('Content, end and begin date are mandatory for a task !', 'manageentities') . "');
              } else if (tasksCategory == 0 && " . $config->fields['hourorday'] . " == " . Config::HOUR . " ) {
                    alert('" . __('Task category must be defined', 'manageentities') . "');
              } else if (end <= begin) {
                    alert('" . __('End date must be after the begin date !', 'manageentities') . "');
              } else {
                //convert duration for display
              let durationDisplay = secondsToHm(duration);

              let taskCount = $('#tasks').children('div').last().attr('data-index');

               //first element
              if (taskCount === undefined) {
                  taskCount = 0;
              }
              taskCount ++;

             var blocTask = getBlockTask(taskCount, description, userIdTech, begin, end, duration, durationDisplay,tasksCategory);
             getUserName(userIdTech,taskCount);
              $('[name =\"has_task\"]').val('true');

              $('#tasks').append(blocTask);

              tinyMCE.get($('textarea[name =\"description\"]')[0].id).setContent('');
              $('[name =\"plan[_duration]\"]').val();
              $('[name =\"plan[begin]\"]').val();
              $('[name =\"users_id_tech\"]').val();
              $('[name =\"taskcategories_id\"]').val();
                 }
              }
         };

            function getBlockTask(taskCount, description, userIdTech, begin, end, duration, durationDisplay,tasksCategory) {
              var  blocTask  = '<div data-index=\"' + taskCount + '\" style=\"margin: 10px; padding:10px; width:100 %; border:dashed;\" id=\"task_' + taskCount + '\" >';
               blocTask += '<tr class=\"tab_bg_1\">';
               blocTask += '<a onclick=\"removeBlockTask(' + taskCount + ');\" \"style = \"cursor:pointer;\" ><i style = \"float:right;\" class=\"ti ti-circle-minus\" ></i ></a> ';
               blocTask += '<span style = \"font-weight:bold; font-size: 15px;\" >" . __('Task') . " :</span><br> ';
               blocTask += '<span style = \"font-weight:bold;\" >" . __("Description", 'servicecatalog') . " : </span ><span> ' + description + ' </span><br> ';
               blocTask += '<span style = \"font-weight:bold;\"  " . __('Technician as assigned', 'manageentities') . " : </span><span id=\"user_tech_name' + taskCount +'\"></span><br> ';
               blocTask += '<span style = \"font-weight:bold;\" >" . __('Begin date') . " : </span ><span> ' + dateToYMD(begin) + ' </span><br> ';
               blocTask += (end == undefined || end == 'undefined') ? '' : ' <span style = \"font-weight:bold;\"> '+ __('End date') + ' : </span><span> ' + dateToYMD(end) + ' </span><br> ';
               blocTask += (duration > 0) ? ' <span style = \"font-weight:bold;\" >" . __('Duration') . " : </span><span> ' + durationDisplay + ' </span><br> ' : '';
               blocTask += ' <input name = \"duration' + taskCount + '\" type = \"hidden\" value = \"' + duration + '\"\>';
               blocTask += ' <input name = \"begin' + taskCount + '\" type = \"hidden\" value = \"' + begin + '\"\>';
               blocTask += ' <input name = \"end' + taskCount + '\" type = \"hidden\" value = \"' + end + '\"\>';
               blocTask += ' <input name = \"description' + taskCount + '\" type = \"hidden\" value = \"' + description + '\"\>';
               blocTask += ' <input name = \"users_id_tech' + taskCount + '\" type = \"hidden\" value = \"' + userIdTech + '\"\>';
               blocTask += ' <input name = \"taskcategories_id' + taskCount + '\" type = \"hidden\" value = \"' + tasksCategory + '\"\>';
               blocTask += ' </tr></div> ';

               return blocTask;
            }

            function dateToYMD(dateToConvert) {
                let newDate = new Date(dateToConvert);
                var d = newDate.getDate();
                var m = newDate.getMonth() + 1;
                var y = newDate.getFullYear();
                var hh = newDate.getHours();
                var mm = newDate.getMinutes();
                var ss = newDate.getSeconds();
                return '' + (d <= 9 ? '0' + d : d) + '-' + (m <=9 ? '0' + m : m) + '-' +  y + ' ' +
                              (hh <= 9 ? '0' + hh : hh) + ':' + (mm <= 9 ? '0' + mm : mm) + ':' + (ss <= 9 ? '0' + ss : hh) ;
            }

            function secondsToHm(d) {
                d = Number(d);
                var h = Math.floor(d / 3600);
                var m = Math.floor(d % 3600 / 60);

                var hDisplay = h > 0 ? h + (h == 1 ? \" h \" : \" h \") : \"\";
                var mDisplay = m > 0 ? m + (m == 1 ? \" m \" : \" m \") : \"\";
                return hDisplay + mDisplay;
            }

             function getUserName(userIdTech,taskCount) {
                   return $.ajax({
                             url   : root_manageentities_doc + '/ajax/getUserTechName.php',
                             type  : 'POST',
                             data  : {
                                     'user_id_tech': userIdTech,
                             },
                             success:function(data) {
                                   $('#user_tech_name' + taskCount).html(JSON.parse(data));
                                 }
                           });
            }

           </script>";

        // Non-accomplished tasks (optional, rich text)
        $show_undone       = (bool) $config->getField("non_accomplished_tasks");
        $undone_wrapper_id = '';
        $undone_textarea   = '';
        if ($show_undone) {
            $rand_text = mt_rand();
            $rand      = mt_rand();
            $rows      = 5;
            $content_id = "content$rand";
            $content    = isset($options['description-undone']) ? $options['description-undone'] : "";
            $undone_wrapper_id = "content$rand_text";
            $undone_textarea   = $capture(fn() => Html::textarea([
                'name' => 'description-undone',
                'filecontainer' => 'content_info',
                'id' => 'description-undone',
                'editor_id' => $content_id,
                'required' => $tt->isMandatoryField('description-undone'),
                'rows' => $rows,
                'enable_richtext' => true,
                'enable_fileupload' => false,
                'enable_images' => false,
                'value' => RichText::getSafeHtml($content, true),
            ]));
        }

        TemplateRenderer::getInstance()->display('@manageentities/generatecri_wizard.html.twig', [
            'form_action'                => self::getFormUrl(),
            'hidden_fields'              => $hidden_fields,
            'label_ticket_info'          => __('Ticket informations', 'manageentities'),
            'label_client'               => _n('Client', 'Clients', 2, 'manageentities'),
            'entity_dropdown'            => $entity_dropdown,
            'label_type'                 => __('Type'),
            'type_dropdown'              => $type_dropdown,
            'label_category'             => $label_category,
            'category_dropdown'          => $category_dropdown,
            'show_contract'              => $show_contract,
            'contract_rows'              => $contract_rows,
            'show_title'                 => $show_title,
            'title_label'                => $title_label,
            'title_field'                => $title_field,
            'label_content'              => $label_content,
            'content_wrapper_id'         => $content_wrapper_id,
            'content_textarea'           => $content_textarea,
            'show_urgency'               => $show_urgency,
            'label_urgency'              => $label_urgency,
            'urgency_dropdown'           => $urgency_dropdown,
            'show_impact'                => $show_impact,
            'label_impact'               => $label_impact,
            'impact_dropdown'            => $impact_dropdown,
            'show_priority'              => $show_priority,
            'label_priority'             => $label_priority,
            'priority_dropdown'          => $priority_dropdown,
            'label_technician'           => __('Technician'),
            'technician_dropdown'        => $technician_dropdown,
            'tasktemplate_block'         => $tasktemplate_block,
            'label_tasktemplate'         => __('Predefined task informations', 'manageentities'),
            'label_accomplished'         => __('Accomplished tasks informations', 'manageentities'),
            'label_add_task'             => __('Add this Task', 'manageentities'),
            'label_description'          => __('Description'),
            'task_desc_wrapper_id'       => $task_desc_wrapper_id,
            'task_description_textarea'  => $task_description_textarea,
            'label_start_date'           => __('Start date'),
            'label_duration'             => __('Duration'),
            'label_user'                 => _n('User', 'Users', 1),
            'label_technician_single'    => _n("Technician", "Technicians", 1, "manageentities"),
            'label_task_category'        => __('Category'),
            'task_datetime_field'        => $task_datetime_field,
            'task_duration_field'        => $task_duration_field,
            'task_user_dropdown'         => $task_user_dropdown,
            'task_taskcategory_dropdown' => $task_taskcategory_dropdown,
            'tasks_script'               => $tasks_script,
            'show_undone'                => $show_undone,
            'label_undone'               => __('Non-accomplished tasks informations', 'manageentities'),
            'undone_wrapper_id'          => $undone_wrapper_id,
            'undone_textarea'            => $undone_textarea,
        ]);
    }

    /**
     * @param $input
     *
     * @return bool|int
     */
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
                switch ($key) {
                    //               case 'content':
                    //               case 'name':
                    //                  $inputs[$key] = addslashes($value);
                    //                  break;
                    default:
                        $inputs[$key] = $value;
                        break;
                }
            }
        }
        $inputs['status'] = CommonITILObject::PLANNED;

        $ticketId = $ticket->add($inputs);

        if ($ticketId) {
            foreach ($input['users_intervenor'] as $user_assign) {
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

        $ticketId = $ticket->add($inputs);

        $ticket_ticket->add(['tickets_id_1' => $ticketId, 'tickets_id_2' => $tickets_id, 'link' => '3']);
        if ($ticketId) {
            foreach ($input['users_intervenor'] as $user_assign) {
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
        if (isset($inputs['predefined-task'])) {
            $task_template = new TaskTemplate();
            $task_template_id = $inputs['predefined-task'];
            $task_template->getFromDB($task_template_id);

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

        $inputs['_plan'] = [];
        //      $inputs['plan']  = [];
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
                    $ticket_task = new TicketTask();
                    $ticket_task->add([
                        'tickets_id' => $ticket_id,
                        'users_id' => Session::getLoginUserID(),
                        'users_id_tech' => $inputs['users_id_tech'],
                        'taskcategories_id' => $inputs['taskcategories_id'],
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
         * Information complémentaire pour la description globale du CRI.
         * Préremplissage avec les informations des suivis non privés.
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
            $input['entities_id'] = $inputs['entities_id'];
            $input['enregistrement'] = true;
            //      $input['download']           = isset($inputs['download']) ? $inputs['download'] : 0;
            $Cri->generatePdf($input);
        } else {
            $ticket = new Ticket();
            $ticket->getFromDB($ticket_id);

            Html::header(__('Entities portal', 'manageentities'), '', "helpdesk", Generatecri::class);
            CriDetail::displayTabContentForItem($ticket);
        }
    }

    /**
     * @param        $entities_id
     * @param string $type
     *
     * @return array
     * @throws \GlpitestSQLError
     */
    public static function showContractLinkDropdown($entities_id, $type = 'ticket')
    {
        global $DB;

        $contract = new \Contract();
        $contract->getEmpty();
        $rand = mt_rand();
        $width = 300;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_contracts.id',
                'glpi_contracts.name',
                'glpi_contracts.num',
                'glpi_plugin_manageentities_contracts.contracts_id',
                'glpi_plugin_manageentities_contracts.id as ID_us',
                'glpi_plugin_manageentities_contracts.id as is_default',
            ],
            'DISTINCT' => true,
            'FROM' => 'glpi_contracts',
            'LEFT JOIN' => [
                'glpi_plugin_manageentities_contracts' => [
                    'ON' => [
                        'glpi_plugin_manageentities_contracts' => 'contracts_id',
                        'glpi_contracts' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_contracts.is_deleted' => 0,
                'glpi_plugin_manageentities_contracts.entities_id' => $entities_id,
            ],
            'ORDERBY' => ['glpi_contracts.name'],
        ]);

        $selected = false;
        $contractSelected = 0;
        $contractdaySelected = 0;

        // Display contract
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Intervention with contract', 'manageentities') . "</td>";
        echo "<td>";
        if (count($iterator) > 0) {
            if ($type == 'ticket') {
                $elements = [\Dropdown::EMPTY_VALUE];
                $value = 0;
                foreach ($iterator as $data) {
                    if ($data["id"]) {
                        $selected = true;
                        $value = $data["id"];
                    } elseif ($data["is_default"] == '1' && !$selected) {
                        $contractSelected = $data['contracts_id'];
                        $value = $data["id"];
                    }

                    if (Contract::checkRemainingOpenContractDays($data["id"])) {
                        $elements[$data["id"]] = $data["name"] . " - " . $data["num"];
                    }
                }
                if ($value == 0 && count($elements) == 2) {
                    unset($elements[0]);
                }
                $rand = \Dropdown::showFromArray('contracts_id', $elements, ['value' => $value, 'width' => $width]);
            } else {
                foreach ($iterator as $data) {
                }
                if ($contractSelected) {
                    echo \Dropdown::getDropdownName('glpi_contracts', $contractSelected);
                }
            }
        } else {
            echo __('No active contracts', 'manageentities');
        }

        if (count($iterator) > 0) {
            // Tooltip for contract
            if (!empty($contractSelected)) {
                echo '&nbsp;';
                $contract->getFromDB($contractSelected);
                Html::showToolTip($contract->fields['comment'], [
                    'link' => $contract->getLinkURL(),
                    'linktarget' => '_blank',
                ]);
            }

            // Ajax for contract
            $params = [
                'contracts_id' => '__VALUE__',
                'contractdays_id' => $contractdaySelected,
                'current_contracts_id' => $contractSelected,
                'width' => $width,
            ];
            Ajax::updateItemOnSelectEvent(
                "dropdown_contracts_id$rand",
                "show_contractdays",
                PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
                $params,
            );
            Ajax::updateItem(
                "show_contractdays",
                PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/dropdownContract.php",
                $params,
                "dropdown_contracts_id$rand",
            );
            echo "</td>";

            // Display contract day
            echo "<td>" . __('Periods of contract', 'manageentities') . "</td>";
            echo "<td>";
            $restrict = [
                'entities_id' => $contract->fields['entities_id'],
                'contracts_id' => $contractSelected,
            ];
            $restrict += ['NOT' => ['plugin_manageentities_contractstates_id' => 2]]; //Closed contract was 8, is now 2
            if ($type == 'ticket') {
                echo "<span id='show_contractdays'>";
                \Dropdown::show(ContractDay::class, [
                    'name' => 'plugin_manageentities_contractdays_id',
                    'value' => $contractdaySelected,
                    'condition' => $restrict,
                    'width' => $width,
                ]);
                echo "</span>";
            } else {
                echo \Dropdown::getDropdownName('glpi_plugin_manageentities_contractdays', $contractdaySelected);
            }
            echo "</td>";
            echo "</tr>";

            return [
                'contractSelected' => $contractSelected,
                'contractdaySelected' => $contractdaySelected,
                'is_contract' => count($iterator),
            ];
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
