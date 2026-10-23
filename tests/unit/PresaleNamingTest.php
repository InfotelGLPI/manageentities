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

namespace GlpiPlugin\Manageentities\Tests\Unit;

use GlpiPlugin\Manageentities\PresaleCounter;
use GlpiPlugin\Manageentities\PresaleGenerator;
use GlpiPlugin\Manageentities\PresaleProposal_Annex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Names deduced by the pre-sales: annex titles, type of contract, label, reference prefix
 */
class PresaleNamingTest extends TestCase
{
    public function testTheFirstVersionOfADocumentHasNoNumber(): void
    {
        $this->assertSame('SOC261019 - Société Test.docx', PresaleGenerator::getDocumentName('SOC261019', 'Société Test', 1));
        $this->assertSame('SOC261019_V2 - Société Test.docx', PresaleGenerator::getDocumentName('SOC261019', 'Société Test', 2));
        $this->assertSame('SOC261019_V3 - LOréal.docx', PresaleGenerator::getDocumentName('SOC261019', "L'Oréal", 3));
    }

    public static function documentVersionProvider(): iterable
    {
        yield 'first version'        => ['SOC261019 - Société Test.docx', 1];
        yield 'next version'         => ['SOC261019_V2 - Société Test.docx', 2];
        yield 'monthly reference'    => ['SOC261001_V12 - Client.docx', 12];
        yield 'name of the releases' => ['SOC261001 - Client - v3.docx', 3];
        yield 'another document'     => ['Contrat signé.docx', null];
        yield 'not a docx'           => ['SOC261019 - Client.pdf', null];
    }

    #[DataProvider('documentVersionProvider')]
    public function testTheVersionIsReadFromTheName(string $name, ?int $expected): void
    {
        $this->assertSame($expected, PresaleGenerator::getDocumentVersion($name));
    }

    public function testAnnexesAreNumberedInFrench(): void
    {
        $this->assertSame('Annexe 2 : Expertise GLPI', PresaleProposal_Annex::numberTitle(2, 'Expertise GLPI'));
    }

    public function testNumberingReplacesTheNumberOfTheModel(): void
    {
        // The name of the model may already hold a number: it is not doubled
        $this->assertSame('Annexe 3 : Expertise GLPI', PresaleProposal_Annex::numberTitle(3, 'Annexe 1 : Expertise GLPI'));
    }

    public static function strippedTitleProvider(): iterable
    {
        yield 'colon'          => ['Annexe 1 : Expertise', 'Expertise'];
        yield 'no space'       => ['Annexe 12: Support', 'Support'];
        yield 'dash'           => ['annexe 2 - Projet', 'Projet'];
        yield 'en dash'        => ['ANNEXE 4 – Souscription', 'Souscription'];
        yield 'english "annex"' => ['Annex 5 : Training', 'Training'];
        yield 'not numbered'   => ['Expertise GLPI', 'Expertise GLPI'];
        yield 'word inside'    => ['Expertise annexe 1 : x', 'Expertise annexe 1 : x'];
    }

    #[DataProvider('strippedTitleProvider')]
    public function testStripNumber(string $title, string $expected): void
    {
        $this->assertSame($expected, PresaleProposal_Annex::stripNumber($title));
    }

    public function testContractTypeJoinsEachNameOnceInOrder(): void
    {
        $this->assertSame(
            'Expertise & Souscription',
            PresaleProposal_Annex::deduceContractType(['expertise', 'Souscription', 'Expertise', ' ']),
        );
        $this->assertSame('Éditeur', PresaleProposal_Annex::deduceContractType(['éditeur']));
        $this->assertSame('', PresaleProposal_Annex::deduceContractType([]));
    }

    public function testLabelPrefixesTheClient(): void
    {
        $this->assertSame('APRR - Expertise & Souscription', PresaleProposal_Annex::deduceLabel('APRR', 'Expertise & Souscription'));
        $this->assertSame('APRR', PresaleProposal_Annex::deduceLabel('APRR', ''));
    }

    public static function prefixProvider(): iterable
    {
        yield 'plain'            => ['Société Générale', 'SOC'];
        yield 'accents'          => ['Électricité de France', 'ELE'];
        yield 'punctuation'      => ["L'Oréal", 'LOR'];
        yield 'digits kept'      => ['3M France', '3MF'];
        yield 'short, padded'    => ['Ab', 'ABX'];
        yield 'one letter'       => ['Z', 'ZXX'];
        yield 'no letter at all' => ['---', 'XXX'];
    }

    #[DataProvider('prefixProvider')]
    public function testReferencePrefix(string $client_name, string $expected): void
    {
        $this->assertSame($expected, PresaleCounter::getPrefix($client_name));
    }
}
