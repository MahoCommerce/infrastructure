<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

use Composer\Json\JsonManipulator;

/**
 * Aligns a repo's `composer.json` PHP version policy with the `maho`
 * source-of-truth repo, so every PHP project in the org agrees on the minimum:
 *
 *  - **`require.php`** is pinned to the canonical constraint (e.g. `">=8.3"`),
 *    but only when the repo already declares one; we never invent a floor for a
 *    project that deliberately floats.
 *  - **`config.platform.php`** is added (e.g. `"8.3"`) when absent, so Composer
 *    resolves dependencies against that PHP version regardless of the CI/host
 *    runtime. An existing value is left untouched.
 *
 * The content is computed from the repo's live `composer.json` (it can't be a
 * static file, every repo has its own dependencies). Edits go through Composer's
 * own {@see JsonManipulator}, which rewrites the file in place and preserves its
 * existing formatting, so a sync PR's diff is exactly the lines that changed.
 *
 * Used as a computed file source (see {@see Sync\FileSync}); returns `null` when
 * there's no `composer.json` or nothing drifted.
 */
final readonly class PhpConstraint
{
    /**
     * Build a computed file source that pins `require.php` to `$constraint`
     * (e.g. `">=8.3"`) and ensures `config.platform.php` is `$platform`
     * (e.g. `"8.3"`). The returned closure matches the signature FileSync
     * expects for a managed-file source.
     */
    public static function ensure(string $constraint, string $platform): \Closure
    {
        return static fn(GitHub $gh, string $owner, string $repo): ?string
            => self::build($gh, $owner, $repo, $constraint, $platform);
    }

    private static function build(
        GitHub $gh,
        string $owner,
        string $repo,
        string $constraint,
        string $platform,
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

        // Pin the floor only where one already exists.
        if (self::has($decoded, ['require', 'php'])) {
            $manipulator->addLink('require', 'php', $constraint);
        }

        // Lock the resolver platform, adding it only when missing so a repo that
        // deliberately set a different value keeps it.
        if (!self::has($decoded, ['config', 'platform', 'php'])) {
            $manipulator->addConfigSetting('platform.php', $platform);
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
