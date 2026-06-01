# infrastructure

A stateless reconciler that keeps every repo in the **MahoCommerce** GitHub org
in sync with one source of truth. There is no database or state file: each run
reads live state from the GitHub API, compares it to the desired config, and
acts only on drift — so it's safe to run repeatedly.

Two delivery modes:

- **Direct API** for things GitHub has no PR flow for — repo settings, Actions
  permissions, security features.
- **Pull requests** for managed files, opened on the `infra-sync` branch and
  never pushed to the default branch, so branch protection and review still apply.

## What gets synced

Applied to every repo in [scope](#scope):

**Repo settings** — patched directly on the repo:

- Squash merge only (merge commits and rebase merging off)
- "Always suggest updating branches" on
- Wikis off

**GitHub Actions** — `/actions/permissions`:

- Actions enabled, so CI and the `github-actions` Dependabot updater can run

**Security features** — each on its own endpoint:

- Dependency graph **+** Dependabot alerts (the API enables them together;
  there's no way to turn on the graph alone)
- Dependabot security updates (auto-PRs for dependencies with a published advisory)

**Managed files** — delivered as a pull request on `infra-sync`:

- `.github/FUNDING.yml` — sponsor links (skipped on `maho-starter`, which is
  meant to be cloned)
- `.github/dependabot.yml` — computed per repo: a `composer` updater only when a
  `composer.lock` is committed, a `github-actions` updater only when the repo has
  workflows; weekly schedule

### Scope

Every **public, non-archived** org repo is synced (new repos are picked up
automatically), plus any repo named in config, minus the `exclude` list.
**Private and archived repos are always skipped, even if named in config.**

## How it works

- `config/repos.php` is the source of truth. It defines `defaults`, named
  `groups` of repos, and per-repo overrides. Layers merge in that order, so a
  group adds to the defaults rather than replacing them.
- `config/files/` holds the actual file contents that get synced into repos.
- `src/Sync/*` are the reconcilers (`SettingsSync`, `ActionsSync`,
  `SecuritySync`, `FileSync`). Each is idempotent: it reads current state, acts
  only on drift, and is safe to run repeatedly.
- `sync.php` resolves the effective config per repo and runs every reconciler.

## Run locally

```bash
composer install
export GITHUB_TOKEN=$(gh auth token)   # needs repo admin scope on the org

php sync.php --dry-run                 # show what would change, touch nothing
php sync.php                           # apply
php sync.php --repo=module-mollie      # limit to one repo
```

## CI

`.github/workflows/sync.yml` runs weekly and on demand. It authenticates as a
GitHub App (`MAHO_ORGANIZATION_CONTROLLER_CLIENT_ID` +
`MAHO_ORGANIZATION_CONTROLLER_PRIVATE_KEY` org secrets) and mints a
short-lived token scoped to the org. Scheduled runs are always forced to
`--dry-run`; to apply, trigger `workflow_dispatch` from the Actions tab and
untick the dry-run input.

## Adding a reconciler

Implement a `final readonly` class in `src/Sync/` with a
`run(string $repo, array $desired): array` method returning a `list<string>` of
human-readable changes (empty when nothing drifted), then register it in
`sync.php`. Read current state, compare to `$desired`, and only call the API on
a real difference.
