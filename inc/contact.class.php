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

class PluginManageentitiesContact extends CommonDBTM {

   static $rightname = 'plugin_manageentities';

   static function canView() {
      return Session::haveRight(self::$rightname, READ);
   }

   static function canCreate() {
      return Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, DELETE]);
   }

   /**
    * Add a contact ba default
    *
    * @param type  $contacts_id
    * @param type  $entities_id
    *
    * @global type $DB
    *
    */
   function addContactByDefault($contacts_id, $entities_id) {

      global $DB;

      $query  = "SELECT *
        FROM `" . $this->getTable() . "`
        WHERE `entities_id` = '" . $entities_id . "' ";
      $result = $DB->doQuery($query);
      $number = $DB->numrows($result);

      if ($number) {
         while ($data = $DB->fetchArray($result)) {

            $query_nodefault  = "UPDATE `" . $this->getTable() . "`
            SET `is_default` = 0 WHERE `id` = '" . $data["id"] . "' ";
            $result_nodefault = $DB->doQuery($query_nodefault);
         }
      }

      $query_default  = "UPDATE `" . $this->getTable() . "`
        SET `is_default` = 1 WHERE `id` ='" . $contacts_id . "' ";
      $result_default = $DB->doQuery($query_default);
   }

   /**
    *
    * @param type  $instID
    *
    * @global type $CFG_GLPI
    *
    * @global type $DB
    */
   function showContacts($instID) {
      global $DB, $CFG_GLPI;

      $entitiesId = "'" . implode("', '", $instID) . "'";
      $query      = "SELECT `glpi_contacts`.*, `" . $this->getTable() . "`.`id` as contacts_id, `" . $this->getTable() . "`.`is_default`
        FROM `" . $this->getTable() . "`, `glpi_contacts`
        WHERE `" . $this->getTable() . "`.`contacts_id`=`glpi_contacts`.`id`
        AND `glpi_contacts`.`is_deleted` = 0
        AND `" . $this->getTable() . "`.`entities_id` IN ($entitiesId)
        ORDER BY `glpi_contacts`.`name`";

      $result = $DB->doQuery($query);
      $number = $DB->numrows($result);

      if ($number) {
         echo "<form method='post' action=\"./entity.php\">";
         echo "<div align='center'><table class='tab_cadre_me center'>";
         echo "<tr><th colspan='6'>";
         echo "<h3><div class='alert alert-secondary' role='alert'>";
         echo _n('Associated contact', 'Associated contacts', 2);
         echo "</div></h3>";
         echo "</th></tr>";
         echo "<tr><th>" . __('Name') . "</th>";
         echo "<th>" . __('Phone') . "</th>";
         echo "<th>" . __('Mobile phone') . "</th>";
         echo "<th>" . __('Email address') . "</th>";
         echo "<th>" . __('Type') . "</th>";
         if ($this->canCreate() && sizeof($instID) == 1)
            echo "<th>&nbsp;</th>";
         echo "</tr>";

         while ($data = $DB->fetchArray($result)) {
            $ID = $data["contacts_id"];
            echo "<tr class='tab_bg_1'>";
            echo "<td class='left'><a href='" . $CFG_GLPI["root_doc"] . "/front/contact.form.php?id=" . $data["id"] . "'>" . $data["name"] . " " . $data["firstname"] . "</a></td>";
            echo "<td class='center'>" . $data["phone"] . "</td>";
            echo "<td class='center'>" . $data["mobile"] . "</td>";
            echo "<td class='center'><a href='mailto:" . $data["email"] . "'>" . $data["email"] . "</a></td>";
            echo "<td class='center'>" . Dropdown::getDropdownName("glpi_contacttypes", $data["contacttypes_id"]) . "<br>";
            if (sizeof($instID) == 1
                && Session::getCurrentInterface() != 'helpdesk') {
               if ($data["is_default"]) {
                  echo __('Manager');
               } else {
                  Html::showSimpleForm(PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                                       'contactbydefault',
                                       __('Manager'),
                                       ['contacts_id' => $ID, 'entities_id' => $_SESSION["glpiactive_entity"]]);
               }
            } else {
               if ($data["is_default"]) {
                  echo __('Manager');
               }
            }
            echo "</td>";

            if ($this->canCreate() && sizeof($instID) == 1) {
               echo "<td class='center' class='tab_bg_2'>";
               Html::showSimpleForm(PLUGIN_MANAGEENTITIES_WEBDIR . '/front/entity.php',
                                    'deletecontacts',
                                    _x('button', 'Delete permanently'),
                                    ['id' => $ID],
                                    'fa-times-circle');
               echo "</td>";
            }
            echo "</tr>";

         }

         if ($this->canCreate() && sizeof($instID) == 1) {
            echo "<tr class='tab_bg_1'><td colspan='5' class='center'>";
            echo Html::hidden('entities_id', ['value' => $_SESSION["glpiactive_entity"]]);
            $rand = Dropdown::show('Contact', ['name' => "contacts_id"]);
            echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/contact.form.php' target='_blank'>";
            echo "<i title=\"" . _sx('button', 'Add') . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i>";
            echo "</a>";
            echo "</td><td class='center'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'addcontacts', 'class' => 'btn btn-primary']);
            echo "</td>";
            echo "</tr>";
         }
         echo "</table></div>";
         Html::closeForm();

      } else {

         if ($this->canCreate() && sizeof($instID) == 1) {
            echo "<form method='post' action=\"./entity.php\">";
            echo "<div align='center'><table class='tab_cadre_me center'>";
            echo "<tr><th colspan='6'>";
            echo "<h3><div class='alert alert-secondary' role='alert'>";
            echo _n('Associated contact', 'Associated contacts', 2);
            echo "</div></h3>";
            echo "</th></tr>";

            echo "<tr><td class='tab_bg_2 center'>";
            echo Html::hidden('entities_id', ['value' => $_SESSION["glpiactive_entity"]]);
            Dropdown::show('Contact', ['name' => "contacts_id"]);
            echo "<a href='" . $CFG_GLPI['root_doc'] . "/front/contact.form.php' target='_blank'>";
            echo "<i title=\"" . _sx('button', 'Add') . "\" class=\"far fa-plus-square\" style='cursor:pointer; margin-left:2px;'></i>";
            echo "</a>";
            echo "</td><td class='center tab_bg_2'>";
            echo Html::submit(_sx('button', 'Add'), ['name' => 'addcontacts', 'class' => 'btn btn-primary']);
            echo "</td></tr>";

            echo "</table></div>";
            Html::closeForm();
         }
      }
   }
}
