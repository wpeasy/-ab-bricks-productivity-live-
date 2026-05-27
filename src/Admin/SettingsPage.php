<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive\Admin;

use AB\BricksProductivityLive\SnippetLoader;
use AB\BricksProductivityLive\SnippetRegistry;

defined('ABSPATH') || exit;

/**
 * Renders the BRXProd Live admin settings page — a plain server-rendered
 * form. v0.0.1 stays deliberately simple: no Svelte, no REST, no JS. Each
 * snippet gets a checkbox; the form posts to `admin-post.php` which calls
 * `handle_save()` on this class.
 *
 * Surfaces three layers of doubling-up warnings:
 *   1. A page-level banner explaining the doubling problem in general.
 *   2. A per-row warning for snippets the loader skipped because their
 *      classes/functions were already declared.
 *   3. A standing reminder under the table that closure-only PHP snippets
 *      and inline JS can't be auto-detected.
 */
final class SettingsPage
{
    /** Slug used as the admin-post.php `action` for our save handler. */
    public const SAVE_ACTION = 'abpl_save_snippets';

    /** Nonce action / name pair for the form. */
    private const NONCE_ACTION = 'abpl_save_snippets_nonce';
    private const NONCE_NAME   = 'abpl_nonce';

    /**
     * Register the form-submit handler. Wired from Plugin::init() in
     * admin context.
     */
    public static function register_save_handler(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handle_save']);
    }

    /**
     * Page renderer. Called by the `add_menu_page` callback.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'ab-bricks-productivity-live'));
        }

        $parent_exists = SnippetRegistry::parent_files_exist();
        $snippets = $parent_exists ? SnippetRegistry::all() : [];
        $enabled = SnippetLoader::enabled_ids();
        $skipped = SnippetLoader::skipped_map();
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('BRXProd Live — Snippets', 'ab-bricks-productivity-live'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Settings saved.', 'ab-bricks-productivity-live'); ?></p>
                </div>
            <?php endif; ?>

            <?php self::render_intro(); ?>

            <?php if (!$parent_exists) : ?>
                <?php self::render_parent_missing_notice(); ?>
            <?php else : ?>
                <?php self::render_doubling_warning(); ?>
                <?php self::render_form($snippets, $enabled, $skipped); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Top-of-page introduction paragraph.
     */
    private static function render_intro(): void
    {
        ?>
        <p>
            <?php echo esc_html__(
                'Toggle Bricks Productivity code snippets on or off. Enabled snippets are loaded on every frontend request — PHP snippets are included on plugins_loaded, JavaScript and CSS snippets are enqueued on wp_enqueue_scripts.',
                'ab-bricks-productivity-live'
            ); ?>
        </p>
        <p>
            <?php
            printf(
                /* translators: %s = parent plugin slug */
                esc_html__('Snippets are read directly from the %s plugin\'s assets/snippets/ folder. The parent plugin does not need to be active — only its files need to exist on disk.', 'ab-bricks-productivity-live'),
                '<code>' . esc_html(ABPL_PARENT_SLUG) . '</code>'
            );
            ?>
        </p>
        <?php
    }

    /**
     * Red error notice shown when the parent plugin's files aren't on disk.
     */
    private static function render_parent_missing_notice(): void
    {
        $expected = SnippetRegistry::parent_snippets_dir();
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php echo esc_html__('Parent plugin files not found.', 'ab-bricks-productivity-live'); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s = expected filesystem path */
                    esc_html__('BRXProd Live expected to find snippet files at %s but the directory does not exist. Install the parent "Bricks Productivity" plugin (it does not need to be activated) and refresh this page.', 'ab-bricks-productivity-live'),
                    '<code>' . esc_html($expected) . '</code>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Prominent yellow banner explaining the doubling-up problem and the
     * detection limits.
     */
    private static function render_doubling_warning(): void
    {
        ?>
        <div class="notice notice-warning" style="margin-top: 1.5em;">
            <p>
                <strong><?php echo esc_html__('Avoid loading snippets twice.', 'ab-bricks-productivity-live'); ?></strong>
                <?php echo esc_html__(
                    'If you have already pasted any of these snippets into FluentSnippets, WPCodeBox, or your child theme\'s functions.php, do NOT also enable them here. Doubling causes filters to fire twice and JavaScript to run twice.',
                    'ab-bricks-productivity-live'
                ); ?>
            </p>
            <ul style="margin: 0.5em 0 0.5em 1.5em; list-style: disc;">
                <li>
                    <?php echo esc_html__(
                        'PHP snippets that declare a class or named function are auto-detected: BRXProd Live will skip the include if the symbol is already loaded and show a warning next to the row.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
                <li>
                    <?php echo esc_html__(
                        'PHP snippets that only register filter / action closures (no top-level class or function) cannot be detected automatically — you must verify yourself.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
                <li>
                    <?php echo esc_html__(
                        'JavaScript and CSS snippets are detected only if they were already enqueued under the same handle. Inline <script> blocks or duplicate enqueues under a different handle are not detected.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * Main form with one row per discovered snippet.
     *
     * @param array<int,array<string,string>> $snippets
     * @param array<int,string> $enabled
     * @param array<string,string> $skipped
     */
    private static function render_form(array $snippets, array $enabled, array $skipped): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

            <?php if (empty($snippets)) : ?>
                <p><em><?php echo esc_html__('No snippets found in the parent plugin\'s assets/snippets folder.', 'ab-bricks-productivity-live'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped" style="margin-top: 1em;">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 4em;"><?php echo esc_html__('Enable', 'ab-bricks-productivity-live'); ?></th>
                            <th scope="col"><?php echo esc_html__('Snippet', 'ab-bricks-productivity-live'); ?></th>
                            <th scope="col" style="width: 6em;"><?php echo esc_html__('Type', 'ab-bricks-productivity-live'); ?></th>
                            <th scope="col"><?php echo esc_html__('Status', 'ab-bricks-productivity-live'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snippets as $snippet) : ?>
                            <?php
                            $id           = $snippet['id'];
                            $is_enabled   = in_array($id, $enabled, true);
                            $skip_reason  = $skipped[$id] ?? '';
                            $pretty_label = self::prettify_id($id);
                            ?>
                            <tr>
                                <td>
                                    <label class="screen-reader-text" for="abpl-toggle-<?php echo esc_attr($id); ?>">
                                        <?php
                                        printf(
                                            /* translators: %s = snippet display name */
                                            esc_html__('Enable %s snippet', 'ab-bricks-productivity-live'),
                                            esc_html($pretty_label)
                                        );
                                        ?>
                                    </label>
                                    <input
                                        type="checkbox"
                                        name="abpl_enabled_snippets[]"
                                        id="abpl-toggle-<?php echo esc_attr($id); ?>"
                                        value="<?php echo esc_attr($id); ?>"
                                        <?php checked($is_enabled); ?>
                                    >
                                </td>
                                <td>
                                    <strong><?php echo esc_html($pretty_label); ?></strong><br>
                                    <code style="font-size: 11px; color: #666;"><?php echo esc_html($snippet['filename']); ?></code>
                                </td>
                                <td>
                                    <span class="abpl-lang-badge abpl-lang-<?php echo esc_attr($snippet['language']); ?>">
                                        <?php echo esc_html(strtoupper($snippet['language'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($is_enabled && $skip_reason !== '') : ?>
                                        <span style="color: #b32d2e;">
                                            ⚠ <strong><?php echo esc_html__('Skipped:', 'ab-bricks-productivity-live'); ?></strong>
                                            <?php echo esc_html($skip_reason); ?>
                                        </span>
                                    <?php elseif ($is_enabled) : ?>
                                        <span style="color: #008a20;">✓ <?php echo esc_html__('Loaded', 'ab-bricks-productivity-live'); ?></span>
                                    <?php else : ?>
                                        <span style="color: #888;"><?php echo esc_html__('Disabled', 'ab-bricks-productivity-live'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Save changes', 'ab-bricks-productivity-live'); ?>
                </button>
            </p>
        </form>

        <style>
            .abpl-lang-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                background: #2271b1;
                color: #fff;
            }
            .abpl-lang-php { background: #777bb3; }
            .abpl-lang-js  { background: #f0db4f; color: #323330; }
            .abpl-lang-css { background: #264de4; }
        </style>
        <?php
    }

    /**
     * `admin_post_abpl_save_snippets` handler. Capability + nonce checked,
     * then writes the enabled-snippet ids to the option and redirects back
     * to the settings page with `?saved=1`.
     */
    public static function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'ab-bricks-productivity-live'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $submitted = isset($_POST['abpl_enabled_snippets']) && is_array($_POST['abpl_enabled_snippets'])
            ? wp_unslash($_POST['abpl_enabled_snippets'])
            : [];

        $clean = [];
        foreach ($submitted as $value) {
            if (!is_string($value)) {
                continue;
            }
            $key = sanitize_key($value);
            if ($key !== '') {
                $clean[] = $key;
            }
        }

        SnippetLoader::set_enabled($clean);

        wp_safe_redirect(add_query_arg([
            'page'  => Menu::MENU_SLUG,
            'saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Convert a snippet id (kebab-case filename without extension) to a
     * human-friendly title. v0.0.1 stub: replaces dashes with spaces and
     * title-cases. v0.0.2 will parse the snippet's docblock for a real
     * @title tag.
     */
    private static function prettify_id(string $id): string
    {
        $words = str_replace('-', ' ', $id);
        return ucwords($words);
    }
}
