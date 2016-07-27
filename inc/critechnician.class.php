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

class PluginManageentitiesCriTechnician extends CommonDBTM {

   function checkIfTechnicianExists($ID) {
      global $DB;
    
      $result = $DB->query("SELECT `id`
                FROM `".$this->getTable()."`
                WHERE `tickets_id` = '".$ID."' ");
      if ($DB->numrows($result) > 0)
        return $DB->result($result,0,"id");
      else
        return 0;
   }

   function addDefaultTechnician($user_id,$ID) {
  
      $input["users_id"]=$user_id;
      $input["tickets_id"]=$ID;

      return $this->add($input);
   }
}

?>