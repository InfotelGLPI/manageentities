<?php
/*
 * @version $Id: HEADER 15930 2011-10-30 15:47:55Z tsmr $
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2014-2017 by the Manageentities Development Team.

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

class PluginManageentitiesContractpoint extends CommonDBTM {

   const MANAGEMENT_NONE      = 0;
   const MANAGEMENT_QUARTERLY = 1;
   const MANAGEMENT_ANNUAL    = 2;

   const CONTRACT_TYPE_NULL = 0;
   //time mode 
   const CONTRACT_POINTS        = 1;
   const CONTRACT_UNLIMITED    = 3;
   //Daily mode
   const CONTRACT_TYPE_AT      = 4;
   const CONTRACT_TYPE_FORFAIT = 5;

   static $rightname = 'plugin_manageentities';

   static function getTypeName($nb = 1) {

      return __('Contract details', 'manageentities');
   }

   static function canView() {
      return Session::haveRight(self::$rightname, READ);
   }

   static function canCreate() {
      return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
   }
   function canUpdateItem() {
      return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
   }

   function prepareInputForAdd($input) {

      if (isset($input['date_renewal'])
          && empty($input['date_renewal']))
         $input['date_renewal'] = 'NULL';
      if (isset($input['date_signature'])
          && empty($input['date_signature']))
         $input['date_signature'] = 'NULL';

      if (isset($input['contract_added'])
          && ($input['contract_added'] === "on"
          || ($input['contract_added'] && $input['contract_added'] != 0 ))) {
         $input['contract_added'] = 1;
      } else {
         $input['contract_added'] = 0;
      }
      if (isset($input['refacturable_costs'])
          && ($input['refacturable_costs'] === "on"
          || ($input['refacturable_costs'] && $input['refacturable_costs'] != 0 ))) {
         $input['refacturable_costs'] = 1;
      } else {
         $input['refacturable_costs'] = 0;
      }
      if(isset($input['initial_credit'])) {
         $input['current_credit'] = $input['initial_credit'];
      }
      return $input;
   }

   function prepareInputForUpdate($input) {

      if (isset($input['date_renewal'])
          && empty($input['date_renewal']))
         $input['date_renewal'] = 'NULL';
      if (isset($input['date_signature'])
          && empty($input['date_signature']))
         $input['date_signature'] = 'NULL';

      if (isset($input['contract_added'])
          && ($input['contract_added'] === "on"
          || ($input['contract_added'] && $input['contract_added'] != 0 ))) {
         $input['contract_added'] = 1;
      } else {
         $input['contract_added'] = 0;
      }
      if (isset($input['refacturable_costs'])
          && ($input['refacturable_costs'] === "on"
          || ($input['refacturable_costs'] && $input['refacturable_costs'] != 0 ))) {
         $input['refacturable_costs'] = 1;
      } else {
         $input['refacturable_costs'] = 0;
      }
      return $input;
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() == 'Contract'
          && !isset($withtemplate) || empty($withtemplate)) {

         $dbu                = new DbUtils();
         $restrict           = ["`entities_id`"  => $item->fields['entities_id'],
                                "`contracts_id`" => $item->fields['id']];
         $pluginContractDays = $dbu->countElementsInTable("glpi_plugin_manageentities_contractdays", $restrict);
         if ($_SESSION['glpishow_count_on_tabs']) {
            return self::createTabEntry(__('Contract detail', 'manageentities'), $pluginContractDays);
         }
         return __('Contract detail', 'manageentities');
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      if (get_class($item) == 'Contract') {
         $self = new self();
         $self->showForContract($item);

//         if (self::canView()) {
//            PluginManageentitiesContractBilling::showForContract($item);
//         }
      }
      return true;
   }

   function addContractByDefault($id, $entities_id) {
      global $DB;

      $query  = "SELECT *
        FROM `" . $this->getTable() . "`
        WHERE `entities_id` IN (" . $entities_id . ") ";
      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number) {
         while ($data = $DB->fetchArray($result)) {

            $query_nodefault = "UPDATE `" . $this->getTable() . "`
            SET `is_default` = 0 WHERE `id` = " . $data["id"];
            $DB->query($query_nodefault);
         }
      }

      $query_default = "UPDATE `" . $this->getTable() . "`
        SET `is_default` = 1 WHERE `id` = $id";
      $DB->query($query_default);
   }

   function showForContract(Contract $contract) {
      $rand    = mt_rand();
      $canView = $contract->can($contract->fields['id'], READ);
      $canEdit = $contract->can($contract->fields['id'], UPDATE);
      $config  = PluginManageentitiesConfig::getInstance();
      $entity = new Entity();
      $entity->getFromDB(0);
      if (!$canView) return false;

      $restrict        = [
//         "`glpi_plugin_manageentities_contracts`.`entities_id`"  => $contract->fields['entities_id'],
                          "`glpi_plugin_manageentities_contracts`.`contracts_id`" => $contract->fields['id']];
      $dbu             = new DbUtils();
      $pluginContracts = $dbu->getAllDataFromTable("glpi_plugin_manageentities_contracts", $restrict);
      $pluginContract  = reset($pluginContracts);
      $this->getEmpty();
      $saved = self::getDefaultValues();
      $this->restoreSavedValues($saved);
      $this->getFromDBByCrit(['contracts_id' => $contract->getID()]);

      if ($canEdit) {
         echo "<form method='post' name='contract_form$rand' id='contract_form$rand'
               action='" . Toolbox::getItemTypeFormURL('PluginManageentitiesContractpoint') . "'>";
      }

      echo "<div align='spaced'><table class='tab_cadre_fixe center'>";
      echo Html::hidden('contracts_id',['value' => $contract->fields['id']]);
      echo "<tr><th colspan='4'>" . PluginManageentitiesContract::getTypeName(0) . "</th></tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Contract mode','manageentities') . "</td>";
      echo "<td colspan='1'>";
      self::dropdownContractType('contract_type', $this->fields['contract_type']);
      echo "</td>";
      echo "<td>";
      echo "</td>";
      echo "<td>";
      echo "</td>";
      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Cancelled contract','manageentities') . "</td>";

      echo "<td colspan='1'>";
      Dropdown::showYesNo('contract_cancelled',$this->fields['contract_cancelled']);
      echo "</td>";
      echo "<td>";
      echo "</td>";
      echo "<td>";
      echo "</td>";
      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Contact mail','manageentities') . "</td>";
      echo "<td colspan='1'>" . Html::input('contact_email',['value' => $this->fields['contact_email']]) . "</td>";
      echo "<td>";
      echo "</td>";
      echo "<td>";
      echo "</td>";
      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Initial credit','manageentities') . "</td>";
      echo "<td colspan='1'>" . Html::input('initial_credit',['value' => $this->fields['initial_credit']]) . "  ". __('points','manageentities'). "</td>";
      echo "<td colspan='1'>" . __('Renewal number','manageentities') . "</td>";
      echo "<td colspan='1'>" . $this->fields['renewal_number']. "</td>";

      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Current credit','manageentities') . "</td>";
      echo "<td colspan='1'>" . $this->fields['current_credit']."  ". __('points','manageentities')."</td>";
      echo "<td colspan='1'>" . __('Credit consumed','manageentities') . "</td>";
      echo "<td colspan='1'>" . $this->fields['credit_consumed']."  ". __('points','manageentities')."</td>";

      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Contract renewal threshold','manageentities') . "</td>";
      echo "<td colspan='1'>" .Html::input('threshold',['value' => $this->fields['threshold']]) ."  ". __('points','manageentities')."</td>";
      echo "<td colspan='1'>" . "</td>";
      echo "<td colspan='1'>" . "</td>";

      echo "</tr>";

      echo "<tr>";
      echo "<td colspan='1'>" . __('Number of minutes in a slice by default','manageentities') . "</td>";
      echo "<td colspan='1'>" . Dropdown::showNumber('minutes_slice',['max' => 480,'display' => false,'value' => $this->fields['minutes_slice']])."</td>";
      echo "<td colspan='1'>" . __('Number of points per slice by default','manageentities') . "</td>";
      echo "<td colspan='1'>" . Dropdown::showNumber('points_slice',['max' => 1500,'display' => false,'value' => $this->fields['points_slice']])."</td>";

      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Logo to show in report','manageentities') . "</td>";
      echo "<td colspan='3'>" . Html::file(['name' => 'picture_logo','value' => $this->fields["picture_logo"],'onlyimages' => true,'display' => false])."</td>";
      echo "</tr>";
      echo "<tr>";
      echo "<td colspan='1'>" . __('Text in report footer','manageentities') . "</td>";
      echo "<td colspan='3'>" . Html::textarea(['name' => 'footer','value' => $this->fields["footer"],'display' => false])."</td>";

      echo "</tr>";
      echo "<tr>";
      $params['canedit'] = true;
      $params['formfooter'] = null;
      $this->showFormButtons($params);
      echo "</tr>";

      echo "</table></div>";
      if ($canEdit) {
         Html::closeForm();
      }
      if($this->getID() != 0) {
         $mapping = new PluginManageentitiesMappingCategorySlice();
         $mapping->showForm(0,['plugin_manageentities_contractpoints_id' => $this->getID()]);
         $mapping->showFromContract($this->getID(),$_GET);
      }

   }


   function showContracts($instID) {
      global $DB, $CFG_GLPI;

      PluginManageentitiesEntity::showManageentitiesHeader(__('Associated assistance contracts', 'manageentities'));

      $entitiesID = "'" . implode("', '", $instID) . "'";
      $config     = PluginManageentitiesConfig::getInstance();

      $query  = "SELECT `glpi_contracts`.*,
                       `" . $this->getTable() . "`.`contracts_id`,
                       `" . $this->getTable() . "`.`management`,
                       `" . $this->getTable() . "`.`contract_type`,
                       `" . $this->getTable() . "`.`is_default`,
                       `" . $this->getTable() . "`.`id` as myid
        FROM `" . $this->getTable() . "`, `glpi_contracts`
        WHERE `" . $this->getTable() . "`.`contracts_id` = `glpi_contracts`.`id`
        AND `" . $this->getTable() . "`.`entities_id` IN (" . $entitiesID . ")
        ORDER BY `glpi_contracts`.`begin_date`, `glpi_contracts`.`name`";
      $result = $DB->query($query);
      $number = $DB->numrows($result);

      if ($number) {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadre_me center'>";
         echo "<tr><th>" . __('Name') . "</th>";
         echo "<th>" . _x('phone', 'Number') . "</th>";
         echo "<th>" . __('Comments') . "</th>";
         if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
            echo "<th>" . __('Mode of management', 'manageentities') . "</th>";
            echo "<th>" . __('Type of service contract', 'manageentities') . "</th>";
         } else if ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
            echo "<th>" . __('Type of service contract', 'manageentities') . "</th>";
         }
         echo "<th>" . __('Used by default', 'manageentities') . "</th>";
         if ($this->canCreate() && sizeof($instID) == 1)
            echo "<th>&nbsp;</th>";
         echo "</tr>";

         $used = [];

         while ($data = $DB->fetchArray($result)) {
            $used[] = $data["contracts_id"];

            echo "<tr class='" . ($data["is_deleted"] == '1' ? "_2" : "") . "'>";
            echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.form.php?id=" . $data["contracts_id"] . "\">" . $data["name"] . "";
            if ($_SESSION["glpiis_ids_visible"] || empty($data["name"])) echo " (" . $data["contracts_id"] . ")";
            echo "</a></td>";
            echo "<td class='center'>" . $data["num"] . "</td>";
            echo "<td class='center'>" . nl2br($data["comment"]) . "</td>";
            if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
               echo "<td class='center'>" . self::getContractManagement($data["management"]) . "</td>";
               echo "<td class='center'>" . self::getContractType($data['contract_type']) . "</td>";
            } else if ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
               echo "<td class='center'></td>";
               //               echo "<td class='center'>".self::getContractType($data['contract_type'])."</td>";
            }
            echo "<td class='center'>";
            if (sizeof($instID) == 1) {
               if ($data["is_default"]) {
                  echo __('Yes');
               } else {
                  Html::showSimpleForm($CFG_GLPI['root_doc'] . '/plugins/manageentities/front/entity.php',
                                       'contractbydefault',
                                       __('No'),
                                       ['myid' => $data["myid"], 'entities_id' => $_SESSION["glpiactive_entity"]]);
               }
            } else {
               echo Dropdown::getYesNo($data["is_default"]);
            }
            echo "</td>";
            if ($this->canCreate() && sizeof($instID) == 1) {
               echo "<td class='center' class='tab_bg_2'>";

               Html::showSimpleForm($CFG_GLPI['root_doc'] . '/plugins/manageentities/front/entity.php',
                                    'deletecontracts',
                                    _x('button', 'Delete permanently'),
                                    ['id' => $data["myid"]]);
               echo "</td>";
            }
            echo "</tr>";

         }

         if ($this->canCreate() && sizeof($instID) == 1) {
            if ($config->fields['hourorday'] == PluginManageentitiesConfig::HOUR) {
               echo "<tr class='tab_bg_1'><td colspan='6' class='center'>";
            } else if ($config->fields['hourorday'] == PluginManageentitiesConfig::DAY && $config->fields['useprice'] == PluginManageentitiesConfig::PRICE) {
               echo "<tr class='tab_bg_1'><td colspan='5' class='center'>";
            } else {
               echo "<tr class='tab_bg_1'><td colspan='4' class='center'>";
            }
            echo "<input type='hidden' name='entities_id' value='" . $_SESSION["glpiactive_entity"] . "'>";
            Dropdown::show('Contract', ['name' => "contracts_id",
                                        'used' => $used]);
            echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/setup.templates.php?itemtype=Contract&add=1' target='_blank'><i title=\"" . _sx('button', 'Add') . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i></a>";
            echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"" . _sx('button', 'Add') . "\" class='submit'></td>";
            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();

      } else {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadrehov center'>";
         echo "<tr><th colspan='3'>" . __('Associated assistance contracts', 'manageentities') . ":</th></tr>";
         echo "<tr><th>" . __('Name') . "</th>";
         echo "<th>" . _x('phone', 'Number') . "</th>";
         echo "<th>" . __('Comments') . "</th>";

         echo "</tr>";
         if ($this->canCreate()) {
            echo "<tr class='tab_bg_1'><td class='center'>";
            echo "<input type='hidden' name='entities_id' value=" . $_SESSION["glpiactive_entity"] . ">";
            Dropdown::show('Contract', ['name' => "contracts_id"]);
            echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/setup.templates.php?itemtype=Contract&add=1' target='_blank'>
            <i title=\"" . _sx('button', 'Add') . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i></a>";
            echo "</td><td class='center'><input type='submit' name='addcontracts' value=\"" . _sx('button', 'Add') . "\" class='submit'>";
            echo "</td><td></td>";
            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();
      }
   }

   /**
    * Dropdown list contract management
    *
    * @param type $name
    * @param type $value
    * @param type $rand
    *
    * @return boolean
    */
   static function dropdownContractManagement($name, $value = 0, $rand = null) {
      $contractManagements = [self::MANAGEMENT_NONE      => Dropdown::EMPTY_VALUE,
                              self::MANAGEMENT_QUARTERLY => __('Quarterly', 'manageentities'),
                              self::MANAGEMENT_ANNUAL    => __('Annual', 'manageentities')];

      if (!empty($contractManagements)) {
         if ($rand == null) {
            return Dropdown::showFromArray($name, $contractManagements, ['value' => $value]);
         } else {
            return Dropdown::showFromArray($name, $contractManagements, ['value' => $value, 'rand' => $rand]);
         }
      } else {
         return false;
      }
   }

   /**
    * Return the name of contract management
    *
    * @param type $value
    *
    * @return string
    */
   static function getContractManagement($value) {
      switch ($value) {
         case self::MANAGEMENT_NONE :
            return Dropdown::EMPTY_VALUE;
         case self::MANAGEMENT_QUARTERLY :
            return __('Quarterly', 'manageentities');
         case self::MANAGEMENT_ANNUAL :
            return __('Annual', 'manageentities');
         default :
            return "";
      }
   }

   /**
    * dropdown list of the types of contract
    *
    * @param type $name
    * @param type $value
    * @param type $rand
    * @param type $on_change
    *
    * @return boolean
    */
   static function dropdownContractType($name, $value = 0, $rand = null) {
      $config = PluginManageentitiesConfig::getInstance();


         $contractTypes = self::get_contract_type();


      if (!empty($contractTypes)) {
         if ($rand == null) {
            return Dropdown::showFromArray($name, $contractTypes, ['value' => $value]);
         } else {
            return Dropdown::showFromArray($name, $contractTypes, ['value' => $value, 'rand' => $rand]);
         }
      } else {
         return false;
      }
   }





   static function checkRemainingOpenContractDays($contracts_id) {
      global $DB;

      $query = "SELECT count(*) as count
                FROM `glpi_plugin_manageentities_contractdays`
                LEFT JOIN `glpi_plugin_manageentities_contractstates`
                    ON (`glpi_plugin_manageentities_contractdays`.`plugin_manageentities_contractstates_id` = `glpi_plugin_manageentities_contractstates`.`id`)
                WHERE `glpi_plugin_manageentities_contractdays`.`contracts_id` = " . $contracts_id . " 
                AND `glpi_plugin_manageentities_contractstates`.`is_active` = 1";

      $result = $DB->query($query);
      while ($data = $DB->fetchArray($result)) {
         if ($data['count'] > 0) {
            return true;
         }
      }

      return false;
   }

   static function get_contract_type($type = 0) {
      $list_types = [];
      $list_types[self::CONTRACT_POINTS] = __('Points','manageentities');
      $list_types[self::CONTRACT_UNLIMITED] = __('Unlimited','manageentities');

      if($type == 0) {
         return $list_types;
      } else {
         return $list_types[$type];
      }
   }

   static function getDefaultValues() {
      return [
         'renewal_number' => 0,
         'initial_credit' => 0,
         'current_credit' => 0,
         'credit_consumed' => 0,
         'contact_email' => '',
         'contract_cancelled' => 0,
         'contract_type' => 1,
      ];
   }

   function getEmpty() {
      parent::getEmpty();
      $this->fields['current_credit'] = 0;
   }

   /**
    * Displaying message solution if the ticket is link to a JIRA ticket
    *
    * @param $params
    *
    * @return bool
    */
   static function messageTask($params) {

      if (isset($params['item'])) {
         $item = $params['item'];
         $cridetail = new PluginManageentitiesCriDetail();
         $contractpoints= new PluginManageentitiesContractpoint();
         if ($item->getType() == TicketTask::getType()) {
            if ($_POST['parenttype'] == Ticket::getType() && !$cridetail->getFromDBByCrit(['tickets_id' =>$_POST['tickets_id']])){
               $text = __("No contract link to this ticket",'manageentities');
               echo "<tr class='tab_bg_1 warning'><td colspan='4'><i class='fas fa-exclamation-triangle fa-2x'></i> $text</td></tr>";
            } else if ($_POST['parenttype'] == Ticket::getType() &&
                       $cridetail->getFromDBByCrit(['tickets_id' =>$_POST['tickets_id']]) &&
                       $contractpoints->getFromDBByCrit(['contracts_id' => $cridetail->fields['contracts_id']]) &&
                       $contractpoints->fields['contract_cancelled'] == 1 &&
                       $contractpoints->fields['current_credit'] < $contractpoints->fields['threshold']){
               $text = sprintf(__("The contract is cancelled and the current credit is under %s",'manageentities'),$contractpoints->fields['threshold']);
               echo "<tr class='tab_bg_1 warning'><td colspan='4'><i class='fas fa-exclamation-triangle fa-2x'></i> $text</td></tr>";
            }

         }

      }
      return true;
   }

    /**
     * @param $name
     *
     * @return array
     */
    static function cronInfo($name) {
        switch ($name) {
            case 'AutoReport':
                return [
                    'description' => __('Auto intervention report', 'manageentities')]; // Optional
                break;

        }
        return [];
    }

   /**
    * Cron action on review : alert group supervisors if a review is planned
    *
    * @param CronTask $task for log, display information if NULL? (default NULL)
    *
    * @return void
    **/
   static function cronAutoReport($task = null) {

       global $CFG_GLPI;
        if (!$CFG_GLPI["notifications_mailing"]) {
           return 0;
        }

      $config = PluginManageentitiesConfig::getInstance();
      if($config->fields['hourorday'] != PluginManageentitiesConfig::POINTS ) {
         return true;
      }
//      if($config->fields['date_to_generate_contract'] != date('d') ) {
//         return true;
//      }

      $begin_date =  date('Y-m-d', strtotime('first day of last month'));
      $end_date =  date('Y-m-d', strtotime('last day of last month'))." 23:59:59";
      $month_number = date('n', strtotime('last day of last month'));
      $year = date('Y', strtotime('last day of last month'));

      $cron_status  = 0;
      $contract = new PluginManageentitiesContractpoint();
      $contract_object = new Contract();
      $entity = new Entity();
      $contracts = $contract->find();
      $task = new TicketTask();
      $solution = new ITILSolution();
      $ticket = new Ticket();
      foreach ($contracts as $data) {
         $totalpoint_on_contract = 0;
         $cridetail = new PluginManageentitiesCriDetail();
         $cridetails = $cridetail->find(['contracts_id' => $data['contracts_id']]);
         $contract_object->getFromDB($data['contracts_id']);
         $entity->getFromDB($contract_object->getEntityID());
         $pdf = new GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);


         $pdf->SetCreator('GLPI');
         $pdf->SetAuthor('GLPI');
         $pdf->SetTitle("");
         $pdf->SetHeaderData('', '', "", '');
         $font = 'helvetica';
         //$subsetting = true;
         $fontsize = 10;
         if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
            $font = $_SESSION['glpipdffont'];
            //$subsetting = false;
         }
         $pdf->setHeaderFont([$font, 'B', $fontsize]);
         $pdf->setFooterFont([$font, 'B', $fontsize]);

         //set margins
         $pdf->SetMargins(10, 15, 10);
         $pdf->SetHeaderMargin(10);
         $pdf->SetFooterMargin(10);

         //set auto page breaks
         $pdf->SetAutoPageBreak(true, 15);
         $month = Toolbox::getMonthsOfYearArray();
         // For standard language
         //$pdf->setFontSubsetting($subsetting);
         // set font
         $pdf->SetFont($font, '', $fontsize);
         $pdf->AddPage();
         $img = $config->getField('picture_logo');
         $footer = $config->getField('footer');

         if(!empty($data['picture_logo'])) {
            $img = $data['picture_logo'];
         }
         if(!empty($data['footer'])) {
            $footer = $data['footer'];
         }
         $html = " <header >
            <div style=\" text-align: left;\">";
         $dataimg = "";
         if(is_file(GLPI_PICTURE_DIR . '/'.$img)) {
            $dataimg = base64_encode(file_get_contents(GLPI_PICTURE_DIR . '/'.$img));
         }


         $html .= '<img width="200px" height="100px" src="@' . $dataimg . '">';
        $html .="    </div>
    
        </header>
        <br>
        <div id=\"client\" style=\" text-align: right;padding-bottom: 20px\">
        ".$entity->getFriendlyName()."<br>
         ".$entity->getField('address')."<br>
         ".$entity->getField('postcode')." ".$entity->getField('town')."<br><br>

      
        </div>
           <table style=\"margin:auto;  \">
             
                  <tr style=\" text-align: center;font-weight: bold; font-size: 12px \">
                        <td colspan=\"2\" style=\"border: 1px solid black;\">".__("Statement of intervention tickets","manageentities")."</td>
                        
                        <td style=\" border: 1px solid black;\">".$month[$month_number]." ".$year."</td>
                    </tr>
            
            </table>
            <br> 
            <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
             .__("Point balance :",'manageentities')." ".$data['current_credit']." <br>
           
              
              </div>
            <table style=\"margin:auto;\">
             <thead >
                  <tr>
                        <td style=\"border: 1px solid black;\">".__('Date')."</td>
                        <td colspan=\"3\" style=\"border: 1px solid black;\">".__('Client request, action and solve','manageentities')."</td>
                        <td style=\"border: 1px solid black;\">".__('Time','manageentities')."</td>
                        
                    </tr>
            </thead>
                <tbody>";



         $sumpoints = 0;

         foreach ($cridetails as $cri) {
            $ticket->getFromDB($cri['tickets_id']);
            $tasks = $task->find(['tickets_id' => $cri['tickets_id'],['date'          => ['>=', $begin_date]],
                                  ['date'          => ['<=', $end_date]]]);
            $is_sol = false;
            $solution->getEmpty();
            if(!in_array($ticket->getField('status'),Ticket::getNotSolvedStatusArray() )) {
               $is_sol =  $solution->getFromDBByCrit(['itemtype' => Ticket::getType(),'items_id' => $cri['tickets_id'],
                                                      'status' => CommonITILValidation::ACCEPTED,['date_creation'          => ['>=', $begin_date]],
                                                      ['date_creation'          => ['<=', $end_date]]]);
            }
            $count = ((count($tasks) ?? 0) + (($is_sol == true) ? 1:0));
            $compteur = 0;
            if($count != 0) {
               $count++;
               $html .= "<tr>";
               $html .= "<td rowspan=\"$count\" style=\"border: 1px solid black;text-align: center; vertical-align: middle;\">";
               $html .= Html::convDate($ticket->fields['date']);
               $html .= "<br>";
               $html .= sprintf(__('Ticket n° %s','manageentities'),"<br> ".$cri['tickets_id']);
               $html .= "</td>";

               $html .= "<td colspan=\"4\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($ticket->fields['content']));
               $html .= "</td>";

               $html .= "</tr>";
            }


            foreach ($tasks as $t) {
               $totalpoint_on_contract += PluginManageentitiesMappingCategorySlice::getPoints($data['id'],$t['taskcategories_id'],$t['actiontime']/60);

               $html .= "<tr>";


               $html .= "<td  style=\"border: 1px solid black;text-align: center\">";
               $html .= Html::convDate($t['date']);

               $html .= "</td>";
               $html .= "<td colspan=\"2\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($t['content']));
               $html .= "</td>";
               $html .= "<td  style=\"border: 1px solid black;\">";
               $html .= ($t['actiontime']/60)." "._n('minute', 'minutes', 2);
               $html .= "</td>";
               $html .= "</tr>";
               $compteur++;
            }

            if($solution->getID() != 0 && $solution->getID() != -1) {
               $html .= "<tr>";


               $html .= "<td colspan=\"4\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($solution->fields['content']));
               $html .= "</td>";

               $html .= "</tr>";
            }

//            $html .= "$count";
         }


         $renewal_number = 0;
         if((($rest = $data['current_credit'] - $totalpoint_on_contract) <= $data['threshold']) && $data['contract_cancelled'] == 0) {
            $rest;

            while($rest <= 0) {
               $rest += $data['initial_credit'];
               $renewal_number++;
            }
         }
         $info['id'] = $data['id'];
         $info['current_credit'] = $rest;
         $info['renewal_number'] = $data['renewal_number'] + $renewal_number;
         $info['credit_consumed'] = $data['credit_consumed'] + $totalpoint_on_contract;
         $contract->update($info);
         $html .="      </tbody>
            </table>

        
        <br>
      <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
                 .__("Total points counted :",'manageentities')." ".$totalpoint_on_contract." <br> <br>"
                 .__("Points remaining :",'manageentities')." ".$rest." <br> <br>"
                 .__("Number of renewal :",'manageentities')." ".$renewal_number." <br> <br>
      
           
              
              </div>
        ";
         $html .= "     
         <div id=\"footer\" style=\" text-align: center;padding-bottom: 20px; font-weight: bold;\">"
                  . nl2br($footer) . " <br> <br>
      
           
              
              </div>
        ";
         $pdf->setPrintFooter(false);
         $pdf->writeHTML($html, true, true, true, false, 'center');
//         $pdf->Output($entity->getFriendlyName()."-".$month[$month_number].$year.".pdf", 'D');
         $prefix = $entity->getID()."-".rand();
         $pdf->Output(GLPI_DOC_DIR."/_tmp/".$entity->getFriendlyName()."-".$month[$month_number].$year.".pdf", 'F');
         $document = new Document();
         $doc_id = $document->add(['_filename' => [$entity->getFriendlyName()."-".$month[$month_number].$year.".pdf"], 'entities_id' => $entity->getID() ]);


         $mmail = new GLPIMailer();
         $mmail->AddCustomHeader("Auto-Submitted: auto-generated");
         // For exchange
         $mmail->AddCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
         $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"], false);
         $subject = sprintf(__("Billinf of %s",'manageentities'),$month[$month_number].' '.$year);
         $user = new User();

         $user_email = $data['contact_email'];

         if(!empty($user_email)) {
            $mmail->AddAddress($user_email, $user_email);
            //     $mmail->isHTML(true);
            $mmail->Subject = $subject;
            //     $mmail->Body = $header.GLPIMailer::normalizeBreaks($body).$footer;
            $mmail->Body = sprintf(__("Please find attached billing of %s",'manageentities'),$month[$month_number].' '.$year);
            $mmail->MessageID = "GLPI-".Ticket::getType()."-".$ticket->getID().".".time().".".rand(). "@".php_uname('n');
            //
            $document->getFromDB($doc_id);
            $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
            $mmail->addAttachment(
               $path,
               $document->fields['filename']
            );
            if( $mmail->Send()) {
               Session::addMessageAfterRedirect(__('The email has been sent','manageentities'),false,INFO);
            } else {

               Session::addMessageAfterRedirect(__('Error sending email with document','manageentities'). "<br/>" . $mmail->ErrorInfo,false,ERROR);
//               Toolbox::logInFile('mail_manageentities',__('Error sending email with document','manageentities'). "<br/>" . $mmail->ErrorInfo,true);

            }
         } else {
            Session::addMessageAfterRedirect(__('Error sending email with document : No email','manageentities'),false,ERROR);
//            Toolbox::logInFile('mail_manageentities',__('Error sending email with document : No email','manageentities'),true);

         }

          if($renewal_number > 0 && !empty($config->getField('email_billing_destination'))) {
              $user_email = $config->getField('email_billing_destination');
              $mmail->AddAddress($user_email, $user_email);
              //     $mmail->isHTML(true);
              $mmail->Subject = $subject;
              //     $mmail->Body = $header.GLPIMailer::normalizeBreaks($body).$footer;
              $mmail->Body = sprintf(__("Please find attached billing of %s",'manageentities'),$month[$month_number].' '.$year);
              $mmail->MessageID = "GLPI-".Ticket::getType()."-".$ticket->getID().".".time().".".rand(). "@".php_uname('n');
              //
              $document->getFromDB($doc_id);
              $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
              $mmail->addAttachment(
                  $path,
                  $document->fields['filename']
              );
              if( $mmail->Send()) {
                  Session::addMessageAfterRedirect(__('The email has been sent','manageentities'),false,INFO);
              } else {

                  Session::addMessageAfterRedirect(__('Error sending email with document for renewal','manageentities'). "<br/>" . $mmail->ErrorInfo,false,ERROR);
//                  Toolbox::logInFile('mail_manageentities',__('Error sending email with document for renewal','manageentities'). "<br/>" . $mmail->ErrorInfo,true);

              }
          } else {
              Session::addMessageAfterRedirect(__('Error sending email with document : No email','manageentities'),false,ERROR);
              Toolbox::logInFile('mail_manageentities',__('Error sending email with document : No email','manageentities'),true);

          }
         //      print_r($html);
//         return true; //TODO delete
//         $message              = __('A new report has been created for the entity %1$s', 'manageentities');
//         if ($task) {
//            $task->log(sprintf($message."\n", $entity->getFriendlyName()));
//            $task->addVolume(1);
//         } else {
//            Session::addMessageAfterRedirect(sprintf($message,  $entity->getFriendlyName()));
//         }
      }

       $config = PluginManageentitiesConfig::getInstance();
      //SEND mail for task without contract

      $tasks = $task->find(['taskcategories_id' => $config->fields['category_outOfContract'],['date'          => ['>=', $begin_date]],
                            ['date'          => ['<=', $end_date]]],['date ASC','tickets_id ASC']);
      $entityReport = [];
      $tick = new Ticket();
      foreach ($tasks as $t) {
          $tick->getFromDB($t['tickets_id']);
         $entityReport[$tick->fields['entities_id']][$t['tickets_id']][] = $t;
      }

      foreach ($entityReport as $entity => $TicketsbyEntity) {
         $pdf = new GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);

         $entity->getFromDB($entity);

         $pdf->SetCreator('GLPI');
         $pdf->SetAuthor('GLPI');
         $pdf->SetTitle("");
         $pdf->SetHeaderData('', '', "", '');
         $font = 'helvetica';
         //$subsetting = true;
         $fontsize = 10;
         if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
            $font = $_SESSION['glpipdffont'];
            //$subsetting = false;
         }
         $pdf->setHeaderFont([$font, 'B', $fontsize]);
         $pdf->setFooterFont([$font, 'B', $fontsize]);

         //set margins
         $pdf->SetMargins(10, 15, 10);
         $pdf->SetHeaderMargin(10);
         $pdf->SetFooterMargin(10);

         //set auto page breaks
         $pdf->SetAutoPageBreak(true, 15);
         $month = Toolbox::getMonthsOfYearArray();
         // For standard language
         //$pdf->setFontSubsetting($subsetting);
         // set font
         $pdf->SetFont($font, '', $fontsize);
         $pdf->AddPage();
         $img = $config->getField('picture_logo');
         $footer = $config->getField('footer');

         if(!empty($data['picture_logo'])) {
            $img = $data['picture_logo'];
         }
         if(!empty($data['footer'])) {
            $footer = $data['footer'];
         }
         $html = " <header >
            <div style=\" text-align: left;\">";
         $dataimg = "";
         if(is_file(GLPI_PICTURE_DIR . '/'.$img)) {
            $dataimg = base64_encode(file_get_contents(GLPI_PICTURE_DIR . '/'.$img));
         }


         $html .= '<img width="200px" height="100px" src="@' . $dataimg . '">';
         $html .="    </div>
    
        </header>
        <br>
        <div id=\"client\" style=\" text-align: right;padding-bottom: 20px\">
        ".$entity->getFriendlyName()."<br>
         ".$entity->getField('address')."<br>
         ".$entity->getField('postcode')." ".$entity->getField('town')."<br><br>

      
        </div>
           <table style=\"margin:auto;  \">
             
                  <tr style=\" text-align: center;font-weight: bold; font-size: 12px \">
                        <td colspan=\"2\" style=\"border: 1px solid black;\">".__("Statement of intervention tickets","manageentities")."</td>
                        
                        <td style=\" border: 1px solid black;\">".$month[$month_number]." ".$year."</td>
                    </tr>
            
            </table>
            <br> 
            <table style=\"margin:auto;\">
             <thead >
                  <tr>
                        <td style=\"border: 1px solid black;\">".__('Date')."</td>
                        <td colspan=\"3\" style=\"border: 1px solid black;\">".__('Client request, action and solve','manageentities')."</td>
                        <td style=\"border: 1px solid black;\">".__('Time','manageentities')."</td>
                        
                    </tr>
            </thead>
          <tbody>";



         $sumpoints = 0;

         foreach ($TicketsbyEntity as $tickets_id => $tasks) {
            $ticket->getFromDB($tickets_id);
//            $tasks = $task->find(['tickets_id' => $cri['tickets_id'],['date'          => ['>=', $begin_date]],
//                                  ['date'          => ['<=', $end_date]]]);
            $is_sol = false;
            $solution->getEmpty();
            if(!in_array($ticket->getField('status'),Ticket::getNotSolvedStatusArray() )) {
               $is_sol =  $solution->getFromDBByCrit(['itemtype' => Ticket::getType(),'items_id' => $tickets_id,
                                                      'status' => CommonITILValidation::ACCEPTED,['date_creation'          => ['>=', $begin_date]],
                                                      ['date_creation'          => ['<=', $end_date]]]);
            }
            $count = ((count($tasks) ?? 0) + (($is_sol == true) ? 1:0));
            $compteur = 0;
            if($count != 0) {
               $count++;
               $html .= "<tr>";
               $html .= "<td rowspan=\"$count\" style=\"border: 1px solid black;text-align: center; vertical-align: middle;\">";
               $html .= Html::convDate($ticket->fields['date']);
               $html .= "<br>";
               $html .= sprintf(__('Ticket n° %s','manageentities'),"<br> ".$tickets_id);
               $html .= "</td>";

               $html .= "<td colspan=\"4\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($ticket->fields['content']));
               $html .= "</td>";

               $html .= "</tr>";
            }


            foreach ($tasks as $t) {
               $totaltime_on_contract += ($t['actiontime']/60);

               $html .= "<tr>";


               $html .= "<td  style=\"border: 1px solid black;text-align: center\">";
               $html .= Html::convDate($t['date']);

               $html .= "</td>";
               $html .= "<td colspan=\"2\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($t['content']));
               $html .= "</td>";
               $html .= "<td  style=\"border: 1px solid black;\">";
               $html .= ($t['actiontime']/60)." "._n('minute', 'minutes', 2);
               $html .= "</td>";
               $html .= "</tr>";
               $compteur++;
            }

            if($solution->getID() != 0 && $solution->getID() != -1) {
               $html .= "<tr>";


               $html .= "<td colspan=\"4\" style=\"border: 1px solid black;\">";
               $html .= nl2br(Html::clean($solution->fields['content']));
               $html .= "</td>";

               $html .= "</tr>";
            }

            //            $html .= "$count";
         }



         $html .="      </tbody>
            </table>

        
        <br>
      <div id=\"current_credit\" style=\" text-align: right;padding-bottom: 20px; font-weight: bold;\">"
                 .__("Total time counted :",'manageentities')." ".$totaltime_on_contract. " "._n('minute', 'minutes', 2)." <br> <br>
      
           
              
              </div>
        ";
         $html .= "     
         <div id=\"footer\" style=\" text-align: center;padding-bottom: 20px; font-weight: bold;\">"
                  . nl2br($footer) . " <br> <br>
      
           
              
              </div>
        ";
         $pdf->setPrintFooter(false);
         $pdf->writeHTML($html, true, true, true, false, 'center');
//         $pdf->Output($entity->getFriendlyName()."-".$month[$month_number].$year.".pdf", 'D');
         $prefix = $entity->getID()."-".rand();
         $pdf->Output(GLPI_DOC_DIR."/_tmp/".$entity->getFriendlyName()."-".$month[$month_number].$year.".pdf", 'F');
         $document = new Document();
         $doc_id = $document->add(['_filename' => [$entity->getFriendlyName()."-".$month[$month_number].$year.".pdf"], 'entities_id' => $entity->getID() ]);



         $mmail = new GLPIMailer();
         $mmail->AddCustomHeader("Auto-Submitted: auto-generated");
         // For exchange
         $mmail->AddCustomHeader("X-Auto-Response-Suppress: OOF, DR, NDR, RN, NRN");
         $mmail->SetFrom($CFG_GLPI["admin_email"], $CFG_GLPI["admin_email_name"], false);
         $subject = sprintf(__("Billinf of %s",'manageentities'),$month[$month_number].' '.$year);
         $user = new User();
          $config = PluginManageentitiesConfig::getInstance();
         $user_email = $config->fields['email_billing_destination'];
          Toolbox::logInFile('mail_manageentities',print_r($config->fields,true) . " debugggg",true);
         if(!empty($user_email)) {
            $mmail->AddAddress($user_email,$user_email);
            //     $mmail->isHTML(true);
            $mmail->Subject = $subject;
            //     $mmail->Body = $header.GLPIMailer::normalizeBreaks($body).$footer;
            $mmail->Body = sprintf(__("Please find attached billing of %s",'manageentities'),$month[$month_number].' '.$year);
            $mmail->MessageID = "GLPI-".Ticket::getType()."-".$ticket->getID().".".time().".".rand(). "@".php_uname('n');
            //
            $document->getFromDB($doc_id);
            $path = GLPI_DOC_DIR . "/" . $document->fields['filepath'];
            $mmail->addAttachment(
               $path,
               $document->fields['filename']
            );
            if( $mmail->Send()) {
               Session::addMessageAfterRedirect(__('The email has been sent','manageentities'),false,INFO);
            } else {

               Session::addMessageAfterRedirect(__('Error sending email with document','manageentities'). "<br/>" . $mmail->ErrorInfo,false,ERROR);
               Toolbox::logInFile('mail_manageentities',__('Error sending email with document','manageentities'). "<br/>" . $mmail->ErrorInfo,true);

            }
         } else {
            Session::addMessageAfterRedirect(__('Error sending email with document : No email','manageentities'),false,ERROR);
            Toolbox::logInFile('mail_manageentities',__('Error sending email with document : No email','manageentities'),true);

         }
         //      print_r($html);
         //         return true; //TODO delete
         //         $message              = __('A new report has been created for the entity %1$s', 'manageentities');
         //         if ($task) {
         //            $task->log(sprintf($message."\n", $entity->getFriendlyName()));
         //            $task->addVolume(1);
         //         } else {
         //            Session::addMessageAfterRedirect(sprintf($message,  $entity->getFriendlyName()));
         //         }

      }






      return $cron_status;
   }

}