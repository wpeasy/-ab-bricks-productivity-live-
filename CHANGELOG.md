# Changelog

All notable changes to BRXProd Live are documented in this file.

## 0.0.2 — 2026-05-27

### New features
- **Toggle switches with auto-save.** The admin UI now uses on/off switches that persist each change immediately via a `POST /wp-json/abpl/v1/toggle` REST call. The `Save Changes` button is gone.
- **Frontend probe for PHP snippets.** When a PHP snippet is enabled, the server runs a loopback `wp_remote_get(home_url('/'))` and inspects the response. If the page returns HTTP 5xx or the body contains a fatal-error signature (`Fatal error`, `Parse error`, `There has been a critical error…`), the snippet is automatically reverted and the row surfaces the reason. Loopback failures (timeout, firewall) leave the snippet enabled and flag an "unverified — test manually" warning.
- **Self-updater via GitHub Releases.** New `Updater` class hooks `pre_set_site_transient_update_plugins`, `plugins_api`, and `upgrader_source_selection` so updates are pulled from `github.com/wpeasy/-ab-bricks-productivity-live-` with no wordpress.org dependency. The release lookup is cached for 12 hours (15 minutes on error) to stay under GitHub's anonymous rate limit. The updater prefers an uploaded asset matching `{slug}.zip` or `{slug}-{version}.zip` (ships `vendor/`), falling back to the GitHub source archive.
- **"Check for updates" button.** Settings page now has an inline check-now control that clears the GitHub cache plus WP's `update_plugins` transient and renders the result (✓ up to date / ↑ update available / ⚠ unreachable) without a page reload.
- **Menu positioning.** When the parent `ab-bricks-productivity` plugin is active, the BRXProd Live menu sits at position `3.2` — directly below the parent at `3.1`. Falls back to `81` when the parent isn't active.
- **Build script.** Added `create-plugin-zip.ps1` so releases produce a clean `ab-bricks-productivity-live-{version}.zip` containing `vendor/` and excluding hidden files, Composer manifests, and Markdown.

### Fixes
- **Switch UI artifact.** WordPress admin's `input[type="checkbox"]` styling (1rem min-width, `::before` checkmark, focus outline) was leaking through `opacity: 0` and showing as a line above the slider during the disable/enable transition. The input is now properly visually-hidden (`clip: rect(0,0,0,0)` + `appearance: none` + explicit min-width/min-height overrides). Focus indication moves to the slider via `:focus-visible`.

### Removed
- `Admin\SettingsPage::register_save_handler()` and the `admin_post_abpl_save_snippets` handler — replaced by the REST endpoint.

## 0.0.1 — 2026-05-27

Initial scaffold.

- Stateless snippet discovery from `WP_PLUGIN_DIR/ab-bricks-productivity/assets/snippets/*.{php,js,css}`.
- `SnippetLoader` includes enabled `.php` on `plugins_loaded@6` (with `token_get_all`-based duplicate-symbol detection) and enqueues `.js` / `.css` on `wp_enqueue_scripts`.
- Server-rendered admin settings page with capability + nonce.
- Parent plugin only needs files on disk — does NOT need to be active.
