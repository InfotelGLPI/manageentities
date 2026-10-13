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

use Document;
use Document_Item;
use GlpiPlugin\Manageentities\Presale\Docx\DocProperties;
use GlpiPlugin\Manageentities\Presale\Docx\DocxAppender;
use GlpiPlugin\Manageentities\Presale\Docx\DocxTemplate;
use NumberFormatter;
use RuntimeException;

/**
 * Word document of a proposal (last step of the wizard), ported from the proof of concept
 * (ProposalGenerator, AnnexeGenerator):
 * 1. markers of the proposal template: logo, total, project manager block;
 * 2. custom properties of the template (DOCPROPERTY fields): client, contact, sales...;
 * 3. one document per annex from the annex template, embedded in the proposal (w:altChunk,
 *    merged by Word when the file is opened).
 * The result becomes a GLPI document linked to the proposal: a new version at each generation,
 * the previous ones are kept.
 */
class PresaleGenerator
{
    /** Heights of the client logo: the cover has room, the header band does not */
    private const COVER_HEIGHT  = 70;
    private const HEADER_HEIGHT = 42;

    /** Shown in the VAT columns of an annex exempted from VAT */
    private const DASH = "\u{2014}";

    private PresaleProposal $proposal;
    private PresaleClient $client;
    private PresaleContact $contact;

    /** @var string[] working files, removed at the end */
    private array $temp_files = [];

    public function __construct(PresaleProposal $proposal)
    {
        $this->proposal = $proposal;

        $this->client = new PresaleClient();
        if (!$this->client->getFromDB((int) $proposal->fields['plugin_manageentities_presaleclients_id'])) {
            throw new RuntimeException(__('The prospect of the proposal is missing.', 'manageentities'));
        }
        $this->contact = new PresaleContact();
        $this->contact->getFromDB((int) $proposal->fields['plugin_manageentities_presalecontacts_id']);
    }

    public function __destruct()
    {
        foreach ($this->temp_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Template of the proposal or of an annex (PresaleTemplate::*), uploaded in the configuration
     */
    public static function getTemplateFile(string $kind): string
    {
        return PresaleTemplate::getFile($kind);
    }

    /**
     * Kind of the template of an annex
     */
    private static function annexTemplate(string $mode): string
    {
        return match ($mode) {
            PresalePricing::MODE_FLAT  => PresaleTemplate::SUBSCRIPTION_ANNEX,
            PresalePricing::MODE_CLOUD => PresaleTemplate::CLOUD_ANNEX,
            default                    => PresaleTemplate::ANNEX,
        };
    }

    /**
     * Labels of the templates this proposal needs that are not uploaded yet: the proposal one
     * and the one of each kind of its annexes
     *
     * @return list<string>
     */
    public function getMissingTemplates(): array
    {
        $kinds = [PresaleTemplate::PROPOSAL];
        foreach ($this->getAnnexes() as $annex) {
            $kinds[] = self::annexTemplate($annex['mode']);
        }

        return PresaleTemplate::getMissing($kinds);
    }

    /**
     * Builds the document, stores it as a new GLPI document linked to the proposal and updates
     * the proposal (reference at the first generation, version, status)
     *
     * @return int id of the created document
     */
    public function generate(): int
    {
        $fields    = $this->proposal->fields;
        $reference = (string) ($fields['reference'] ?? '');
        if ($reference === '') {
            $reference = PresaleCounter::next((string) $this->client->fields['name']);
        }
        $version = (int) $fields['version'] + 1;

        $annexes = $this->getAnnexes();

        // Pass 1: markers of the proposal template. No total amount: the prices are given
        // annex by annex.
        $template = new DocxTemplate(self::getTemplateFile(PresaleTemplate::PROPOSAL));
        $template->setCondition('IF_PROJECT_MANAGER', (string) $fields['pm_name'] !== '');
        // No item table of its own: the prices are in the annexes
        $template->setCondition('IF_ITEMS', false);
        // Version of this document, and the history of the versions (approval table): one row
        // per generation, the previous ones read from the documents of the proposal
        $template->setRows('VERSION_NUM', $this->getVersionRows($version));
        $template->setTexts([
            'VERSION'      => self::versionLabel($version),
            'PM_NAME'      => (string) $fields['pm_name'],
            'PM_ROLE'      => (string) $fields['pm_role'],
            'PM_EMAIL'     => (string) $fields['pm_email'],
            'PM_PHONE'     => (string) $fields['pm_phone'],
        ]);
        $logo = $this->getLogoFile();
        if ($logo !== null) {
            // Headers first, at the header size and floating in front of the text: a template
            // may put LOGO_COVER in the header too, and an inline image there made the
            // header grow on every page. Then the cover page, at its own size.
            [$width, $height] = self::fitImage($logo, self::HEADER_HEIGHT);
            $template->setHeaderImage('LOGO_COVER', $logo, $width, $height);
            $template->setHeaderImage('LOGO_HEADER', $logo, $width, $height);
            [$width, $height] = self::fitImage($logo, self::COVER_HEIGHT);
            $template->setImage('LOGO_COVER', $logo, $width, $height);
            $template->setImage('LOGO_HEADER', $logo, $width, $height);
        }
        $intermediate = $this->tempFile();
        $template->clearRemaining()->render($intermediate);

        // Pass 2: custom properties (the names are the ones declared in the Word template)
        $date       = new \DateTimeImmutable((string) ($fields['proposal_date'] ?: 'now'));
        $properties = new DocProperties($intermediate);
        $properties
            ->setMany($this->getWordProperties($reference))
            ->setDate('Date contrat', $date);
        $output = $this->tempFile();
        $properties->saveAs($output);

        // Pass 3: annexes, assembled aside and swapped in only once complete
        if ($annexes !== []) {
            $files = [];
            foreach ($annexes as $annex) {
                $files[] = $this->renderAnnex($annex, $date);
            }
            $assembled = $this->tempFile();
            (new DocxAppender())->append($output, $files, $assembled);
            $output = $assembled;
        }

        $documents_id = $this->storeDocument($output, $reference, $version);

        $this->proposal->update([
            'id'           => $this->proposal->getID(),
            'reference'    => $reference,
            'version'      => $version,
            'documents_id' => $documents_id,
            'status'       => PresaleProposal::STATUS_GENERATED,
            'step'         => PresaleWizard::STEP_GENERATE,
        ]);

        return $documents_id;
    }

    /**
     * Retained annexes in document order, with their lines and totals
     *
     * @return array<int, array{fields: array, mode: string, lines: array<int, array>, totals: array{ht: float, vat: float, ttc: float}}>
     */
    private function getAnnexes(): array
    {
        $annexes = [];
        foreach ((new PresaleProposal_Annex())->find([
            'plugin_manageentities_presaleproposals_id' => $this->proposal->getID(),
        ], ['rank', 'id']) as $annex) {
            $lines = array_values((new PresaleAnnexLine())->find([
                'plugin_manageentities_presaleproposals_annexes_id' => $annex['id'],
            ], ['rank', 'id']));
            $mode = PresaleProposal_Annex::getPricingMode($annex);
            $annexes[] = [
                'fields' => $annex,
                'mode'   => $mode,
                'lines'  => $lines,
                'totals' => PresalePricing::annexTotals($lines, (float) $annex['vat_rate'], $mode),
            ];
        }
        return $annexes;
    }

    /**
     * One annex as a standalone document, from the annex template
     *
     * @param array{fields: array, mode: string, lines: array<int, array>, totals: array} $annex
     */
    private function renderAnnex(array $annex, \DateTimeImmutable $date): string
    {
        $rate = (float) $annex['fields']['vat_rate'];
        $rows = [];
        foreach ($annex['lines'] as $line) {
            $quantity = (float) $line['quantity'];
            $price    = (float) $line['unit_price'];
            $months   = PresalePricing::months($line, $annex['mode']);
            $amounts  = PresalePricing::lineAmounts($quantity, $price, $rate, $months);
            $rows[]   = [
                'DESIGNATION' => (string) $line['designation'],
                'QUANTITE'    => self::number($quantity),
                // Cloud template: monthly price per subscription and number of months
                'PRIX_MOIS'   => self::number($price),
                'MOIS'        => self::number($months),
                'TARIF_JOUR'  => self::money($price),
                'LIGNE_HT'    => self::money($amounts['ht']),
                'LIGNE_TVA'   => $rate > 0 ? self::money($amounts['vat']) : self::DASH,
                'LIGNE_TTC'   => self::money($amounts['ttc']),
            ];
        }

        $contact_name = $this->contact->isNewItem()
            ? ''
            : trim($this->contact->fields['firstname'] . ' ' . $this->contact->fields['name']);

        // A subscription has no daily rate: its own template, without that column. A cloud one
        // prices subscriptions per month: its own template too, totals in the table.
        $template = new DocxTemplate(self::getTemplateFile(self::annexTemplate($annex['mode'])));
        if ($rows !== []) {
            $template->setRows('DESIGNATION', $rows);
        }
        $template
            ->setHtml('OBJET', (string) $annex['fields']['objet'], null, self::resolveImage(...))
            // Left aligned whatever the template: its paragraph style may be justified
            ->setHtml('DESCRIPTION', (string) $annex['fields']['description'], 'left', self::resolveImage(...))
            // Estimate of a regular annex: removed with its placeholder when empty
            ->setTable('CHIFFRAGE', self::estimateHtml(PresaleEstimate::decode($annex['fields']['estimate'] ?? null)), [75, 25])
            ->setTexts([
                // Title of the annex: TITRE or TITLE
                'TITRE'              => (string) $annex['fields']['title'],
                'TITLE'              => (string) $annex['fields']['title'],
                'LIEU'               => (string) $this->client->fields['town'],
                'DATE'               => $date->format('d/m/Y'),
                'CLIENT'             => (string) $this->client->fields['name'],
                'SIGNATAIRE_INFOTEL' => (string) $this->proposal->fields['sales_name'],
                'SIGNATAIRE_CLIENT'  => $contact_name,
                // Role of the recipient, as typed for the contact at step 1
                'FONCTION_CLIENT'    => $this->contact->isNewItem() ? '' : (string) $this->contact->fields['role'],
                'TAUX_TVA'           => $rate > 0 ? self::number($rate) . ' %' : '',
                'MENTION_TVA'        => $rate > 0 ? '' : __('VAT not applicable: amounts excluding taxes.', 'manageentities'),
                'TOTAL_HT'           => self::money($annex['totals']['ht']),
                'TOTAL_TVA'          => $rate > 0 ? self::money($annex['totals']['vat']) : self::DASH,
                'TOTAL_TTC'          => self::money($annex['totals']['ttc']),
            ]);
        $file = $this->tempFile();
        $template->clearRemaining()->render($file);

        return $file;
    }

    /**
     * Custom properties of the proposal template. The names on the left are the ones declared
     * in the Word document: renaming one means editing the template in Word.
     *
     * @return array<string, string>
     */
    private function getWordProperties(string $reference): array
    {
        $fields  = $this->proposal->fields;
        $client  = $this->client->fields;
        $contact = $this->contact->isNewItem() ? [] : $this->contact->fields;

        return [
            'Client'               => (string) $client['name'],
            'Adresse'              => (string) $client['address'],
            'CP'                   => (string) $client['postcode'],
            'Ville'                => (string) $client['town'],
            'Contact'              => trim(($contact['firstname'] ?? '') . ' ' . ($contact['name'] ?? '')),
            'Fonction contact'     => (string) ($contact['role'] ?? ''),
            'email contact'        => (string) ($contact['email'] ?? ''),
            'telephone contact'    => (string) (($contact['phone'] ?? '') ?: ($contact['mobile'] ?? '')),
            'Type de contrat'      => (string) $fields['proposal_type'],
            'Référence'            => $reference,
            'Commercial'           => (string) $fields['sales_name'],
            'Fonction Commercial'  => (string) $fields['sales_role'],
            'Email Commercial'     => (string) $fields['sales_email'],
            'Téléphone Commercial' => (string) $fields['sales_phone'],
            'Chef de projet'       => (string) $fields['pm_name'],
            'Fonction CP'          => (string) $fields['pm_role'],
            'Email CP'             => (string) $fields['pm_email'],
            'Téléphone CP'         => (string) $fields['pm_phone'],
        ];
    }

    /**
     * Logo of the prospect, null when none (the template keeps its own image)
     */
    private function getLogoFile(): ?string
    {
        $documents_id = (int) $this->client->fields['logo_documents_id'];
        if ($documents_id <= 0) {
            return null;
        }
        $document = new Document();
        if (!$document->getFromDB($documents_id)) {
            return null;
        }
        $file = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
        return is_file($file) && @getimagesize($file) !== false ? $file : null;
    }

    /**
     * Width and height of an image fitted to a height, ratio kept
     *
     * @return array{int, int}
     */
    private static function fitImage(string $file, int $height): array
    {
        $size = getimagesize($file);
        if ($size === false || $size[1] === 0) {
            return [$height, $height];
        }
        return [(int) round($height * $size[0] / $size[1]), $height];
    }

    /** Version as printed in the document: 1.0, 2.0... */
    private static function versionLabel(int $version): string
    {
        return $version . '.0';
    }

    /**
     * Rows of the history of the versions, oldest first: the documents generated before, then
     * the one being generated
     *
     * @return list<array<string, string>>
     */
    private function getVersionRows(int $version): array
    {
        $documents = [];
        foreach ((new Document_Item())->find([
            'itemtype' => PresaleProposal::class,
            'items_id' => $this->proposal->getID(),
        ], ['id']) as $link) {
            $document = new Document();
            // Generated versions only: named "<reference> - <client> - v<n>.docx"
            if ($document->getFromDB((int) $link['documents_id'])
                && preg_match('/ - v(\d+)\.docx$/', (string) $document->fields['name'], $match)
                && (int) $match[1] < $version) {
                $documents[(int) $match[1]] = $document->fields;
            }
        }
        ksort($documents);

        $rows = [];
        foreach ($documents as $number => $document) {
            $rows[] = $this->versionRow(
                $number,
                (string) $document['date_creation'],
                (int) $document['users_id'],
            );
        }
        $rows[] = $this->versionRow($version, (string) $_SESSION['glpi_currenttime'], (int) \Session::getLoginUserID());

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function versionRow(int $version, string $date, int $users_id): array
    {
        return [
            'VERSION_NUM'    => self::versionLabel($version),
            'VERSION_DATE'   => $date !== '' ? date('d/m/Y', (int) strtotime($date)) : '',
            'VERSION_AUTHOR' => $users_id > 0 ? getUserName($users_id) : '',
            'VERSION_CHANGE' => $version === 1
                ? __('Initial version', 'manageentities')
                : sprintf(__('Version %s', 'manageentities'), self::versionLabel($version)),
        ];
    }

    /**
     * Stores the generated file as a GLPI document linked to the proposal
     */
    private function storeDocument(string $file, string $reference, int $version): int
    {
        $name   = sprintf(
            '%s - %s - v%d.docx',
            $reference,
            preg_replace('/[^\w\- ]/u', '', (string) $this->client->fields['name']),
            $version,
        );
        $prefix = uniqid('', true) . '_';
        if (!copy($file, GLPI_UPLOAD_DIR . '/' . $prefix . $name)) {
            throw new RuntimeException(__('The document could not be stored.', 'manageentities'));
        }

        $document = new Document();
        $input    = [
            'entities_id'           => $this->proposal->fields['entities_id'],
            'is_recursive'          => 0,
            'name'                  => $name,
            'documentcategories_id' => (int) (Config::getInstance()->fields['presale_documentcategories_id'] ?? 0),
            'upload_file'           => $prefix . $name,
            '_prefix_filename'      => [$prefix],
            '_no_message'           => true,
            'itemtype'              => PresaleProposal::class,
            'items_id'              => $this->proposal->getID(),
        ];
        if (!$document->can(-1, CREATE, $input) || !($documents_id = $document->add($input))) {
            @unlink(GLPI_UPLOAD_DIR . '/' . $prefix . $name);
            throw new RuntimeException(__('The document could not be stored.', 'manageentities'));
        }
        // Document::add() links the item; make sure even without that input
        if (countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documents_id,
            'itemtype'     => PresaleProposal::class,
            'items_id'     => $this->proposal->getID(),
        ]) === 0) {
            (new Document_Item())->add([
                'documents_id' => $documents_id,
                'itemtype'     => PresaleProposal::class,
                'items_id'     => $this->proposal->getID(),
            ]);
        }

        return (int) $documents_id;
    }

    /**
     * Working file: PHPWord opens the archive according to its extension
     */
    private function tempFile(): string
    {
        $file = GLPI_TMP_DIR . '/presale_' . bin2hex(random_bytes(8)) . '.docx';
        $this->temp_files[] = $file;
        return $file;
    }

    /**
     * Local file of an image of a rich text: a GLPI document (document.send.php?docid=N, the
     * link TinyMCE inserts) the user can see. Any other source is left out of the document.
     */
    private static function resolveImage(string $src): ?string
    {
        $query = (string) parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY);
        if (!str_contains($src, '/front/document.send.php')) {
            return null;
        }
        parse_str($query, $params);
        $documents_id = (int) ($params['docid'] ?? 0);
        $document     = new Document();
        if ($documents_id <= 0 || !$document->getFromDB($documents_id)) {
            return null;
        }
        $options = isset($params['itemtype'], $params['items_id'])
            ? ['itemtype' => (string) $params['itemtype'], 'items_id' => (string) $params['items_id']]
            : [];
        if (!$document->canViewFile($options)) {
            return null;
        }
        $file = GLPI_DOC_DIR . '/' . $document->fields['filepath'];

        return is_file($file) ? $file : null;
    }

    /**
     * Estimate of an annex as an HTML table for DocxTemplate::setTable(): header, section titles
     * on the two columns, lines with their load in man-days, total of the days. Empty when the
     * annex has none.
     *
     * @param list<array{type: string, label: string, days: string}> $rows
     */
    private static function estimateHtml(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // In the language of the Word documents, as their templates
        $html = '<table><tr><th data-shade="005091" data-color="FFFFFF">Description</th>'
            . '<th data-shade="005091" data-color="FFFFFF" data-align="right">Charge (en jours homme)</th></tr>';
        foreach ($rows as $row) {
            if ($row['type'] === PresaleEstimate::TYPE_TITLE) {
                $html .= '<tr><td colspan="2" data-shade="D9E2F3"><strong>' . $escape($row['label']) . '</strong></td></tr>';
                continue;
            }
            $days  = PresalePricing::parseNumber($row['days']);
            $html .= '<tr><td>' . $escape($row['label']) . '</td><td data-align="right">'
                . ($days !== null ? $escape(self::number($days)) : '') . '</td></tr>';
        }
        $html .= '<tr><th data-shade="D9E2F3">Total</th><th data-shade="D9E2F3" data-align="right">'
            . $escape(self::number(PresaleEstimate::total($rows))) . '</th></tr>';

        return $html . '</table>';
    }

    /** Amount in euros, French typography */
    public static function money(float $amount): string
    {
        return (string) (new NumberFormatter('fr_FR', NumberFormatter::CURRENCY))->formatCurrency($amount, 'EUR');
    }

    /** Number without useless decimals: 3 not 3,00; 5,5 not 5,50 */
    public static function number(float $value): string
    {
        $text = number_format($value, 2, ',', ' ');
        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
