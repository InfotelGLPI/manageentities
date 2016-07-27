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

$title = "Portale Entit&agrave";

$LANG['plugin_manageentities']['title'][1] = "".$title."";
$LANG['plugin_manageentities']['title'][2] = "Generazione del report di intervento";
$LANG['plugin_manageentities']['title'][3] = "Dettagli contratto";
$LANG['plugin_manageentities']['title'][4] = "Type of management";
$LANG['plugin_manageentities']['title'][5] = "Period of contract";

$LANG['plugin_manageentities'][1] = "Portale";
$LANG['plugin_manageentities'][2] = "State of contract";
$LANG['plugin_manageentities'][3] = "Contratti di assistenza associati";
//4
$LANG['plugin_manageentities'][5] = "Associated interventions";
//$LANG['plugin_manageentities'][5] = "Chiamate associate in lavorazione";
$LANG['plugin_manageentities'][6] = "Intervention type by default";
$LANG['plugin_manageentities'][7] = "Contatti associati";
$LANG['plugin_manageentities'][8] = "By intervention";
$LANG['plugin_manageentities'][9] = "Tutti i report";
$LANG['plugin_manageentities'][10] = "Lancia il plugin ".$title." all'avvio di GLPI";
$LANG['plugin_manageentities'][11] = "Attenzione : Se ci sono pi� di un plugin caricati all'avvio, solo il primo sar&agrave utilizzato";
$LANG['plugin_manageentities'][12] = "Manager";
$LANG['plugin_manageentities'][13] = "Utilizzato per default";
$LANG['plugin_manageentities'][14] = "Tipo di intervento";
$LANG['plugin_manageentities'][15] = "Tariffa giornaliera";
$LANG['plugin_manageentities'][16] = "Numero di giorni acquistati";
$LANG['plugin_manageentities'][17] = "giorni";
$LANG['plugin_manageentities'][18] = "Pacchetto Annuale Garantito";
$LANG['plugin_manageentities'][19] = "Totale rimanente (amount)";
$LANG['plugin_manageentities'][20] = "Numero di giorni rimanenti";
$LANG['plugin_manageentities'][21] = "Tariffa giornaliera applicata";
$LANG['plugin_manageentities'][22] = "Computare";
$LANG['plugin_manageentities'][23] = "Consumo annuale totale";
$LANG['plugin_manageentities'][24] = "Stima dei giorni rimanenti";
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
//$LANG['plugin_manageentities']['onglet'][1] = "Descrizione";
$LANG['plugin_manageentities']['onglet'][2] = "Client planning";
//$LANG['plugin_manageentities']['onglet'][2] = "Chiamate in lavorazione";
$LANG['plugin_manageentities']['onglet'][3] = "Rapporti di intervento";
$LANG['plugin_manageentities']['onglet'][4] = "Documenti";
$LANG['plugin_manageentities']['onglet'][5] = "Contratti";
$LANG['plugin_manageentities']['onglet'][6] = "Applicazioni Web";
$LANG['plugin_manageentities']['onglet'][7] = "Account";

$LANG['plugin_manageentities']['profile'][0] = "Gestione Permessi";

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

$LANG['plugin_manageentities']['infoscompreport'][0] = "Intervento a contratto";
$LANG['plugin_manageentities']['infoscompreport'][2] = "Dettagli del lavoro realizzato";
$LANG['plugin_manageentities']['infoscompreport'][3] = "Salva il rapporto di intervento";
$LANG['plugin_manageentities']['infoscompreport'][4] = "Tecnici";
$LANG['plugin_manageentities']['infoscompreport'][5] = "Aggiungi un tecnico";
$LANG['plugin_manageentities']['infoscompreport'][6] = "Grazie per aver assegnato un tecnico aalla chiamata";

$LANG['plugin_manageentities']['report'][0] = "Rapporto di intervento";
$LANG['plugin_manageentities']['report'][4] = "da";
$LANG['plugin_manageentities']['report'][5] = "a";
$LANG['plugin_manageentities']['report'][6] = "Chiamate associate";
$LANG['plugin_manageentities']['report'][7] = "Tutti";

$LANG['plugin_manageentities']['infoscompactivitesreport'][0] = "Intervento urgente";
$LANG['plugin_manageentities']['infoscompactivitesreport'][1] = "Intervento programmato";
$LANG['plugin_manageentities']['infoscompactivitesreport'][2] = "Studio e consulenza";

$LANG['plugin_manageentities']['cri'][0] = "Rapporti di intervento associati";
$LANG['plugin_manageentities']['cri'][1] = "Creato da";
$LANG['plugin_manageentities']['cri'][2] = "in";
$LANG['plugin_manageentities']['cri'][3] = "numero";
$LANG['plugin_manageentities']['cri'][4] = "Numero della chiamata di riferimento";
$LANG['plugin_manageentities']['cri'][5] = "Tecnico";
$LANG['plugin_manageentities']['cri'][6] = "Data di intervento";
$LANG['plugin_manageentities']['cri'][7] = "Anno";
$LANG['plugin_manageentities']['cri'][8] = "Mese";
$LANG['plugin_manageentities']['cri'][9] = "Da";
$LANG['plugin_manageentities']['cri'][10] = "A";
$LANG['plugin_manageentities']['cri'][11] = "Ragione Sociale";
$LANG['plugin_manageentities']['cri'][12] = "Citta'";
$LANG['plugin_manageentities']['cri'][13] = "Persona in carico";
$LANG['plugin_manageentities']['cri'][14] = "Tipo di Contratto";
$LANG['plugin_manageentities']['cri'][15] = "Assistenza a contratto";
$LANG['plugin_manageentities']['cri'][16] = "Contratto n.ro";
$LANG['plugin_manageentities']['cri'][17] = "Fuori contratto";
$LANG['plugin_manageentities']['cri'][18] = "Tempo trascorso (dall'apertura della chiamata)";
$LANG['plugin_manageentities']['cri'][19] = "Tipo di attivita'";
$LANG['plugin_manageentities']['cri'][20] = "Data di ";
$LANG['plugin_manageentities']['cri'][21] = "Ora di ";
$LANG['plugin_manageentities']['cri'][22] = "Tempo impiegato";
$LANG['plugin_manageentities']['cri'][23] = "inizio";
$LANG['plugin_manageentities']['cri'][24] = "fine";
$LANG['plugin_manageentities']['cri'][25] = "(in giorni)";
$LANG['plugin_manageentities']['cri'][26] = "Totale (in giorni)";
$LANG['plugin_manageentities']['cri'][27] = "Dettagli del lavoro realizzato";
$LANG['plugin_manageentities']['cri'][28] = "Commenti del cliente";
$LANG['plugin_manageentities']['cri'][29] = "Timbro del cliente";
$LANG['plugin_manageentities']['cri'][30] = "Visto del cliente";
$LANG['plugin_manageentities']['cri'][31] = "Pagina";
$LANG['plugin_manageentities']['cri'][32] = "di";
$LANG['plugin_manageentities']['cri'][33] = "Indirizzo proprio";
$LANG['plugin_manageentities']['cri'][34] = "Fine dell'indirizzo";
$LANG['plugin_manageentities']['cri'][35] = "Rapporto";
$LANG['plugin_manageentities']['cri'][36] = "dell'intervento:";
$LANG['plugin_manageentities']['cri'][37] = "Grazie per aver selezionato un tipo di intervento";
$LANG['plugin_manageentities']['cri'][38] = "Generazione impossibile, non vi &egrave alcun incarico assegnato per questa chiamata";
$LANG['plugin_manageentities']['cri'][39] = "Anteprima";
$LANG['plugin_manageentities']['cri'][40] = "Rapporto di intervento creato con successo. Puoi chiudere la finestra.";
$LANG['plugin_manageentities']['cri'][41] = "Interventions of period of contract";
$LANG['plugin_manageentities']['cri'][42] = "Impossible generation, ticket is not accepted";
$LANG['plugin_manageentities']['cri'][43] = "(in hours)";
$LANG['plugin_manageentities']['cri'][44] = "Total (in hours)";
$LANG['plugin_manageentities']['cri'][45] = "Associate to a contract";

$LANG['plugin_manageentities']['setup'][0] = "Opzioni";
$LANG['plugin_manageentities']['setup'][1] = "Numero di ore al giorno";
$LANG['plugin_manageentities']['setup'][2] = "Rubric by default for reports";
$LANG['plugin_manageentities']['setup'][3] = "Configuration daily or hourly";
$LANG['plugin_manageentities']['setup'][4] = "Tariffa giornaliera";
$LANG['plugin_manageentities']['setup'][5] = "Determinazione del saldo iniziale";
$LANG['plugin_manageentities']['setup'][6] = "Daily";
$LANG['plugin_manageentities']['setup'][7] = "Hourly";
$LANG['plugin_manageentities']['setup'][8] = "Only ticket accepted are taking into account for consumption calculation";
$LANG['plugin_manageentities']['setup'][9] = "Use of price";
$LANG['plugin_manageentities']['setup'][10] = "Salva i rapporti in glpi";
$LANG['plugin_manageentities']['setup'][11] = "Detail of configuration";
$LANG['plugin_manageentities']['setup'][12] = "Only public task are visible on intervention report";

?>