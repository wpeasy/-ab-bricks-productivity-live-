<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive\Admin;

defined('ABSPATH') || exit;

/**
 * Admin menu registration. Single top-level menu page; the page body is
 * rendered by `SettingsPage::render()`.
 */
final class Menu
{
    public const MENU_SLUG = 'abpl-snippets';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            __('BRXProd Live', 'ab-bricks-productivity-live'),
            __('BRXProd Live', 'ab-bricks-productivity-live'),
            'manage_options',
            self::MENU_SLUG,
            [SettingsPage::class, 'render'],
            'dashicons-shortcode',
            self::menu_position()
        );
    }

    /**
     * Pick a menu position. When the parent plugin is active its top-level
     * menu sits at 3.1; placing ours at 3.2 makes it the next item in the
     * sidebar. When the parent isn't active we fall back to a high default
     * so we don't shoulder our way next to unrelated admin menus.
     */
    private static function menu_position(): float
    {
        return class_exists(\AB\BricksProductivity\Admin\Menu::class) ? 3.2 : 81.0;
    }
}
