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

$title = "Entities portal";

$LANG['plugin_manageentities']['title'][1] = "".$title."";
$LANG['plugin_manageentities']['title'][2] = "Generation of the intervention report";
$LANG['plugin_manageentities']['title'][3] = "Contract detail";
$LANG['plugin_manageentities']['title'][4] = "Type of management";
$LANG['plugin_manageentities']['title'][5] = "Period of contract";

$LANG['plugin_manageentities'][1] = "Portal";
$LANG['plugin_manageentities'][2] = "State of contract";
$LANG['plugin_manageentities'][3] = "Associated assistance contracts";
//4
$LANG['plugin_manageentities'][5] = "Associated interventions";
//$LANG['plugin_manageentities'][5] = "Associated tickets in progress";
$LANG['plugin_manageentities'][6] = "Intervention type by default";
$LANG['plugin_manageentities'][7] = "Associated contacts";
$LANG['plugin_manageentities'][8] = "By intervention";
$LANG['plugin_manageentities'][9] = "All reports";
$LANG['plugin_manageentities'][10] = "Launch the plugin ".$title." with GLPI launching";
$LANG['plugin_manageentities'][11] = "Warning : If there are more than one plugin which be loaded at startup, then only the first will be used";
$LANG['plugin_manageentities'][12] = "Manager";
$LANG['plugin_manageentities'][13] = "Used by default";
$LANG['plugin_manageentities'][14] = "Intervention type";
$LANG['plugin_manageentities'][15] = "Daily rate";
$LANG['plugin_manageentities'][16] = "Number of bought days";
$LANG['plugin_manageentities'][17] = "days";
$LANG['plugin_manageentities'][18] = "Guaranteed yearly package ";
$LANG['plugin_manageentities'][19] = "Remaining total (amount)";
$LANG['plugin_manageentities'][20] = "Number of remaining days";
$LANG['plugin_manageentities'][21] = "Applied daily rate";
$LANG['plugin_manageentities'][22] = "To compute";
$LANG['plugin_manageentities'][23] = "Total yearly consumption";
$LANG['plugin_manageentities'][24] = "Estimated number of remaining days";
$LANG['plugin_manageentities'][25] = "Quarterly";
$LANG['plugin_manageentities'][26] = "Annual";
$LANG['plugin_manageentities'][27] = "Periods of contract";
$LANG['plugin_manageentities'][28] = "hours";
$LANG['plugin_manageentities'][29] = "interventions";
$LANG['plugin_manageentities'][30] = "Unlimited";
$LANG['plugin_manageentities'][31] = "Processed interventions";
$LANG['plugin_manageentities'][32] = "To be processed interventions";
$LANG['plugin_manageentities'][33] = "Impossible action as an intervention report exist";

$LANG['plugin_manageentities']['onglet'][0] = "General follow-up";
$LANG['plugin_manageentities']['onglet'][1] = "Data administrative";
//$LANG['plugin_manageentities']['onglet'][1] = "Description";
$LANG['plugin_manageentities']['onglet'][2] = "Client planning";
//$LANG['plugin_manageentities']['onglet'][2] = "Tickets in progress";
$LANG['plugin_manageentities']['onglet'][3] = "Interventions reports";
$LANG['plugin_manageentities']['onglet'][4] = "Documents";
$LANG['plugin_manageentities']['onglet'][5] = "Contracts";
$LANG['plugin_manageentities']['onglet'][6] = "Web Applications";
$LANG['plugin_manageentities']['onglet'][7] = "Accounts";

$LANG['plugin_manageentities']['profile'][0] = "Rights management";

$LANG['plugin_manageentities']['taskcategory'][0] = "Management of task category";
$LANG['plugin_manageentities']['taskcategory'][1] = "Use for calculation of intervention report";

$LANG['plugin_manageentities']['contract'][0] = "Date of signature";
$LANG['plugin_manageentities']['contract'][1] = "Date of renewal";
$LANG['plugin_manageentities']['contract'][2] = "Mode of management";
$LANG['plugin_manageentities']['contract'][3] = "Type of service contract";

$LANG['plugin_manageentities']['contractday'][0] = "Add a period of contract";
$LANG['plugin_manageentities']['contractday'][1] = "Report";
$LANG['plugin_manageentities']['contractday'][2] = "Date of begin";
$LANG['plugin_manageentities']['contractday'][3] = "Date of end";
$LANG['plugin_manageentities']['contractday'][4] = "Initial credit";
$LANG['plugin_manageentities']['contractday'][5] = "Total consummated";
$LANG['plugin_manageentities']['contractday'][6] = "Total remaining";
$LANG['plugin_manageentities']['contractday'][7] = "Total exceeding";
$LANG['plugin_manageentities']['contractday'][8] = "Object of intervention";
$LANG['plugin_manageentities']['contractday'][9] = "Consumption";
$LANG['plugin_manageentities']['contractday'][10] = "Type of service contract missing";
$LANG['plugin_manageentities']['contractday'][11] = "Ticket not validated";

$LANG['plugin_manageentities']['follow-up'][0] = "Client";
$LANG['plugin_manageentities']['follow-up'][1] = "Last visit";
$LANG['plugin_manageentities']['follow-up'][2] = "Total initial credit";

$LANG['plugin_manageentities']['infoscompreport'][0] = "Intervention with contract";
$LANG['plugin_manageentities']['infoscompreport'][2] = "Detail of the realized works";
$LANG['plugin_manageentities']['infoscompreport'][3] = "Save the intervention report";
$LANG['plugin_manageentities']['infoscompreport'][4] = "Technicians";
$LANG['plugin_manageentities']['infoscompreport'][5] = "Add a technician";
$LANG['plugin_manageentities']['infoscompreport'][6] = "Thanks to assign a technician to the ticket";

$LANG['plugin_manageentities']['report'][0] = "Intervention report";
$LANG['plugin_manageentities']['report'][4] = "from";
$LANG['plugin_manageentities']['report'][5] = "to";
$LANG['plugin_manageentities']['report'][6] = "Associated ticket";
$LANG['plugin_manageentities']['report'][7] = "All";

$LANG['plugin_manageentities']['infoscompactivitesreport'][0] = "Urgent intervention";
$LANG['plugin_manageentities']['infoscompactivitesreport'][1] = "Scheduled intervention";
$LANG['plugin_manageentities']['infoscompactivitesreport'][2] = "Study and advice";

$LANG['plugin_manageentities']['cri'][0] = "Associated intervention reports";
$LANG['plugin_manageentities']['cri'][1] = "Created by";
$LANG['plugin_manageentities']['cri'][2] = "in";
$LANG['plugin_manageentities']['cri'][3] = "number";
$LANG['plugin_manageentities']['cri'][4] = "requests number of associated help";
$LANG['plugin_manageentities']['cri'][5] = "Technician";
$LANG['plugin_manageentities']['cri'][6] = "Intervention date";
$LANG['plugin_manageentities']['cri'][7] = "Year";
$LANG['plugin_manageentities']['cri'][8] = "Month";
$LANG['plugin_manageentities']['cri'][9] = "From";
$LANG['plugin_manageentities']['cri'][10] = "To";
$LANG['plugin_manageentities']['cri'][11] = "Society name";
$LANG['plugin_manageentities']['cri'][12] = "Town";
$LANG['plugin_manageentities']['cri'][13] = "Person in charge";
$LANG['plugin_manageentities']['cri'][14] = "Contract type";
$LANG['plugin_manageentities']['cri'][15] = "Help on contract";
$LANG['plugin_manageentities']['cri'][16] = "Contract number";
$LANG['plugin_manageentities']['cri'][17] = "Out of contract";
$LANG['plugin_manageentities']['cri'][18] = "Crossed time (itinerary including)";
$LANG['plugin_manageentities']['cri'][19] = "Wording of the activities";
$LANG['plugin_manageentities']['cri'][20] = "Date of";
$LANG['plugin_manageentities']['cri'][21] = "Hour of";
$LANG['plugin_manageentities']['cri'][22] = "Crossed time";
$LANG['plugin_manageentities']['cri'][23] = "begin";
$LANG['plugin_manageentities']['cri'][24] = "end";
$LANG['plugin_manageentities']['cri'][25] = "(in days)";
$LANG['plugin_manageentities']['cri'][26] = "Total (in days)";
$LANG['plugin_manageentities']['cri'][27] = "Detail of work done";
$LANG['plugin_manageentities']['cri'][28] = "Customer comments";
$LANG['plugin_manageentities']['cri'][29] = "Customer stamp";
$LANG['plugin_manageentities']['cri'][30] = "Customer Visa";
$LANG['plugin_manageentities']['cri'][31] = "Page";
$LANG['plugin_manageentities']['cri'][32] = "on";
$LANG['plugin_manageentities']['cri'][33] = "My society address";
$LANG['plugin_manageentities']['cri'][34] = "End of address";
$LANG['plugin_manageentities']['cri'][35] = "Report";
$LANG['plugin_manageentities']['cri'][36] = "of this intervention";
$LANG['plugin_manageentities']['cri'][37] = "Thanks to select a intervention type";
$LANG['plugin_manageentities']['cri'][38] = "Impossible generation, you didn't create a scheduled task";
$LANG['plugin_manageentities']['cri'][39] = "Preview";
$LANG['plugin_manageentities']['cri'][40] = "Intervention report created with success. You can close the windows.";
$LANG['plugin_manageentities']['cri'][41] = "Interventions of period of contract";
$LANG['plugin_manageentities']['cri'][42] = "Impossible generation, ticket is not accepted";
$LANG['plugin_manageentities']['cri'][43] = "(in hours)";
$LANG['plugin_manageentities']['cri'][44] = "Total (in hours)";
$LANG['plugin_manageentities']['cri'][45] = "Associate to a contract";

$LANG['plugin_manageentities']['setup'][0] = "Options";
$LANG['plugin_manageentities']['setup'][1] = "Number of hours by day";
$LANG['plugin_manageentities']['setup'][2] = "Rubric by default for reports";
$LANG['plugin_manageentities']['setup'][3] = "Configuration daily or hourly";
$LANG['plugin_manageentities']['setup'][4] = "Daily rate";
$LANG['plugin_manageentities']['setup'][5] = "Determination of the initial balance";
$LANG['plugin_manageentities']['setup'][6] = "Daily";
$LANG['plugin_manageentities']['setup'][7] = "Hourly";
$LANG['plugin_manageentities']['setup'][8] = "Only ticket accepted are taking into account for consumption calculation";
$LANG['plugin_manageentities']['setup'][9] = "Use of price";
$LANG['plugin_manageentities']['setup'][10] = "Save reports in glpi";
$LANG['plugin_manageentities']['setup'][11] = "Detail of configuration";
$LANG['plugin_manageentities']['setup'][12] = "Only public task are visible on intervention report";

?>