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

$title = "Portail entités";

$LANG['plugin_manageentities']['title'][1] = "".$title."";
$LANG['plugin_manageentities']['title'][2] = "Génération du rapport d'intervention";
$LANG['plugin_manageentities']['title'][3] = "Détail du contrat";
$LANG['plugin_manageentities']['title'][4] = "Type de gestion";
$LANG['plugin_manageentities']['title'][5] = "Période du contrat";

$LANG['plugin_manageentities'][1] = "Portail";
$LANG['plugin_manageentities'][2] = "Etat du contrat";
$LANG['plugin_manageentities'][3] = "Contrats d'assistance associés";
//4
$LANG['plugin_manageentities'][5] = "Interventions associées";
//$LANG['plugin_manageentities'][5] = "Tickets associés en cours";
$LANG['plugin_manageentities'][6] = "Type d'intervention par défaut";
$LANG['plugin_manageentities'][7] = "Contacts associés";
$LANG['plugin_manageentities'][8] = "A l'intervention";
$LANG['plugin_manageentities'][9] = "Liste complète";
$LANG['plugin_manageentities'][10] = "Lancer le plugin ".$title." au démarrage de GLPI";
$LANG['plugin_manageentities'][11] = "Attention : si plusieurs plugins sont lancés au démarrage, seul le premier sera actif";
$LANG['plugin_manageentities'][12] = "Responsable";
$LANG['plugin_manageentities'][13] = "Utilisé par défaut";
$LANG['plugin_manageentities'][14] = "Type d'intervention";
$LANG['plugin_manageentities'][15] = "Tarif Journalier";
$LANG['plugin_manageentities'][16] = "Nombre de jours acheté";
$LANG['plugin_manageentities'][17] = "jours";
$LANG['plugin_manageentities'][18] = "Forfait Annuel Garanti";
$LANG['plugin_manageentities'][19] = "Total restant (montant)";
$LANG['plugin_manageentities'][20] = "Nombre de jours restant";
$LANG['plugin_manageentities'][21] = "Tarif Journalier appliqué";
$LANG['plugin_manageentities'][22] = "Décompte";
$LANG['plugin_manageentities'][23] = "Total consommation annuelle";
$LANG['plugin_manageentities'][24] = "Estimation Nombre de jours restant";
$LANG['plugin_manageentities'][25] = "Trimestriel";
$LANG['plugin_manageentities'][26] = "Annuel";
$LANG['plugin_manageentities'][27] = "Périodes du contrat";
$LANG['plugin_manageentities'][28] = "heures";
$LANG['plugin_manageentities'][29] = "interventions";
$LANG['plugin_manageentities'][30] = "Illimité";
$LANG['plugin_manageentities'][31] = "Interventions traitées";
$LANG['plugin_manageentities'][32] = "Interventions à venir";
$LANG['plugin_manageentities'][33] = "Action impossible car un rapport d'intervention existe";

$LANG['plugin_manageentities']['onglet'][0] = "Suivi général";
$LANG['plugin_manageentities']['onglet'][1] = "Données administratives";
//$LANG['plugin_manageentities']['onglet'][1] = "Description";
$LANG['plugin_manageentities']['onglet'][2] = "Planning client";
//$LANG['plugin_manageentities']['onglet'][2] = "Tickets en cours";
$LANG['plugin_manageentities']['onglet'][3] = "Rapports d'interventions";
$LANG['plugin_manageentities']['onglet'][4] = "Documents";
$LANG['plugin_manageentities']['onglet'][5] = "Contrats";
$LANG['plugin_manageentities']['onglet'][6] = "Applications Web";
$LANG['plugin_manageentities']['onglet'][7] = "Comptes";

$LANG['plugin_manageentities']['profile'][0] = "Gestion des droits";

$LANG['plugin_manageentities']['taskcategory'][0] = "Gestion des catégories de tâches";
$LANG['plugin_manageentities']['taskcategory'][1] = "Utiliser pour le calcul des CRI";

$LANG['plugin_manageentities']['contract'][0] = "Date de signature";
$LANG['plugin_manageentities']['contract'][1] = "Date de reconduction";
$LANG['plugin_manageentities']['contract'][2] = "Mode de gestion";
$LANG['plugin_manageentities']['contract'][3] = "Type de contrat de service";

$LANG['plugin_manageentities']['contractday'][0] = "Ajouter une période de contrat";
$LANG['plugin_manageentities']['contractday'][1] = "Report";
$LANG['plugin_manageentities']['contractday'][2] = "Date de début";
$LANG['plugin_manageentities']['contractday'][3] = "Date de fin";
$LANG['plugin_manageentities']['contractday'][4] = "Crédit initial";
$LANG['plugin_manageentities']['contractday'][5] = "Total consommé";
$LANG['plugin_manageentities']['contractday'][6] = "Total restant";
$LANG['plugin_manageentities']['contractday'][7] = "Total dépassement";
$LANG['plugin_manageentities']['contractday'][8] = "Objet de l'intervention";
$LANG['plugin_manageentities']['contractday'][9] = "Consommation";
$LANG['plugin_manageentities']['contractday'][10] = "Type de contrat de service manquant";
$LANG['plugin_manageentities']['contractday'][11] = "Ticket non validé";

$LANG['plugin_manageentities']['follow-up'][0] = "Client";
$LANG['plugin_manageentities']['follow-up'][1] = "Dernière visite";
$LANG['plugin_manageentities']['follow-up'][2] = "Total crédit initial";

$LANG['plugin_manageentities']['infoscompreport'][0] = "Intervention sous contrat";
$LANG['plugin_manageentities']['infoscompreport'][2] = "Détail des travaux réalisés";
$LANG['plugin_manageentities']['infoscompreport'][3] = "Enregistrer le compte-rendu d'intervention";
$LANG['plugin_manageentities']['infoscompreport'][4] = "Intervenants";
$LANG['plugin_manageentities']['infoscompreport'][5] = "Ajouter un intervenant";
$LANG['plugin_manageentities']['infoscompreport'][6] = "Merci d'assigner un technicien au ticket";

$LANG['plugin_manageentities']['report'][0] = "Rapport d'intervention";
$LANG['plugin_manageentities']['report'][4] = "du";
$LANG['plugin_manageentities']['report'][5] = "au";
$LANG['plugin_manageentities']['report'][6] = "Ticket associé";
$LANG['plugin_manageentities']['report'][7] = "Tous";

$LANG['plugin_manageentities']['infoscompactivitesreport'][0] = "Intervention urgente";
$LANG['plugin_manageentities']['infoscompactivitesreport'][1] = "Intervention planifiée";
$LANG['plugin_manageentities']['infoscompactivitesreport'][2] = "Étude et conseil";

$LANG['plugin_manageentities']['cri'][0] = "Rapports d'intervention associés";
$LANG['plugin_manageentities']['cri'][1] = "Créé le";
$LANG['plugin_manageentities']['cri'][2] = "à";
$LANG['plugin_manageentities']['cri'][3] = "n°";
$LANG['plugin_manageentities']['cri'][4] = "N° de demande de support associée";
$LANG['plugin_manageentities']['cri'][5] = "Intervenant";
$LANG['plugin_manageentities']['cri'][6] = "Date d'intervention";
$LANG['plugin_manageentities']['cri'][7] = "Année";
$LANG['plugin_manageentities']['cri'][8] = "Mois";
$LANG['plugin_manageentities']['cri'][9] = "Du";
$LANG['plugin_manageentities']['cri'][10] = "Au";
$LANG['plugin_manageentities']['cri'][11] = "Nom de la société";
$LANG['plugin_manageentities']['cri'][12] = "Ville";
$LANG['plugin_manageentities']['cri'][13] = "Responsable";
$LANG['plugin_manageentities']['cri'][14] = "Type de contrat";
$LANG['plugin_manageentities']['cri'][15] = "Assistance sur contrat";
$LANG['plugin_manageentities']['cri'][16] = "N° du contrat";
$LANG['plugin_manageentities']['cri'][17] = "Hors contrat";
$LANG['plugin_manageentities']['cri'][18] = "Temps passé (trajet inclus)";
$LANG['plugin_manageentities']['cri'][19] = "";
$LANG['plugin_manageentities']['cri'][20] = "Date de";
$LANG['plugin_manageentities']['cri'][21] = "Heure de";
$LANG['plugin_manageentities']['cri'][22] = "Temps passé";
$LANG['plugin_manageentities']['cri'][23] = "début";
$LANG['plugin_manageentities']['cri'][24] = "fin";
$LANG['plugin_manageentities']['cri'][25] = "(en jours)";
$LANG['plugin_manageentities']['cri'][26] = "Total (en jours)";
$LANG['plugin_manageentities']['cri'][27] = "Détail des travaux réalisés";
$LANG['plugin_manageentities']['cri'][28] = "Observations du client";
$LANG['plugin_manageentities']['cri'][29] = "Cachet client";
$LANG['plugin_manageentities']['cri'][30] = "Visa client";
$LANG['plugin_manageentities']['cri'][31] = "Page";
$LANG['plugin_manageentities']['cri'][32] = "sur";
$LANG['plugin_manageentities']['cri'][33] = "Adresse de mon entreprise";
$LANG['plugin_manageentities']['cri'][34] = "Fin de l'adresse";
$LANG['plugin_manageentities']['cri'][35] = "Compte rendu";
$LANG['plugin_manageentities']['cri'][36] = "d'intervention";
$LANG['plugin_manageentities']['cri'][37] = "Merci de sélectionner un type d'intervention";
$LANG['plugin_manageentities']['cri'][38] = "Génération impossible, aucune tâche planifiée créée";
$LANG['plugin_manageentities']['cri'][39] = "Aperçu";
$LANG['plugin_manageentities']['cri'][40] = "CRI créé avec succès. Vous pouvez fermer la fenêtre.";
$LANG['plugin_manageentities']['cri'][41] = "Interventions de la période du contrat";
$LANG['plugin_manageentities']['cri'][42] = "Génération impossible, le ticket n'est pas accepté";
$LANG['plugin_manageentities']['cri'][43] = "(en heures)";
$LANG['plugin_manageentities']['cri'][44] = "Total (en heures)";
$LANG['plugin_manageentities']['cri'][45] = "Associer à un contrat";

$LANG['plugin_manageentities']['setup'][0] = "Options";
$LANG['plugin_manageentities']['setup'][1] = "Nombre d'heures par jour";
$LANG['plugin_manageentities']['setup'][2] = "Rubrique par défaut pour les rapports";
$LANG['plugin_manageentities']['setup'][3] = "Configuration journalière ou horaire";
$LANG['plugin_manageentities']['setup'][4] = "Tarif Journalier";
$LANG['plugin_manageentities']['setup'][5] = "Détermination du solde initial";
$LANG['plugin_manageentities']['setup'][6] = "Journalier";
$LANG['plugin_manageentities']['setup'][7] = "Horaire";
$LANG['plugin_manageentities']['setup'][8] = "Seuls les tickets avec la validation « acceptée » sont concernés pour le calcul des consommés";
$LANG['plugin_manageentities']['setup'][9] = "Utilisation de la tarification";
$LANG['plugin_manageentities']['setup'][10] = "Sauvegarder les rapports dans glpi";
$LANG['plugin_manageentities']['setup'][11] = "Détail de la configuration";
$LANG['plugin_manageentities']['setup'][12] = "Seules les tâches publiques sont visibles sur le CRI";

?>