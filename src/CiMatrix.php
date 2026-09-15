<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Maho\Infra;

/**
 * Aligns the PHP version a CI workflow runs on with org policy, in the two
 * shapes `maho` uses:
 *
 *  - {@see normalize()} rewrites a **matrix** to the canonical set (e.g.
 *    `['8.5', '8.6']`), for a check whose result depends on the PHP version
 *    (**phpstan**, **php syntax**, **install**).
 *  - {@see pin()} rewrites a **single version** to the org floor (e.g. `8.5`),
 *    for a check that runs once (**lint**). A workflow left below the floor
 *    runs on a PHP version the repo no longer supports, which is how syntax the
 *    floor allows reaches CI unchecked.
 *
 * Scope is deliberate and wired up per workflow file, not blanket: only the
 * workflows every repo shares belong here. A workflow that exists in one repo
 * alone is that repo's own to pin.
 *
 * Both edits are surgical and format-preserving. They rewrite only the value on
 * a `php-versions:` / `php-version:` / `php:` line, leaving the key name,
 * indentation, quoting style, trailing comment and the rest of the file
 * untouched. `normalize()` only touches **flow-style** lists (`[...]`, the style
 * `maho` uses); a block-style matrix is left as-is rather than risk mangling
 * multi-line YAML. `pin()` only touches a literal version, so a
 * `${{ matrix.php-version }}` reference is never rewritten.
 *
 * Used as computed file sources (see {@see Sync\FileSync}); the file `$path` is
 * baked into the closure because FileSync doesn't pass the path through. Both
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
        $flow = '[' . implode(', ', array_map(static fn(string $v): string => "'{$v}'", $versions)) . ']';

        // A `${{ matrix.php-versions }}` reference isn't followed by `[`, so the
        // trailing `\[...\]` keeps this to real matrix lines.
        return self::source($path, '/' . self::KEY . '\[[^\]\r\n]*\]/m', static fn(array $m): string => $m[1] . $m[2] . $m[3] . $flow);
    }

    /**
     * Build a computed file source that pins the single PHP version in the
     * workflow at `$path` to `$version`.
     */
    public static function pin(string $path, string $version): \Closure
    {
        // Only a bare `8.3` / `'8.3'` / `"8.3.1"` value, optionally followed by a
        // comment. Anything else on the right-hand side (a list, an expression)
        // is left alone. Group 4 is the quote style, echoed back as it was.
        $pattern = '/' . self::KEY . '(["\']?)\d+(?:\.\d+){1,2}\4([ \t]*(?:#[^\r\n]*)?)$/m';

        return self::source($path, $pattern, static fn(array $m): string => $m[1] . $m[2] . $m[3] . $m[4] . $version . $m[4] . $m[5]);
    }

    /**
     * A file source that rewrites every `$pattern` match in `$path` through
     * `$replace`, and returns `null` when the file is absent or unchanged.
     *
     * @param \Closure(array<int, string>): string $replace
     */
    private static function source(string $path, string $pattern, \Closure $replace): \Closure
    {
        return static function (GitHub $gh, string $owner, string $repo) use ($path, $pattern, $replace): ?string {
            $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));
            [$status, $body] = $gh->tryGet("/repos/{$owner}/{$repo}/contents/{$encodedPath}");
            if ($status !== 200) {
                return null;
            }

            $current = base64_decode((string) ($body['content'] ?? ''), true);
            if ($current === false || $current === '') {
                return null;
            }

            $updated = preg_replace_callback($pattern, $replace, $current);

            return ($updated === null || $updated === $current) ? null : $updated;
        };
    }
}
