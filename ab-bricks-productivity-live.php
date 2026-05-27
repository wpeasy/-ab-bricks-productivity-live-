<?php
/**
 * Plugin Name:       BRXProd Live
 * Plugin URI:        https://brxprod.com
 * Description:       Runtime registrar for Bricks Productivity code snippets — toggle frontend snippets on/off from the WP admin instead of pasting them by hand.
 * Version:           0.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Alan Blair <alan@alanblair.co>
 * Author URI:        https://brxprod.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ab-bricks-productivity-live
 * Domain Path:       /languages
 * Network:           false
 * Update URI:        false
 *
 * @package AB\BricksProductivityLive
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Plugin constants
define('ABPL_VERSION', '0.0.3');
define('ABPL_PLUGIN_FILE', __FILE__);
define('ABPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ABPL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ABPL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// The parent plugin we read snippet files from. The parent does NOT need to
// be ACTIVE — only its files need to exist at this slug under WP_PLUGIN_DIR.
define('ABPL_PARENT_SLUG', 'ab-bricks-productivity');

// GitHub repository the updater pulls releases from. Owner/repo only —
// the updater builds api.github.com and github.com URLs from these.
define('ABPL_GITHUB_OWNER', 'wpeasy');
define('ABPL_GITHUB_REPO',  '-ab-bricks-productivity-live-');

// Composer autoloader
if (file_exists(ABPL_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ABPL_PLUGIN_DIR . 'vendor/autoload.php';
}

// Bootstrap on plugins_loaded priority 5 — snippets must load before any
// plugin/theme that might consume them on `init` / `after_setup_theme`.
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\AB\BricksProductivityLive\Plugin::class)) {
        return;
    }
    \AB\BricksProductivityLive\Plugin::init();
}, 5);

// Load plugin textdomain for i18n (translation files land under /languages/
// in a later release; loading the textdomain now means strings retro-translate
// once .mo files exist).
add_action('init', static function (): void {
    load_plugin_textdomain(
        'ab-bricks-productivity-live',
        false,
        dirname(ABPL_PLUGIN_BASENAME) . '/languages'
    );
});
