<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Maho\Infra\Config;
use Maho\Infra\GitHub;
use Maho\Infra\Sync\FileSync;
use Maho\Infra\Sync\SettingsSync;

require __DIR__ . '/vendor/autoload.php';

$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$only = null;
foreach ($argv as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--repo=')) {
        $only = substr($arg, strlen('--repo='));
    }
}

$token = getenv('GITHUB_TOKEN') ?: '';
if ($token === '') {
    fwrite(STDERR, "GITHUB_TOKEN is not set.\n");
    exit(1);
}

/** @var array<string, mixed> $raw */
$raw = require __DIR__ . '/config/repos.php';
$config = new Config($raw);
$gh = new GitHub($token, dryRun: $dryRun);

$reconcilers = [
    new SettingsSync($gh, $config->owner),
    new FileSync($gh, $config->owner, __DIR__ . '/config'),
];

$repos = $only !== null ? [$only] : discoverRepos($gh, $config);

if ($dryRun) {
    echo "DRY RUN — reporting only, nothing will be changed.\n\n";
}

$changedCount = 0;
$upToDate = 0;
$failed = [];

foreach ($repos as $repo) {
    $desired = $config->forRepo($repo);

    $changes = [];
    foreach ($reconcilers as $reconciler) {
        try {
            $changes = [...$changes, ...$reconciler->run($repo, $desired)];
        } catch (\Throwable $e) {
            $failed[$repo] = $e->getMessage();
        }
    }

    if ($changes === []) {
        $upToDate++;
        continue;
    }

    $changedCount++;
    echo "{$repo}\n";
    foreach ($changes as $change) {
        echo "  {$change}\n";
    }
    echo "\n";
}

$verb = $dryRun ? 'would change' : 'changed';
echo sprintf(
    "%d repos checked · %d %s · %d up to date · %d failed\n",
    count($repos),
    $changedCount,
    $verb,
    $upToDate,
    count($failed),
);

foreach ($failed as $repo => $message) {
    fwrite(STDERR, "  ! {$repo}: {$message}\n");
}

exit($failed === [] ? 0 : 1);

/**
 * Every non-archived org repo (so new repos are covered automatically), plus
 * any explicitly configured repo, minus the exclude list.
 *
 * @return list<string>
 */
function discoverRepos(GitHub $gh, Config $config): array
{
    $discovered = [];
    foreach ($gh->getAll("/orgs/{$config->owner}/repos", ['type' => 'all']) as $repo) {
        if (($repo['archived'] ?? false) !== true) {
            $discovered[] = (string) ($repo['name'] ?? '');
        }
    }

    $all = array_unique([...$discovered, ...$config->configuredRepos()]);
    sort($all);

    return array_values(array_diff($all, $config->exclude));
}
