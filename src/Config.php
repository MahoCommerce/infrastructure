<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Resolves the layered config (defaults -> groups -> per-repo) into the
 * effective desired state for a single repo.
 */
final readonly class Config
{
    public string $owner;

    /** @var list<string> repos the sync must never touch */
    public array $exclude;

    /** @var array<array-key, mixed> */
    private array $defaults;

    /** @var array<array-key, mixed> raw group definitions */
    private array $groups;

    /** @var array<array-key, mixed> per-repo overrides */
    private array $repoOverrides;

    /** @param array<array-key, mixed> $config */
    public function __construct(array $config)
    {
        $this->owner = (string) ($config['owner'] ?? '');
        $this->exclude = array_values(array_map(strval(...), (array) ($config['exclude'] ?? [])));
        $this->defaults = (array) ($config['defaults'] ?? []);
        $this->groups = (array) ($config['groups'] ?? []);
        $this->repoOverrides = (array) ($config['repos'] ?? []);
    }

    /**
     * Literal repo names referenced in groups or overrides, so they're synced
     * even if discovery somehow misses them. Glob/regex patterns are skipped:
     * they only match against discovered repos, they can't name new ones.
     *
     * @return list<string>
     */
    public function configuredRepos(): array
    {
        $repos = array_map(strval(...), array_keys($this->repoOverrides));
        foreach ($this->groups as $group) {
            foreach ($this->groupRepos($group) as $name) {
                if (!$this->isPattern($name)) {
                    $repos[] = $name;
                }
            }
        }
        return array_values(array_unique($repos));
    }

    /**
     * Effective desired state for one repo: defaults + each matching group + per-repo.
     *
     * @return array<array-key, mixed>
     */
    public function forRepo(string $repo): array
    {
        $merged = $this->defaults;

        foreach ($this->groups as $group) {
            $group = (array) $group;
            if ($this->repoInGroup($repo, $group)) {
                unset($group['repos']);
                $merged = $this->merge($merged, $group);
            }
        }

        if (isset($this->repoOverrides[$repo])) {
            $merged = $this->merge($merged, (array) $this->repoOverrides[$repo]);
        }

        return $merged;
    }

    /** @return list<string> the names or patterns a group lists */
    private function groupRepos(mixed $group): array
    {
        $repos = (array) (((array) $group)['repos'] ?? []);
        return array_map(strval(...), array_values($repos));
    }

    /** @param array<array-key, mixed> $group */
    private function repoInGroup(string $repo, array $group): bool
    {
        foreach ($this->groupRepos($group) as $pattern) {
            if ($this->repoMatches($repo, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A group entry matches a repo by exact name, by glob (`maho-language-*`),
     * or by regex when the entry is slash-delimited (`/^module-(mollie|revolut)$/`).
     */
    private function repoMatches(string $repo, string $pattern): bool
    {
        if (str_starts_with($pattern, '/')) {
            return preg_match($pattern, $repo) === 1;
        }
        if ($this->isPattern($pattern)) {
            return fnmatch($pattern, $repo);
        }
        return $pattern === $repo;
    }

    private function isPattern(string $value): bool
    {
        return str_starts_with($value, '/')
            || str_contains($value, '*')
            || str_contains($value, '?')
            || str_contains($value, '[');
    }

    /**
     * Merge desired-state layers. Associative maps (`files`, `settings`,
     * `actions`, `security`, `labels`, `replaces`) merge key-by-key so a later
     * layer adds/overrides entries (`files`/`labels` entries can be set to
     * `false` to opt out of an earlier layer); everything else is replaced
     * wholesale.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($key === 'files' || $key === 'labels') {
                // A `false` entry opts the repo out of a file/label set by an earlier layer.
                $entries = [...(array) ($base[$key] ?? []), ...(array) $value];
                $base[$key] = array_filter($entries, static fn(mixed $source): bool => $source !== false && $source !== null);
            } elseif ($key === 'settings' || $key === 'actions' || $key === 'security' || $key === 'replaces') {
                $base[$key] = [...(array) ($base[$key] ?? []), ...(array) $value];
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
