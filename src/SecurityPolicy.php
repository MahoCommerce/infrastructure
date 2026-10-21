<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Builds the desired `.github/SECURITY.md` for a single repo.
 *
 * The content is computed rather than read from a static file, because each
 * repo must send reports to its own private advisory form: an advisory filed
 * on another repo cannot reference the affected package's versions. The form
 * only exists while private vulnerability reporting is on, which the
 * `security` config keeps enabled (see {@see Sync\SecuritySync}).
 *
 * The file lives under `.github/` rather than the root because the managed
 * `.gitattributes` export-ignores that directory, so the policy stays out of
 * the Composer tarball.
 */
final readonly class SecurityPolicy
{
    /** Computed file source: the effective `.github/SECURITY.md` content for the repo. */
    public static function build(GitHub $gh, string $owner, string $repo): string
    {
        return <<<MD
            ## Reporting Security Issues

            If you believe you have found a security vulnerability in {$owner}/{$repo}, please report it through coordinated disclosure.

            **Please do not report security vulnerabilities through the repository issues, discussions, or pull requests**.
            Instead, please [open a new GitHub security advisory](https://github.com/{$owner}/{$repo}/security/advisories/new).

            Please include the following information to help us better understand and resolve the issue:

            - Type of vulnerability (e.g., SQL injection, cross-site scripting, authentication bypass)
            - Full paths of affected source file(s)
            - Location of the affected source code (tag/branch/commit or direct URL)
            - Required configuration to reproduce the issue
            - Step-by-step reproduction instructions
            - Proof-of-concept or exploit code (if possible)
            - Impact assessment, including potential attack vectors
            - Any proposed mitigations or fixes (optional)

            MD;
    }
}
