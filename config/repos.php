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

use Maho\Infra\Dependabot;

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
    ],

    // Files for a named subset of repos. A group's `files` merge onto the
    // defaults, so a group adds files rather than replacing the funding default.
    //
    // `repos` entries match by exact name, by glob (`maho-language-*`), or by
    // regex when slash-delimited (`/^module-(mollie|revolut)$/`).
    'groups' => [
        // 'language-packs' => [
        //     'repos' => ['maho-language-*'],
        //     'files' => [
        //         '.github/workflows/sync-translations.yml' => 'files/sync-translations.yml',
        //     ],
        // ],
        // 'payment-modules' => [
        //     'repos' => ['module-mollie', 'module-braintree', 'module-revolut'],
        //     'files' => [
        //         '.github/workflows/ci.yml' => 'files/module-ci.yml',
        //     ],
        // ],
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
