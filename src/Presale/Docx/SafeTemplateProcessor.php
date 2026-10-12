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

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * TemplateProcessor stripped of its automatic macro repair.
 *
 * PHPWord tries to glue back the macros fragmented by Word as soon as the
 * template is opened, in fixBrokenMacros(). Its method is to spot a piece of
 * XML that looks like a broken macro, then to apply strip_tags() to it. Two
 * flaws make it unusable here:
 *
 *   1. The macro characters are stored in a STATIC property. The first
 *      document processed in the PHP process sets them to {{ }}, and every
 *      following one opens its template with that setting, including inside
 *      the constructor, before any call to setMacroChars().
 *
 *   2. With {{ }}, the expression looks for a single opening brace. A real
 *      Word document contains some: revision identifiers, docId GUIDs, custom
 *      properties. The match then spans thousands of characters and
 *      strip_tags() removes all the markup met on the way. The document
 *      silently loses its structure.
 *
 * That repair is not needed: MacroNormalizer does it beforehand, through the
 * DOM, without touching the rest of the document. The method is therefore
 * neutralised.
 *
 * @see MacroNormalizer
 */
final class SafeTemplateProcessor extends TemplateProcessor
{
    /**
     * Returns the part unchanged. Macros are rebuilt by MacroNormalizer
     * before the template is opened.
     *
     * @param string $documentPart
     *
     * @return string
     */
    protected function fixBrokenMacros($documentPart)
    {
        return $documentPart;
    }

    /**
     * setImageValue() restricted to the headers: the body and the footers are set aside during
     * the replacement, so that the same placeholder can get another size in the body
     *
     * @param string               $search placeholder name, without the macro characters
     * @param array<string, mixed> $replace image arguments of setImageValue()
     */
    public function setHeaderImageValue(string $search, array $replace): void
    {
        $main    = $this->tempDocumentMainPart;
        $footers = $this->tempDocumentFooters;
        // A placeholder-free body: setImageValue() only finds the headers
        $this->tempDocumentMainPart = '<w:document/>';
        $this->tempDocumentFooters  = [];
        try {
            $this->setImageValue($search, $replace);
        } finally {
            $this->tempDocumentMainPart = $main;
            $this->tempDocumentFooters  = $footers;
        }
    }

    /**
     * Adds an image to the main part and returns its <w:pict>, to be placed in a run by the
     * caller (an image of a rich text). setImageValue() does the work on a stand-in main part
     * holding a single placeholder: the archive gets the media, its content type and the
     * relation, and the <w:pict> is cut out of the stand-in.
     *
     * @param array<string, mixed> $image image arguments of setImageValue()
     */
    public function addMainPartImage(array $image): string
    {
        $main    = $this->tempDocumentMainPart;
        $headers = $this->tempDocumentHeaders;
        $footers = $this->tempDocumentFooters;
        $this->tempDocumentMainPart = '<w:document><w:r><w:t>' . DocxTemplate::OPEN . 'RICH_TEXT_IMAGE' . DocxTemplate::CLOSE . '</w:t></w:r></w:document>';
        $this->tempDocumentHeaders  = [];
        $this->tempDocumentFooters  = [];
        try {
            $this->setImageValue('RICH_TEXT_IMAGE', $image);
            $standin = (string) $this->tempDocumentMainPart;
        } finally {
            $this->tempDocumentMainPart = $main;
            $this->tempDocumentHeaders  = $headers;
            $this->tempDocumentFooters  = $footers;
        }

        return preg_match('#<w:pict>.*?</w:pict>#s', $standin, $match) ? $match[0] : '';
    }

    /**
     * Turns the images inserted by setImageValue() in the headers into images floating in
     * front of the text ("Format > Wrap text > In front of text" in Word): an inline image
     * makes the header grow with its height, pushing down the body of every page.
     *
     * Only the VML images produced by setImageValue() are changed (style "width:…;height:…"),
     * the other images of the headers are left as they are.
     */
    public function floatHeaderImages(): void
    {
        foreach ($this->tempDocumentHeaders as $index => $xml) {
            $this->tempDocumentHeaders[$index] = (string) preg_replace(
                '#<v:shape type="\#_x0000_t75" style="(width:[^;"]+;height:[^;"]+)"#',
                '<v:shape type="#_x0000_t75" style="position:absolute;margin-left:0;margin-top:0;'
                    . 'z-index:251659264;mso-position-horizontal:right;mso-position-horizontal-relative:margin;'
                    . 'mso-position-vertical-relative:line;$1"',
                $xml,
            );
        }
    }

    /** Main part of the document, as filled so far */
    public function getMainPart(): string
    {
        return (string) $this->tempDocumentMainPart;
    }

    /**
     * Forces the alignment of the paragraph holding a placeholder in the main part, whatever its
     * style says (e.g. a justified style): a <w:jc> in the <w:pPr> of the paragraph, replacing
     * the one already there. getPlaceholderFormatting() then copies it on the following
     * paragraphs of a rich text.
     *
     * @param string $alignment left, center, right or both
     */
    public function setPlaceholderAlignment(string $placeholder, string $alignment): void
    {
        $xml      = (string) $this->tempDocumentMainPart;
        $position = strpos($xml, $placeholder);
        if ($position === false || !in_array($alignment, ['left', 'center', 'right', 'both'], true)) {
            return;
        }
        $before    = substr($xml, 0, $position);
        $paragraph = max((int) strrpos($before, '<w:p>'), (int) strrpos($before, '<w:p '));
        $head      = substr($xml, $paragraph, $position - $paragraph);
        $jc        = '<w:jc w:val="' . $alignment . '"/>';

        if (preg_match('#<w:pPr>(.*?)</w:pPr>#s', $head, $match, PREG_OFFSET_CAPTURE)) {
            $content = (string) preg_replace('#<w:jc [^>]*/>#', '', $match[1][0]);
            // In a <w:pPr>, w:jc comes after the spacing and indentation, before w:rPr
            $content = str_contains($content, '<w:rPr>')
                ? (string) preg_replace('#<w:rPr>#', $jc . '<w:rPr>', $content, 1)
                : $content . $jc;
            $head = substr_replace($head, '<w:pPr>' . $content . '</w:pPr>', $match[0][1], strlen($match[0][0]));
        } else {
            $head = (string) preg_replace('#^(<w:p(?: [^>]*)?>)#', '$1<w:pPr>' . $jc . '</w:pPr>', $head, 1);
        }

        $this->tempDocumentMainPart = substr($xml, 0, $paragraph) . $head . substr($xml, $position);
    }

    /**
     * Formatting of the paragraph and of the run holding a placeholder in the main part:
     * the <w:pPr> and <w:rPr> elements, empty strings when absent or when not found.
     *
     * The rich text inserted in place of a placeholder opens its own paragraphs and runs:
     * without this formatting they would fall back to the paragraph style, which is not
     * necessarily the font of the placeholder (e.g. a Times New Roman style whose
     * paragraph forces Verdana).
     *
     * @return array{paragraph: string, run: string}
     */
    public function getPlaceholderFormatting(string $placeholder): array
    {
        $xml      = (string) $this->tempDocumentMainPart;
        $position = strpos($xml, $placeholder);
        if ($position === false) {
            return ['paragraph' => '', 'run' => ''];
        }

        $before    = substr($xml, 0, $position);
        $paragraph = max((int) strrpos($before, '<w:p>'), (int) strrpos($before, '<w:p '));
        $run       = max((int) strrpos($before, '<w:r>'), (int) strrpos($before, '<w:r '));

        $ppr = '';
        if (preg_match('#^<w:p[ >].*?(<w:pPr>.*?</w:pPr>|<w:pPr/>)#s', substr($xml, $paragraph, $run - $paragraph), $match)) {
            $ppr = $match[1];
        }
        $rpr = '';
        if (preg_match('#^<w:r[ >]\s*(<w:rPr>.*?</w:rPr>)#s', substr($xml, $run, $position - $run), $match)) {
            $rpr = $match[1];
        }

        return ['paragraph' => $ppr, 'run' => $rpr];
    }
}
