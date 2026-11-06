<?php
/**
 * Redirects: the table, what a valid redirect is, and reading and writing rows.
 *
 * One row per redirect. An exact redirect is found by `match_key` (its path,
 * decoded, lowercased, without a trailing slash: the loosest form, so one
 * indexed lookup finds every candidate) and then checked against its own
 * flags. Regex redirects are few and are all read and tried in order.
 *
 * What a redirect may never do is decided here, in validate(), and the same
 * check runs for the settings form, the Jarvis route and the imports:
 *
 * - redirect wp-admin, wp-login.php, the REST API, xmlrpc.php or wp-cron.php;
 * - redirect a URL that resolves to published content (a post, a page, a term
 *   archive, the front page), because that hides a live page;
 * - point at itself.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use function TGD_Site_Kit\table;

defined( 'ABSPATH' ) || exit;

// The kit's own table, with no WordPress API over it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const TABLE_VERSION = 1;
const STATUSES      = array( 301, 302, 307, 308, 410 );
const QUERY_MODES   = array( 'exact', 'ignore', 'pass' );
const OPTION        = 'tgdsk_redirects';

/**
 * Create the table when the module boots for the first time.
 */
function ensure_table() {
	\TGD_Site_Kit\ensure_table(
		'redirects',
		'id bigint(20) unsigned NOT NULL auto_increment,
source varchar(500) NOT NULL default \'\',
match_key varchar(191) NOT NULL default \'\',
is_regex tinyint(1) NOT NULL default 0,
query_mode varchar(10) NOT NULL default \'exact\',
match_case tinyint(1) NOT NULL default 0,
match_trailing tinyint(1) NOT NULL default 0,
status smallint(5) unsigned NOT NULL default 301,
target text NOT NULL,
enabled tinyint(1) NOT NULL default 1,
note varchar(200) NOT NULL default \'\',
origin varchar(60) NOT NULL default \'\',
import_hash char(32) NOT NULL default \'\',
hits bigint(20) unsigned NOT NULL default 0,
last_hit datetime NULL default NULL,
created_gmt datetime NOT NULL default \'0000-00-00 00:00:00\',
modified_gmt datetime NOT NULL default \'0000-00-00 00:00:00\',
PRIMARY KEY  (id),
KEY match_key (match_key),
KEY is_regex (is_regex),
KEY origin (origin)',
		TABLE_VERSION
	);
}

/**
 * Whether the table exists yet (the module may never have been on).
 *
 * @return bool
 */
function table_ready() {
	$versions = get_option( \TGD_Site_Kit\DB_OPTION, array() );
	return is_array( $versions ) && (int) ( $versions['redirects'] ?? 0 ) >= TABLE_VERSION;
}

/**
 * Module settings, with defaults.
 *
 * `cache_hours` is how long a browser may cache a 301 or 308 (Redirection's
 * default is one hour, and the import copies its setting); 0 sends no-cache.
 *
 * @return array{cache_hours: int, track_hits: bool}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'cache_hours' => max( 0, (int) ( $stored['cache_hours'] ?? 1 ) ),
		'track_hits'  => (bool) ( $stored['track_hits'] ?? true ),
	);
}

/**
 * Store the settings.
 *
 * @param array $settings Settings.
 */
function save_settings( array $settings ) {
	update_option(
		OPTION,
		array(
			'cache_hours' => max( 0, (int) $settings['cache_hours'] ),
			'track_hits'  => (bool) $settings['track_hits'],
		),
		false
	);
}

/**
 * Split a request target or a typed source into a decoded path and a query.
 *
 * A full URL on this site's host (with or without www) is cut down to its
 * path; a URL on another host is refused by the caller.
 *
 * @param string $raw Source as typed or requested.
 * @return array{path: string, query: string, host: string}
 */
function split_url( $raw ) {
	$raw   = trim( (string) $raw );
	$parts = wp_parse_url( $raw );
	$parts = is_array( $parts ) ? $parts : array();
	$path  = (string) ( $parts['path'] ?? '' );
	$path  = '/' . ltrim( rawurldecode( $path ), '/' );
	return array(
		'path'  => $path,
		'query' => (string) ( $parts['query'] ?? '' ),
		'host'  => strtolower( (string) ( $parts['host'] ?? '' ) ),
	);
}

/**
 * A host without a leading `www.`.
 *
 * @param string $host Host.
 * @return string
 */
function bare_host( $host ) {
	$host = strtolower( (string) $host );
	return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

/**
 * Whether a host is this site's.
 *
 * @param string $host Host, '' for a relative URL.
 * @return bool
 */
function is_own_host( $host ) {
	return '' === $host || bare_host( $host ) === bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

/**
 * The lookup key for a path: lowercased, no trailing slash, `/` for the root.
 *
 * @param string $path Decoded path.
 * @return string
 */
function match_key( $path ) {
	$key = untrailingslashit( strtolower( (string) $path ) );
	$key = '' === $key ? '/' : $key;
	return function_exists( 'mb_substr' ) ? mb_substr( $key, 0, 191 ) : substr( $key, 0, 191 );
}

/**
 * Path prefixes no redirect may touch: the dashboard, login, REST, XML-RPC
 * and cron, wherever WordPress itself lives.
 *
 * @return string[] Lowercased path prefixes.
 */
function protected_prefixes() {
	$out = array();
	foreach ( array_unique( array( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ) ) ) as $base ) {
		$base = '/' . trim( $base, '/' );
		$base = '/' === $base ? '' : $base;
		foreach ( array( '/wp-admin', '/wp-login.php', '/' . rest_get_url_prefix(), '/xmlrpc.php', '/wp-cron.php', '/wp-signup.php', '/wp-activate.php' ) as $path ) {
			$out[] = strtolower( $base . $path );
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Whether a path, or a request with `?rest_route=`, is one of the protected ones.
 *
 * @param string $path  Decoded path.
 * @param string $query Query string.
 * @return bool
 */
function is_protected( $path, $query = '' ) {
	$lower = strtolower( (string) $path );
	foreach ( protected_prefixes() as $prefix ) {
		if ( $lower === $prefix || str_starts_with( $lower, $prefix . '/' ) || str_starts_with( $lower, $prefix . '?' ) || ( str_ends_with( $prefix, '.php' ) && str_starts_with( $lower, $prefix ) ) ) {
			return true;
		}
	}
	return str_contains( (string) $query, 'rest_route=' );
}

/**
 * What published content a path resolves to, if any.
 *
 * Posts and pages through url_to_postid(), the front page, term archives by
 * slug, and post type archives by their own link.
 *
 * @param string $path Decoded path.
 * @return string Plain-text description of what is there, or '' for nothing.
 */
function published_at( $path ) {
	$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	$key       = match_key( $path );
	if ( match_key( '' === $home_path ? '/' : $home_path ) === $key ) {
		return __( 'the front page', 'tgd-site-kit' );
	}
	$post_id = url_to_postid( home_url( $path ) );
	if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
		/* translators: 1: post type label, 2: title, 3: ID */
		return sprintf( __( 'the published %1$s “%2$s” (ID %3$d)', 'tgd-site-kit' ), get_post_type_object( get_post_type( $post_id ) )->labels->singular_name ?? '', get_the_title( $post_id ), $post_id );
	}
	$slug = basename( untrailingslashit( $path ) );
	if ( '' !== $slug ) {
		$terms = get_terms(
			array(
				'slug'       => sanitize_title( $slug ),
				'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && match_key( split_url( $link )['path'] ) === $key ) {
				/* translators: 1: term name, 2: taxonomy */
				return sprintf( __( 'the archive for “%1$s” (%2$s)', 'tgd-site-kit' ), $term->name, $term->taxonomy );
			}
		}
	}
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		$link = get_post_type_archive_link( $type );
		if ( $link && match_key( split_url( $link )['path'] ) === $key ) {
			/* translators: %s: post type */
			return sprintf( __( 'the %s archive', 'tgd-site-kit' ), $type );
		}
	}
	return '';
}

/**
 * Paths of published content to test a regex source against.
 *
 * @return string[]
 */
function published_paths() {
	$paths = array( split_url( home_url( '/' ) )['path'] );
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );
	$ids = get_posts(
		array(
			'post_type'      => array_values( $types ),
			'post_status'    => 'publish',
			'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only, on save.
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'no_found_rows'  => true,
		)
	);
	foreach ( $ids as $id ) {
		$paths[] = split_url( (string) get_permalink( $id ) )['path'];
	}
	$terms = get_terms(
		array(
			'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
			'hide_empty' => true,
			'number'     => 1000,
		)
	);
	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$link = get_term_link( $term );
		if ( is_string( $link ) ) {
			$paths[] = split_url( $link )['path'];
		}
	}
	return array_values( array_unique( $paths ) );
}

/**
 * A regex source as a PCRE pattern, the way Redirection builds it.
 *
 * @param string $source     Pattern as typed.
 * @param bool   $match_case Case-sensitive.
 * @return string
 */
function regex_pattern( $source, $match_case ) {
	return '@' . str_replace( '@', '\\@', (string) $source ) . '@s' . ( $match_case ? '' : 'i' );
}

/**
 * Check and normalize a redirect before it is stored.
 *
 * @param array $input  Fields: source, target, status, is_regex, query_mode,
 *                      match_case, match_trailing, enabled, note.
 * @param int   $own_id Row being updated, 0 for a new one (excluded from the
 *                      duplicate check).
 * @return array|\WP_Error The row's columns, or why it was refused.
 */
function validate( array $input, $own_id = 0 ) {
	$fail     = fn( $code, $message ) => new \WP_Error( $code, $message, array( 'status' => 400 ) );
	$is_regex = ! empty( $input['is_regex'] );
	$source   = trim( (string) ( $input['source'] ?? '' ) );
	$status   = (int) ( $input['status'] ?? 301 );
	$mode     = (string) ( $input['query_mode'] ?? 'exact' );
	$target   = trim( (string) ( $input['target'] ?? '' ) );
	$case     = ! empty( $input['match_case'] );

	if ( '' === $source ) {
		return $fail( 'tgdsk_redirect_source', __( 'A redirect needs a source.', 'tgd-site-kit' ) );
	}
	if ( ! in_array( $status, STATUSES, true ) ) {
		return $fail( 'tgdsk_redirect_status', __( 'The status must be 301, 302, 307, 308 or 410.', 'tgd-site-kit' ) );
	}
	if ( ! in_array( $mode, QUERY_MODES, true ) ) {
		return $fail( 'tgdsk_redirect_query', __( 'Query handling must be exact, ignore or pass.', 'tgd-site-kit' ) );
	}
	if ( strlen( $source ) > 500 ) {
		return $fail( 'tgdsk_redirect_source', __( 'The source is longer than 500 characters.', 'tgd-site-kit' ) );
	}

	if ( $is_regex ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is reported, not warned about.
		if ( false === @preg_match( regex_pattern( $source, $case ), '' ) ) {
			return $fail( 'tgdsk_redirect_regex', __( 'The source is not a valid regular expression.', 'tgd-site-kit' ) );
		}
		$samples = array_merge( protected_prefixes(), array_map( fn( $p ) => $p . '/', protected_prefixes() ) );
		foreach ( $samples as $sample ) {
			if ( preg_match( regex_pattern( $source, $case ), $sample ) ) {
				/* translators: %s: path */
				return $fail( 'tgdsk_redirect_protected', sprintf( __( 'The pattern matches %s, which is never redirected.', 'tgd-site-kit' ), $sample ) );
			}
		}
		foreach ( published_paths() as $path ) {
			if ( preg_match( regex_pattern( $source, $case ), $path ) ) {
				/* translators: %s: path */
				return $fail( 'tgdsk_redirect_published', sprintf( __( 'The pattern matches %s, which is published content. A redirect would hide it.', 'tgd-site-kit' ), $path ) );
			}
		}
		$key = '';
	} else {
		$parts = split_url( $source );
		if ( ! is_own_host( $parts['host'] ) ) {
			return $fail( 'tgdsk_redirect_host', __( 'The source must be a path on this site, such as /old-page/.', 'tgd-site-kit' ) );
		}
		if ( is_protected( $parts['path'], $parts['query'] ) ) {
			/* translators: %s: path */
			return $fail( 'tgdsk_redirect_protected', sprintf( __( '%s is part of WordPress itself (the dashboard, login, REST API, XML-RPC or cron) and is never redirected.', 'tgd-site-kit' ), $parts['path'] ) );
		}
		$there = published_at( $parts['path'] );
		if ( '' !== $there ) {
			/* translators: 1: path, 2: what is published there */
			return $fail( 'tgdsk_redirect_published', sprintf( __( '%1$s is %2$s. A redirect would hide a live page; unpublish it or change its address first.', 'tgd-site-kit' ), $parts['path'], $there ) );
		}
		$source = $parts['path'] . ( '' !== $parts['query'] ? '?' . $parts['query'] : '' );
		$key    = match_key( $parts['path'] );
	}

	if ( 410 === $status ) {
		$target = '';
	} else {
		if ( '' === $target ) {
			return $fail( 'tgdsk_redirect_target', __( 'A redirect needs a target URL or path.', 'tgd-site-kit' ) );
		}
		if ( ! str_starts_with( $target, '/' ) && ! preg_match( '#^https?://[^/\s]+#i', $target ) ) {
			return $fail( 'tgdsk_redirect_target', __( 'The target must be a path starting with / or a full http(s) URL.', 'tgd-site-kit' ) );
		}
		if ( str_starts_with( $target, '//' ) || preg_match( '/[\s<>"]/', $target ) ) {
			return $fail( 'tgdsk_redirect_target', __( 'The target has characters a URL cannot contain.', 'tgd-site-kit' ) );
		}
		if ( ! $is_regex ) {
			$to = split_url( $target );
			if ( is_own_host( $to['host'] ) && match_key( $to['path'] ) === $key && ( '' === $to['query'] || 'exact' !== $mode ) ) {
				return $fail( 'tgdsk_redirect_loop', __( 'The target is the source itself, so the redirect would loop.', 'tgd-site-kit' ) );
			}
		}
	}

	$row = array(
		'source'         => $source,
		'match_key'      => $key,
		'is_regex'       => $is_regex ? 1 : 0,
		'query_mode'     => $mode,
		'match_case'     => $case ? 1 : 0,
		'match_trailing' => ! empty( $input['match_trailing'] ) ? 1 : 0,
		'status'         => $status,
		'target'         => $target,
		'enabled'        => array_key_exists( 'enabled', $input ) && ! $input['enabled'] ? 0 : 1,
		'note'           => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 200 ),
	);

	$same = find_same( $row, $own_id );
	if ( $same ) {
		/* translators: %d: redirect ID */
		return $fail( 'tgdsk_redirect_duplicate', sprintf( __( 'Redirect %d already has this source.', 'tgd-site-kit' ), $same ) );
	}
	return $row;
}

/**
 * Another row with the same source and matching, if any.
 *
 * @param array $row    Normalized row.
 * @param int   $own_id Row to ignore.
 * @return int ID, or 0.
 */
function find_same( array $row, $own_id ) {
	global $wpdb;
	if ( ! table_ready() ) {
		return 0;
	}
	if ( $row['is_regex'] ) {
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE is_regex = 1 AND source = %s AND id <> %d LIMIT 1', table( 'redirects' ), $row['source'], $own_id ) );
	}
	// Same path (any case, either trailing slash) and the same query: two
	// redirects that would answer the same request.
	$query = query_params( split_url( $row['source'] )['query'], true );
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source FROM %i WHERE is_regex = 0 AND match_key = %s AND id <> %d ORDER BY id', table( 'redirects' ), $row['match_key'], $own_id ), ARRAY_A );
	foreach ( (array) $rows as $other ) {
		if ( query_params( split_url( $other['source'] )['query'], true ) === $query ) {
			return (int) $other['id'];
		}
	}
	return 0;
}

/**
 * Columns that make up what a redirect does, for hashing.
 *
 * @param array $row Row.
 * @return string
 */
function row_hash( array $row ) {
	$keys = array( 'source', 'is_regex', 'query_mode', 'match_case', 'match_trailing', 'status', 'target', 'enabled' );
	$out  = array();
	foreach ( $keys as $key ) {
		$out[ $key ] = (string) ( $row[ $key ] ?? '' );
	}
	return md5( (string) wp_json_encode( $out ) );
}

/**
 * Insert a validated row.
 *
 * @param array $row   From validate().
 * @param array $extra Other columns (origin, import_hash, hits, last_hit).
 * @return int New ID, 0 on failure.
 */
function insert( array $row, array $extra = array() ) {
	global $wpdb;
	$now = current_time( 'mysql', true );
	$ok  = $wpdb->insert(
		table( 'redirects' ),
		array_merge(
			$row,
			array(
				'created_gmt'  => $now,
				'modified_gmt' => $now,
			),
			$extra
		)
	);
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Update a row with validated columns.
 *
 * @param int   $id    Row ID.
 * @param array $row   Columns.
 * @param array $extra Other columns.
 * @return bool
 */
function update( $id, array $row, array $extra = array() ) {
	global $wpdb;
	return false !== $wpdb->update( table( 'redirects' ), array_merge( $row, array( 'modified_gmt' => current_time( 'mysql', true ) ), $extra ), array( 'id' => (int) $id ) );
}

/**
 * Delete a row (settings screen only; the REST route disables instead).
 *
 * @param int $id Row ID.
 */
function delete_row( $id ) {
	global $wpdb;
	$wpdb->delete( table( 'redirects' ), array( 'id' => (int) $id ) );
}

/**
 * One row.
 *
 * @param int $id Row ID.
 * @return array|null
 */
function get( $id ) {
	global $wpdb;
	if ( ! table_ready() ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', table( 'redirects' ), $id ), ARRAY_A );
	return $row ? shape( $row ) : null;
}

/**
 * Every row, newest first.
 *
 * @return array[]
 */
function all() {
	global $wpdb;
	if ( ! table_ready() ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', table( 'redirects' ) ), ARRAY_A );
	return array_map( __NAMESPACE__ . '\\shape', (array) $rows );
}

/**
 * A row with typed values, for the screen and the REST route.
 *
 * @param array $row Raw row.
 * @return array
 */
function shape( array $row ) {
	return array(
		'id'             => (int) $row['id'],
		'source'         => (string) $row['source'],
		'is_regex'       => (bool) $row['is_regex'],
		'query_mode'     => (string) $row['query_mode'],
		'match_case'     => (bool) $row['match_case'],
		'match_trailing' => (bool) $row['match_trailing'],
		'status'         => (int) $row['status'],
		'target'         => (string) $row['target'],
		'enabled'        => (bool) $row['enabled'],
		'note'           => (string) $row['note'],
		'origin'         => (string) $row['origin'],
		'hits'           => (int) $row['hits'],
		'last_hit'       => $row['last_hit'] ? mysql_to_rfc3339( $row['last_hit'] ) : null,
		'created_gmt'    => (string) $row['created_gmt'],
		'modified_gmt'   => (string) $row['modified_gmt'],
		'import_hash'    => (string) $row['import_hash'],
	);
}

/**
 * Rows that could match a request: exact candidates for its path, and every
 * enabled regex, in ID order.
 *
 * @param string $path Decoded request path.
 * @return array[] Raw rows.
 */
function candidates( $path ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1 AND ( match_key = %s OR is_regex = 1 ) ORDER BY is_regex ASC, id ASC', table( 'redirects' ), match_key( $path ) ), ARRAY_A );
}

/**
 * Count a hit.
 *
 * @param int $id Row ID.
 */
function record_hit( $id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', table( 'redirects' ), current_time( 'mysql', true ), $id ) );
}

/**
 * Exact-path redirects whose match key is in a list, for the 404 log.
 *
 * @param string[] $paths Decoded paths.
 * @return array<string, int> Match key => redirect ID.
 */
function ids_for_paths( array $paths ) {
	global $wpdb;
	if ( ! table_ready() || ! $paths ) {
		return array();
	}
	$keys = array_values( array_unique( array_map( __NAMESPACE__ . '\\match_key', $paths ) ) );
	$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, match_key FROM %i WHERE is_regex = 0 AND match_key IN ($in)", array_merge( array( table( 'redirects' ) ), $keys ) ), ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ $row['match_key'] ] = (int) $row['id'];
	}
	return $out;
}
