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
        if (is_admin()) {
            Admin\Menu::init();
        }

        // REST routes register on rest_api_init, which only fires for REST
        // requests — cheap to wire unconditionally and required for the
        // admin UI's auto-save toggles to work.
        Admin\RestController::init();

        // Snippet loader — always on. Internally no-ops if the parent's
        // snippet files don't exist on disk.
        SnippetLoader::init();

        // GitHub-releases self-updater. Hooks register cheaply on every
        // request; the actual GitHub API call only happens when WP refreshes
        // its update_plugins transient (~12h or on demand).
        Updater::init();
    }
}
