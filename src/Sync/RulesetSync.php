<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles branch rulesets. GitHub Free has no organization rulesets, so each
 * repo gets its own copy. Rulesets are keyed by name: this creates a missing one
 * and replaces one that drifted. A ruleset that the config does not name is left
 * alone. Every managed ruleset is active, targets branches, has no bypass actors,
 * and holds rules without parameters, such as `deletion` and `non_fast_forward`.
 */
final readonly class RulesetSync
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
        $rulesets = (array) ($desired['rulesets'] ?? []);
        if ($rulesets === []) {
            return [];
        }

        $base = "/repos/{$this->owner}/{$repo}/rulesets";
        $existing = [];
        foreach ($this->gh->getAll($base, ['includes_parents' => 'false']) as $summary) {
            $existing[(string) ($summary['name'] ?? '')] = (int) ($summary['id'] ?? 0);
        }

        $changes = [];
        foreach ($rulesets as $name => $spec) {
            $name = (string) $name;
            $body = $this->body($name, (array) $spec);

            if (!isset($existing[$name])) {
                $this->gh->post($base, $body);
                $changes[] = "ruleset   created {$name}";
                continue;
            }

            $path = "{$base}/{$existing[$name]}";
            if ($this->matches($this->gh->get($path), $body)) {
                continue;
            }

            $this->gh->put($path, $body);
            $changes[] = "ruleset   updated {$name}";
        }

        return $changes;
    }

    /**
     * @param array<array-key, mixed> $spec `branches` (ref patterns) and `rules` (rule types)
     * @return array<string, mixed> the request body for the rulesets API
     */
    private function body(string $name, array $spec): array
    {
        return [
            'name' => $name,
            'target' => 'branch',
            'enforcement' => 'active',
            'bypass_actors' => [],
            'conditions' => [
                'ref_name' => [
                    'include' => array_values(array_map(strval(...), (array) ($spec['branches'] ?? []))),
                    'exclude' => [],
                ],
            ],
            'rules' => array_values(array_map(
                static fn(mixed $type): array => ['type' => (string) $type],
                (array) ($spec['rules'] ?? []),
            )),
        ];
    }

    /**
     * @param array<array-key, mixed> $current the ruleset as GitHub returns it
     * @param array<string, mixed> $body the desired request body
     */
    private function matches(array $current, array $body): bool
    {
        $conditions = (array) ($current['conditions'] ?? []);
        $refName = (array) ($conditions['ref_name'] ?? []);
        $desiredRefName = (array) ((array) $body['conditions'])['ref_name'];

        return ($current['target'] ?? null) === $body['target']
            && ($current['enforcement'] ?? null) === $body['enforcement']
            && (array) ($current['bypass_actors'] ?? []) === []
            && $this->sorted((array) ($refName['include'] ?? [])) === $this->sorted((array) $desiredRefName['include'])
            && (array) ($refName['exclude'] ?? []) === []
            && $this->sorted($this->ruleTypes((array) ($current['rules'] ?? []))) === $this->sorted($this->ruleTypes((array) $body['rules']));
    }

    /**
     * @param array<array-key, mixed> $rules
     * @return list<string>
     */
    private function ruleTypes(array $rules): array
    {
        return array_values(array_map(
            static fn(mixed $rule): string => (string) (((array) $rule)['type'] ?? ''),
            $rules,
        ));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_map(strval(...), $values));
        sort($values);
        return $values;
    }
}
