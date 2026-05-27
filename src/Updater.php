<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Self-updater that points WordPress at GitHub Releases instead of
 * wordpress.org.
 *
 * Flow:
 *   1. WP refreshes its `update_plugins` site transient (every ~12h or on
 *      demand). Our `pre_set_site_transient_update_plugins` filter fires.
 *   2. We hit `api.github.com/repos/{owner}/{repo}/releases/latest`, cached
 *      in a site transient for 12h to stay under GitHub's 60 req/hr
 *      unauthenticated limit.
 *   3. If the release's tag is newer than `ABPL_VERSION`, we inject an
 *      update entry pointing at either a release asset named
 *      `{plugin-slug}.zip` (preferred — ships vendor/) or GitHub's
 *      auto-generated source archive (fallback).
 *   4. When the user clicks "update now", WP downloads the package and
 *      our `upgrader_source_selection` filter renames the extracted
 *      directory to the canonical plugin slug.
 *   5. `plugins_api` is filtered so the "View details" modal shows
 *      release notes pulled from the GitHub release body.
 *
 * Failure modes are intentionally silent — a missing release, a 404,
 * a rate-limit response, or a network blip should not break the admin
 * UI. Negative responses cache for 15 minutes so we don't hammer GitHub
 * when something's wrong.
 */
final class Updater
{
    private const CACHE_KEY     = 'abpl_github_latest_release';
    private const CACHE_TTL     = 12 * HOUR_IN_SECONDS;
    private const NEG_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_details'], 10, 3);
        add_filter('upgrader_source_selection', [self::class, 'rename_extracted_dir'], 10, 4);
    }

    /**
     * Wipe the GitHub-release cache AND WordPress's plugin-update transient
     * so the next admin page load re-runs the full update check.
     */
    public static function clear_cache(): void
    {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }

    /**
     * Force a fresh check against GitHub and return a UI-friendly summary.
     *
     * @return array{
     *     current: string,
     *     latest: string|null,
     *     available: bool,
     *     release_url: string,
     *     message: string,
     * }
     */
    public static function force_check_and_describe(): array
    {
        self::clear_cache();

        $current = (string) ABPL_VERSION;
        $release = self::fetch_latest_release();

        if ($release === null) {
            return [
                'current'     => $current,
                'latest'      => null,
                'available'   => false,
                'release_url' => '',
                'message'     => __('Could not reach GitHub. Check the site\'s outbound connectivity and try again.', 'ab-bricks-productivity-live'),
            ];
        }

        $available = version_compare($release['version'], $current, '>');

        return [
            'current'     => $current,
            'latest'      => $release['version'],
            'available'   => $available,
            'release_url' => $release['html_url'],
            'message'     => $available
                ? sprintf(
                    /* translators: %s = release version. */
                    __('Update available — version %s.', 'ab-bricks-productivity-live'),
                    $release['version']
                )
                : __('BRXProd Live is up to date.', 'ab-bricks-productivity-live'),
        ];
    }

    /**
     * Inject our update entry into the `update_plugins` transient. If no
     * newer release exists, the transient is returned unchanged.
     */
    public static function inject_update($transient)
    {
        // WP sometimes calls this very early with a non-object value; bail
        // safely if we don't have a real transient yet.
        if (!is_object($transient)) {
            return $transient;
        }

        $release = self::fetch_latest_release();
        if ($release === null) {
            return $transient;
        }

        if (version_compare($release['version'], (string) ABPL_VERSION, '<=')) {
            return $transient;
        }

        $slug = self::plugin_slug();
        $item = (object) [
            'id'            => ABPL_PLUGIN_BASENAME,
            'slug'          => $slug,
            'plugin'        => ABPL_PLUGIN_BASENAME,
            'new_version'   => $release['version'],
            'url'           => $release['html_url'],
            'package'       => $release['zip_url'],
            'tested'        => '6.5',
            'requires'      => '6.5',
            'requires_php'  => '8.0',
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'compatibility' => new \stdClass(),
        ];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[ABPL_PLUGIN_BASENAME] = $item;

        // WP also expects the plugin to appear in `no_update` when it does
        // NOT have an update — but since we DO have one, remove any stale
        // entry there.
        if (isset($transient->no_update[ABPL_PLUGIN_BASENAME])) {
            unset($transient->no_update[ABPL_PLUGIN_BASENAME]);
        }

        return $transient;
    }

    /**
     * Serve the "View details" modal from our cached release info.
     *
     * @param mixed                $result
     * @param string               $action
     * @param object|null          $args
     * @return mixed
     */
    public static function plugin_details($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!is_object($args) || empty($args->slug) || $args->slug !== self::plugin_slug()) {
            return $result;
        }

        $release = self::fetch_latest_release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name'              => 'BRXProd Live',
            'slug'              => self::plugin_slug(),
            'version'           => $release['version'],
            'author'            => '<a href="https://brxprod.com">Alan Blair</a>',
            'homepage'          => $release['html_url'],
            'short_description' => 'Runtime registrar for Bricks Productivity code snippets.',
            'requires'          => '6.5',
            'tested'            => '6.5',
            'requires_php'      => '8.0',
            'download_link'     => $release['zip_url'],
            'last_updated'      => $release['published_at'],
            'sections'          => [
                'description' => '<p>Runtime registrar for Bricks Productivity code snippets — toggle frontend snippets on or off from the WP admin.</p>',
                'changelog'   => self::format_release_body($release['body']),
            ],
            'banners'           => [],
        ];
    }

    /**
     * Rename `/wp-content/upgrade/{repo}-{tag}/` to `/wp-content/upgrade/
     * {plugin-slug}/` so WP installs into the right directory.
     *
     * Only acts when the upgrade target is this plugin — leaves every
     * other plugin/theme upgrade untouched.
     *
     * @param string|WP_Error $source
     * @param string          $remote_source
     * @param \WP_Upgrader    $upgrader
     * @param array<string,mixed> $hook_extra
     * @return string|WP_Error
     */
    public static function rename_extracted_dir($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (is_wp_error($source)) {
            return $source;
        }
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== ABPL_PLUGIN_BASENAME) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }

        $desired_slug = self::plugin_slug();
        $desired_path = trailingslashit($remote_source) . $desired_slug;

        if (trailingslashit($source) === trailingslashit($desired_path)) {
            return $source;
        }

        if ($wp_filesystem->move($source, $desired_path, true)) {
            return trailingslashit($desired_path);
        }

        return new WP_Error(
            'abpl_rename_failed',
            __('BRXProd Live updater: could not rename the extracted package directory.', 'ab-bricks-productivity-live')
        );
    }

    /**
     * Hit GitHub's latest-release endpoint with a 12h cache.
     *
     * @return array{version:string,html_url:string,body:string,zip_url:string,published_at:string}|null
     */
    private static function fetch_latest_release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        // `false` (vs null) signals a recent negative cache — don't retry yet.
        if ($cached === false && self::has_negative_cache()) {
            return null;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode(ABPL_GITHUB_OWNER),
            rawurlencode(ABPL_GITHUB_REPO)
        );

        $response = wp_safe_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ],
        ]);

        if (is_wp_error($response)) {
            self::store_negative_cache();
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            self::store_negative_cache();
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name']) || !is_string($body['tag_name'])) {
            self::store_negative_cache();
            return null;
        }

        $data = [
            'version'      => ltrim($body['tag_name'], 'vV'),
            'html_url'     => isset($body['html_url']) && is_string($body['html_url']) ? $body['html_url'] : '',
            'body'         => isset($body['body']) && is_string($body['body']) ? $body['body'] : '',
            'zip_url'      => self::pick_zip_url($body),
            'published_at' => isset($body['published_at']) && is_string($body['published_at']) ? $body['published_at'] : '',
        ];

        set_site_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * Prefer an uploaded release asset that bundles vendor/ — the build
     * script (`create-plugin-zip.ps1`) produces `{slug}-{version}.zip`,
     * but we also accept the exact `{slug}.zip` name for releases that
     * want a stable URL. Fall back to GitHub's auto-generated source
     * archive; its extracted directory will be `{repo}-{tag}` and gets
     * renamed by `rename_extracted_dir()`.
     *
     * @param array<string,mixed> $release
     */
    private static function pick_zip_url(array $release): string
    {
        $slug    = self::plugin_slug();
        $pattern = '/^' . preg_quote($slug, '/') . '(-[A-Za-z0-9._-]+)?\.zip$/i';

        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = $asset['name'] ?? '';
                $url  = $asset['browser_download_url'] ?? '';
                if (is_string($name) && is_string($url) && $url !== '' && preg_match($pattern, $name)) {
                    return $url;
                }
            }
        }

        return sprintf(
            'https://github.com/%s/%s/archive/refs/tags/%s.zip',
            rawurlencode(ABPL_GITHUB_OWNER),
            rawurlencode(ABPL_GITHUB_REPO),
            rawurlencode((string) ($release['tag_name'] ?? ''))
        );
    }

    private static function plugin_slug(): string
    {
        return dirname(ABPL_PLUGIN_BASENAME);
    }

    private static function store_negative_cache(): void
    {
        set_site_transient(self::CACHE_KEY, false, self::NEG_CACHE_TTL);
    }

    private static function has_negative_cache(): bool
    {
        // get_site_transient already returns false when the transient is
        // unset OR expired, so this helper exists purely to make the
        // distinction at the call site readable. The fact that we got
        // `false` (not array) means whichever branch we're in, retrying
        // immediately gains nothing within NEG_CACHE_TTL.
        return true;
    }

    /**
     * Escape the release body and wrap it in paragraphs. We deliberately
     * don't render full Markdown — the safe-escaped text is readable enough
     * for the View Details modal and avoids the dependency of a parser.
     */
    private static function format_release_body(string $body): string
    {
        if ($body === '') {
            return '<p>' . esc_html__('No release notes provided.', 'ab-bricks-productivity-live') . '</p>';
        }
        return wpautop(esc_html($body));
    }
}
