# Documentation — Manageentities Plugin for GLPI

**License:** GNU GPL v3+  
**Author:** Infotel (Xavier CAILLAUD)  
**Repository:** https://github.com/InfotelGLPI/manageentities

---

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Global Configuration](#global-configuration)
4. [Features](#features)
   - [Entity portal](#entity-portal)
   - [General follow-up](#general-follow-up)
   - [Monthly follow-up](#monthly-follow-up)
   - [GANTT](#gantt)
   - [Administrative data](#administrative-data)
   - [Contracts and periods](#contracts-and-periods)
   - [Publisher subscriptions](#publisher-subscriptions)
   - [Status overview](#status-overview)
   - [Intervention reports (CRI)](#intervention-reports-cri)
   - [Not billed interventions (Direct Helpdesk)](#not-billed-interventions-direct-helpdesk)
   - [Documents and accounts](#documents-and-accounts)
   - [References](#references)
   - [Management reports](#management-reports)
   - [Companies](#companies)
   - [Dashboard](#dashboard)
5. [Rights management](#rights-management)
6. [Integrations](#integrations)
7. [Uninstallation](#uninstallation)

---

## Overview

The **Manageentities** plugin (formerly *manageentity*) is a client entity management portal for GLPI. It allows you to:

- Centralize administrative information, contacts, contracts, and documents per entity
- Create and track **intervention reports (CRI)** linked to tickets, exportable as PDF
- Manage **contract balances** (days or hours) and their drawdown over interventions
- Monitor entity activity through **general follow-up**, **monthly follow-up**, and **GANTT** views
- Record **non-billed interventions** (direct helpdesk)
- Generate **management reports** (technician movement, occupation rate)
- Display a per-entity **dashboard widget**

---

## Installation

1. Download the plugin from [GitHub](https://github.com/InfotelGLPI/manageentities) or the GLPI marketplace.
2. Extract the archive into the `plugins/` (or `marketplace/`) directory of your GLPI installation.
3. Run `composer install --no-dev` in the plugin directory.
4. Log in to GLPI as an administrator.
5. Go to **Setup › Plugins**, then click **Install** and **Enable** for *Entities portal*.

> **Note:** The plugin can be configured to launch automatically when GLPI loads (redirect to the entity portal on login).

---

## Global Configuration

Access: **Management › Clients management › Configuration**  
(Required right: `UPDATE` on `plugin_manageentities`)

| Parameter | Description |
|-----------|-------------|
| **Save reports in GLPI** | Saves generated CRIs as GLPI documents in the configured category |
| **Default category for reports** | Document category used for saved CRIs |
| **Use prices** | Enables a rate field on contract periods |
| **Daily or hourly configuration** | Calculation mode for periods: `Daily` (half-days) or `Hourly` |
| **Only public tasks visible in reports** | Filters ticket tasks in CRIs to show only public tasks |
| **Allow periods on the same date range** | Allows creating contract periods with identical date ranges |
| **Client-side view (simplified interface)** | Defines what the client sees in the interventions tab: `Intervention reports` or `Contract periods` |
| **Use editor subscriptions** | Enables publisher (editor) subscription management (default: Yes). When set to `No`, hides the "Publisher subscriptions" and "Status overview" tabs on the entity, the subscription blocks and alerts on the ticket and contract tabs, the "New subscription" card in the wizard portal, and the "Subscription" step of the new-client wizard (also blocks direct access to the subscription form) |
| **Contract statuses shown in general follow-up** | Multi-select of statuses included in the general follow-up view |
| **Default business list (general follow-up)** | Business contacts shown by default in the general follow-up view |
| **Show company comments in CRI** | Includes the company comment in the CRI PDF |
| **Use non-accomplished tasks** | Includes unfinished tasks in the CRI generation form |
| **Display PDF** | Automatically opens the PDF after generating a CRI |
| **Status of created ticket** | Status applied to the ticket when a CRI is created via the form |
| **Default duration** | Pre-filled duration in the CRI form |
| **Default time (morning)** | Start time of the morning half-day in CRIs |
| **Default time (afternoon)** | Start time of the afternoon half-day in CRIs |
| **Disable creation date in PDF header** | Hides the generation date in the PDF header |

> **Warning:** Changing the daily/hourly mode affects existing contract types.

---

## Features

### Entity portal

Access: **Management › Clients management** (central interface) or the side menu (simplified interface)

The entity portal is the plugin's main page. For each active GLPI entity, it groups all the tabs described below. An automatic redirect to this portal on GLPI startup can be configured.

---

### General follow-up

**Tab 1 — General follow-up**

Aggregated view of entity activity, filterable by period and parameters. Displays tickets, contract periods, and consumed balance. The contract statuses shown are configurable (see Global Configuration).

---

### Monthly follow-up

**Tab 2 — Monthly follow-up** *(central interface only)*

Month-by-month view of activity: number of interventions, durations, breakdown by category or technician.

---

### GANTT

**Tab 3 — GANTT**

Gantt chart of the entity's contract periods, visualizing the intervention schedule on a timeline.

---

### Administrative data

**Tab 4 — Administrative data**

Summary record of the GLPI entity including:
- **Entity logo** (JPG/JPEG format, uploadable from the central interface)
- Full name, phone, fax, website, e-mail, full postal address
- Comments
- **Associated contacts** (including the manager)
- **Business contacts** (commercial contacts)

---

### Contracts and periods

**Tab 5 — Contracts**

Management of service contracts associated with the entity. Each contract includes:

| Field | Description |
|-------|-------------|
| **Management type** | Tracking mode: None, Quarterly, Annual |
| **Contract type** | Depending on mode (hourly or daily): AT, Flat rate, Hours, Interventions, Unlimited |
| **Date of signature** | Contractual start date |
| **Date of renewal** | Contract expiry date |
| **Additional contract** | Indicates an amendment |
| **Rebillable costs** | Enables cost rebilling |

**Contract periods (`ContractDay`)**: each contract can have multiple periods defining the available balance of days/hours, with:
- Start and end dates
- Initial balance (number of days or hours)
- **Stakeholders** (`InterventionSkateholder`): list of technicians authorized for the period
- **Rates** (`CriPrice`): rates per intervention type for the period

The balance is automatically decremented each time a CRI is validated.

---

### Publisher subscriptions

**Tab 6 — Publisher subscriptions**

> Displayed only when the **Use editor subscriptions** option is enabled (see Global Configuration).

Lists the publisher (editor) subscriptions recorded for the entity. Each subscription describes a software licence or SaaS contract taken out with a publisher, independently of the GLPI service contracts.

| Field | Description |
|-------|-------------|
| **Entity** | GLPI entity the subscription belongs to |
| **Publisher customer account ID** | Customer account reference at the publisher |
| **Referenced name at the publisher** | Name under which the client is registered with the publisher |
| **Type** | `Cloud` (SaaS/hosted) or `Editor` (on-premise) |
| **Subscription level** | Level from the `SubscriptionLevel` dropdown |
| **Start date** | Subscription start date |
| **End date** | Subscription expiry date (highlighted in red when past) |

The table provides:
- A **text search** over all columns
- An **Expired only** filter to show subscriptions whose end date has passed
- A **CSV export** (respects the active expired filter)
- A **Create subscription** button (requires `CREATE`/`UPDATE` and the central interface), and an **Edit** action per row

Subscriptions are sorted with open-ended ones first, then by oldest end date.

---

### Status overview

**Tab 7 — Status overview** *(central interface only)*

> Displayed only when the **Use editor subscriptions** option is enabled (see Global Configuration).

Consolidated dashboard cross-referencing publisher subscriptions with contract activity, over the client entities in scope (the archive entity subtree is excluded from alerts). It surfaces three consistency alerts and two count breakdowns:

| Element | Description |
|---------|-------------|
| **Active contract but no subscription** | Entities with an active contract (matching the configured contract statuses) but no publisher subscription — shown in red, or a green confirmation when none |
| **Expired subscriptions** | Entities whose subscription has expired, split into `Cloud` and `On-premise` |
| **Subscription but no active contract** | Entities holding a subscription without an active contract, split into *previously had a contract* / *never had a contract* |
| **Subscriptions by type** | Counters for `Cloud` and `On-premise` subscriptions |
| **Subscriptions by level** | Counters per subscription level |

The scoped entities depend on the wizard parent entity (`wizard_default_entities_id`) and archive entity (`wizard_archive_entities_id`) set in the configuration.

---

### Intervention reports (CRI)

**Tab 7 — Intervention reports** *(or Contract periods depending on configuration)*  
Required right: `plugin_manageentities_cri_create`

A **CRI (Compte Rendu d'Intervention)** is an intervention report associated with a GLPI ticket, generated as a **PDF** via FPDF.

**Generating a CRI:**
1. From the interventions tab of a ticket (or from **Tools › Generate CRI**)
2. Select the entity, contract period, and intervention type
3. Fill in the information: date, technician, duration (morning/afternoon or hours)
4. The CRI is generated as a PDF and can be saved as a GLPI document

**CRI PDF content:**
- Header with entity logo and contact information
- Detail of ticket tasks (public only if configured)
- Information on the contract period and remaining balance
- Company comments (if configured)
- Technician signature

**Quick access from a ticket:** an **Intervention report** tab appears directly on the ticket record, allowing a CRI to be created without leaving the ticket.

---

### Not billed interventions (Direct Helpdesk)

Access: **Tools › Not billed interventions** (central and helpdesk interface)

Allows recording of interventions that are not attached to a billable ticket. Each non-billed intervention can later be linked to a ticket.

Main fields:
- Date and duration (1h, 2h, 3h)
- ITIL category
- Description
- **Is billed** indicator (set to billed when linked to a ticket)

---

### Documents and accounts

**Tab 8 — Documents**

Lists GLPI documents associated with the entity, with upload capability.

> CRIs saved as documents appear here in the configured document category. The modification date of a CRI document is preserved during updates to avoid masking the actual intervention dates.

**Tab 10 — Accounts** *(if the Accounts plugin is active)*

Lists Accounts plugin accounts associated with the entity.

---

### References

**Tab 11 — References** *(central interface only)*

Lists client entities with which a contract has been signed, grouped by year of signature, with each entity's logo.

---

### Management reports

Access: **Tools › Reports**

Three reports are available:

| Report | Description |
|--------|-------------|
| **Intervention reports** | Summary of CRIs by entity, period, technician |
| **Report on technician movement** | Summary of technician travel between entities over a period |
| **Occupation report** | Technician occupation rate over a given period |

---

### Companies

Access: **Management › Clients management › Companies**

Management of partner or client companies in the park. A company can have associated documents.

---

### Dashboard

The plugin exposes a **dashboard widget** (`Dashboard`) in the GLPI dashboard (via the `mydashboard` hook), displaying follow-up indicators for the active entity.

---

## Rights management

Access: **Administration › Profiles › [profile] › Entities portal tab**

| Right | Field | Description |
|-------|-------|-------------|
| **Entities portal** | `plugin_manageentities` | Full access to the portal: read, create, update, delete |
| **Intervention reports** | `plugin_manageentities_cri_create` | Create and view CRIs |

At installation, the Super-Admin profile receives all rights.

---

## Integrations

| Plugin | Description |
|--------|-------------|
| **accounts** | Accounts tab in the entity portal to view associated accounts |
| **servicecatalog** | Integration into the service catalog of the simplified interface |
| **datainjection** | Data import via CSV file |
| **activity** | Planning events from the activity plugin are visible in the GLPI planning |

---

## Uninstallation

1. Go to **Setup › Plugins**.
2. Click **Disable** then **Uninstall** for *Entities portal*.

> **Warning:** Uninstalling removes all plugin tables (contracts, periods, CRIs, business contacts, etc.) and associated data.
