# infrastructure

Keeps MahoCommerce org repositories in sync from one source of truth: labels,
branch protection, shared CI workflow stubs and (optionally) README badge blocks.

It reconciles **live state against config on every run**, so there is no state
file to store. Settings are applied directly via the GitHub API; managed files
are delivered as **pull requests**, never pushed to the default branch.

## How it works

- `config/repos.php` is the source of truth. It defines `defaults`, named
  `groups` of repos, and per-repo overrides. Layers merge in that order.
- `config/files/` holds the actual file contents that get synced into repos.
- `src/Sync/*` are the reconcilers. Each is idempotent: it reads current state,
  acts only on drift, and is safe to run repeatedly.
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
GitHub App (`SYNC_APP_ID` + `SYNC_APP_PRIVATE_KEY` secrets) and mints a
short-lived token scoped to the org. Use `workflow_dispatch` with the dry-run
input to preview from the Actions tab.

## Adding a reconciler

Implement a class in `src/Sync/` with a `run(string $repo, array $desired): void`
method, then register it in `sync.php`. Read current state, compare to
`$desired`, and only call the API on a real difference.
