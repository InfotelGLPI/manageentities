<?php

include('../../../inc/includes.php');
Session::checkLoginUser();

$PluginManageentitiesGenerateCri = new PluginManageentitiesGenerateCri();
$PluginManageentitiesCri         = new PluginManageentitiesCri();

if (isset($_POST['generatecri'])) {
   if (Session::haveRight('ticket', CREATE)) {

      $ko = $PluginManageentitiesGenerateCri->checkMandatoryFields($_POST);
      if (!$ko) {
         $ticket_id = $PluginManageentitiesGenerateCri->createTicketAndAssociateContract($_POST);
         if ($ticket_id) {
            $PluginManageentitiesGenerateCri->createTasks($_POST, $ticket_id);
            $PluginManageentitiesGenerateCri->generateCri($_POST, $ticket_id, $PluginManageentitiesCri);
         }
      }

      Html::back();

   } else {
      Html::displayRightError();
   }

} else {
   Html::header(PluginManageentitiesGenerateCRI::getMenuName(0), '', "assets", PluginManageentitiesGenerateCRI::getType());
   $ticket = new Ticket();
   $ticket->fields['itilcategories_id'] = isset($_REQUEST['itilcategories_id']) ? $_REQUEST['itilcategories_id'] : 0;
   $ticket->fields['type'] = isset($_REQUEST['type']) ? $_REQUEST['type'] : '';
   $PluginManageentitiesGenerateCri->showWizard($ticket, $_SESSION['glpiactive_entity']);
   Html::footer();

}

if (Session::getCurrentInterface() == 'central') {
   Html::footer();
} else {
   Html::helpFooter();
}