<?php
/**
 * Automatic redirects: a 301 from a published address when it changes.
 *
 * When a published post, page or public custom type's permalink changes (its
 * slug, its parent, its date or category where the permalinks use them),
 * the old path gets a 301 to the new one, origin `auto`. For a hierarchical
 * type every published descendant whose address changed gets one too. The
 * same for public taxonomy terms and their child terms.
 *
 * - The old addresses are noted before the change is written
 *   (`pre_post_update`, `edit_terms`) and compared after it is complete
 *   (`wp_after_insert_post`, which REST fires only once terms and meta are
 *   saved, and `edited_term`). Those run for the editor, quick and bulk edit,
 *   REST (the WordPress MCP server's writes included) and WP-CLI alike.
 * - Never from a path that is now published content, never a loop (rename
 *   back, and the older automatic redirect from the new address is removed
 *   first), never a duplicate. Every redirect goes through validate().
 * - **No chain**: a redirect whose target was the old path is pointed at the
 *   new one.
 * - Skipped: attachments, posts that weren't published before and after, and
 *   types or taxonomies that aren't public.
 * - A switch on the Redirects tab, on by default (settings()['auto']).
 *
 * Core's own `_wp_old_slug` handling is left alone; a redirect here answers
 * first, from `wp_loaded`.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use function TGD_Site_Kit\table;

defined( 'ABSPATH' ) || exit;

// The kit's own table, with no WordPress API over it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Add the hooks, when the switch is on.
 */
function add_auto_hooks() {
	if ( ! settings()['auto'] ) {
		return;
	}
	add_action( 'pre_post_update', __NAMESPACE__ . '\\note_post_paths', 10, 2 );
	add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\compare_post_paths', 10, 4 );
	add_action( 'edit_terms', __NAMESPACE__ . '\\note_term_paths', 10, 2 );
	add_action( 'edited_term', __NAMESPACE__ . '\\compare_term_paths', 10, 3 );
}

/**
 * Old paths noted before a change, keyed `post:<id>` or `term:<id>`.
 *
 * @param string     $key   Key.
 * @param array|null $paths Object ID => path to note; null to take (and
 *                          forget) what was noted.
 * @return array<int, string>
 */
function noted_paths( $key, $paths = null ) {
	static $noted = array();
	if ( null !== $paths ) {
		$noted[ $key ] = $paths;
		return $paths;
	}
	$out = $noted[ $key ] ?? array();
	unset( $noted[ $key ] );
	return $out;
}

/**
 * Whether a post's address can get an automatic redirect.
 *
 * @param \WP_Post|null $post Post.
 * @return bool
 */
function auto_post_eligible( $post ) {
	return $post instanceof \WP_Post
		&& 'publish' === $post->post_status
		&& 'attachment' !== $post->post_type
		&& is_post_type_viewable( $post->post_type )
		&& (bool) get_post_type_object( $post->post_type )?->public;
}

/**
 * A URL's path, decoded, or '' when it is not on this site.
 *
 * @param string|false $url URL.
 * @return string
 */
function own_path( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}
	$parts = split_url( $url );
	return is_own_host( $parts['host'] ) ? $parts['path'] : '';
}

/**
 * Published descendants of a post, by ID.
 *
 * @param \WP_Post $post Post of a hierarchical type.
 * @return int[]
 */
function published_descendants( \WP_Post $post ) {
	$ids   = array();
	$todo  = array( (int) $post->ID );
	$found = 0;
	while ( $todo && $found < 2000 ) {
		$children = get_posts(
			array(
				'post_type'        => $post->post_type,
				'post_status'      => 'publish',
				'post_parent__in'  => $todo,
				'fields'           => 'ids',
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only, when a parent page moves.
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$todo     = array_diff( array_map( 'intval', $children ), $ids );
		$ids      = array_merge( $ids, $todo );
	}
	return $ids;
}

/**
 * Before a post is updated: note its address, and its published
 * descendants' when its slug or parent is changing.
 *
 * @param int   $post_id Post ID.
 * @param array $data    The values about to be written.
 */
function note_post_paths( $post_id, $data ) {
	$post = get_post( $post_id );
	if ( ! auto_post_eligible( $post ) ) {
		return;
	}
	$paths = array( (int) $post_id => own_path( get_permalink( $post ) ) );
	$moved = ( isset( $data['post_name'] ) && $data['post_name'] !== $post->post_name ) || ( isset( $data['post_parent'] ) && (int) $data['post_parent'] !== (int) $post->post_parent );
	if ( $moved && is_post_type_hierarchical( $post->post_type ) ) {
		foreach ( published_descendants( $post ) as $id ) {
			$paths[ $id ] = own_path( get_permalink( $id ) );
		}
	}
	noted_paths( 'post:' . $post_id, $paths );
}

/**
 * After a post is saved: a redirect for every noted address that changed.
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post      $post        Post as saved.
 * @param bool          $update      Whether it was an update.
 * @param \WP_Post|null $post_before Post before the update.
 */
function compare_post_paths( $post_id, $post, $update, $post_before ) {
	$noted = noted_paths( 'post:' . $post_id );
	if ( ! $update || ! $noted || ! auto_post_eligible( $post ) || ! auto_post_eligible( $post_before ) ) {
		return;
	}
	clean_post_cache( $post_id );
	foreach ( $noted as $id => $old ) {
		$new = own_path( get_permalink( $id ) );
		if ( $id !== (int) $post_id && 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		/* translators: 1: title, 2: post type, 3: ID */
		$note = sprintf( __( 'Moved: “%1$s” (%2$s %3$d)', 'tgd-site-kit' ), get_the_title( $id ), get_post_type( $id ), $id );
		add_auto_redirect( $old, $new, $note );
	}
}

/**
 * Before a term is updated: note its link and its child terms'.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy.
 */
function note_term_paths( $term_id, $taxonomy ) {
	$tax = get_taxonomy( $taxonomy );
	if ( ! $tax || ! $tax->public || ! $tax->publicly_queryable ) {
		return;
	}
	$ids = array( (int) $term_id );
	if ( $tax->hierarchical ) {
		$children = get_term_children( $term_id, $taxonomy );
		$ids      = array_merge( $ids, is_array( $children ) ? array_map( 'intval', $children ) : array() );
	}
	$paths = array();
	foreach ( $ids as $id ) {
		$paths[ $id ] = own_path( get_term_link( $id, $taxonomy ) );
	}
	noted_paths( 'term:' . $term_id, $paths );
}

/**
 * After a term is updated: a redirect for every noted link that changed.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID.
 * @param string $taxonomy Taxonomy.
 */
function compare_term_paths( $term_id, $tt_id, $taxonomy ) {
	$noted = noted_paths( 'term:' . $term_id );
	if ( ! $noted ) {
		return;
	}
	clean_term_cache( array_keys( $noted ), $taxonomy );
	foreach ( $noted as $id => $old ) {
		$term = get_term( $id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			continue;
		}
		/* translators: 1: term name, 2: taxonomy, 3: ID */
		$note = sprintf( __( 'Moved: “%1$s” (%2$s %3$d)', 'tgd-site-kit' ), $term->name, $taxonomy, $id );
		add_auto_redirect( $old, own_path( get_term_link( $term ) ), $note );
	}
}

/**
 * Add a 301 from an old path to a new one, and repoint redirects that
 * ended at the old path.
 *
 * @param string $from_path Old path.
 * @param string $to_path   New path.
 * @param string $note      Note for the redirect.
 * @return int|\WP_Error|null New redirect ID, why it wasn't added, or null
 *                            when nothing changed.
 */
function add_auto_redirect( $from_path, $to_path, $note ) {
	global $wpdb;
	if ( '' === $from_path || '' === $to_path || match_key( $from_path ) === match_key( $to_path ) || ! table_ready() ) {
		return null;
	}
	// Renamed back: the automatic redirect from the address that is live again goes.
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE origin = 'auto' AND is_regex = 0 AND match_key = %s", table( 'redirects' ), match_key( $to_path ) ) );

	repoint_targets( $from_path, $to_path );

	// An automatic redirect already at the old path is moved along, not doubled.
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM %i WHERE origin = 'auto' AND is_regex = 0 AND match_key = %s ORDER BY id LIMIT 1", table( 'redirects' ), match_key( $from_path ) ), ARRAY_A );
	$input    = array(
		'source'     => $from_path,
		'target'     => $to_path,
		'status'     => 301,
		'query_mode' => 'pass',
		'note'       => $note,
	);
	$row      = validate( $input, $existing ? (int) $existing['id'] : 0 );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	if ( $existing ) {
		update( (int) $existing['id'], $row );
		return (int) $existing['id'];
	}
	return insert( $row, array( 'origin' => 'auto' ) );
}

/**
 * This site's scheme, host and port, for a full URL from a path (which
 * already carries any subfolder the site lives in).
 *
 * @return string
 */
function site_origin() {
	$home = wp_parse_url( home_url() );
	return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
}

/**
 * Point every redirect whose target is an old path at the new one, keeping
 * the target's form (a path stays a path, a full URL stays a full URL) and
 * any query it had. Regex targets with backreferences are left alone.
 *
 * @param string $from_path Old path.
 * @param string $to_path   New path.
 * @return int[] IDs repointed.
 */
function repoint_targets( $from_path, $to_path ) {
	$done = array();
	foreach ( all( 'ASC' ) as $row ) {
		if ( '' === $row['target'] || has_backreference( $row ) ) {
			continue;
		}
		$to = split_url( $row['target'] );
		if ( ! is_own_host( $to['host'] ) || match_key( $to['path'] ) !== match_key( $from_path ) ) {
			continue;
		}
		$target = ( '' !== $to['host'] ? site_origin() . $to_path : $to_path ) . ( '' !== $to['query'] ? '?' . $to['query'] : '' );
		$clean  = validate( array( 'target' => $target ) + $row, $row['id'] );
		if ( is_wp_error( $clean ) ) {
			continue;
		}
		update( $row['id'], $clean );
		$done[] = (int) $row['id'];
	}
	return $done;
}
