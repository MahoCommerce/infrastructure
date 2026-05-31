<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles repo-level settings (merge options, wiki, etc.). Settings can't go
 * through a pull request, so this patches the repo directly; it only writes the
 * keys that actually differ, so a matching repo is left untouched.
 */
final readonly class SettingsSync
{
    public function __construct(
        private GitHub $gh,
        private string $owner,
    ) {}

    /**
     * @param array<array-key, mixed> $desired effective config for the repo
     * @return list<string> human-readable summary of what changed (empty if nothing)
     */
    public function run(string $repo, array $desired): array
    {
        $settings = (array) ($desired['settings'] ?? []);
        if ($settings === []) {
            return [];
        }

        $current = $this->gh->get("/repos/{$this->owner}/{$repo}");

        $diff = [];
        foreach ($settings as $key => $value) {
            if (($current[(string) $key] ?? null) !== $value) {
                $diff[(string) $key] = $value;
            }
        }

        if ($diff === []) {
            return [];
        }

        $this->gh->patch("/repos/{$this->owner}/{$repo}", $diff);

        return ['settings  ' . implode(', ', array_keys($diff))];
    }
}
