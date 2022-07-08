<?php

/**
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2003-2016 by the Manageentities Development Team.

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

class PluginManageentitiesMappingCategorySlice extends CommonDBTM {

   static $rightname = 'plugin_manageentities';





   /**
    * Get name of this type by language of the user connected
    *
    * @param integer $nb number of elements
    * @return string name of this type
    */
   static function getTypeName($nb = 0) {

      return __('Category/Slice mapping','manageentities');

   }

   public static function canUpdate() {
      return Session::haveRight(static::$rightname, UPDATE);
   }

   public function canUpdateItem() {
      return Session::haveRight(static::$rightname, UPDATE);
   }
   public function can($ID, $right, array &$input = null) {
      return Session::haveRight(static::$rightname, $right);
   }


   /**
    * Show form
    *
    * @global type $CFG_GLPI
    * @return boolean
    */
   function showForm($ID, $options = []) {
      global $CFG_GLPI;
      if (!$this->canView()) {
         return false;
      }
      if (empty($ID)) {
         $this->getEmpty();
      } else {
         $this->getFromDB($ID);
      }

      //      $options['colspan'] = 1;
      $this->initForm($ID,$options);
      $this->showFormHeader($options);


      echo "<tr class='tab_bg_1'>";
      echo Html::hidden('plugin_manageentities_contractpoints_id',['value' => $options['plugin_manageentities_contractpoints_id']]);
      echo "<td>" . TaskCategory::getTypeName(1) . "</td>";
      echo "<td>";
      $used = [];
      $self = new self();
      $existing_groups = $self->find(['plugin_manageentities_contractpoints_id'=> $options['plugin_manageentities_contractpoints_id']]);
      foreach ($existing_groups as $existing_group) {
         $used[$existing_group['taskcategories_id']] = $existing_group['taskcategories_id'];
      }
      $opt = [
         'value' => $this->fields['taskcategories_id'],
         'name' => 'taskcategories_id',
         'used' => $used,
//         'condition' => ['is_assign' => 1]
      ];
      TaskCategory::dropdown($opt);

      echo "</td>";

      echo "<td>" .  __('Number of minutes in a slice','manageentities') . "</td>";
      echo "<td>";
      Dropdown::showNumber('minutes_slice',['max' => 480]);

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";

      echo "<td>" . __('Number of points per slice','manageentities') . "</td>";
      echo "<td>";
      Dropdown::showNumber('points_slice',['max' => 1500]);

      echo "</td>";

      echo "<td>" . "</td>";
      echo "<td>";


      echo "</td>";
      echo "</tr>";

      //      echo "<tr><td class='tab_bg_2 center' colspan='2'><input type=\"submit\" name=\"update\" class=\"submit\"
      //         value=\"" . _sx('button', 'Save') . "\" ></td></tr>";
      //
      //      echo "</table></div>";
      $this->showFormButtons($options);
      Html::closeForm();

      return true;
   }
   /**
    * Show form
    *
    * @global type $CFG_GLPI
    * @return boolean
    */
   function showFromContract($plugin_manageentities_contractpoints_id,$params) {
      global $CFG_GLPI;
      if (!$this->canView()) {
         return false;
      }

      // Default values of parameters
      $default_values["start"]  = $start  = 0;
      $default_values["id"]     = $id     = 0;
      $default_values["export"] = $export = false;
      $default_values['filter'] = $filter = 0;

      foreach ($default_values as $key => $val) {
         if (isset($params[$key])) {
            $$key=$params[$key];
         }
      }
      $result = $this->find(['plugin_manageentities_contractpoints_id' => $plugin_manageentities_contractpoints_id]);
      $numrows = count($result);
      //         $numrows = 1 + ip2long($this->fields['end_ip']) - ip2long($this->fields['begin_ip']);
      $result = array_slice($result, $start, $_SESSION["glpilist_limit"]);

      Html::printPager($start, $numrows, PluginManageentitiesContractpoint::getFormURL(),
                       "id=$plugin_manageentities_contractpoints_id&_glpi_tab=PluginManageentitiesContractpoint$1&start=$start&amp;id=$id&amp;filter=$filter");
      // Set display type for export if define
      $output_type = Search::HTML_OUTPUT;

      if (isset($_GET["display_type"])) {
         $output_type = $_GET["display_type"];
      }
      $nbcols        = 2;
      $header_num    = 1;
      $row_num       = 1;
      $num_row_final = count($result);
      // Column headers
      $rand = mt_rand();
      $massformid = 'massform'.self::getType().$rand;
      Html::openMassiveActionsForm($massformid);
      $massiveactionparams = ['container' => $massformid];
      Html::showMassiveActions($massiveactionparams);
//      Html::showMassiveActions($massiveactionparams);

      echo Search::showHeader($output_type, $num_row_final, $nbcols, 1);
      echo Search::showNewLine($output_type);
      echo Search::showHeaderItem($output_type,
                                Html::getCheckAllAsCheckbox($massformid),
                                $header_num, "", 0);
      echo Search::showHeaderItem($output_type, TaskCategory::getTypeName(1), $header_num);

      echo Search::showHeaderItem($output_type, __('Number of minutes in a slice','manageentities'), $header_num);
      echo Search::showHeaderItem($output_type, __('Number of points per slice','manageentities'), $header_num);

      echo Search::showEndLine($output_type);

      foreach ($result as $key => $value) {
         echo Search::showNewLine($output_type);
         echo Search::showItem($output_type,  Html::getMassiveActionCheckBox($this->getType(), $value['id'], ['class' => $this]), $item_num, $row_num);
         echo Search::showItem($output_type, Dropdown::getDropdownName(TaskCategory::getTable(),$value['taskcategories_id']), $item_num, $row_num);
         echo Search::showItem($output_type, $value['minutes_slice']." ".__("minutes",'manageentities'), $item_num, $row_num);
         echo Search::showItem($output_type, $value['points_slice']." ".__("points",'manageentities'), $item_num, $row_num);
         echo Search::showEndLine($output_type);
      }
      echo Search::showNewLine($output_type);
      echo Search::showHeaderItem($output_type,
                                  Html::getCheckAllAsCheckbox($massformid),
                                  $header_num, "", 0);
      echo Search::showHeaderItem($output_type, TaskCategory::getTypeName(1), $header_num);

      echo Search::showHeaderItem($output_type, __('Number of minutes in a slice','manageentities'), $header_num);
      echo Search::showHeaderItem($output_type, __('Number of points per slice','manageentities'), $header_num);

      echo Search::showEndLine($output_type);
      $massiveactionparams['ontop'] = false;
      Html::showMassiveActions($massiveactionparams);

      echo Search::showFooter($output_type);

      return true;

   }


   public function prepareInputForAdd($input) {

      $compliant = true;
      if(empty($input["taskcategories_id"])) {
         $compliant = false;
         Session::addMessageAfterRedirect(__("The task category is missing",'manageentities'),false,ERROR);
      }
      if(empty($input["minutes_slice"])) {
         $compliant = false;
         Session::addMessageAfterRedirect(__("The number of minute is missing",'manageentities'),false,ERROR);
      }
      if(empty($input["points_slice"]) && $input['points_slice'] != 0) {
         $compliant = false;
         Session::addMessageAfterRedirect(__("The number of points is missing",'manageentities'),false,ERROR);
      }

      if($this->getFromDBByCrit(['taskcategories_id' => $input['taskcategories_id'],'plugin_manageentities_contractpoints_id' => $input['plugin_manageentities_contractpoints_id']])){
         $compliant = false;
         Session::addMessageAfterRedirect(__("The task category is already present",'manageentities'),false,ERROR);
      }


      if($compliant == true) {
         return $input;
      }
      return false;
   }


   public function prepareInputForUpdate($input) {

      return parent::prepareInputForUpdate($input); // TODO: Change the autogenerated stub
   }

   public function post_updateItem($history = 1) {
      parent::post_updateItem($history); // TODO: Change the autogenerated stub
   }

   /**
    * Get the standard massive actions which are forbidden
    *
    * @return array an array of massive actions
    **@since version 0.84
    *
    * This should be overloaded in Class
    *
    */
   function getForbiddenStandardMassiveAction() {

      $forbidden   = parent::getForbiddenStandardMassiveAction();
      $forbidden[] = 'update';
      $forbidden[] = 'clone';
      return $forbidden;
   }

   static function getPoints($plugin_manageentities_contractpoints_id,$taskcategories_id,$time) {
      $contract_points = new PluginManageentitiesContractpoint();
      $self = new self();
      $total_point = 0;
      if($contract_points->getFromDB($plugin_manageentities_contractpoints_id)) {
         $points = $contract_points->fields['points_slice'];
         $minute_slice = $contract_points->fields['minutes_slice'];
         if($self->getFromDBByCrit(['plugin_manageentities_contractpoints_id' => $plugin_manageentities_contractpoints_id,
                                    'taskcategories_id' => $taskcategories_id])) {

            $points = $self->fields['points_slice'];
            $minute_slice = $self->fields['minutes_slice'];
         }
         if($minute_slice != 0) {
            $number = ceil((float)$time / (float)$minute_slice);
            $total_point = $points*$number;
         }

      }
      return $total_point;

   }












}
