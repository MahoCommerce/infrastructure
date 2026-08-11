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
use Maho\Infra\ComposerPolicy;
use Maho\Infra\Dependabot;

// PHP versions the version-sensitive CI checks run against, mirroring maho.
$phpCiVersions = ['8.3', '8.4', '8.5'];

return [
    'owner' => 'MahoCommerce',

    // Repos the sync should never touch.
    'exclude' => [
        'infrastructure',
        'sboms',
    ],

    // Applied to every non-archived org repo.
    'defaults' => [
        'files' => [
            '.github/FUNDING.yml' => '.github/FUNDING.yml',
            // Computed per repo: composer updates only when composer.lock is
            // committed, github-actions only when the repo has workflows.
            '.github/dependabot.yml' => Dependabot::build(...),
            // Computed per repo: align the PHP version policy with maho. Pin an
            // existing require.php floor to the constraint below, and lock
            // config.platform.php when unset. Skips repos with no composer.json.
            'composer.json' => ComposerPolicy::ensure('>=8.3', '8.3'),
            // Computed per repo: normalise the PHP matrix in the version-sensitive
            // workflows to match maho. Only existing workflows are touched (never
            // created); lint/pest stay single-version and aren't listed here.
            '.github/workflows/phpstan.yml' => CiMatrix::normalize('.github/workflows/phpstan.yml', $phpCiVersions),
            '.github/workflows/syntax-php.yml' => CiMatrix::normalize('.github/workflows/syntax-php.yml', $phpCiVersions),
            // Flags AI-assisted PRs with a GenAI transparency note when the
            // `✨ ai-assisted` label (below) is applied.
            '.github/workflows/ai-assisted-note.yml' => '.github/workflows/ai-assisted-note.yml',
        ],
        // Issue/PR labels, keyed by name. The ai-assisted-note workflow above is
        // inert without this label, so both are synced together.
        'labels' => [
            '✨ ai-assisted' => [
                'color' => 'A371F7',
                'description' => 'Developed with the help of AI',
            ],
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
                '.github/workflows/ai-assisted-note.yml' => false,
            ],
            // No human PRs land here either, so the AI-assisted label that
            // pairs with the workflow above is pointless too.
            'labels' => [
                '✨ ai-assisted' => false,
            ],
            'settings' => [
                'has_issues' => false,
                'has_wiki' => false,
                'has_projects' => false,
            ],
        ],
        // The module baseline. Every module shares one cs-fixer and one rector
        // config (canonical copies of maho's, path-resilient so app-only repos
        // work), runs them from a single lint.yml that supersedes the old
        // per-tool workflows, and carries the dev tooling those configs and the
        // managed phpstan workflow need. maho keeps its own (larger) configs and
        // is intentionally not in this group. phpstan stays in its phpstan.yml.
        'php-modules' => [
            'repos' => ['module-*'],
            'files' => [
                '.github/workflows/lint.yml' => '.github/workflows/lint.yml',
                '.php-cs-fixer.php' => '.php-cs-fixer.php',
                '.rector.php' => '.rector.php',
                // Override the default PHP-only policy: modules also need the
                // lint/test tooling in require-dev (mahocommerce/maho is what
                // lets phpstan resolve the Mage_* classes a module extends).
                'composer.json' => ComposerPolicy::ensure('>=8.3', '8.3', [
                    'friendsofphp/php-cs-fixer' => '*',
                    'mahocommerce/maho' => '*',
                    'mahocommerce/maho-phpstan-plugin' => '*',
                    'phpstan/phpstan' => '*',
                    'phpstan/phpstan-deprecation-rules' => '*',
                    'phpstan/phpstan-strict-rules' => '*',
                    'rector/rector' => '*',
                ]),
            ],
            'replaces' => [
                '.github/workflows/lint.yml' => [
                    '.github/workflows/php-cs-fixer.yml',
                    '.github/workflows/rector.yml',
                ],
            ],
        ],
        // Standalone PHP packages that are not modules. They ship their own
        // dependencies and their own phpstan setup, so they take the lint half
        // of the module baseline only. Note the absence of `mahocommerce/maho`
        // in require-dev: the shared cs-fixer and rector configs use standard
        // rules only, so they do not need it, and `maho` requires
        // maho-composer-plugin, so adding it back would close a dependency
        // cycle. Rector's PHP set follows each repo's own composer.json (see
        // .rector.php), which the policy below keeps aligned. maho keeps its own
        // larger configs and is deliberately not in this group.
        'php-libraries' => [
            'repos' => [
                'directory-data',
                'maho-composer-plugin',
                'maho-phpstan-plugin',
            ],
            'files' => [
                '.github/workflows/lint.yml' => '.github/workflows/lint.yml',
                '.php-cs-fixer.php' => '.php-cs-fixer.php',
                '.rector.php' => '.rector.php',
                // Override the default PHP-only policy with the two tools the
                // configs above run. Existing entries are left as-is.
                'composer.json' => ComposerPolicy::ensure('>=8.3', '8.3', [
                    'friendsofphp/php-cs-fixer' => '*',
                    'rector/rector' => '*',
                ]),
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
        // Icons is a pure SVG distribution package: no PHP code, no dependencies,
        // so the composer PHP policy has nothing to police there. The PHP CI
        // matrix files don't exist in the repo, so those syncs already no-op.
        'icons' => [
            'files' => [
                'composer.json' => false,
            ],
        ],
        // Starter is meant to be cloned, so it must not carry our sponsor links.
        'maho-starter' => [
            'files' => [
                '.github/FUNDING.yml' => false,
            ],
        ],
        // Legacy compat shim: it deliberately ships old Varien_Crypt code to
        // decrypt M1 mcrypt data under modern (libsodium) maho. The module lint
        // baseline doesn't apply (rector/cs-fixer must not modernise frozen
        // crypto code, and pulling mahocommerce/maho into require-dev breaks its
        // install), so opt out of it and keep only the PHP-only composer policy.
        'module-mcrypt-compat' => [
            'files' => [
                '.github/workflows/lint.yml' => false,
                '.php-cs-fixer.php' => false,
                '.rector.php' => false,
                'composer.json' => ComposerPolicy::ensure('>=8.3', '8.3'),
            ],
        ],
    ],
];
