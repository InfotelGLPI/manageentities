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
use DOMXPath;
use ZipArchive;

/**
 * Read-only access to a .docx: what is needed to check a produced document
 * without opening it again in Word.
 *
 * All methods are static and free of side effects. They open the archive,
 * read, close. None writes.
 */
final class DocxInspector
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Parts carrying editorial content. */
    private const CONTENT_PARTS = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

    /**
     * XML of each content part, indexed by name.
     *
     * @return array<string,string>
     */
    public static function parts(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return [];
        }

        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match(self::CONTENT_PARTS, $name) === 1) {
                $parts[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $parts;
    }

    /** XML of all content parts, concatenated. */
    public static function body(string $file): string
    {
        return implode('', self::parts($file));
    }

    /**
     * Visible text of the document, tags removed and entities decoded.
     * Each paragraph is separated by a line feed.
     */
    public static function text(string $file): string
    {
        $text = '';
        foreach (self::parts($file) as $xml) {
            $paragraphs = preg_replace('#</w:p>#', "\n", $xml) ?? '';
            $text      .= html_entity_decode(strip_tags($paragraphs), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $text;
    }

    /** XML of a named part, empty string when missing. */
    public static function part(string $file, string $part): string
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName($part);
        $zip->close();

        return $xml;
    }

    public static function hasPart(string $file, string $part): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }
        $exists = $zip->locateName($part) !== false;
        $zip->close();

        return $exists;
    }

    /**
     * Embedded images.
     *
     * @return list<string>
     */
    public static function media(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return [];
        }

        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/')) {
                $media[] = $name;
            }
        }
        $zip->close();

        return $media;
    }

    /** Number of DOCPROPERTY fields, simple and complex notations together. */
    public static function countFields(string $file): int
    {
        return (int) preg_match_all('/DOCPROPERTY/', self::body($file));
    }

    /**
     * Properties actually requested by a field of the document.
     *
     * The instruction of a complex field is spread over several <w:instrText>:
     * it must be rebuilt paragraph by paragraph before reading the name in it.
     *
     * @return array<string,int> name => number of fields
     */
    public static function fieldNames(string $file): array
    {
        $names = [];

        foreach (self::parts($file) as $xml) {
            $dom = new DOMDocument();
            if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET)) {
                continue;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', self::NS_W);

            foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
                $instruction = '';
                foreach ($xpath->query('.//w:instrText | .//w:fldSimple/@w:instr', $paragraph) ?: [] as $node) {
                    $instruction .= ' ' . $node->textContent;
                }

                preg_match_all('/DOCPROPERTY\s+(?:"([^"]*)"|(\S+))/u', $instruction, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
                    if ($name !== '') {
                        $names[$name] = ($names[$name] ?? 0) + 1;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Placeholders present in the document, written exactly as in the template.
     *
     * The text is rebuilt paragraph by paragraph, so a placeholder that Word
     * split over several runs is still found, as MacroNormalizer will glue it
     * back. The marker is returned verbatim (spaces included): a marker typed
     * as "{{ NAME}}" is not the same as "{{NAME}}" for the template engine.
     *
     * @return array<string,int> marker => number of occurrences
     */
    public static function markers(string $file, string $open = DocxTemplate::OPEN, string $close = DocxTemplate::CLOSE): array
    {
        $pattern = '/' . preg_quote($open, '/') . '[^{}]{1,200}?' . preg_quote($close, '/') . '/u';
        $markers = [];

        foreach (self::parts($file) as $xml) {
            $dom = new DOMDocument();
            if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET)) {
                continue;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', self::NS_W);

            foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
                $text = '';
                // Only the text of this paragraph: a text box nests its own ones.
                foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                    $owner = $xpath->query('ancestor::w:p[1]', $node);
                    if ($owner !== false && $owner->item(0) === $paragraph) {
                        $text .= $node->textContent;
                    }
                }

                preg_match_all($pattern, $text, $matches);
                foreach ($matches[0] as $marker) {
                    $markers[$marker] = ($markers[$marker] ?? 0) + 1;
                }
            }
        }

        return $markers;
    }

    /**
     * Parts whose XML does not load again. An empty list means a sound document.
     *
     * @return list<string>
     */
    public static function brokenParts(string $file): array
    {
        $broken = [];
        foreach (self::parts($file) as $name => $xml) {
            $dom = new DOMDocument();
            if ($xml === '' || !@$dom->loadXML($xml, LIBXML_NONET)) {
                $broken[] = $name;
            }
        }

        return $broken;
    }

    /** The archive opens and passes the consistency check of ZipArchive. */
    public static function isValidArchive(string $file): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        $zip->close();

        return true;
    }
}
