<?php
/**
 * Plugin Name:       LucidIT WordPress Enabler
 * Plugin URI:        https://o-matic.ai
 * Description:       Full WordPress abilities surface for the MCP Adapter, plus a first-party Elementor MCP. Content, users, comments, plugins, options, menus (read and write, incl. Polylang switcher), block-theme template parts and navigation, public-render verification, themes, media, meta, taxonomy CRUD, site-wide search, and Elementor structure, elements, templates, global design tokens and SVG upload.
 * Version:           2.5.1
 * Author:            LucidIT, LLC / O-Matic AI Research Lab
 * Author URI:        https://o-matic.ai
 * License:           GPL-2.0+
 * Text Domain:       lucid-wp-enabler
 * Requires at least: 6.9
 * Tested up to:      7.1
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The plugin version, read from the Version header above so there is exactly
 * one literal to bump (task #1013, W5).
 */
if ( ! defined( 'OMATIC_ENABLER_VERSION' ) ) {
    $omatic_enabler_header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
    define( 'OMATIC_ENABLER_VERSION', (string) $omatic_enabler_header['Version'] );
    unset( $omatic_enabler_header );
}

/**
 * Shared helpers (rolling snapshots) and the parser-based SVG sanitizer.
 */
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/svg-sanitizer.php';

/**
 * Elementor abilities. Registered on the same Abilities API hooks and surfaced
 * by the same MCP Adapter endpoint as the abilities below — one plugin, one
 * server, no second endpoint to configure.
 */
require_once __DIR__ . '/includes/elementor-abilities.php';

/**
 * Menu write, block-theme (site editor) and public-render verification
 * abilities (2.4.0). Same hooks, same endpoint.
 */
require_once __DIR__ . '/includes/site-navigation-abilities.php';

add_action( 'wp_abilities_api_categories_init', 'omatic_register_categories' );
add_action( 'wp_abilities_api_init', 'omatic_register_abilities' );
add_action( 'template_redirect', 'omatic_legacy_site_redirect', 1 );

// ─────────────────────────────────────────────
// CATEGORIES
// ─────────────────────────────────────────────

function omatic_register_categories() {
    wp_register_ability_category( 'content', array(
        'label'       => 'Content',
        'description' => 'Abilities for creating and managing WordPress posts and pages.',
    ) );
    wp_register_ability_category( 'taxonomy', array(
        'label'       => 'Taxonomy',
        'description' => 'Abilities for managing categories, tags, and custom taxonomies.',
    ) );
    wp_register_ability_category( 'media', array(
        'label'       => 'Media',
        'description' => 'Abilities for accessing and uploading to the media library.',
    ) );
    wp_register_ability_category( 'site', array(
        'label'       => 'Site',
        'description' => 'Abilities for reading and writing site configuration.',
    ) );
    wp_register_ability_category( 'users', array(
        'label'       => 'Users',
        'description' => 'Abilities for managing WordPress user accounts and roles.',
    ) );
    wp_register_ability_category( 'comments', array(
        'label'       => 'Comments',
        'description' => 'Abilities for managing comments and discussion.',
    ) );
    wp_register_ability_category( 'plugins', array(
        'label'       => 'Plugins',
        'description' => 'Abilities for auditing installed plugins and their status.',
    ) );
    wp_register_ability_category( 'menus', array(
        'label'       => 'Menus',
        'description' => 'Abilities for managing navigation menus.',
    ) );
    wp_register_ability_category( 'themes', array(
        'label'       => 'Themes',
        'description' => 'Abilities for reading theme information and templates.',
    ) );
    wp_register_ability_category( 'search', array(
        'label'       => 'Search',
        'description' => 'Abilities for site-wide content search across all post types.',
    ) );
}

// ─────────────────────────────────────────────
// ABILITY REGISTRATION
// ─────────────────────────────────────────────

function omatic_register_abilities() {

    // ═════════════════════════════════════════
    // POSTS (from v1 — unchanged)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/posts-list', array(
        'label'               => 'List Posts',
        'description'         => 'Retrieve a paginated list of WordPress posts with optional filters.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'status'         => array( 'type' => 'string', 'default' => 'publish', 'description' => 'Post status filter: publish, draft, pending, private, trash, any.' ),
                'posts_per_page' => array( 'type' => 'integer', 'default' => 10, 'description' => 'Number of posts per page. Use -1 for all (caution on large sites).' ),
                'paged'          => array( 'type' => 'integer', 'default' => 1, 'description' => 'Page number for pagination (WP_Query native name).' ),
                'page'           => array( 'type' => 'integer', 'description' => "Alias of 'paged'. Accepted because callers reliably try this name first; 'paged' wins if both are sent." ),
                'search'         => array( 'type' => 'string', 'description' => 'Search keyword to filter posts.' ),
                'category_name'  => array( 'type' => 'string', 'description' => 'Filter by category slug.' ),
                'tag'            => array( 'type' => 'string', 'description' => 'Filter by tag slug.' ),
                'orderby'        => array( 'type' => 'string', 'default' => 'date', 'description' => 'Sort field: date, title, modified, ID, rand.' ),
                'order'          => array( 'type' => 'string', 'default' => 'DESC', 'description' => 'Sort direction: ASC or DESC.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_posts_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/posts-get', array(
        'label'               => 'Get Post',
        'description'         => 'Get full content, metadata, featured image, and custom fields for a single post by ID.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id' ),
            'properties' => array(
                'post_id'      => array( 'type' => 'integer', 'description' => 'The post ID to retrieve.' ),
                'include_meta' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Include all post meta fields in response.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_posts_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/posts-create', array(
        'label'               => 'Create Post',
        'description'         => 'Create a new WordPress post. Defaults to draft.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'title', 'content' ),
            'properties' => array(
                'title'      => array( 'type' => 'string' ),
                'content'    => array( 'type' => 'string' ),
                'excerpt'    => array( 'type' => 'string' ),
                'status'     => array( 'type' => 'string', 'default' => 'draft' ),
                'slug'       => array( 'type' => 'string', 'description' => 'URL slug. Auto-generated from title if omitted.' ),
                'date'       => array( 'type' => 'string', 'description' => 'Original publication date in a WordPress-compatible datetime format. Use when migrating dated content.' ),
                'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'meta'       => array( 'type' => 'object', 'description' => 'Key-value pairs of post meta to set.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_posts_create',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_publish_posts',
    ) );

    wp_register_ability( 'omatic/posts-update', array(
        'label'               => 'Update Post',
        'description'         => 'Update an existing post by ID. Supports content, status, meta, and taxonomy changes.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id' ),
            'properties' => array(
                'post_id'    => array( 'type' => 'integer' ),
                'title'      => array( 'type' => 'string' ),
                'content'    => array( 'type' => 'string' ),
                'excerpt'    => array( 'type' => 'string' ),
                'status'     => array( 'type' => 'string' ),
                'slug'       => array( 'type' => 'string' ),
                'date'       => array( 'type' => 'string', 'description' => 'Publication date in a WordPress-compatible datetime format.' ),
                'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'meta'       => array( 'type' => 'object', 'description' => 'Key-value pairs of post meta to update.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_posts_update',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_edit_posts',
    ) );

    wp_register_ability( 'omatic/migrate-legacy-o-matic-post', array(
        'label'               => 'Migrate Legacy O-MATIC Post',
        'description'         => 'Copy one published post from the legacy o-matic.io site, retaining its title, body, slug and publication date. Duplicate slugs are returned without creating a second post.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'legacy_post_id' ),
            'properties' => array(
                'legacy_post_id' => array( 'type' => 'integer', 'description' => 'Published WordPress post ID on www.o-matic.io.' ),
                'status'         => array( 'type' => 'string', 'default' => 'publish' ),
                'category_id'    => array( 'type' => 'integer', 'description' => 'Target category ID. Defaults to Uncategorized.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_migrate_legacy_o_matic_post',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_publish_posts',
    ) );

    wp_register_ability( 'omatic/posts-delete', array(
        'label'               => 'Delete Post',
        'description'         => 'Move a post to trash. Set force_delete true to permanently delete.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id' ),
            'properties' => array(
                'post_id'      => array( 'type' => 'integer' ),
                'force_delete' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_posts_delete',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_delete_posts',
    ) );

    // ═════════════════════════════════════════
    // PAGES (from v1 — enhanced with meta + parent)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/pages-list', array(
        'label'               => 'List Pages',
        'description'         => 'Retrieve a list of WordPress pages with optional filters.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'status'         => array( 'type' => 'string', 'default' => 'publish' ),
                'posts_per_page' => array( 'type' => 'integer', 'default' => 50 ),
                'search'         => array( 'type' => 'string' ),
                'parent_id'      => array( 'type' => 'integer', 'description' => 'Filter by parent page ID. Use 0 for top-level pages.' ),
                'orderby'        => array( 'type' => 'string', 'default' => 'menu_order' ),
                'order'          => array( 'type' => 'string', 'default' => 'ASC' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_pages_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/pages-get', array(
        'label'               => 'Get Page',
        'description'         => 'Get full content, metadata, and template info for a single page by ID.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'page_id' ),
            'properties' => array(
                'page_id'      => array( 'type' => 'integer' ),
                'include_meta' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_pages_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/pages-create', array(
        'label'               => 'Create Page',
        'description'         => 'Create a new WordPress page. Defaults to draft.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'title', 'content' ),
            'properties' => array(
                'title'     => array( 'type' => 'string' ),
                'content'   => array( 'type' => 'string' ),
                'status'    => array( 'type' => 'string', 'default' => 'draft' ),
                'parent_id' => array( 'type' => 'integer', 'default' => 0 ),
                'template'  => array( 'type' => 'string', 'description' => 'Page template filename (e.g. elementor_canvas).' ),
                'meta'      => array( 'type' => 'object', 'description' => 'Key-value pairs of page meta to set.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_pages_create',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_publish_pages',
    ) );

    wp_register_ability( 'omatic/pages-update', array(
        'label'               => 'Update Page',
        'description'         => 'Update an existing page by ID. Supports content, status, parent, template, and meta.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'page_id' ),
            'properties' => array(
                'page_id'   => array( 'type' => 'integer' ),
                'title'     => array( 'type' => 'string' ),
                'content'   => array( 'type' => 'string' ),
                'status'    => array( 'type' => 'string' ),
                'parent_id' => array( 'type' => 'integer', 'description' => 'Reparent this page under a different parent.' ),
                'template'  => array( 'type' => 'string' ),
                'meta'      => array( 'type' => 'object' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_pages_update',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_edit_pages',
    ) );

    // ═════════════════════════════════════════
    // TAXONOMY (expanded — full CRUD)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/categories-list', array(
        'label'               => 'List Categories',
        'description'         => 'Get all post categories with IDs, slugs, and counts.',
        'category'            => 'taxonomy',
        'input_schema'        => array( 'type' => 'object', 'properties' => array(
            'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
        ) ),
        'execute_callback'    => 'omatic_cb_categories_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/tags-list', array(
        'label'               => 'List Tags',
        'description'         => 'Get all post tags with IDs, slugs, and counts.',
        'category'            => 'taxonomy',
        'input_schema'        => array( 'type' => 'object', 'properties' => array(
            'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
        ) ),
        'execute_callback'    => 'omatic_cb_tags_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/taxonomy-create-term', array(
        'label'               => 'Create Taxonomy Term',
        'description'         => 'Create a new category, tag, or custom taxonomy term.',
        'category'            => 'taxonomy',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'taxonomy', 'name' ),
            'properties' => array(
                'taxonomy'    => array( 'type' => 'string', 'description' => 'Taxonomy slug: category, post_tag, or custom.' ),
                'name'        => array( 'type' => 'string' ),
                'slug'        => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'parent'      => array( 'type' => 'integer', 'default' => 0, 'description' => 'Parent term ID (hierarchical taxonomies only).' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_taxonomy_create_term',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_manage_categories',
    ) );

    wp_register_ability( 'omatic/taxonomy-update-term', array(
        'label'               => 'Update Taxonomy Term',
        'description'         => 'Update an existing taxonomy term by ID.',
        'category'            => 'taxonomy',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'term_id', 'taxonomy' ),
            'properties' => array(
                'term_id'     => array( 'type' => 'integer' ),
                'taxonomy'    => array( 'type' => 'string' ),
                'name'        => array( 'type' => 'string' ),
                'slug'        => array( 'type' => 'string' ),
                'description' => array( 'type' => 'string' ),
                'parent'      => array( 'type' => 'integer' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_taxonomy_update_term',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_manage_categories',
    ) );

    wp_register_ability( 'omatic/taxonomy-delete-term', array(
        'label'               => 'Delete Taxonomy Term',
        'description'         => 'Delete a taxonomy term by ID.',
        'category'            => 'taxonomy',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'term_id', 'taxonomy' ),
            'properties' => array(
                'term_id'  => array( 'type' => 'integer' ),
                'taxonomy' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_taxonomy_delete_term',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_manage_categories',
    ) );

    // ═════════════════════════════════════════
    // MEDIA (expanded — list + upload)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/media-list', array(
        'label'               => 'List Media',
        'description'         => 'List items in the WordPress media library with optional filters.',
        'category'            => 'media',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'posts_per_page' => array( 'type' => 'integer', 'default' => 20 ),
                'mime_type'      => array( 'type' => 'string', 'description' => 'Filter by MIME type: image, image/png, application/pdf, etc.' ),
                'search'         => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_media_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_upload',
    ) );

    wp_register_ability( 'omatic/media-upload', array(
        'label'               => 'Upload Media from URL',
        'description'         => 'Sideload a file from a URL into the WordPress media library.',
        'category'            => 'media',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'url' ),
            'properties' => array(
                'url'         => array( 'type' => 'string', 'description' => 'Public URL of the file to sideload.' ),
                'filename'    => array( 'type' => 'string', 'description' => 'Override filename. Auto-detected from URL if omitted.' ),
                'title'       => array( 'type' => 'string', 'description' => 'Media library title.' ),
                'alt_text'    => array( 'type' => 'string', 'description' => 'Image alt text for accessibility.' ),
                'description' => array( 'type' => 'string' ),
                'post_id'     => array( 'type' => 'integer', 'default' => 0, 'description' => 'Attach to this post/page ID.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_media_upload',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_upload',
    ) );

    // ═════════════════════════════════════════
    // SITE INFO (from v1 — unchanged)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/site-info', array(
        'label'               => 'Get Site Info',
        'description'         => 'Get site name, description, URL, admin email, WordPress version, and active theme.',
        'category'            => 'site',
        'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback'    => 'omatic_cb_site_info',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    // ═════════════════════════════════════════
    // P1: OPTIONS / SETTINGS (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/options-get', array(
        'label'               => 'Get Option',
        'description'         => 'Read a WordPress option value by key. Supports any option in the wp_options table.',
        'category'            => 'site',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'option_name' ),
            'properties' => array(
                'option_name' => array( 'type' => 'string', 'description' => 'The option key to read (e.g. blogname, siteurl, permalink_structure).' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_options_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_manage_options',
    ) );

    wp_register_ability( 'omatic/options-update', array(
        'label'               => 'Update Option',
        'description'         => 'Write a WordPress option value. Restricted to an allowlist: general, reading, discussion and media settings; omatic_*, elementor_* and ewww_image_optimizer_* options; default_role only to an unprivileged role (e.g. subscriber); users_can_register only to 0. admin_email, active_plugins, siteurl, home, role definitions, keys and salts are refused.',
        'category'            => 'site',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'option_name', 'option_value' ),
            'properties' => array(
                'option_name'  => array( 'type' => 'string', 'description' => 'The option key to set.' ),
                'option_value' => array( 'description' => 'The value to store. Strings, numbers, arrays, and objects are accepted.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_options_update',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => false ) ),
        'permission_callback' => 'omatic_perm_manage_options',
    ) );

    wp_register_ability( 'omatic/options-list', array(
        'label'               => 'List Options',
        'description'         => 'Search wp_options by key pattern. Returns matching option names and values. Use % as wildcard.',
        'category'            => 'site',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'search' ),
            'properties' => array(
                'search' => array( 'type' => 'string', 'description' => 'SQL LIKE pattern to match option names (e.g. wpforms_% or recaptcha%).' ),
                'limit'  => array( 'type' => 'integer', 'default' => 50, 'description' => 'Max results to return.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_options_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_manage_options',
    ) );

    wp_register_ability( 'omatic/options-delete', array(
        'label'               => 'Delete Option',
        'description'         => 'Remove a WordPress option by key. Restricted to the same allowlist as omatic/options-update.',
        'category'            => 'site',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'option_name' ),
            'properties' => array(
                'option_name' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_options_delete',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_manage_options',
    ) );

    // ═════════════════════════════════════════
    // P1: USERS (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/users-list', array(
        'label'               => 'List Users',
        'description'         => 'List WordPress users with optional role and search filters.',
        'category'            => 'users',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'role'    => array( 'type' => 'string', 'description' => 'Filter by role: administrator, editor, author, contributor, subscriber.' ),
                'search'  => array( 'type' => 'string', 'description' => 'Search by login, email, display name, or nicename.' ),
                'number'  => array( 'type' => 'integer', 'default' => 50 ),
                'orderby' => array( 'type' => 'string', 'default' => 'display_name' ),
                'order'   => array( 'type' => 'string', 'default' => 'ASC' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_users_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_list_users',
    ) );

    wp_register_ability( 'omatic/users-get', array(
        'label'               => 'Get User',
        'description'         => 'Get detailed information for a single user by ID.',
        'category'            => 'users',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'user_id' ),
            'properties' => array(
                'user_id'      => array( 'type' => 'integer' ),
                'include_meta' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Include user meta fields.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_users_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_list_users',
    ) );

    wp_register_ability( 'omatic/users-create', array(
        'label'               => 'Create User',
        'description'         => 'Create a new WordPress user account.',
        'category'            => 'users',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'username', 'email' ),
            'properties' => array(
                'username'     => array( 'type' => 'string' ),
                'email'        => array( 'type' => 'string' ),
                'password'     => array( 'type' => 'string', 'description' => 'If omitted, a random password is generated.' ),
                'first_name'   => array( 'type' => 'string' ),
                'last_name'    => array( 'type' => 'string' ),
                'display_name' => array( 'type' => 'string' ),
                'role'         => array( 'type' => 'string', 'default' => 'subscriber' ),
                'send_notification' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Send the new user notification email.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_users_create',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_create_users',
    ) );

    wp_register_ability( 'omatic/users-update', array(
        'label'               => 'Update User',
        'description'         => 'Update an existing user profile or role.',
        'category'            => 'users',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'user_id' ),
            'properties' => array(
                'user_id'      => array( 'type' => 'integer' ),
                'email'        => array( 'type' => 'string' ),
                'first_name'   => array( 'type' => 'string' ),
                'last_name'    => array( 'type' => 'string' ),
                'display_name' => array( 'type' => 'string' ),
                'role'         => array( 'type' => 'string' ),
                'meta'         => array( 'type' => 'object', 'description' => 'Key-value pairs of user meta to update.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_users_update',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_edit_users',
    ) );

    wp_register_ability( 'omatic/users-delete', array(
        'label'               => 'Delete User',
        'description'         => 'Delete a WordPress user. Optionally reassign their content to another user.',
        'category'            => 'users',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'user_id' ),
            'properties' => array(
                'user_id'     => array( 'type' => 'integer' ),
                'reassign_to' => array( 'type' => 'integer', 'description' => 'Reassign content to this user ID. Required to prevent orphaned content.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_users_delete',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_delete_users',
    ) );

    // ═════════════════════════════════════════
    // P1: PLUGINS (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/plugins-list', array(
        'label'               => 'List Plugins',
        'description'         => 'List all installed plugins with activation status, version, and update availability.',
        'category'            => 'plugins',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'status' => array( 'type' => 'string', 'description' => 'Filter: active, inactive, all.', 'default' => 'all' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_plugins_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_manage_plugins',
    ) );

    wp_register_ability( 'omatic/plugins-activate', array(
        'label'               => 'Activate Plugin',
        'description'         => 'Activate an installed plugin by its file path (e.g. akismet/akismet.php).',
        'category'            => 'plugins',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'plugin' ),
            'properties' => array(
                'plugin' => array( 'type' => 'string', 'description' => 'Plugin file path relative to wp-content/plugins/.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_plugins_activate',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_manage_plugins',
    ) );

    wp_register_ability( 'omatic/plugins-deactivate', array(
        'label'               => 'Deactivate Plugin',
        'description'         => 'Deactivate an active plugin by its file path.',
        'category'            => 'plugins',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'plugin' ),
            'properties' => array(
                'plugin' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_plugins_deactivate',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_manage_plugins',
    ) );

    // ═════════════════════════════════════════
    // P2: COMMENTS (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/comments-list', array(
        'label'               => 'List Comments',
        'description'         => 'List comments with optional status, post, and author filters.',
        'category'            => 'comments',
        'input_schema'        => array(
            'type'       => 'object',
            'properties' => array(
                'status'  => array( 'type' => 'string', 'default' => 'all', 'description' => 'Filter: approve, hold, spam, trash, all.' ),
                'post_id' => array( 'type' => 'integer', 'description' => 'Filter by post/page ID.' ),
                'search'  => array( 'type' => 'string' ),
                'number'  => array( 'type' => 'integer', 'default' => 50 ),
                'orderby' => array( 'type' => 'string', 'default' => 'comment_date' ),
                'order'   => array( 'type' => 'string', 'default' => 'DESC' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_comments_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_moderate_comments',
    ) );

    wp_register_ability( 'omatic/comments-get', array(
        'label'               => 'Get Comment',
        'description'         => 'Get a single comment by ID with full content and metadata.',
        'category'            => 'comments',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'comment_id' ),
            'properties' => array(
                'comment_id' => array( 'type' => 'integer' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_comments_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_moderate_comments',
    ) );

    wp_register_ability( 'omatic/comments-update-status', array(
        'label'               => 'Update Comment Status',
        'description'         => 'Approve, hold, spam, or trash a comment.',
        'category'            => 'comments',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'comment_id', 'status' ),
            'properties' => array(
                'comment_id' => array( 'type' => 'integer' ),
                'status'     => array( 'type' => 'string', 'description' => 'New status: approve (1), hold (0), spam, trash.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_comments_update_status',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_moderate_comments',
    ) );

    wp_register_ability( 'omatic/comments-delete', array(
        'label'               => 'Delete Comment',
        'description'         => 'Permanently delete a comment.',
        'category'            => 'comments',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'comment_id' ),
            'properties' => array(
                'comment_id'   => array( 'type' => 'integer' ),
                'force_delete' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_comments_delete',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_moderate_comments',
    ) );

    wp_register_ability( 'omatic/comments-counts', array(
        'label'               => 'Comment Counts',
        'description'         => 'Get comment counts by status (approved, pending, spam, trash, total).',
        'category'            => 'comments',
        'input_schema'        => array( 'type' => 'object', 'properties' => array(
            'post_id' => array( 'type' => 'integer', 'description' => 'Scope counts to a specific post. Omit for site-wide.' ),
        ) ),
        'execute_callback'    => 'omatic_cb_comments_counts',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_moderate_comments',
    ) );

    // ═════════════════════════════════════════
    // P2: POST META (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/meta-get', array(
        'label'               => 'Get Post Meta',
        'description'         => 'Read one or all meta values for a post/page. Returns all meta if key is omitted.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id' ),
            'properties' => array(
                'post_id'  => array( 'type' => 'integer' ),
                'meta_key' => array( 'type' => 'string', 'description' => 'Specific meta key. Omit to get all meta.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_meta_get',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_edit_posts',
    ) );

    wp_register_ability( 'omatic/meta-update', array(
        'label'               => 'Update Post Meta',
        'description'         => 'Set a meta value on a post/page. Creates the key if it does not exist.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id', 'meta_key', 'meta_value' ),
            'properties' => array(
                'post_id'    => array( 'type' => 'integer' ),
                'meta_key'   => array( 'type' => 'string' ),
                'meta_value' => array( 'description' => 'Value to store. Strings, numbers, arrays, and objects accepted.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_meta_update',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_edit_posts',
    ) );

    wp_register_ability( 'omatic/meta-delete', array(
        'label'               => 'Delete Post Meta',
        'description'         => 'Remove a meta key from a post/page.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id', 'meta_key' ),
            'properties' => array(
                'post_id'  => array( 'type' => 'integer' ),
                'meta_key' => array( 'type' => 'string' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_meta_delete',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'destructive' => true ) ),
        'permission_callback' => 'omatic_perm_edit_posts',
    ) );

    wp_register_ability( 'omatic/set-featured-image', array(
        'label'               => 'Set Featured Image',
        'description'         => 'Set the featured image (post thumbnail) for a post or page by attachment ID.',
        'category'            => 'content',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'post_id', 'attachment_id' ),
            'properties' => array(
                'post_id'       => array( 'type' => 'integer' ),
                'attachment_id' => array( 'type' => 'integer', 'description' => 'Media library attachment ID.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_set_featured_image',
        'meta'                => array( 'mcp' => array( 'public' => true ) ),
        'permission_callback' => 'omatic_perm_edit_posts',
    ) );

    // ═════════════════════════════════════════
    // P3: MENUS (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/menus-list', array(
        'label'               => 'List Menus',
        'description'         => 'List all registered navigation menus and their assigned locations.',
        'category'            => 'menus',
        'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback'    => 'omatic_cb_menus_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_edit_theme_options',
    ) );

    wp_register_ability( 'omatic/menus-get-items', array(
        'label'               => 'Get Menu Items',
        'description'         => 'Get all items in a navigation menu by menu ID or slug.',
        'category'            => 'menus',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'menu' ),
            'properties' => array(
                'menu' => array( 'type' => 'string', 'description' => 'Menu ID, slug, or name.' ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_menus_get_items',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_edit_theme_options',
    ) );

    // ═════════════════════════════════════════
    // P3: THEMES (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/themes-info', array(
        'label'               => 'Get Theme Info',
        'description'         => 'Get active theme details: name, version, template, parent theme, and available page templates.',
        'category'            => 'themes',
        'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback'    => 'omatic_cb_themes_info',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    wp_register_ability( 'omatic/themes-list', array(
        'label'               => 'List Installed Themes',
        'description'         => 'List all installed themes with version, status, and parent info.',
        'category'            => 'themes',
        'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
        'execute_callback'    => 'omatic_cb_themes_list',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );

    // ═════════════════════════════════════════
    // P3: SEARCH (NEW)
    // ═════════════════════════════════════════

    wp_register_ability( 'omatic/search', array(
        'label'               => 'Site-Wide Search',
        'description'         => 'Search across all post types (posts, pages, attachments, custom post types) by keyword.',
        'category'            => 'search',
        'input_schema'        => array(
            'type'       => 'object',
            'required'   => array( 'query' ),
            'properties' => array(
                'query'          => array( 'type' => 'string', 'description' => 'Search keyword(s).' ),
                'post_type'      => array( 'type' => 'string', 'default' => 'any', 'description' => 'Filter by post type: post, page, attachment, any, or custom type slug.' ),
                'posts_per_page' => array( 'type' => 'integer', 'default' => 20 ),
                'paged'          => array( 'type' => 'integer', 'default' => 1, 'description' => 'Page number for pagination (WP_Query native name).' ),
                'page'           => array( 'type' => 'integer', 'description' => "Alias of 'paged'. Accepted because callers reliably try this name first; 'paged' wins if both are sent." ),
            ),
        ),
        'execute_callback'    => 'omatic_cb_search',
        'meta'                => array( 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
        'permission_callback' => 'omatic_perm_read',
    ) );
}

// ─────────────────────────────────────────────
// PERMISSIONS
// ─────────────────────────────────────────────

function omatic_perm_read()               { return current_user_can( 'read' ); }
function omatic_perm_publish_posts()      { return current_user_can( 'publish_posts' ); }
function omatic_perm_edit_posts()         { return current_user_can( 'edit_posts' ); }
function omatic_perm_delete_posts()       { return current_user_can( 'delete_posts' ); }
function omatic_perm_publish_pages()      { return current_user_can( 'publish_pages' ); }
function omatic_perm_edit_pages()         { return current_user_can( 'edit_pages' ); }
function omatic_perm_upload()             { return current_user_can( 'upload_files' ); }
function omatic_perm_manage_options()     { return current_user_can( 'manage_options' ); }
function omatic_perm_list_users()         { return current_user_can( 'list_users' ); }
function omatic_perm_create_users()       { return current_user_can( 'create_users' ); }
function omatic_perm_edit_users()         { return current_user_can( 'edit_users' ); }
function omatic_perm_delete_users()       { return current_user_can( 'delete_users' ); }
function omatic_perm_manage_plugins()     { return current_user_can( 'activate_plugins' ); }
function omatic_perm_moderate_comments()  { return current_user_can( 'moderate_comments' ); }
function omatic_perm_manage_categories()  { return current_user_can( 'manage_categories' ); }
function omatic_perm_edit_theme_options() { return current_user_can( 'edit_theme_options' ); }

// ─────────────────────────────────────────────
// CALLBACKS — POSTS (v1 enhanced)
// ─────────────────────────────────────────────

function omatic_cb_posts_list( $input ) {
    // 'paged' is the WP_Query-native key and wins when a caller sends it
    // explicitly. 'page' is accepted as an alias: it is the name every REST-
    // style caller reaches for first, and silently ignoring it here is what
    // previously made every page request fall back to the paged=1 default
    // while still reporting the true total/pages — a truncated result set
    // that looked complete. See task T-S7-002.
    $requested_page = 1;
    if ( isset( $input['paged'] ) && '' !== $input['paged'] ) {
        $requested_page = (int) $input['paged'];
    } elseif ( isset( $input['page'] ) && '' !== $input['page'] ) {
        $requested_page = (int) $input['page'];
    }
    $args = array(
        'post_type'      => 'post',
        'post_status'    => isset( $input['status'] ) ? $input['status'] : 'publish',
        'posts_per_page' => isset( $input['posts_per_page'] ) ? (int) $input['posts_per_page'] : 10,
        'paged'          => max( 1, $requested_page ),
        'orderby'        => isset( $input['orderby'] ) ? $input['orderby'] : 'date',
        'order'          => isset( $input['order'] ) ? $input['order'] : 'DESC',
    );
    if ( ! empty( $input['search'] ) )        $args['s']             = sanitize_text_field( $input['search'] );
    if ( ! empty( $input['category_name'] ) ) $args['category_name'] = sanitize_text_field( $input['category_name'] );
    if ( ! empty( $input['tag'] ) )           $args['tag']           = sanitize_text_field( $input['tag'] );
    $query = new WP_Query( $args );
    $posts = array();
    foreach ( $query->posts as $p ) {
        $thumb_id  = get_post_thumbnail_id( $p->ID );
        $posts[]   = array(
            'ID'              => $p->ID,
            'title'           => $p->post_title,
            'status'          => $p->post_status,
            'slug'            => $p->post_name,
            'date'            => $p->post_date,
            'modified'        => $p->post_modified,
            'permalink'       => get_permalink( $p->ID ),
            'featured_image'  => $thumb_id ? wp_get_attachment_url( $thumb_id ) : null,
        );
    }
    return array( 'posts' => $posts, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
}

function omatic_cb_posts_get( $input ) {
    $post = get_post( absint( $input['post_id'] ) );
    if ( ! $post || 'post' !== $post->post_type ) {
        return array( 'error' => 'Post not found.' );
    }
    $thumb_id = get_post_thumbnail_id( $post->ID );
    $result   = array(
        'ID'             => $post->ID,
        'title'          => $post->post_title,
        'content'        => $post->post_content,
        'excerpt'        => $post->post_excerpt,
        'status'         => $post->post_status,
        'slug'           => $post->post_name,
        'date'           => $post->post_date,
        'modified'       => $post->post_modified,
        'permalink'      => get_permalink( $post->ID ),
        'categories'     => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
        'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
        'featured_image' => $thumb_id ? wp_get_attachment_url( $thumb_id ) : null,
        'template'       => get_page_template_slug( $post->ID ),
    );
    if ( ! empty( $input['include_meta'] ) ) {
        $result['meta'] = omatic_get_filtered_meta( $post->ID );
    }
    return $result;
}

function omatic_cb_posts_create( $input ) {
    $data = array(
        'post_title'   => sanitize_text_field( $input['title'] ),
        'post_content' => wp_kses_post( $input['content'] ),
        'post_status'  => sanitize_text_field( isset( $input['status'] ) ? $input['status'] : 'draft' ),
        'post_author'  => get_current_user_id(),
        'post_type'    => 'post',
    );
    if ( ! empty( $input['excerpt'] ) )    $data['post_excerpt']  = sanitize_text_field( $input['excerpt'] );
    if ( ! empty( $input['slug'] ) )       $data['post_name']     = sanitize_title( $input['slug'] );
    if ( ! empty( $input['date'] ) )       $data['post_date']     = omatic_sanitize_post_date( $input['date'] );
    if ( ! empty( $input['categories'] ) ) $data['post_category'] = array_map( 'absint', $input['categories'] );
    if ( ! empty( $input['tags'] ) )       $data['tags_input']    = array_map( 'sanitize_text_field', $input['tags'] );
    if ( ! empty( $input['meta'] ) )       $data['meta_input']    = omatic_sanitize_meta_input( $input['meta'] );
    $id = wp_insert_post( $data, true );
    if ( is_wp_error( $id ) ) {
        return array( 'error' => $id->get_error_message() );
    }
    return array( 'success' => true, 'post_id' => $id, 'permalink' => get_permalink( $id ), 'edit_link' => get_edit_post_link( $id, 'raw' ) );
}

/**
 * Import one public legacy O-MATIC article. The source is intentionally fixed
 * to the retiring first-party site; callers cannot use this as a general URL
 * fetcher or an arbitrary-content publishing proxy.
 */
function omatic_cb_migrate_legacy_o_matic_post( $input ) {
    $legacy_id = absint( $input['legacy_post_id'] );
    if ( ! $legacy_id ) return array( 'error' => 'A valid legacy post ID is required.' );

    $response = wp_remote_get(
        'https://www.o-matic.io/wp-json/wp/v2/posts/' . $legacy_id . '?context=view',
        array( 'timeout' => 20, 'redirection' => 2, 'user-agent' => 'O-Matic migration/1.0' )
    );
    if ( is_wp_error( $response ) ) return array( 'error' => 'Legacy source could not be read: ' . $response->get_error_message() );
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return array( 'error' => 'Legacy source returned HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '.' );

    $source = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $source ) || empty( $source['slug'] ) || empty( $source['title']['rendered'] ) ) return array( 'error' => 'Legacy source response was incomplete.' );

    $slug     = sanitize_title( $source['slug'] );
    $existing = get_page_by_path( $slug, OBJECT, 'post' );
    if ( $existing ) {
        return array( 'success' => true, 'created' => false, 'post_id' => $existing->ID, 'permalink' => get_permalink( $existing->ID ), 'message' => 'A post with this legacy slug already exists.' );
    }

    $date    = isset( $source['date'] ) ? omatic_sanitize_post_date( $source['date'] ) : '';
    $content = isset( $source['content']['rendered'] ) ? $source['content']['rendered'] : '';
    $note    = '<hr><p><strong>Update from O-MATIC</strong> — This Field Note was first published on ' . esc_html( $date ? $date : 'the legacy O-MATIC site' ) . '. It is preserved as part of our research record; implementation details and product language may have changed. See <a href="/o-matic-server/">O-MATIC Server</a> for the current foundation.</p>';

    $data = array(
        'post_title'   => wp_strip_all_tags( $source['title']['rendered'] ),
        'post_content' => wp_kses_post( $content . $note ),
        'post_excerpt' => isset( $source['excerpt']['rendered'] ) ? wp_strip_all_tags( $source['excerpt']['rendered'] ) : '',
        'post_status'  => sanitize_text_field( isset( $input['status'] ) ? $input['status'] : 'publish' ),
        'post_name'    => $slug,
        'post_author'  => get_current_user_id(),
        'post_type'    => 'post',
        'meta_input'   => array( '_omatic_legacy_post_id' => $legacy_id, '_omatic_legacy_published_at' => $date ),
    );
    if ( $date ) $data['post_date'] = $date;
    if ( ! empty( $input['category_id'] ) ) $data['post_category'] = array( absint( $input['category_id'] ) );

    $target_id = wp_insert_post( $data, true );
    if ( is_wp_error( $target_id ) ) return array( 'error' => $target_id->get_error_message() );

    return array( 'success' => true, 'created' => true, 'post_id' => $target_id, 'permalink' => get_permalink( $target_id ), 'legacy_post_id' => $legacy_id, 'legacy_date' => $date );
}

function omatic_cb_posts_update( $input ) {
    $id   = absint( $input['post_id'] );
    $post = get_post( $id );
    if ( ! $post ) {
        return array( 'error' => 'Post not found.' );
    }
    $data = array( 'ID' => $id );
    if ( isset( $input['title'] ) )        $data['post_title']    = sanitize_text_field( $input['title'] );
    if ( isset( $input['content'] ) )      $data['post_content']  = wp_kses_post( $input['content'] );
    if ( isset( $input['excerpt'] ) )      $data['post_excerpt']  = sanitize_text_field( $input['excerpt'] );
    if ( isset( $input['status'] ) )       $data['post_status']   = sanitize_text_field( $input['status'] );
    if ( isset( $input['slug'] ) )         $data['post_name']     = sanitize_title( $input['slug'] );
    if ( ! empty( $input['date'] ) )       $data['post_date']     = omatic_sanitize_post_date( $input['date'] );
    if ( ! empty( $input['categories'] ) ) $data['post_category'] = array_map( 'absint', $input['categories'] );
    if ( ! empty( $input['tags'] ) )       $data['tags_input']    = array_map( 'sanitize_text_field', $input['tags'] );
    if ( ! empty( $input['meta'] ) )       $data['meta_input']    = omatic_sanitize_meta_input( $input['meta'] );
    $result = wp_update_post( $data, true );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    return array( 'success' => true, 'post_id' => $id, 'permalink' => get_permalink( $id ) );
}

function omatic_cb_posts_delete( $input ) {
    $id = absint( $input['post_id'] );
    if ( ! get_post( $id ) ) {
        return array( 'error' => 'Post not found.' );
    }
    $force  = isset( $input['force_delete'] ) ? (bool) $input['force_delete'] : false;
    $result = wp_delete_post( $id, $force );
    if ( ! $result ) {
        return array( 'error' => 'Delete failed.' );
    }
    return array( 'success' => true, 'post_id' => $id );
}

// ─────────────────────────────────────────────
// CALLBACKS — PAGES (v1 enhanced)
// ─────────────────────────────────────────────

function omatic_cb_pages_list( $input ) {
    $args = array(
        'post_type'      => 'page',
        'post_status'    => isset( $input['status'] ) ? $input['status'] : 'publish',
        'posts_per_page' => isset( $input['posts_per_page'] ) ? (int) $input['posts_per_page'] : 50,
        'orderby'        => isset( $input['orderby'] ) ? $input['orderby'] : 'menu_order',
        'order'          => isset( $input['order'] ) ? $input['order'] : 'ASC',
    );
    if ( ! empty( $input['search'] ) )    $args['s']           = sanitize_text_field( $input['search'] );
    if ( isset( $input['parent_id'] ) )   $args['post_parent'] = absint( $input['parent_id'] );
    $query = new WP_Query( $args );
    $pages = array();
    foreach ( $query->posts as $p ) {
        $pages[] = array(
            'ID'        => $p->ID,
            'title'     => $p->post_title,
            'status'    => $p->post_status,
            'slug'      => $p->post_name,
            'permalink' => get_permalink( $p->ID ),
            'parent'    => $p->post_parent,
            'template'  => get_page_template_slug( $p->ID ),
        );
    }
    return array( 'pages' => $pages, 'total' => (int) $query->found_posts );
}

function omatic_cb_pages_get( $input ) {
    $post = get_post( absint( $input['page_id'] ) );
    if ( ! $post || 'page' !== $post->post_type ) {
        return array( 'error' => 'Page not found.' );
    }
    $result = array(
        'ID'        => $post->ID,
        'title'     => $post->post_title,
        'content'   => $post->post_content,
        'status'    => $post->post_status,
        'slug'      => $post->post_name,
        'permalink' => get_permalink( $post->ID ),
        'parent'    => $post->post_parent,
        'template'  => get_page_template_slug( $post->ID ),
    );
    if ( ! empty( $input['include_meta'] ) ) {
        $result['meta'] = omatic_get_filtered_meta( $post->ID );
    }
    return $result;
}

function omatic_cb_pages_create( $input ) {
    $data = array(
        'post_title'   => sanitize_text_field( $input['title'] ),
        'post_content' => wp_kses_post( $input['content'] ),
        'post_status'  => sanitize_text_field( isset( $input['status'] ) ? $input['status'] : 'draft' ),
        'post_type'    => 'page',
        'post_parent'  => absint( isset( $input['parent_id'] ) ? $input['parent_id'] : 0 ),
        'post_author'  => get_current_user_id(),
    );
    if ( ! empty( $input['template'] ) ) $data['page_template'] = sanitize_text_field( $input['template'] );
    if ( ! empty( $input['meta'] ) )     $data['meta_input']    = omatic_sanitize_meta_input( $input['meta'] );
    $id = wp_insert_post( $data, true );
    if ( is_wp_error( $id ) ) {
        return array( 'error' => $id->get_error_message() );
    }
    return array( 'success' => true, 'page_id' => $id, 'permalink' => get_permalink( $id ), 'edit_link' => get_edit_post_link( $id, 'raw' ) );
}

function omatic_cb_pages_update( $input ) {
    $id   = absint( $input['page_id'] );
    $post = get_post( $id );
    if ( ! $post || 'page' !== $post->post_type ) {
        return array( 'error' => 'Page not found.' );
    }
    $data = array( 'ID' => $id );
    if ( isset( $input['title'] ) )     $data['post_title']    = sanitize_text_field( $input['title'] );
    if ( isset( $input['content'] ) )   $data['post_content']  = wp_kses_post( $input['content'] );
    if ( isset( $input['status'] ) )    $data['post_status']   = sanitize_text_field( $input['status'] );
    if ( isset( $input['parent_id'] ) ) $data['post_parent']   = absint( $input['parent_id'] );
    if ( isset( $input['template'] ) )  $data['page_template'] = sanitize_text_field( $input['template'] );
    if ( ! empty( $input['meta'] ) )    $data['meta_input']    = omatic_sanitize_meta_input( $input['meta'] );
    $result = wp_update_post( $data, true );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    return array( 'success' => true, 'page_id' => $id, 'permalink' => get_permalink( $id ) );
}

// ─────────────────────────────────────────────
// CALLBACKS — TAXONOMY (expanded)
// ─────────────────────────────────────────────

function omatic_cb_categories_list( $input ) {
    $cats = get_categories( array( 'hide_empty' => ! empty( $input['hide_empty'] ) ) );
    $out  = array();
    foreach ( $cats as $c ) {
        $out[] = array( 'ID' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent );
    }
    return array( 'categories' => $out );
}

function omatic_cb_tags_list( $input ) {
    $tags = get_tags( array( 'hide_empty' => ! empty( $input['hide_empty'] ) ) );
    $out  = array();
    foreach ( $tags as $t ) {
        $out[] = array( 'ID' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
    }
    return array( 'tags' => $out );
}

function omatic_cb_taxonomy_create_term( $input ) {
    $taxonomy = sanitize_key( $input['taxonomy'] );
    if ( ! taxonomy_exists( $taxonomy ) ) {
        return array( 'error' => "Taxonomy '$taxonomy' does not exist." );
    }
    $args = array();
    if ( ! empty( $input['slug'] ) )        $args['slug']        = sanitize_title( $input['slug'] );
    if ( ! empty( $input['description'] ) ) $args['description'] = sanitize_text_field( $input['description'] );
    if ( isset( $input['parent'] ) )        $args['parent']      = absint( $input['parent'] );
    $result = wp_insert_term( sanitize_text_field( $input['name'] ), $taxonomy, $args );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    return array( 'success' => true, 'term_id' => $result['term_id'], 'term_taxonomy_id' => $result['term_taxonomy_id'] );
}

function omatic_cb_taxonomy_update_term( $input ) {
    $taxonomy = sanitize_key( $input['taxonomy'] );
    $term_id  = absint( $input['term_id'] );
    $args     = array();
    if ( isset( $input['name'] ) )        $args['name']        = sanitize_text_field( $input['name'] );
    if ( isset( $input['slug'] ) )        $args['slug']        = sanitize_title( $input['slug'] );
    if ( isset( $input['description'] ) ) $args['description'] = sanitize_text_field( $input['description'] );
    if ( isset( $input['parent'] ) )      $args['parent']      = absint( $input['parent'] );
    $result = wp_update_term( $term_id, $taxonomy, $args );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    return array( 'success' => true, 'term_id' => $result['term_id'] );
}

function omatic_cb_taxonomy_delete_term( $input ) {
    $result = wp_delete_term( absint( $input['term_id'] ), sanitize_key( $input['taxonomy'] ) );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    if ( false === $result ) {
        return array( 'error' => 'Term does not exist.' );
    }
    return array( 'success' => true );
}

// ─────────────────────────────────────────────
// CALLBACKS — MEDIA (expanded)
// ─────────────────────────────────────────────

function omatic_cb_media_list( $input ) {
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => isset( $input['posts_per_page'] ) ? (int) $input['posts_per_page'] : 20,
    );
    if ( ! empty( $input['mime_type'] ) ) $args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
    if ( ! empty( $input['search'] ) )    $args['s']              = sanitize_text_field( $input['search'] );
    $query = new WP_Query( $args );
    $items = array();
    foreach ( $query->posts as $p ) {
        $items[] = array(
            'ID'        => $p->ID,
            'title'     => $p->post_title,
            'filename'  => basename( get_attached_file( $p->ID ) ),
            'url'       => wp_get_attachment_url( $p->ID ),
            'mime_type' => $p->post_mime_type,
            'date'      => $p->post_date,
            'alt_text'  => get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
        );
    }
    return array( 'media' => $items, 'total' => (int) $query->found_posts );
}

function omatic_cb_media_upload( $input ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $url = esc_url_raw( $input['url'] );
    $tmp = download_url( $url, 30 );
    if ( is_wp_error( $tmp ) ) {
        return array( 'error' => 'Download failed: ' . $tmp->get_error_message() );
    }

    $filename = ! empty( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : basename( wp_parse_url( $url, PHP_URL_PATH ) );
    $file_array = array(
        'name'     => $filename,
        'tmp_name' => $tmp,
    );

    $post_id      = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
    $attachment_id = media_handle_sideload( $file_array, $post_id );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        return array( 'error' => $attachment_id->get_error_message() );
    }

    if ( ! empty( $input['title'] ) )    wp_update_post( array( 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $input['title'] ) ) );
    if ( ! empty( $input['alt_text'] ) ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
    if ( ! empty( $input['description'] ) ) wp_update_post( array( 'ID' => $attachment_id, 'post_content' => sanitize_text_field( $input['description'] ) ) );

    return array(
        'success'       => true,
        'attachment_id' => $attachment_id,
        'url'           => wp_get_attachment_url( $attachment_id ),
        'filename'      => basename( get_attached_file( $attachment_id ) ),
    );
}

// ─────────────────────────────────────────────
// CALLBACKS — SITE INFO (v1 enhanced)
// ─────────────────────────────────────────────

function omatic_cb_site_info( $input ) {
    $theme = wp_get_theme();
    return array(
        'name'         => get_bloginfo( 'name' ),
        'description'  => get_bloginfo( 'description' ),
        'url'          => get_bloginfo( 'url' ),
        'admin_email'  => get_bloginfo( 'admin_email' ),
        'wp_version'   => get_bloginfo( 'version' ),
        'language'     => get_bloginfo( 'language' ),
        'timezone'     => get_option( 'timezone_string', wp_timezone_string() ),
        'active_theme' => array(
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        ),
        'php_version'  => PHP_VERSION,
    );
}

// ─────────────────────────────────────────────
// CALLBACKS — OPTIONS (P1 NEW)
// ─────────────────────────────────────────────

function omatic_cb_options_get( $input ) {
    $name  = sanitize_text_field( $input['option_name'] );
    $value = get_option( $name, '__omatic_not_found__' );
    if ( '__omatic_not_found__' === $value ) {
        return array( 'error' => "Option '$name' not found." );
    }
    return array( 'option_name' => $name, 'option_value' => $value );
}

/**
 * Options the options-update and options-delete abilities may change.
 *
 * An allowlist, not a blocklist (task #1013, W3): the 2.4.0 blocklist missed
 * users_can_register, default_role, admin_email, active_plugins and
 * <prefix>user_roles, any of which turns an options write into a privilege
 * escalation or a site takeover, and options-delete had no guard at all (it
 * could delete siteurl and home). Only site-content settings a site builder
 * legitimately changes are listed. Plugins, users, themes and menus have their
 * own abilities with their own capability checks.
 *
 * Extend deliberately with the `omatic_options_write_allowlist` filter
 * (exact names) and `omatic_options_write_allowed_prefixes` filter.
 *
 * @return array{names: string[], prefixes: string[]}
 */
function omatic_options_write_allowlist() {
    $names = array(
        // General.
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'date_format', 'time_format', 'start_of_week', 'WPLANG', 'site_icon',
        // Reading.
        'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page',
        'posts_per_rss', 'rss_use_excerpt', 'blog_public',
        // Discussion.
        'default_comment_status', 'default_ping_status', 'comment_moderation',
        'comment_registration', 'close_comments_for_old_posts', 'close_comments_days_old',
        'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page',
        'default_comments_page', 'comment_order', 'comment_previously_approved',
        'require_name_email', 'show_avatars', 'avatar_rating', 'avatar_default',
        // Media.
        'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
        'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
        'uploads_use_yearmonth_folders',
        // Privacy page.
        'wp_page_for_privacy_policy',
    );
    // Prefixes: this plugin's own options (omatic_legacy_redirect drives
    // omatic_legacy_site_redirect()), Elementor settings, and plugin settings
    // factory records show being written through this ability
    // (ewww_image_optimizer_lazy_load, lucidIT task #84).
    $prefixes = array( 'omatic_', 'elementor_', 'ewww_image_optimizer_' );

    $names    = (array) apply_filters( 'omatic_options_write_allowlist', $names );
    $prefixes = (array) apply_filters( 'omatic_options_write_allowed_prefixes', $prefixes );
    return array( 'names' => $names, 'prefixes' => $prefixes );
}

/**
 * Whether an option may be written or deleted through the abilities.
 *
 * @param string $name Option name.
 * @return bool
 */
function omatic_option_is_writable( $name, $value = null, $op = 'update' ) {
    $name = (string) $name;
    if ( '' === $name ) {
        return false;
    }

    // Two security settings may be written only in the hardening direction,
    // never deleted. Factory records show default_role being lowered from
    // administrator to subscriber through this ability (lucidIT decision #89),
    // so that use stays; raising it to a privileged role, or opening
    // registration, is the escalation W3 closes.
    if ( 'default_role' === $name ) {
        return 'update' === $op && omatic_role_is_unprivileged( $value );
    }
    if ( 'users_can_register' === $name ) {
        return 'update' === $op && empty( $value );
    }

    $allow = omatic_options_write_allowlist();
    if ( in_array( $name, $allow['names'], true ) ) {
        return true;
    }
    foreach ( $allow['prefixes'] as $prefix ) {
        if ( '' !== (string) $prefix && 0 === strpos( $name, (string) $prefix ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a role name exists and carries none of the capabilities that make
 * a self-registered account an administrator in all but name.
 *
 * @param mixed $role Role slug.
 * @return bool
 */
function omatic_role_is_unprivileged( $role ) {
    if ( ! is_string( $role ) || '' === $role || ! function_exists( 'get_role' ) ) {
        return false;
    }
    $obj = get_role( $role );
    if ( ! $obj ) {
        return false;
    }
    $privileged = array(
        'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users',
        'list_users', 'remove_users', 'install_plugins', 'activate_plugins', 'edit_plugins',
        'update_plugins', 'delete_plugins', 'install_themes', 'edit_themes', 'switch_themes',
        'edit_theme_options', 'update_core', 'edit_files', 'unfiltered_html', 'unfiltered_upload',
        'import', 'export', 'manage_network', 'manage_sites',
    );
    foreach ( $privileged as $cap ) {
        if ( $obj->has_cap( $cap ) ) {
            return false;
        }
    }
    return true;
}

function omatic_cb_options_update( $input ) {
    $name  = sanitize_text_field( $input['option_name'] );
    $value = $input['option_value'];

    if ( ! omatic_option_is_writable( $name, $value, 'update' ) ) {
        return array( 'error' => "Option '$name' is not on the write allowlist (or this value is refused). Allowed: general, reading, discussion and media settings; omatic_*, elementor_* and ewww_image_optimizer_* options; default_role only to an unprivileged role; users_can_register only to 0. Extend with the omatic_options_write_allowlist / omatic_options_write_allowed_prefixes filters." );
    }

    $result = update_option( $name, $value );
    return array( 'success' => true, 'option_name' => $name, 'updated' => $result );
}

function omatic_cb_options_list( $input ) {
    global $wpdb;
    $pattern = sanitize_text_field( $input['search'] );
    $limit   = isset( $input['limit'] ) ? absint( $input['limit'] ) : 50;
    $limit   = min( $limit, 200 );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
            $pattern,
            $limit
        ),
        ARRAY_A
    );

    $options = array();
    foreach ( $rows as $row ) {
        $val = maybe_unserialize( $row['option_value'] );
        // Truncate large serialized values for safety
        if ( is_string( $val ) && strlen( $val ) > 2000 ) {
            $val = substr( $val, 0, 2000 ) . '... [truncated]';
        }
        $options[] = array( 'option_name' => $row['option_name'], 'option_value' => $val );
    }
    return array( 'options' => $options, 'total' => count( $options ) );
}

function omatic_cb_options_delete( $input ) {
    $name   = sanitize_text_field( $input['option_name'] );
    if ( ! omatic_option_is_writable( $name, null, 'delete' ) ) {
        return array( 'error' => "Option '$name' is not on the write allowlist; refusing to delete it." );
    }
    $result = delete_option( $name );
    return array( 'success' => $result, 'option_name' => $name );
}

// ─────────────────────────────────────────────
// CALLBACKS — USERS (P1 NEW)
// ─────────────────────────────────────────────

function omatic_cb_users_list( $input ) {
    $args = array(
        'number'  => isset( $input['number'] ) ? (int) $input['number'] : 50,
        'orderby' => isset( $input['orderby'] ) ? $input['orderby'] : 'display_name',
        'order'   => isset( $input['order'] ) ? $input['order'] : 'ASC',
    );
    if ( ! empty( $input['role'] ) )   $args['role']   = sanitize_text_field( $input['role'] );
    if ( ! empty( $input['search'] ) ) $args['search'] = '*' . sanitize_text_field( $input['search'] ) . '*';

    $user_query = new WP_User_Query( $args );
    $users      = array();
    foreach ( $user_query->get_results() as $u ) {
        $users[] = array(
            'ID'           => $u->ID,
            'username'     => $u->user_login,
            'email'        => $u->user_email,
            'display_name' => $u->display_name,
            'roles'        => $u->roles,
            'registered'   => $u->user_registered,
        );
    }
    return array( 'users' => $users, 'total' => (int) $user_query->get_total() );
}

function omatic_cb_users_get( $input ) {
    $user = get_userdata( absint( $input['user_id'] ) );
    if ( ! $user ) {
        return array( 'error' => 'User not found.' );
    }
    $result = array(
        'ID'           => $user->ID,
        'username'     => $user->user_login,
        'email'        => $user->user_email,
        'first_name'   => $user->first_name,
        'last_name'    => $user->last_name,
        'display_name' => $user->display_name,
        'roles'        => $user->roles,
        'registered'   => $user->user_registered,
        'url'          => $user->user_url,
    );
    if ( ! empty( $input['include_meta'] ) ) {
        $all_meta = get_user_meta( $user->ID );
        $safe_meta = array();
        foreach ( $all_meta as $key => $values ) {
            // Skip internal/sensitive keys
            if ( strpos( $key, 'session_tokens' ) !== false || strpos( $key, 'capabilities' ) !== false || strpos( $key, 'user_level' ) !== false ) {
                continue;
            }
            $safe_meta[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
        }
        $result['meta'] = $safe_meta;
    }
    return $result;
}

function omatic_cb_users_create( $input ) {
    $password = ! empty( $input['password'] ) ? $input['password'] : wp_generate_password( 16, true, true );
    $userdata = array(
        'user_login'   => sanitize_user( $input['username'] ),
        'user_email'   => sanitize_email( $input['email'] ),
        'user_pass'    => $password,
        'role'         => sanitize_text_field( isset( $input['role'] ) ? $input['role'] : 'subscriber' ),
    );
    if ( ! empty( $input['first_name'] ) )   $userdata['first_name']   = sanitize_text_field( $input['first_name'] );
    if ( ! empty( $input['last_name'] ) )    $userdata['last_name']    = sanitize_text_field( $input['last_name'] );
    if ( ! empty( $input['display_name'] ) ) $userdata['display_name'] = sanitize_text_field( $input['display_name'] );

    $user_id = wp_insert_user( $userdata );
    if ( is_wp_error( $user_id ) ) {
        return array( 'error' => $user_id->get_error_message() );
    }

    if ( ! empty( $input['send_notification'] ) ) {
        wp_new_user_notification( $user_id, null, 'user' );
    }

    return array( 'success' => true, 'user_id' => $user_id, 'username' => $userdata['user_login'] );
}

function omatic_cb_users_update( $input ) {
    $user_id = absint( $input['user_id'] );
    if ( ! get_userdata( $user_id ) ) {
        return array( 'error' => 'User not found.' );
    }
    $userdata = array( 'ID' => $user_id );
    if ( isset( $input['email'] ) )        $userdata['user_email']   = sanitize_email( $input['email'] );
    if ( isset( $input['first_name'] ) )   $userdata['first_name']   = sanitize_text_field( $input['first_name'] );
    if ( isset( $input['last_name'] ) )    $userdata['last_name']    = sanitize_text_field( $input['last_name'] );
    if ( isset( $input['display_name'] ) ) $userdata['display_name'] = sanitize_text_field( $input['display_name'] );
    if ( isset( $input['role'] ) )         $userdata['role']         = sanitize_text_field( $input['role'] );

    $result = wp_update_user( $userdata );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }

    if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
        foreach ( $input['meta'] as $key => $value ) {
            update_user_meta( $user_id, sanitize_key( $key ), $value );
        }
    }

    return array( 'success' => true, 'user_id' => $user_id );
}

function omatic_cb_users_delete( $input ) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $user_id   = absint( $input['user_id'] );
    $reassign  = isset( $input['reassign_to'] ) ? absint( $input['reassign_to'] ) : null;
    $result    = wp_delete_user( $user_id, $reassign );
    if ( ! $result ) {
        return array( 'error' => 'Delete failed. User may not exist or cannot be deleted.' );
    }
    return array( 'success' => true, 'user_id' => $user_id );
}

// ─────────────────────────────────────────────
// CALLBACKS — PLUGINS (P1 NEW)
// ─────────────────────────────────────────────

function omatic_cb_plugins_list( $input ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins    = get_plugins();
    $active_plugins = get_option( 'active_plugins', array() );
    $updates        = get_site_transient( 'update_plugins' );
    $status_filter  = isset( $input['status'] ) ? $input['status'] : 'all';

    $plugins = array();
    foreach ( $all_plugins as $file => $data ) {
        $is_active = in_array( $file, $active_plugins, true );
        if ( 'active' === $status_filter && ! $is_active ) continue;
        if ( 'inactive' === $status_filter && $is_active ) continue;

        $has_update = isset( $updates->response[ $file ] );
        $plugins[]  = array(
            'file'           => $file,
            'name'           => $data['Name'],
            'version'        => $data['Version'],
            'active'         => $is_active,
            'update_available' => $has_update,
            'new_version'    => $has_update ? $updates->response[ $file ]->new_version : null,
            'author'         => $data['Author'],
            'description'    => wp_strip_all_tags( $data['Description'] ),
            'requires_wp'    => isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : null,
            'requires_php'   => isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : null,
        );
    }
    return array( 'plugins' => $plugins, 'total' => count( $plugins ), 'active_count' => count( $active_plugins ) );
}

function omatic_cb_plugins_activate( $input ) {
    if ( ! function_exists( 'activate_plugin' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin = sanitize_text_field( $input['plugin'] );
    $result = activate_plugin( $plugin );
    if ( is_wp_error( $result ) ) {
        return array( 'error' => $result->get_error_message() );
    }
    return array( 'success' => true, 'plugin' => $plugin, 'status' => 'active' );
}

function omatic_cb_plugins_deactivate( $input ) {
    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin = sanitize_text_field( $input['plugin'] );
    deactivate_plugins( $plugin );
    return array( 'success' => true, 'plugin' => $plugin, 'status' => 'inactive' );
}

// ─────────────────────────────────────────────
// CALLBACKS — COMMENTS (P2 NEW)
// ─────────────────────────────────────────────

function omatic_cb_comments_list( $input ) {
    $args = array(
        'number'  => isset( $input['number'] ) ? (int) $input['number'] : 50,
        'orderby' => isset( $input['orderby'] ) ? $input['orderby'] : 'comment_date',
        'order'   => isset( $input['order'] ) ? $input['order'] : 'DESC',
    );
    $status = isset( $input['status'] ) ? $input['status'] : 'all';
    if ( 'all' !== $status ) $args['status'] = $status;
    if ( ! empty( $input['post_id'] ) ) $args['post_id'] = absint( $input['post_id'] );
    if ( ! empty( $input['search'] ) )  $args['search']  = sanitize_text_field( $input['search'] );

    $comments = get_comments( $args );
    $out      = array();
    foreach ( $comments as $c ) {
        $out[] = array(
            'ID'           => (int) $c->comment_ID,
            'post_id'      => (int) $c->comment_post_ID,
            'author'       => $c->comment_author,
            'author_email' => $c->comment_author_email,
            'content'      => $c->comment_content,
            'date'         => $c->comment_date,
            'status'       => wp_get_comment_status( $c ),
            'parent'       => (int) $c->comment_parent,
            'type'         => $c->comment_type,
        );
    }
    return array( 'comments' => $out, 'total' => count( $out ) );
}

function omatic_cb_comments_get( $input ) {
    $comment = get_comment( absint( $input['comment_id'] ) );
    if ( ! $comment ) {
        return array( 'error' => 'Comment not found.' );
    }
    return array(
        'ID'           => (int) $comment->comment_ID,
        'post_id'      => (int) $comment->comment_post_ID,
        'author'       => $comment->comment_author,
        'author_email' => $comment->comment_author_email,
        'author_url'   => $comment->comment_author_url,
        'author_ip'    => $comment->comment_author_IP,
        'content'      => $comment->comment_content,
        'date'         => $comment->comment_date,
        'status'       => wp_get_comment_status( $comment ),
        'parent'       => (int) $comment->comment_parent,
        'type'         => $comment->comment_type,
        'user_id'      => (int) $comment->user_id,
    );
}

function omatic_cb_comments_update_status( $input ) {
    $comment_id = absint( $input['comment_id'] );
    $status     = sanitize_text_field( $input['status'] );
    // Map friendly names to WP values
    $status_map = array( 'approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash' );
    $wp_status  = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status;
    $result     = wp_set_comment_status( $comment_id, $wp_status );
    if ( ! $result ) {
        return array( 'error' => 'Status update failed.' );
    }
    return array( 'success' => true, 'comment_id' => $comment_id, 'status' => $status );
}

function omatic_cb_comments_delete( $input ) {
    $comment_id = absint( $input['comment_id'] );
    $force      = isset( $input['force_delete'] ) ? (bool) $input['force_delete'] : false;
    $result     = wp_delete_comment( $comment_id, $force );
    if ( ! $result ) {
        return array( 'error' => 'Delete failed.' );
    }
    return array( 'success' => true, 'comment_id' => $comment_id );
}

function omatic_cb_comments_counts( $input ) {
    $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
    $counts  = wp_count_comments( $post_id );
    return array(
        'approved'       => (int) $counts->approved,
        'awaiting_moderation' => (int) $counts->moderated,
        'spam'           => (int) $counts->spam,
        'trash'          => (int) $counts->trash,
        'total'          => (int) $counts->total_comments,
    );
}

// ─────────────────────────────────────────────
// CALLBACKS — POST META (P2 NEW)
// ─────────────────────────────────────────────

function omatic_cb_meta_get( $input ) {
    $post_id = absint( $input['post_id'] );
    if ( ! get_post( $post_id ) ) {
        return array( 'error' => 'Post not found.' );
    }
    if ( ! empty( $input['meta_key'] ) ) {
        $key   = sanitize_key( $input['meta_key'] );
        $value = get_post_meta( $post_id, $key, true );
        return array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value );
    }
    return array( 'post_id' => $post_id, 'meta' => omatic_get_filtered_meta( $post_id ) );
}

function omatic_cb_meta_update( $input ) {
    $post_id = absint( $input['post_id'] );
    if ( ! get_post( $post_id ) ) {
        return array( 'error' => 'Post not found.' );
    }
    $key    = sanitize_key( $input['meta_key'] );
    $result = update_post_meta( $post_id, $key, $input['meta_value'] );
    return array( 'success' => true, 'post_id' => $post_id, 'meta_key' => $key, 'updated' => (bool) $result );
}

function omatic_cb_meta_delete( $input ) {
    $post_id = absint( $input['post_id'] );
    $key     = sanitize_key( $input['meta_key'] );
    $result  = delete_post_meta( $post_id, $key );
    return array( 'success' => $result, 'post_id' => $post_id, 'meta_key' => $key );
}

function omatic_cb_set_featured_image( $input ) {
    $post_id       = absint( $input['post_id'] );
    $attachment_id = absint( $input['attachment_id'] );
    if ( ! get_post( $post_id ) ) {
        return array( 'error' => 'Post not found.' );
    }
    if ( ! wp_attachment_is_image( $attachment_id ) && ! get_post( $attachment_id ) ) {
        return array( 'error' => 'Attachment not found.' );
    }
    $result = set_post_thumbnail( $post_id, $attachment_id );
    return array( 'success' => (bool) $result, 'post_id' => $post_id, 'attachment_id' => $attachment_id );
}

// ─────────────────────────────────────────────
// CALLBACKS — MENUS (P3 NEW)
// ─────────────────────────────────────────────

function omatic_cb_menus_list( $input ) {
    $menus     = wp_get_nav_menus();
    $locations = get_nav_menu_locations();
    $out       = array();
    foreach ( $menus as $m ) {
        $assigned_locations = array();
        foreach ( $locations as $loc => $menu_id ) {
            if ( $menu_id === $m->term_id ) {
                $assigned_locations[] = $loc;
            }
        }
        $out[] = array(
            'ID'        => $m->term_id,
            'name'      => $m->name,
            'slug'      => $m->slug,
            'count'     => $m->count,
            'locations' => $assigned_locations,
        );
    }
    $registered = get_registered_nav_menus();
    return array( 'menus' => $out, 'registered_locations' => $registered );
}

function omatic_cb_menus_get_items( $input ) {
    $menu  = $input['menu'];
    $items = wp_get_nav_menu_items( $menu );
    if ( false === $items ) {
        return array( 'error' => "Menu '$menu' not found." );
    }
    $out = array();
    foreach ( $items as $item ) {
        $out[] = array(
            'ID'          => $item->ID,
            'title'       => $item->title,
            'url'         => $item->url,
            'type'        => $item->type,
            'object'      => $item->object,
            'object_id'   => (int) $item->object_id,
            'parent'      => (int) $item->menu_item_parent,
            'menu_order'  => (int) $item->menu_order,
            'target'      => $item->target,
            'classes'      => $item->classes,
        );
    }
    return array( 'items' => $out, 'total' => count( $out ) );
}

// ─────────────────────────────────────────────
// CALLBACKS — THEMES (P3 NEW)
// ─────────────────────────────────────────────

function omatic_cb_themes_info( $input ) {
    $theme     = wp_get_theme();
    $templates = wp_get_theme()->get_page_templates();
    return array(
        'name'            => $theme->get( 'Name' ),
        'version'         => $theme->get( 'Version' ),
        'template'        => $theme->get_template(),
        'stylesheet'      => $theme->get_stylesheet(),
        'parent'          => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
        'author'          => $theme->get( 'Author' ),
        'text_domain'     => $theme->get( 'TextDomain' ),
        'page_templates'  => $templates,
        'is_block_theme'  => $theme->is_block_theme(),
    );
}

function omatic_cb_themes_list( $input ) {
    $themes      = wp_get_themes();
    $active_slug = get_stylesheet();
    $out         = array();
    foreach ( $themes as $slug => $theme ) {
        $out[] = array(
            'slug'    => $slug,
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'active'  => ( $slug === $active_slug ),
            'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            'is_block_theme' => $theme->is_block_theme(),
        );
    }
    return array( 'themes' => $out, 'total' => count( $out ) );
}

// ─────────────────────────────────────────────
// CALLBACKS — SEARCH (P3 NEW)
// ─────────────────────────────────────────────

function omatic_cb_search( $input ) {
    $post_type = isset( $input['post_type'] ) ? $input['post_type'] : 'any';
    // 'paged' is the WP_Query-native key and wins when a caller sends it
    // explicitly. 'page' is accepted as an alias: it is the name every REST-
    // style caller reaches for first, and silently ignoring it here is what
    // previously made every page request fall back to the paged=1 default
    // while still reporting the true total/pages — a truncated result set
    // that looked complete. See task T-S7-002.
    $requested_page = 1;
    if ( isset( $input['paged'] ) && '' !== $input['paged'] ) {
        $requested_page = (int) $input['paged'];
    } elseif ( isset( $input['page'] ) && '' !== $input['page'] ) {
        $requested_page = (int) $input['page'];
    }
    $args = array(
        's'              => sanitize_text_field( $input['query'] ),
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => isset( $input['posts_per_page'] ) ? (int) $input['posts_per_page'] : 20,
        'paged'          => max( 1, $requested_page ),
    );
    $query   = new WP_Query( $args );
    $results = array();
    foreach ( $query->posts as $p ) {
        $results[] = array(
            'ID'        => $p->ID,
            'title'     => $p->post_title,
            'type'      => $p->post_type,
            'status'    => $p->post_status,
            'slug'      => $p->post_name,
            'date'      => $p->post_date,
            'permalink' => get_permalink( $p->ID ),
            'excerpt'   => wp_trim_words( $p->post_content, 30, '...' ),
        );
    }
    return array( 'results' => $results, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
}

// ─────────────────────────────────────────────
// UTILITY HELPERS
// ─────────────────────────────────────────────

/**
 * Get filtered post meta — strips internal/Elementor noise for readable output.
 */
function omatic_get_filtered_meta( $post_id ) {
    $all_meta = get_post_meta( $post_id );
    $filtered = array();
    $skip_prefixes = array( '_edit_', '_oembed_', '_pingme', '_encloseme' );
    foreach ( $all_meta as $key => $values ) {
        $skip = false;
        foreach ( $skip_prefixes as $prefix ) {
            if ( strpos( $key, $prefix ) === 0 ) {
                $skip = true;
                break;
            }
        }
        if ( $skip ) continue;
        $filtered[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
    }
    return $filtered;
}

/**
 * Sanitize meta_input for safe storage.
 */
function omatic_sanitize_meta_input( $meta ) {
    if ( ! is_array( $meta ) ) return array();
    $sanitized = array();
    foreach ( $meta as $key => $value ) {
        $sanitized[ sanitize_key( $key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
    }
    return $sanitized;
}

/**
 * Normalize an explicitly supplied historical publication date for migrations.
 * Invalid values are ignored rather than silently changing an article's date.
 */
function omatic_sanitize_post_date( $date ) {
    $timestamp = strtotime( sanitize_text_field( $date ) );
    if ( false === $timestamp ) return '';
    return wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
}

/**
 * Optional legacy-domain 301 redirect.
 *
 * Enable only on the retiring site with the `omatic_legacy_redirect` option:
 * {"enabled":true,"target_base":"https://www.o-matic.ai","routes":{"/old/":"/new/"}}
 * The default preserves each path, individual mappings override it, and all
 * administrative, REST, login, cron and CLI traffic remains on the legacy host.
 */
function omatic_legacy_site_redirect() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) return;

    $config = get_option( 'omatic_legacy_redirect', array() );
    if ( ! is_array( $config ) || empty( $config['enabled'] ) ) return;

    $target_base = isset( $config['target_base'] ) ? untrailingslashit( esc_url_raw( $config['target_base'] ) ) : '';
    $target_host = wp_parse_url( $target_base, PHP_URL_HOST );
    if ( 'www.o-matic.ai' !== $target_host && 'o-matic.ai' !== $target_host ) return;

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
    $path        = wp_parse_url( $request_uri, PHP_URL_PATH );
    $path        = is_string( $path ) && '' !== $path ? $path : '/';
    if ( 0 === strpos( $path, '/wp-admin' ) || 0 === strpos( $path, '/wp-json' ) || '/wp-login.php' === $path ) return;

    $routes      = isset( $config['routes'] ) && is_array( $config['routes'] ) ? $config['routes'] : array();
    $destination = isset( $routes[ $path ] ) ? $routes[ $path ] : $path;
    if ( ! is_string( $destination ) || 0 !== strpos( $destination, '/' ) ) return;

    $query = wp_parse_url( $request_uri, PHP_URL_QUERY );
    $url   = $target_base . $destination . ( $query ? '?' . $query : '' );
    wp_safe_redirect( $url, 301, 'O-Matic legacy migration' );
    exit;
}
