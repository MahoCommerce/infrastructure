<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

use Composer\Json\JsonManipulator;

/**
 * Aligns a repo's `composer.json` with org policy:
 *
 *  - **`require.php`** is pinned to the canonical constraint (e.g. `">=8.5"`),
 *    and added when the repo declares none. The shared rector.php derives its
 *    target PHP version from this floor, so the floor must state the syntax
 *    Rector writes into the repo.
 *  - **`config.platform.php`** is pinned to the canonical version (e.g. `"8.5"`),
 *    and added when absent, so Composer resolves dependencies against the same
 *    PHP version as maho regardless of the CI/host runtime.
 *  - **`require-dev`** entries are ensured when a baseline is passed (e.g. the
 *    lint/test tooling every module needs). Existing entries are left as-is, so
 *    a repo can hold a tighter constraint; only missing ones are added.
 *
 * The content is computed from the repo's live `composer.json` (it can't be a
 * static file, every repo has its own dependencies). Edits go through Composer's
 * own {@see JsonManipulator}, which rewrites the file in place and preserves its
 * existing formatting, so a sync PR's diff is exactly the lines that changed.
 *
 * Used as a computed file source (see {@see Sync\FileSync}); returns `null` when
 * there's no `composer.json` or nothing drifted.
 */
final readonly class ComposerPolicy
{
    /**
     * Build a computed file source that pins `require.php` to `$constraint`
     * (e.g. `">=8.5"`), pins `config.platform.php` to `$platform` (e.g.
     * `"8.5"`), and adds any missing `$requireDev` entries (package => version
     * constraint). The returned closure matches the signature FileSync expects.
     *
     * @param array<string, string> $requireDev
     */
    public static function ensure(string $constraint, string $platform, array $requireDev = []): \Closure
    {
        return static fn(GitHub $gh, string $owner, string $repo): ?string
            => self::build($gh, $owner, $repo, $constraint, $platform, $requireDev);
    }

    /**
     * @param array<string, string> $requireDev
     */
    private static function build(
        GitHub $gh,
        string $owner,
        string $repo,
        string $constraint,
        string $platform,
        array $requireDev,
    ): ?string {
        [$status, $body] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/composer.json");
        if ($status !== 200) {
            return null;
        }

        $current = base64_decode((string) ($body['content'] ?? ''), true);
        if ($current === false || $current === '') {
            return null;
        }

        $decoded = json_decode($current, true);
        if (!is_array($decoded)) {
            return null;
        }

        $manipulator = new JsonManipulator($current);

        // Pin the floor and the resolver platform, adding either when missing.
        // Every PHP version in the org moves together, so a value that already
        // matches is a no-op and a drifted one is overwritten.
        $manipulator->addLink('require', 'php', $constraint);
        $manipulator->addConfigSetting('platform.php', $platform);

        // Add any missing baseline dev tooling, leaving existing entries alone.
        foreach ($requireDev as $package => $version) {
            if (!self::has($decoded, ['require-dev', $package])) {
                $manipulator->addLink('require-dev', $package, $version);
            }
        }

        $result = $manipulator->getContents();

        // Nothing drifted (manipulator left the content untouched) -> no-op.
        return $result === $current ? null : $result;
    }

    /**
     * Whether a nested key path is present in the decoded composer.json.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $path
     */
    private static function has(array $data, array $path): bool
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return false;
            }
            $cursor = $cursor[$key];
        }
        return true;
    }
}
