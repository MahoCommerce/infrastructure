<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles managed files into each repo via a pull request. Never commits to
 * the default branch directly, so branch protection and review still apply.
 * When nothing drifted, no branch or PR is created.
 */
final readonly class FileSync
{
    private const string BRANCH = 'infra-sync';

    public function __construct(
        private GitHub $gh,
        private string $owner,
        private string $configDir,
    ) {}

    /** @param array<array-key, mixed> $desired effective config for the repo */
    public function run(string $repo, array $desired): void
    {
        $files = (array) ($desired['files'] ?? []);
        if ($files === []) {
            return;
        }

        $defaultBranch = (string) ($this->gh->get("/repos/{$this->owner}/{$repo}")['default_branch'] ?? '');

        $changed = [];
        foreach ($files as $path => $source) {
            $path = (string) $path;
            $wanted = $this->readSource((string) $source);
            [$status, $body] = $this->gh->tryGet(
                "/repos/{$this->owner}/{$repo}/contents/" . $this->encodePath($path) . "?ref={$defaultBranch}",
            );

            $currentSha = null;
            if ($status === 200) {
                if (base64_decode((string) ($body['content'] ?? '')) === $wanted) {
                    continue;
                }
                $currentSha = isset($body['sha']) ? (string) $body['sha'] : null;
            }

            $changed[] = ['path' => $path, 'content' => $wanted, 'sha' => $currentSha];
        }

        if ($changed === []) {
            return;
        }

        echo '    > ' . count($changed) . " file(s) drifted, opening PR\n";
        $ref = $this->gh->get("/repos/{$this->owner}/{$repo}/git/ref/heads/{$defaultBranch}");
        $object = (array) ($ref['object'] ?? []);
        $headSha = (string) ($object['sha'] ?? '');
        $this->resetBranch($repo, $headSha);

        foreach ($changed as $file) {
            echo "      ~ {$file['path']}\n";
            $payload = [
                'message' => "chore: sync {$file['path']} from infrastructure",
                'content' => base64_encode($file['content']),
                'branch' => self::BRANCH,
            ];
            if ($file['sha'] !== null) {
                $payload['sha'] = $file['sha'];
            }
            $this->gh->put(
                "/repos/{$this->owner}/{$repo}/contents/" . $this->encodePath($file['path']),
                $payload,
            );
        }

        $this->ensurePullRequest($repo, $defaultBranch);
    }

    private function resetBranch(string $repo, string $headSha): void
    {
        [$status] = $this->gh->tryGet("/repos/{$this->owner}/{$repo}/git/ref/heads/" . self::BRANCH);
        if ($status === 200) {
            $this->gh->patch("/repos/{$this->owner}/{$repo}/git/refs/heads/" . self::BRANCH, [
                'sha' => $headSha,
                'force' => true,
            ]);
            return;
        }
        $this->gh->post("/repos/{$this->owner}/{$repo}/git/refs", [
            'ref' => 'refs/heads/' . self::BRANCH,
            'sha' => $headSha,
        ]);
    }

    private function ensurePullRequest(string $repo, string $base): void
    {
        $open = $this->gh->getAll("/repos/{$this->owner}/{$repo}/pulls", [
            'head' => "{$this->owner}:" . self::BRANCH,
            'state' => 'open',
        ]);
        if ($open !== []) {
            return;
        }
        $this->gh->post("/repos/{$this->owner}/{$repo}/pulls", [
            'title' => 'Sync shared files from infrastructure',
            'head' => self::BRANCH,
            'base' => $base,
            'body' => "Automated sync from [infrastructure](https://github.com/{$this->owner}/infrastructure). Review and merge.",
        ]);
    }

    private function readSource(string $source): string
    {
        $file = $this->configDir . '/' . $source;
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Cannot read managed file source: {$file}");
        }
        return $content;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
