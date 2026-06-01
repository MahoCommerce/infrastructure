<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles GitHub Actions permissions (whether Actions is enabled, and which
 * actions are allowed). These live on a dedicated endpoint, not the repo object,
 * so they can't be folded into {@see SettingsSync}'s PATCH. Like settings, they
 * can't go through a pull request, so this writes directly and only on drift.
 *
 * Actions being enabled is what lets the synced CI workflows run and the
 * github-actions Dependabot updater do its job.
 */
final readonly class ActionsSync
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
        $actions = (array) ($desired['actions'] ?? []);
        if ($actions === []) {
            return [];
        }

        $current = $this->gh->get("/repos/{$this->owner}/{$repo}/actions/permissions");

        $diff = [];
        foreach ($actions as $key => $value) {
            if (($current[(string) $key] ?? null) !== $value) {
                $diff[(string) $key] = $value;
            }
        }

        if ($diff === []) {
            return [];
        }

        // `enabled` is required on every PUT; carry the current/desired value
        // through even when only another key (e.g. allowed_actions) drifted.
        $payload = [...$diff];
        $payload['enabled'] = $actions['enabled'] ?? ($current['enabled'] ?? true);

        $this->gh->put("/repos/{$this->owner}/{$repo}/actions/permissions", $payload);

        return ['actions   ' . implode(', ', array_keys($diff))];
    }
}
