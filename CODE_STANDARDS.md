# Code Standards

Conventions for `ab-bricks-productivity-live`. The plugin is intentionally pure PHP — no build step, no JS framework, no frontend asset pipeline. The standards reflect that.

The sister plugin `ab-bricks-productivity` has a fuller `CODE_STANDARDS.md` that covers Svelte 5, Vite, TypeScript, WPEA framework conventions, etc. — none of that applies here. Refer to it for cross-project consistency on PHP-only standards; the rest is parent-context only.

---

## General Principles

1. **Consistency over preference** — match existing patterns in this plugin and the parent plugin.
2. **Explicit over implicit** — readable code, no clever shortcuts.
3. **Comments explain why, not what** — code should explain "what" via good names; comments capture invariants, gotchas, and links to issues.
4. **Fail fast, fail loudly** — but never fatal in production. Skip the failing step, log via `error_log()` gated on `WP_DEBUG`, surface the issue in the admin UI.
5. **Trust nothing from `$_POST` / `$_GET` / `$_FILES`** — sanitize on receive, escape on output.

---

## Naming Conventions

### Files

| Type | Convention | Example |
|------|------------|---------|
| PHP Classes | PascalCase | `SnippetRegistry.php` |
| Config files | lowercase + dot | `composer.json` |
| Docs | UPPERCASE_SNAKE | `CLAUDE.md`, `CODE_STANDARDS.md` |

### PHP

| Type | Convention | Example |
|------|------------|---------|
| Classes | PascalCase | `SnippetLoader` |
| Methods | snake_case (WP convention) | `enabled_ids()` |
| Static factory methods | snake_case | `parent_snippets_dir()` |
| Constants | UPPER_SNAKE_CASE | `ABPL_PARENT_SLUG` |
| Variables | $snake_case | `$enabled_ids` |
| Hooks (action/filter) | snake_case with `abpl/` prefix | `abpl/snippet/before_load` |
| Option keys | `abpl_` prefix + snake_case | `abpl_enabled_snippets` |

---

## PHP File Header

Every PHP file in `src/`:

```php
<?php

declare(strict_types=1);

namespace AB\BricksProductivityLive[\Subnamespace];

defined('ABSPATH') || exit;

/**
 * One-line summary of what the class does.
 *
 * Longer description with any non-obvious behaviour, invariants,
 * or gotchas. Reference related classes by FQN.
 */
final class ClassName {
    // ...
}
```

- `declare(strict_types=1);` is mandatory.
- `defined('ABSPATH') || exit;` is mandatory.
- Classes are `final` unless they're explicitly designed to be extended.
- Use static methods for stateless utilities. Instance state only when there's genuine per-instance state to track.

---

## WordPress API Usage

- **All option access** through `get_option()` / `update_option()`. Always supply a default to `get_option()`. Use `autoload=false` on `update_option()` when the value is only read from admin / settings paths.
- **All capability checks** on every admin-page render AND every save handler. Capability for this plugin's pages: `manage_options`. Nonce-verify every form POST.
- **All output escaping** via `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, `esc_textarea()`. Never echo `$_POST` / option values / file paths without escaping — even when "you know" the source is trusted, the next reader doesn't.
- **All input sanitization** via `sanitize_key()` for ids, `sanitize_text_field()` for short text, `wp_unslash()` before any of those if the input came from `$_POST` / `$_GET`.
- **i18n on every user-visible string** via `__('...', 'ab-bricks-productivity-live')`. Use `esc_html__()` when the string goes into HTML context.

---

## Security Checklist (mirrors the parent's audit history)

Every PR that adds an admin handler must satisfy:

- [ ] Capability check (`current_user_can('manage_options')`) at the top of the handler.
- [ ] Nonce verified (`wp_verify_nonce` or `check_admin_referer`).
- [ ] All `$_POST` / `$_GET` reads pass through `wp_unslash` + a `sanitize_*` function before use.
- [ ] All HTML output is escaped via the appropriate `esc_*` helper.
- [ ] No `include` / `require` of a path that includes any user-controlled segment.
- [ ] No `error_log()` of user content or option values without a `WP_DEBUG` gate.
- [ ] Option writes use `autoload=false` when the value is admin-context only.

---

## Coding Style

- **Indentation:** 4 spaces. No tabs.
- **Line length:** soft 100, hard 120.
- **Quotes:** single-quoted strings unless interpolating; double-quoted with interpolation is fine when readable.
- **Trailing comma** in multi-line array / argument lists (PHP 8 supports it everywhere).
- **Short array syntax** `[]` everywhere — never `array()`.
- **No `else` after `return`** — early-return pattern preferred.
- **Type hints** on every parameter + return type. Use `?Type` for nullable, `mixed` only when genuinely unknown.

Bad:
```php
function get_path($slug) {
    if ($slug) {
        return WP_PLUGIN_DIR . "/" . $slug;
    } else {
        return "";
    }
}
```

Good:
```php
public static function get_path(string $slug): string
{
    if ($slug === '') {
        return '';
    }
    return WP_PLUGIN_DIR . '/' . $slug;
}
```

---

## Testing (manual for v0.0.1)

No PHPUnit setup in v0.0.1. Verification is manual via the steps in `CLAUDE.md`'s "Snippet Discovery + Load Flow" and the four scenarios from the plan:

1. Snippets toggle on/off persist.
2. Enabled PHP snippet effect is visible on the frontend.
3. Enabled JS snippet appears in the page source with `abpl-{id}-js` handle.
4. Parent-files-missing fallback shows the admin notice and skips all loads.

When the plugin grows beyond a single settings page, introduce PHPUnit + WP_Mock — that's the trigger to write the test plumbing.
