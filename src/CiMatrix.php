<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Normalises the PHP version matrix in a single CI workflow to match `maho`
 * (e.g. `['8.3', '8.4', '8.5']`), so the version-sensitive checks run against the
 * same set of PHP versions everywhere.
 *
 * Scope is deliberate: `maho` only matrixes the checks whose result depends on
 * the PHP version (**phpstan** and **php syntax**) while lint (cs-fixer/rector)
 * and pest stay single-version, so this is wired up per workflow file, not blanket.
 *
 * The edit is surgical and format-preserving: it rewrites only the bracketed
 * version list on a `php-versions:` / `php-version:` / `php:` matrix line,
 * leaving the key name, indentation and the rest of the file untouched. It only
 * touches **flow-style** lists (`[...]`, the style `maho` uses); a block-style
 * matrix is left as-is rather than risk mangling multi-line YAML.
 *
 * Used as a computed file source (see {@see Sync\FileSync}); the file `$path` is
 * baked into the closure because FileSync doesn't pass the path through. Returns
 * `null` when the repo doesn't have that workflow, or its matrix already matches,
 * so the file is never *created*, only an existing matrix is aligned.
 */
final readonly class CiMatrix
{
    /**
     * Build a computed file source that rewrites the PHP matrix in the workflow
     * at `$path` to `$versions`.
     *
     * @param list<string> $versions e.g. `['8.3', '8.4', '8.5']`
     */
    public static function normalize(string $path, array $versions): \Closure
    {
        return static fn(GitHub $gh, string $owner, string $repo): ?string
            => self::apply($gh, $owner, $repo, $path, $versions);
    }

    /**
     * @param list<string> $versions
     */
    private static function apply(
        GitHub $gh,
        string $owner,
        string $repo,
        string $path,
        array $versions,
    ): ?string {
        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));
        [$status, $body] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/{$encodedPath}");
        if ($status !== 200) {
            return null;
        }

        $current = base64_decode((string) ($body['content'] ?? ''), true);
        if ($current === false || $current === '') {
            return null;
        }

        $flow = '[' . implode(', ', array_map(static fn(string $v): string => "'{$v}'", $versions)) . ']';

        // Match an indented `php-versions: [ ... ]` matrix line and swap only the
        // bracketed list. The longer `php-versions?` alternative is tried before
        // bare `php` so the full key name is preserved. References like
        // `${{ matrix.php-versions }}` aren't followed by `[`, so they're skipped.
        $updated = preg_replace_callback(
            '/^([ \t]*)(php-versions?|php)([ \t]*:[ \t]*)\[[^\]\r\n]*\]/m',
            static fn(array $m): string => $m[1] . $m[2] . $m[3] . $flow,
            $current,
        );

        if ($updated === null || $updated === $current) {
            return null;
        }

        return $updated;
    }
}
