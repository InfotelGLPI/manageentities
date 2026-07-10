<?php

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

namespace GlpiPlugin\Manageentities;

use CommonDBTM;
use CommonGLPI;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Config;
use Html;
use Session;
use Toolbox;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class InterventionStakeholder extends CommonDBTM
{

    static $rightname = 'plugin_manageentities';

    static function getTypeName($nb = 0)
    {
        return _n('User affected', 'Users affected', $nb, 'manageentities');
    }

    static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    static function countForItem(CommonDBTM $item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_interventionstakeholders',
            ["plugin_manageentities_contractdays_id" => $item->fields['id']]
        );
    }

    function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(__CLASS__, $ong, $options);

        return $ong;
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate) {
            switch ($item->getType()) {
                case ContractDay::class:
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        return self::createTabEntry(
                            InterventionStakeholder::getTypeName(self::countForItem($item)),
                            self::countForItem($item)
                        );
                    } else {
                        return self::createTabEntry(InterventionStakeholder::getTypeName($item));
                    }
            }
        }
        return '';
    }


    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $interventionStakeholder = new InterventionStakeholder();
        if ($item->getType() == ContractDay::class) {
            $options = [];
            if (isset($item->fields['id']) && $item->fields['id'] > 0) {
                $options['rand'] = $item->fields['id'];
                $_SESSION['glpi_plugin_manageentities_nbdays'] = $item->fields['nbday'];
            } else {
                $options['rand'] = 0;
                $_SESSION['glpi_plugin_manageentities_nbdays'] = 0;
            }
            $interventionStakeholder->showForm($item, $options);
            echo "<div id='divAjaxDisplay" . $item->fields['id'] . "'></div>";
        }
        return true;
    }


    public function reinitValuesNbDays($idDpNbdays, $contractdaysId)
    {
        $nbDays = $this->getNbAvailiableDay($contractdaysId);

        $this->showHeaderJS();
        for ($i = 0; $i <= $nbDays; $i += 0.5) {
            $data[] = ['id' => $i, 'text' => "$i"];
        }
        echo "$('input[name=\"nb_days\"]').select2({width : '100', data:" . json_encode($data) . "});";
        $this->closeFormJS();
    }

    public function reinitListStakeholders($item, $contractdaysId, $idDpNbdays = null, $toDelete = false)
    {
        if ($item->getType() == InterventionStakeholder::getType()) {
            $idToUse   = $item->fields['plugin_manageentities_contractdays_id'];
            $idDivAjax = "divAjaxDisplay" . $item->fields['plugin_manageentities_contractdays_id'];
        } else {
            $idToUse   = $item->fields['id'];
            $idDivAjax = "divAjaxDisplay" . $item->fields['id'];
        }

        $user      = new User();
        $user->getFromDB($item->fields['users_id']);
        $condition = ['plugin_manageentities_contractdays_id' => $item->fields['plugin_manageentities_contractdays_id']];

        $this->showHeaderJS();

        echo "var tbl = document.getElementById('list_stakeholders" . $idToUse . "');\n";

        $dbu = new DbUtils();
        if ($toDelete) {
            echo "var row = document.getElementById('row_" . $item->fields['id'] . "');";
            echo "row.parentNode.removeChild(row);";
            $cd = $dbu->getAllDataFromTable($this->getTable(), $condition);
            if (sizeof($cd) == 0) {
                echo "if(document.getElementById('empty_stakeholders" . $idToUse . "') != null){";
                echo "   tbl.deleteRow(-1);";
                echo "}else{";
                echo "   row=tbl.insertRow(-1);\n";
                echo "   row=tbl.insertRow(-1);\n";
                echo "   row.setAttribute('class','tab_bg_1');\n";
                echo "   row.id='empty_stakeholders" . $idToUse . "';";
                echo "   var tmpCell=row.insertCell(0);\n";
                echo "   tmpCell.innerHTML=\"" . __("No stakeholders have been affected yet.", "manageentities") . "\";";
                echo "}";
            }
        } else {
            echo "if (document.getElementById('td_user_id" . $item->fields['id'] . "') != null){\n";
            echo "   document.getElementById('td_user_id" . $item->fields['id'] . "').innerHTML = '" . $item->fields['number_affected_days'] . " " . _n("Day", "Days", 2) . "';\n";
            echo "}else{\n";
            echo "   if (document.getElementById('empty_stakeholders" . $idToUse . "') != null){";
            echo "      tbl.deleteRow(-1);";
            echo "   }";

            echo "row=tbl.insertRow(-1);\n";
            echo "row.id='row_" . $item->fields['id'] . "';\n";
            echo "row.setAttribute('class','tab_bg_1');\n";

            $link = $user->getLinkURL();

            echo "var tmpCell=row.insertCell(0);\n";
            echo "tmpCell.innerHTML=\"";
            echo "<a href='" . $link . "' target='_blank'>" . $dbu->formatUserName(
                    $user->fields['id'],
                    $user->fields['name'],
                    $user->fields['realname'],
                    $user->fields['firstname']
                ) . "</a>";
            echo "\";";

            echo "tmpCell=row.insertCell(1);";
            echo "tmpCell.id='td_user_id" . $item->fields['id'] . "';";
            echo "tmpCell.innerHTML=\"";
            echo $item->fields['number_affected_days'] . "&nbsp;" . _n("Day", "Days", 2);
            echo "\";";

            echo "tmpCell=row.insertCell(2);";
            echo "tmpCell.innerHTML=\"";
            echo "<i title=\\\"" . __("Delete", "manageentities") . "\\\" class=\\\"ti ti-trash pointer\\\" id='delete_" . $user->fields['id'] . "'></i>";
            echo "\";";
            echo "}";

            echo "document.getElementById('delete_" . $user->fields['id'] . "').onclick= function () {if (confirm('" . __("This action is irreversible. Continue ?", 'manageentities') . "')){deleteStakeholder" . $idToUse . $item->fields['id'] . "();}};";
        }

        $this->closeFormJS();

        if (!$toDelete) {
            $ajax_url = PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/interventionstakeholderactions.php";
            $params   = [
                'action'          => 'delete_user_datas',
                'id_div_ajax'     => $idDivAjax,
                'id_dp_nbdays'    => "nb_days" . $item->fields['plugin_manageentities_contractdays_id'],
                'contractdays_id' => $item->fields['plugin_manageentities_contractdays_id'],
                'stakeholder_id'  => $item->fields['id'],
            ];
            $this->showJSfunction("deleteStakeholder" . $idToUse . $item->fields['id'], $idDivAjax, $ajax_url, [], $params);
        }

        if ($idDpNbdays != null) {
            $this->reinitValuesNbDays('nb_days2', $contractdaysId);
        }
    }

    private function listStakeholders($item, $options = [])
    {
        if ($item->fields['id'] <= 0) {
            return;
        }

        $idToUse  = ($item->getType() == InterventionStakeholder::getType())
            ? $item->fields['plugin_manageentities_contractdays_id']
            : $item->fields['id'];
        $idDivAjax = "divAjaxDisplay" . $idToUse;

        $condition        = ['plugin_manageentities_contractdays_id' => $item->fields['id']];
        $dbu              = new DbUtils();
        $listStakeholders = $dbu->getAllDataFromTable($this->getTable(), $condition);
        $can_create       = $this->canCreate();
        $ajax_url         = PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/interventionstakeholderactions.php";

        $entries    = [];
        $delete_js  = [];

        foreach ($listStakeholders as $stakeholder) {
            $user = new User();
            $user->getFromDB($stakeholder['users_id']);
            if (!isset($user->fields['id'])) {
                continue;
            }
            $user_link = "<a href='" . $user->getLinkURL() . "' target='_blank'>"
                . htmlspecialchars(
                    $dbu->formatUserName(
                        $user->fields['id'],
                        $user->fields['name'],
                        $user->fields['realname'],
                        $user->fields['firstname']
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                ) . "</a>";

            $delete_btn = '';
            if ($can_create) {
                $fn         = "deleteStakeholder" . $idToUse . $stakeholder['id'];
                $delete_btn = "<i title=\"" . __('Delete', 'manageentities')
                    . "\" class=\"ti ti-trash pointer\" id='delete_" . $user->fields['id'] . "'"
                    . " onclick=\"if(confirm('" . __('This action is irreversible. Continue ?', 'manageentities')
                    . "')){" . $fn . "();}\"></i>";

                $delete_js[] = [
                    'fn'              => $fn,
                    'idDivAjax'       => $idDivAjax,
                    'url'             => $ajax_url,
                    'stakeholder_id'  => $stakeholder['id'],
                    'contractdays_id' => $item->fields['id'],
                    'id_dp_nbdays'    => "nb_days" . $stakeholder['plugin_manageentities_contractdays_id'],
                ];
            }

            $entries[] = [
                'row_id'  => 'row_' . $stakeholder['id'],
                'user'    => $user_link,
                'nb_days' => "<span id='td_user_id" . $stakeholder['id'] . "'>"
                    . $stakeholder['number_affected_days'] . '&nbsp;' . _n('Day', 'Days', 2)
                    . "</span>",
                'actions' => $delete_btn,
            ];
        }

        TemplateRenderer::getInstance()->display('@manageentities/interventionstakeholder_list.html.twig', [
            'id_to_use'  => $idToUse,
            'can_create' => $can_create,
            'entries'    => $entries,
            'delete_js'  => $delete_js,
            'ajax_url'   => $ajax_url,
        ]);
    }


    public function hideAddForm($idToUse)
    {
        $this->showHeaderJS();
        echo "var tbl = $('#global_form_content" . $idToUse . "').hide();";
        $this->closeFormJS();
    }

    public function showAddForm($idToUse)
    {
        $this->showHeaderJS();
        echo "var tbl = $('#global_form_content" . $idToUse . "').show();";
        $this->closeFormJS();
    }


    public function showForm($item = [], $options = [])
    {
        $idToUse   = ($item->getType() == InterventionStakeholder::getType())
            ? $item->fields['plugin_manageentities_contractdays_id']
            : $item->fields['id'];
        $idDivAjax = "tabstakeholderajax" . $idToUse;

        if (!isset($options['display_list']) || $options['display_list'] != "false") {
            $this->listStakeholders($item);
        }

        echo "<div id='divAjaxDisplay" . $idToUse . "'></div>";

        if (!$this->canCreate()) {
            return;
        }

        $rand   = $options['rand'] ?? 0;
        $nbDays = $this->getNbAvailiableDay($item->fields['id']);
        $url    = PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/interventionstakeholderactions.php";
        $_SESSION['glpi_plugin_manageentities_nbdays'] -= $nbDays;

        $config = Config::getInstance();
        $is_day = ($config->fields['hourorday'] == Config::DAY);
        $unit   = $is_day ? _n('Day', 'Days', 2) : _n('Hour', 'Hours', 2);

        ob_start();
        $idUser = User::dropdown([
            'name'  => 'users_id_tech' . $idToUse,
            'right' => 'interface',
        ]);
        $user_dropdown_html = ob_get_clean();

        ob_start();
        \Dropdown::showNumber('nb_days', [
            'width' => 100,
            'min'   => 0,
            'max'   => $nbDays,
            'step'  => '0.5',
            'rand'  => $rand,
        ]);
        $nbdays_dropdown_html = ob_get_clean();

        $add_fn   = "addStakeholder" . $idToUse;
        $list_ids = [
            "dropdown_nb_days" . $idToUse                 => ['dropdown', 'nb_days'],
            "dropdown_users_id_tech" . $idToUse . $idUser => ['dropdown', 'users_id_tech'],
        ];
        $params   = [
            'action'          => 'add_user_datas',
            'id_dp_nbdays'    => "dropdown_nb_days" . $idToUse,
            'id_div_ajax'     => $idDivAjax,
            'contractdays_id' => $item->fields['id'],
        ];

        TemplateRenderer::getInstance()->display('@manageentities/interventionstakeholder_form.html.twig', [
            'id_to_use'            => $idToUse,
            'id_div_ajax'          => $idDivAjax,
            'nb_days'              => $nbDays,
            'unit'                 => $unit,
            'user_dropdown_html'   => $user_dropdown_html,
            'nbdays_dropdown_html' => $nbdays_dropdown_html,
            'id_user_field'        => "dropdown_users_id_tech" . $idUser,
            'add_fn'               => $add_fn,
            'ajax_url'             => $url,
            'list_ids'             => $list_ids,
            'params'               => $params,
        ]);
    }

    public static function jsGetElementbyID($id)
    {
        return "$('#$id')";
    }

    public function showJSfunction($functionName, $idDivAjax, $url, $listId, $params, $additionalDiv = null)
    {
        $this->showHeaderJS();
        echo "function " . $functionName . "() {\n";

        $divReturned = $additionalDiv ?? $idDivAjax;

        echo self::jsGetElementbyID($divReturned) . ".load(\n            '" . $url . "'\n";
        echo ",{";
        $first = true;
        foreach ($listId as $key => $val) {
            if (!$first) {
                echo ",";
            }
            $first = false;
            switch ($val[0]) {
                case "checkbox":
                    echo $val[1] . ":" . self::jsGetElementbyID(Html::cleanId($key)) . ".is(':checked')";
                    break;
                default:
                    echo $val[1] . ":" . self::jsGetElementbyID(Html::cleanId($key)) . ".val()";
                    break;
            }
        }
        foreach ($params as $key => $val) {
            if (!$first) {
                echo ",";
            }
            $first = false;
            echo $key . ":'" . $val . "'";
        }
        echo "}\n);";
        echo "}";
        $this->closeFormJS();
    }


    public function getNbAvailiableDay($contractdays_id)
    {
        $contractDay = new ContractDay();
        $contractDay->getFromDB($contractdays_id);
        $nbMaxDays = $contractDay->fields['nbday'];

        $condition            = ["plugin_manageentities_contractdays_id" => $contractdays_id];
        $dbu                  = new DbUtils();
        $listInterventionDays = $dbu->getAllDataFromTable($this->getTable(), $condition);

        if (sizeof($listInterventionDays) == 0) {
            return $nbMaxDays;
        }
        foreach ($listInterventionDays as $intervention) {
            $nbMaxDays -= $intervention['number_affected_days'];
        }
        return $nbMaxDays;
    }


    public function showMessage($message, $messageType, $with = -1, $height = -1)
    {
        switch ($messageType) {
            case ERROR:
                $srcImg     = "ti ti-alert-triangle";
                $color      = "orange";
                $alertTitle = __("Warning");
                break;
            case INFO:
            default:
                $srcImg     = "ti ti-info-circle";
                $color      = "forestgreen";
                $alertTitle = _n("Information", "Informations", 1);
                break;
        }

        $this->showHeaderJS();
        echo " if ($('#alert-message').val()){ $('#alert-message').val(''); }";
        $this->closeFormJS();

        echo "<div id='alert-message' class='tab_cadre_navigation_center' style='display:none;'>" . $message . "</div>";

        $this->showHeaderJS();
        echo "var mTitle = \"<i class='" . $srcImg . "' style='color:" . $color . "'></i>&nbsp;" . $alertTitle . "\";";
        echo "$('#alert-message').dialog({
            autoOpen: false,
            height: " . ($height > 0 ? $height : 150) . ",
            width: " . ($with > 0 ? $with : 250) . ",
            modal: true,
            open: function(){ $(this).parent().children('.ui-dialog-titlebar').html(mTitle); },
            buttons: { 'ok': function(){ $(this).dialog('close'); } },
            beforeClose: function(event){ $('#alert-message').remove(); return false; }
        });
        $('#alert-message').dialog('open');";
        $this->closeFormJS();
    }

    private function showHeaderJS()
    {
        echo "\n<script type='text/javascript'>\n";
    }

    private function closeFormJS()
    {
        echo "</script>\n";
    }

}
