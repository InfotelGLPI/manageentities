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

namespace GlpiPlugin\Manageentities\Presale\Docx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Appends Word documents at the end of another one, each on a new page.
 *
 * The mechanism is OOXML's own: `w:altChunk`. The annex document is embedded
 * as is in the archive, and Word merges it into the document when opening it.
 * Nothing is copied or reinterpreted, so nothing is lost: page layout, styles,
 * headers, images and fields of the annex remain those of its template.
 *
 * The trade-off fits in one sentence: **Word performs the merge**. The
 * produced file carries the annexes without having integrated them, and a
 * reader that ignores `altChunk` (LibreOffice notably) will not display them.
 * The proposals are meant to be read in Word.
 *
 * Each annex must be filled BEFORE being appended: `docProps/custom.xml` is
 * specific to each document, and the annex keeps its own.
 */
final class DocxAppender
{
    private const NS_W  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_R  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const NS_PR = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const REL_CHUNK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk';
    private const TYPE_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /**
     * @param list<string> $annexes paths of the documents to append, in order
     */
    public function append(string $sourceFile, array $annexes, string $targetFile): void
    {
        if ($annexes === []) {
            throw new RuntimeException('No annex to append.');
        }

        foreach ($annexes as $annex) {
            if (!is_file($annex)) {
                throw new RuntimeException("Annex not found: {$annex}");
            }
        }

        if ($sourceFile !== $targetFile && !copy($sourceFile, $targetFile)) {
            throw new RuntimeException("Unable to copy to {$targetFile}");
        }

        $zip = new ZipArchive();
        if ($zip->open($targetFile) !== true) {
            throw new RuntimeException("Unreadable archive: {$targetFile}");
        }

        $document = $this->read($zip, 'word/document.xml');
        $rels     = $this->read($zip, 'word/_rels/document.xml.rels');
        $types    = $this->read($zip, '[Content_Types].xml');

        $next_id = $this->nextRelationId($rels);
        $added   = [];

        foreach (array_values($annexes) as $rank => $annex) {
            $part   = sprintf('word/annexe%d.docx', $rank + 1);
            $rel_id = 'rId' . ($next_id + $rank);

            $content = file_get_contents($annex);
            if ($content === false) {
                $zip->close();
                throw new RuntimeException("Unable to read: {$annex}");
            }

            $zip->addFromString($part, $content);
            $added[] = ['part' => $part, 'rel_id' => $rel_id];
        }

        $zip->addFromString('word/_rels/document.xml.rels', $this->withRelations($rels, $added));
        $zip->addFromString('[Content_Types].xml', $this->withContentTypes($types, $added));
        $zip->addFromString('word/document.xml', $this->withChunks($document, $added));

        if (!$zip->close()) {
            throw new RuntimeException("Unable to write: {$targetFile}");
        }
    }

    private function read(ZipArchive $zip, string $part): string
    {
        $xml = $zip->getFromName($part);
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException("Part missing from the archive: {$part}");
        }

        return $xml;
    }

    /** Avoids overwriting an existing relationship of the document. */
    private function nextRelationId(string $rels): int
    {
        preg_match_all('/Id="rId(\d+)"/', $rels, $m);
        $ids = array_map('intval', $m[1]);

        return $ids === [] ? 1 : max($ids) + 1;
    }

    /** @param list<array{part:string,rel_id:string}> $added */
    private function withRelations(string $rels, array $added): string
    {
        $dom  = $this->load($rels);
        $root = $dom->documentElement;
        if ($root === null) {
            throw new RuntimeException('Unreadable document relationships.');
        }

        foreach ($added as $entry) {
            $relation = $dom->createElementNS(self::NS_PR, 'Relationship');
            $relation->setAttribute('Id', $entry['rel_id']);
            $relation->setAttribute('Type', self::REL_CHUNK);
            $relation->setAttribute('Target', basename($entry['part']));
            $root->appendChild($relation);
        }

        return (string) $dom->saveXML();
    }

    /** @param list<array{part:string,rel_id:string}> $added */
    private function withContentTypes(string $types, array $added): string
    {
        $dom  = $this->load($types);
        $root = $dom->documentElement;
        if ($root === null) {
            throw new RuntimeException('Unreadable Content_Types.');
        }

        foreach ($added as $entry) {
            $override = $dom->createElementNS(self::NS_CT, 'Override');
            $override->setAttribute('PartName', '/' . $entry['part']);
            $override->setAttribute('ContentType', self::TYPE_DOCX);
            $root->appendChild($override);
        }

        return (string) $dom->saveXML();
    }

    /**
     * Inserts the references to the annexes right before the final `w:sectPr`:
     * they come after all the content, but stay in the section of the
     * document, hence under its headers and page layout.
     *
     * @param list<array{part:string,rel_id:string}> $added
     */
    private function withChunks(string $document, array $added): string
    {
        $dom   = $this->load($document);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);

        $bodies = $xpath->query('//w:body');
        $body   = $bodies !== false ? $bodies->item(0) : null;
        if (!$body instanceof DOMElement) {
            throw new RuntimeException('Document body not found.');
        }

        // The final sectPr describes the page layout; everything must stay before it.
        $sections = $xpath->query('./w:sectPr', $body);
        $sect_pr  = $sections !== false ? $sections->item(0) : null;

        foreach ($added as $entry) {
            $break = $this->pageBreak($dom);
            $chunk = $dom->createElementNS(self::NS_W, 'w:altChunk');
            $chunk->setAttributeNS(self::NS_R, 'r:id', $entry['rel_id']);

            if ($sect_pr !== null) {
                $body->insertBefore($break, $sect_pr);
                $body->insertBefore($chunk, $sect_pr);
            } else {
                $body->appendChild($break);
                $body->appendChild($chunk);
            }
        }

        return (string) $dom->saveXML();
    }

    /** Paragraph holding only a page break: the annex starts at the top of a page. */
    private function pageBreak(DOMDocument $dom): DOMElement
    {
        $paragraph = $dom->createElementNS(self::NS_W, 'w:p');
        $run       = $dom->createElementNS(self::NS_W, 'w:r');
        $break     = $dom->createElementNS(self::NS_W, 'w:br');
        $break->setAttributeNS(self::NS_W, 'w:type', 'page');

        $run->appendChild($break);
        $paragraph->appendChild($run);

        return $paragraph;
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Invalid XML.');
        }

        return $dom;
    }
}
