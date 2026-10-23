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
use DOMText;

/**
 * Rich text typed in the form: cleaning, display, Word injection.
 *
 * The editor of the interface produces HTML. That HTML makes three trips, and
 * each one has its requirements:
 *
 *   1. it is STORED in the database: it must therefore be cleaned before,
 *      since what enters the database comes out in a page one day;
 *   2. it is DISPLAYED again in the editor and in the lists;
 *   3. it is INJECTED into the Word document, where HTML means nothing: it
 *      has to be translated into WordprocessingML.
 *
 * The accepted vocabulary is deliberately short: bold, italic, underline,
 * paragraphs, bulleted and numbered lists. This is not a technical limit but
 * a choice: everything accepted here must have a faithful equivalent in the
 * Word document, otherwise the person typing would see on screen something
 * other than what they will sign.
 *
 * Lists do not go through word/numbering.xml but through a tab followed by
 * its bullet. This is deliberate: a real Word numbering requires enriching the
 * archive and making the numberings of each annex coexist once they are put
 * end to end, for a visually identical result. The tab depends on nothing and
 * survives the assembly.
 */
final class RichText
{
    /** Accepted inline tags => style mark applied to the text. */
    private const INLINE = [
        'strong' => 'b',
        'b'      => 'b',
        'em'     => 'i',
        'i'      => 'i',
        'u'      => 'u',
    ];

    /** Accepted block tags. */
    private const BLOCK = ['p', 'div', 'ul', 'ol', 'li', 'br'];

    /** Table tags kept by the cleaning, rendered as a Word table (only the rows and cells count) */
    private const TABLE = ['table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'];

    /** Text width of an A4 page with 2 cm margins, in twentieths of a point: width of a table */
    private const TABLE_WIDTH = 9638;

    /** Widths of the columns of the next table, in percent (estimate of an annex), [] for even */
    private static array $column_widths = [];

    /**
     * Tags whose CONTENT goes away with the tag.
     *
     * The general rule is the opposite: an unknown tag disappears leaving its
     * text, so as not to punish a paste from Word. But the content of these is
     * not prose: unwrapping a <script> would write "alert(1)" in black and
     * white in the annex, and a <style> would print its stylesheet there.
     */
    private const SILENT = [
        'script', 'style', 'noscript', 'template',
        'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link',
    ];

    /**
     * Cleans the HTML of the editor: only the accepted tags survive, never
     * their attributes.
     *
     * A `contenteditable` editor produces what the browser is willing to give
     * it, and above all what a paste from Word or from the Web drops in it:
     * styles, classes, whole tags. None of that is to cross this door.
     */
    public static function sanitize(string $html): string
    {
        $document = self::parse($html);

        if ($document === null) {
            return '';
        }

        $clean = '';

        foreach (iterator_to_array($document->childNodes) as $node) {
            $clean .= self::cleanNode($node);
        }

        $clean = trim($clean);

        // An empty editor often leaves a carcass: a paragraph without text, or
        // the line break the browser drops in it alone.
        return self::isEmpty($clean) ? '' : $clean;
    }

    /** True when the HTML carries neither text nor image, whatever its tags. */
    public static function isEmpty(string $html): bool
    {
        return trim(self::toPlainText($html)) === '' && stripos($html, '<img') === false;
    }

    /**
     * Text only, formatting removed.
     *
     * Used wherever a summary is shown: the list of annexes, the label of a
     * checkbox. A bullet becomes a dash there, for lack of better on one line.
     */
    public static function toPlainText(string $html): string
    {
        $text = preg_replace('#<(br|/p|/li|/div)\s*/?>#i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Translates the HTML into WordprocessingML, ready to replace a marker.
     *
     * The produced fragment replaces the TEXT of a marker, not its paragraph:
     * it therefore starts by closing the run hosting it and ends by opening a
     * new one, which the document will close itself. It is the same mechanism
     * as the line breaks in DocxTemplate: proven, and without it the document
     * would have to be rewritten through the DOM.
     *
     * Intended consequence: the first paragraph INHERITS from the template's
     * paragraph: its font, spacing, alignment. The layout stays with the Word
     * document, here too.
     *
     * The following paragraphs and every run would only get the paragraph
     * STYLE, which may differ from the formatting applied on the placeholder
     * itself (a Times New Roman style whose paragraph forces Verdana): the
     * <w:pPr> of the placeholder's paragraph and the <w:rPr> of its run are
     * therefore copied on each of them, the bold / italic / underline of the
     * rich text being added to the run properties.
     *
     * Images are drawn by $image, given the src and the width of the <img>: it returns the
     * <w:pict> of the image, or '' to leave it out. Without it, they are left out.
     *
     * @param string                               $paragraph_properties <w:pPr> of the placeholder's paragraph, or ''
     * @param string                               $run_properties       <w:rPr> of the placeholder's run, or ''
     * @param (callable(string, int): string)|null $image                image of an <img>, see above
     */
    /**
     * A table of fixed columns (in percent of the page width), rendered by toWordml(): the
     * estimate of an annex
     *
     * @param list<int> $widths
     */
    public static function tableToWordml(string $html, array $widths, string $paragraph_properties = '', string $run_properties = ''): string
    {
        self::$column_widths = $widths;
        try {
            return self::toWordml($html, $paragraph_properties, $run_properties);
        } finally {
            self::$column_widths = [];
        }
    }

    public static function toWordml(string $html, string $paragraph_properties = '', string $run_properties = '', ?callable $image = null): string
    {
        $blocks = self::blocks(self::sanitize($html));

        if ($blocks === []) {
            return '';
        }

        return '</w:t></w:r>'
            . self::paragraphs($blocks, $paragraph_properties, $run_properties, $image)
            . '<w:r>' . $run_properties . '<w:t xml:space="preserve">';
    }

    /**
     * Blocks as paragraphs, the first one in the paragraph already open: the following ones
     * close it and open their own, with the same paragraph properties.
     *
     * The editor separates its paragraphs by a blank line, the template's paragraph often has
     * no spacing at all (w:after="0"): an empty line between two paragraphs, as on screen. The
     * items of a list follow each other without one; a table sits between two paragraphs.
     *
     * @param list<list<array>>                    $blocks
     * @param (callable(string, int): string)|null $image
     */
    private static function paragraphs(array $blocks, string $paragraph_properties, string $run_properties, ?callable $image): string
    {
        $xml           = '';
        $previous_kind = null;
        foreach ($blocks as $rank => $block) {
            $kind = isset($block[0]['table']) ? 'table' : (($block[0]['tabs'] ?? 0) > 0 ? 'item' : 'text');
            if ($rank > 0) {
                if (!($kind === 'item' && $previous_kind === 'item')) {
                    $xml .= '</w:p><w:p>' . $paragraph_properties;
                }
                $xml .= '</w:p><w:p>' . $paragraph_properties;
            }
            $xml .= $kind === 'table'
                ? '</w:p>' . self::table($block[0]['table'], $paragraph_properties, $run_properties, $image) . '<w:p>' . $paragraph_properties
                : self::runs($block, $run_properties, $image);
            $previous_kind = $kind;
        }

        return $xml;
    }

    /**
     * A table of the rich text: the width of the page shared between its columns, thin borders,
     * header cells in bold. Its cells hold the same rich text as the rest.
     *
     * @param list<list<array{html: string, header: bool, span: int, shade: string, color: string, align: string}>> $rows
     * @param (callable(string, int): string)|null                                                $image
     */
    private static function table(array $rows, string $paragraph_properties, string $run_properties, ?callable $image): string
    {
        $columns = max(array_map(static fn(array $cells): int => array_sum(array_column($cells, 'span')), $rows));
        // Columns of different widths (data-widths on the table, in percent), else even ones
        $widths = self::$column_widths !== [] && count(self::$column_widths) === $columns
            ? array_map(static fn(int $pct): int => intdiv(self::TABLE_WIDTH * $pct, 100), self::$column_widths)
            : array_fill(0, $columns, intdiv(self::TABLE_WIDTH, $columns));
        $width   = intdiv(self::TABLE_WIDTH, $columns);
        $border  = '<w:%s w:val="single" w:sz="4" w:space="0" w:color="A6A6A6"/>';
        $borders = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $borders .= sprintf($border, $side);
        }

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/>'
            . '<w:tblBorders>' . $borders . '</w:tblBorders>'
            . '<w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr><w:tblGrid>';
        foreach ($widths as $width) {
            $xml .= '<w:gridCol w:w="' . $width . '"/>';
        }
        $xml .= '</w:tblGrid>';

        foreach ($rows as $cells) {
            $xml    .= '<w:tr>';
            $column  = 0;
            $covered = array_sum(array_column($cells, 'span'));
            foreach ($cells as $idx => $cell) {
                $html = $cell['header'] ? '<strong>' . $cell['html'] . '</strong>' : $cell['html'];
                // The last cell of a short row spans the missing columns
                $span   = $cell['span'] + ($idx === count($cells) - 1 ? $columns - $covered : 0);
                $width  = array_sum(array_slice($widths, $column, $span));
                $column += $span;
                $blocks = self::blocks(self::sanitize($html));
                // Alignment of the cell (data-align), on top of the paragraph of the placeholder
                $cell_paragraph = $paragraph_properties;
                if (in_array($cell['align'], ['right', 'center'], true)) {
                    $jc             = '<w:jc w:val="' . $cell['align'] . '"/>';
                    $cell_paragraph = $cell_paragraph === ''
                        ? '<w:pPr>' . $jc . '</w:pPr>'
                        : (string) preg_replace('#<w:jc [^>]*/>#', '', $cell_paragraph);
                    if (!str_contains($cell_paragraph, $jc)) {
                        $cell_paragraph = (string) preg_replace('#(<w:rPr>|</w:pPr>)#', $jc . '$1', $cell_paragraph, 1);
                    }
                }
                // Text colour of the cell (data-color), on top of the run of the placeholder
                $cell_run = $run_properties;
                if (preg_match('/^[0-9A-F]{6}$/', $cell['color'])) {
                    $colour   = '<w:color w:val="' . $cell['color'] . '"/>';
                    $base     = (string) preg_replace(['#^<w:rPr>(.*)</w:rPr>$#s', '#<w:color [^>]*/>#'], ['$1', ''], $run_properties);
                    $cell_run = '<w:rPr>' . self::mergeRunProperties($colour, $base) . '</w:rPr>';
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>'
                    . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                    . (preg_match('/^[0-9A-F]{6}$/', $cell['shade']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $cell['shade'] . '"/>' : '')
                    . '</w:tcPr><w:p>' . $cell_paragraph
                    . self::paragraphs($blocks, $cell_paragraph, $cell_run, $image)
                    . '</w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    /** Loads an HTML fragment, or null when it is empty or unreadable. */
    private static function parse(string $html): ?DOMElement
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            LIBXML_NONET | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    /** Rewrites a node, keeping only what is accepted. */
    private static function cleanNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return '<br>';
        }

        // An image keeps its source and its width only: the document it points to is
        // resolved, and its rights checked, when the Word document is built
        if ($tag === 'img') {
            $src = trim($node->getAttribute('src'));
            if ($src === '') {
                return '';
            }
            $width = (int) $node->getAttribute('width');

            return sprintf(
                '<img src="%s"%s>',
                htmlspecialchars($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $width > 0 ? sprintf(' width="%d"', $width) : '',
            );
        }

        if (in_array($tag, self::SILENT, true)) {
            return '';
        }

        $inner = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $inner .= self::cleanNode($child);
        }

        // A table keeps its structure, never its attributes (widths, borders of the editor):
        // the Word table takes the width of the page, with thin borders
        if (in_array($tag, self::TABLE, true)) {
            // A cell keeps its span, and the shading of a header row (data-shade, set by the
            // estimate of an annex): a colour of the editor is never kept
            $attributes = '';
            $span       = (int) $node->getAttribute('colspan');
            if (in_array($tag, ['td', 'th'], true) && $span > 1) {
                $attributes .= sprintf(' colspan="%d"', $span);
            }
            // Shading and text colour as RRGGBB, alignment right or center: nothing else
            foreach (['data-shade', 'data-color'] as $attribute) {
                $colour = $node->getAttribute($attribute);
                if (in_array($tag, ['td', 'th'], true) && preg_match('/^[0-9A-F]{6}$/', $colour)) {
                    $attributes .= sprintf(' %s="%s"', $attribute, $colour);
                }
            }
            $align = $node->getAttribute('data-align');
            if (in_array($tag, ['td', 'th'], true) && in_array($align, ['right', 'center'], true)) {
                $attributes .= sprintf(' data-align="%s"', $align);
            }
            return sprintf('<%s%s>%s</%s>', $tag, $attributes, $inner, $tag);
        }

        // Unknown tag: it disappears, its content stays. Removing the text
        // along with the tag would punish the person who pasted from Word.
        if (!isset(self::INLINE[$tag]) && !in_array($tag, self::BLOCK, true)) {
            return $inner;
        }

        if (trim(strip_tags($inner)) === '' && !str_contains($inner, '<br>') && !str_contains($inner, '<img ')) {
            return '';
        }

        return sprintf('<%s>%s</%s>', $tag, $inner, $tag);
    }

    /**
     * Splits the cleaned HTML into paragraphs, each being a sequence of runs.
     *
     * @return list<list<array{text: string, styles: list<string>, tabs: int, break: bool, image?: array{src: string, width: int}, table?: list<list<array{html: string, header: bool}>>}>>
     */
    private static function blocks(string $html): array
    {
        $body = self::parse($html);

        if ($body === null) {
            return [];
        }

        $blocks  = [];
        $current = [];

        self::collect($body, [], 0, $blocks, $current);

        if (self::hasText($current)) {
            $blocks[] = $current;
        }

        return array_values(array_filter($blocks, static fn(array $block): bool => $block !== []));
    }

    /**
     * Walks the tree accumulating runs, and closes a block on each block
     * element met.
     *
     * @param list<string>                                                           $styles
     * @param list<list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}>> $blocks
     * @param list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}>       $current
     */
    private static function collect(DOMNode $node, array $styles, int $level, array &$blocks, array &$current): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                $text = (string) $child->nodeValue;

                if (trim($text) !== '') {
                    $current[] = ['text' => $text, 'styles' => $styles, 'tabs' => 0, 'break' => false];
                }

                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if ($tag === 'br') {
                $current[] = ['text' => '', 'styles' => [], 'tabs' => 0, 'break' => true];
                continue;
            }

            if ($tag === 'img') {
                $current[] = [
                    'text'   => '',
                    'styles' => [],
                    'tabs'   => 0,
                    'break'  => false,
                    'image'  => ['src' => $child->getAttribute('src'), 'width' => (int) $child->getAttribute('width')],
                ];
                continue;
            }

            if (isset(self::INLINE[$tag])) {
                $child_styles = array_values(array_unique([...$styles, self::INLINE[$tag]]));
                self::collect($child, $child_styles, $level, $blocks, $current);
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol') {
                self::closeBlock($blocks, $current);
                self::collectList($child, $tag === 'ol', $styles, $level + 1, $blocks);
                continue;
            }

            if ($tag === 'table') {
                self::closeBlock($blocks, $current);
                $rows = self::collectTable($child);
                if ($rows !== []) {
                    $blocks[] = [['text' => '', 'styles' => [], 'tabs' => 0, 'break' => false, 'table' => $rows]];
                }
                continue;
            }

            // p, div, or any block: it closes the current one.
            self::closeBlock($blocks, $current);
            self::collect($child, $styles, $level, $blocks, $current);
            self::closeBlock($blocks, $current);
        }
    }

    /**
     * Rows of a table, each cell kept as cleaned HTML: it is rendered with the same rules as
     * the rest of the text, paragraphs and lists included
     *
     * @return list<list<array{html: string, header: bool, span: int, shade: string, color: string, align: string}>>
     */
    private static function collectTable(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            // A nested table is rendered as its text: its rows belong to it, not to this one
            if ($tr->parentNode === null || self::closestTable($tr) !== $table) {
                continue;
            }
            $cells = [];
            foreach (iterator_to_array($tr->childNodes) as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $html = '';
                foreach (iterator_to_array($cell->childNodes) as $child) {
                    $html .= (string) $cell->ownerDocument?->saveHTML($child);
                }
                $cells[] = [
                    'html'   => $html,
                    'header' => strtolower($cell->tagName) === 'th',
                    'span'   => max(1, (int) $cell->getAttribute('colspan')),
                    'shade'  => (string) $cell->getAttribute('data-shade'),
                    'color'  => (string) $cell->getAttribute('data-color'),
                    'align'  => (string) $cell->getAttribute('data-align'),
                ];
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private static function closestTable(DOMNode $node): ?DOMElement
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'table') {
                return $parent;
            }
        }

        return null;
    }

    /**
     * @param list<string>                                                           $styles
     * @param list<list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}>> $blocks
     */
    private static function collectList(DOMElement $list, bool $numbered, array $styles, int $level, array &$blocks): void
    {
        $rank = 0;

        foreach (iterator_to_array($list->childNodes) as $item) {
            if (!$item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $rank++;

            $current = [[
                'text'   => $numbered ? $rank . '. ' : '• ',
                'styles' => [],
                'tabs'   => $level,
                'break'  => false,
            ]];

            self::collect($item, $styles, $level, $blocks, $current);

            if (self::hasText($current)) {
                $blocks[] = $current;
            }
        }
    }

    /**
     * @param list<list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}>> $blocks
     * @param list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}>       $current
     */
    private static function closeBlock(array &$blocks, array &$current): void
    {
        if (self::hasText($current)) {
            $blocks[] = $current;
        }

        $current = [];
    }

    /** @param list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}> $block */
    private static function hasText(array $block): bool
    {
        foreach ($block as $run) {
            if ($run['break'] || isset($run['image']) || isset($run['table']) || trim($run['text']) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{text:string,styles:list<string>,tabs:int,break:bool,image?:array{src:string,width:int},table?:list<list<array{html:string,header:bool}>>}> $block */
    private static function runs(array $block, string $run_properties = '', ?callable $image = null): string
    {
        $xml = '';

        // Base formatting of the placeholder's run, without the wrapping element
        $base = (string) preg_replace('#^<w:rPr>(.*)</w:rPr>$#s', '$1', $run_properties);

        foreach ($block as $run) {
            if ($run['break']) {
                $xml .= '<w:r>' . $run_properties . '<w:br/></w:r>';
                continue;
            }

            if (isset($run['image'])) {
                $drawing = $image !== null ? $image($run['image']['src'], $run['image']['width']) : '';
                if ($drawing !== '') {
                    $xml .= '<w:r>' . $run_properties . $drawing . '</w:r>';
                }
                continue;
            }

            // The rich text styles (b, i, u) come first: in a <w:rPr>, w:b and w:i precede
            // the fonts and sizes of the schema order, and Word rejects them after w:sz
            $properties = '';
            foreach ($run['styles'] as $style) {
                $properties .= sprintf('<w:%s/>', $style);
            }
            $properties = self::mergeRunProperties($properties, $base);

            $xml .= '<w:r>'
                . ($properties !== '' ? '<w:rPr>' . $properties . '</w:rPr>' : '')
                . str_repeat('<w:tab/>', $run['tabs'])
                . '<w:t xml:space="preserve">'
                . htmlspecialchars($run['text'], ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</w:t></w:r>';
        }

        return $xml;
    }

    /**
     * Run properties of the rich text (<w:b/>, <w:i/>, <w:u/>) merged into the ones of the
     * placeholder's run, in the order of the WordprocessingML schema: rStyle and rFonts come
     * before b and i, which come before the colour, the size and the underline
     *
     * @param string $styles rich text properties, e.g. '<w:b/><w:u/>'
     * @param string $base   content of the <w:rPr> of the placeholder's run
     */
    private static function mergeRunProperties(string $styles, string $base): string
    {
        if ($base === '') {
            return $styles;
        }
        if ($styles === '') {
            return $base;
        }

        // Elements of the placeholder's run, the toggles of the rich text replacing theirs
        $elements = [];
        preg_match_all('#<w:([A-Za-z]+)\b(?:[^>]*/>|[^>]*>.*?</w:\1>)#s', $base, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $elements[$match[1]] = $match[0];
        }
        preg_match_all('#<w:([a-z]+)\b[^>]*/>#', $styles, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $elements[$match[1]] = $match[0];
        }

        $order = [
            'rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike',
            'outline', 'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden',
            'color', 'spacing', 'w', 'kern', 'position', 'sz', 'szCs', 'highlight', 'u', 'effect',
            'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath',
        ];
        $rank = array_flip($order);
        uksort($elements, static fn(string $a, string $b): int => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));

        return implode('', $elements);
    }
}
