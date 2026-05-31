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
$failures = 0;

foreach ($repos as $repo) {
    echo ($dryRun ? '[dry-run] ' : '') . "Syncing {$repo}\n";
    $desired = $config->forRepo($repo);

    foreach ($reconcilers as $reconciler) {
        try {
            $reconciler->run($repo, $desired);
        } catch (\Throwable $e) {
            $failures++;
            fwrite(STDERR, '    ! ' . $reconciler::class . ": {$e->getMessage()}\n");
        }
    }
}

echo $failures === 0 ? "\nDone.\n" : "\nDone with {$failures} failure(s).\n";
exit($failures === 0 ? 0 : 1);

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
