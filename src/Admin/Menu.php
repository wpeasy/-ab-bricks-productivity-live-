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
            81
        );
    }
}
