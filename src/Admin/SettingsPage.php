<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive\Admin;

use AB\BricksProductivityLive\SnippetLoader;
use AB\BricksProductivityLive\SnippetRegistry;

defined('ABSPATH') || exit;

/**
 * Renders the BRXProd Live admin settings page.
 *
 * Each snippet row gets a toggle switch that auto-saves via the REST
 * endpoint registered by `RestController`. No form, no Save Changes
 * button — every change round-trips immediately. For PHP snippets, the
 * server runs a frontend probe before confirming the enable; if the
 * probe trips a fatal, the toggle flips back and the row shows the
 * error.
 */
final class SettingsPage
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'ab-bricks-productivity-live'));
        }

        $parent_exists = SnippetRegistry::parent_files_exist();
        $snippets      = $parent_exists ? SnippetRegistry::all() : [];
        $enabled       = SnippetLoader::enabled_ids();
        $skipped       = SnippetLoader::skipped_map();

        ?>
        <div class="wrap abpl-wrap">
            <h1><?php echo esc_html__('BRXProd Live — Snippets', 'ab-bricks-productivity-live'); ?></h1>

            <?php self::render_intro(); ?>
            <?php self::render_update_check(); ?>

            <?php if (!$parent_exists) : ?>
                <?php self::render_parent_missing_notice(); ?>
            <?php else : ?>
                <?php self::render_doubling_warning(); ?>
                <?php self::render_table($snippets, $enabled, $skipped); ?>
            <?php endif; ?>

            <?php self::render_inline_script($parent_exists); ?>
        </div>
        <?php self::render_inline_styles(); ?>
        <?php
    }

    private static function render_intro(): void
    {
        ?>
        <p>
            <?php echo esc_html__(
                'Toggle Bricks Productivity code snippets on or off. Changes save immediately. Enabled snippets load on every frontend request — PHP on plugins_loaded, JS and CSS on wp_enqueue_scripts.',
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

    private static function render_update_check(): void
    {
        ?>
        <div class="abpl-update-card">
            <div class="abpl-update-card-info">
                <strong><?php echo esc_html__('Plugin updates', 'ab-bricks-productivity-live'); ?></strong>
                <span class="abpl-update-card-version">
                    <?php
                    printf(
                        /* translators: %s = plugin version. */
                        esc_html__('Installed: %s', 'ab-bricks-productivity-live'),
                        '<code>' . esc_html((string) ABPL_VERSION) . '</code>'
                    );
                    ?>
                </span>
            </div>
            <div class="abpl-update-card-action">
                <button type="button" class="button" data-action="abpl-check-updates">
                    <?php echo esc_html__('Check for updates', 'ab-bricks-productivity-live'); ?>
                </button>
                <span class="abpl-update-card-result" data-update-result></span>
            </div>
        </div>
        <?php
    }

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
                        'PHP snippets that declare a class or named function are auto-detected: BRXProd Live will skip the include if the symbol is already loaded.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
                <li>
                    <?php echo esc_html__(
                        'PHP snippets that only register filter / action closures cannot be detected automatically — you must verify yourself.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
                <li>
                    <?php echo esc_html__(
                        'JavaScript and CSS snippets are detected only if they were already enqueued under the same handle. Inline scripts or duplicate enqueues under a different handle are not detected.',
                        'ab-bricks-productivity-live'
                    ); ?>
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,string>> $snippets
     * @param array<int,string>               $enabled
     * @param array<string,string>            $skipped
     */
    private static function render_table(array $snippets, array $enabled, array $skipped): void
    {
        if (empty($snippets)) {
            ?>
            <p><em><?php echo esc_html__('No snippets found in the parent plugin\'s assets/snippets folder.', 'ab-bricks-productivity-live'); ?></em></p>
            <?php
            return;
        }
        ?>
        <table class="widefat striped abpl-snippets-table" style="margin-top: 1em;">
            <thead>
                <tr>
                    <th scope="col" style="width: 5em;"><?php echo esc_html__('Enable', 'ab-bricks-productivity-live'); ?></th>
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
                            <label class="abpl-switch" for="abpl-toggle-<?php echo esc_attr($id); ?>">
                                <input
                                    type="checkbox"
                                    id="abpl-toggle-<?php echo esc_attr($id); ?>"
                                    data-snippet-id="<?php echo esc_attr($id); ?>"
                                    data-snippet-language="<?php echo esc_attr($snippet['language']); ?>"
                                    <?php checked($is_enabled); ?>
                                >
                                <span class="abpl-switch-slider" aria-hidden="true"></span>
                                <span class="screen-reader-text">
                                    <?php
                                    printf(
                                        /* translators: %s = snippet display name */
                                        esc_html__('Enable %s snippet', 'ab-bricks-productivity-live'),
                                        esc_html($pretty_label)
                                    );
                                    ?>
                                </span>
                            </label>
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
                            <span class="abpl-status" data-status-for="<?php echo esc_attr($id); ?>">
                                <?php if ($is_enabled && $skip_reason !== '') : ?>
                                    <span class="abpl-status-skipped">⚠ <?php echo esc_html($skip_reason); ?></span>
                                <?php elseif ($is_enabled) : ?>
                                    <span class="abpl-status-ok">✓ <?php echo esc_html__('Loaded', 'ab-bricks-productivity-live'); ?></span>
                                <?php else : ?>
                                    <span class="abpl-status-off"><?php echo esc_html__('Disabled', 'ab-bricks-productivity-live'); ?></span>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Inline JS for the auto-save toggles AND the "check for updates"
     * button. The toggle handler only binds when there are snippet rows;
     * the update-check handler binds whenever the button is rendered.
     */
    private static function render_inline_script(bool $bind_toggles): void
    {
        $config = [
            'toggleEndpoint' => esc_url_raw(rest_url(RestController::REST_NAMESPACE . RestController::ROUTE_TOGGLE)),
            'checkEndpoint'  => esc_url_raw(rest_url(RestController::REST_NAMESPACE . RestController::ROUTE_CHECK_UPDATES)),
            'nonce'          => wp_create_nonce('wp_rest'),
            'bindToggles'    => $bind_toggles,
            'strings'        => [
                'saving'        => __('Saving…', 'ab-bricks-productivity-live'),
                'enabled'       => __('✓ Loaded', 'ab-bricks-productivity-live'),
                'disabled'      => __('Disabled', 'ab-bricks-productivity-live'),
                'networkError'  => __('Network error — change not saved.', 'ab-bricks-productivity-live'),
                'checkLabel'    => __('Check for updates', 'ab-bricks-productivity-live'),
                'checking'      => __('Checking…', 'ab-bricks-productivity-live'),
                'viewRelease'   => __('View release', 'ab-bricks-productivity-live'),
                'checkFailed'   => __('Could not reach GitHub.', 'ab-bricks-productivity-live'),
            ],
        ];
        ?>
        <script>
        (function () {
            var config = <?php echo wp_json_encode($config); ?>;

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function setStatus(id, html, cssClass) {
                var el = document.querySelector('[data-status-for="' + CSS.escape(id) + '"]');
                if (!el) return;
                el.innerHTML = '<span class="' + cssClass + '">' + html + '</span>';
            }

            function bindToggleHandler() {
                var table = document.querySelector('.abpl-snippets-table');
                if (!table) return;

                table.addEventListener('change', function (event) {
                    var input = event.target;
                    if (!input.matches('input[type="checkbox"][data-snippet-id]')) return;

                    var id      = input.dataset.snippetId;
                    var enabled = input.checked;

                    input.disabled = true;
                    setStatus(id, escapeHtml(config.strings.saving), 'abpl-status-saving');

                    fetch(config.toggleEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': config.nonce
                        },
                        body: JSON.stringify({ id: id, enabled: enabled })
                    })
                    .then(function (r) {
                        return r.json().then(function (body) {
                            return { ok: r.ok, status: r.status, body: body };
                        });
                    })
                    .then(function (res) {
                        if (!res.ok) {
                            input.checked = !enabled;
                            var msg = (res.body && (res.body.message || res.body.code)) || ('HTTP ' + res.status);
                            setStatus(id, '⚠ ' + escapeHtml(msg), 'abpl-status-skipped');
                            return;
                        }

                        if (res.body.reverted) {
                            input.checked = false;
                            setStatus(id, '⚠ ' + escapeHtml(res.body.message || ''), 'abpl-status-skipped');
                            return;
                        }

                        if (res.body.warning) {
                            setStatus(id, '⚠ ' + escapeHtml(res.body.warning), 'abpl-status-warn');
                            return;
                        }

                        if (enabled) {
                            setStatus(id, escapeHtml(config.strings.enabled), 'abpl-status-ok');
                        } else {
                            setStatus(id, escapeHtml(config.strings.disabled), 'abpl-status-off');
                        }
                    })
                    .catch(function () {
                        input.checked = !enabled;
                        setStatus(id, '⚠ ' + escapeHtml(config.strings.networkError), 'abpl-status-skipped');
                    })
                    .finally(function () {
                        input.disabled = false;
                    });
                });
            }

            function bindUpdateCheckHandler() {
                var btn       = document.querySelector('[data-action="abpl-check-updates"]');
                var resultEl  = document.querySelector('[data-update-result]');
                if (!btn || !resultEl) return;

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    btn.textContent = config.strings.checking;
                    resultEl.innerHTML = '';

                    fetch(config.checkEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': config.nonce }
                    })
                    .then(function (r) {
                        return r.json().then(function (body) {
                            return { ok: r.ok, body: body };
                        });
                    })
                    .then(function (res) {
                        if (!res.ok || !res.body) {
                            resultEl.innerHTML = '<span class="abpl-status-skipped">⚠ ' +
                                escapeHtml(config.strings.checkFailed) + '</span>';
                            return;
                        }
                        var body = res.body;
                        if (body.available && body.release_url) {
                            resultEl.innerHTML =
                                '<span class="abpl-status-warn">↑ ' + escapeHtml(body.message) + '</span> ' +
                                '<a href="' + escapeHtml(body.release_url) + '" target="_blank" rel="noopener noreferrer">' +
                                escapeHtml(config.strings.viewRelease) + '</a>';
                        } else if (body.latest) {
                            resultEl.innerHTML = '<span class="abpl-status-ok">✓ ' + escapeHtml(body.message) + '</span>';
                        } else {
                            resultEl.innerHTML = '<span class="abpl-status-skipped">⚠ ' + escapeHtml(body.message) + '</span>';
                        }
                    })
                    .catch(function () {
                        resultEl.innerHTML = '<span class="abpl-status-skipped">⚠ ' +
                            escapeHtml(config.strings.networkError) + '</span>';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.textContent = config.strings.checkLabel;
                    });
                });
            }

            if (config.bindToggles) {
                bindToggleHandler();
            }
            bindUpdateCheckHandler();
        })();
        </script>
        <?php
    }

    private static function render_inline_styles(): void
    {
        ?>
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

            .abpl-switch {
                position: relative;
                display: inline-block;
                width: 42px;
                height: 22px;
                vertical-align: middle;
            }
            /*
             * Visually-hidden input. The WP admin's input[type="checkbox"]
             * skin (min-width: 1rem, border, ::before checkmark, focus
             * outline) was leaking just above the slider during the
             * disable/enable transition. Clipping to 1x1 and explicitly
             * neutralizing the admin overrides keeps that out of paint.
             */
            .abpl-switch input {
                position: absolute;
                width: 1px;
                height: 1px;
                min-width: 0;
                min-height: 0;
                margin: 0;
                padding: 0;
                border: 0;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                background: transparent;
                box-shadow: none;
                appearance: none;
                -webkit-appearance: none;
            }
            .abpl-switch input::before,
            .abpl-switch input::after {
                display: none;
            }
            .abpl-switch-slider {
                position: absolute;
                cursor: pointer;
                inset: 0;
                background-color: #ccc;
                border-radius: 22px;
                transition: background-color 0.18s ease;
            }
            .abpl-switch-slider::before {
                content: "";
                position: absolute;
                height: 16px;
                width: 16px;
                left: 3px;
                top: 3px;
                background-color: #fff;
                border-radius: 50%;
                transition: transform 0.18s ease;
                box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            }
            .abpl-switch input:checked + .abpl-switch-slider {
                background-color: #2271b1;
            }
            .abpl-switch input:checked + .abpl-switch-slider::before {
                transform: translateX(20px);
            }
            .abpl-switch input:focus-visible + .abpl-switch-slider {
                outline: 2px solid #2271b1;
                outline-offset: 2px;
            }
            .abpl-switch input:disabled + .abpl-switch-slider {
                opacity: 0.55;
                cursor: wait;
            }

            .abpl-status-ok      { color: #008a20; }
            .abpl-status-off     { color: #888; }
            .abpl-status-saving  { color: #2271b1; font-style: italic; }
            .abpl-status-warn    { color: #b26a00; }
            .abpl-status-skipped { color: #b32d2e; }

            .abpl-update-card {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 1em;
                padding: 12px 16px;
                margin: 1em 0;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-left: 4px solid #2271b1;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            .abpl-update-card-info {
                display: flex;
                align-items: center;
                gap: 0.75em;
                flex: 1 1 auto;
            }
            .abpl-update-card-version { color: #555; }
            .abpl-update-card-action {
                display: flex;
                align-items: center;
                gap: 0.75em;
                flex: 0 1 auto;
            }
            .abpl-update-card-result:empty { display: none; }
        </style>
        <?php
    }

    /**
     * kebab-case id → human-friendly title. v0.0.1 stub.
     */
    private static function prettify_id(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
