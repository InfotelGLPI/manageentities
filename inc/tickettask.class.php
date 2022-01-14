<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2022 by the Manageentities Development Team.

 https://github.com/InfotelGLPI/manageentities
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Manageentities.

 Manageentities is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Manageentities is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Manageentities. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginManageentitiesTicketTask extends CommonDBTM {

   var $dohistory = false;

   static $rightname = "plugin_manageentities";

   static public function postForm($params) {
      global $CFG_GLPI;

      $tickettask = $params['item'];
      switch ($tickettask->getType()) {
         case 'TicketTask':

            $rand = mt_rand();
            echo '<tr class="tab_bg_1"><td colspan="3"></td>';
            echo '<td>';
            echo "<div class='fa-label right' style='width:300px;margin-right: 0;margin-left: auto;'>";
            $value = $tickettask->fields['date'];
            if (!empty($tickettask->fields['begin'])) {
               $value = date('Y-m-d H:i:s', strtotime($tickettask->fields['begin'] . ' + 1 DAY'));
            }
            $randDate      = Html::showDateTimeField('new_date', ['value'   => $value,
                                                                  'rand'    => $rand,
                                                                  'mintime' => $CFG_GLPI["planning_begin"],
                                                                  'maxtime' => $CFG_GLPI["planning_end"]]);
            $params        = json_encode(['root_doc'       => PLUGIN_MANAGEENTITIES_WEBDIR,
                                          //                                       'new_date_id'    => 'showdate' . $randDate,
                                          'tickets_id'     => $tickettask->fields['tickets_id'],
                                          'tickettasks_id' => $tickettask->fields['id']]);
            $tickettask_id = $tickettask->fields['id'];
            echo "<span name=\"duplicate_$tickettask_id\" onclick='cloneTicketTask($params);'>";
            echo "<i class='far fa-clone fa-fw pointer'
            title='" . _sx('button', 'Duplicate') . "'></i>";

            echo "</span>";
            echo '</div>';
            echo '</td></tr>';
            break;
      }
   }
}
