<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive;

defined('ABSPATH') || exit;

/**
 * Plugin bootstrap. Single entry point — wires the admin UI in admin
 * context and always wires the SnippetLoader so enabled snippets fire on
 * every request.
 */
final class Plugin
{
    public static function init(): void
    {
        // Admin UI — registered only when serving the admin (saves a few
        // function calls per frontend request).
        if (is_admin()) {
            Admin\Menu::init();
            Admin\SettingsPage::register_save_handler();
        }

        // Snippet loader — always on. Internally no-ops if the parent's
        // snippet files don't exist on disk.
        SnippetLoader::init();
    }
}
