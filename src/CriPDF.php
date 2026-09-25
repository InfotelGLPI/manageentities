<?php

/**
 * -------------------------------------------------------------------------
 * manageentities plugin for GLPI
 * Copyright (C) 2017-2026 by the manageentities Development Team.
 *
 * https://github.com/InfotelGLPI/manageentities
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of manageentities.
 *
 * manageentities is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * manageentities is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with manageentities. If not, see <http://www.gnu.org/licenses/>.
 * --------------------------------------------------------------------------
 */

namespace GlpiPlugin\Manageentities;

use Glpi\RichText\RichText;
use GlpiPlugin\Manageentities\Config;
use GlpiPlugin\Manageentities\Contact;
use Toolbox;

class CriPDF extends \TCPDF
{
    /* Report attributes sent by the user before the generation. */

    public $sous_contrat = false;    // Whether the intervention is under contract.
    public $deplacement = false;     // Whether the contract manages travels
    public $nombredeplacement = 0;   // Total travels
    public $libelle_activite = "";   // Activity label of the report.
    public $description_cri = "";    // Document description (concatenation of the public followups).
    public $no_cri = "";             // Generated document number.

    /* Other attributes, loaded from the database for instance. */
    public $demande_associee = "";   // Id of the ticket the report is generated for.
    public $intervenant = "";        // Ticket technician.
    public $date_intervention = null;// 3 items (0 --> year and month; 1 --> from; 2 --> to).
    public $entite = null;           // 3 items: entity, entitydata and contract.
    public $temps_passes = null;     // Time spent on the intervention.
    public $forfait = false;         // Fixed-price contract
    public $intervention = false;    // Per-intervention contract

    /* Layout settings. */
    public $line_height = 5;         // Height of a single line.
    public $pol_def = 'Helvetica';   // Default font (TCPDF core font; FPDF already aliased Arial to it).
    public $tail_pol_def = 10;       // Default font size.
    public $tail_titre = 22;         // Title size.
    public $marge_haut = 5;          // Top margin.
    public $marge_gauche = 15;       // Left margin, and right margin as well.
    public $largeur_grande_cell = 190;   // Width of a full page cell.
    public $tail_bas_page = 20;      // Footer height.
    public $nb_carac_ligne = 90;     // For the work details.

    /* Time rounding rules, with an additional threshold. */
    public $tranches_seuil = 0.001;
    public $tranches_arrondi = [0, 0.25, 0.5, 0.75, 1];

    /**
     * CriPDF constructor.
     *
     * Called as `new CriPDF('P', 'mm', 'A4')` (legacy FPDF positional args). TCPDF's own
     * constructor takes the same first three parameters, so we simply forward them and enable
     * UTF-8 (TCPDF is unicode-native — that is why every Toolbox::decodeFromUtf8() latin1 wrapper
     * was removed from this class).
     *
     * @param string $orientation Page orientation ('P' or 'L').
     * @param string $unit        Measurement unit.
     * @param string $size        Page format.
     */
    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        parent::__construct($orientation, $unit, $size, true, 'UTF-8');

        // This report is hand-positioned for FPDF geometry: 15 mm side margins, a 1 mm cell
        // margin, and manual page breaks (the TestBasDePage* helpers). Restore that layout so
        // TCPDF's defaults (15 mm margins but ~1.5 mm padding + auto page break) don't shift it:
        //  - left/right margins at $marge_gauche, top margin at $marge_haut;
        //  - auto page break OFF (page breaks are handled explicitly via GetSeuilSaut);
        //  - 1 mm horizontal cell padding ≈ FPDF's default cell margin so text keeps its inset.
        $this->SetMargins($this->marge_gauche, $this->marge_haut, $this->marge_gauche);
        $this->SetAutoPageBreak(false);
        $this->setCellPaddings(1, 0, 1, 0);
    }

    /* ********************** */
    /* Generic layout methods. */
    /* ********************** */

    /** Draw a blank separator line. */
    public function Separateur()
    {
        $this->Cell($this->largeur_grande_cell, $this->line_height, '', 0, 0, '');
        $this->SetY($this->GetY() + $this->line_height);
    }

    /** Set the light background colour. */
    public function SetFondClair()
    {
        $this->SetFillColor(100, 122, 157);
    }

    /** Set the dark background colour. */
    public function SetFondFonce()
    {
        $this->SetFillColor(205, 205, 205);
    }

    /**
     * Set the font of a label.
     *
     * @param $italic True for italic, false otherwise.
     */
    public function SetFontLabel($italic)
    {
        if ($italic) {
            $this->SetFont($this->pol_def, 'I', $this->tail_pol_def);
        } else {
            $this->SetFont($this->pol_def, '', $this->tail_pol_def);
        }
    }

    /**
     * Restore the normal font.
     *
     * @param $souligne True to underline the text, false (default) otherwise.
     */
    public function SetFontNormale($souligne = false)
    {
        if ($souligne) {
            $this->SetFont($this->pol_def, 'U', $this->tail_pol_def);
        } else {
            $this->SetFont($this->pol_def, '', $this->tail_pol_def);
        }
    }

    /**
     * Draw a label cell for one or several value cells.
     *
     * @param $italic True for an italic label, false otherwise.
     * @param $w Width of the label cell.
     * @param $label Label value.
     * @param $multH Cell height multiplier, 1 by default.
     * @param $align Text alignment in the cell.
     * @param $bordure Borders to draw, all of them by default.
     */
    public function CellLabel($italic, $w, $label, $multH = 1, $align = '', $bordure = 1)
    {
        $this->SetFondClair();
        $this->SetFontLabel($italic);
        $this->Cell($w, $this->line_height * $multH, $label, $bordure, 0, $align, true);
    }

    /**
     * Draw a value cell.
     *
     * @param $w Width of the value cell.
     * @param $valeur Value to display.
     * @param $align Cell alignment.
     * @param $multH Cell height multiplier, 1 by default.
     * @param $bordure Borders to draw, all of them by default.
     * @param $souligne Whether the cell content is underlined.
     */
    public function CellValeur($w, $valeur, $align = '', $multH = 1, $bordure = 1, $souligne = false)
    {
        $this->SetFontNormale($souligne);
        $this->Cell($w, $this->line_height * $multH, $valeur, $bordure, 0, $align);
    }

    /**
     * Draw an empty dark grey cell.
     *
     * @param $w Cell width.
     */
    public function CellVideFoncee($w)
    {
        $this->SetFondFonce();
        $this->Cell($w, $this->line_height, '', 1, 0, '', true);
    }

    /* *********************** */
    /* Report content methods. */
    /* *********************** */

    /**
     * Draw the report header.
     */
    public function Header()
    {
        global $CFG_GLPI;

        // TCPDF::setHeader() forces setCellPadding(0) right before calling Header(), so the header
        // cells would lose the 1 mm horizontal inset the body keeps (constructor setCellPaddings).
        // Re-assert it here so header and body text align identically (left-aligned values).
        $this->setCellPaddings(1, 0, 1, 0);

        /* Header cell widths (their sum must equal $largeur_grande_cell). */
        $largeur_logo = 50;
        $largeur_titre = 90;
        $largeur_date = 50;
        /* Set the margins. */
        $this->SetX($this->marge_gauche);
        $this->SetY($this->marge_haut);
        // Current date.
        $aujour_hui = getdate();

        $plugin_company = new Company();
        $filepath_logo = $plugin_company->getLogo($this);
        /* Logo. */
        if ($filepath_logo != null && file_exists(GLPI_DOC_DIR . "/" . $filepath_logo)) {
            $this->Image(GLPI_DOC_DIR . "/" . $filepath_logo, 17, 10, 35, 10);
        }

        $this->Cell($largeur_logo, 20, '', 1, 0, 'C');
        /* Title. */
        $this->SetFont($this->pol_def, 'B', $this->tail_titre);
        $this->Cell(
            $largeur_titre,
            $this->line_height * 2,
            _n('Report', 'Reports', 1),
            'LTR',
            0,
            'C',
        );
        $this->SetY($this->GetY() + $this->line_height * 2);
        // Align the title's bottom half on the same X as its top half (logo width + left margin),
        // otherwise the two halves are 5 mm apart and draw a doubled/staggered vertical border.
        $this->SetX($largeur_logo + $this->marge_gauche);
        $this->Cell(
            $largeur_titre,
            $this->line_height * 2,
            __('of this intervention', 'manageentities'),
            'LRB',
            0,
            'C',
        );
        $this->SetY($this->GetY() - $this->line_height * 2);
        $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);

        $client = new EntityLogo();
        $client_logo = $client->getLogo($this->entite[0]->fields["id"]);

        if ($client_logo != null && file_exists(GLPI_DOC_DIR . "/" . $client_logo)) {
            $size = self::fctaffichimage(GLPI_DOC_DIR . "/" . $client_logo, 40, 18);
            if ($size[1] < 15) {
                $this->Image(
                    GLPI_DOC_DIR . "/" . $client_logo,
                    $largeur_titre + $largeur_logo + 15,
                    $this->line_height * 2,
                    $size[0],
                    $size[1],
                );
            } elseif ($size[0] > 20) {
                $this->Image(
                    GLPI_DOC_DIR . "/" . $client_logo,
                    $largeur_titre + $largeur_logo + 15,
                    $this->line_height + 1,
                    $size[0],
                    $size[1],
                );
            } else {
                $this->Image(
                    GLPI_DOC_DIR . "/" . $client_logo,
                    $largeur_titre + $largeur_logo + 25,
                    $this->line_height + 1,
                    $size[0],
                    $size[1],
                );
            }
            $this->Cell($largeur_logo, 20, '', 1, 0, 'C');
            $this->SetY($this->GetY() + $this->line_height * 4);

            /* Date and time. */
            $this->CellValeur(
                $this->largeur_grande_cell,
                __('Created by', 'manageentities') . ' : ' . $this->GetDateFormatee($aujour_hui) . " " . __(
                    'in',
                    'manageentities',
                ) . " " . $this->GetHeureFormatee($aujour_hui),
                'C',
                1,
                'LTRB',
                false,
            ); // Date label.
            $this->SetY($this->GetY() + $this->line_height);
        } else {
            $config = Config::getInstance();
            if (!$config->fields['disable_date_header']) {
                /* Date and time. */
                $this->CellValeur(
                    $largeur_date,
                    __('Created by', 'manageentities') . ' :',
                    'C',
                    1,
                    'LTR',
                    true,
                ); // Date label.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur($largeur_date, $this->GetDateFormatee($aujour_hui), 'C', 1, 'LR'); // Date.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur(
                    $largeur_date,
                    __('in', 'manageentities') . ' :',
                    'C',
                    1,
                    'LR',
                    true,
                ); // Time label.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur($largeur_date, $this->GetHeureFormatee($aujour_hui), 'C', 1, 'LRB'); // Time.
                $this->SetY($this->GetY() + $this->line_height);
            } else {
                /* Empty */
                $this->CellValeur($largeur_date, "", 'C', 1, 'LTR', true); // Date label.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur($largeur_date, "", 'C', 1, 'LR'); // Date.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur($largeur_date, "", 'C', 1, 'LR', true); // Time label.
                $this->SetY($this->GetY() + $this->line_height);
                $this->SetX($largeur_titre + $largeur_logo + $this->marge_gauche);
                $this->CellValeur($largeur_date, "", 'C', 1, 'LRB'); // Time.
                $this->SetY($this->GetY() + $this->line_height);
            }
        }


        /* Report identifier. */
        $this->Cell(
            $this->largeur_grande_cell,
            $this->line_height,
            "N°" . $this->GetNoCri($aujour_hui),
            1,
            0,
            'C',
        );
        $this->SetY($this->GetY() + $this->line_height);

        // FPDF let the page body continue right below wherever Header() left the cursor. TCPDF
        // instead re-homes the cursor to (lMargin, tMargin) after Header() returns (see
        // TCPDF::setHeader). The header height here is dynamic (client logo / date block vary),
        // so pin the top margin to the header's actual end: TCPDF then drops the body exactly
        // where FPDF did. Header() runs on every page, so this stays correct after page breaks.
        $this->SetTopMargin($this->GetY());
    }

    /**
     * Draw the general information table.
     */
    public function InfosGenerales()
    {
        /* Linked ticket number. */
        $this->SetTextColor(255, 255, 255);
        $this->SetFontNormale(false); // Restore the normal font.
        $this->CellLabel(
            false,
            $this->largeur_grande_cell / 2,
            __('Request number of associated help', 'manageentities'),
        );
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur($this->largeur_grande_cell / 2, $this->demande_associee);
        $this->SetTextColor(255, 255, 255);
        $this->SetY($this->GetY() + $this->line_height);

        /* Technician. */
        $this->SetY($this->GetY() + $this->line_height);

        $intervenants = explode(',', $this->intervenant);
        if (sizeof($intervenants) > 1) {
            $plural = 2;
        } else {
            $plural = 1;
        }

        $this->CellLabel(
            false,
            $this->largeur_grande_cell,
            _n('Technician', 'Technicians', $plural, 'manageentities'),
            1,
            'C',
        );
        $this->SetY($this->GetY() + $this->line_height);
        $this->SetTextColor(0, 0, 0);
        $this->SetFontNormale(false);
        foreach ($intervenants as $une_ligne) {
            $this->TestBasDePageDetailTravaux($une_ligne);

            // Force left align: TCPDF's MultiCell defaults to 'J' (justify) and justifies even a
            // single non-wrapping line, spreading words to both edges (e.g. "Xavier      CAILLAUD").
            // FPDF left-aligned the last/only line, so pass 'L' explicitly to restore that behaviour.
            $this->MultiCell($this->largeur_grande_cell, $this->line_height, $une_ligne, 'LR', 'L');
        }
        $this->Cell($this->largeur_grande_cell, 0, '', 'LRB'); // Closing line drawing the bottom border of the cell.
        $this->SetY($this->GetY() + $this->line_height);
        $this->SetTextColor(255, 255, 255);

        /* Intervention date. */
        $this->CellLabel(false, 40, __('Intervention date', 'manageentities'), 2);

        /* Year and month... */
        $this->CellLabel(true, 20, __('Year', 'manageentities'));
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(35, $this->date_intervention[0]["year"]);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(true, 20, __('month'));
        $monthsarray = Toolbox::getMonthsOfYearArray();
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(35, $monthsarray[$this->date_intervention[0]["mon"]]);
        $this->CellVideFoncee(40);
        $this->Ln();

        /* From, to... */
        $this->SetX($this->GetX() + 40);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(true, 20, __('From', 'manageentities'));
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(35, $this->date_intervention[1]);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(true, 20, __('To', 'manageentities'));
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(35, $this->date_intervention[2]);
        $this->CellVideFoncee(40);
        $this->SetY($this->GetY() + $this->line_height);
    }

    /**
     * Draw the information table of the entity the report is about.
     */
    public function InfosEntite()
    {
        global $DB;

        if (!isset($this->entite[0]->fields["id"])) {
            $this->entite[0]->fields["id"] = 0;
        }
        if (!isset($this->entite[0]->fields["name"])) {
            $this->entite[0]->fields["name"] = __('Root entity');
        }

        $contact = new Contact();
        $contacts = $contact->find([
            'entities_id' => $this->entite[0]->fields["id"],
            'is_default' => 1,
        ]);
        foreach ($contacts as $data) {
            $contact = new \Contact();
            $contact->getFromDB($data["contacts_id"]);
            $manager = $contact->fields["firstname"] . " " . $contact->fields["name"];
        }

        /* Entity name. */
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(false, 40, __('Society name', 'manageentities'));
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(150, $this->entite[0]->fields["name"]);
        $this->SetY($this->GetY() + $this->line_height);
        /* City. */
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(false, 40, __('City'));
        $this->SetTextColor(0, 0, 0);
        if (!isset($this->entite[0]->fields["town"])) {
            $this->entite[0]->fields["town"] = "";
        }
        $this->CellValeur(150, $this->entite[0]->fields["town"]);
        $this->SetY($this->GetY() + $this->line_height);
        /* Manager. */
        if (!isset($manager)) {
            $manager = "";
        }
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(false, 40, __('Person in charge', 'manageentities'));
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(150, $manager);
        $this->SetY($this->GetY() + $this->line_height);
    }

    /**
     * Draw a cell prefixed with a checkbox symbol.
     * The cell is actually made of 2 cells.
     *
     * @param $cochee True for a black square, false for a white one.
     * @param $w Total width.
     * @param $label Content of the second sub-cell.
     */
    public function CellContrat($cochee, $w, $label)
    {
        $largeur_symbol = 2.5;

        $this->SetFondClair();
        $this->SetFontLabel(true);
        if ($cochee) {
            $this->SetFont('zapfdingbats', '', 6);
            $this->Cell($largeur_symbol, $this->line_height, chr(110), 'LTB', 0, '', true);
        } else {
            $this->SetFont('zapfdingbats', '', 6);
            $this->Cell($largeur_symbol, $this->line_height, chr(111), 'LTB', 0, '', true);
        }
        $this->SetFontLabel(true);
        $this->Cell($w - $largeur_symbol, $this->line_height, $label, 'TRB', 0, '', true);
    }

    /**
     * Draw the contract information of the entity the report is about.
     */
    public function InfosContrats()
    {
        $this->SetTextColor(255, 255, 255);
        /* Contract type. */
        $this->CellLabel(false, 40, _n('Contract type', 'Contract types', 1), 2);
        /* Under contract. */

        $this->CellContrat($this->sous_contrat, 50, __('Help on contract', 'manageentities'));

        if ($this->sous_contrat) {
            $this->CellLabel(true, 35, __('Contract number', 'manageentities'));
            $this->SetTextColor(0, 0, 0);
            $this->CellValeur(65, $this->entite[1]);
        } else {
            $this->CellVideFoncee(100);
            $this->SetTextColor(0, 0, 0);
        }
        $this->Ln();
        /* Out of contract. */
        $this->SetX($this->GetX() + 40);
        $this->SetTextColor(255, 255, 255);
        $this->CellContrat(!$this->sous_contrat, 50, __('Out of contract', 'manageentities'));
        $this->CellVideFoncee(100);
        $this->SetTextColor(0, 0, 0);
        $this->SetY($this->GetY() + $this->line_height);
    }

    /** Draw the header of the time spent table. */
    public function TempsPassesEntete()
    {
        $config = Config::getInstance();
        /* Header of the time spent table. */
        $width = 0;
        if ($this->forfait) {
            $width = 15;
        }
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(
            false,
            $this->largeur_grande_cell,
            __('Crossed time (itinerary including)', 'manageentities'),
            1,
            'C',
        );
        $this->SetTextColor(0, 0, 0);
        $this->Ln();
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(true, 95, __('Wording of the activities', 'manageentities'), 2, 'C');

        $this->CellLabel(true, 20 + $width, __('Date of', 'manageentities'), 1, 'C', 'LTR');
        if (!$this->forfait) {
            $this->CellLabel(true, 15, __('Hour of', 'manageentities'), 1, 'C', 'LTR');
        }
        $this->CellLabel(true, 20 + $width, __('Date of', 'manageentities'), 1, 'C', 'LTR');
        if (!$this->forfait) {
            $this->CellLabel(true, 15, __('Hour of', 'manageentities'), 1, 'C', 'LTR');
        }
        if ($this->intervention) {
            $this->CellLabel(true, 25, _x('Quantity', 'Number'), 1, 'C', 'LTR');
        } else {
            $this->CellLabel(true, 25, __('Crossed time', 'manageentities'), 1, 'C', 'LTR');
        }
        $this->SetTextColor(0, 0, 0);

        $this->Ln();
        $this->SetX($this->GetX() + 95);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(true, 20 + $width, __('Begin'), 1, 'C', 'LBR');
        if (!$this->forfait) {
            $this->CellLabel(true, 15, __('Begin'), 1, 'C', 'LBR');
        }
        $this->CellLabel(true, 20 + $width, __('End'), 1, 'C', 'LBR');
        if (!$this->forfait) {
            $this->CellLabel(true, 15, __('End'), 1, 'C', 'LBR');
        }
        if ($this->intervention) {
            $this->CellLabel(
                true,
                25,
                __('of this intervention', 'manageentities'),
                1,
                'C',
                'LBR',
            );
        } else {
            if ($config->fields['hourorday'] == Config::DAY) {
                $this->CellLabel(true, 25, __('(in days)', 'manageentities'), 1, 'C', 'LBR');
            } else {
                $this->CellLabel(true, 25, __('(in hours)', 'manageentities'), 1, 'C', 'LBR');
            }
        }
        $this->SetTextColor(0, 0, 0);
        $this->Ln();
    }

    /** Draw the time spent area. */
    public function TempsPasses()
    {
        $config = Config::getInstance();

        $_SESSION["glpi_plugin_manageentities_total"] = 0;
        // Table header.
        $this->TempsPassesEntete();
        /* Time spent rows. */
        $total_tps = 0;
        for ($l = 0; $l < count($this->temps_passes); $l++) {
            $this->TestBasDePageTpsPasses(); // Page break if needed.
            if ($config->fields['useprice'] == Config::NOPRICE) {
                $this->CellValeur(95, $this->libelle_activite[$l]);
            } elseif ($config->fields['hourorday'] == Config::HOUR) {
                $this->CellValeur(95, $this->libelle_activite[$l]);
            } else {
                $this->CellValeur(95, $this->libelle_activite);
            }

            $width = 0;
            if ($this->forfait) {
                $width = 15;
            }
            $this->CellValeur(20 + $width, $this->temps_passes[$l][0], 'C');
            if (!$this->forfait) {
                $this->CellValeur(15, $this->temps_passes[$l][1], 'C');
            }
            $this->CellValeur(20 + $width, $this->temps_passes[$l][2], 'C');
            if (!$this->forfait) {
                $this->CellValeur(15, $this->temps_passes[$l][3], 'C');
            }
            $this->CellValeur(25, $this->TotalTpsPassesArrondis($this->temps_passes[$l][4]), 'C');
            $total_tps += $this->temps_passes[$l][4];
            $this->Ln();
        }

        if ($this->deplacement) {
            /* Travel. */
            $this->Separateur();
            $this->Cell(115, $this->line_height, '', 0, 0, '');
            $this->SetTextColor(255, 255, 255);
            if ($config->fields['hourorday'] == Config::DAY) {
                $this->CellLabel(true, 40, __('Travel (in days)', 'manageentities'));
            } else {
                $this->CellLabel(true, 40, __('Travel', 'manageentities'));
            }
            $this->SetTextColor(0, 0, 0);
            $this->CellValeur(35, $this->nombredeplacement, 'C');
        }

        /* Total. */
        $this->Separateur();
        $this->Cell(115, $this->line_height, '', 0, 0, '');
        $this->SetTextColor(255, 255, 255);
        if ($this->intervention) {
            $this->CellLabel(true, 40, __('Total'));
        } else {
            if ($config->fields['hourorday'] == Config::DAY) {
                $this->CellLabel(true, 40, __('Total (in days)', 'manageentities'));
            } else {
                $this->CellLabel(true, 40, __('Total (in hours)', 'manageentities'));
            }
        }
        $this->SetTextColor(0, 0, 0);
        $this->CellValeur(35, $this->TotalTpsPassesArrondis($total_tps + $this->nombredeplacement), 'C');
        $this->Separateur();

        $_SESSION["glpi_plugin_manageentities_total"] = ($total_tps + $this->nombredeplacement);
    }

    /**
     * Round the total time spent to the steps defined in $tranches_arrondi.
     *
     * @param float|int $a_arrondir Total to round.
     *
     * @return int The rounded total.
     */
    public function TotalTpsPassesArrondis($a_arrondir)
    {
        $result = 0;

        $partie_entiere = floor($a_arrondir);
        $reste = $a_arrondir - $partie_entiere + 10; // The + 10 works around a float comparison issue below.
        /* Steps increased by the additional threshold. */
        $tranches_majorees = [];
        for ($i = 0; $i < count($this->tranches_arrondi); $i++) {
            // Same + 10 offset as $reste above.
            $tranches_majorees[] = $this->tranches_arrondi[$i] + $this->tranches_seuil + 10;
        }
        if ($reste < $tranches_majorees[0]) {
            $result = $partie_entiere;
        } elseif ($reste >= $tranches_majorees[0] && $reste < $tranches_majorees[1]) {
            $result = $partie_entiere + $this->tranches_arrondi[1];
        } elseif ($reste >= $tranches_majorees[1] && $reste < $tranches_majorees[2]) {
            $result = $partie_entiere + $this->tranches_arrondi[2];
        } elseif ($reste >= $tranches_majorees[2] && $reste < $tranches_majorees[3]) {
            $result = $partie_entiere + $this->tranches_arrondi[3];
        } else {
            $result = $partie_entiere + $this->tranches_arrondi[4];
        }

        return $result;
    }

    /** Handle a page break in the time spent area. */
    public function TestBasDePageTpsPasses()
    {
        if ($this->GetSeuilSaut() < $this->line_height) {
            $this->AddPage();
            $this->SetY($this->GetY() + $this->line_height);
            // Draw the table header again.
            $this->TempsPassesEntete();
        }
    }

    /** Draw the header of the work details table. */
    public function DetailTravauxEntete()
    {
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(
            false,
            $this->largeur_grande_cell,
            __('Detail of work done', 'manageentities'),
            1,
            'C',
        );
        $this->SetY($this->GetY() + $this->line_height);
        $this->SetFontNormale(false); // Restore the normal font.
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Draw the work details area.
     *
     * @param $description Text to display in the details area.
     */
    public function DetailTravaux()
    {
        // Table header.
        $this->DetailTravauxEntete();

        $decoupage1 = [];
        $tok = strtok($this->description_cri, "\n");
        while ($tok !== false) {
            $decoupage1[] = $tok;
            $tok = strtok("\n");
        }
        $this->description_cri = $decoupage1;
        foreach ($this->description_cri as $une_ligne) {
            $this->TestBasDePageDetailTravaux($une_ligne);
            // Force left align: TCPDF's MultiCell defaults to 'J' (justify) and justifies even a
            // single non-wrapping line, spreading words to both edges (e.g. "Xavier      CAILLAUD").
            // FPDF left-aligned the last/only line, so pass 'L' explicitly to restore that behaviour.
            $this->MultiCell($this->largeur_grande_cell, $this->line_height, $une_ligne, 'LR', 'L');
        }
        $this->Cell($this->largeur_grande_cell, 0, '', 'LRB'); // Closing line drawing the bottom border of the cell.
    }

    /**
     * Handle a page break in the details area.
     *
     * @param $une_ligne Line to check.
     */
    public function TestBasDePageDetailTravaux($une_ligne)
    {
        $nb_lg_necessaires = 1;
        if (strlen($une_ligne) > $this->nb_carac_ligne) {
            $nb_lg_necessaires = round(strlen($une_ligne) / $this->nb_carac_ligne, 0, PHP_ROUND_HALF_UP);
        }
        if (($nb_lg_necessaires * $this->line_height) > $this->GetSeuilSaut()) {
            $this->Cell(
                $this->largeur_grande_cell,
                0,
                '',
                'LRB',
            ); // Closing line drawing the bottom border of the cell.
            $this->AddPage();
            $this->SetY($this->GetY() + $this->line_height);
            // Draw the table header again.
            $this->DetailTravauxEntete();
        }
    }

    /** Draw the customer remarks area. */
    public function Observations()
    {
        $tail_zone = 30;
        $ligne_points = '...........................................';

        // Check there is enough room left.
        $this->TestBasDePageGenerique($tail_zone);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(
            false,
            $this->largeur_grande_cell,
            __('Customer comments', 'manageentities'),
            1,
            'C',
        );
        $this->SetTextColor(0, 0, 0);
        $this->SetY($this->GetY() + $this->line_height);
        $this->CellValeur($this->largeur_grande_cell, '', 'C', 1, 'LTR');
        $this->Ln();
        $this->CellValeur(
            $this->largeur_grande_cell,
            $ligne_points . $ligne_points . $ligne_points . $ligne_points,
            'C',
            0.5,
            'LR',
        );
        $this->Ln();
        $this->CellValeur($this->largeur_grande_cell, '', 'C', 1.5, 'LR');
        $this->Ln();
        $this->CellValeur(
            $this->largeur_grande_cell,
            $ligne_points . $ligne_points . $ligne_points . $ligne_points,
            'C',
            0.5,
            'LR',
        );
        $this->Ln();
        $this->CellValeur($this->largeur_grande_cell, '', 'C', 1.5, 'LR');
        $this->Ln();
        $this->CellValeur(
            $this->largeur_grande_cell,
            $ligne_points . $ligne_points . $ligne_points . $ligne_points,
            'C',
            0.5,
            'LR',
        );
        $this->Ln();
        $this->CellValeur($this->largeur_grande_cell, '', 'C', 0.5, 'LBR');
        $this->SetTextColor(0, 0, 0);
        $this->Ln();
    }

    /** Draw the header of the company comments table. */
    public function DetailCommentaires()
    {
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(false, $this->largeur_grande_cell, __('Comments'), 1, 'C');
        $this->SetY($this->GetY() + $this->line_height);
        $this->SetFontNormale(false); // Restore the normal font.
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Draw the company comments area.
     *
     * @param $description Text to display in the details area.
     */
    public function Commentaires()
    {
        $plugin_company = new Company();
        $comment = $plugin_company->getComment($this);

        // Table header.
        $this->DetailCommentaires();

        $decoupage1 = [];
        $tok = strtok($comment, "\n");
        while ($tok !== false) {
            $decoupage1[] = $tok;
            $tok = strtok("\n");
        }
        $comment = $decoupage1;
        foreach ($comment as $une_ligne) {
            $this->TestBasDePageCommentaires($une_ligne);
            // Force left align: TCPDF's MultiCell defaults to 'J' (justify) and justifies even a
            // single non-wrapping line, spreading words to both edges (e.g. "Xavier      CAILLAUD").
            // FPDF left-aligned the last/only line, so pass 'L' explicitly to restore that behaviour.
            $this->MultiCell($this->largeur_grande_cell, $this->line_height, $une_ligne, 'LR', 'L');
        }
        $this->Cell($this->largeur_grande_cell, 0, '', 'LRB'); // Closing line drawing the bottom border of the cell.
    }

    /**
     * Handle a page break in the details area.
     *
     * @param $une_ligne Line to check.
     */
    public function TestBasDePageCommentaires($une_ligne)
    {
        $nb_lg_necessaires = 1;
        if (strlen($une_ligne) > $this->nb_carac_ligne) {
            $nb_lg_necessaires = round(strlen($une_ligne) / $this->nb_carac_ligne, 0, PHP_ROUND_HALF_UP);
        }
        if (($nb_lg_necessaires * $this->line_height) > $this->GetSeuilSaut()) {
            $this->Cell(
                $this->largeur_grande_cell,
                0,
                '',
                'LRB',
            ); // Closing line drawing the bottom border of the cell.
            $this->AddPage();
            $this->SetY($this->GetY() + $this->line_height);
            // Draw the table header again.
            $this->DetailCommentaires();
        }
    }

    /** Draw the customer stamp and signature area. */
    public function CachetClient()
    {
        $tail_zone = 32.5;

        // Check there is enough room left.
        $this->TestBasDePageGenerique($tail_zone);
        $this->SetTextColor(255, 255, 255);
        $this->CellLabel(
            false,
            $this->largeur_grande_cell / 2,
            __('Customer stamp', 'manageentities'),
            1,
            'C',
        );
        $this->CellLabel(
            false,
            $this->largeur_grande_cell / 2,
            __('Customer Visa', 'manageentities'),
            1,
            'C',
        );
        $this->SetY($this->GetY() + $this->line_height);
        $this->CellValeur($this->largeur_grande_cell / 2, '', '', 5.5); // Customer stamp.
        $this->CellValeur($this->largeur_grande_cell / 2, '', '', 5.5); // Customer signature.
        $this->SetTextColor(0, 0, 0);
        $this->Ln();
    }

    /**
     * Check whether an unbreakable area still fits at the bottom of the page.
     *
     * @param $tail_zone Height of the area.
     */
    public function TestBasDePageGenerique($tail_zone)
    {
        if ($this->GetSeuilSaut() < $tail_zone) {
            $this->AddPage();
            $this->SetY($this->GetY() + $this->line_height);
        }
    }

    /**
     * Draw the report footer.
     */
    public function Footer()
    {
        // Position from the bottom of the page.
        $this->SetY(-$this->tail_bas_page);
        /* Page number. */
        $this->SetFont($this->pol_def, '', 9);
        // TCPDF does not use FPDF's literal "{nb}" alias: the current page and total page count
        // must be requested via getAliasNumPage()/getAliasNbPages() (which also add the extra
        // curly braces required by our unicode/UTF-8 font). TCPDF substitutes them at Output().
        $this->Cell(
            0,
            $this->tail_bas_page / 2,
            __('Page', 'manageentities') . ' ' . $this->getAliasNumPage()
            . ' ' . __('on', 'manageentities') . ' ' . $this->getAliasNbPages(),
            0,
            0,
            'C',
        );
        $this->Ln(10);
        /* Infos company. */
        $this->SetFont($this->pol_def, 'I', 9);

        $plugin_company = new Company();
        $address = $plugin_company->getAddress($this);


        if (isset($address)) {
            $strAddress = nl2br($address);
            $listLines = explode("<br />", $strAddress);
            if (sizeof($listLines) > 1) {
                foreach ($listLines as $line) {
                    $this->Cell(0, $this->tail_bas_page / 4, $line, 0, 0, 'C');
                    $this->Ln();
                }
            } else {
                $this->Cell(0, $this->tail_bas_page / 4, $strAddress, 0, 0, 'C');
            }
        } else {
            $this->Cell(0, $this->tail_bas_page / 4, "", 0, 0, 'C');
        }

        $this->Ln(5);
    }

    /** Draw the report, part by part. */
    public function DrawCri()
    {
        // No AliasNbPages() call: TCPDF resolves the {nb} total-pages placeholder automatically.
        $this->AddPage(); // First page.

        $this->InfosGenerales();
        $this->Separateur();
        $this->InfosEntite();
        $this->Separateur();
        $this->InfosContrats();
        $this->Separateur();
        $this->TempsPasses();
        $this->Separateur();
        $this->DetailTravaux();
        $this->Separateur();
        $config = new Config();
        if ($config->isCommentCri()) {
            $this->Commentaires();
            $this->Separateur();
        }
        $this->Observations();
        $this->Separateur();
        $this->CachetClient();
    }

    /* ************** */
    /* Other methods. */
    /* ************** */

    /**
     * Format a date as dd/mm/yyyy.
     *
     * @param $une_date Date to format.
     *
     * @return string The date formatted as dd/mm/yyyy.
     */
    public function GetDateFormatee($une_date)
    {
        return $this->CompleterAvec0($une_date['mday'], 2) . "/" . $this->CompleterAvec0(
            $une_date['mon'],
            2,
        ) . "/" . $une_date['year'];
    }

    /**
     * Format a time as hh:mm.
     *
     * @param $une_date Date to format.
     *
     * @return string The time formatted as hh:mm.
     */
    public function GetHeureFormatee($une_date)
    {
        return $this->CompleterAvec0($une_date['hours'], 2) . ":" . $this->CompleterAvec0($une_date['minutes'], 2);
    }

    /**
     * Generate the report number from a date, only once.
     *
     * @param $une_date Date the report number is built from.
     *
     * @return string The generated report number.
     */
    public function GetNoCri($une_date = "")
    {
        if ($this->no_cri == "" && $une_date != "") {
            $this->no_cri = substr($une_date['year'], 2) . $this->CompleterAvec0($une_date['mon'], 2)
                . $this->CompleterAvec0($une_date['mday'], 2) . "-" . $this->CompleterAvec0($une_date['hours'], 2)
                . $this->CompleterAvec0($une_date['minutes'], 2) . $this->CompleterAvec0($une_date['seconds'], 2);
        }
        return $this->no_cri;
    }

    /**
     * Left-pad a string with '0' up to the given length.
     *
     * @param $une_chaine String to pad.
     * @param $lg Final length of the string.
     *
     * @return string The padded string.
     */
    public function CompleterAvec0($une_chaine, $lg)
    {
        while (strlen($une_chaine) != $lg) {
            $une_chaine = "0" . $une_chaine;
        }

        return $une_chaine;
    }

    /* ******************** */
    /* Getters and setters. */
    /* ******************** */

    public function SetSousContrat($sous_contrat)
    {
        $this->sous_contrat = $sous_contrat;
    }

    public function SetDeplacement($deplacement)
    {
        $this->deplacement = $deplacement;
    }

    public function SetNombreDeplacement($nombredeplacement)
    {
        $this->nombredeplacement = $nombredeplacement;
    }

    public function SetLibelleActivite($libelle_activite)
    {
        $config = Config::getInstance();

        if (is_array($libelle_activite)) {
            $this->libelle_activite = $libelle_activite;
        } elseif ($config->fields['hourorday'] == Config::DAY && is_integer($libelle_activite)) {
            $this->libelle_activite =
                \Dropdown::getDropdownName(
                    "glpi_plugin_manageentities_critypes",
                    $libelle_activite,
                )
            ;
        } else {
            $this->libelle_activite = $libelle_activite;
        }
    }

    public function SetDescriptionCri($description_cri)
    {
        $this->description_cri = $description_cri;
        $this->description_cri = RichText::getTextFromHtml($this->description_cri);
        $this->description_cri = stripcslashes($this->description_cri);
        $this->description_cri = htmlspecialchars_decode($this->description_cri);
        $this->description_cri = str_replace("\\\\", "\\", $this->description_cri);
        $this->description_cri = str_replace("\\'", "'", $this->description_cri);
        $this->description_cri = str_replace("<br>", " ", $this->description_cri);
        $this->description_cri = $this->description_cri;
    }

    public function SetIntervenant($intervenant)
    {
        $this->intervenant = $intervenant;
    }

    public function SetDemandeAssociee($demande_associee)
    {
        $this->demande_associee = $demande_associee;
    }

    public function SetDateIntervention($date_intervention)
    {
        // Dates come straight from the database, as yyyy-mm-dd hh:mm.
        /* Year and month of the intervention. */
        $this->date_intervention[0] = getdate(
            mktime(
                0,
                0,
                0,
                substr($date_intervention[0], 5, 2),
                substr($date_intervention[0], 8, 2),
                substr($date_intervention[0], 0, 4),
            ),
        );
        /* From and to. */
        $this->date_intervention[1] = substr($date_intervention[1], 8, 2) . "/" . substr(
            $date_intervention[1],
            5,
            2,
        ) . "/"
            . substr($date_intervention[1], 0, 4);
        $this->date_intervention[2] = substr($date_intervention[2], 8, 2) . "/" . substr(
            $date_intervention[2],
            5,
            2,
        ) . "/"
            . substr($date_intervention[2], 0, 4);
    }

    public function SetEntite($entite)
    {
        $this->entite = $entite;
    }

    public function SetTempsPasses($temps_passes)
    {
        $this->temps_passes = $temps_passes;
    }

    public function GetSeuilSaut()
    {
        return (297 - $this->GetY() - $this->tail_bas_page);
    }

    public function setForfait()
    {
        $this->forfait = true;
    }

    public function setIntervention()
    {
        $this->intervention = true;
    }

    /**
     * Compute the display size of an image fitting in a box, keeping its ratio.
     *
     * @param string    $img_Src Path of the source image
     * @param int|float $W_max   Maximum width, 0 for no limit
     * @param int|float $H_max   Maximum height, 0 for no limit
     *
     * @return array{0: int|float, 1: int|float} [width, height]
     */
    public function fctaffichimage($img_Src, $W_max, $H_max)
    {
        if (file_exists($img_Src)) {
            // Source image size
            $img_size = getimagesize($img_Src);
            $W_Src = $img_size[0]; // source width
            $H_Src = $img_size[1]; // source height
            if (!$W_max) {
                $W_max = 0;
            }
            if (!$H_max) {
                $H_max = 0;
            }
            // Size fitting the box on each axis
            $W_test = round($W_Src * ($H_max / $H_Src), 0, PHP_ROUND_HALF_UP);
            $H_test = round($H_Src * ($W_max / $W_Src), 0, PHP_ROUND_HALF_UP);
            // The image is smaller than the box
            if ($W_Src < $W_max && $H_Src < $H_max) {
                $W = $W_Src;
                $H = $H_Src;
                // No limit at all
            } elseif ($W_max == 0 && $H_max == 0) {
                $W = $W_Src;
                $H = $H_Src;
                // Free width
            } elseif ($W_max == 0) {
                $W = $W_test;
                $H = $H_max;
                // Free height
            } elseif ($H_max == 0) {
                $W = $W_max;
                $H = $H_test;
                // Otherwise, the size fitting the box
            } elseif ($H_test > $H_max) {
                $W = $W_test;
                $H = $H_max;
            } else {
                $W = $W_max;
                $H = $H_test;
            }
        } else { // the image file does not exist
            $W = 0;
            $H = 0;
        }
        return [$W, $H];
    }

}
