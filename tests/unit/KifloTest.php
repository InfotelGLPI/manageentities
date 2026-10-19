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

use GlpiPlugin\Manageentities\KifloClient;
use GlpiPlugin\Manageentities\KifloLead;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Kiflo Partner API client (pagination, errors) and mapping of a lead to the local table
 */
class KifloTest extends TestCase
{
    public function testLeadsArePaginatedUntilTheTotalCount(): void
    {
        $requests = [];
        $pages    = [
            ['items' => array_map(fn($i) => ['id' => $i], range(1, KifloClient::PAGE_SIZE)), 'totalCount' => KifloClient::PAGE_SIZE + 2],
            ['items' => [['id' => 1001], ['id' => 1002]], 'totalCount' => KifloClient::PAGE_SIZE + 2],
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$pages) {
            $requests[] = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];
            return new MockResponse(json_encode(array_shift($pages)));
        });

        $leads = (new KifloClient('https://api.kiflo.test/v3/', 'secret', $http))->getLeads();

        $this->assertCount(KifloClient::PAGE_SIZE + 2, $leads);
        $this->assertCount(2, $requests);
        $this->assertStringStartsWith('https://api.kiflo.test/v3/leads?', $requests[0]['url']);
        $this->assertStringContainsString('offset=' . KifloClient::PAGE_SIZE, $requests[1]['url']);
        $this->assertSame(['Authorization: Bearer secret'], $requests[0]['headers']['authorization']);
    }

    public function testEmptyPageStopsThePagination(): void
    {
        $calls = 0;
        $http  = new MockHttpClient(function () use (&$calls) {
            $calls++;
            return new MockResponse(json_encode(['items' => [], 'totalCount' => 50]));
        });

        $this->assertSame([], (new KifloClient('', 'secret', $http))->getLeads());
        $this->assertSame(1, $calls);
    }

    public function testCountLeadsReadsTheTotalCount(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['items' => [['id' => 1]], 'totalCount' => 42])));

        $this->assertSame(42, (new KifloClient('', 'secret', $http))->countLeads());
    }

    public function testMissingTokenIsRefusedWithoutRequest(): void
    {
        $http = new MockHttpClient(function () {
            $this->fail('No request expected without a key');
        });

        $this->expectException(RuntimeException::class);
        (new KifloClient('', '  ', $http))->getLeads();
    }

    public static function httpErrorProvider(): iterable
    {
        yield 'invalid key' => [401, '401'];
        yield 'partner program' => [403, '403'];
        yield 'rate limit' => [429, '429'];
        yield 'server' => [503, '503'];
    }

    #[DataProvider('httpErrorProvider')]
    public function testHttpErrorsGiveAMessageWithoutTheToken(int $code, string $expected): void
    {
        $http = new MockHttpClient(new MockResponse('{"errorCode":"x"}', ['http_code' => $code]));

        try {
            (new KifloClient('', 'secret-token', $http))->getLeads();
            $this->fail('An exception was expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testInvalidJsonIsRefused(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>proxy</html>'));

        $this->expectException(RuntimeException::class);
        (new KifloClient('', 'secret', $http))->getLeads();
    }

    public function testLeadIsMappedFromItsProperties(): void
    {
        $row = KifloLead::toRow([
            'id'           => 105,
            'creationDate' => '2026-03-25T12:00:00Z',
            'status'       => 'Pending',
            'source'       => 'Tenant',
            'customerId'   => null,
            'properties'   => [
                'companyName'  => '  ACME  ',
                'firstName'    => 'Camille',
                'lastName'     => 'Martin',
                'email'        => 'camille@example.com',
                'phoneNumber'  => '0102030405',
                'origine'      => 'website',
                'commentaires' => 'Demande de démo',
            ],
            'owner' => ['firstName' => 'Alex', 'lastName' => 'Durand', 'email' => 'alex@publisher.test'],
        ]);

        $this->assertNotNull($row);
        $this->assertSame(105, $row['kiflo_id']);
        $this->assertSame('ACME', $row['name']);
        $this->assertSame('Camille Martin', $row['contact_name']);
        $this->assertSame('camille@example.com', $row['email']);
        $this->assertSame('website', $row['origin']);
        $this->assertSame('Pending', $row['status']);
        $this->assertSame('Alex Durand', $row['owner_name']);
        $this->assertSame(0, $row['kiflo_customers_id']);
        $this->assertNull($row['deadline']);
        $this->assertSame('Demande de démo', json_decode($row['properties'], true)['commentaires']);
    }

    public function testLeadWithoutCompanyFallsBackOnTheContact(): void
    {
        $this->assertSame('Camille Martin', KifloLead::toRow(['id' => 1, 'properties' => ['firstName' => 'Camille', 'lastName' => 'Martin']])['name']);
        $this->assertSame('#2', KifloLead::toRow(['id' => 2])['name']);
        $this->assertNull(KifloLead::toRow(['properties' => ['companyName' => 'No id']]));
    }

    public function testUtcDateIsConvertedToTheLocalTimeZone(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
        try {
            $this->assertSame('2026-07-01 14:00:00', KifloLead::toLocalDate('2026-07-01T12:00:00Z'));
            $this->assertNull(KifloLead::toLocalDate(null));
            $this->assertNull(KifloLead::toLocalDate('not a date'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
