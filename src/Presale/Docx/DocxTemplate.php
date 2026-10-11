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

use LogicException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * Generation facade: the .docx carries the layout, PHP provides the data.
 *
 * Two pre-processings are applied to the template before PHPWord steps in:
 *   1. MacroNormalizer   : glues back the placeholders split by Word;
 *   2. ConditionalBlocks : resolves the {{NAME}}...{{/NAME}} blocks via the DOM.
 *
 * Conditions must therefore be declared before any filling.
 *
 * Filling goes through SafeTemplateProcessor and not through PHPWord's
 * TemplateProcessor: the macro repair built into the latter destroys real
 * Word documents from the second document processed in the same process on.
 *
 * @see SafeTemplateProcessor
 */
final class DocxTemplate
{
    public const OPEN  = '{{';
    public const CLOSE = '}}';

    /** Widest image of a rich text, in pixels: the text width of an A4 page with 2 cm margins */
    private const MAX_IMAGE_WIDTH = 642;

    private ?TemplateProcessor $processor = null;

    private string $work_file;

    /** @var array<string,bool> */
    private array $conditions = [];

    private int $repaired = 0;

    public function __construct(private readonly string $templatePath)
    {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException("Template not found: {$this->templatePath}");
        }

        $this->work_file = self::tempFile();
    }

    public function __destruct()
    {
        if (is_file($this->work_file)) {
            @unlink($this->work_file);
        }
    }

    /**
     * Conditional block: {{NAME}} ... {{/NAME}}, each marker alone in its paragraph.
     * To be called before any filling.
     */
    public function setCondition(string $blockName, bool $keep): self
    {
        if ($this->processor !== null) {
            throw new LogicException('Conditions must be declared before filling the template.');
        }

        $this->conditions[$blockName] = $keep;

        return $this;
    }

    /** Number of placeholders rebuilt when the template was opened. */
    public function repairedMacros(): int
    {
        $this->processor();

        return $this->repaired;
    }

    /**
     * Remaining placeholders, body + headers + footers.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $variables = array_values(array_unique($this->processor()->getVariables()));
        sort($variables);

        return $variables;
    }

    public function setText(string $code, string|int|float|null $value): self
    {
        $this->processor()->setValue($code, self::escape((string) ($value ?? '')));

        return $this;
    }

    /** @param array<string,string|int|float|null> $values */
    public function setTexts(array $values): self
    {
        foreach ($values as $code => $value) {
            $this->setText($code, $value);
        }

        return $this;
    }

    /**
     * Replaces a placeholder with rich text typed in the form.
     *
     * The HTML is cleaned then translated into WordprocessingML: bold, italic,
     * underline, paragraphs and lists. The template's paragraph keeps its
     * formatting, the injected text only brings its own.
     *
     * @see RichText
     */
    /**
     * Images of the rich text are embedded when $image_resolver gives the local file of their
     * src (null: left out), at their width in the editor, within the width of the page.
     *
     * @param string|null                    $alignment      alignment forced on the paragraphs of the rich text
     *                                                       (left, center, right, both), null to keep the template's
     * @param (callable(string): ?string)|null $image_resolver local file of the src of an <img>
     */
    public function setHtml(string $code, string $html, ?string $alignment = null, ?callable $image_resolver = null): self
    {
        // The paragraphs and runs of the rich text take the formatting of the placeholder
        $processor  = $this->processor();
        $formatting = ['paragraph' => '', 'run' => ''];
        if ($processor instanceof SafeTemplateProcessor) {
            $placeholder = self::OPEN . $code . self::CLOSE;
            if ($alignment !== null) {
                $processor->setPlaceholderAlignment($placeholder, $alignment);
            }
            $formatting = $processor->getPlaceholderFormatting($placeholder);
        }
        $image = null;
        if ($image_resolver !== null && $processor instanceof SafeTemplateProcessor) {
            $image = static function (string $src, int $width) use ($image_resolver, $processor): string {
                $path = $image_resolver($src);
                $size = $path !== null ? @getimagesize($path) : false;
                if ($size === false) {
                    return '';
                }
                // Height follows the ratio of the image
                return $processor->addMainPartImage([
                    'path'   => $path,
                    'width'  => min($width > 0 ? $width : $size[0], self::MAX_IMAGE_WIDTH),
                    'height' => '',
                ]);
            };
        }
        $processor->setValue($code, RichText::toWordml($html, $formatting['paragraph'], $formatting['run'], $image));

        return $this;
    }

    /**
     * Replaces a placeholder with a table given as HTML (rows, cells, colspan, data-shade,
     * data-align), its columns at the given widths in percent of the page. An empty table
     * removes the placeholder.
     *
     * @param list<int> $widths
     */
    public function setTable(string $code, string $html, array $widths): self
    {
        $processor  = $this->processor();
        $formatting = ['paragraph' => '', 'run' => ''];
        if ($processor instanceof SafeTemplateProcessor) {
            $formatting = $processor->getPlaceholderFormatting(self::OPEN . $code . self::CLOSE);
        }
        $processor->setValue($code, RichText::tableToWordml($html, $widths, $formatting['paragraph'], $formatting['run']));

        return $this;
    }

    /** Inserts an image in place of the placeholder, headers included. */
    public function setImage(string $code, string $imagePath, int $width = 120, int $height = 50): self
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException("Image not found: {$imagePath}");
        }

        $this->processor()->setImageValue($code, [
            'path'   => $imagePath,
            'width'  => $width,
            'height' => $height,
            'ratio'  => true,
        ]);

        return $this;
    }

    /**
     * Inserts an image in place of the placeholder in the headers only, floating in front of
     * the text so that the header keeps its height. To be called BEFORE setImage() on the
     * same placeholder, which then fills the body (the cover page) at its own size.
     */
    public function setHeaderImage(string $code, string $imagePath, int $width, int $height): self
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException("Image not found: {$imagePath}");
        }

        $processor = $this->processor();
        if (!$processor instanceof SafeTemplateProcessor) {
            return $this;
        }
        $processor->setHeaderImageValue($code, [
            'path'   => $imagePath,
            'width'  => $width,
            'height' => $height,
            'ratio'  => true,
        ]);
        $processor->floatHeaderImages();

        return $this;
    }

    /**
     * Duplicates a table row, once per entry, in every table holding the placeholder (a template
     * may show the lines twice, e.g. a summary table and a detailed one). Each call fills the
     * first row still holding it: its placeholders become numbered ones (DESIGNATION#1).
     *
     * @param list<array<string,string|int|float>> $rows
     */
    public function setRows(string $rowPlaceholder, array $rows): self
    {
        $placeholder = self::OPEN . $rowPlaceholder . self::CLOSE;
        $processor   = $this->processor();

        if ($rows === []) {
            while (in_array($rowPlaceholder, $processor->getVariables(), true)) {
                $processor->deleteRow($rowPlaceholder);
            }

            return $this;
        }

        $escaped = array_map(
            static fn(array $row): array => array_map(
                static fn(string|int|float $cell): string => self::escape((string) $cell),
                $row,
            ),
            $rows,
        );

        // Bounded: a row whose placeholder could not be replaced must not loop forever
        for ($tables = 0; $tables < 10 && str_contains($this->mainPart(), $placeholder); $tables++) {
            $processor->cloneRowAndSetValues($rowPlaceholder, $escaped);
        }

        return $this;
    }

    /** Main part of the document being filled */
    private function mainPart(): string
    {
        $processor = $this->processor();

        return $processor instanceof SafeTemplateProcessor ? $processor->getMainPart() : '';
    }

    /** Empties the placeholders left without value, so that the customer never sees them. */
    public function clearRemaining(): self
    {
        foreach ($this->variables() as $variable) {
            $this->processor()->setValue($variable, '');
        }

        return $this;
    }

    public function render(string $outputPath): string
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Output directory not accessible: {$directory}");
        }

        $this->processor()->saveAs($outputPath);

        return $outputPath;
    }

    /** Prepares the template on first use, then reuses the processor. */
    private function processor(): TemplateProcessor
    {
        if ($this->processor !== null) {
            return $this->processor;
        }

        $report         = MacroNormalizer::normalizeFile($this->templatePath, $this->work_file, self::OPEN, self::CLOSE);
        $this->repaired = $report['repaired'];

        ConditionalBlocks::apply($this->work_file, $this->conditions);

        $this->processor = new SafeTemplateProcessor($this->work_file);
        $this->processor->setMacroChars(self::OPEN, self::CLOSE);

        return $this->processor;
    }

    /** Escapes the characters forbidden in WordprocessingML XML. */
    private static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Line breaks typed by the user become Word breaks.
        return str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', $value);
    }

    /** Working copy of the template, in GLPI's temporary directory when available. */
    private static function tempFile(): string
    {
        $directory = defined('GLPI_TMP_DIR') && is_dir(GLPI_TMP_DIR) ? GLPI_TMP_DIR : sys_get_temp_dir();

        $file = tempnam($directory, 'me_docx_');
        if ($file === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        return $file;
    }
}
