<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Builds the desired `.github/dependabot.yml` for a single repo.
 *
 * The content is computed from live repo state rather than read from a static
 * file, because which ecosystems apply depends on what the repo actually
 * contains:
 *  - **composer** only when `composer.lock` is committed (libraries that don't
 *    pin a lock are intentionally left to float).
 *  - **github-actions** only when the repo has workflows for Dependabot to scan.
 *
 * Used as a computed file source (see {@see Sync\FileSync}); returns `null` when
 * neither ecosystem applies, so no file is synced.
 */
final readonly class Dependabot
{
    /**
     * Computed file source: the effective `.github/dependabot.yml` content for
     * the repo, or `null` when there's nothing for Dependabot to do.
     */
    public static function build(GitHub $gh, string $owner, string $repo): ?string
    {
        $blocks = [];
        if (self::exists($gh, $owner, $repo, 'composer.lock')) {
            $blocks[] = self::ecosystem('composer');
        }
        if (self::exists($gh, $owner, $repo, '.github/workflows')) {
            $blocks[] = self::ecosystem('github-actions');
        }

        if ($blocks === []) {
            return null;
        }

        return "version: 2\n"
            . "updates:\n"
            . implode("\n", $blocks) . "\n";
    }

    /** One updater block, mirroring the format in MahoCommerce/maho. */
    private static function ecosystem(string $name): string
    {
        return <<<YAML
              - package-ecosystem: "{$name}"
                directory: "/"
                schedule:
                  interval: "weekly"
            YAML;
    }

    /** Whether a path (file or directory) exists on the repo's default branch. */
    private static function exists(GitHub $gh, string $owner, string $repo, string $path): bool
    {
        [$status] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/{$path}");
        return $status === 200;
    }
}
