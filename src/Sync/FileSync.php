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
 *
 * Drift is measured against the default branch first (already merged -> nothing
 * to do) and then against the open PR branch (already staged -> leave it alone),
 * so a run only ever pushes content that isn't there yet. A repo whose PR is
 * already correct is left completely untouched.
 */
final readonly class FileSync
{
    private const string BRANCH = 'infra-sync';

    public function __construct(
        private GitHub $gh,
        private string $owner,
        private string $configDir,
    ) {}

    /**
     * @param array<array-key, mixed> $desired effective config for the repo
     * @return list<string> human-readable summary of what changed (empty if nothing)
     */
    public function run(string $repo, array $desired): array
    {
        $files = (array) ($desired['files'] ?? []);
        if ($files === []) {
            return [];
        }

        $defaultBranch = (string) ($this->gh->get("/repos/{$this->owner}/{$repo}")['default_branch'] ?? '');
        $branchExists = $this->branchExists($repo);

        // A sync branch with no open PR is a leftover from a merged/closed PR.
        // Delete it so it can't be reused as a stale base, and measure drift
        // cleanly against the default branch instead.
        $deletedLeftover = false;
        if ($branchExists && !$this->hasOpenPullRequest($repo)) {
            $this->gh->delete("/repos/{$this->owner}/{$repo}/git/refs/heads/" . self::BRANCH);
            $branchExists = false;
            $deletedLeftover = true;
        }

        $toCommit = [];   // files whose content must be written to the branch
        $staged = [];     // files already correct on the branch (PR in flight)
        foreach ($files as $path => $source) {
            $path = (string) $path;
            $wanted = $this->readSource((string) $source);

            // Already on the default branch -> nothing to sync for this file.
            [$defStatus, $defBody] = $this->contents($repo, $path, $defaultBranch);
            if ($defStatus === 200 && base64_decode((string) ($defBody['content'] ?? '')) === $wanted) {
                continue;
            }

            // Must land via PR. Skip the write if the branch already carries it.
            if ($branchExists) {
                [$brStatus, $brBody] = $this->contents($repo, $path, self::BRANCH);
                if ($brStatus === 200 && base64_decode((string) ($brBody['content'] ?? '')) === $wanted) {
                    $staged[] = $path;
                    continue;
                }
                $sha = ($brStatus === 200 && isset($brBody['sha'])) ? (string) $brBody['sha'] : null;
            } else {
                // A fresh branch is cut from the default HEAD, so the blob there
                // is the one this write would replace.
                $sha = ($defStatus === 200 && isset($defBody['sha'])) ? (string) $defBody['sha'] : null;
            }

            $toCommit[] = ['path' => $path, 'content' => $wanted, 'sha' => $sha];
        }

        // Nothing drifted from the default branch at all -> no branch, no PR.
        if ($toCommit === [] && $staged === []) {
            return $deletedLeftover ? ['branch    deleted ' . self::BRANCH . ' (PR merged)'] : [];
        }

        if ($toCommit !== []) {
            if (!$branchExists) {
                $this->gh->post("/repos/{$this->owner}/{$repo}/git/refs", [
                    'ref' => 'refs/heads/' . self::BRANCH,
                    'sha' => $this->defaultHeadSha($repo, $defaultBranch),
                ]);
            }

            foreach ($toCommit as $file) {
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
        }

        $createdPr = $this->ensurePullRequest($repo, $defaultBranch);

        // Branch was already correct and the PR was already open: truly a no-op.
        if ($toCommit === [] && !$createdPr) {
            return [];
        }

        $paths = [...array_map(static fn(array $file): string => $file['path'], $toCommit), ...$staged];
        return ['PR        ' . implode(', ', $paths)];
    }

    private function branchExists(string $repo): bool
    {
        [$status] = $this->gh->tryGet("/repos/{$this->owner}/{$repo}/git/ref/heads/" . self::BRANCH);
        return $status === 200;
    }

    private function defaultHeadSha(string $repo, string $defaultBranch): string
    {
        $ref = $this->gh->get("/repos/{$this->owner}/{$repo}/git/ref/heads/{$defaultBranch}");
        return (string) (((array) ($ref['object'] ?? []))['sha'] ?? '');
    }

    /**
     * @return array{0: int, 1: array<array-key, mixed>}
     */
    private function contents(string $repo, string $path, string $ref): array
    {
        return $this->gh->tryGet(
            "/repos/{$this->owner}/{$repo}/contents/" . $this->encodePath($path) . "?ref={$ref}",
        );
    }

    private function hasOpenPullRequest(string $repo): bool
    {
        return $this->gh->getAll("/repos/{$this->owner}/{$repo}/pulls", [
            'head' => "{$this->owner}:" . self::BRANCH,
            'state' => 'open',
        ]) !== [];
    }

    private function ensurePullRequest(string $repo, string $base): bool
    {
        if ($this->hasOpenPullRequest($repo)) {
            return false;
        }
        $this->gh->post("/repos/{$this->owner}/{$repo}/pulls", [
            'title' => 'Sync shared files from infrastructure',
            'head' => self::BRANCH,
            'base' => $base,
            'body' => "Automated sync from [infrastructure](https://github.com/{$this->owner}/infrastructure). Review and merge.",
        ]);
        return true;
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
