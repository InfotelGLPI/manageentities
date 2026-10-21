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
use GlpiPlugin\Manageentities\Presale\Docx\DocxTemplate;
use RuntimeException;

/**
 * Word document of a DAT (last step of its wizard), from the template uploaded in the DAT tab of
 * the configuration (placeholders listed in docs/dat.md):
 * - general information: CLIENT, DATE_DAT, AUTHOR_DAT, VERSION_DAT, logo in the header;
 * - servers: each table row holding {{IP}} is duplicated once per server ({{NOM_DNS}},
 *   {{NOM_INTERNE}} of the server), {{NOM_DNS}} out of a table receives the list of the DNS names;
 * - sections: rich text for *_INFORMATIONS, lines of commands for *_INSTALLATION.
 * The result becomes a GLPI document linked to the client entity: a new version at each
 * generation, the previous ones are kept.
 */
class DatGenerator
{
    /** Height of the client logo in the header band */
    private const HEADER_HEIGHT = 42;

    private Dat $dat;

    /** @var string[] working files, removed at the end */
    private array $temp_files = [];

    public function __construct(Dat $dat)
    {
        $this->dat = $dat;
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
     * Builds the document, stores it as a new GLPI document linked to the client and updates the
     * DAT (version, status, last document)
     *
     * @return int id of the created document
     */
    public function generate(): int
    {
        $version  = (int) $this->dat->fields['version'] + 1;
        $output   = $this->tempFile();
        $this->fill(new DocxTemplate(PresaleTemplate::getFile(PresaleTemplate::DAT)), $version)->render($output);

        $documents_id = $this->storeDocument($output, $version);

        $this->dat->update([
            'id'           => $this->dat->getID(),
            'version'      => $version,
            'documents_id' => $documents_id,
            'status'       => Dat::STATUS_GENERATED,
            'step'         => DatWizard::STEP_SUMMARY,
        ]);

        return $documents_id;
    }

    /**
     * Fills every placeholder of the template, the unknown ones emptied
     */
    public function fill(DocxTemplate $template, int $version): DocxTemplate
    {
        $sections = $this->dat->getSections();
        $servers  = DatServer::getForDat($this->dat->getID());

        // Table rows first: their placeholders are numbered by the duplication, the ones left
        // out of a table are then filled as plain text
        $template->setRows('IP', array_map(
            static fn(array $server): array => [
                'IP'          => (string) $server['ip'],
                'NOM_DNS'     => (string) $server['name'],
                'NOM_INTERNE' => (string) $server['internal_name'],
            ],
            $servers,
        ));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->dat->fields['dat_date']);
        $template->setTexts([
            'CLIENT'      => $sections['CLIENT'],
            'DATE_DAT'    => $date !== false ? $date->format('d/m/Y') : '',
            'AUTHOR_DAT'  => getUserName((int) $this->dat->fields['author_users_id']),
            'VERSION_DAT' => self::versionLabel($version),
            'NOM_DNS'     => implode(', ', array_column($servers, 'name')),
        ]);

        foreach (Dat::SECTIONS as $placeholder) {
            if ($placeholder === 'CLIENT') {
                continue;
            }
            match (DatWizard::getSectionKind($placeholder)) {
                DatWizard::KIND_RICHTEXT => $template->setHtml($placeholder, $sections[$placeholder]),
                // Each line becomes a line break of the "Code" paragraph of the template
                default                  => $template->setText($placeholder, $sections[$placeholder]),
            };
        }

        $logo = $this->getLogoFile();
        if ($logo !== null) {
            [$width, $height] = self::fitImage($logo, self::HEADER_HEIGHT);
            $template->setHeaderImage('LOGO_CLIENT', $logo, $width, $height);
        }

        return $template->clearRemaining();
    }

    /**
     * Version printed in the document, as in its revision table: 1.0, 2.0...
     */
    public static function versionLabel(int $version): string
    {
        return $version . '.0';
    }

    /**
     * Logo of the client entity (EntityLogo), null when none: the header is left without it
     */
    private function getLogoFile(): ?string
    {
        $logo = (new EntityLogo())->find(['entities_id' => (int) $this->dat->fields['entities_id']], [], 1);
        if ($logo === []) {
            return null;
        }
        $document = new Document();
        if (!$document->getFromDB((int) reset($logo)['logos_id'])) {
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

    /**
     * Stores the generated file as a GLPI document linked to the client entity
     */
    private function storeDocument(string $file, int $version): int
    {
        $entities_id = (int) $this->dat->fields['entities_id'];
        $name        = sprintf(
            'DAT - %s - v%s.docx',
            preg_replace('/[^\w\- ]/u', '', $this->dat->getSections()['CLIENT']),
            self::versionLabel($version),
        );
        $prefix = uniqid('', true) . '_';
        if (!copy($file, GLPI_UPLOAD_DIR . '/' . $prefix . $name)) {
            throw new RuntimeException(__('The document could not be stored.', 'manageentities'));
        }

        $document = new Document();
        $input    = [
            'entities_id'           => $entities_id,
            'is_recursive'          => 0,
            'name'                  => $name,
            'documentcategories_id' => (int) (Config::getInstance()->fields['dat_documentcategories_id'] ?? 0),
            'upload_file'           => $prefix . $name,
            '_prefix_filename'      => [$prefix],
            '_no_message'           => true,
            'itemtype'              => \Entity::class,
            'items_id'              => $entities_id,
        ];
        if (!$document->can(-1, CREATE, $input) || !($documents_id = $document->add($input))) {
            @unlink(GLPI_UPLOAD_DIR . '/' . $prefix . $name);
            throw new RuntimeException(__('The document could not be stored.', 'manageentities'));
        }
        // Document::add() links the item; make sure even without that input
        $link = [
            'documents_id' => $documents_id,
            'itemtype'     => \Entity::class,
            'items_id'     => $entities_id,
        ];
        if (countElementsInTable(Document_Item::getTable(), $link) === 0) {
            (new Document_Item())->add($link);
        }

        return (int) $documents_id;
    }

    /**
     * Working file: PHPWord opens the archive according to its extension
     */
    private function tempFile(): string
    {
        $file = GLPI_TMP_DIR . '/dat_' . bin2hex(random_bytes(8)) . '.docx';
        $this->temp_files[] = $file;
        return $file;
    }
}
