<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive\Admin;

use AB\BricksProductivityLive\SnippetLoader;
use AB\BricksProductivityLive\SnippetRegistry;
use AB\BricksProductivityLive\Updater;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * REST controller for the admin UI's auto-save toggles.
 *
 * Single endpoint: POST /abpl/v1/toggle — flips one snippet's enabled
 * state. For PHP snippets being turned ON, runs a frontend loopback probe
 * to catch fatals. If the probe trips, the change is reverted on the
 * server and the response tells the client to flip back.
 */
final class RestController
{
    public const REST_NAMESPACE      = 'abpl/v1';
    public const ROUTE_TOGGLE        = '/toggle';
    public const ROUTE_CHECK_UPDATES = '/check-updates';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE_TOGGLE, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_toggle'],
            'permission_callback' => [self::class, 'permission_check'],
            'args'                => [
                'id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
                'enabled' => [
                    'type'     => 'boolean',
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, self::ROUTE_CHECK_UPDATES, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle_check_updates'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);
    }

    /**
     * Force a fresh GitHub release lookup. Returns a UI-friendly summary
     * the settings page renders inline. Also clears WP's own
     * `update_plugins` transient so the plugins.php list refreshes on the
     * next admin page load.
     */
    public static function handle_check_updates(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // unused — kept for the callback signature
        return new WP_REST_Response(Updater::force_check_and_describe(), 200);
    }

    public static function permission_check(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Toggle handler. Persists the new state, optionally probes, returns
     * `{enabled, reverted, message, warning?}`.
     */
    public static function handle_toggle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id      = (string) $request->get_param('id');
        $enabled = (bool) $request->get_param('enabled');

        $snippet = SnippetRegistry::find($id);
        if ($snippet === null) {
            return new WP_Error(
                'abpl_unknown_snippet',
                __('Unknown snippet id.', 'ab-bricks-productivity-live'),
                ['status' => 404]
            );
        }

        $existing = SnippetLoader::enabled_ids();
        $next     = array_values(array_diff($existing, [$id]));
        if ($enabled) {
            $next[] = $id;
        }
        SnippetLoader::set_enabled($next);

        // Only PHP snippets being turned ON warrant a probe — disables
        // can't introduce fatals, and JS/CSS can't fatal the server.
        if ($enabled && $snippet['language'] === 'php') {
            $probe = self::probe_frontend();

            if ($probe['status'] === 'error') {
                SnippetLoader::set_enabled(array_values(array_diff($next, [$id])));
                return new WP_REST_Response([
                    'enabled'  => false,
                    'reverted' => true,
                    'message'  => $probe['message'],
                ], 200);
            }

            if ($probe['status'] === 'unverified') {
                return new WP_REST_Response([
                    'enabled'  => true,
                    'reverted' => false,
                    'warning'  => $probe['message'],
                ], 200);
            }
        }

        return new WP_REST_Response([
            'enabled'  => $enabled,
            'reverted' => false,
            'message'  => $enabled
                ? __('Enabled.', 'ab-bricks-productivity-live')
                : __('Disabled.', 'ab-bricks-productivity-live'),
        ], 200);
    }

    /**
     * Probe the site's frontend with a fresh anonymous loopback request.
     * The just-saved option means a new PHP process will load the snippet
     * — if it fatals, we see it in the response.
     *
     * Status meanings:
     *   - `ok`         — request succeeded with no fatal markers.
     *   - `error`      — caller MUST revert (HTTP 5xx or fatal signature).
     *   - `unverified` — loopback itself failed; keep the change but warn.
     *
     * @return array{status: string, message: string}
     */
    private static function probe_frontend(): array
    {
        // Random query arg busts most page caches so the snippet actually
        // executes on this request rather than being served from cache.
        $url = add_query_arg(
            ['abpl_probe' => wp_generate_password(8, false, false)],
            home_url('/')
        );

        $response = wp_remote_get($url, [
            'timeout'   => 10,
            'sslverify' => false,
            'blocking'  => true,
            'cookies'   => [],
            'headers'   => ['X-ABPL-Probe' => '1'],
        ]);

        if (is_wp_error($response)) {
            return [
                'status'  => 'unverified',
                'message' => sprintf(
                    /* translators: %s = WP_Error message */
                    __('Enabled, but the frontend probe could not run: %s. Please test the site manually.', 'ab-bricks-productivity-live'),
                    $response->get_error_message()
                ),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code >= 500) {
            return [
                'status'  => 'error',
                'message' => sprintf(
                    /* translators: %d = HTTP status code */
                    __('Frontend returned HTTP %d after enabling — snippet disabled. Check your debug log for the fatal.', 'ab-bricks-productivity-live'),
                    $code
                ),
            ];
        }

        // WP_DEBUG can leak fatals into the response body. Recovery-mode
        // and the default critical-error template have stable strings too.
        $signatures = [
            'Fatal error',
            'Parse error',
            'There has been a critical error on this website',
            'There has been a critical error on your website',
        ];
        foreach ($signatures as $sig) {
            if (stripos($body, $sig) !== false) {
                return [
                    'status'  => 'error',
                    'message' => sprintf(
                        /* translators: %s = matched fatal-error signature */
                        __('Frontend response contained "%s" after enabling — snippet disabled.', 'ab-bricks-productivity-live'),
                        $sig
                    ),
                ];
            }
        }

        return [
            'status'  => 'ok',
            'message' => __('Enabled and verified.', 'ab-bricks-productivity-live'),
        ];
    }
}
