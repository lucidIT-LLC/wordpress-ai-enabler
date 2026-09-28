<?php
/**
 * LucidIT WordPress Enabler — menu write, block-theme (site editor) and
 * public-render verification abilities.
 *
 * Registered on the same Abilities API hooks and surfaced by the same MCP
 * Adapter endpoint as the omatic/* abilities in lucid-wp-enabler.php.
 *
 * Three groups, added in 2.4.0:
 *
 *  1. MENUS (write). Create a classic nav menu, add / update / delete items,
 *     assign a menu to a theme location. Item types follow core's
 *     wp_update_nav_menu_item() contract exactly (post_type / custom), plus a
 *     Polylang "Languages" item stored the way Polylang itself stores it.
 *     Vendor sources:
 *       https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/
 *       https://developer.wordpress.org/reference/functions/wp_create_nav_menu/
 *       https://developer.wordpress.org/reference/functions/wp_get_nav_menu_object/
 *       https://developer.wordpress.org/reference/functions/set_theme_mod/
 *
 *  2. SITE EDITOR (block themes). Read and update wp_template_part records by
 *     theme//slug and wp_navigation posts by ID, and insert Polylang's
 *     language-switcher block into either. Mirrors the write path of core's
 *     WP_REST_Templates_Controller (wp-includes/rest-api/endpoints/
 *     class-wp-rest-templates-controller.php, prepare_item_for_database):
 *     a template part whose source is the theme file is materialised as a
 *     'custom' wp_template_part post (post_name = slug, wp_theme term, origin
 *     meta) before it is changed. Vendor sources:
 *       https://developer.wordpress.org/rest-api/reference/wp_template_parts/
 *       https://developer.wordpress.org/reference/functions/get_block_template/
 *
 *  3. VERIFY. Fetch a public URL on this site server-side (wp_remote_get, no
 *     cookies, no auth) and report which strings appear; and list the
 *     per-language URLs Polylang reports for a post. Vendor sources:
 *       https://developer.wordpress.org/reference/functions/wp_remote_get/
 *       https://polylang.pro/documentation/support/developers/function-reference/
 *
 * Every site-editor write takes an automatic snapshot first (rolling, capped
 * per post), exactly like the Elementor abilities, so any change is one call
 * away from being undone via omatic/site-editor-restore-snapshot.
 *
 * @package LucidIT_WP_Enabler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OMATIC_SE_SNAPSHOT_META = '_omatic_se_snapshots';
const OMATIC_SE_SNAPSHOT_MAX  = 8;

/**
 * Polylang's language-switcher menu item, as Polylang stores it.
 *
 * Polylang registers a "Languages" metabox on Appearance > Menus
 * (https://polylang.pro/documentation/support/getting-started/create-menus/,
 * "you have the possibility to add a language switcher anywhere in a menu").
 * That metabox submits a *custom* menu item whose URL is the sentinel
 * '#pll_switcher' and, on save, Polylang writes the switcher options to the
 * item's post meta '_pll_menu_item'. On the front end Polylang replaces any
 * item carrying that meta with one item per language (or a dropdown parent).
 * Measured in Polylang's own source, tag 3.8.9 (the current Stable tag) and
 * the 3.9 development branch:
 *   src/admin/admin-nav-menu.php     — '#pll_switcher' hidden URL, and
 *                                      update_post_meta( $id, '_pll_menu_item', $options )
 *   src/frontend/frontend-nav-menu.php — get_post_meta( $item->ID, '_pll_menu_item', true )
 *   src/nav-menu.php                 — '#pll_switcher' === $item->url
 *
 * 3.8.9 stores exactly these six keys (0/1). 3.9 adds 'layout' and
 * 'show_labels' but deliberately keeps these legacy keys in the database
 * ("in case a user rollbacks to a version < 3.9" — Abstract_Fields::
 * add_legacy_settings). Writing the 3.8.9 shape is therefore readable by both.
 */
const OMATIC_PLL_SWITCHER_URL = '#pll_switcher';
const OMATIC_PLL_MENU_META    = '_pll_menu_item';

/**
 * Block names Polylang registers for the switcher, read from the block.json
 * files in Polylang's source (src/modules/Blocks/Language_Switcher/Standard/
 * block.json and .../Navigation/block.json). The Navigation variant declares
 * "parent": ["core/navigation"] — it is only valid inside a navigation block.
 * Polylang's documentation names them "Language Switcher" and "Navigation
 * Language Switcher" and notes the Site Editor / navigation-block switcher
 * needs Polylang Pro (https://polylang.pro/documentation/support/guides/
 * the-language-switcher/ and .../multilingual-navigation-site-editor/).
 */
const OMATIC_PLL_BLOCK_STANDARD   = 'polylang/language-switcher';
const OMATIC_PLL_BLOCK_NAVIGATION = 'polylang/navigation-language-switcher';

add_action( 'wp_abilities_api_categories_init', 'omatic_sn_register_categories' );
add_action( 'wp_abilities_api_init', 'omatic_sn_register_abilities' );

// ─────────────────────────────────────────────
// CATEGORIES
// ─────────────────────────────────────────────

function omatic_sn_register_categories() {
	// 'menus' is registered by lucid-wp-enabler.php; the write abilities reuse it.
	wp_register_ability_category(
		'site-editor',
		array(
			'label'       => 'Site Editor',
			'description' => 'Read and edit block-theme template parts and navigation posts, with automatic snapshots.',
		)
	);
	wp_register_ability_category(
		'verify',
		array(
			'label'       => 'Verify',
			'description' => 'Server-side checks of what the public site actually renders, and what Polylang reports.',
		)
	);
}

/**
 * Exposure metadata — identical policy to omatic_el_meta(): both the 7.1
 * unified flag and the channel flag, because setting only the unified flag
 * hides the ability on WordPress 7.0.
 */
function omatic_sn_meta( $destructive = false, $readonly = false ) {
	$meta = array(
		'public' => true,
		'mcp'    => array( 'public' => true ),
	);
	if ( $destructive ) {
		$meta['annotations'] = array( 'destructive' => true );
	} elseif ( $readonly ) {
		$meta['annotations'] = array( 'readonly' => true );
	}
	return $meta;
}

// ─────────────────────────────────────────────
// HELPERS — Polylang presence
// ─────────────────────────────────────────────

function omatic_sn_polylang_active() {
	return function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_translations' );
}

/**
 * Normalise the switcher options a caller passed into Polylang's stored shape.
 * Unknown keys are dropped; every value is coerced to 0/1.
 */
function omatic_sn_pll_switcher_options( $input ) {
	$defaults = array(
		'hide_if_no_translation' => 0,
		'hide_current'           => 0,
		'force_home'             => 0,
		'show_flags'             => 0,
		'show_names'             => 1,
		'dropdown'               => 0,
	);
	$out = $defaults;
	if ( is_array( $input ) ) {
		foreach ( $defaults as $key => $unused ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}
		}
	}
	return $out;
}

// ─────────────────────────────────────────────
// HELPERS — menus
// ─────────────────────────────────────────────

function omatic_sn_resolve_menu( $menu ) {
	if ( is_numeric( $menu ) ) {
		$obj = wp_get_nav_menu_object( absint( $menu ) );
	} else {
		$obj = wp_get_nav_menu_object( sanitize_text_field( (string) $menu ) );
	}
	return $obj instanceof WP_Term ? $obj : null;
}

function omatic_sn_item_belongs_to_menu( $item_id, $menu_id ) {
	if ( ! is_nav_menu_item( $item_id ) ) {
		return false;
	}
	$menus = wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
	return is_array( $menus ) && in_array( (int) $menu_id, array_map( 'intval', $menus ), true );
}

function omatic_sn_describe_item( $item_id ) {
	$item = wp_setup_nav_menu_item( get_post( $item_id ) );
	if ( ! $item ) {
		return null;
	}
	$out = array(
		'ID'         => (int) $item->ID,
		'title'      => $item->title,
		'url'        => $item->url,
		'type'       => $item->type,
		'object'     => $item->object,
		'object_id'  => (int) $item->object_id,
		'parent'     => (int) $item->menu_item_parent,
		'menu_order' => (int) $item->menu_order,
		'status'     => $item->post_status,
		'target'     => $item->target,
		'classes'    => $item->classes,
	);
	$pll = get_post_meta( $item_id, OMATIC_PLL_MENU_META, true );
	if ( is_array( $pll ) ) {
		$out['language_switcher'] = $pll;
	}
	return $out;
}

/**
 * Build the wp_update_nav_menu_item() argument array from ability input.
 * Only keys the caller supplied are set, so an update leaves the rest alone.
 * wp_update_nav_menu_item() expects title/description/attr-title pre-slashed.
 *
 * @return array|WP_Error
 */
function omatic_sn_build_item_args( $input, $existing_id = 0 ) {
	$args = array();
	$type = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : '';

	if ( '' !== $type ) {
		switch ( $type ) {
			case 'page':
			case 'post':
				$object_id = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0;
				$post      = $object_id ? get_post( $object_id ) : null;
				if ( ! $post || $post->post_type !== $type ) {
					return new WP_Error( 'omatic_bad_object', "object_id must be an existing {$type} ID." );
				}
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = $type;
				$args['menu-item-object-id'] = $object_id;
				break;

			case 'custom':
				if ( empty( $input['url'] ) ) {
					return new WP_Error( 'omatic_bad_url', 'url is required for a custom item.' );
				}
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = esc_url_raw( trim( (string) $input['url'] ) );
				break;

			case 'language_switcher':
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = OMATIC_PLL_SWITCHER_URL;
				break;

			default:
				return new WP_Error( 'omatic_bad_type', 'type must be one of page, post, custom, language_switcher.' );
		}
	}

	if ( isset( $input['title'] ) ) {
		$args['menu-item-title'] = wp_slash( sanitize_text_field( (string) $input['title'] ) );
	} elseif ( 'language_switcher' === $type && ! $existing_id ) {
		$args['menu-item-title'] = wp_slash( 'Languages' );
	}
	if ( isset( $input['parent_id'] ) ) {
		$args['menu-item-parent-id'] = absint( $input['parent_id'] );
	}
	if ( isset( $input['position'] ) ) {
		$args['menu-item-position'] = absint( $input['position'] );
	}
	if ( isset( $input['target'] ) ) {
		$args['menu-item-target'] = '_blank' === $input['target'] ? '_blank' : '';
	}
	if ( isset( $input['classes'] ) ) {
		$args['menu-item-classes'] = sanitize_text_field( (string) $input['classes'] );
	}
	if ( isset( $input['attr_title'] ) ) {
		$args['menu-item-attr-title'] = wp_slash( sanitize_text_field( (string) $input['attr_title'] ) );
	}
	if ( isset( $input['description'] ) ) {
		$args['menu-item-description'] = wp_slash( sanitize_textarea_field( (string) $input['description'] ) );
	}
	$args['menu-item-status'] = ( isset( $input['status'] ) && 'draft' === $input['status'] ) ? 'draft' : 'publish';

	return $args;
}

// ─────────────────────────────────────────────
// HELPERS — site editor
// ─────────────────────────────────────────────

/**
 * Resolve a template part by slug (and optional theme) using core's own
 * lookup, which returns the custom (database) version when one exists and
 * falls back to the theme file otherwise.
 *
 * @return WP_Block_Template|null
 */
function omatic_sn_get_template_part( $slug, $theme = '' ) {
	$slug  = sanitize_key( $slug );
	$theme = '' !== $theme ? sanitize_text_field( $theme ) : get_stylesheet();
	if ( '' === $slug || ! function_exists( 'get_block_template' ) ) {
		return null;
	}
	return get_block_template( $theme . '//' . $slug, 'wp_template_part' );
}

function omatic_sn_describe_template( WP_Block_Template $t, $with_content = true ) {
	$out = array(
		'id'             => $t->id,
		'slug'           => $t->slug,
		'theme'          => $t->theme,
		'area'           => isset( $t->area ) ? $t->area : null,
		'title'          => $t->title,
		'description'    => $t->description,
		'source'         => $t->source,
		'origin'         => isset( $t->origin ) ? $t->origin : null,
		'status'         => $t->status,
		'wp_id'          => (int) $t->wp_id,
		'has_theme_file' => (bool) $t->has_theme_file,
	);
	if ( $with_content ) {
		$out['content']         = $t->content;
		$out['navigation_refs'] = omatic_sn_navigation_refs( $t->content );
		$out['block_names']     = omatic_sn_block_names( $t->content );
	}
	return $out;
}

/**
 * IDs of wp_navigation posts referenced by <!-- wp:navigation {"ref":N} --> in a
 * block string. A header that renders its menu from a navigation post is the
 * common block-theme shape; the switcher belongs in that post, not the part.
 */
function omatic_sn_navigation_refs( $content ) {
	$refs = array();
	if ( ! function_exists( 'parse_blocks' ) ) {
		return $refs;
	}
	$walk = function ( $blocks ) use ( &$walk, &$refs ) {
		foreach ( $blocks as $b ) {
			if ( 'core/navigation' === $b['blockName'] && ! empty( $b['attrs']['ref'] ) ) {
				$refs[] = (int) $b['attrs']['ref'];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$walk( $b['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( (string) $content ) );
	return array_values( array_unique( $refs ) );
}

function omatic_sn_block_names( $content ) {
	$names = array();
	if ( ! function_exists( 'parse_blocks' ) ) {
		return $names;
	}
	$walk = function ( $blocks ) use ( &$walk, &$names ) {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$names[] = $b['blockName'];
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$walk( $b['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( (string) $content ) );
	return array_values( array_unique( $names ) );
}

/**
 * Store a post's current post_content in a rolling snapshot slot through the
 * shared omatic_snapshot_push() (includes/common.php), the same mechanism as
 * omatic_el_take_snapshot().
 *
 * @return string|false Snapshot key, or false when the post does not exist.
 */
function omatic_sn_take_snapshot( $post_id, $reason = 'edit' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}
	return omatic_snapshot_push(
		$post_id,
		OMATIC_SE_SNAPSHOT_META,
		OMATIC_SE_SNAPSHOT_MAX,
		$reason,
		array(
			'post_type' => $post->post_type,
			'bytes'     => strlen( (string) $post->post_content ),
			'title'     => $post->post_title,
			'content'   => $post->post_content,
		)
	);
}

/**
 * Persist new content for a template part, materialising a theme-file part
 * as a custom post first exactly as core's REST controller does.
 *
 * @return array Result payload (success or error).
 */
function omatic_sn_write_template_part( WP_Block_Template $template, $content, $title = null, $reason = 'edit' ) {
	$content = (string) $content;

	if ( 'custom' === $template->source && $template->wp_id ) {
		$post_id      = (int) $template->wp_id;
		$snapshot_key = omatic_sn_take_snapshot( $post_id, $reason );
		$changes      = array(
			'ID'           => $post_id,
			'post_content' => $content,
			'post_status'  => 'publish',
		);
		if ( null !== $title ) {
			$changes['post_title'] = sanitize_text_field( $title );
		}
		$result = wp_update_post( wp_slash( $changes ), true );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'wp_update_post failed: ' . $result->get_error_message(), 'snapshot_key' => $snapshot_key );
		}
		$materialised = false;
	} else {
		// Theme-file (or plugin) part: create the custom copy with the ORIGINAL
		// content, snapshot it, then apply the change — so the first snapshot
		// held is the pristine theme file and the invariant "snapshot before
		// every write" holds for the very first edit too.
		$insert = array(
			'post_name'    => $template->slug,
			'post_type'    => 'wp_template_part',
			'post_status'  => 'publish',
			'post_title'   => $template->title,
			'post_excerpt' => $template->description,
			'post_content' => $template->content,
			'meta_input'   => array( 'origin' => $template->source ),
		);
		$post_id = wp_insert_post( wp_slash( $insert ), true );
		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => 'wp_insert_post failed: ' . $post_id->get_error_message() );
		}
		// Terms set explicitly rather than through tax_input so the result does
		// not depend on the assign_terms capability check inside wp_insert_post().
		wp_set_object_terms( $post_id, $template->theme, 'wp_theme' );
		$area = ! empty( $template->area ) ? $template->area : WP_TEMPLATE_PART_AREA_UNCATEGORIZED;
		if ( function_exists( '_filter_block_template_part_area' ) ) {
			$area = _filter_block_template_part_area( $area );
		}
		wp_set_object_terms( $post_id, $area, 'wp_template_part_area' );

		$snapshot_key = omatic_sn_take_snapshot( $post_id, 'theme-file-original' );

		$changes = array( 'ID' => $post_id, 'post_content' => $content );
		if ( null !== $title ) {
			$changes['post_title'] = sanitize_text_field( $title );
		}
		$result = wp_update_post( wp_slash( $changes ), true );
		if ( is_wp_error( $result ) ) {
			return array( 'error' => 'wp_update_post failed after materialising: ' . $result->get_error_message(), 'wp_id' => $post_id, 'snapshot_key' => $snapshot_key );
		}
		$materialised = true;
	}

	$fresh = get_block_template( $template->theme . '//' . $template->slug, 'wp_template_part' );
	return array(
		'success'          => true,
		'template_part'    => $fresh ? omatic_sn_describe_template( $fresh, false ) : null,
		'wp_id'            => (int) $post_id,
		'materialised'     => $materialised,
		'snapshot_key'     => $snapshot_key,
		'undo'             => 'omatic/site-editor-restore-snapshot with post_id=' . (int) $post_id . ' and snapshot_key=latest',
	);
}

function omatic_sn_get_navigation_post( $post_id ) {
	$post = get_post( absint( $post_id ) );
	if ( ! $post || 'wp_navigation' !== $post->post_type ) {
		return null;
	}
	return $post;
}

function omatic_sn_describe_navigation( WP_Post $post, $with_content = true ) {
	$out = array(
		'ID'       => (int) $post->ID,
		'title'    => $post->post_title,
		'slug'     => $post->post_name,
		'status'   => $post->post_status,
		'modified' => $post->post_modified_gmt,
	);
	if ( $with_content ) {
		$out['content']     = $post->post_content;
		$out['block_names'] = omatic_sn_block_names( $post->post_content );
	}
	if ( function_exists( 'pll_get_post_language' ) ) {
		$out['language'] = pll_get_post_language( $post->ID, 'slug' );
	}
	return $out;
}

/**
 * Serialised block comment for a self-closing dynamic block.
 */
function omatic_sn_block_comment( $name, array $attrs ) {
	$attrs = array_filter(
		$attrs,
		function ( $v ) {
			return null !== $v;
		}
	);
	if ( empty( $attrs ) ) {
		return '<!-- wp:' . $name . ' /-->';
	}
	return '<!-- wp:' . $name . ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
}

/**
 * Switcher block attributes from ability input, limited to the keys and enums
 * Polylang's block.json declares.
 */
function omatic_sn_switcher_block_attrs( $input ) {
	$attrs = array();
	if ( isset( $input['layout'] ) && in_array( $input['layout'], array( 'vertical', 'horizontal', 'dropdown', 'select' ), true ) ) {
		$attrs['layout'] = $input['layout'];
	}
	if ( isset( $input['show_labels'] ) && in_array( $input['show_labels'], array( 'names', 'codes', '' ), true ) ) {
		$attrs['show_labels'] = $input['show_labels'];
	}
	foreach ( array( 'show_flags', 'force_home', 'hide_current', 'hide_if_no_translation' ) as $flag ) {
		if ( isset( $input[ $flag ] ) ) {
			$attrs[ $flag ] = (bool) $input[ $flag ];
		}
	}
	return $attrs;
}

/**
 * Insert $block_markup into $content at a position. For 'after_navigation'
 * the insertion follows the first core/navigation block, whether it is
 * self-closing (<!-- wp:navigation {...} /-->) or has inner content.
 *
 * @return string|WP_Error
 */
function omatic_sn_insert_markup( $content, $block_markup, $position ) {
	$content = (string) $content;
	switch ( $position ) {
		case 'prepend':
			return $block_markup . "\n" . $content;
		case 'append':
			return rtrim( $content ) . "\n" . $block_markup;
		case 'after_navigation':
			if ( preg_match( '/<!--\s+wp:navigation(?=[\s\/])[^>]*?\/-->/s', $content, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1] + strlen( $m[0][0] );
				return substr( $content, 0, $at ) . "\n" . $block_markup . substr( $content, $at );
			}
			$close = strpos( $content, '<!-- /wp:navigation -->' );
			if ( false !== $close ) {
				$at = $close + strlen( '<!-- /wp:navigation -->' );
				return substr( $content, 0, $at ) . "\n" . $block_markup . substr( $content, $at );
			}
			return new WP_Error( 'omatic_no_navigation', 'No core/navigation block found to insert after.' );
		default:
			return new WP_Error( 'omatic_bad_position', 'position must be prepend, append, or after_navigation.' );
	}
}

// ─────────────────────────────────────────────
// REGISTRATION
// ─────────────────────────────────────────────

function omatic_sn_register_abilities() {

	$menu_prop = array( 'type' => 'string', 'description' => 'Menu ID, slug, or name.' );
	$item_props = array(
		'type'        => array( 'type' => 'string', 'enum' => array( 'page', 'post', 'custom', 'language_switcher' ), 'description' => 'Item type. page/post link to an existing post by object_id; custom uses url; language_switcher is Polylang\'s "Languages" item (stored as url #pll_switcher + _pll_menu_item meta).' ),
		'object_id'   => array( 'type' => 'integer', 'description' => 'Page or post ID (type page/post).' ),
		'url'         => array( 'type' => 'string', 'description' => 'Link URL (type custom).' ),
		'title'       => array( 'type' => 'string', 'description' => 'Navigation label. For page/post, omit to use the object\'s own title.' ),
		'parent_id'   => array( 'type' => 'integer', 'description' => 'Parent menu item ID for a sub-item. 0 for top level.' ),
		'position'    => array( 'type' => 'integer', 'description' => 'menu_order (1-based). Omit to append.' ),
		'target'      => array( 'type' => 'string', 'description' => '"_blank" to open in a new tab; anything else clears it.' ),
		'classes'     => array( 'type' => 'string', 'description' => 'Space-separated CSS classes.' ),
		'attr_title'  => array( 'type' => 'string', 'description' => 'HTML title attribute.' ),
		'description' => array( 'type' => 'string', 'description' => 'Item description.' ),
		'status'      => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ), 'default' => 'publish' ),
		'switcher'    => array(
			'type'        => 'object',
			'description' => 'Polylang switcher options (type language_switcher only). Keys as Polylang stores them: hide_if_no_translation, hide_current, force_home, show_flags, show_names, dropdown — each 0/1.',
			'properties'  => array(
				'hide_if_no_translation' => array( 'type' => 'boolean', 'default' => false ),
				'hide_current'           => array( 'type' => 'boolean', 'default' => false ),
				'force_home'             => array( 'type' => 'boolean', 'default' => false ),
				'show_flags'             => array( 'type' => 'boolean', 'default' => false ),
				'show_names'             => array( 'type' => 'boolean', 'default' => true ),
				'dropdown'               => array( 'type' => 'boolean', 'default' => false ),
			),
		),
	);

	// ═════════════════════════════════════════
	// MENUS — write
	// ═════════════════════════════════════════

	wp_register_ability(
		'omatic/menus-create',
		array(
			'label'               => 'Create Menu',
			'description'         => 'Create a new navigation menu by name (wp_create_nav_menu). Optionally assign it to a theme location in the same call.',
			'category'            => 'menus',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'name' ),
				'properties' => array(
					'name'     => array( 'type' => 'string', 'description' => 'Menu name. Must not already exist.' ),
					'location' => array( 'type' => 'string', 'description' => 'Optional registered theme location slug to assign (see menus-list registered_locations).' ),
					'language' => array( 'type' => 'string', 'description' => 'Optional Polylang language slug for the location assignment (see menus-assign-location).' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_menus_create',
			'meta'                => omatic_sn_meta(),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/menus-add-item',
		array(
			'label'               => 'Add Menu Item',
			'description'         => 'Add an item to a menu: a page, a post, a custom link, or a Polylang language switcher (wp_update_nav_menu_item).',
			'category'            => 'menus',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'menu', 'type' ),
				'properties' => array_merge( array( 'menu' => $menu_prop ), $item_props ),
			),
			'execute_callback'    => 'omatic_cb_sn_menus_add_item',
			'meta'                => omatic_sn_meta(),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/menus-update-item',
		array(
			'label'               => 'Update Menu Item',
			'description'         => 'Update fields of an existing menu item. Only supplied fields change. Supply type to retarget the item.',
			'category'            => 'menus',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'menu', 'item_id' ),
				'properties' => array_merge(
					array(
						'menu'    => $menu_prop,
						'item_id' => array( 'type' => 'integer', 'description' => 'Menu item ID (from menus-get-items).' ),
					),
					$item_props
				),
			),
			'execute_callback'    => 'omatic_cb_sn_menus_update_item',
			'meta'                => omatic_sn_meta(),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/menus-delete-item',
		array(
			'label'               => 'Delete Menu Item',
			'description'         => 'Permanently delete a menu item (wp_delete_post, force). Children become top-level, as in wp-admin.',
			'category'            => 'menus',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'menu', 'item_id' ),
				'properties' => array(
					'menu'    => $menu_prop,
					'item_id' => array( 'type' => 'integer', 'description' => 'Menu item ID.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_menus_delete_item',
			'meta'                => omatic_sn_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/menus-assign-location',
		array(
			'label'               => 'Assign Menu to Location',
			'description'         => 'Assign a menu to a registered theme location (set_theme_mod nav_menu_locations). With Polylang, pass language to assign the menu for that language\'s copy of the location; Polylang keeps those in its own option (polylang[nav_menus][theme][location][lang]). Pass menu as 0 or "" to clear.',
			'category'            => 'menus',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'location' ),
				'properties' => array(
					'location' => array( 'type' => 'string', 'description' => 'Registered location slug (see menus-list registered_locations).' ),
					'menu'     => array( 'type' => 'string', 'description' => 'Menu ID, slug, or name. Empty or 0 clears the location.' ),
					'language' => array( 'type' => 'string', 'description' => 'Polylang language slug (e.g. "es"). Omit for the site default / non-Polylang sites.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_menus_assign_location',
			'meta'                => omatic_sn_meta(),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	// ═════════════════════════════════════════
	// SITE EDITOR — template parts
	// ═════════════════════════════════════════

	$theme_prop = array( 'type' => 'string', 'description' => 'Theme stylesheet slug. Defaults to the active theme.' );

	wp_register_ability(
		'omatic/template-parts-list',
		array(
			'label'               => 'List Template Parts',
			'description'         => 'List block-theme template parts (theme files and customised copies) with slug, area, source and whether a database copy exists.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'area'  => array( 'type' => 'string', 'description' => 'Filter by area: header, footer, uncategorized.' ),
					'theme' => $theme_prop,
				),
			),
			'execute_callback'    => 'omatic_cb_sn_template_parts_list',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/template-parts-get',
		array(
			'label'               => 'Get Template Part',
			'description'         => 'Read a template part by slug (e.g. "header") — its block content, source, and the wp_navigation posts it references.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug' ),
				'properties' => array(
					'slug'  => array( 'type' => 'string', 'description' => 'Template part slug, e.g. header.' ),
					'theme' => $theme_prop,
				),
			),
			'execute_callback'    => 'omatic_cb_sn_template_parts_get',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/template-parts-update',
		array(
			'label'               => 'Update Template Part',
			'description'         => 'Replace a template part\'s block content by slug. Snapshots first. A part that only exists as a theme file is materialised as a customised copy first, exactly as the Site Editor does.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'slug', 'content' ),
				'properties' => array(
					'slug'    => array( 'type' => 'string' ),
					'theme'   => $theme_prop,
					'content' => array( 'type' => 'string', 'description' => 'Full serialised block markup for the part.' ),
					'title'   => array( 'type' => 'string', 'description' => 'Optional new title.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_template_parts_update',
			'meta'                => omatic_sn_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	// ═════════════════════════════════════════
	// SITE EDITOR — navigation posts
	// ═════════════════════════════════════════

	wp_register_ability(
		'omatic/navigation-list',
		array(
			'label'               => 'List Navigation Menus (block)',
			'description'         => 'List wp_navigation posts — the menus a block theme\'s Navigation block renders from.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'status' => array( 'type' => 'string', 'default' => 'publish', 'description' => 'publish, draft, or any.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_navigation_list',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/navigation-get',
		array(
			'label'               => 'Get Navigation Menu (block)',
			'description'         => 'Read a wp_navigation post\'s block content by ID.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
			),
			'execute_callback'    => 'omatic_cb_sn_navigation_get',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/navigation-update',
		array(
			'label'               => 'Update Navigation Menu (block)',
			'description'         => 'Replace a wp_navigation post\'s block content. Snapshots first.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'content' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'content' => array( 'type' => 'string', 'description' => 'Full serialised block markup (core/navigation-link, core/navigation-submenu, ...).' ),
					'title'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_navigation_update',
			'meta'                => omatic_sn_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/insert-language-switcher',
		array(
			'label'               => 'Insert Language Switcher Block',
			'description'         => 'Insert Polylang\'s language switcher block into a wp_navigation post (block polylang/navigation-language-switcher) or a template part (block polylang/language-switcher). Snapshots first. Refuses if the block is not registered on this site.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'target' ),
				'properties' => array(
					'target'                 => array( 'type' => 'string', 'enum' => array( 'navigation', 'template_part' ), 'description' => 'navigation = a wp_navigation post (post_id); template_part = a part by slug.' ),
					'post_id'                => array( 'type' => 'integer', 'description' => 'wp_navigation post ID (target navigation).' ),
					'slug'                   => array( 'type' => 'string', 'description' => 'Template part slug (target template_part).' ),
					'theme'                  => $theme_prop,
					'position'               => array( 'type' => 'string', 'enum' => array( 'append', 'prepend', 'after_navigation' ), 'default' => 'append', 'description' => 'Where to place the block. after_navigation is only meaningful for a template part.' ),
					'layout'                 => array( 'type' => 'string', 'enum' => array( 'vertical', 'horizontal', 'dropdown', 'select' ) ),
					'show_labels'            => array( 'type' => 'string', 'enum' => array( 'names', 'codes', '' ) ),
					'show_flags'             => array( 'type' => 'boolean' ),
					'force_home'             => array( 'type' => 'boolean' ),
					'hide_current'           => array( 'type' => 'boolean' ),
					'hide_if_no_translation' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_insert_language_switcher',
			'meta'                => omatic_sn_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	// ═════════════════════════════════════════
	// SITE EDITOR — safety
	// ═════════════════════════════════════════

	wp_register_ability(
		'omatic/site-editor-list-snapshots',
		array(
			'label'               => 'List Site Editor Snapshots',
			'description'         => 'List automatic pre-write snapshots held for a wp_template_part or wp_navigation post, newest last.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'description' => 'wp_id of the template part, or the wp_navigation post ID.' ) ),
			),
			'execute_callback'    => 'omatic_cb_sn_list_snapshots',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/site-editor-restore-snapshot',
		array(
			'label'               => 'Restore Site Editor Snapshot',
			'description'         => 'Restore a template part or navigation post\'s content from a snapshot. Takes a snapshot of the current state first, so a restore is itself undoable.',
			'category'            => 'site-editor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'snapshot_key' ),
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'snapshot_key' => array( 'type' => 'string', 'description' => 'Key from site-editor-list-snapshots. Use "latest" for the most recent.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_restore_snapshot',
			'meta'                => omatic_sn_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	// ═════════════════════════════════════════
	// VERIFY
	// ═════════════════════════════════════════

	wp_register_ability(
		'omatic/verify-public-render',
		array(
			'label'               => 'Verify Public Render',
			'description'         => 'Fetch a public URL on this site server-side as an anonymous visitor (wp_remote_get, no cookies) and report whether each given string appears in the HTML. Only URLs on this site\'s own host are fetched.',
			'category'            => 'verify',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'strings' ),
				'properties' => array(
					'url'            => array( 'type' => 'string', 'description' => 'Absolute URL on this site, or a path like "/es/". Defaults to the homepage.' ),
					'strings'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Strings to look for in the response body.' ),
					'case_sensitive' => array( 'type' => 'boolean', 'default' => false ),
					'include_excerpt' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return up to 200 characters around the first match of each string.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_sn_verify_public_render',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/polylang-post-urls',
		array(
			'label'               => 'Polylang Post URLs',
			'description'         => 'For a post or page, list the translations Polylang reports (pll_get_post_translations) with each language\'s permalink, plus the per-language home URLs (pll_home_url).',
			'category'            => 'verify',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
			),
			'execute_callback'    => 'omatic_cb_sn_polylang_post_urls',
			'meta'                => omatic_sn_meta( false, true ),
			'permission_callback' => 'omatic_perm_read',
		)
	);
}

// ─────────────────────────────────────────────
// CALLBACKS — MENUS
// ─────────────────────────────────────────────

function omatic_cb_sn_menus_create( $input ) {
	$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
	if ( '' === $name ) {
		return array( 'error' => 'name is required.' );
	}
	if ( wp_get_nav_menu_object( $name ) ) {
		return array( 'error' => "A menu named '{$name}' already exists." );
	}
	$menu_id = wp_create_nav_menu( $name );
	if ( is_wp_error( $menu_id ) ) {
		return array( 'error' => $menu_id->get_error_message() );
	}
	$out = array( 'success' => true, 'menu_id' => (int) $menu_id, 'name' => $name );
	if ( ! empty( $input['location'] ) ) {
		$out['assignment'] = omatic_cb_sn_menus_assign_location(
			array(
				'location' => $input['location'],
				'menu'     => (string) $menu_id,
				'language' => isset( $input['language'] ) ? $input['language'] : '',
			)
		);
	}
	return $out;
}

function omatic_cb_sn_menus_add_item( $input ) {
	$menu = omatic_sn_resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );
	if ( ! $menu ) {
		return array( 'error' => 'Menu not found.' );
	}
	if ( isset( $input['type'] ) && 'language_switcher' === $input['type'] && ! omatic_sn_polylang_active() ) {
		return array( 'error' => 'Polylang is not active on this site; a language_switcher item would render as a dead "#pll_switcher" link.' );
	}
	$args = omatic_sn_build_item_args( $input, 0 );
	if ( is_wp_error( $args ) ) {
		return array( 'error' => $args->get_error_message() );
	}
	if ( ! empty( $args['menu-item-parent-id'] ) && ! omatic_sn_item_belongs_to_menu( $args['menu-item-parent-id'], $menu->term_id ) ) {
		return array( 'error' => 'parent_id is not an item of this menu.' );
	}

	$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
	if ( is_wp_error( $item_id ) ) {
		return array( 'error' => $item_id->get_error_message() );
	}

	if ( 'language_switcher' === $input['type'] ) {
		// Polylang's own admin save path only runs on the nav-menus.php form
		// (it checks the update-nav_menu nonce), so write the options meta the
		// same way it does. Without this meta Polylang treats the item as an
		// ordinary custom link to "#pll_switcher".
		update_post_meta( $item_id, OMATIC_PLL_MENU_META, omatic_sn_pll_switcher_options( isset( $input['switcher'] ) ? $input['switcher'] : array() ) );
	}

	return array(
		'success' => true,
		'menu_id' => (int) $menu->term_id,
		'item'    => omatic_sn_describe_item( $item_id ),
	);
}

function omatic_cb_sn_menus_update_item( $input ) {
	$menu = omatic_sn_resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );
	if ( ! $menu ) {
		return array( 'error' => 'Menu not found.' );
	}
	$item_id = isset( $input['item_id'] ) ? absint( $input['item_id'] ) : 0;
	if ( ! omatic_sn_item_belongs_to_menu( $item_id, $menu->term_id ) ) {
		return array( 'error' => 'item_id is not an item of this menu.' );
	}

	// wp_update_nav_menu_item() resets every unsupplied key to its default, so
	// start from the item's current values and overlay only what was passed.
	$current = wp_setup_nav_menu_item( get_post( $item_id ) );
	$base    = array(
		'menu-item-object-id'   => (int) $current->object_id,
		'menu-item-object'      => $current->object,
		'menu-item-parent-id'   => (int) $current->menu_item_parent,
		'menu-item-position'    => (int) $current->menu_order,
		'menu-item-type'        => $current->type,
		'menu-item-title'       => wp_slash( $current->post_title ),
		'menu-item-url'         => 'custom' === $current->type ? $current->url : '',
		'menu-item-description' => wp_slash( $current->post_content ),
		'menu-item-attr-title'  => wp_slash( $current->post_excerpt ),
		'menu-item-target'      => $current->target,
		'menu-item-classes'     => implode( ' ', (array) $current->classes ),
		'menu-item-xfn'         => $current->xfn,
		'menu-item-status'      => $current->post_status,
	);

	$overlay = omatic_sn_build_item_args( $input, $item_id );
	if ( is_wp_error( $overlay ) ) {
		return array( 'error' => $overlay->get_error_message() );
	}
	if ( ! isset( $input['status'] ) ) {
		unset( $overlay['menu-item-status'] );
	}
	if ( isset( $overlay['menu-item-parent-id'] ) && $overlay['menu-item-parent-id'] && ! omatic_sn_item_belongs_to_menu( $overlay['menu-item-parent-id'], $menu->term_id ) ) {
		return array( 'error' => 'parent_id is not an item of this menu.' );
	}
	$args = array_merge( $base, $overlay );

	$result = wp_update_nav_menu_item( $menu->term_id, $item_id, $args );
	if ( is_wp_error( $result ) ) {
		return array( 'error' => $result->get_error_message() );
	}

	$is_switcher = ( isset( $input['type'] ) && 'language_switcher' === $input['type'] ) || OMATIC_PLL_SWITCHER_URL === $args['menu-item-url'];
	if ( $is_switcher ) {
		$existing = get_post_meta( $item_id, OMATIC_PLL_MENU_META, true );
		$merged   = array_merge( is_array( $existing ) ? $existing : array(), isset( $input['switcher'] ) && is_array( $input['switcher'] ) ? $input['switcher'] : array() );
		update_post_meta( $item_id, OMATIC_PLL_MENU_META, omatic_sn_pll_switcher_options( $merged ) );
	} elseif ( isset( $input['type'] ) ) {
		// Retargeted away from the switcher: drop Polylang's marker meta.
		delete_post_meta( $item_id, OMATIC_PLL_MENU_META );
	}

	return array(
		'success' => true,
		'menu_id' => (int) $menu->term_id,
		'item'    => omatic_sn_describe_item( $item_id ),
	);
}

function omatic_cb_sn_menus_delete_item( $input ) {
	$menu = omatic_sn_resolve_menu( isset( $input['menu'] ) ? $input['menu'] : '' );
	if ( ! $menu ) {
		return array( 'error' => 'Menu not found.' );
	}
	$item_id = isset( $input['item_id'] ) ? absint( $input['item_id'] ) : 0;
	if ( ! omatic_sn_item_belongs_to_menu( $item_id, $menu->term_id ) ) {
		return array( 'error' => 'item_id is not an item of this menu.' );
	}
	$before = omatic_sn_describe_item( $item_id );
	$result = wp_delete_post( $item_id, true );
	if ( ! $result ) {
		return array( 'error' => 'wp_delete_post returned false.' );
	}
	return array( 'success' => true, 'menu_id' => (int) $menu->term_id, 'deleted' => $before );
}

function omatic_cb_sn_menus_assign_location( $input ) {
	$location   = isset( $input['location'] ) ? sanitize_key( $input['location'] ) : '';
	$registered = get_registered_nav_menus();
	if ( '' === $location || ! isset( $registered[ $location ] ) ) {
		return array( 'error' => 'location is not a registered theme location.', 'registered_locations' => array_keys( (array) $registered ) );
	}

	$menu_id = 0;
	if ( isset( $input['menu'] ) && '' !== (string) $input['menu'] && '0' !== (string) $input['menu'] ) {
		$menu = omatic_sn_resolve_menu( $input['menu'] );
		if ( ! $menu ) {
			return array( 'error' => 'Menu not found.' );
		}
		$menu_id = (int) $menu->term_id;
	}

	$language = isset( $input['language'] ) ? sanitize_key( $input['language'] ) : '';
	$out      = array( 'success' => true, 'location' => $location, 'menu_id' => $menu_id );

	if ( '' !== $language ) {
		if ( ! omatic_sn_polylang_active() ) {
			return array( 'error' => 'language was given but Polylang is not active.' );
		}
		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
		if ( ! in_array( $language, (array) $slugs, true ) ) {
			return array( 'error' => 'Unknown Polylang language slug.', 'languages' => $slugs );
		}
		// Polylang stores per-language location assignments in its own option:
		// polylang[nav_menus][<theme>][<location>][<lang>] = menu term_id
		// (src/admin/admin-nav-menu.php, update_nav_menu_locations(); option
		// name from src/Options/Options.php OPTION_NAME = 'polylang'). Its own
		// writer only runs on the wp-admin form nonce, so mirror the write.
		$theme   = get_option( 'stylesheet' );
		$options = get_option( 'polylang', array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$options['nav_menus'][ $theme ][ $location ][ $language ] = $menu_id;
		update_option( 'polylang', $options );
		$out['polylang_nav_menus'] = $options['nav_menus'][ $theme ][ $location ];

		if ( function_exists( 'pll_default_language' ) && pll_default_language( 'slug' ) !== $language ) {
			$out['note'] = 'Assigned for language ' . $language . ' in Polylang\'s nav_menus option. The theme_mod (default language) is unchanged.';
			return $out;
		}
	}

	$locations              = get_theme_mod( 'nav_menu_locations' );
	$locations              = is_array( $locations ) ? $locations : array();
	$locations[ $location ] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
	$out['nav_menu_locations'] = get_theme_mod( 'nav_menu_locations' );
	return $out;
}

// ─────────────────────────────────────────────
// CALLBACKS — SITE EDITOR
// ─────────────────────────────────────────────

function omatic_cb_sn_template_parts_list( $input ) {
	if ( ! function_exists( 'get_block_templates' ) ) {
		return array( 'error' => 'Block templates API unavailable on this WordPress.' );
	}
	$query = array();
	if ( ! empty( $input['area'] ) ) {
		$query['area'] = sanitize_key( $input['area'] );
	}
	$parts = get_block_templates( $query, 'wp_template_part' );
	$out   = array();
	foreach ( $parts as $t ) {
		if ( ! empty( $input['theme'] ) && $t->theme !== sanitize_text_field( $input['theme'] ) ) {
			continue;
		}
		$out[] = omatic_sn_describe_template( $t, false );
	}
	return array(
		'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : null,
		'theme'          => get_stylesheet(),
		'template_parts' => $out,
		'total'          => count( $out ),
	);
}

function omatic_cb_sn_template_parts_get( $input ) {
	$t = omatic_sn_get_template_part( isset( $input['slug'] ) ? $input['slug'] : '', isset( $input['theme'] ) ? $input['theme'] : '' );
	if ( ! $t ) {
		return array( 'error' => 'Template part not found.' );
	}
	$out = omatic_sn_describe_template( $t, true );
	if ( $t->wp_id ) {
		$snaps                 = get_post_meta( $t->wp_id, OMATIC_SE_SNAPSHOT_META, true );
		$out['snapshots_held'] = is_array( $snaps ) ? count( $snaps ) : 0;
	}
	return $out;
}

function omatic_cb_sn_template_parts_update( $input ) {
	$t = omatic_sn_get_template_part( isset( $input['slug'] ) ? $input['slug'] : '', isset( $input['theme'] ) ? $input['theme'] : '' );
	if ( ! $t ) {
		return array( 'error' => 'Template part not found.' );
	}
	if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return array( 'error' => 'content (string) is required.' );
	}
	$content = wp_kses_post( $input['content'] ); // Same filter the block editor applies for users without unfiltered_html.
	if ( current_user_can( 'unfiltered_html' ) ) {
		$content = $input['content'];
	}
	return omatic_sn_write_template_part( $t, $content, isset( $input['title'] ) ? $input['title'] : null, 'update' );
}

function omatic_cb_sn_navigation_list( $input ) {
	$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
	$posts  = get_posts(
		array(
			'post_type'      => 'wp_navigation',
			'post_status'    => 'any' === $status ? array( 'publish', 'draft' ) : $status,
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$out = array();
	foreach ( $posts as $p ) {
		$out[] = omatic_sn_describe_navigation( $p, false );
	}
	return array( 'navigations' => $out, 'total' => count( $out ) );
}

function omatic_cb_sn_navigation_get( $input ) {
	$post = omatic_sn_get_navigation_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( ! $post ) {
		return array( 'error' => 'wp_navigation post not found.' );
	}
	$out                   = omatic_sn_describe_navigation( $post, true );
	$snaps                 = get_post_meta( $post->ID, OMATIC_SE_SNAPSHOT_META, true );
	$out['snapshots_held'] = is_array( $snaps ) ? count( $snaps ) : 0;
	return $out;
}

function omatic_sn_write_navigation( WP_Post $post, $content, $title = null, $reason = 'edit' ) {
	$snapshot_key = omatic_sn_take_snapshot( $post->ID, $reason );
	$changes      = array( 'ID' => $post->ID, 'post_content' => (string) $content );
	if ( null !== $title ) {
		$changes['post_title'] = sanitize_text_field( $title );
	}
	$result = wp_update_post( wp_slash( $changes ), true );
	if ( is_wp_error( $result ) ) {
		return array( 'error' => 'wp_update_post failed: ' . $result->get_error_message(), 'snapshot_key' => $snapshot_key );
	}
	return array(
		'success'      => true,
		'navigation'   => omatic_sn_describe_navigation( get_post( $post->ID ), false ),
		'snapshot_key' => $snapshot_key,
		'undo'         => 'omatic/site-editor-restore-snapshot with post_id=' . (int) $post->ID . ' and snapshot_key=latest',
	);
}

function omatic_cb_sn_navigation_update( $input ) {
	$post = omatic_sn_get_navigation_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
	if ( ! $post ) {
		return array( 'error' => 'wp_navigation post not found.' );
	}
	if ( ! isset( $input['content'] ) || ! is_string( $input['content'] ) ) {
		return array( 'error' => 'content (string) is required.' );
	}
	$content = current_user_can( 'unfiltered_html' ) ? $input['content'] : wp_kses_post( $input['content'] );
	return omatic_sn_write_navigation( $post, $content, isset( $input['title'] ) ? $input['title'] : null, 'update' );
}

function omatic_cb_sn_insert_language_switcher( $input ) {
	$target   = isset( $input['target'] ) ? sanitize_key( $input['target'] ) : '';
	$position = isset( $input['position'] ) ? sanitize_key( $input['position'] ) : 'append';
	$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;

	if ( 'navigation' === $target ) {
		$block = OMATIC_PLL_BLOCK_NAVIGATION;
	} elseif ( 'template_part' === $target ) {
		$block = OMATIC_PLL_BLOCK_STANDARD;
	} else {
		return array( 'error' => 'target must be navigation or template_part.' );
	}

	if ( ! $registry || ! $registry->is_registered( $block ) ) {
		return array(
			'error'          => "Block {$block} is not registered on this site. Polylang's documentation lists the Site Editor / navigation switcher blocks as a Polylang Pro feature (https://polylang.pro/documentation/support/guides/the-language-switcher/). Alternative: a classic menu with a language_switcher item via omatic/menus-add-item.",
			'polylang_active' => omatic_sn_polylang_active(),
			'registered_polylang_blocks' => $registry ? array_values( array_filter( array_keys( $registry->get_all_registered() ), function ( $n ) { return 0 === strpos( $n, 'polylang/' ); } ) ) : array(),
		);
	}

	$markup = omatic_sn_block_comment( $block, omatic_sn_switcher_block_attrs( $input ) );

	if ( 'navigation' === $target ) {
		$post = omatic_sn_get_navigation_post( isset( $input['post_id'] ) ? $input['post_id'] : 0 );
		if ( ! $post ) {
			return array( 'error' => 'post_id must be an existing wp_navigation post.' );
		}
		if ( false !== strpos( $post->post_content, 'wp:' . $block ) ) {
			return array( 'error' => 'This navigation already contains ' . $block . '.', 'post_id' => (int) $post->ID );
		}
		$new = omatic_sn_insert_markup( $post->post_content, $markup, 'after_navigation' === $position ? 'append' : $position );
		if ( is_wp_error( $new ) ) {
			return array( 'error' => $new->get_error_message() );
		}
		$out                    = omatic_sn_write_navigation( $post, $new, null, 'insert-language-switcher' );
		$out['inserted_markup'] = $markup;
		return $out;
	}

	$t = omatic_sn_get_template_part( isset( $input['slug'] ) ? $input['slug'] : '', isset( $input['theme'] ) ? $input['theme'] : '' );
	if ( ! $t ) {
		return array( 'error' => 'Template part not found.' );
	}
	if ( false !== strpos( $t->content, 'wp:' . $block ) ) {
		return array( 'error' => 'This template part already contains ' . $block . '.', 'id' => $t->id );
	}
	$new = omatic_sn_insert_markup( $t->content, $markup, $position );
	if ( is_wp_error( $new ) ) {
		return array( 'error' => $new->get_error_message(), 'navigation_refs' => omatic_sn_navigation_refs( $t->content ) );
	}
	$out                    = omatic_sn_write_template_part( $t, $new, null, 'insert-language-switcher' );
	$out['inserted_markup'] = $markup;
	$out['navigation_refs'] = omatic_sn_navigation_refs( $t->content );
	return $out;
}

function omatic_cb_sn_list_snapshots( $input ) {
	$post_id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$snapshots = get_post_meta( $post_id, OMATIC_SE_SNAPSHOT_META, true );
	if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
		return array( 'post_id' => $post_id, 'count' => 0, 'snapshots' => array() );
	}
	$list = array();
	foreach ( $snapshots as $key => $snap ) {
		$list[] = array(
			'key'       => $key,
			'taken_at'  => isset( $snap['taken_at'] ) ? $snap['taken_at'] : null,
			'reason'    => isset( $snap['reason'] ) ? $snap['reason'] : null,
			'post_type' => isset( $snap['post_type'] ) ? $snap['post_type'] : null,
			'bytes'     => isset( $snap['bytes'] ) ? $snap['bytes'] : null,
		);
	}
	return array( 'post_id' => $post_id, 'count' => count( $list ), 'max_held' => OMATIC_SE_SNAPSHOT_MAX, 'snapshots' => $list );
}

function omatic_cb_sn_restore_snapshot( $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'wp_template_part', 'wp_navigation' ), true ) ) {
		return array( 'error' => 'post_id must be a wp_template_part or wp_navigation post.' );
	}
	$snapshots = get_post_meta( $post_id, OMATIC_SE_SNAPSHOT_META, true );
	if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
		return array( 'error' => 'No snapshots held for this post.' );
	}
	$key = isset( $input['snapshot_key'] ) ? (string) $input['snapshot_key'] : 'latest';
	if ( 'latest' === $key ) {
		end( $snapshots );
		$key = key( $snapshots );
	}
	if ( ! isset( $snapshots[ $key ] ) || ! isset( $snapshots[ $key ]['content'] ) ) {
		return array( 'error' => 'Snapshot not found.', 'available' => array_keys( $snapshots ) );
	}

	$pre_key = omatic_sn_take_snapshot( $post_id, 'pre-restore' );
	$changes = array( 'ID' => $post_id, 'post_content' => (string) $snapshots[ $key ]['content'] );
	if ( isset( $snapshots[ $key ]['title'] ) ) {
		$changes['post_title'] = $snapshots[ $key ]['title'];
	}
	$result = wp_update_post( wp_slash( $changes ), true );
	if ( is_wp_error( $result ) ) {
		return array( 'error' => 'wp_update_post failed: ' . $result->get_error_message() );
	}
	return array(
		'success'          => true,
		'post_id'          => $post_id,
		'restored'         => $key,
		'taken_at'         => isset( $snapshots[ $key ]['taken_at'] ) ? $snapshots[ $key ]['taken_at'] : null,
		'pre_restore_key'  => $pre_key,
	);
}

// ─────────────────────────────────────────────
// CALLBACKS — VERIFY
// ─────────────────────────────────────────────

function omatic_cb_sn_verify_public_render( $input ) {
	$strings = isset( $input['strings'] ) && is_array( $input['strings'] ) ? array_values( array_filter( array_map( 'strval', $input['strings'] ), 'strlen' ) ) : array();
	if ( empty( $strings ) ) {
		return array( 'error' => 'strings must be a non-empty array.' );
	}

	$home = home_url( '/' );
	$url  = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
	if ( '' === $url ) {
		$url = $home;
	} elseif ( 0 === strpos( $url, '/' ) ) {
		$url = home_url( $url );
	}
	$url = esc_url_raw( $url, array( 'http', 'https' ) );

	// Same-host guard: this ability verifies THIS site's public output. It is
	// not a general fetcher, so it never becomes an SSRF primitive.
	$site_host = wp_parse_url( $home, PHP_URL_HOST );
	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $url_host || strtolower( (string) $url_host ) !== strtolower( (string) $site_host ) ) {
		return array( 'error' => 'url must be on this site\'s host (' . $site_host . ').' );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'cookies'     => array(),
			'headers'     => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
			'user-agent'  => 'LucidIT-WP-Enabler/' . OMATIC_ENABLER_VERSION . ' verify (+' . $home . ')',
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'error' => 'wp_remote_get failed: ' . $response->get_error_message(), 'url' => $url );
	}

	$body     = (string) wp_remote_retrieve_body( $response );
	$haystack = ! empty( $input['case_sensitive'] ) ? $body : strtolower( $body );
	$found    = array();
	$excerpts = array();
	foreach ( $strings as $s ) {
		$needle = ! empty( $input['case_sensitive'] ) ? $s : strtolower( $s );
		$pos    = strpos( $haystack, $needle );
		$found[ $s ] = false !== $pos;
		if ( false !== $pos && ! empty( $input['include_excerpt'] ) ) {
			$start          = max( 0, $pos - 100 );
			$excerpts[ $s ] = substr( $body, $start, 200 + strlen( $s ) );
		}
	}

	$out = array(
		'url'          => $url,
		'final_url'    => isset( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ? $response['http_response']->get_response_object()->url : $url,
		'status'       => (int) wp_remote_retrieve_response_code( $response ),
		'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
		'bytes'        => strlen( $body ),
		'found'        => $found,
		'all_found'    => ! in_array( false, $found, true ),
	);
	if ( ! empty( $excerpts ) ) {
		$out['excerpts'] = $excerpts;
	}
	return $out;
}

function omatic_cb_sn_polylang_post_urls( $input ) {
	if ( ! omatic_sn_polylang_active() ) {
		return array( 'error' => 'Polylang is not active on this site.', 'polylang_active' => false );
	}
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return array( 'error' => 'Post not found.' );
	}

	$languages    = pll_languages_list( array( 'fields' => 'slug' ) );
	$translations = pll_get_post_translations( $post_id );
	$per_language = array();
	foreach ( (array) $languages as $slug ) {
		$tid = isset( $translations[ $slug ] ) ? (int) $translations[ $slug ] : 0;
		$tp  = $tid ? get_post( $tid ) : null;
		$per_language[ $slug ] = array(
			'post_id'  => $tid ?: null,
			'status'   => $tp ? $tp->post_status : null,
			'title'    => $tp ? $tp->post_title : null,
			'url'      => $tp ? get_permalink( $tp ) : null,
			'home_url' => function_exists( 'pll_home_url' ) ? pll_home_url( $slug ) : null,
		);
	}
	return array(
		'post_id'          => $post_id,
		'post_language'    => function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id, 'slug' ) : null,
		'default_language' => function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : null,
		'languages'        => $languages,
		'translations'     => $per_language,
		'missing'          => array_values( array_filter( (array) $languages, function ( $l ) use ( $translations ) { return empty( $translations[ $l ] ); } ) ),
	);
}
