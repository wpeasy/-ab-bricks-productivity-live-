<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive;

defined('ABSPATH') || exit;

/**
 * Stateless discovery of snippet files in the parent plugin's
 * `assets/snippets/` directory.
 *
 * The parent plugin (`ab-bricks-productivity`, slug = `ABPL_PARENT_SLUG`)
 * does NOT need to be active — only its files need to exist on disk under
 * `WP_PLUGIN_DIR`. If the directory is missing, every accessor degrades
 * gracefully (empty list, false existence flag) so the rest of the plugin
 * can keep running without fatals.
 *
 * Discovery re-runs every request — no caching in v0.0.1. `glob()` on a
 * directory of ~10 files is sub-millisecond.
 */
final class SnippetRegistry
{
    /** Snippet types we know how to load. Anything else is ignored. */
    private const SUPPORTED_EXTENSIONS = ['php', 'js', 'css'];

    /**
     * Absolute filesystem path to the parent's snippets directory.
     * No trailing slash.
     */
    public static function parent_snippets_dir(): string
    {
        return rtrim(WP_PLUGIN_DIR, '/\\') . '/' . ABPL_PARENT_SLUG . '/assets/snippets';
    }

    /**
     * URL pointing at the parent's snippets directory.
     * No trailing slash.
     */
    public static function parent_snippets_url(): string
    {
        return rtrim(plugins_url(ABPL_PARENT_SLUG . '/assets/snippets'), '/');
    }

    /**
     * True when the parent's snippet directory exists on disk. False if the
     * parent has been deleted entirely or installed under a different slug.
     */
    public static function parent_files_exist(): bool
    {
        return is_dir(self::parent_snippets_dir());
    }

    /**
     * Discover every snippet file in the parent's directory.
     *
     * @return array<int, array{
     *     id: string,
     *     language: string,
     *     filename: string,
     *     path: string,
     *     url: string,
     * }>
     */
    public static function all(): array
    {
        if (!self::parent_files_exist()) {
            return [];
        }

        $dir = self::parent_snippets_dir();
        $url = self::parent_snippets_url();
        $entries = [];

        foreach (self::SUPPORTED_EXTENSIONS as $ext) {
            $matches = glob($dir . '/*.' . $ext);
            if (!is_array($matches)) {
                continue;
            }
            foreach ($matches as $path) {
                $filename = basename($path);
                $id = self::id_from_filename($filename);
                if ($id === '') {
                    continue;
                }
                $entries[] = [
                    'id'       => $id,
                    'language' => $ext,
                    'filename' => $filename,
                    'path'     => $path,
                    'url'      => $url . '/' . $filename,
                ];
            }
        }

        // Sort by id for stable ordering across requests.
        usort($entries, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $entries;
    }

    /**
     * Find a single snippet by id. Returns null if the id isn't known.
     *
     * @return array{id:string,language:string,filename:string,path:string,url:string}|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Discover every top-level class and function declared in a PHP snippet
     * file. Used by the loader to skip a snippet whose symbols already
     * exist (e.g. the user has pasted the same snippet into FluentSnippets
     * or their child theme).
     *
     * Implementation: walks the file's PHP tokens via `token_get_all()` and
     * collects every `T_CLASS` / `T_FUNCTION` declaration at brace-depth 0.
     * Accurate vs regex — won't false-positive on a `class X` mention
     * inside a string or a comment, and won't false-positive on method
     * declarations inside class bodies.
     *
     * Returns `['classes' => string[], 'functions' => string[]]`. Both
     * arrays unique-deduped. Empty arrays on read failure.
     *
     * @return array{classes: array<int,string>, functions: array<int,string>}
     */
    public static function defines_for_php(string $path): array
    {
        $empty = ['classes' => [], 'functions' => []];
        if (!is_file($path) || !is_readable($path)) {
            return $empty;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return $empty;
        }

        $tokens = token_get_all($contents);
        $classes = [];
        $functions = [];
        $brace_depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (is_string($tok)) {
                if ($tok === '{') {
                    $brace_depth++;
                } elseif ($tok === '}') {
                    $brace_depth--;
                }
                continue;
            }

            [$id, $text] = $tok;

            // Only count top-level declarations (brace_depth === 0). Anything
            // nested inside a class body or another function isn't a global
            // symbol we'd risk redeclaring.
            if ($brace_depth !== 0) {
                continue;
            }

            if ($id === T_CLASS) {
                $name = self::next_t_string($tokens, $i, $count);
                if ($name !== null) {
                    $classes[] = $name;
                }
            } elseif ($id === T_FUNCTION) {
                // Anonymous function: T_FUNCTION is followed by whitespace
                // then `(`. Named function: T_FUNCTION → T_STRING → `(`.
                // next_t_string stops at the first T_STRING OR `(` — if it
                // returns null, it was anonymous and we skip.
                $name = self::next_t_string_before_paren($tokens, $i, $count);
                if ($name !== null) {
                    $functions[] = $name;
                }
            }
        }

        return [
            'classes'   => array_values(array_unique($classes)),
            'functions' => array_values(array_unique($functions)),
        ];
    }

    /**
     * Filename → snippet id (strip extension). Returns '' for filenames
     * that don't sanitize cleanly. Snippet ids round-trip through the
     * `abpl_enabled_snippets` option so they must be safe shell-equivalents.
     */
    private static function id_from_filename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $id = sanitize_key($base);
        return $id;
    }

    /**
     * Find the next T_STRING token after position $i in the token list.
     * Returns the token's text or null if none found before the end.
     */
    private static function next_t_string(array $tokens, int $i, int $count): ?string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
        }
        return null;
    }

    /**
     * Find the next T_STRING before the next `(`. Used for detecting named
     * functions: T_FUNCTION → T_STRING → `(` is named; T_FUNCTION → `(` is
     * anonymous (or arrow-function variant). Returns null on anonymous.
     */
    private static function next_t_string_before_paren(array $tokens, int $i, int $count): ?string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === '(') {
                    return null;
                }
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
        }
        return null;
    }
}
