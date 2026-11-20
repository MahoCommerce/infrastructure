<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

use Composer\Json\JsonManipulator;
use Composer\Package\Locker;
use Composer\Semver\Semver;

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
 *  - **`minimum-stability`** and **`prefer-stable`** are pinned when a
 *    stability is passed. A baseline can pull in a dependency that has only
 *    pre-releases (maho needs the Symfony AI 1.0 betas), and without these keys
 *    Composer quietly falls back to an older release of that dependency.
 *    `prefer-stable` keeps every other package on its stable release.
 *  - **`config.allow-plugins.php-http/discovery`** is pinned to `false`. The
 *    plugin is pulled in transitively and only adds packages on its own, and a
 *    non-interactive install stops on any plugin `allow-plugins` does not list.
 *    A repo with a boolean `allow-plugins` (allow or block all) is left as-is.
 *  - **`require-dev`** entries in `$pinDev` are added when missing and
 *    overwritten when the constraint differs (e.g. Pest's major version, which
 *    every module must share with maho).
 *  - **`config.allow-plugins`** entries in `$allowPlugins` are set to `true`,
 *    so a Composer plugin that a dev package ships is not blocked on install.
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
     * @param array<string, string> $requireDev package => version constraint, added when missing
     * @param array<string, string> $pinDev package => version constraint, added or overwritten
     * @param list<string> $allowPlugins Composer plugin packages to allow
     */
    public function __construct(
        private string $constraint,
        private string $platform,
        private array $requireDev = [],
        private ?string $minimumStability = null,
        private array $pinDev = [],
        private array $allowPlugins = [],
    ) {}

    /**
     * Pin `require.php` to `$constraint` (e.g. `">=8.5"`), pin
     * `config.platform.php` to `$platform` (e.g. `"8.5"`), add any missing
     * `$requireDev` entries, pin every `$pinDev` entry, allow every
     * `$allowPlugins` plugin, and pin `minimum-stability` to
     * `$minimumStability` (with `prefer-stable`) when one is given.
     *
     * @param array<string, string> $requireDev
     * @param array<string, string> $pinDev
     * @param list<string> $allowPlugins
     */
    public static function ensure(
        string $constraint,
        string $platform,
        array $requireDev = [],
        ?string $minimumStability = null,
        array $pinDev = [],
        array $allowPlugins = [],
    ): self {
        return new self($constraint, $platform, $requireDev, $minimumStability, $pinDev, $allowPlugins);
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
     * Adding a `require-dev` entry does add one, and changing a pinned
     * constraint can move one, and only a real `composer update` can put either
     * in the lock. So a repo where either happens is skipped here
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
     * The manifest this run would write, and whether producing it changed the
     * package set (a dependency added, or a pinned constraint changed).
     *
     * `drifted` is null when the live file already complies, so a caller that
     * needs the effective manifest either way falls back to `current`.
     *
     * @return array{current: ?string, drifted: ?string, needsUpdate: bool}
     */
    private function manifest(GitHub $gh, string $owner, string $repo): array
    {
        $none = ['current' => null, 'drifted' => null, 'needsUpdate' => false];

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

        // Block php-http/discovery everywhere. A boolean allow-plugins already
        // decides for every plugin, so only a list (or no key) gets the entry.
        if (!is_bool($decoded['config']['allow-plugins'] ?? null)) {
            $manipulator->addConfigSetting('allow-plugins.php-http/discovery', false);
        }

        // Let the baseline's pre-release dependencies resolve, stable first.
        if ($this->minimumStability !== null) {
            $manipulator->addMainKey('minimum-stability', $this->minimumStability);
            $manipulator->addMainKey('prefer-stable', true);
        }

        // Add any missing baseline dev tooling, leaving existing entries alone.
        $needsUpdate = false;
        foreach ($this->requireDev as $package => $version) {
            if (!self::has($decoded, ['require-dev', $package])) {
                $manipulator->addLink('require-dev', $package, $version);
                $needsUpdate = true;
            }
        }

        // Pinned dev tooling: add it when missing, overwrite a different constraint.
        foreach ($this->pinDev as $package => $version) {
            if (($decoded['require-dev'][$package] ?? null) !== $version) {
                $manipulator->addLink('require-dev', $package, $version);
                $needsUpdate = true;
            }
        }

        // Allow the Composer plugins the dev tooling ships (e.g. pestphp/pest-plugin).
        // A boolean allow-plugins already decides for every plugin, as above.
        if (!is_bool($decoded['config']['allow-plugins'] ?? null)) {
            foreach ($this->allowPlugins as $plugin) {
                $manipulator->addConfigSetting('allow-plugins.' . $plugin, true);
            }
        }

        $result = $manipulator->getContents();

        return [
            'current' => $current,
            // Nothing drifted (manipulator left the content untouched) -> no-op.
            'drifted' => $result === $current ? null : $result,
            'needsUpdate' => $needsUpdate,
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
        if ($effective === null || $manifest['needsUpdate']) {
            return null;
        }

        $decoded = json_decode($current, true);
        if (!is_array($decoded)) {
            return null;
        }

        // A policy change can merge before anyone runs `composer update`. On
        // the next run the manifest no longer drifts, so check the lock itself:
        // a fresh hash over a lock that lacks the policy's packages would hide
        // the mismatch that tells a human to update it.
        $manifestData = json_decode($effective, true);
        if (!is_array($manifestData) || !$this->lockHasPolicyPackages($decoded, $manifestData)) {
            return null;
        }

        $manipulator = new JsonManipulator($current);
        $manipulator->addMainKey('content-hash', Locker::getContentHash($effective));
        // `platform` mirrors the root platform requires, `platform-overrides`
        // mirrors config.platform. Composer resolves against these on install,
        // so a stale pair would keep installing against the old PHP version.
        self::setSubKey($manipulator, $decoded, 'platform', $this->constraint);
        self::setSubKey($manipulator, $decoded, 'platform-overrides', $this->platform);
        // The lock records the stability settings it was resolved with.
        if ($this->minimumStability !== null) {
            $manipulator->addMainKey('minimum-stability', $this->minimumStability);
            $manipulator->addMainKey('prefer-stable', true);
        }

        $result = $manipulator->getContents();

        return $result === $current ? null : $result;
    }

    /**
     * Whether the lock holds every package the policy puts in `require-dev`, at
     * a version that satisfies the manifest's constraint for it.
     *
     * @param array<array-key, mixed> $lock the decoded lock file
     * @param array<array-key, mixed> $manifest the decoded composer.json
     */
    private function lockHasPolicyPackages(array $lock, array $manifest): bool
    {
        $locked = [];
        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $locked[$package['name']] = $package['version'];
            }
        }

        foreach (array_keys([...$this->requireDev, ...$this->pinDev]) as $name) {
            $constraint = ((array) ($manifest['require-dev'] ?? []))[$name] ?? null;
            if (!is_string($constraint)) {
                continue;
            }
            $version = $locked[$name] ?? null;
            if ($version === null) {
                return false;
            }
            try {
                if (!Semver::satisfies($version, $constraint)) {
                    return false;
                }
            } catch (\UnexpectedValueException) {
                return false;
            }
        }

        return true;
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
