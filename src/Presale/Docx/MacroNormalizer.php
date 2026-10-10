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
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Repairs the placeholders fragmented by Word.
 *
 * When {{CLIENT_NAME}} is typed in Word, the word processor frequently splits
 * the string into several <w:r> (spell checker, language change, revision
 * tracking, rsid). The placeholder then cannot be found by the replacement
 * engine, which fails silently.
 *
 * This class rebuilds each placeholder inside a single <w:t>, keeping the
 * formatting of the first fragment.
 */
final class MacroNormalizer
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS  = 'http://www.w3.org/XML/1998/namespace';

    /** Parts of the .docx that may contain placeholders. */
    private const TARGET_PARTS = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

    /**
     * Normalises a whole .docx into a new file.
     *
     * @return array{repaired:int, parts:array<string,int>} number of rebuilt placeholders
     */
    public static function normalizeFile(
        string $sourcePath,
        string $targetPath,
        string $open = '{{',
        string $close = '}}'
    ): array {
        if (!is_file($sourcePath)) {
            throw new RuntimeException("Template not found: {$sourcePath}");
        }
        if ($sourcePath !== $targetPath && !@copy($sourcePath, $targetPath)) {
            throw new RuntimeException("Unable to copy to: {$targetPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($targetPath) !== true) {
            throw new RuntimeException("Unreadable .docx archive: {$targetPath}");
        }

        $repaired = 0;
        $parts    = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match(self::TARGET_PARTS, $name) !== 1) {
                continue;
            }

            $xml = $zip->getFromName($name);
            if ($xml === false || $xml === '') {
                continue;
            }

            $result = self::normalizeXml($xml, $open, $close);
            if ($result['repaired'] > 0) {
                $zip->addFromString($name, $result['xml']);
                $repaired    += $result['repaired'];
                $parts[$name] = $result['repaired'];
            }
        }

        $zip->close();

        return ['repaired' => $repaired, 'parts' => $parts];
    }

    /**
     * Normalises the XML of a document part.
     *
     * @return array{xml:string, repaired:int}
     */
    public static function normalizeXml(string $xml, string $open = '{{', string $close = '}}'): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['xml' => $xml, 'repaired' => 0];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);

        $pattern  = '/' . preg_quote($open, '/') . '[^{}]{1,200}?' . preg_quote($close, '/') . '/u';
        $repaired = 0;

        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            if ($paragraph instanceof DOMElement) {
                $repaired += self::normalizeParagraph($xpath, $paragraph, $pattern);
            }
        }

        // Spaces typed inside a marker ("{{ TITLE}}") would make it a different variable for
        // PHPWord: once the markers are whole, they are trimmed
        foreach ($xpath->query('//w:t') ?: [] as $node) {
            $text    = $node->textContent;
            $trimmed = (string) preg_replace_callback(
                $pattern,
                static fn(array $match): string => $open . trim(substr($match[0], strlen($open), -strlen($close))) . $close,
                $text,
            );
            if ($trimmed !== $text) {
                $node->nodeValue = '';
                $node->appendChild($dom->createTextNode($trimmed));
                $repaired++;
            }
        }

        return ['xml' => (string) $dom->saveXML(), 'repaired' => $repaired];
    }

    /**
     * Rebuilds the placeholders split inside a paragraph.
     */
    private static function normalizeParagraph(DOMXPath $xpath, DOMElement $paragraph, string $pattern): int
    {
        // Only the <w:t> whose closest paragraph is this one: a paragraph may
        // contain other ones (text boxes, shapes).
        $nodes = [];
        foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
            if ($node instanceof DOMElement && self::closestParagraph($node) === $paragraph) {
                $nodes[] = $node;
            }
        }

        $count = count($nodes);
        if ($count < 2) {
            return 0; // nothing to glue back
        }

        $texts   = [];
        $offsets = [];
        $cursor  = 0;
        foreach ($nodes as $index => $node) {
            $texts[$index]   = $node->textContent;
            $offsets[$index] = $cursor;
            $cursor         += strlen($texts[$index]);
        }

        $full = implode('', $texts);
        if (preg_match_all($pattern, $full, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return 0;
        }

        $repaired = 0;

        // Right to left: the offsets of the previous occurrences stay valid.
        foreach (array_reverse($matches[0]) as $match) {
            [$placeholder, $start] = $match;
            $end                   = $start + strlen($placeholder);

            $first = self::nodeIndexAt($offsets, $texts, $start);
            $last  = self::nodeIndexAt($offsets, $texts, $end - 1);

            if ($first === null || $last === null || $first === $last) {
                continue; // placeholder already whole in a single <w:t>
            }

            // The first fragment receives the whole placeholder.
            $texts[$first] = substr($texts[$first], 0, $start - $offsets[$first]) . $placeholder;

            // The intermediate fragments are emptied.
            for ($i = $first + 1; $i < $last; $i++) {
                $texts[$i] = '';
            }

            // The last one only keeps what follows the placeholder.
            $texts[$last] = substr($texts[$last], $end - $offsets[$last]);

            $repaired++;
        }

        if ($repaired === 0) {
            return 0;
        }

        foreach ($nodes as $index => $node) {
            if ($node->textContent === $texts[$index]) {
                continue;
            }
            $node->nodeValue = '';
            $node->appendChild($node->ownerDocument->createTextNode($texts[$index]));
            $node->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        }

        return $repaired;
    }

    /**
     * Index of the <w:t> covering the given position.
     *
     * @param array<int,int>    $offsets
     * @param array<int,string> $texts
     */
    private static function nodeIndexAt(array $offsets, array $texts, int $position): ?int
    {
        foreach ($offsets as $index => $offset) {
            $length = strlen($texts[$index]);
            if ($length > 0 && $position >= $offset && $position < $offset + $length) {
                return $index;
            }
        }

        return null;
    }

    private static function closestParagraph(DOMNode $node): ?DOMNode
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && $parent->localName === 'p') {
                return $parent;
            }
        }

        return null;
    }
}
