<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles issue/PR labels. Labels have no pull-request flow, so this writes
 * them through the labels API directly: it creates a missing label and patches
 * one whose color or description has drifted, leaving a matching label untouched.
 * Labels are keyed by name; comparison is case-insensitive on color (GitHub
 * normalises the hex to lowercase).
 */
final readonly class LabelSync
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
        $labels = (array) ($desired['labels'] ?? []);
        if ($labels === []) {
            return [];
        }

        $base = "/repos/{$this->owner}/{$repo}/labels";
        $changes = [];

        foreach ($labels as $name => $spec) {
            $name = (string) $name;
            $spec = (array) $spec;
            $color = strtolower((string) ($spec['color'] ?? ''));
            $description = (string) ($spec['description'] ?? '');

            // The label name carries a space and emoji, so it must be encoded
            // into the path; GitHub answers 404 when the label doesn't exist.
            $path = $base . '/' . rawurlencode($name);
            [$status, $current] = $this->gh->tryGet($path);

            if ($status === 404) {
                $this->gh->post($base, [
                    'name' => $name,
                    'color' => $color,
                    'description' => $description,
                ]);
                $changes[] = "label     created {$name}";
                continue;
            }

            if (strtolower((string) ($current['color'] ?? '')) === $color
                && (string) ($current['description'] ?? '') === $description
            ) {
                continue;
            }

            $this->gh->patch($path, [
                'color' => $color,
                'description' => $description,
            ]);
            $changes[] = "label     updated {$name}";
        }

        return $changes;
    }
}
