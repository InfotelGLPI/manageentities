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

include("../../../inc/includes.php");
header("Content-Type: text/html; charset=UTF-8");
Html::header_nocache();
Session::checkCentralAccess();

if (!defined('GLPI_ROOT')) {
   die("Can not acces directly to this file");
}

$val = "";

$val         .= "<tr class='tab_bg_1' id='tr_add_contract' style='display:table-row; '>";
$val         .= "<td>";
$val         .= __("Add a document");
$val         .= "</td>";
$val         .= "<td colspan='5'>";
$root_manage = PLUGIN_MANAGEENTITIES_WEBDIR;
$val         .= "<a onclick=\"showFormAddPDFContract('$root_manage', '" . __("Add a document")
                . "','" . _sx('button', 'Add') . "','" . _sx('button', 'Cancel') . "');\" class='pointer'>";
$val         .= "<i class=\"ti ti-square-plus\" style=\"font-size:3em;\"></i></a>";
$val         .= "</td>";
$val         .= "</tr>";
$ele         = new PluginManageentitiesAddElementsView();
$val         .= $ele->showListPDFcontract(false);

$pModel     = PluginManageentitiesAddElementsModel::getInstance();
$srcImg     = "";
$alertTitle = "";

while (!isset($_SESSION["manageentities"]["add_doc_status"]["result"])) {

}
$infos = $_SESSION["manageentities"]["add_doc_status"];
unset($_SESSION["manageentities"]["add_doc_status"]);
$message = $infos["message"];
if ($infos["result"] == Status::ADDED) {
   $messageType = Messages::MESSAGE_INFO;
} else {
   $messageType = Messages::MESSAGE_ERROR;
}
switch ($messageType) {
   case Messages::MESSAGE_ERROR:
      $srcImg     = "ti ti-alert-triangle";
      $color      = "orange";
      $alertTitle = $pModel->getMessage("message_error");
      break;
   case Messages::MESSAGE_INFO:
   default:
      $srcImg     = "ti ti-info-circle";
      $color      = "forestgreen";
      $alertTitle = $pModel->getMessage("message_info");
      break;
}
//$this->showHeaderJS();

//$val.= " if ($('#alert-message').val()){
//               $('#alert-message').val('');
//            }";
//$val .= Html::scriptBlock(" if ($('#alert-message').val()){
//               $('#alert-message').val('');
//            }");
////$this->closeFormJS();

//$val.= "<div id='alert-message' class='tab_cadre_navigation_center' style='display:none;'>" . $message . "</div>";

//$this->showHeaderJS();
$val .= Html::scriptBlock("
$( \"body\" ).append(\"<div id='alert-message' class='tab_cadre_navigation_center' style='display:none;'> $message </div>\");
var mTitle =  \"<i class='" . $srcImg . "' style='color:" . $color . "'></i>&nbsp;" . $alertTitle . " \";
$( '#alert-message' ).dialog({
        autoOpen: false,
        height: " . 150 . ",
        width: " . 300 . ",
        modal: true,
        open: function (){
         $(this)
            .parent()
            .children('.ui-dialog-titlebar')
            .html(mTitle);
      },
        buttons: {
         'ok': function() {
            $( this ).dialog( 'close' );
         }
      },
      beforeClose: function(event) {
            $('#alert-message').remove();
            return false;
      }
    });
    $('#alert-message').dialog('open');");


//$this->closeFormJS();
echo $val;
