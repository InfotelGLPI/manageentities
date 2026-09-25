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

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use DbUtils;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Manageentities\Config;
use Html;
use Migration;
use Session;
use User;

class InterventionStakeholder extends CommonDBTM
{
    public static $rightname = 'plugin_manageentities';

    public static function getTypeName($nb = 0)
    {
        return _n('User affected', 'Users affected', $nb, 'manageentities');
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
    }

    public static function getIcon()
    {
        return "ti ti-user-pentagon";
    }

    public static function countForItem(CommonDBTM $item)
    {
        $dbu = new DbUtils();
        return $dbu->countElementsInTable(
            'glpi_plugin_manageentities_interventionstakeholders',
            ["plugin_manageentities_contractdays_id" => $item->fields['id']],
        );
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(__CLASS__, $ong, $options);

        return $ong;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$withtemplate) {
            switch ($item->getType()) {
                case ContractDay::class:
                    if ($_SESSION['glpishow_count_on_tabs']) {
                        return self::createTabEntry(
                            InterventionStakeholder::getTypeName(self::countForItem($item)),
                            self::countForItem($item),
                        );
                    } else {
                        return self::createTabEntry(InterventionStakeholder::getTypeName($item));
                    }
            }
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
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
        }
        return true;
    }

    /**
     * Emit an instruction for public/scripts/interventionstakeholder.js, which replays it once
     * the AJAX response is loaded. The payload travels as JSON in an auto-escaped attribute:
     * no JavaScript is generated here.
     *
     * @param 'message'|'row'|'form' $action
     * @param array<string, mixed>   $payload
     */
    private function renderAction(string $action, array $payload): void
    {
        TemplateRenderer::getInstance()->display('@manageentities/interventionstakeholder_action.html.twig', [
            'action'  => $action,
            'payload' => $payload,
        ]);
    }

    /**
     * Refresh the stakeholder row of $item in the list after an AJAX add, update or delete.
     */
    public function reinitListStakeholders(InterventionStakeholder $item, bool $toDelete = false): void
    {
        $idToUse = $item->fields['plugin_manageentities_contractdays_id'];

        $user = new User();
        $user->getFromDB($item->fields['users_id']);
        $condition = ['plugin_manageentities_contractdays_id' => $idToUse];

        $dbu = new DbUtils();
        $this->renderAction('row', [
            'table_id'       => 'list_stakeholders' . $idToUse,
            'empty_id'       => 'empty_stakeholders' . $idToUse,
            'row_id'         => 'row_' . $item->fields['id'],
            'cell_id'        => 'td_user_id' . $item->fields['id'],
            'stakeholder_id' => (int) $item->fields['id'],
            'to_delete'      => $toDelete,
            'is_empty'       => $toDelete
                && count($dbu->getAllDataFromTable($this->getTable(), $condition)) === 0,
            'user_url'       => $user->getLinkURL(),
            'user_name'      => $dbu->formatUserName(
                $user->fields['id'] ?? 0,
                $user->fields['name'] ?? '',
                $user->fields['realname'] ?? '',
                $user->fields['firstname'] ?? '',
            ),
            'nb_days'        => (float) $item->fields['number_affected_days'] . "\u{00A0}" . _n('Day', 'Days', 2),
        ]);
    }

    private function listStakeholders($item, $options = [])
    {
        if ($item->fields['id'] <= 0) {
            return;
        }

        $idToUse  = ($item->getType() == InterventionStakeholder::getType())
            ? $item->fields['plugin_manageentities_contractdays_id']
            : $item->fields['id'];

        $condition        = ['plugin_manageentities_contractdays_id' => $item->fields['id']];
        $dbu              = new DbUtils();
        $listStakeholders = $dbu->getAllDataFromTable($this->getTable(), $condition);

        $entries = [];
        foreach ($listStakeholders as $stakeholder) {
            $user = new User();
            $user->getFromDB($stakeholder['users_id']);
            if (!isset($user->fields['id'])) {
                continue;
            }

            // Structured data: the template escapes it, nothing is concatenated as HTML here
            $entries[] = [
                'row_id'         => 'row_' . $stakeholder['id'],
                'stakeholder_id' => (int) $stakeholder['id'],
                'user_url'       => $user->getLinkURL(),
                'user_name'      => $dbu->formatUserName(
                    $user->fields['id'],
                    $user->fields['name'],
                    $user->fields['realname'],
                    $user->fields['firstname'],
                ),
                'nb_days'        => (float) $stakeholder['number_affected_days'],
            ];
        }

        TemplateRenderer::getInstance()->display('@manageentities/interventionstakeholder_list.html.twig', [
            'id_to_use'  => $idToUse,
            'can_create' => $this->canCreate(),
            'entries'    => $entries,
            'ajax_url'   => PLUGIN_MANAGEENTITIES_WEBDIR . "/ajax/interventionstakeholderactions.php",
        ]);
    }

    /**
     * Show or hide the add form of a contract day, depending on the days left to affect.
     *
     * @param int $idToUse identifier of the contract day whose form block is toggled
     */
    public function toggleAddForm(int $idToUse, bool $visible): void
    {
        $this->renderAction('form', [
            'contractdays_id' => $idToUse,
            'visible'         => $visible,
        ]);
    }


    public function showForm($item = [], $options = [])
    {
        $idToUse   = ($item->getType() == InterventionStakeholder::getType())
            ? $item->fields['plugin_manageentities_contractdays_id']
            : $item->fields['id'];

        if (!isset($options['display_list']) || $options['display_list'] != "false") {
            $this->listStakeholders($item);
        }

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

        TemplateRenderer::getInstance()->display('@manageentities/interventionstakeholder_form.html.twig', [
            'id_to_use'            => $idToUse,
            'nb_days'              => $nbDays,
            'unit'                 => $unit,
            'user_dropdown_html'   => $user_dropdown_html,
            'nbdays_dropdown_html' => $nbdays_dropdown_html,
            'user_field'           => Html::cleanId('dropdown_users_id_tech' . $idToUse . $idUser),
            'nbdays_field'         => Html::cleanId('dropdown_nb_days' . $rand),
            'contractdays_id'      => (int) $item->fields['id'],
            'ajax_url'             => $url,
        ]);
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


    /**
     * Display a feedback message in a core modal.
     *
     * The title and the message are inserted as text by public/scripts/interventionstakeholder.js.
     */
    public function showMessage(string $message, int $messageType): void
    {
        $this->renderAction('message', [
            'type'    => $messageType === ERROR ? 'error' : 'info',
            'title'   => $messageType === ERROR ? __('Warning') : _n('Information', 'Informations', 1),
            'message' => $message,
        ]);
    }

    public static function install(Migration $migration)
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
        $table  = self::getTable();

        if (!$DB->tableExists($table)) {
            $query = "CREATE TABLE `$table` (
                            `id` int {$default_key_sign} NOT NULL auto_increment,
                            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_users (id)',
                            `number_affected_days` double NOT NULL DEFAULT '0' COMMENT 'Number of days affected to the user to an intervention',
                            `plugin_manageentities_contractdays_id` int {$default_key_sign} NOT NULL DEFAULT '0' COMMENT 'RELATION to glpi_plugin_manageentities_contractdays (id)',
                            PRIMARY KEY  (`id`)
               ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            $DB->doQuery($query);
        }
    }


    public static function uninstall()
    {
        global $DB;

        $DB->dropTable(self::getTable(), true);
    }
}
