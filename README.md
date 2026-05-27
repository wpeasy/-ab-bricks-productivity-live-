# BRXProd Live

Runtime registrar for [Bricks Productivity](https://brxprod.com) code snippets.

The parent plugin (`ab-bricks-productivity`) ships every frontend-facing feature as a copy-paste Code Snippet so you can choose whether to install it. That keeps deactivating the parent plugin safe but pushes friction onto users who'd prefer one-click on/off.

This sibling plugin gives you that toggle: an admin page that lists every snippet from the parent's `assets/snippets/` directory and lets you enable each one. Enabled snippets are loaded on every request — PHP via `include_once`, JS via `wp_enqueue_script`, CSS via `wp_enqueue_style`.

## How it works

- BRXProd Live reads the parent's snippet files directly from the filesystem at `wp-content/plugins/ab-bricks-productivity/assets/snippets/`.
- The parent does **not** need to be active. Only the parent's files need to exist on disk.
- If the parent's files are missing, BRXProd Live shows an admin notice and disables all snippets safely (no fatals).

## Install

1. Activate `BRXProd Live` in the WordPress plugins list.
2. Run `composer dump-autoload -o` in the plugin's directory the first time (only needed when adding new PHP classes — not for normal use after a release ZIP).
3. Open **BRXProd Live** in the admin menu.
4. Toggle on the snippets you want loaded.

## License

GPL-2.0-or-later. Same as the parent plugin.
