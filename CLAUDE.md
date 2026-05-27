# CLAUDE.md

## Project Properties

- **Plugin Name:** BRXProd Live
- **Description:** Runtime registrar for Bricks Productivity code snippets — toggle frontend snippets on/off from the WP admin.
- **Minimum WordPress:** 6.5
- **Minimum PHP:** 8.0
- **PHP Namespace:** `AB\BricksProductivityLive`
- **Constants Prefix:** `ABPL_`
- **Textdomain:** `ab-bricks-productivity-live`
- **Option Prefix:** `abpl_`
- **JS Global:** _(none — no frontend JS shipped from this plugin)_
- **CSS Prefix:** `.abpl-` _(reserved; nothing styled in v0.0.1)_
- **Parent Plugin Slug:** `ab-bricks-productivity` (defined as `ABPL_PARENT_SLUG`)

## Frontend Dependencies — The Whole Point Pillar

**The parent plugin (`ab-bricks-productivity`) enforces a strict NO-frontend-dependencies pillar. This plugin INVERTS that pillar — loading frontend code is its entire purpose.**

When this plugin is active and a snippet is enabled, BRXProd Live unconditionally:
- `include_once`s enabled `.php` snippets on `plugins_loaded` priority 5, so the snippets' `add_action` / `add_filter` calls land before `init` fires.
- `wp_enqueue_script()`s enabled `.js` snippets on `wp_enqueue_scripts`.
- `wp_enqueue_style()`s enabled `.css` snippets on `wp_enqueue_scripts`.

**What this plugin still must NOT do:**

- **Do not inject into the Bricks Builder iframe or builder admin UI.** That is the parent plugin's job. The live plugin is frontend-only — if a user wants builder enhancements they belong in the parent. Use `bricks_is_builder()` / `bricks_is_builder_iframe()` as guards if any future feature could leak into the builder.
- **Do not silently enqueue from your own `assets/` directory** for v0.0.1. Anything this plugin ships should be either (a) a snippet referenced from the parent or (b) an admin-only asset gated on `is_admin()`. A `wp_enqueue_scripts` callback that loads anything beyond user-enabled snippets is a bug.
- **Do not require the parent plugin to be active.** Only the parent's files need to exist on disk at `WP_PLUGIN_DIR/ABPL_PARENT_SLUG/`. See the next section.

## Parent Plugin Files — The Source of Truth

**Critical:** the constant `ABPL_PARENT_SLUG` (defined in the main plugin file as `'ab-bricks-productivity'`) points at the parent plugin's directory under `WP_PLUGIN_DIR`. The live plugin reads snippet files from `WP_PLUGIN_DIR . '/' . ABPL_PARENT_SLUG . '/assets/snippets/'`.

This means:

- The parent does **not** need to be active. It just needs to be installed (files on disk).
- If the parent is deactivated, BRXProd Live keeps working. Enabled snippets keep loading.
- If the parent is **deleted** (or the user installs BRXProd Live standalone), the snippet directory won't exist. Detect this via `SnippetRegistry::parent_files_exist()` and:
  - Show a clear admin notice on the BRXProd Live settings page.
  - Skip all `include_once` / enqueue calls — return cleanly, no fatals.
  - Optionally surface a hint pointing the user at the parent's download URL.

When working in this plugin, **never hardcode paths into the parent**. Always go through `SnippetRegistry::parent_snippets_dir()` / `parent_snippets_url()` so the slug stays the single source of truth.

## Snippet Discovery + Load Flow

```
SnippetRegistry::all()
    ├── glob() parent's assets/snippets/*.{php,js,css}
    ├── derive id from filename (without extension)
    ├── derive language from extension
    └── return [['id', 'language', 'filename', 'path', 'url'], ...]

SnippetLoader::init()
    ├── plugins_loaded@5  →  include_once each enabled .php
    └── wp_enqueue_scripts@10  →  enqueue each enabled .js / .css
```

**Discovery is stateless and re-runs every request.** No caching, no transients in v0.0.1 — `glob()` on a directory with <50 entries is microsecond-scale. If performance ever matters, cache invalidated by snippet-file mtime.

**Enabled state lives in a single option:** `get_option('abpl_enabled_snippets', [])` — an array of snippet IDs (filenames without extension). Persisted via `update_option('abpl_enabled_snippets', $ids, false)` — `autoload=false` because we only read it from request paths that already had to touch options anyway.

**File-extension-to-handler mapping:**

| Extension | Handler | Hook | Notes |
|---|---|---|---|
| `.php` | `include_once` | `plugins_loaded@5` | Snippet's own `add_action` / `add_filter` calls fire at their natural priority. |
| `.js` | `wp_enqueue_script` | `wp_enqueue_scripts@10` | Handle `abpl-{snippetId}`. Loads in footer by default. |
| `.css` | `wp_enqueue_style` | `wp_enqueue_scripts@10` | Handle `abpl-{snippetId}`. |
| anything else | _ignored_ | — | `.html`, `.txt`, etc. would be parent-side snippet sources only. |

**Adding a new snippet to the parent plugin** automatically surfaces it here — no live-plugin code change needed. The registry sees the new file on its next request.

## Doubling-Up Safety — `class_exists` / `function_exists` Guards

**Hard rule:** every PHP snippet load must check for symbol redeclaration first. A user who has already pasted the same snippet into FluentSnippets, WPCodeBox, or their child theme's `functions.php` would otherwise hit a fatal `Cannot redeclare class X` / `Cannot redeclare function Y` the moment they enable the snippet here.

**How the safety works:**

`SnippetRegistry::defines_for_php(string $path): array` uses PHP's built-in `token_get_all()` to walk the snippet file and pull out every top-level (brace-depth 0) class and function declaration. Returns `['classes' => [...], 'functions' => [...]]`. Tokens are accurate — no regex false positives from comments / strings / inner class methods.

`SnippetLoader` calls this for every enabled `.php` snippet BEFORE `include_once`-ing it. For each symbol:

- If `class_exists($name, false)` or `function_exists($name)` returns true → **skip the include**. Don't fatal. Record the snippet in a request-scoped "skipped" list with the offending symbol name.
- If no collision → `include_once` proceeds normally.

`SettingsPage::render()` reads the skipped list and surfaces a per-row warning: `"Skipped — '{className}' is already declared somewhere else (likely your code-manager or child theme). Disable it there or disable it here."`

**What this cannot detect:**

PHP snippets that ONLY register filter/action closures (no top-level class or function declared — `register-compound-animation.php` and `component-instance-class.php` are exactly this) cannot be detected as duplicates. Two anonymous closures hooked on the same filter are unique objects to `has_filter()`, so we can't tell them apart from "the user already added this elsewhere".

For these snippets, the admin UI surfaces a **prominent banner at the top of the settings page** warning that closure-only snippets cannot be auto-detected — the user must verify they haven't installed the snippet elsewhere before enabling.

**JS / CSS doubling:**

`wp_script_is($handle, 'enqueued')` / `wp_style_is($handle, 'enqueued')` is checked before `wp_enqueue_*` for our own handle. That catches the case where someone has also enqueued under the EXACT handle name we use (`abpl-{snippetId}`). It does NOT catch:

- Inline `<script>` blocks pasted into the page
- Same file content enqueued under a different handle
- Bundler-emitted JS that happens to do the same thing

For JS / CSS, the safety net is the same prominent banner — "verify before enabling."

**Adding new snippets — defensive coding contract:**

When authoring a new PHP snippet for `assets/snippets/`, prefer wrapping its work in a uniquely-named class or function (rather than an anonymous closure) wherever practical. That gives the duplicate-detection layer something concrete to check. Snippets that legitimately only register filter closures should be flagged in their docblock so the live plugin can show a stronger per-row warning for those specifically (a future v0.0.2 enhancement — for v0.0.1 the page-level banner applies to all).

## Required Reading

| File | Purpose |
|------|---------|
| **CODE_STANDARDS.md** | Naming conventions + general code style (PHP-only for this plugin). |

The parent plugin has additional docs (`BRICKS_NOTES.md`, `SVELTE5_IMPLEMENTATION.md`, `WPEA_FRAMEWORK.md`, etc.) — none apply here. This plugin is pure PHP, doesn't touch Bricks Vue state, and doesn't render Svelte UIs. If you ever need to read or modify Bricks state, that's a signal the work belongs in the parent plugin, not here.

## Development Workflow

**No build step.** This is a pure-PHP plugin. No `npm install`, no `vite build`, no TypeScript compilation. The admin UI is plain server-rendered PHP.

**After adding or renaming a PHP class:** run `composer dump-autoload -o` in the plugin's directory once. Optional if Composer's class-map is current — required after adding new classes that the autoloader hasn't seen yet. A release ZIP includes the generated `vendor/autoload.php` so end users never run Composer.

**Activation check:** the plugin file uses `class_exists()` before calling `Plugin::init()` so missing-vendor / missing-autoload scenarios fail silently instead of fataling.

**Adding a new admin page section:** drop a new method on `Admin\SettingsPage` and call it from `render()`. The page is plain PHP — no Svelte, no REST. If a feature ever needs JS interactivity, that's the trigger to introduce a build step.

## Security Patterns (inherited from the parent)

The parent plugin has a documented security-audit history. The same patterns apply here:

1. **`current_user_can('manage_options')`** on every settings-page render AND every save handler. The save handler also verifies a nonce.
2. **No `error_log()` of sensitive content** without a `WP_DEBUG` gate. The live plugin's snippet metadata is non-sensitive (filenames + paths) — but if you ever log raw user input or option values, gate it.
3. **No string interpolation of file paths** in any `include` / `require`. The registry returns absolute paths from `glob()` against a fixed directory — that's the trust boundary. Never accept a path from a request param and require it.
4. **Sanitize option writes**: `array_map('sanitize_key', $ids)` on the enabled-snippets array before saving. The registry only emits well-formed kebab-case ids, but the option round-trips through `$_POST` which is untrusted.

## Coding-style quick reference

- `declare(strict_types=1);` at the top of every PHP file.
- Namespace + `defined('ABSPATH') || exit;` immediately after.
- Static methods on classes for the v0.0.1 scaffold (no constructors, no singletons). Instantiate only when state needs to live longer than a single request — and currently nothing does.
- 4-space indentation. Single-quoted strings unless interpolating.
- Translatable strings: `__('...', 'ab-bricks-productivity-live')`. Never `_e`/echo without escaping.
- HTML output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` as appropriate. Never echo user input or option values raw.
