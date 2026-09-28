<?php
/**
 * LucidIT WordPress Enabler — Elementor abilities.
 *
 * Turns this plugin into an Elementor MCP as well as a general WordPress
 * abilities enabler. Everything here is registered through the Abilities API
 * and surfaced by the WordPress MCP Adapter on the same endpoint as the
 * omatic/* abilities — there is no second server and no second plugin.
 *
 * THREE THINGS THIS FILE GETS RIGHT, because getting them wrong is the usual
 * way an agent quietly destroys an Elementor page:
 *
 *  1. SLASHES. _elementor_data is stored slash-escaped. get_post_meta() hands
 *     it back unslashed and update_post_meta() re-slashes on the way in, so a
 *     naive decode/encode round trip mangles every escaped quote in a text
 *     widget. Writes go through omatic_el_save_data(), which wp_slash()es.
 *
 *  2. CSS CACHE. Elementor compiles per-post CSS to uploads/elementor/css/.
 *     Write the meta without invalidating it and the browser keeps serving the
 *     old CSS — you change a colour, see nothing, and conclude the write
 *     failed. Every write calls omatic_el_flush_css().
 *
 *  3. NO FLATTENING. Edits walk the tree by element id and mutate in place,
 *     deep-merging settings. Siblings and unknown keys are preserved. Nothing
 *     ever replaces a whole settings object.
 *
 * Every write also takes an automatic snapshot first (rolling, capped), so any
 * change is one call away from being undone.
 *
 * @package LucidIT_WP_Enabler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OMATIC_EL_SNAPSHOT_META = '_omatic_el_snapshots';
const OMATIC_EL_SNAPSHOT_MAX  = 8;
const OMATIC_EL_SVG_MAX_BYTES = 2097152; // 2 MiB cap on a fetched SVG.

add_action( 'wp_abilities_api_categories_init', 'omatic_el_register_category' );
add_action( 'wp_abilities_api_init', 'omatic_el_register_abilities' );

// ─────────────────────────────────────────────
// CATEGORY
// ─────────────────────────────────────────────

function omatic_el_register_category() {
	wp_register_ability_category(
		'elementor',
		array(
			'label'       => 'Elementor',
			'description' => 'Read and edit Elementor page structure, elements, templates and global design tokens.',
		)
	);
}

/**
 * Exposure metadata.
 *
 * 'public'      — WordPress 7.1 unified public exposure flag.
 * 'mcp.public'  — channel-specific flag; required on 6.9/7.0, and remains
 *                 authoritative on 7.1 (resolution is
 *                 $meta['mcp']['public'] ?? $meta['public'] ?? false).
 *
 * Both are set deliberately. Setting only the unified flag hides every ability
 * on WordPress 7.0, which is what this site runs today.
 *
 * @param bool $destructive Mark the ability as destructive for clients that surface it.
 * @return array
 */
function omatic_el_meta( $destructive = false ) {
	$meta = array(
		'public' => true,
		'mcp'    => array( 'public' => true ),
	);
	if ( $destructive ) {
		$meta['annotations'] = array( 'destructive' => true );
	}
	return $meta;
}

// ─────────────────────────────────────────────
// HELPERS — data access
// ─────────────────────────────────────────────

/**
 * Read and decode _elementor_data for a post.
 *
 * @param int $post_id Post ID.
 * @return array Element tree, or empty array when the post is not Elementor-built.
 */
function omatic_el_get_data( $post_id ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $raw ) ) {
		return array();
	}
	if ( is_array( $raw ) ) {
		return $raw;
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Encode and persist an element tree, snapshotting first and busting CSS after.
 *
 * @param int    $post_id Post ID.
 * @param array  $data    Element tree.
 * @param string $reason  Short label recorded on the snapshot.
 * @return true|array True on success, or an error array suitable for returning to the client.
 */
function omatic_el_save_data( $post_id, $data, $reason = 'edit' ) {
	if ( ! is_array( $data ) ) {
		return array( 'error' => 'Refusing to save: element tree is not an array.' );
	}

	$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json ) {
		return array( 'error' => 'Refusing to save: element tree failed to encode (' . json_last_error_msg() . ').' );
	}

	omatic_el_take_snapshot( $post_id, $reason );

	// wp_slash() is load-bearing — see the note at the top of this file.
	update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
	}

	omatic_el_flush_css( $post_id );

	return true;
}

/**
 * Invalidate Elementor's compiled CSS so the edit is actually visible.
 *
 * @param int $post_id Post ID, or 0 for a global flush only.
 * @return bool Whether a flush ran.
 */
function omatic_el_flush_css( $post_id = 0 ) {
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return false;
	}
	try {
		if ( $post_id && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
		}
		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		return true;
	} catch ( \Throwable $e ) {
		return false;
	}
}

// ─────────────────────────────────────────────
// HELPERS — tree walking
// ─────────────────────────────────────────────

/**
 * Is this a string-keyed (associative) array?
 *
 * @param mixed $value Value to test.
 * @return bool
 */
function omatic_el_is_assoc( $value ) {
	if ( ! is_array( $value ) || array() === $value ) {
		return false;
	}
	return array_keys( $value ) !== range( 0, count( $value ) - 1 );
}

/**
 * Recursive deep merge. Associative arrays merge; lists replace wholesale,
 * because an Elementor list setting (icon lists, slides, tabs) is a single
 * value the caller means to swap, not something to interleave.
 *
 * @param array $base  Existing value.
 * @param array $patch Incoming value.
 * @return array
 */
function omatic_el_merge( $base, $patch ) {
	foreach ( $patch as $key => $value ) {
		if ( is_array( $value ) && omatic_el_is_assoc( $value )
			&& isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
			$base[ $key ] = omatic_el_merge( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

/**
 * Walk the tree, apply a callback to the node with the given id, return the
 * rebuilt tree. Returning null from the callback deletes the node.
 *
 * @param array    $nodes    Element tree (or subtree).
 * @param string   $id       Target element id.
 * @param callable $callback Receives the node array, returns a node array or null.
 * @param bool     $hit      Set by reference when the target is found.
 * @return array Rebuilt tree.
 */
function omatic_el_apply( array $nodes, $id, callable $callback, &$hit = false ) {
	foreach ( $nodes as $index => $node ) {
		if ( isset( $node['id'] ) && (string) $node['id'] === (string) $id ) {
			$result = call_user_func( $callback, $node );
			$hit    = true;
			if ( null === $result ) {
				unset( $nodes[ $index ] );
			} else {
				$nodes[ $index ] = $result;
			}
			return array_values( $nodes );
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$nodes[ $index ]['elements'] = omatic_el_apply( $node['elements'], $id, $callback, $hit );
			if ( $hit ) {
				return $nodes;
			}
		}
	}
	return $nodes;
}

/**
 * Locate a node without mutating the tree.
 *
 * @param array  $nodes Element tree.
 * @param string $id    Target element id.
 * @return array|null
 */
function omatic_el_locate( array $nodes, $id ) {
	foreach ( $nodes as $node ) {
		if ( isset( $node['id'] ) && (string) $node['id'] === (string) $id ) {
			return $node;
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$found = omatic_el_locate( $node['elements'], $id );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Insert a node under a parent id at an optional index. Passing an empty
 * parent appends at the top level.
 *
 * @param array       $nodes     Element tree.
 * @param string|null $parent_id Parent element id, or null/'' for top level.
 * @param array       $new_node  Node to insert.
 * @param int|null    $position  Zero-based index, or null to append.
 * @param bool        $hit       Set by reference when inserted.
 * @return array
 */
function omatic_el_insert( array $nodes, $parent_id, array $new_node, $position = null, &$hit = false ) {
	if ( empty( $parent_id ) ) {
		if ( null === $position || $position >= count( $nodes ) ) {
			$nodes[] = $new_node;
		} else {
			array_splice( $nodes, max( 0, (int) $position ), 0, array( $new_node ) );
		}
		$hit = true;
		return $nodes;
	}

	foreach ( $nodes as $index => $node ) {
		if ( isset( $node['id'] ) && (string) $node['id'] === (string) $parent_id ) {
			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array();
			if ( null === $position || $position >= count( $children ) ) {
				$children[] = $new_node;
			} else {
				array_splice( $children, max( 0, (int) $position ), 0, array( $new_node ) );
			}
			$nodes[ $index ]['elements'] = $children;
			$hit                          = true;
			return $nodes;
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$nodes[ $index ]['elements'] = omatic_el_insert( $node['elements'], $parent_id, $new_node, $position, $hit );
			if ( $hit ) {
				return $nodes;
			}
		}
	}
	return $nodes;
}

/**
 * Fresh Elementor-style element id: 7 lowercase hex characters.
 *
 * @return string
 */
function omatic_el_new_id() {
	return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
}

/**
 * Recursively re-id a node and its children, so a duplicated or imported
 * subtree never collides with the element it came from.
 *
 * @param array $node Node to re-id.
 * @return array
 */
function omatic_el_reid( array $node ) {
	$node['id'] = omatic_el_new_id();
	if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
		foreach ( $node['elements'] as $i => $child ) {
			if ( is_array( $child ) ) {
				$node['elements'][ $i ] = omatic_el_reid( $child );
			}
		}
	}
	return $node;
}

/**
 * Short human label for a node, so a structure listing is readable without
 * dumping the whole settings object.
 *
 * @param array $node Element node.
 * @return string
 */
function omatic_el_label( array $node ) {
	$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

	foreach ( array( 'title', 'editor', 'text', 'html', 'menu_name', 'caption' ) as $key ) {
		if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
			$plain = trim( wp_strip_all_tags( $settings[ $key ] ) );
			if ( '' !== $plain ) {
				return mb_substr( $plain, 0, 70 );
			}
		}
	}
	if ( ! empty( $settings['image']['url'] ) ) {
		return basename( (string) $settings['image']['url'] );
	}
	if ( ! empty( $settings['background_color'] ) ) {
		return 'bg ' . $settings['background_color'];
	}
	return '';
}

/**
 * Compact, depth-limited view of the tree.
 *
 * @param array $nodes Element tree.
 * @param int   $depth Current depth.
 * @param int   $max   Maximum depth to expand.
 * @return array
 */
function omatic_el_summarize( array $nodes, $depth = 0, $max = 6 ) {
	$out = array();
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}
		$row = array(
			'id'   => isset( $node['id'] ) ? $node['id'] : null,
			'type' => isset( $node['elType'] ) ? $node['elType'] : null,
		);
		if ( ! empty( $node['widgetType'] ) ) {
			$row['widget'] = $node['widgetType'];
		}
		$label = omatic_el_label( $node );
		if ( '' !== $label ) {
			$row['label'] = $label;
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			if ( $depth < $max ) {
				$row['children'] = omatic_el_summarize( $node['elements'], $depth + 1, $max );
			} else {
				$row['children_count'] = count( $node['elements'] );
			}
		}
		$out[] = $row;
	}
	return $out;
}

/**
 * Flatten the tree for searching.
 *
 * @param array  $nodes Element tree.
 * @param string $path  Accumulated ancestor path.
 * @return array
 */
function omatic_el_flatten( array $nodes, $path = '' ) {
	$out = array();
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) || ! isset( $node['id'] ) ) {
			continue;
		}
		$here  = $path ? $path . ' > ' . $node['id'] : (string) $node['id'];
		$out[] = array(
			'id'     => $node['id'],
			'type'   => isset( $node['elType'] ) ? $node['elType'] : null,
			'widget' => isset( $node['widgetType'] ) ? $node['widgetType'] : null,
			'label'  => omatic_el_label( $node ),
			'path'   => $here,
			'node'   => $node,
		);
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$out = array_merge( $out, omatic_el_flatten( $node['elements'], $here ) );
		}
	}
	return $out;
}

// ─────────────────────────────────────────────
// HELPERS — snapshots
// ─────────────────────────────────────────────

/**
 * Store the current _elementor_data in a rolling snapshot slot.
 *
 * @param int    $post_id Post ID.
 * @param string $reason  Short label.
 * @return string|false Snapshot key, or false when there was nothing to snapshot.
 */
function omatic_el_take_snapshot( $post_id, $reason = 'edit' ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $raw ) ) {
		return false;
	}
	return omatic_snapshot_push(
		$post_id,
		OMATIC_EL_SNAPSHOT_META,
		OMATIC_EL_SNAPSHOT_MAX,
		$reason,
		array(
			'bytes' => strlen( (string) $raw ),
			'data'  => $raw,
		)
	);
}

// ─────────────────────────────────────────────
// HELPERS — SVG sanitising
// ─────────────────────────────────────────────

/**
 * Sanitize SVG markup. Kept as the Elementor-facing name; the work is the
 * parser-based allowlist sanitizer in includes/svg-sanitizer.php (task #1013,
 * which replaced a regex sanitizer that passed three measured XSS payloads).
 *
 * @param string $svg Raw SVG markup.
 * @return string|WP_Error Sanitized markup, or an error.
 */
function omatic_el_sanitize_svg( $svg ) {
	return omatic_svg_sanitize( $svg );
}

// ─────────────────────────────────────────────
// ABILITY REGISTRATION
// ─────────────────────────────────────────────

function omatic_el_register_abilities() {

	$post_id_prop = array( 'type' => 'integer', 'description' => 'Post, page or Elementor template ID.' );

	// ---- Discovery ----------------------------------------------------

	wp_register_ability(
		'omatic/elementor-detect',
		array(
			'label'               => 'Detect Elementor',
			'description'         => 'Report Elementor and Elementor Pro versions, whether atomic elements (Elementor 4.0+) are available, and the active kit ID.',
			'category'            => 'elementor',
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'execute_callback'    => 'omatic_cb_el_detect',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_read',
		)
	);

	wp_register_ability(
		'omatic/elementor-list-pages',
		array(
			'label'               => 'List Elementor Pages',
			'description'         => 'List posts, pages and custom post types that are built with Elementor.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array( 'type' => 'string', 'description' => 'Restrict to one post type. Omit for any.' ),
					'search'    => array( 'type' => 'string', 'description' => 'Match against the title.' ),
					'limit'     => array( 'type' => 'integer', 'description' => 'Default 50, max 200.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_list_pages',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-list-templates',
		array(
			'label'               => 'List Elementor Templates',
			'description'         => 'List Elementor library templates — headers, footers, single, archive, popups, sections — with their template type and display conditions.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'template_type' => array( 'type' => 'string', 'description' => 'Filter, e.g. header, footer, single-post, archive, popup, kit.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_list_templates',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-get-structure',
		array(
			'label'               => 'Get Elementor Structure',
			'description'         => 'Return the element tree for a post as a compact, depth-limited outline: element IDs, types, widget types and a short label. Use this before editing, not the raw meta.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => $post_id_prop,
					'depth'   => array( 'type' => 'integer', 'description' => 'Levels to expand. Default 6.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_get_structure',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-find-element',
		array(
			'label'               => 'Find Elementor Element',
			'description'         => 'Search a page for elements by widget type, element type, or text content. Returns matching element IDs with their ancestor path.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array(
					'post_id' => $post_id_prop,
					'widget'  => array( 'type' => 'string', 'description' => 'Widget type, e.g. image, heading, text-editor, nav-menu.' ),
					'el_type' => array( 'type' => 'string', 'description' => 'Element type: container, section, column or widget.' ),
					'text'    => array( 'type' => 'string', 'description' => 'Case-insensitive substring to match in settings.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_find_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-get-element',
		array(
			'label'               => 'Get Elementor Element',
			'description'         => 'Return the full settings object for a single element by ID.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'element_id' ),
				'properties' => array(
					'post_id'    => $post_id_prop,
					'element_id' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_get_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-get-global-settings',
		array(
			'label'               => 'Get Elementor Global Settings',
			'description'         => 'Return the active kit: global colour palette, typography presets and site-wide layout settings.',
			'category'            => 'elementor',
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'execute_callback'    => 'omatic_cb_el_get_global_settings',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	// ---- Element writes -----------------------------------------------

	wp_register_ability(
		'omatic/elementor-update-element',
		array(
			'label'               => 'Update Elementor Element',
			'description'         => 'Deep-merge settings into one element by ID. Preserves every key you do not mention, and every sibling. Snapshots first and flushes Elementor CSS after.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'element_id', 'settings' ),
				'properties' => array(
					'post_id'    => $post_id_prop,
					'element_id' => array( 'type' => 'string' ),
					'settings'   => array( 'type' => 'object', 'description' => 'Partial settings to merge.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_update_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-batch-update',
		array(
			'label'               => 'Batch Update Elementor Elements',
			'description'         => 'Apply several element updates to one page in a single write, with one snapshot and one CSS flush. Prefer this over repeated single updates.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'updates' ),
				'properties' => array(
					'post_id' => $post_id_prop,
					'updates' => array(
						'type'        => 'array',
						'description' => 'Array of { element_id, settings } objects.',
						'items'       => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'omatic_cb_el_batch_update',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-add-element',
		array(
			'label'               => 'Add Elementor Element',
			'description'         => 'Insert a new widget or container under a parent element. Omit parent_id to append at the top level.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'el_type' ),
				'properties' => array(
					'post_id'     => $post_id_prop,
					'el_type'     => array( 'type' => 'string', 'description' => 'container or widget.' ),
					'widget_type' => array( 'type' => 'string', 'description' => 'Required when el_type is widget, e.g. heading, image.' ),
					'settings'    => array( 'type' => 'object' ),
					'parent_id'   => array( 'type' => 'string', 'description' => 'Parent element ID. Omit for top level.' ),
					'position'    => array( 'type' => 'integer', 'description' => 'Zero-based index among siblings. Omit to append.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_add_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-remove-element',
		array(
			'label'               => 'Remove Elementor Element',
			'description'         => 'Delete an element and its children by ID. Snapshots first — restore with elementor-restore-snapshot.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'element_id' ),
				'properties' => array(
					'post_id'    => $post_id_prop,
					'element_id' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_remove_element',
			'meta'                => omatic_el_meta( true ),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-duplicate-element',
		array(
			'label'               => 'Duplicate Elementor Element',
			'description'         => 'Copy an element and its children in place, assigning fresh IDs throughout so nothing collides.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'element_id' ),
				'properties' => array(
					'post_id'    => $post_id_prop,
					'element_id' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_duplicate_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-move-element',
		array(
			'label'               => 'Move Elementor Element',
			'description'         => 'Move an element to a new parent and/or position, keeping its IDs and settings intact.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'element_id' ),
				'properties' => array(
					'post_id'   => $post_id_prop,
					'element_id' => array( 'type' => 'string' ),
					'parent_id' => array( 'type' => 'string', 'description' => 'New parent ID. Omit for top level.' ),
					'position'  => array( 'type' => 'integer', 'description' => 'Zero-based index among the new siblings.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_move_element',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	// ---- Page and global ----------------------------------------------

	wp_register_ability(
		'omatic/elementor-update-page-settings',
		array(
			'label'               => 'Update Elementor Page Settings',
			'description'         => 'Merge into a page\'s Elementor settings — page layout, background, custom CSS class, hide title.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'settings' ),
				'properties' => array(
					'post_id'  => $post_id_prop,
					'settings' => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_update_page_settings',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-update-global-colors',
		array(
			'label'               => 'Update Elementor Global Colors',
			'description'         => 'Update the active kit\'s global colour palette by colour ID or title. Site-wide — every element bound to a global colour changes.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'colors' ),
				'properties' => array(
					'colors' => array(
						'type'        => 'array',
						'description' => 'Array of { _id or title, color } objects. Colours are hex, e.g. #E53935.',
						'items'       => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'omatic_cb_el_update_global_colors',
			'meta'                => omatic_el_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	wp_register_ability(
		'omatic/elementor-update-global-typography',
		array(
			'label'               => 'Update Elementor Global Typography',
			'description'         => 'Update the active kit\'s typography presets — family, weight, size, line height — by preset ID or title. Site-wide.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'typography' ),
				'properties' => array(
					'typography' => array(
						'type'        => 'array',
						'description' => 'Array of { _id or title, ...typography_* keys } objects.',
						'items'       => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'omatic_cb_el_update_global_typography',
			'meta'                => omatic_el_meta( true ),
			'permission_callback' => 'omatic_perm_edit_theme_options',
		)
	);

	// ---- Transfer ------------------------------------------------------

	wp_register_ability(
		'omatic/elementor-export-page',
		array(
			'label'               => 'Export Elementor Page',
			'description'         => 'Return the raw decoded element tree for a page, for backup or transfer to another page.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array( 'post_id' => $post_id_prop ),
			),
			'execute_callback'    => 'omatic_cb_el_export_page',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-import-page',
		array(
			'label'               => 'Import Elementor Page',
			'description'         => 'Replace a page\'s entire element tree. Destructive — snapshots first, and re-IDs the incoming tree by default so it can coexist with its source.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'elements' ),
				'properties' => array(
					'post_id'  => $post_id_prop,
					'elements' => array( 'type' => 'array', 'description' => 'Element tree, as returned by elementor-export-page.' ),
					'keep_ids' => array( 'type' => 'boolean', 'description' => 'Keep incoming element IDs instead of regenerating. Default false.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_import_page',
			'meta'                => omatic_el_meta( true ),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	// ---- Assets ---------------------------------------------------------

	wp_register_ability(
		'omatic/elementor-upload-svg',
		array(
			'label'               => 'Upload SVG',
			'description'         => 'Sanitise and upload an SVG into the media library from raw markup or a URL. WordPress blocks SVG upload by default; this parses it and keeps only an allowlist of SVG elements and attributes (no scripts, event handlers, foreignObject or external references). A url is fetched with wp_safe_remote_get (public http(s) hosts only, 2 MiB cap).',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'title' ),
				'properties' => array(
					'title'   => array( 'type' => 'string', 'description' => 'Used for the filename and attachment title.' ),
					'svg'     => array( 'type' => 'string', 'description' => 'Raw SVG markup. Provide this or url.' ),
					'url'     => array( 'type' => 'string', 'description' => 'Remote SVG to fetch. Provide this or svg.' ),
					'alt'     => array( 'type' => 'string', 'description' => 'Alt text. Strongly recommended.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_upload_svg',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_upload',
		)
	);

	// ---- Safety ---------------------------------------------------------

	wp_register_ability(
		'omatic/elementor-list-snapshots',
		array(
			'label'               => 'List Elementor Snapshots',
			'description'         => 'List automatic pre-write snapshots held for a page, newest last.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id' ),
				'properties' => array( 'post_id' => $post_id_prop ),
			),
			'execute_callback'    => 'omatic_cb_el_list_snapshots',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-restore-snapshot',
		array(
			'label'               => 'Restore Elementor Snapshot',
			'description'         => 'Restore a page\'s element tree from a snapshot. Takes a snapshot of the current state first, so a restore is itself undoable.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'snapshot_key' ),
				'properties' => array(
					'post_id'      => $post_id_prop,
					'snapshot_key' => array( 'type' => 'string', 'description' => 'Key from elementor-list-snapshots. Use "latest" for the most recent.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_restore_snapshot',
			'meta'                => omatic_el_meta( true ),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);

	wp_register_ability(
		'omatic/elementor-flush-css',
		array(
			'label'               => 'Flush Elementor CSS',
			'description'         => 'Clear Elementor\'s compiled CSS cache. Use when an edit landed in the data but the rendered page still looks stale.',
			'category'            => 'elementor',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'Regenerate this post\'s CSS too. Omit for a global flush.' ),
				),
			),
			'execute_callback'    => 'omatic_cb_el_flush_css',
			'meta'                => omatic_el_meta(),
			'permission_callback' => 'omatic_perm_edit_posts',
		)
	);
}

// ─────────────────────────────────────────────
// CALLBACKS
// ─────────────────────────────────────────────

function omatic_cb_el_detect( $input ) {
	$kit_id = (int) get_option( 'elementor_active_kit' );
	return array(
		'elementor_active'  => defined( 'ELEMENTOR_VERSION' ),
		'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
		'pro_active'        => defined( 'ELEMENTOR_PRO_VERSION' ),
		'pro_version'       => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		'atomic_supported'  => defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '4.0', '>=' ),
		'active_kit_id'     => $kit_id ? $kit_id : null,
		'wp_version'        => get_bloginfo( 'version' ),
	);
}

function omatic_cb_el_list_pages( $input ) {
	$limit = isset( $input['limit'] ) ? min( 200, max( 1, (int) $input['limit'] ) ) : 50;

	$args = array(
		'post_type'      => ! empty( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'any',
		'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
		'posts_per_page' => $limit,
		'meta_key'       => '_elementor_edit_mode',
		'meta_value'     => 'builder',
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	if ( ! empty( $input['search'] ) ) {
		$args['s'] = sanitize_text_field( $input['search'] );
	}

	$results = array();
	foreach ( get_posts( $args ) as $post ) {
		$results[] = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'modified' => $post->post_modified_gmt,
			'url'      => get_permalink( $post ),
		);
	}
	return array( 'count' => count( $results ), 'pages' => $results );
}

function omatic_cb_el_list_templates( $input ) {
	$posts = get_posts(
		array(
			'post_type'      => 'elementor_library',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 200,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	$filter    = ! empty( $input['template_type'] ) ? sanitize_key( $input['template_type'] ) : '';
	$templates = array();

	foreach ( $posts as $post ) {
		$type = get_post_meta( $post->ID, '_elementor_template_type', true );
		if ( $filter && $type !== $filter ) {
			continue;
		}
		$conditions  = get_post_meta( $post->ID, '_elementor_conditions', true );
		$templates[] = array(
			'id'            => $post->ID,
			'title'         => $post->post_title,
			'template_type' => $type ? $type : null,
			'status'        => $post->post_status,
			'conditions'    => is_array( $conditions ) ? $conditions : array(),
		);
	}
	return array( 'count' => count( $templates ), 'templates' => $templates );
}

function omatic_cb_el_get_structure( $input ) {
	$post_id = absint( $input['post_id'] );
	if ( ! get_post( $post_id ) ) {
		return array( 'error' => 'Post not found.' );
	}
	$data = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'This post has no Elementor data. It may not be built with Elementor.', 'post_id' => $post_id );
	}
	$depth = isset( $input['depth'] ) ? max( 1, (int) $input['depth'] ) : 6;

	return array(
		'post_id'   => $post_id,
		'title'     => get_the_title( $post_id ),
		'top_level' => count( $data ),
		'structure' => omatic_el_summarize( $data, 0, $depth ),
	);
}

function omatic_cb_el_find_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}

	$widget  = ! empty( $input['widget'] ) ? sanitize_text_field( $input['widget'] ) : '';
	$el_type = ! empty( $input['el_type'] ) ? sanitize_text_field( $input['el_type'] ) : '';
	$text    = ! empty( $input['text'] ) ? (string) $input['text'] : '';

	$matches = array();
	foreach ( omatic_el_flatten( $data ) as $row ) {
		if ( $widget && $row['widget'] !== $widget ) {
			continue;
		}
		if ( $el_type && $row['type'] !== $el_type ) {
			continue;
		}
		if ( '' !== $text ) {
			$haystack = wp_json_encode( isset( $row['node']['settings'] ) ? $row['node']['settings'] : array() );
			if ( false === stripos( (string) $haystack, $text ) ) {
				continue;
			}
		}
		unset( $row['node'] );
		$matches[] = $row;
	}
	return array( 'post_id' => $post_id, 'count' => count( $matches ), 'matches' => $matches );
}

function omatic_cb_el_get_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}
	$node = omatic_el_locate( $data, (string) $input['element_id'] );
	if ( null === $node ) {
		return array( 'error' => 'Element not found on this post.', 'element_id' => $input['element_id'] );
	}
	return array( 'post_id' => $post_id, 'element' => $node );
}

function omatic_cb_el_get_global_settings( $input ) {
	$kit_id = (int) get_option( 'elementor_active_kit' );
	if ( ! $kit_id ) {
		return array( 'error' => 'No active Elementor kit found.' );
	}
	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	if ( ! is_array( $settings ) ) {
		return array( 'error' => 'Active kit has no settings.', 'kit_id' => $kit_id );
	}
	return array(
		'kit_id'            => $kit_id,
		'system_colors'     => isset( $settings['system_colors'] ) ? $settings['system_colors'] : array(),
		'custom_colors'     => isset( $settings['custom_colors'] ) ? $settings['custom_colors'] : array(),
		'system_typography' => isset( $settings['system_typography'] ) ? $settings['system_typography'] : array(),
		'all_settings_keys' => array_keys( $settings ),
	);
}

function omatic_cb_el_update_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}
	if ( ! is_array( $input['settings'] ) ) {
		return array( 'error' => 'settings must be an object.' );
	}

	$patch = $input['settings'];
	$hit   = false;
	$data  = omatic_el_apply(
		$data,
		(string) $input['element_id'],
		function ( $node ) use ( $patch ) {
			$existing         = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			$node['settings'] = omatic_el_merge( $existing, $patch );
			return $node;
		},
		$hit
	);

	if ( ! $hit ) {
		return array( 'error' => 'Element not found on this post.', 'element_id' => $input['element_id'] );
	}

	$saved = omatic_el_save_data( $post_id, $data, 'update-element ' . $input['element_id'] );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $input['element_id'], 'merged_keys' => array_keys( $patch ) );
}

function omatic_cb_el_batch_update( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}
	if ( ! is_array( $input['updates'] ) ) {
		return array( 'error' => 'updates must be an array.' );
	}

	$applied = array();
	$missing = array();

	foreach ( $input['updates'] as $update ) {
		if ( empty( $update['element_id'] ) || ! isset( $update['settings'] ) || ! is_array( $update['settings'] ) ) {
			$missing[] = array( 'element_id' => isset( $update['element_id'] ) ? $update['element_id'] : null, 'reason' => 'element_id and settings object are both required.' );
			continue;
		}
		$patch = $update['settings'];
		$hit   = false;
		$data  = omatic_el_apply(
			$data,
			(string) $update['element_id'],
			function ( $node ) use ( $patch ) {
				$existing         = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
				$node['settings'] = omatic_el_merge( $existing, $patch );
				return $node;
			},
			$hit
		);
		if ( $hit ) {
			$applied[] = $update['element_id'];
		} else {
			$missing[] = array( 'element_id' => $update['element_id'], 'reason' => 'not found' );
		}
	}

	if ( empty( $applied ) ) {
		return array( 'error' => 'No updates applied.', 'not_applied' => $missing );
	}

	$saved = omatic_el_save_data( $post_id, $data, 'batch-update x' . count( $applied ) );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'applied' => $applied, 'not_applied' => $missing );
}

function omatic_cb_el_add_element( $input ) {
	$post_id = absint( $input['post_id'] );
	if ( ! get_post( $post_id ) ) {
		return array( 'error' => 'Post not found.' );
	}
	$data    = omatic_el_get_data( $post_id );
	$el_type = sanitize_text_field( $input['el_type'] );

	$node = array(
		'id'       => omatic_el_new_id(),
		'elType'   => $el_type,
		'settings' => isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array(),
		'elements' => array(),
	);
	if ( 'widget' === $el_type ) {
		if ( empty( $input['widget_type'] ) ) {
			return array( 'error' => 'widget_type is required when el_type is widget.' );
		}
		$node['widgetType'] = sanitize_text_field( $input['widget_type'] );
	}

	$parent_id = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
	$position  = isset( $input['position'] ) ? (int) $input['position'] : null;

	if ( '' !== $parent_id && null === omatic_el_locate( $data, $parent_id ) ) {
		return array( 'error' => 'Parent element not found.', 'parent_id' => $parent_id );
	}

	$hit  = false;
	$data = omatic_el_insert( $data, $parent_id, $node, $position, $hit );
	if ( ! $hit ) {
		return array( 'error' => 'Could not insert the element.' );
	}

	$saved = omatic_el_save_data( $post_id, $data, 'add-element ' . $el_type );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $node['id'], 'parent_id' => $parent_id ? $parent_id : null );
}

function omatic_cb_el_remove_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}

	$hit  = false;
	$data = omatic_el_apply( $data, (string) $input['element_id'], function () { return null; }, $hit );
	if ( ! $hit ) {
		return array( 'error' => 'Element not found on this post.', 'element_id' => $input['element_id'] );
	}

	$saved = omatic_el_save_data( $post_id, $data, 'remove-element ' . $input['element_id'] );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'removed' => $input['element_id'], 'undo' => 'omatic/elementor-restore-snapshot with snapshot_key=latest' );
}

function omatic_cb_el_duplicate_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}
	$source = omatic_el_locate( $data, (string) $input['element_id'] );
	if ( null === $source ) {
		return array( 'error' => 'Element not found on this post.' );
	}

	$copy = omatic_el_reid( $source );

	// Insert the copy directly after its source, wherever in the tree that is.
	$data = omatic_el_insert_after( $data, (string) $input['element_id'], $copy );

	$saved = omatic_el_save_data( $post_id, $data, 'duplicate-element ' . $input['element_id'] );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'source_id' => $input['element_id'], 'new_id' => $copy['id'] );
}

/**
 * Insert a node immediately after the node with the given id.
 *
 * @param array  $nodes    Element tree.
 * @param string $after_id Sibling to insert after.
 * @param array  $new_node Node to insert.
 * @return array
 */
function omatic_el_insert_after( array $nodes, $after_id, array $new_node ) {
	foreach ( $nodes as $index => $node ) {
		if ( isset( $node['id'] ) && (string) $node['id'] === (string) $after_id ) {
			array_splice( $nodes, $index + 1, 0, array( $new_node ) );
			return $nodes;
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$before                       = wp_json_encode( $node['elements'] );
			$nodes[ $index ]['elements'] = omatic_el_insert_after( $node['elements'], $after_id, $new_node );
			if ( wp_json_encode( $nodes[ $index ]['elements'] ) !== $before ) {
				return $nodes;
			}
		}
	}
	return $nodes;
}

function omatic_cb_el_move_element( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}

	$element_id = (string) $input['element_id'];
	$node       = omatic_el_locate( $data, $element_id );
	if ( null === $node ) {
		return array( 'error' => 'Element not found on this post.' );
	}

	$parent_id = isset( $input['parent_id'] ) ? (string) $input['parent_id'] : '';
	if ( $parent_id === $element_id ) {
		return array( 'error' => 'An element cannot be its own parent.' );
	}
	if ( '' !== $parent_id && null === omatic_el_locate( $data, $parent_id ) ) {
		return array( 'error' => 'Target parent not found.', 'parent_id' => $parent_id );
	}
	// Refuse to move a node inside its own subtree.
	if ( '' !== $parent_id && null !== omatic_el_locate( array( $node ), $parent_id ) ) {
		return array( 'error' => 'Refusing to move an element inside its own subtree.' );
	}

	$removed = false;
	$data    = omatic_el_apply( $data, $element_id, function () { return null; }, $removed );
	if ( ! $removed ) {
		return array( 'error' => 'Could not detach the element.' );
	}

	$hit  = false;
	$data = omatic_el_insert( $data, $parent_id, $node, isset( $input['position'] ) ? (int) $input['position'] : null, $hit );
	if ( ! $hit ) {
		return array( 'error' => 'Could not attach the element to the new parent.' );
	}

	$saved = omatic_el_save_data( $post_id, $data, 'move-element ' . $element_id );
	if ( true !== $saved ) {
		return $saved;
	}
	return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $element_id, 'new_parent' => $parent_id ? $parent_id : 'top-level' );
}

function omatic_cb_el_update_page_settings( $input ) {
	$post_id = absint( $input['post_id'] );
	if ( ! get_post( $post_id ) ) {
		return array( 'error' => 'Post not found.' );
	}
	if ( ! is_array( $input['settings'] ) ) {
		return array( 'error' => 'settings must be an object.' );
	}

	$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	$merged = omatic_el_merge( $existing, $input['settings'] );

	update_post_meta( $post_id, '_elementor_page_settings', $merged );
	omatic_el_flush_css( $post_id );

	return array( 'success' => true, 'post_id' => $post_id, 'merged_keys' => array_keys( $input['settings'] ) );
}

function omatic_cb_el_update_global_colors( $input ) {
	$kit_id = (int) get_option( 'elementor_active_kit' );
	if ( ! $kit_id ) {
		return array( 'error' => 'No active Elementor kit found.' );
	}
	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	if ( ! is_array( $settings ) ) {
		return array( 'error' => 'Active kit has no settings.' );
	}
	if ( ! is_array( $input['colors'] ) ) {
		return array( 'error' => 'colors must be an array.' );
	}

	$updated = array();
	foreach ( array( 'system_colors', 'custom_colors' ) as $bucket ) {
		if ( empty( $settings[ $bucket ] ) || ! is_array( $settings[ $bucket ] ) ) {
			continue;
		}
		foreach ( $settings[ $bucket ] as $i => $entry ) {
			foreach ( $input['colors'] as $incoming ) {
				if ( empty( $incoming['color'] ) ) {
					continue;
				}
				$id_match    = ! empty( $incoming['_id'] ) && isset( $entry['_id'] ) && $entry['_id'] === $incoming['_id'];
				$title_match = ! empty( $incoming['title'] ) && isset( $entry['title'] ) && 0 === strcasecmp( $entry['title'], $incoming['title'] );
				if ( $id_match || $title_match ) {
					$settings[ $bucket ][ $i ]['color'] = sanitize_hex_color( $incoming['color'] ) ? sanitize_hex_color( $incoming['color'] ) : $entry['color'];
					$updated[]                          = array( 'bucket' => $bucket, '_id' => isset( $entry['_id'] ) ? $entry['_id'] : null, 'color' => $settings[ $bucket ][ $i ]['color'] );
				}
			}
		}
	}

	if ( empty( $updated ) ) {
		return array( 'error' => 'No matching global colours found. Call elementor-get-global-settings for valid _id and title values.' );
	}

	update_post_meta( $kit_id, '_elementor_page_settings', $settings );
	omatic_el_flush_css( $kit_id );

	return array( 'success' => true, 'kit_id' => $kit_id, 'updated' => $updated );
}

function omatic_cb_el_update_global_typography( $input ) {
	$kit_id = (int) get_option( 'elementor_active_kit' );
	if ( ! $kit_id ) {
		return array( 'error' => 'No active Elementor kit found.' );
	}
	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	if ( ! is_array( $settings ) || empty( $settings['system_typography'] ) ) {
		return array( 'error' => 'Active kit has no typography presets.' );
	}
	if ( ! is_array( $input['typography'] ) ) {
		return array( 'error' => 'typography must be an array.' );
	}

	$updated = array();
	foreach ( $settings['system_typography'] as $i => $entry ) {
		foreach ( $input['typography'] as $incoming ) {
			$id_match    = ! empty( $incoming['_id'] ) && isset( $entry['_id'] ) && $entry['_id'] === $incoming['_id'];
			$title_match = ! empty( $incoming['title'] ) && isset( $entry['title'] ) && 0 === strcasecmp( $entry['title'], $incoming['title'] );
			if ( ! $id_match && ! $title_match ) {
				continue;
			}
			foreach ( $incoming as $key => $value ) {
				if ( in_array( $key, array( '_id', 'title' ), true ) ) {
					continue;
				}
				$settings['system_typography'][ $i ][ sanitize_key( $key ) ] = $value;
			}
			$updated[] = isset( $entry['_id'] ) ? $entry['_id'] : $i;
		}
	}

	if ( empty( $updated ) ) {
		return array( 'error' => 'No matching typography presets found. Call elementor-get-global-settings for valid _id and title values.' );
	}

	update_post_meta( $kit_id, '_elementor_page_settings', $settings );
	omatic_el_flush_css( $kit_id );

	return array( 'success' => true, 'kit_id' => $kit_id, 'updated' => $updated );
}

function omatic_cb_el_export_page( $input ) {
	$post_id = absint( $input['post_id'] );
	$data    = omatic_el_get_data( $post_id );
	if ( empty( $data ) ) {
		return array( 'error' => 'No Elementor data for this post.' );
	}
	return array(
		'post_id'   => $post_id,
		'title'     => get_the_title( $post_id ),
		'top_level' => count( $data ),
		'elements'  => $data,
	);
}

function omatic_cb_el_import_page( $input ) {
	$post_id = absint( $input['post_id'] );
	if ( ! get_post( $post_id ) ) {
		return array( 'error' => 'Post not found.' );
	}
	if ( ! is_array( $input['elements'] ) ) {
		return array( 'error' => 'elements must be an array.' );
	}

	$elements = $input['elements'];
	$keep_ids = ! empty( $input['keep_ids'] );
	if ( ! $keep_ids ) {
		foreach ( $elements as $i => $node ) {
			if ( is_array( $node ) ) {
				$elements[ $i ] = omatic_el_reid( $node );
			}
		}
	}

	$saved = omatic_el_save_data( $post_id, $elements, 'import-page' );
	if ( true !== $saved ) {
		return $saved;
	}
	return array(
		'success'   => true,
		'post_id'   => $post_id,
		'top_level' => count( $elements ),
		're_ided'   => ! $keep_ids,
		'undo'      => 'omatic/elementor-restore-snapshot with snapshot_key=latest',
	);
}

function omatic_cb_el_upload_svg( $input ) {
	$title = sanitize_text_field( $input['title'] );
	if ( '' === $title ) {
		return array( 'error' => 'title is required.' );
	}

	$svg = '';
	if ( ! empty( $input['svg'] ) ) {
		$svg = (string) $input['svg'];
	} elseif ( ! empty( $input['url'] ) ) {
		$url = esc_url_raw( $input['url'] );
		if ( ! wp_http_validate_url( $url ) ) {
			return array( 'error' => 'url is not a valid, fetchable URL.' );
		}
		// wp_safe_remote_get() sets reject_unsafe_urls, so the caller cannot
		// point this at loopback, private-range or non-http(s) targets (SSRF),
		// and the body is capped: an SVG larger than this is refused below.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => OMATIC_EL_SVG_MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Could not fetch url: ' . $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array( 'error' => 'Fetching url returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}
		$svg = wp_remote_retrieve_body( $response );
		if ( strlen( $svg ) > OMATIC_EL_SVG_MAX_BYTES ) {
			return array( 'error' => 'Fetched SVG exceeds ' . OMATIC_EL_SVG_MAX_BYTES . ' bytes; refusing.' );
		}
	} else {
		return array( 'error' => 'Provide either svg markup or a url.' );
	}

	$clean = omatic_el_sanitize_svg( $svg );
	if ( is_wp_error( $clean ) ) {
		return array( 'error' => $clean->get_error_message() );
	}

	$filename = sanitize_file_name( $title );
	if ( '.svg' !== strtolower( substr( $filename, -4 ) ) ) {
		$filename .= '.svg';
	}

	// WordPress rejects SVG uploads by default. Allow it for this call only.
	$allow_svg = function ( $mimes ) {
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	};
	add_filter( 'upload_mimes', $allow_svg );

	$upload = wp_upload_bits( $filename, null, $clean );

	remove_filter( 'upload_mimes', $allow_svg );

	if ( ! empty( $upload['error'] ) ) {
		return array( 'error' => 'Upload failed: ' . $upload['error'] );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		return array( 'error' => 'Could not create the media library attachment.' );
	}

	// SVGs have no generated sizes; record intrinsic dimensions where present.
	$meta = array( 'file' => _wp_relative_upload_path( $upload['file'] ), 'filesize' => strlen( $clean ) );
	if ( preg_match( '#viewBox\s*=\s*"[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)"#i', $clean, $m ) ) {
		$meta['width']  = (int) round( (float) $m[1] );
		$meta['height'] = (int) round( (float) $m[2] );
	}
	wp_update_attachment_metadata( $attachment_id, $meta );

	if ( ! empty( $input['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
	}

	return array(
		'success'       => true,
		'attachment_id' => $attachment_id,
		'url'           => wp_get_attachment_url( $attachment_id ),
		'bytes'         => strlen( $clean ),
		'alt'           => ! empty( $input['alt'] ) ? sanitize_text_field( $input['alt'] ) : null,
	);
}

function omatic_cb_el_list_snapshots( $input ) {
	$post_id   = absint( $input['post_id'] );
	$snapshots = get_post_meta( $post_id, OMATIC_EL_SNAPSHOT_META, true );
	if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
		return array( 'post_id' => $post_id, 'count' => 0, 'snapshots' => array() );
	}

	$list = array();
	foreach ( $snapshots as $key => $snap ) {
		$list[] = array(
			'key'      => $key,
			'taken_at' => isset( $snap['taken_at'] ) ? $snap['taken_at'] : null,
			'reason'   => isset( $snap['reason'] ) ? $snap['reason'] : null,
			'bytes'    => isset( $snap['bytes'] ) ? $snap['bytes'] : null,
		);
	}
	return array( 'post_id' => $post_id, 'count' => count( $list ), 'max_held' => OMATIC_EL_SNAPSHOT_MAX, 'snapshots' => $list );
}

function omatic_cb_el_restore_snapshot( $input ) {
	$post_id   = absint( $input['post_id'] );
	$snapshots = get_post_meta( $post_id, OMATIC_EL_SNAPSHOT_META, true );
	if ( ! is_array( $snapshots ) || empty( $snapshots ) ) {
		return array( 'error' => 'No snapshots held for this post.' );
	}

	$key = (string) $input['snapshot_key'];
	if ( 'latest' === $key ) {
		end( $snapshots );
		$key = key( $snapshots );
	}
	if ( ! isset( $snapshots[ $key ] ) ) {
		return array( 'error' => 'Snapshot not found.', 'available' => array_keys( $snapshots ) );
	}

	// Never write a payload that will not parse. A restore that lands corrupt
	// data is worse than a restore that refuses, because it destroys the very
	// state the caller was trying to get back to.
	$payload = isset( $snapshots[ $key ]['data'] ) ? $snapshots[ $key ]['data'] : '';
	if ( '' === $payload || ! is_array( json_decode( $payload, true ) ) ) {
		return array(
			'error'        => 'Refusing to restore: this snapshot payload is not valid JSON. It predates the 2.1.1 slash fix and cannot be trusted.',
			'snapshot_key' => $key,
			'json_error'   => json_last_error_msg(),
		);
	}

	// Snapshot the current state so the restore is itself undoable.
	omatic_el_take_snapshot( $post_id, 'pre-restore' );

	update_post_meta( $post_id, '_elementor_data', wp_slash( $payload ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	omatic_el_flush_css( $post_id );

	return array(
		'success'  => true,
		'post_id'  => $post_id,
		'restored' => $key,
		'taken_at' => isset( $snapshots[ $key ]['taken_at'] ) ? $snapshots[ $key ]['taken_at'] : null,
	);
}

function omatic_cb_el_flush_css( $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$ok      = omatic_el_flush_css( $post_id );
	return array(
		'success' => $ok,
		'post_id' => $post_id ? $post_id : null,
		'note'    => $ok ? 'Elementor CSS cache cleared.' : 'Elementor is not active; nothing to flush.',
	);
}
