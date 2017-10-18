<?php
/*
 -------------------------------------------------------------------------
 Manageentities plugin for GLPI
 Copyright (C) 2003-2012 by the Manageentities Development Team.

 https://forge.indepnet.net/projects/manageentities
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

class PluginManageentitiesContractState extends CommonDropdown {

   static $rightname = 'plugin_manageentities';
   
   static function getTypeName($nb=0) {
      return _n('State of contract', 'States of contracts', $nb, 'manageentities');
   }
   
   static function canView() {
      return Session::haveRight(self::$rightname, READ);
   }

   static function canCreate() {
      return Session::HaveRightsOr(self::$rightname, array(CREATE, UPDATE, DELETE));
   }

   function getAdditionalFields() {
      return array( array( 'name'  => 'is_active',
                           'label' => __('Active'),
                           'type'  => 'bool'),
                    array( 'name'  => 'is_closed',
                           'label' => __('Closed'),
                           'type'  => 'bool'),
                    array( 'name'  => 'color',
                           'label' => __('Color','manageentities'),
                           'type'  => 'text'),
      );
   }

   function getSearchOptions() {
      $tab = parent::getSearchOptions();

      $tab[14]['table']         = $this->getTable();
      $tab[14]['field']         = 'is_active';
      $tab[14]['name']          = __('Active');
      $tab[14]['datatype']      = 'bool';

      $tab[15]['table']         = $this->getTable();
      $tab[15]['field']         = 'is_closed';
      $tab[15]['name']          = __('Closed');
      $tab[15]['datatype']      = 'bool';
      
      $tab[16]['table']         = $this->getTable();
      $tab[16]['field']         = 'color';
      $tab[16]['name']          = __('Color','manageentities');
      $tab[16]['datatype']      = 'string';

      return $tab;
   }
   
   public function prepareInputForAdd($input) {
      return $this->checkColor($input);
   }
   
   public function prepareInputForUpdate($input) {
      return $this->checkColor($input);
   }
   
   function checkColor($input){
      if(!preg_match('/#([a-f]|[A-F]|[0-9]){3}(([a-f]|[A-F]|[0-9]){3})?\b/', $input['color'])){
         Session::addMessageAfterRedirect(__('Color field is not correct', 'manageentities'), true, ERROR);
         return array();
      }
      return  $input;
   }
   
   static function getOpenedStates(){
      $out = array();
      $dbu = new DbUtils();
      $data = $dbu->getAllDataFromTable('glpi_plugin_manageentities_contractstates', "`is_active` = 1");
      if(!empty($data)){
         foreach($data as $val){
            $out[] = $val['id'];
         }
      }
      
      return $out;
   }
}

?>