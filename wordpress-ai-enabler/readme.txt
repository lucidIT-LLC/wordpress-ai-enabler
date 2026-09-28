=== LucidIT WordPress Enabler ===
Contributors: jameswalker
Tags: abilities-api, mcp, ai, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.1
License: GPL-2.0+

Registers 81 WordPress abilities for the MCP Adapter — content, users, comments, plugins, options, menus (read and write), block-theme template parts and navigation, public-render verification, themes, media, meta, taxonomy CRUD, site-wide search, and Elementor.

== Description ==

The LucidIT WordPress Enabler exposes WordPress capabilities through the Abilities API, enabling AI agents and automation tools to interact with your site via the MCP Adapter.

**Ability Categories (13):**

* Content — Posts and pages CRUD with meta, slug, template, and featured image support
* Taxonomy — Categories, tags, and custom taxonomy full CRUD
* Media — List and sideload-upload with alt text and attachment
* Site — Site info, options get/set/list/delete (with security blocklist)
* Users — List, get, create, update, delete with role and meta management
* Comments — List, get, moderate, delete, counts
* Plugins — List installed (with update check), activate, deactivate
* Menus — List menus with locations, get menu items; create menus, add/update/delete items (page, post, custom link, Polylang language switcher), assign locations
* Site Editor — Read/update block-theme template parts and wp_navigation posts, insert Polylang's language-switcher block, automatic snapshots with restore
* Verify — Fetch a public URL on the site server-side and report which strings render; per-language URLs Polylang reports for a post
* Themes — Active theme info with page templates, list all installed
* Search — Site-wide search across all post types

**Security:**

* Options blocklist prevents remote modification of siteurl, home, auth keys/salts
* User meta strips session tokens and capabilities from API responses
* All inputs sanitized through WordPress core functions
* Permission callbacks enforce WordPress capability checks

== Installation ==

1. Upload the `lucid-wp-enabler` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu in WordPress
3. Requires the MCP Adapter plugin to be installed and active

== Changelog ==

= 2.5.1 =
* Restored (present on every live site, missing from 2.5.0): omatic/migrate-legacy-o-matic-post and the optional omatic_legacy_redirect 301 redirect (template_redirect), both from the deployed 2.3.0 build; the 'page' alias on omatic/posts-list and omatic/search (task T-S7-002) from the 2.5.0 package build.
* Options allowlist widened to the writes factory records show in use: omatic_* and ewww_image_optimizer_* prefixes; default_role may be set to an unprivileged role (never deleted); users_can_register may be set to 0 (never deleted).
* Note: a separately built package also carried the number 2.5.0 (2026-09-11, lucidit.io/assets/plugin-packages). 2.5.1 supersedes both and contains everything either one registers.

= 2.5.0 =
* Security: omatic/elementor-upload-svg now sanitizes with a DOMDocument allowlist (includes/svg-sanitizer.php) in place of the regex sanitizer, which let `<svg/onload=...>`, unquoted `href=javascript:` and entity-encoded `&#106;avascript:` through. DOCTYPE internal subsets and ENTITY declarations are refused; only allowlisted SVG elements and attributes survive; href must be a #fragment (http(s) also on <a>, base64 raster data: also on <image>); url() must be url(#fragment).
* Security: the SVG url is fetched with wp_safe_remote_get (reject_unsafe_urls) and a 2 MiB limit_response_size.
* Security (breaking): omatic/options-update and omatic/options-delete use a write allowlist (general, reading, discussion, media settings and elementor_* options) instead of a blocklist; options-delete previously had no guard. Extend with the omatic_options_write_allowlist and omatic_options_write_allowed_prefixes filters.
* Internal: one rolling-snapshot mechanism (omatic_snapshot_push) for Elementor and Site Editor snapshots; stored format unchanged.
* Internal: OMATIC_ENABLER_VERSION is read from the plugin header; the verify user agent uses it.

= 2.4.0 =
* Added: Menus write — omatic/menus-create, menus-add-item, menus-update-item, menus-delete-item, menus-assign-location. Items: page, post, custom link, and a Polylang language switcher stored the way Polylang stores it (custom item with url #pll_switcher and _pll_menu_item options meta; Polylang source src/admin/admin-nav-menu.php, 3.8.9). menus-assign-location takes an optional Polylang language and writes that language's assignment where Polylang keeps it (polylang[nav_menus][theme][location][lang]).
* Added: Site Editor — omatic/template-parts-list, template-parts-get, template-parts-update (by theme//slug; a theme-file part is materialised as a customised copy first, as core's WP_REST_Templates_Controller does), navigation-list, navigation-get, navigation-update (wp_navigation posts), insert-language-switcher (polylang/navigation-language-switcher into a navigation post, or polylang/language-switcher into a template part; refuses when the block is not registered).
* Added: Site Editor safety — every template-part and navigation write takes a rolling snapshot first (8 held per post, same mechanism as the Elementor abilities); omatic/site-editor-list-snapshots and site-editor-restore-snapshot. The first snapshot on a materialised part is the pristine theme file.
* Added: Verify — omatic/verify-public-render (server-side wp_remote_get of a same-host URL with no cookies, reports which strings appear) and omatic/polylang-post-urls (per-language permalinks and home URLs Polylang reports).
* All new abilities: capability checks (edit_theme_options for menus and site editor), input sanitisation, both 7.1 unified and channel `public` flags. 16 new abilities; total 81.

= 2.0.0 =
* Full rewrite from O-Matic WP Abilities v1.0.2
* Added: Options/Settings CRUD (P1)
* Added: Users full lifecycle (P1)
* Added: Plugins audit and activation (P1)
* Added: Comments moderation (P2)
* Added: Post meta read/write/delete + featured image (P2)
* Added: Taxonomy full CRUD (P2)
* Added: Menus read (P3)
* Added: Themes info and list (P3)
* Added: Media sideload upload (P3)
* Added: Site-wide search (P3)
* Enhanced: Posts/pages now include meta, slug, template, featured image
* Enhanced: Site info includes active theme, PHP version, timezone
* Security: Options blocklist, user meta filtering, annotations

= 1.0.2 =
* Initial release — content CRUD, taxonomy list, media list, site info


== 2.1.0 — Elementor abilities ==

This plugin is now both things: the general WordPress abilities surface AND a
first-party Elementor MCP. Same plugin, same Abilities API hooks, same MCP
Adapter endpoint — there is no second server to configure.

22 new abilities under the `elementor` category:

  Discovery  detect, list-pages, list-templates, get-structure,
             find-element, get-element, get-global-settings
  Elements   update-element, batch-update, add-element, remove-element,
             duplicate-element, move-element
  Page/site  update-page-settings, update-global-colors,
             update-global-typography
  Transfer   export-page, import-page
  Assets     upload-svg  (sanitised: strips script, event handlers,
             javascript:/data: URIs, foreignObject, external entities)
  Safety     list-snapshots, restore-snapshot, flush-css

Three correctness guarantees, because these are the usual ways an agent
silently destroys an Elementor page:

  * Slashes  — _elementor_data is stored slash-escaped. Writes go through
    wp_slash( wp_json_encode() ), so escaped quotes in text widgets survive.
  * CSS      — every write invalidates Elementor's compiled per-post CSS.
    Without this an edit lands in the data and the page still looks stale.
  * No flattening — edits walk the tree by element id and deep-merge settings.
    Siblings and unknown keys are preserved; nothing replaces a settings object.

Every write takes an automatic snapshot first (rolling, 8 held per post), so
any change is one call from being undone via elementor-restore-snapshot.

WordPress 7.1 readiness: every new ability sets BOTH the 7.1 unified
`meta.public` flag and the channel-specific `meta.mcp.public`. The channel flag
remains authoritative on 7.1 and is REQUIRED on 6.9/7.0 — setting only the
unified flag hides every ability on WordPress 7.0.

Requires at least is now 6.9 (Abilities API in core). Registration is
hook-based, so on a site without the Abilities API the hooks never fire and
nothing fatals.
