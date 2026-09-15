<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

use Composer\Json\JsonManipulator;
use Composer\Package\Locker;

/**
 * Aligns a repo's `composer.json`, and the `composer.lock` beside it, with org policy:
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
 * Both files are computed from the repo's live content (they can't be static
 * files, every repo has its own dependencies). Edits go through Composer's own
 * {@see JsonManipulator}, which rewrites in place and preserves the existing
 * formatting, so a sync PR's diff is exactly the lines that changed.
 *
 * `composer.json` and `composer.lock` are two managed files (FileSync computes
 * one path per entry), so one policy object hands out one closure each via
 * {@see json()} and {@see lock()}. Both closures read the same object, which is
 * the point: `content-hash` covers `require-dev` too, so a lock computed from
 * different arguments than its `composer.json` would be wrong.
 *
 * Used as computed file sources (see {@see Sync\FileSync}); each closure returns
 * `null` when the file is absent or nothing drifted.
 */
final readonly class ComposerPolicy
{
    /**
     * @param array<string, string> $requireDev package => version constraint
     */
    public function __construct(
        private string $constraint,
        private string $platform,
        private array $requireDev = [],
    ) {}

    /**
     * Pin `require.php` to `$constraint` (e.g. `">=8.5"`), pin
     * `config.platform.php` to `$platform` (e.g. `"8.5"`), and add any missing
     * `$requireDev` entries.
     *
     * @param array<string, string> $requireDev
     */
    public static function ensure(string $constraint, string $platform, array $requireDev = []): self
    {
        return new self($constraint, $platform, $requireDev);
    }

    /** File source for `composer.json`. */
    public function json(): \Closure
    {
        return $this->buildJson(...);
    }

    /**
     * File source for `composer.lock`.
     *
     * Composer refuses a lock whose `content-hash` does not match the
     * `composer.json` beside it, so editing one without the other leaves every
     * synced repo failing `composer validate`. The hash is a pure function of
     * the manifest ({@see Locker::getContentHash()}), and the two platform keys
     * are copies of what the policy just pinned, so all three are recomputed
     * here without resolving anything.
     *
     * That shortcut holds only while the **package set** is unchanged, which is
     * true of the PHP pins: they steer resolution, they don't add a dependency.
     * Adding a `require-dev` entry does add one, and only a real `composer
     * update` can put it in the lock. So a repo that gains one is skipped here
     * and left with a mismatched hash on purpose: the warning is what tells a
     * human to run the update, and a rewritten hash would hide it behind a lock
     * that installs without the new package.
     */
    public function lock(): \Closure
    {
        return $this->buildLock(...);
    }

    private function buildJson(GitHub $gh, string $owner, string $repo): ?string
    {
        return $this->manifest($gh, $owner, $repo)['drifted'];
    }

    /**
     * The manifest this run would write, and whether producing it required
     * adding a dependency.
     *
     * `drifted` is null when the live file already complies, so a caller that
     * needs the effective manifest either way falls back to `current`.
     *
     * @return array{current: ?string, drifted: ?string, addedDependency: bool}
     */
    private function manifest(GitHub $gh, string $owner, string $repo): array
    {
        $none = ['current' => null, 'drifted' => null, 'addedDependency' => false];

        $current = self::fetch($gh, $owner, $repo, 'composer.json');
        if ($current === null) {
            return $none;
        }

        $decoded = json_decode($current, true);
        if (!is_array($decoded)) {
            return $none;
        }

        $manipulator = new JsonManipulator($current);

        // Pin the floor and the resolver platform, adding either when missing.
        // Every PHP version in the org moves together, so a value that already
        // matches is a no-op and a drifted one is overwritten.
        $manipulator->addLink('require', 'php', $this->constraint);
        $manipulator->addConfigSetting('platform.php', $this->platform);

        // Add any missing baseline dev tooling, leaving existing entries alone.
        $addedDependency = false;
        foreach ($this->requireDev as $package => $version) {
            if (!self::has($decoded, ['require-dev', $package])) {
                $manipulator->addLink('require-dev', $package, $version);
                $addedDependency = true;
            }
        }

        $result = $manipulator->getContents();

        return [
            'current' => $current,
            // Nothing drifted (manipulator left the content untouched) -> no-op.
            'drifted' => $result === $current ? null : $result,
            'addedDependency' => $addedDependency,
        ];
    }

    private function buildLock(GitHub $gh, string $owner, string $repo): ?string
    {
        $current = self::fetch($gh, $owner, $repo, 'composer.lock');
        if ($current === null) {
            return null;
        }

        // Hash the manifest this same run is about to write, not the one on the
        // default branch, or the lock would be stale the moment the PR merges.
        $manifest = $this->manifest($gh, $owner, $repo);
        $effective = $manifest['drifted'] ?? $manifest['current'];
        if ($effective === null || $manifest['addedDependency']) {
            return null;
        }

        $decoded = json_decode($current, true);
        if (!is_array($decoded)) {
            return null;
        }

        $manipulator = new JsonManipulator($current);
        $manipulator->addMainKey('content-hash', Locker::getContentHash($effective));
        // `platform` mirrors the root platform requires, `platform-overrides`
        // mirrors config.platform. Composer resolves against these on install,
        // so a stale pair would keep installing against the old PHP version.
        self::setSubKey($manipulator, $decoded, 'platform', $this->constraint);
        self::setSubKey($manipulator, $decoded, 'platform-overrides', $this->platform);

        $result = $manipulator->getContents();

        return $result === $current ? null : $result;
    }

    /**
     * Set `$mainNode.php` to `$value`.
     *
     * `addSubNode()` is the surgical edit, but on a missing or empty object it
     * either fails or emits a collapsed `{ "php": "..."}`, so those two cases
     * write the whole object instead. Nothing is lost: the object is empty.
     *
     * @param array<array-key, mixed> $lock the decoded lock file
     */
    private static function setSubKey(JsonManipulator $manipulator, array $lock, string $mainNode, string $value): void
    {
        $existing = $lock[$mainNode] ?? null;
        if (is_array($existing) && $existing !== []) {
            $manipulator->addSubNode($mainNode, 'php', $value);
            return;
        }

        $manipulator->addMainKey($mainNode, ['php' => $value]);
    }

    /** Decoded file content from the repo's default branch, or null when absent. */
    private static function fetch(GitHub $gh, string $owner, string $repo, string $path): ?string
    {
        [$status, $body] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/{$path}");
        if ($status !== 200) {
            return null;
        }

        $content = base64_decode((string) ($body['content'] ?? ''), true);

        return $content === false || $content === '' ? null : $content;
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
