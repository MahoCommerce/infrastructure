# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A stateless reconciler that keeps every repo in the **MahoCommerce** GitHub org in sync with one source of truth. There is no database or state file: each run reads live state from the GitHub API, compares it to the desired config, and acts only on drift. Safe to run repeatedly.

Two kinds of desired state, applied differently:
- **Repo settings** (merge options, wiki, etc.) — patched directly via the API, because GitHub has no PR flow for them.
- **Managed files** (`.github/FUNDING.yml`, CI stubs, …) — delivered as a **pull request** on the `infra-sync` branch, never pushed to the default branch, so branch protection and review still apply.

## Commands

```bash
composer install
export GITHUB_TOKEN=$(gh auth token)   # needs repo admin scope on the org

php sync.php --dry-run                  # report what would change, touch nothing
php sync.php                            # apply
php sync.php --repo=module-mollie       # limit to a single repo

composer lint                           # cs-fixer + rector + phpstan (all dry-run/analyze)
composer lint:cs-fixer
composer lint:rector
composer lint:phpstan
```

There is no test suite. CI (`.github/workflows/lint.yml`) runs `composer lint` across PHP 8.3 / 8.4 / 8.5 on every push and PR. PHPStan runs at level 8 with bleeding-edge + strict + deprecation rules; keep it clean.

## Architecture

`sync.php` is the entry point. It parses flags, builds `Config` from `config/repos.php`, wires up the reconcilers, discovers the repo list, then for each repo runs every reconciler and prints a per-repo change report. Exit code is non-zero if any repo failed.

- **`config/repos.php`** — the source of truth. Three layers that merge in order: `defaults` (every non-archived repo) → matching `groups` (named subsets) → `repos` (per-repo overrides). `files` and `settings` merge key-by-key, so a later layer *adds to* rather than replaces an earlier one. A file source of `false` opts a repo out of a file an earlier layer set (it still gets the settings). `config/files/` holds the actual file contents that get synced into repos. A file source can also be a **closure** `(GitHub $gh, string $owner, string $repo): ?string` that computes the content from live repo state, returning `null` to skip the file for that repo — `.github/dependabot.yml` uses this (see `src/Dependabot.php`) to add a composer updater only when `composer.lock` is committed and a github-actions updater only when the repo has workflows.

- **`src/Config.php`** — resolves the layered config into the effective desired state for one repo via `forRepo()`. Group `repos` entries match by exact name, glob (`maho-language-*`), or regex when slash-delimited (`/^module-(mollie|revolut)$/`). `configuredRepos()` surfaces literal names so they're synced even if API discovery misses them.

- **`src/GitHub.php`** — thin GitHub REST wrapper (Symfony HttpClient). Only the verbs the reconcilers need, with bearer auth, Link-header pagination (`getAll`), a `tryGet` that returns `[status, body]` without throwing on 4xx (so callers branch on 404), and a **dry-run guard**: all write methods (`post`/`patch`/`put`/`delete`) become no-ops when `dryRun` is set.

- **`src/Sync/*.php`** — the reconcilers. Each is a `final readonly` class with `run(string $repo, array $desired): array` returning a `list<string>` of human-readable changes (empty when nothing drifted). `SettingsSync` diffs repo settings and patches only differing keys. `ActionsSync` does the same for GitHub Actions permissions (a separate `/actions/permissions` endpoint, not part of the repo object — so it can't be a `settings` key), keeping Actions enabled so CI and the github-actions Dependabot updater can run. `SecuritySync` reconciles security features on their own endpoints: `vulnerability-alerts` (the only API way to enable the dependency graph — it switches on the graph *and* Dependabot alerts together) and `automated-security-fixes` (Dependabot security-update PRs). `FileSync` compares each managed file's content against the default branch, and only if something drifted does it reset the `infra-sync` branch to HEAD, commit the changed files, and open a PR if one isn't already open.

### Repo discovery

`discoverRepos()` in `sync.php` syncs every **public, non-archived** org repo (so new repos are covered automatically) plus any literal repo named in config, minus the `exclude` list. **Private and archived repos are always skipped**, even if named in config.

### Adding a reconciler

Implement a `final readonly` class in `src/Sync/` with `run(string $repo, array $desired): array`, then register it in the `$reconcilers` array in `sync.php`. Read current state, compare to `$desired`, call the API only on a real difference, and return a one-line summary per change.

## CI / production runs

`.github/workflows/sync.yml` runs weekly (Mondays 06:17 UTC) and on demand. It authenticates as a GitHub App via `actions/create-github-app-token` using the org secrets `MAHO_ORGANIZATION_CONTROLLER_CLIENT_ID` + `MAHO_ORGANIZATION_CONTROLLER_PRIVATE_KEY`, minting a short-lived org-scoped token. **Scheduled runs are always forced to `--dry-run`**; to actually apply, trigger `workflow_dispatch` from the Actions tab and untick "Report changes without applying".

## Conventions

- PHP 8.3+, `declare(strict_types=1)`, `final readonly` classes, PSR-4 under `Maho\Infra\` → `src/`.
- Every PHP file carries the SPDX copyright/license header.
- Reconcilers must stay **idempotent** and respect dry-run (only ever write through the `GitHub` wrapper's guarded methods).
