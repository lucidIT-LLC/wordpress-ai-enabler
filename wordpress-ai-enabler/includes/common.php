<?php
/**
 * LucidIT WordPress Enabler — helpers shared by every ability file.
 *
 * @package LucidIT_WP_Enabler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Push one entry onto a post's rolling snapshot list and trim it to $max,
 * oldest out first. Used by the Elementor snapshots (_elementor_data) and the
 * Site Editor snapshots (post_content); one mechanism, two meta keys.
 *
 * wp_slash() is load-bearing, and subtle enough to have shipped broken once:
 * update_post_meta() unslashes whatever it is given, recursively inside an
 * array. Snapshot payloads are JSON or block markup whose strings carry
 * escaped quotes (\"), so storing them unslashed silently strips one
 * backslash level. It looks fine (the array round-trips, the byte count is
 * right) and only fails later, at restore, when the JSON no longer parses.
 *
 * @param int    $post_id  Post ID.
 * @param string $meta_key Snapshot meta key.
 * @param int    $max      Snapshots held.
 * @param string $reason   Short label.
 * @param array  $payload  Entry fields after taken_at and reason.
 * @return string Snapshot key.
 */
function omatic_snapshot_push( $post_id, $meta_key, $max, $reason, array $payload ) {
	$snapshots = get_post_meta( $post_id, $meta_key, true );
	if ( ! is_array( $snapshots ) ) {
		$snapshots = array();
	}

	$key               = gmdate( 'Ymd-His' ) . '-' . substr( md5( (string) wp_rand() ), 0, 4 );
	$snapshots[ $key ] = array_merge(
		array(
			'taken_at' => gmdate( 'c' ),
			'reason'   => sanitize_text_field( (string) $reason ),
		),
		$payload
	);

	while ( count( $snapshots ) > $max ) {
		array_shift( $snapshots );
	}

	update_post_meta( $post_id, $meta_key, wp_slash( $snapshots ) );
	return $key;
}
