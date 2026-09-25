<p align="center">
  <img src=".wordpress-org/assets/icon-256x256.png" width="96" height="96" alt="LucidIT logo" />
</p>

<h1 align="center">LucidIT WordPress Enabler</h1>

<p align="center">
  Built by <a href="https://o-matic.ai">O-MATIC</a>, the AI research division of <a href="https://lucidit.io">LucidIT, LLC</a>.
</p>

---

A full WordPress abilities surface for the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), plus a first-party Elementor MCP — one plugin, one endpoint, no second server to configure.

This plugin registers **81 abilities** that turn a WordPress site into something an AI agent can actually operate, not just read about:

- **Content** — posts and pages CRUD, with meta, slug, template, and featured image
- **Taxonomy** — categories, tags, and custom taxonomy, full CRUD
- **Media** — list and sideload-upload, with alt text and attachment
- **Site** — site info, options get/set/list/delete (with a security blocklist)
- **Users** — list, get, create, update, delete, with role and meta management
- **Comments** — list, get, moderate, delete, counts
- **Plugins** — list installed (with update check), activate, deactivate
- **Menus** — list menus with locations, get menu items; create menus, add / update / delete items (page, post, custom link, and a Polylang language-switcher item stored exactly as Polylang stores it), assign a menu to a theme location — per Polylang language when Polylang is active
- **Site Editor** — read and update block-theme template parts by slug and `wp_navigation` posts by ID, insert Polylang's language-switcher block into either, and an automatic per-write snapshot with `site-editor-restore-snapshot`
- **Verify** — fetch a public URL on the site server-side as an anonymous visitor and report which strings actually render; list the per-language URLs Polylang reports for a post
- **Themes** — active theme info with page templates, list all installed
- **Search** — site-wide search across all post types
- **Elementor** — page/element discovery and editing, global design tokens, template import/export, SVG upload, and automatic per-write snapshots so every change is one call from being undone

## Dependency

This plugin requires the **[WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/)** plugin (`github.com/WordPress/mcp-adapter`) to be installed and active. It is a separate, official WordPress.org plugin, part of the ["AI Building Blocks for WordPress"](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative — install it first, then this one. LucidIT WordPress Enabler registers abilities on the Abilities API hooks the MCP Adapter reads from; it does not bundle, fork, or modify the MCP Adapter itself.

Elementor support requires [Elementor](https://elementor.com) (or Elementor Pro) to already be active on the site. Elementor abilities register through the same hooks and are simply invisible to the MCP Adapter if Elementor isn't installed.

## Why this exists

The WordPress MCP Adapter gives WordPress an Abilities API bridge to MCP, but ships with very few abilities registered out of the box. This plugin is the surface that actually makes an AI agent useful against a real site: content and taxonomy management, user and comment moderation, plugin/theme inventory, and — because page builders are where most WordPress work actually happens — full Elementor structural editing with the correctness guarantees a naive integration gets wrong:

- **Slash-escaping.** `_elementor_data` is stored slash-escaped; writes go through `wp_slash( wp_json_encode() )` so escaped quotes in text widgets survive.
- **CSS invalidation.** Every write invalidates Elementor's compiled per-post CSS. Skip this and an edit lands in the data while the page still looks stale.
- **No flattening.** Edits walk the element tree by ID and deep-merge settings — siblings and unknown keys are preserved, nothing gets replaced wholesale.
- **Automatic snapshots.** Every write takes a rolling snapshot first (8 held per post), so any change is one call from `elementor-restore-snapshot`.

## Installation

1. Install and activate the [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.
2. Download the latest release from this repo (or `wordpress-ai-enabler/` in this repo) and upload it to `/wp-content/plugins/`.
3. Activate **LucidIT WordPress Enabler** through the Plugins menu.
4. Abilities register automatically on the Abilities API hooks — no separate configuration.

## Security

- An options blocklist prevents remote modification of `siteurl`, `home`, auth keys, and salts.
- User meta responses strip session tokens and capability data.
- All inputs are sanitized through WordPress core functions.
- Permission callbacks enforce standard WordPress capability checks — an agent can only do what the authenticated user could do by hand.
- SVG uploads are sanitized: `<script>`, event handlers, `javascript:`/`data:` URIs, `foreignObject`, and external entities are stripped before the file is accepted.

## License

GPL-2.0-or-later. See `LICENSE`.

## Part of the O-MATIC factory

LucidIT WordPress Enabler is the WordPress-side companion to the [O-Matic WordPress & Elementor MCP connectors](https://github.com/lucidIT-LLC/o-matic-supply) — the connectors talk to the REST endpoints this plugin exposes. Install this plugin on the target WordPress site, then point the connector at it.
