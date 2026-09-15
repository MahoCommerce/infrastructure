<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Aligns the PHP version a CI workflow runs on with org policy, in the shapes
 * `maho` uses:
 *
 *  - {@see normalize()} rewrites a **matrix** to the canonical set (e.g.
 *    `['8.5', '8.6']`), for a check whose result depends on the PHP version
 *    (**phpstan**, **php syntax**, **install**).
 *  - {@see pin()} rewrites a **single version** to the org floor (e.g. `8.5`),
 *    for a check that runs once (**lint**). A workflow left below the floor
 *    runs on a PHP version the repo no longer supports, which is how syntax the
 *    floor allows reaches CI unchecked.
 *  - {@see align()} applies both to one file, for a workflow holding both
 *    shapes (**tests**: a Pest matrix, plus a single version on a job that needs
 *    a live backend).
 *
 * Scope is deliberate and wired up per workflow file, not blanket: only the
 * workflows every repo shares belong here. A workflow that exists in one repo
 * alone is still listed when the whole org should hold it to the floor; the
 * sources no-op on a repo that doesn't have the file.
 *
 * Every edit is surgical and format-preserving. They rewrite only the value on
 * a `php-versions:` / `php-version:` / `php:` line, leaving the key name,
 * indentation, quoting style, trailing comment and the rest of the file
 * untouched. `normalize()` only touches **flow-style** lists (`[...]`, the style
 * `maho` uses); a block-style matrix is left as-is rather than risk mangling
 * multi-line YAML. `pin()` only touches a literal version, so a
 * `${{ matrix.php-version }}` reference is never rewritten.
 *
 * Used as computed file sources (see {@see Sync\FileSync}); the file `$path` is
 * baked into the closure because FileSync doesn't pass the path through. All
 * return `null` when the repo doesn't have that workflow, or it already matches,
 * so the file is never *created*, only an existing one is aligned.
 */
final readonly class CiMatrix
{
    /**
     * The key half of a PHP version line: indent, key, separator. The longer
     * `php-versions?` alternative is tried before bare `php` so the full key
     * name is preserved.
     */
    private const string KEY = '^([ \t]*)(php-versions?|php)([ \t]*:[ \t]*)';

    /**
     * Build a computed file source that rewrites the PHP matrix in the workflow
     * at `$path` to `$versions`.
     *
     * @param list<string> $versions e.g. `['8.5', '8.6']`
     */
    public static function normalize(string $path, array $versions): \Closure
    {
        return self::source($path, static fn(string $yaml): string => self::matrix($yaml, $versions));
    }

    /**
     * Build a computed file source that pins the single PHP version in the
     * workflow at `$path` to `$version`.
     */
    public static function pin(string $path, string $version): \Closure
    {
        return self::source($path, static fn(string $yaml): string => self::scalar($yaml, $version));
    }

    /**
     * Build a computed file source that applies both {@see normalize()} and
     * {@see pin()} to the workflow at `$path`.
     *
     * Order matters: the matrix pass runs first, so by the time the scalar pass
     * reads the text every matrix line is already a flow list, and the only
     * literal versions left are the single-version ones it is meant to take.
     *
     * @param list<string> $versions
     */
    public static function align(string $path, array $versions, string $version): \Closure
    {
        return self::source(
            $path,
            static fn(string $yaml): string => self::scalar(self::matrix($yaml, $versions), $version),
        );
    }

    /**
     * Rewrite every flow-style matrix line to `$versions`.
     *
     * A `${{ matrix.php-versions }}` reference isn't followed by `[`, so the
     * trailing bracket group keeps this to real matrix lines.
     *
     * @param list<string> $versions
     */
    private static function matrix(string $yaml, array $versions): string
    {
        $flow = '[' . implode(', ', array_map(static fn(string $v): string => "'{$v}'", $versions)) . ']';

        return (string) preg_replace_callback(
            '/' . self::KEY . '\[[^\]\r\n]*\]/m',
            static fn(array $m): string => $m[1] . $m[2] . $m[3] . $flow,
            $yaml,
        );
    }

    /**
     * Rewrite every single-version line to `$version`.
     *
     * Only a bare `8.3` / `'8.3'` / `"8.3.1"` value counts, optionally followed
     * by a comment. Anything else on the right-hand side (a list, an expression)
     * is left alone. Group 4 is the quote style, echoed back as it was.
     */
    private static function scalar(string $yaml, string $version): string
    {
        return (string) preg_replace_callback(
            '/' . self::KEY . '(["\']?)\d+(?:\.\d+){1,2}\4([ \t]*(?:#[^\r\n]*)?)$/m',
            static fn(array $m): string => $m[1] . $m[2] . $m[3] . $m[4] . $version . $m[4] . $m[5],
            $yaml,
        );
    }

    /**
     * A file source that reads `$path` from the repo, runs `$rewrite` over it,
     * and returns `null` when the file is absent or the result is unchanged.
     *
     * @param \Closure(string): string $rewrite
     */
    private static function source(string $path, \Closure $rewrite): \Closure
    {
        return static function (GitHub $gh, string $owner, string $repo) use ($path, $rewrite): ?string {
            $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));
            [$status, $body] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/{$encodedPath}");
            if ($status !== 200) {
                return null;
            }

            $current = base64_decode((string) ($body['content'] ?? ''), true);
            if ($current === false || $current === '') {
                return null;
            }

            $updated = $rewrite($current);

            return $updated === $current ? null : $updated;
        };
    }
}
