<?php

/**
 * Source of truth for org sync.
 *
 * `defaults` apply to every non-archived repo in the org (discovered from the
 * API), so new repos are covered automatically. `groups` add files for a named
 * subset; `repos` overrides a single repo. `files` merge across layers, so a
 * group adds to the defaults rather than replacing them.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Maho\Infra\CiMatrix;
use Maho\Infra\Dependabot;
use Maho\Infra\PhpConstraint;

// PHP versions the version-sensitive CI checks run against, mirroring maho.
$phpCiVersions = ['8.3', '8.4', '8.5'];

return [
    'owner' => 'MahoCommerce',

    // Repos the sync should never touch.
    'exclude' => [
        'infrastructure',
        'sboms',
        'icons',
    ],

    // Applied to every non-archived org repo.
    'defaults' => [
        'files' => [
            '.github/FUNDING.yml' => 'files/funding.yml',
            // Computed per repo: composer updates only when composer.lock is
            // committed, github-actions only when the repo has workflows.
            '.github/dependabot.yml' => Dependabot::build(...),
            // Computed per repo: align the PHP version policy with maho. Pin an
            // existing require.php floor to >=8.3 and lock config.platform.php to
            // 8.3 when unset. Skips repos without a composer.json.
            'composer.json' => PhpConstraint::ensure('>=8.3', '8.3'),
            // Computed per repo: normalise the PHP matrix in the version-sensitive
            // workflows to match maho. Only existing workflows are touched (never
            // created); lint/pest stay single-version and aren't listed here.
            '.github/workflows/phpstan.yml' => CiMatrix::normalize('.github/workflows/phpstan.yml', $phpCiVersions),
            '.github/workflows/syntax-php.yml' => CiMatrix::normalize('.github/workflows/syntax-php.yml', $phpCiVersions),
        ],
        // Repo settings, patched directly (GitHub has no PR flow for these).
        'settings' => [
            'allow_squash_merge' => true,
            'allow_merge_commit' => false,
            'allow_rebase_merge' => false,
            'allow_update_branch' => true,
            'has_wiki' => false,
        ],
        // GitHub Actions permissions (separate endpoint from settings). Keeps
        // CI workflows and the github-actions Dependabot updater able to run.
        'actions' => [
            'enabled' => true,
        ],
        // Security features (separate endpoints again). Enabling vulnerability
        // alerts also enables the dependency graph; the API can't do one
        // without the other (github/community discussion #180308).
        'security' => [
            'vulnerability_alerts' => true,
            'automated_security_fixes' => true,
        ],
    ],

    // Files for a named subset of repos. A group's `files` merge onto the
    // defaults, so a group adds files rather than replacing the funding default.
    //
    // `repos` entries match by exact name, by glob (`maho-language-*`), or by
    // regex when slash-delimited (`/^module-(mollie|revolut)$/`).
    'groups' => [
        // Language packs are generated artifacts: maho-l10n is the single source
        // of record and pushes their entire contents (including .github/FUNDING.yml,
        // fanned out from l10n's own copy). So infra must NOT sync files into them
        // (opt them out of the default files) but still holds them to org
        // settings/security standards and makes them read-only (no issues/wiki/
        // projects). PRs can't be disabled via the API; with no human write access
        // the packs are effectively read-only.
        'language-packs' => [
            'repos' => ['maho-language-*'],
            'files' => [
                '.github/FUNDING.yml' => false,
                '.github/dependabot.yml' => false,
                'composer.json' => false,
                '.github/workflows/phpstan.yml' => false,
                '.github/workflows/syntax-php.yml' => false,
            ],
            'settings' => [
                'has_issues' => false,
                'has_wiki' => false,
                'has_projects' => false,
            ],
        ],
        // Modules consolidate their separate php-cs-fixer.yml and rector.yml
        // CI workflows into a single lint.yml that runs both tools via
        // vendor/bin. `replaces` retires the two old workflows in the same PR
        // wherever they still exist. phpstan stays in its own phpstan.yml.
        'php-modules' => [
            'repos' => ['module-*'],
            'files' => [
                '.github/workflows/lint.yml' => 'files/lint.yml',
            ],
            'replaces' => [
                '.github/workflows/lint.yml' => [
                    '.github/workflows/php-cs-fixer.yml',
                    '.github/workflows/rector.yml',
                ],
            ],
        ],
    ],

    // Overrides for a single repo, keyed by repo name. Merged last, so these
    // win over both defaults and groups. A `false` file source opts the repo
    // out of a default file (it still gets the default settings).
    'repos' => [
        // Starter is meant to be cloned, so it must not carry our sponsor links.
        'maho-starter' => [
            'files' => [
                '.github/FUNDING.yml' => false,
            ],
        ],
    ],
];
