# Documentation — Plugin Manageentities pour GLPI

**Licence :** GNU GPL v3+  
**Auteur :** Infotel (Xavier CAILLAUD)  
**Dépôt :** https://github.com/InfotelGLPI/manageentities

---

## Table des matières

1. [Présentation](#présentation)
2. [Installation](#installation)
3. [Configuration globale](#configuration-globale)
4. [Fonctionnalités](#fonctionnalités)
   - [Portail entité](#portail-entité)
   - [Suivi général](#suivi-général)
   - [Suivi mensuel](#suivi-mensuel)
   - [GANTT](#gantt)
   - [Données administratives](#données-administratives)
   - [Contrats et périodes](#contrats-et-périodes)
   - [Rapports d'intervention (CRI)](#rapports-dintervention-cri)
   - [Interventions non facturées (Direct Helpdesk)](#interventions-non-facturées-direct-helpdesk)
   - [Documents et comptes](#documents-et-comptes)
   - [Références](#références)
   - [Rapports de gestion](#rapports-de-gestion)
   - [Sociétés (Companies)](#sociétés-companies)
   - [Tableau de bord](#tableau-de-bord)
5. [Gestion des droits](#gestion-des-droits)
6. [Intégrations](#intégrations)
7. [Désinstallation](#désinstallation)

---

## Présentation

Le plugin **Manageentities** (anciennement *manageentity*) est un portail de gestion des entités clientes dans GLPI. Il permet de :

- Centraliser les informations administratives, contacts, contrats et documents par entité
- Créer et suivre des **rapports d'intervention (CRI)** liés aux tickets, exportables en PDF
- Gérer le **solde contractuel** (jours ou heures) et son décompte au fil des interventions
- Suivre l'activité par entité via des vues **suivi général**, **suivi mensuel** et **diagramme GANTT**
- Enregistrer des **interventions non facturées** (direct helpdesk)
- Générer des **rapports de gestion** (déplacements de techniciens, taux d'occupation)
- Afficher un **tableau de bord** par entité

---

## Installation

1. Télécharger le plugin depuis [GitHub](https://github.com/InfotelGLPI/manageentities) ou la marketplace GLPI.
2. Décompresser l'archive dans le dossier `plugins/` (ou `marketplace/`) de votre installation GLPI.
3. Exécuter `composer install --no-dev` dans le dossier du plugin.
4. Se connecter à GLPI en tant qu'administrateur.
5. Aller dans **Configuration › Plugins**, cliquer sur **Installer** puis **Activer** pour *Entities portal*.

> **Note :** Le plugin peut être configuré pour se lancer automatiquement au démarrage de GLPI (redirection vers le portail entité dès la connexion).

---

## Configuration globale

Accès : **Gestion › Clients management › Configuration**  
(Droit requis : `UPDATE` sur `plugin_manageentities`)

| Paramètre | Description |
|-----------|-------------|
| **Sauvegarder les rapports dans GLPI** | Enregistre les CRI générés comme documents GLPI dans la catégorie configurée |
| **Rubrique par défaut pour les rapports** | Catégorie de document utilisée pour les CRI sauvegardés |
| **Utiliser les prix** | Active la saisie d'un tarif sur les périodes de contrat |
| **Configuration journalière ou horaire** | Mode de calcul des périodes : `Journalier` (demi-journées) ou `Horaire` |
| **Seules les tâches publiques sont visibles** | Filtre les tâches des tickets dans les CRI pour n'afficher que les tâches publiques |
| **Autoriser les périodes sur le même intervalle** | Autorise la création de périodes de contrat aux dates identiques |
| **Vue client (interface simplifiée)** | Définit ce que voit le client dans l'onglet interventions : `Rapports d'intervention` ou `Périodes de contrat` |
| **Utiliser les souscriptions éditeur** | Active la gestion des souscriptions éditeur (valeur par défaut : Oui). Sur `Non`, masque l'onglet « Souscriptions éditeurs » et l'onglet « État des lieux » de la fiche entité, les blocs et alertes de souscription des onglets ticket et contrat, la carte « Nouvelle souscription » du portail assistant, ainsi que l'étape « Souscription » de l'assistant de création de client (interdit également l'accès direct au formulaire de souscription) |
| **Statuts de contrat affichés dans le suivi général** | Sélection multiple des statuts inclus dans la vue suivi général |
| **Business list par défaut (suivi général)** | Contacts business affichés par défaut dans la vue suivi général |
| **Afficher les commentaires société dans le CRI** | Inclut le commentaire de la société dans le PDF du CRI |
| **Utiliser les tâches non accomplies** | Inclut les tâches non terminées dans le formulaire de génération de CRI |
| **Afficher le PDF** | Ouvre automatiquement le PDF lors de la génération d'un CRI |
| **État du ticket créé** | Statut appliqué au ticket lors de la création d'un CRI via le formulaire |
| **Durée par défaut** | Durée préremplie dans le formulaire CRI |
| **Heure par défaut (matin)** | Heure de début de la demi-journée matin dans les CRI |
| **Heure par défaut (après-midi)** | Heure de début de la demi-journée après-midi dans les CRI |
| **Désactiver la date de création dans l'en-tête PDF** | Cache la date de génération dans l'en-tête du PDF |

> **Attention :** Changer le mode journalier/horaire impacte les types de contrat existants.

---

## Fonctionnalités

### Portail entité

Accès : **Gestion › Clients management** (interface centrale) ou menu latéral (interface simplifiée)

Le portail entité est la page principale du plugin. Pour chaque entité GLPI active, elle regroupe tous les onglets décrits ci-dessous. Il est possible de configurer une redirection automatique vers ce portail au démarrage de GLPI.

---

### Suivi général

**Onglet 1 — Suivi général**

Vue agrégée de l'activité de l'entité, filtrable par période et paramètres. Affiche les tickets, les périodes de contrat et le solde consommé. Les statuts de contrat affichés sont configurables (voir Configuration globale).

---

### Suivi mensuel

**Onglet 2 — Suivi mensuel** *(interface centrale uniquement)*

Vue mois par mois de l'activité : nombre d'interventions, durées, répartition par catégorie ou technicien.

---

### GANTT

**Onglet 3 — GANTT**

Diagramme de Gantt des périodes de contrat de l'entité, visualisant le planning des interventions sur une timeline.

---

### Données administratives

**Onglet 4 — Données administratives**

Fiche récapitulative de l'entité GLPI avec :
- **Logo** de l'entité (format JPG/JPEG, téléversable depuis l'interface centrale)
- Nom complet, téléphone, fax, site web, e-mail, adresse postale complète
- Commentaires
- **Contacts** associés (dont le responsable)
- **Business contacts** (interlocuteurs commerciaux)

---

### Contrats et périodes

**Onglet 5 — Contrats**

Gestion des contrats de service associés à l'entité. Chaque contrat comporte :

| Champ | Description |
|-------|-------------|
| **Type de gestion** | Mode de suivi : Néant, Trimestriel, Annuel |
| **Type de contrat** | Selon le mode (horaire ou journalier) : À terme, Forfait, Heures, Interventions, Illimité |
| **Date de signature** | Date de démarrage contractuelle |
| **Date de renouvellement** | Date d'échéance du contrat |
| **Contrat additionnel** | Indique un avenant |
| **Coûts refacturables** | Active la refacturation des coûts |

**Périodes de contrat (`ContractDay`)** : chaque contrat peut avoir plusieurs périodes définissant le solde de jours/heures disponibles, avec :
- Dates de début et fin
- Solde initial (nombre de jours ou d'heures)
- **Intervenants** (`InterventionSkateholder`) : liste des techniciens autorisés sur la période
- **Tarifs** (`CriPrice`) : tarifs par type d'intervention pour la période

Le solde est automatiquement décrémenté à chaque CRI validé.

---

### Rapports d'intervention (CRI)

**Onglet 7 — Interventions reports** *(ou Périodes de contrat selon la configuration)*  
Droit requis : `plugin_manageentities_cri_create`

Un **CRI (Compte Rendu d'Intervention)** est un rapport d'intervention associé à un ticket GLPI, généré en **PDF** via FPDF.

**Génération d'un CRI :**
1. Depuis l'onglet interventions d'un ticket (ou depuis **Outils › Generate CRI**)
2. Sélectionner l'entité, la période de contrat, le type d'intervention
3. Renseigner les informations : date, technicien, durée (matin/après-midi ou heures)
4. Le CRI est généré en PDF et peut être sauvegardé comme document GLPI

**Contenu du CRI PDF :**
- En-tête avec logo de l'entité et informations de contact
- Détail des tâches du ticket (publiques uniquement si configuré)
- Informations sur la période contractuelle et le solde restant
- Commentaires société (si configuré)
- Signature technicien

**Accès rapide depuis le ticket :** un onglet **Intervention report** apparaît directement sur la fiche ticket pour créer un CRI sans quitter le ticket.

---

### Interventions non facturées (Direct Helpdesk)

Accès : **Outils › Not billed interventions** (interface centrale et helpdesk)

Permet d'enregistrer des interventions qui ne sont pas rattachées à un ticket facturable. Chaque intervention non facturée peut ultérieurement être associée à un ticket.

Champs principaux :
- Date et durée (1h, 2h, 3h)
- Catégorie ITIL
- Description
- Indicateur **est facturée** (passage en facturé lors de l'association à un ticket)

---

### Documents et comptes

**Onglet 8 — Documents**

Liste les documents GLPI associés à l'entité, avec possibilité d'upload.

> Les CRI sauvegardés apparaissent ici dans la catégorie de document configurée. La date de modification d'un document CRI est préservée lors des mises à jour pour éviter de masquer les dates d'intervention réelles.

**Onglet 10 — Accounts** *(si le plugin Accounts est actif)*

Liste les comptes du plugin Accounts associés à l'entité.

---

### Références

**Onglet 11 — Références** *(interface centrale uniquement)*

Liste les entités clientes avec lesquelles un contrat a été signé, regroupées par année de signature, avec le logo de chaque entité.

---

### Rapports de gestion

Accès : **Outils › Rapports**

Trois rapports sont disponibles :

| Rapport | Description |
|---------|-------------|
| **Rapports d'intervention** | Synthèse des CRI par entité, période, technicien |
| **Rapport sur les déplacements** | Synthèse des déplacements de techniciens entre entités sur une période |
| **Rapport d'occupation** | Taux d'occupation des techniciens sur une période donnée |

---

### Sociétés (Companies)

Accès : **Gestion › Clients management › Sociétés**

Gestion des sociétés partenaires ou clientes du parc. Une société peut avoir des documents associés.

---

### Tableau de bord

Le plugin expose un **widget de tableau de bord** (`Dashboard`) dans le tableau de bord GLPI (via l'hook `mydashboard`), affichant des indicateurs de suivi de l'entité active.

---

## Gestion des droits

Accès : **Administration › Profils › [profil] › onglet Entities portal**

| Droit | Champ | Description |
|-------|-------|-------------|
| **Portail entités** | `plugin_manageentities` | Accès complet au portail : lecture, création, modification, suppression |
| **Rapports d'intervention** | `plugin_manageentities_cri_create` | Création et consultation des CRI |

À l'installation, le profil Super-Admin reçoit tous les droits.

---

## Intégrations

| Plugin | Description |
|--------|-------------|
| **accounts** | Onglet Accounts dans le portail entité pour consulter les comptes associés |
| **servicecatalog** | Intégration dans le catalogue de services de l'interface simplifiée |
| **datainjection** | Import de données via fichier CSV |
| **activity** | Les événements de planning du plugin activity sont visibles dans le planning GLPI |

---

## Désinstallation

1. Aller dans **Configuration › Plugins**.
2. Cliquer sur **Désactiver** puis **Désinstaller** pour *Entities portal*.

> **Attention :** La désinstallation supprime toutes les tables du plugin (contrats, périodes, CRI, contacts business, etc.) et les données associées.
