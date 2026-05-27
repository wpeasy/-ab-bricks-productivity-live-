<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive;

defined('ABSPATH') || exit;

/**
 * Runtime registrar for enabled snippets.
 *
 * Reads the enabled-snippet ids from the `abpl_enabled_snippets` option
 * and, on the appropriate WP hooks:
 *   - `include_once`s enabled `.php` snippets on `plugins_loaded` priority 5
 *   - `wp_enqueue_script()`s enabled `.js` snippets on `wp_enqueue_scripts`
 *   - `wp_enqueue_style()`s enabled `.css` snippets on `wp_enqueue_scripts`
 *
 * Before including a PHP snippet, checks every top-level class/function
 * it declares — if any are already defined (because the user has also
 * pasted the snippet into a code manager or child theme), the include is
 * SKIPPED to avoid a fatal redeclaration. Skipped snippets are recorded in
 * a request-scoped list that the settings page surfaces as a per-row
 * warning.
 *
 * If the parent's snippet files don't exist on disk
 * (`SnippetRegistry::parent_files_exist()` is false), every hook short-
 * circuits — no fatals.
 */
final class SnippetLoader
{
    /** Option storing the enabled-snippet ids. autoload=false. */
    public const OPTION_KEY = 'abpl_enabled_snippets';

    /** WP enqueue handles use this prefix so they're traceable to us. */
    public const HANDLE_PREFIX = 'abpl-';

    /**
     * Per-request log of snippets that the loader chose not to include
     * because their declared symbols were already defined elsewhere.
     *
     * Shape: `['snippet-id' => 'reason text', ...]`. Populated during the
     * `plugins_loaded` PHP-snippet sweep, read by the settings page.
     */
    private static array $skipped = [];

    public static function init(): void
    {
        if (!SnippetRegistry::parent_files_exist()) {
            return;
        }

        // PHP snippets: load early so their add_action/add_filter calls
        // land before `init` fires. plugins_loaded@5 runs after this very
        // plugin's bootstrap (plugins_loaded@5 from the main file) but the
        // separate add_action call below is just a re-add — WP handles the
        // ordering.
        add_action('plugins_loaded', [self::class, 'load_php_snippets'], 6);

        // JS / CSS snippets: standard enqueue point.
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 10);
    }

    /**
     * Read the current list of enabled snippet ids. Always returns a list.
     *
     * @return array<int,string>
     */
    public static function enabled_ids(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        if (!is_array($value)) {
            return [];
        }
        // Coerce to string array, drop any nonsense entries.
        $ids = [];
        foreach ($value as $v) {
            if (is_string($v) && $v !== '') {
                $ids[] = $v;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function is_enabled(string $id): bool
    {
        return in_array($id, self::enabled_ids(), true);
    }

    /**
     * Persist a new list of enabled-snippet ids. Sanitizes via sanitize_key
     * since these ids round-trip through the option and are used as
     * filename stems / enqueue-handle suffixes.
     *
     * @param array<int,string> $ids
     */
    public static function set_enabled(array $ids): void
    {
        $clean = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $key = sanitize_key($id);
            if ($key !== '') {
                $clean[] = $key;
            }
        }
        $clean = array_values(array_unique($clean));
        // autoload=false: only read on admin pages + plugins_loaded hooks
        // that already touch options anyway.
        update_option(self::OPTION_KEY, $clean, false);
    }

    /**
     * The skipped-with-reason map for the current request. The settings
     * page reads this for the per-row warnings.
     *
     * @return array<string,string>
     */
    public static function skipped_map(): array
    {
        return self::$skipped;
    }

    /**
     * `plugins_loaded@6` handler — include every enabled `.php` snippet
     * whose declared symbols are not already in scope.
     */
    public static function load_php_snippets(): void
    {
        $enabled = self::enabled_ids();
        foreach (SnippetRegistry::all() as $snippet) {
            if ($snippet['language'] !== 'php') {
                continue;
            }
            if (!in_array($snippet['id'], $enabled, true)) {
                continue;
            }

            $defines = SnippetRegistry::defines_for_php($snippet['path']);

            // Check for class collisions first (more common, e.g. Bricks
            // element classes).
            foreach ($defines['classes'] as $name) {
                if (class_exists($name, false)) {
                    self::$skipped[$snippet['id']] = sprintf(
                        /* translators: %s = PHP class name. */
                        __('Class "%s" is already declared — likely in your code-manager plugin or child theme.', 'ab-bricks-productivity-live'),
                        $name
                    );
                    continue 2;
                }
            }

            // Then function collisions.
            foreach ($defines['functions'] as $name) {
                if (function_exists($name)) {
                    self::$skipped[$snippet['id']] = sprintf(
                        /* translators: %s = PHP function name. */
                        __('Function "%s" is already declared — likely in your code-manager plugin or child theme.', 'ab-bricks-productivity-live'),
                        $name
                    );
                    continue 2;
                }
            }

            // Cleared — include the file. include_once is belt-and-braces;
            // we already know nothing in it is declared, but the include
            // path could in theory be reached twice in a single request via
            // some odd hook re-entry pattern.
            include_once $snippet['path'];
        }
    }

    /**
     * `wp_enqueue_scripts` handler — enqueue every enabled JS / CSS
     * snippet. Checks `wp_script_is` / `wp_style_is` for our exact handle
     * before enqueueing so a duplicate enqueue under the same handle isn't
     * doubled. (Doesn't and can't detect duplicates loaded via a different
     * handle or inline `<script>`.)
     */
    public static function enqueue_assets(): void
    {
        $enabled = self::enabled_ids();
        foreach (SnippetRegistry::all() as $snippet) {
            if (!in_array($snippet['id'], $enabled, true)) {
                continue;
            }
            $handle = self::HANDLE_PREFIX . $snippet['id'];

            if ($snippet['language'] === 'js') {
                if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                    self::$skipped[$snippet['id']] = sprintf(
                        /* translators: %s = WordPress script handle. */
                        __('Script handle "%s" is already enqueued — skipping to avoid duplicate load.', 'ab-bricks-productivity-live'),
                        $handle
                    );
                    continue;
                }
                wp_enqueue_script(
                    $handle,
                    $snippet['url'],
                    [],
                    self::file_version($snippet['path']),
                    true // load in footer
                );
                continue;
            }

            if ($snippet['language'] === 'css') {
                if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
                    self::$skipped[$snippet['id']] = sprintf(
                        /* translators: %s = WordPress style handle. */
                        __('Style handle "%s" is already enqueued — skipping to avoid duplicate load.', 'ab-bricks-productivity-live'),
                        $handle
                    );
                    continue;
                }
                wp_enqueue_style(
                    $handle,
                    $snippet['url'],
                    [],
                    self::file_version($snippet['path'])
                );
            }
        }
    }

    /**
     * File-mtime-based version string for cache-busting. Falls back to the
     * plugin version if the file's mtime can't be read.
     */
    private static function file_version(string $path): string
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return defined('ABPL_VERSION') ? (string) ABPL_VERSION : '0.0.1';
        }
        return (string) $mtime;
    }
}
