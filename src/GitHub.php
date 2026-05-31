<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin GitHub REST wrapper. Just the verbs the reconcilers need,
 * with auth, pagination and a dry-run guard baked in.
 */
final readonly class GitHub
{
    private const string BASE = 'https://api.github.com';

    private HttpClientInterface $http;

    public function __construct(
        #[\SensitiveParameter]
        string $token,
        public bool $dryRun = false,
    ) {
        $this->http = HttpClient::create([
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'maho-infrastructure-sync',
            ],
        ]);
    }

    /**
     * GET with automatic Link-header pagination.
     *
     * @param array<string, scalar> $query
     * @return list<array<array-key, mixed>>
     */
    public function getAll(string $path, array $query = []): array
    {
        $items = [];
        $url = self::BASE . $path;
        $query['per_page'] = 100;

        while ($url !== null) {
            $res = $this->http->request('GET', $url, ['query' => $query]);
            foreach ($res->toArray() as $item) {
                $items[] = (array) $item;
            }
            $query = [];
            $link = $res->getHeaders(false)['link'][0] ?? null;
            $url = $this->nextLink(is_string($link) ? $link : null);
        }

        return $items;
    }

    /** @return array<array-key, mixed> */
    public function get(string $path): array
    {
        return $this->http->request('GET', self::BASE . $path)->toArray();
    }

    /**
     * Returns [status, body]; never throws on 4xx so callers can branch on 404.
     *
     * @return array{0: int, 1: array<array-key, mixed>}
     */
    public function tryGet(string $path): array
    {
        $res = $this->http->request('GET', self::BASE . $path);
        $status = $res->getStatusCode();
        return [$status, $status < 400 ? $res->toArray() : []];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->write('POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->write('PATCH', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->write('PUT', $path, $body);
    }

    public function delete(string $path): void
    {
        if ($this->dryRun) {
            return;
        }
        $this->http->request('DELETE', self::BASE . $path)->getStatusCode();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function write(string $method, string $path, array $body): array
    {
        if ($this->dryRun) {
            return [];
        }
        return $this->http->request($method, self::BASE . $path, ['json' => $body])->toArray();
    }

    private function nextLink(?string $linkHeader): ?string
    {
        if ($linkHeader === null) {
            return null;
        }
        foreach (explode(',', $linkHeader) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $part, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
