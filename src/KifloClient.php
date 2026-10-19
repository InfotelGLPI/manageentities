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

namespace GlpiPlugin\Manageentities;

use Glpi\Toolbox\HttpClient;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Read-only client of the Kiflo Partner API (https://docs-api.kiflo.com/).
 *
 * The token handed by the publisher is a partner token: it reads the leads, customers and
 * deals the publisher shares with us, and is refused on /account and on the property lists.
 */
class KifloClient
{
    public const DEFAULT_URL = 'https://api.kiflo.com/v3';

    /** Page size of the list endpoints */
    public const PAGE_SIZE = 100;

    /** Safety net against an API whose totalCount would never be reached */
    private const MAX_PAGES = 100;

    private string $url;

    private string $token;

    /** @var HttpClient|HttpClientInterface */
    private $http;

    /**
     * @param HttpClient|HttpClientInterface|null $http injected by the tests only
     */
    public function __construct(string $url, string $token, $http = null)
    {
        $this->url   = rtrim(trim($url) !== '' ? trim($url) : self::DEFAULT_URL, '/');
        $this->token = trim($token);
        $this->http  = $http ?? new HttpClient(Config::class, ['timeout' => 20]);
    }

    /**
     * Client built from the plugin configuration
     */
    public static function fromConfig(): self
    {
        return new self(Config::getKifloUrl(), Config::getKifloToken());
    }

    /**
     * Every lead the token can read, all pages included
     *
     * @return list<array<string, mixed>>
     */
    public function getLeads(): array
    {
        return $this->getAll('leads');
    }

    /**
     * Number of leads, read from the first page: the connection test of the configuration
     * (GET /account is refused to a partner token)
     */
    public function countLeads(): int
    {
        $page = $this->get('leads', ['offset' => 0, 'limit' => 1]);

        return (int) ($page['totalCount'] ?? count($page['items'] ?? []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAll(string $resource): array
    {
        $items  = [];
        $offset = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->get($resource, ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
            $chunk  = array_values(array_filter((array) ($result['items'] ?? []), 'is_array'));
            array_push($items, ...$chunk);

            $offset += count($chunk);
            $total   = (int) ($result['totalCount'] ?? 0);
            if ($chunk === [] || $offset >= $total) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException with a message safe to display: never the token
     */
    private function get(string $resource, array $query = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException(__('The Kiflo API key is not set.', 'manageentities'));
        }

        try {
            $response = $this->http->request('GET', $this->url . '/' . $resource, [
                'query'   => $query,
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->token,
                ],
            ]);
            $data = json_decode($response->getContent(), true);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException(self::getHttpErrorMessage($e->getResponse()->getStatusCode()), 0, $e);
        } catch (Throwable $e) {
            // Transport: DNS, timeout, TLS certificate (e.g. a proxy inspecting the traffic)
            throw new RuntimeException(sprintf(
                __('Kiflo API unreachable: %s', 'manageentities'),
                $e->getMessage(),
            ), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException(__('Unexpected answer of the Kiflo API.', 'manageentities'));
        }

        return $data;
    }

    public static function getHttpErrorMessage(int $code): string
    {
        return match ($code) {
            401     => __('Kiflo refused the API key (401): check it or generate a new one.', 'manageentities'),
            403     => __('The Kiflo partner program does not grant access to this resource (403).', 'manageentities'),
            404     => __('Kiflo resource not found (404): check the API URL.', 'manageentities'),
            429     => __('Too many requests sent to Kiflo (429): try again later.', 'manageentities'),
            default => sprintf(__('Kiflo API error (HTTP %d).', 'manageentities'), $code),
        };
    }
}
