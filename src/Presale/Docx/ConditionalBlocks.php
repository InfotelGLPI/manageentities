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
 * Conditional blocks: {{NAME}} ... {{/NAME}}
 *
 * Replaces PHPWord's deleteBlock()/cloneBlock(), whose greedy regular
 * expressions leave an unclosed <w:p> and produce a .docx that Word refuses to
 * open. The processing goes through the DOM here: whole nodes are removed, so
 * the structure always stays consistent.
 */
final class ConditionalBlocks
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const TARGET_PARTS = '#^word/(document|header\d*|footer\d*)\.xml$#';

    /**
     * Applies the conditions to a .docx, in place.
     *
     * @param array<string,bool> $conditions block name => keep it or not
     *
     * @return int number of processed blocks
     */
    public static function apply(string $docxPath, array $conditions): int
    {
        if ($conditions === []) {
            return 0;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException("Unreadable .docx archive: {$docxPath}");
        }

        $applied = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match(self::TARGET_PARTS, $name) !== 1) {
                continue;
            }

            $xml = $zip->getFromName($name);
            if ($xml === false || $xml === '') {
                continue;
            }

            $result = self::applyToXml($xml, $conditions);
            if ($result['applied'] > 0) {
                $zip->addFromString($name, $result['xml']);
                $applied += $result['applied'];
            }
        }
        $zip->close();

        return $applied;
    }

    /**
     * @param array<string,bool> $conditions
     *
     * @return array{xml:string, applied:int}
     */
    public static function applyToXml(string $xml, array $conditions): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['xml' => $xml, 'applied' => 0];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);

        $applied = 0;
        foreach ($conditions as $name => $keep) {
            $open  = self::findParagraph($xpath, DocxTemplate::OPEN . $name . DocxTemplate::CLOSE);
            $close = self::findParagraph($xpath, DocxTemplate::OPEN . '/' . $name . DocxTemplate::CLOSE);

            if ($open === null || $close === null) {
                continue;
            }
            if ($open->parentNode !== $close->parentNode) {
                throw new RuntimeException(
                    "Malformed block {$name}: the opening and closing markers must be at the same level.",
                );
            }

            self::removeRange($open, $close, $keep);
            $applied++;
        }

        return ['xml' => (string) $dom->saveXML(), 'applied' => $applied];
    }

    /** First paragraph containing exactly this marker. */
    private static function findParagraph(DOMXPath $xpath, string $marker): ?DOMElement
    {
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            if ($paragraph instanceof DOMElement && str_contains($paragraph->textContent, $marker)) {
                return $paragraph;
            }
        }

        return null;
    }

    /**
     * Removes the block: only the markers if $keep, the whole range otherwise.
     */
    private static function removeRange(DOMElement $open, DOMElement $close, bool $keep): void
    {
        if ($keep) {
            $open->parentNode?->removeChild($open);
            $close->parentNode?->removeChild($close);

            return;
        }

        $doomed  = [$open];
        $current = $open->nextSibling;
        while ($current instanceof DOMNode && $current !== $close) {
            $doomed[] = $current;
            $current  = $current->nextSibling;
        }
        $doomed[] = $close;

        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
