<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra\Sync;

use Maho\Infra\GitHub;

/**
 * Reconciles repo security/analysis features that live on their own endpoints
 * rather than the repo object. Like settings, they can't go through a pull
 * request, so this writes directly and only on drift.
 *
 * - `vulnerability_alerts` is the one knob the API exposes for the dependency
 *   graph: enabling Dependabot alerts also enables the dependency graph (there
 *   is no API to turn on the graph alone — see github/community #180308).
 * - `automated_security_fixes` opens PRs to bump dependencies with a published
 *   advisory; it builds on the alerts above.
 */
final readonly class SecuritySync
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
        $security = (array) ($desired['security'] ?? []);
        if ($security === []) {
            return [];
        }

        $base = "/repos/{$this->owner}/{$repo}";
        $changes = [];

        if (array_key_exists('vulnerability_alerts', $security)) {
            // 204 = on, 404 = off; this endpoint answers with an empty body.
            $enabled = $this->gh->status("{$base}/vulnerability-alerts") === 204;
            $changes[] = $this->reconcile(
                "{$base}/vulnerability-alerts",
                (bool) $security['vulnerability_alerts'],
                $enabled,
                'dependency graph + Dependabot alerts',
            );
        }

        if (array_key_exists('automated_security_fixes', $security)) {
            [$status, $body] = $this->gh->tryGet("{$base}/automated-security-fixes");
            $enabled = $status === 200 && ($body['enabled'] ?? false) === true;
            $changes[] = $this->reconcile(
                "{$base}/automated-security-fixes",
                (bool) $security['automated_security_fixes'],
                $enabled,
                'Dependabot security updates',
            );
        }

        return array_values(array_filter($changes));
    }

    /**
     * Drive a boolean toggle endpoint to the desired state (PUT to enable,
     * DELETE to disable), returning a change line or null when already correct.
     */
    private function reconcile(string $path, bool $want, bool $enabled, string $label): ?string
    {
        if ($want === $enabled) {
            return null;
        }
        if ($want) {
            $this->gh->put($path, []);
            return "security  enabled {$label}";
        }
        $this->gh->delete($path);
        return "security  disabled {$label}";
    }
}
