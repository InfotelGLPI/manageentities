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

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills the custom properties of a Word document and refreshes the displayed
 * value of the DOCPROPERTY fields that use them.
 *
 * Word stores each field in two places: the property itself, in
 * docProps/custom.xml, and the last displayed value, cached in the body of the
 * document. Writing the former without fixing the latter produces a document
 * that opens on the old data until the user forces the fields to update. Both
 * are therefore rewritten together.
 *
 * Two field notations coexist in the documents produced by Word:
 *
 *   simple field    w:fldSimple, the instruction is carried by an attribute
 *   complex field   begin / instrText / separate / displayed value / end
 *
 * Both are supported.
 */
final class DocProperties
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const CUSTOM_PART = 'docProps/custom.xml';

    private const CONTENT_PARTS = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

    /** Internal key carrying the ISO value of a date, invisible to callers. */
    private const ISO_SUFFIX = "\0iso";

    private string $file;

    /** @var array<string,string> property name => value read in the template */
    private array $current = [];

    /** @var array<string,string> property name => value to write */
    private array $pending = [];

    private int $refreshed_fields = 0;

    /** @var list<string> */
    private array $unknown = [];

    public function __construct(string $templateFile)
    {
        if (!is_file($templateFile)) {
            throw new RuntimeException("Template not found: {$templateFile}");
        }

        $this->file    = $templateFile;
        $this->current = $this->readProperties($templateFile);
    }

    /**
     * Custom properties present in the template, with their current value.
     *
     * @return array<string,string>
     */
    public function properties(): array
    {
        return $this->current;
    }

    /**
     * Assigns a value to a property.
     */
    public function set(string $name, string $value): self
    {
        if (!array_key_exists($name, $this->current)) {
            $this->unknown[] = $name;
        }

        $this->pending[$name] = $value;

        return $this;
    }

    /**
     * @param array<string,string> $values
     */
    public function setMany(array $values): self
    {
        foreach ($values as $name => $value) {
            $this->set($name, $value);
        }

        return $this;
    }

    /**
     * Assigns a date. Word keeps the ISO value in the property and displays
     * the formatted string; both must match.
     */
    public function setDate(string $name, DateTimeImmutable $date, string $format = 'd/m/Y'): self
    {
        $this->set($name, $date->format($format));
        $this->pending[$name . self::ISO_SUFFIX] = $date->format('Y-m-d\TH:i:s\Z');

        return $this;
    }

    /**
     * Names given to set() that do not exist in the template.
     *
     * @return list<string>
     */
    public function unknownProperties(): array
    {
        return array_values(array_unique($this->unknown));
    }

    /**
     * Number of fields whose displayed value was rewritten.
     */
    public function refreshedFields(): int
    {
        return $this->refreshed_fields;
    }

    public function saveAs(string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Output directory not accessible: {$directory}");
        }

        if (!copy($this->file, $target)) {
            throw new RuntimeException("Unable to copy the template to {$target}");
        }

        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            throw new RuntimeException("Unable to open: {$target}");
        }

        $this->refreshed_fields = 0;

        // A template without custom properties has nothing to rewrite there.
        if ($zip->locateName(self::CUSTOM_PART) !== false) {
            $zip->addFromString(self::CUSTOM_PART, $this->rewriteProperties($zip));
        }

        foreach ($this->contentParts($zip) as $part) {
            $rewritten = $this->rewriteCachedValues($this->load($zip, $part));
            if ($rewritten !== null) {
                $zip->addFromString($part, $rewritten);
            }
        }

        $zip->close();
    }

    /**
     * @return array<string,string>
     */
    private function readProperties(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new RuntimeException("Unable to open: {$file}");
        }

        $xml = $zip->getFromName(self::CUSTOM_PART);
        $zip->close();

        if ($xml === false || $xml === '') {
            return [];
        }

        $dom = new DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Unreadable XML: ' . self::CUSTOM_PART);
        }

        $properties = [];
        foreach ($dom->getElementsByTagName('property') as $property) {
            $properties[$property->getAttribute('name')] = trim($property->textContent);
        }

        return $properties;
    }

    private function rewriteProperties(ZipArchive $zip): string
    {
        $dom = $this->load($zip, self::CUSTOM_PART);

        foreach ($dom->getElementsByTagName('property') as $property) {
            $name = $property->getAttribute('name');
            if (!array_key_exists($name, $this->pending)) {
                continue;
            }

            $value = $this->pending[$name];
            $node  = $this->firstElement($property);
            if ($node === null) {
                continue;
            }

            // A date is stored as a filetime: the ISO value wins over the display.
            if ($node->localName === 'filetime') {
                $value = $this->pending[$name . self::ISO_SUFFIX] ?? $value;
            }

            $node->nodeValue = '';
            $node->appendChild($dom->createTextNode($value));
        }

        return (string) $dom->saveXML();
    }

    /**
     * Rewrites the displayed value of the DOCPROPERTY fields of a document part.
     * Returns null when the part holds no field to change.
     */
    private function rewriteCachedValues(DOMDocument $dom): ?string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);

        $touched = $this->rewriteSimpleFields($dom, $xpath);
        $touched = $this->rewriteComplexFields($dom, $xpath) || $touched;

        return $touched ? (string) $dom->saveXML() : null;
    }

    private function rewriteSimpleFields(DOMDocument $dom, DOMXPath $xpath): bool
    {
        $touched = false;

        foreach ($xpath->query('//w:fldSimple') ?: [] as $field) {
            if (!$field instanceof DOMElement) {
                continue;
            }
            $value = $this->valueFor($field->getAttributeNS(self::NS_W, 'instr'));
            if ($value === null) {
                continue;
            }

            $runs = [];
            foreach ($xpath->query('./w:r', $field) ?: [] as $run) {
                if ($run instanceof DOMElement) {
                    $runs[] = $run;
                }
            }

            $this->writeValue($dom, $field, $runs, $value);
            $touched = true;
            $this->refreshed_fields++;
        }

        return $touched;
    }

    private function rewriteComplexFields(DOMDocument $dom, DOMXPath $xpath): bool
    {
        $touched   = false;
        $depth     = 0;
        $instr     = '';
        $separated = false;
        /** @var list<DOMElement> $result */
        $result = [];

        foreach ($xpath->query('//w:r') ?: [] as $run) {
            if (!$run instanceof DOMElement) {
                continue;
            }
            $marker = $this->fieldCharType($run, $xpath);

            if ($marker === 'begin') {
                $depth++;
                if ($depth === 1) {
                    $instr     = '';
                    $separated = false;
                    $result    = [];
                }
                continue;
            }

            if ($depth === 0) {
                continue;
            }

            if ($marker === 'end') {
                if ($depth === 1) {
                    $value = $this->valueFor($instr);
                    if ($value !== null && $separated && $run->parentNode !== null) {
                        $this->writeValue($dom, $run->parentNode, $result, $value);
                        $touched = true;
                        $this->refreshed_fields++;
                    }
                }
                $depth--;
                continue;
            }

            // A nested field keeps its own value: only level 1 is touched.
            if ($depth > 1) {
                continue;
            }

            if ($marker === 'separate') {
                $separated = true;
                continue;
            }

            if (!$separated) {
                foreach ($xpath->query('./w:instrText', $run) ?: [] as $text) {
                    $instr .= $text->textContent;
                }
                continue;
            }

            $result[] = $run;
        }

        return $touched;
    }

    /**
     * Writes the value in the first run of the result and removes the others:
     * Word frequently splits the displayed value over several runs.
     *
     * @param list<DOMElement> $runs
     */
    private function writeValue(DOMDocument $dom, DOMNode $container, array $runs, string $value): void
    {
        $first = array_shift($runs);

        foreach ($runs as $extra) {
            $extra->parentNode?->removeChild($extra);
        }

        if ($first === null) {
            $first = $dom->createElementNS(self::NS_W, 'w:r');
            $container->appendChild($first);
        }

        foreach (iterator_to_array($first->getElementsByTagNameNS(self::NS_W, 't')) as $text) {
            $text->parentNode?->removeChild($text);
        }

        $text = $dom->createElementNS(self::NS_W, 'w:t');
        $text->setAttribute('xml:space', 'preserve');
        $text->appendChild($dom->createTextNode($value));
        $first->appendChild($text);
    }

    /**
     * Extracts the property name from a field instruction and returns the
     * value to display, or null when the field is not concerned.
     */
    private function valueFor(string $instruction): ?string
    {
        if (!preg_match('/DOCPROPERTY\s+(?:"([^"]*)"|(\S+))/u', $instruction, $matches)) {
            return null;
        }

        $name = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');

        return $this->pending[$name] ?? null;
    }

    private function fieldCharType(DOMElement $run, DOMXPath $xpath): ?string
    {
        $chars = $xpath->query('./w:fldChar', $run);
        if ($chars === false || $chars->length === 0) {
            return null;
        }

        $char = $chars->item(0);
        if (!$char instanceof DOMElement) {
            return null;
        }

        $type = $char->getAttributeNS(self::NS_W, 'fldCharType');

        return $type !== '' ? $type : null;
    }

    /**
     * @return list<string>
     */
    private function contentParts(ZipArchive $zip): array
    {
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match(self::CONTENT_PARTS, $name) === 1) {
                $parts[] = $name;
            }
        }

        return $parts;
    }

    private function load(ZipArchive $zip, string $part): DOMDocument
    {
        $xml = $zip->getFromName($part);
        if ($xml === false) {
            throw new RuntimeException("Part missing from the document: {$part}");
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException("Unreadable XML: {$part}");
        }

        return $dom;
    }

    private function firstElement(DOMElement $parent): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }
}
