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
 * check runs for the settings form, the TGD Connect route, the CSV import and
 * the automatic redirects:
 *
 * - redirect wp-admin, wp-login.php, the REST API, xmlrpc.php or wp-cron.php;
 * - redirect a URL that resolves to published content (a post, a page, a term
 *   archive, the front page), because that hides a live page, unless the
 *   redirect is flagged `replaces_published`: then hiding it is the point,
 *   and the Redirects tab names what it hides. Attachment pages never count
 *   as published content;
 * - point at itself, or at a chain of exact redirects that leads back to it;
 * - duplicate another redirect's source.
 *
 * `origin` says where a redirect came from: '' (added on the Redirects tab),
 * `import` (a CSV import, or one of the importers before 0.5.0), `connect`
 * (the TGD Connect route) or `auto` (an address change).
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use function TGD_Site_Kit\table;

defined( 'ABSPATH' ) || exit;

// The kit's own table, with no WordPress API over it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const TABLE_VERSION = 2;
const STATUSES      = array( 301, 302, 307, 308, 410 );
const QUERY_MODES   = array( 'exact', 'ignore', 'pass' );
const OPTION        = 'tgdsk_redirects';
const ORIGINS       = array( '', 'import', 'connect', 'auto' );
// Redirects skipped at request time because their source is published now: id => {path, time}.
const SKIPPED_OPTION = 'tgdsk_redirects_skipped';
// Posts and terms hidden by "replaces a published page" redirects: {post: int[], term: int[]}.
const HIDDEN_TRANSIENT = 'tgdsk_redirects_hidden';

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
replaces_published tinyint(1) NOT NULL default 0,
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
 * `cache_hours` is how long a browser may cache a 301 or 308 (default one
 * hour); 0 sends no-cache. `auto` adds a redirect when a published address
 * changes (default on, including for settings stored before 0.5.0).
 *
 * @return array{cache_hours: int, track_hits: bool, auto: bool}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'cache_hours' => max( 0, (int) ( $stored['cache_hours'] ?? 1 ) ),
		'track_hits'  => (bool) ( $stored['track_hits'] ?? true ),
		'auto'        => (bool) ( $stored['auto'] ?? true ),
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
			'auto'        => (bool) ( $settings['auto'] ?? true ),
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
 * slug, and post type archives by their own link. **Attachment pages never
 * count**, whether or not the site serves them: nobody publishes one on
 * purpose, and core reports an attachment as published whenever its parent
 * is.
 *
 * @param string $path Decoded path.
 * @return array{kind: string, id: int, title: string, type: string}|null
 *         `kind` is post, term, archive or front; `type` is a label.
 */
function published_item( $path ) {
	$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	$key       = match_key( $path );
	if ( match_key( '' === $home_path ? '/' : $home_path ) === $key ) {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		return array(
			'kind'  => 'front',
			'id'    => $front,
			'title' => $front ? get_the_title( $front ) : get_bloginfo( 'name' ),
			'type'  => __( 'Front page', 'tgd-site-kit' ),
		);
	}
	$post_id = url_to_postid( home_url( $path ) );
	if ( $post_id && 'attachment' !== get_post_type( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		return array(
			'kind'  => 'post',
			'id'    => (int) $post_id,
			'title' => get_the_title( $post_id ),
			'type'  => $type ? $type->labels->singular_name : (string) get_post_type( $post_id ),
		);
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
				$tax = get_taxonomy( $term->taxonomy );
				return array(
					'kind'  => 'term',
					'id'    => (int) $term->term_id,
					'title' => $term->name,
					'type'  => $tax ? $tax->labels->singular_name : $term->taxonomy,
				);
			}
		}
	}
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		$link = get_post_type_archive_link( $type->name );
		if ( $link && match_key( split_url( $link )['path'] ) === $key ) {
			return array(
				'kind'  => 'archive',
				'id'    => 0,
				'title' => $type->labels->name,
				'type'  => __( 'Archive', 'tgd-site-kit' ),
			);
		}
	}
	return null;
}

/**
 * What published content a path resolves to, in words.
 *
 * @param string $path Decoded path.
 * @return string Plain text, or '' for nothing.
 */
function published_at( $path ) {
	$item = published_item( $path );
	return $item ? describe_item( $item ) : '';
}

/**
 * A published item in words: “Title” (Type).
 *
 * @param array $item From published_item() or published_paths().
 * @return string
 */
function describe_item( array $item ) {
	/* translators: 1: title, 2: type label */
	return sprintf( __( '“%1$s” (%2$s)', 'tgd-site-kit' ), '' !== (string) $item['title'] ? $item['title'] : __( '(no title)', 'tgd-site-kit' ), $item['type'] );
}

/**
 * Published content to test a regex source against: path => item.
 *
 * Attachments are left out (see published_item()). Kept for the request, so
 * a screen that lists many regexes reads the posts once.
 *
 * @return array<string, array> Path => {kind, id, title, type}.
 */
function published_paths() {
	static $paths = null;
	if ( null !== $paths ) {
		return $paths;
	}
	$paths = array();
	$front = published_item( split_url( home_url( '/' ) )['path'] );
	if ( $front ) {
		$paths[ split_url( home_url( '/' ) )['path'] ] = $front;
	}
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );
	$ids = get_posts(
		array(
			'post_type'      => array_values( $types ),
			'post_status'    => 'publish',
			'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only, on save and on the Redirects tab.
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'no_found_rows'  => true,
		)
	);
	foreach ( $ids as $id ) {
		$type = get_post_type_object( (string) get_post_type( $id ) );
		$path = split_url( (string) get_permalink( $id ) )['path'];
		if ( ! isset( $paths[ $path ] ) ) {
			$paths[ $path ] = array(
				'kind'  => 'post',
				'id'    => (int) $id,
				'title' => get_the_title( $id ),
				'type'  => $type ? $type->labels->singular_name : (string) get_post_type( $id ),
			);
		}
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
		if ( is_string( $link ) && ! isset( $paths[ split_url( $link )['path'] ] ) ) {
			$tax                                 = get_taxonomy( $term->taxonomy );
			$paths[ split_url( $link )['path'] ] = array(
				'kind'  => 'term',
				'id'    => (int) $term->term_id,
				'title' => $term->name,
				'type'  => $tax ? $tax->labels->singular_name : $term->taxonomy,
			);
		}
	}
	return $paths;
}

/**
 * The published content a regex source matches.
 *
 * @param string $source     Pattern as typed.
 * @param bool   $match_case Case-sensitive.
 * @return array<string, array> Path => item.
 */
function published_matches( $source, $match_case ) {
	$pattern = regex_pattern( $source, $match_case );
	$out     = array();
	foreach ( published_paths() as $path => $item ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern saved before a PCRE change must not break the screen.
		if ( @preg_match( $pattern, $path ) ) {
			$out[ $path ] = $item;
		}
	}
	return $out;
}

/**
 * What a redirect's source hides now: the published content it covers.
 *
 * @param array $row Shaped or raw row.
 * @return array<string, array> Path => item; empty when it hides nothing.
 */
function hidden_by( array $row ) {
	if ( ! empty( $row['is_regex'] ) ) {
		return published_matches( (string) $row['source'], ! empty( $row['match_case'] ) );
	}
	$path = split_url( (string) $row['source'] )['path'];
	$item = published_item( $path );
	return $item ? array( $path => $item ) : array();
}

/**
 * The published posts and terms that switched-on "replaces a published
 * page" redirects hide: they leave the sitemap and IndexNow doesn't send
 * them, because a visitor (or a crawler) asking for them is sent elsewhere.
 *
 * Worked out from the redirects and the published content, and kept for 12
 * hours, or until a redirect, a post or a term changes (forget_hidden()).
 * Empty while the redirects module is off.
 *
 * @return array{post: int[], term: int[]}
 */
function hidden_objects() {
	$none = array(
		'post' => array(),
		'term' => array(),
	);
	if ( ! \TGD_Site_Kit\is_enabled( 'redirects' ) || ! table_ready() ) {
		return $none;
	}
	$cached = get_transient( HIDDEN_TRANSIENT );
	if ( is_array( $cached ) && isset( $cached['post'], $cached['term'] ) ) {
		return $cached;
	}
	global $wpdb;
	$out  = $none;
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1 AND replaces_published = 1 AND status <> 410 ORDER BY id', table( 'redirects' ) ), ARRAY_A );
	foreach ( $rows as $row ) {
		foreach ( hidden_by( $row ) as $item ) {
			$kind = 'term' === $item['kind'] ? 'term' : ( in_array( $item['kind'], array( 'post', 'front' ), true ) ? 'post' : '' );
			if ( '' !== $kind && $item['id'] ) {
				$out[ $kind ][] = (int) $item['id'];
			}
		}
	}
	$out['post'] = array_values( array_unique( $out['post'] ) );
	$out['term'] = array_values( array_unique( $out['term'] ) );
	set_transient( HIDDEN_TRANSIENT, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/**
 * Forget the hidden objects, so the next ask works them out again.
 */
function forget_hidden() {
	delete_transient( HIDDEN_TRANSIENT );
}

/**
 * A regex source as a PCRE pattern: `@` delimiters, `s`, and `i` unless
 * case-sensitive.
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
 * @param array   $input   Fields: source, target, status, is_regex,
 *                         query_mode, match_case, match_trailing, enabled,
 *                         replaces_published, note.
 * @param int     $own_id  Row being updated, 0 for a new one (excluded from
 *                         the duplicate and loop checks).
 * @param array[] $pending Rows accepted earlier in the same import and not
 *                         stored yet, checked as if they were (so a dry run
 *                         answers as the import will).
 * @return array|\WP_Error The row's columns, or why it was refused.
 */
function validate( array $input, $own_id = 0, array $pending = array() ) {
	$fail     = fn( $code, $message ) => new \WP_Error( $code, $message, array( 'status' => 400 ) );
	$is_regex = ! empty( $input['is_regex'] );
	$source   = trim( (string) ( $input['source'] ?? '' ) );
	$status   = (int) ( $input['status'] ?? 301 );
	// A new redirect passes the query on unless told otherwise: an old link
	// shared on Facebook comes back with ?fbclid=..., and under `exact` that
	// would be a 404.
	$mode   = (string) ( $input['query_mode'] ?? 'pass' );
	$target = trim( (string) ( $input['target'] ?? '' ) );
	$case   = ! empty( $input['match_case'] );
	$flag   = ! empty( $input['replaces_published'] );

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
		$hides = published_matches( $source, $case );
		if ( $hides && ! $flag ) {
			return $fail(
				'tgdsk_redirect_published',
				sprintf(
					/* translators: 1: number of published items, 2: first path, 3: what is there */
					_n( 'The pattern matches %1$d published address (%2$s, %3$s), so it would hide it. Tick “Replaces a published page” (replaces_published) to save it anyway.', 'The pattern matches %1$d published addresses (the first is %2$s, %3$s), so it would hide them. Tick “Replaces a published page” (replaces_published) to save it anyway.', count( $hides ), 'tgd-site-kit' ),
					count( $hides ),
					array_key_first( $hides ),
					describe_item( reset( $hides ) )
				)
			);
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
		if ( '' !== $there && ! $flag ) {
			/* translators: 1: path, 2: what is published there */
			return $fail( 'tgdsk_redirect_published', sprintf( __( '%1$s is the published %2$s. A redirect would hide it. Tick “Replaces a published page” (replaces_published) to save it anyway, or unpublish it or change its address first.', 'tgd-site-kit' ), $parts['path'], $there ) );
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
			$back = leads_back( $target, $key, $own_id, $pending );
			if ( $back ) {
				/* translators: %s: the redirects in the loop */
				return $fail( 'tgdsk_redirect_loop', sprintf( __( 'The target is redirected back to this source (%s), so the redirect would loop.', 'tgd-site-kit' ), $back ) );
			}
		}
	}

	$row = array(
		'source'             => $source,
		'match_key'          => $key,
		'is_regex'           => $is_regex ? 1 : 0,
		'query_mode'         => $mode,
		'match_case'         => $case ? 1 : 0,
		'match_trailing'     => ! empty( $input['match_trailing'] ) ? 1 : 0,
		'status'             => $status,
		'target'             => $target,
		'enabled'            => array_key_exists( 'enabled', $input ) && ! $input['enabled'] ? 0 : 1,
		'replaces_published' => $flag ? 1 : 0,
		'note'               => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 200 ),
	);

	$same = find_same( $row, $own_id, $pending );
	if ( $same < 0 ) {
		return $fail( 'tgdsk_redirect_duplicate', __( 'An earlier row has this source; the first one is kept.', 'tgd-site-kit' ) );
	}
	if ( $same ) {
		/* translators: %d: redirect ID */
		return $fail( 'tgdsk_redirect_duplicate', sprintf( __( 'Redirect %d already has this source.', 'tgd-site-kit' ), $same ) );
	}
	return $row;
}

/**
 * Another row with the same source and matching, if any.
 *
 * @param array   $row     Normalized row.
 * @param int     $own_id  Row to ignore.
 * @param array[] $pending Rows not stored yet (see validate()); a match
 *                         there answers -1 - its index.
 * @return int ID, or 0.
 */
function find_same( array $row, $own_id, array $pending = array() ) {
	global $wpdb;
	$query = query_params( split_url( $row['source'] )['query'], true );
	$same  = fn( array $other ) => $row['is_regex']
		? ( ! empty( $other['is_regex'] ) && $other['source'] === $row['source'] )
		: ( empty( $other['is_regex'] ) && $other['match_key'] === $row['match_key'] && query_params( split_url( $other['source'] )['query'], true ) === $query );
	foreach ( $pending as $i => $other ) {
		if ( $same( $other ) ) {
			return -1 - (int) $i;
		}
	}
	if ( ! table_ready() ) {
		return 0;
	}
	if ( $row['is_regex'] ) {
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE is_regex = 1 AND source = %s AND id <> %d LIMIT 1', table( 'redirects' ), $row['source'], $own_id ) );
	}
	// Same path (any case, either trailing slash) and the same query: two
	// redirects that would answer the same request.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, match_key, is_regex FROM %i WHERE is_regex = 0 AND match_key = %s AND id <> %d ORDER BY id', table( 'redirects' ), $row['match_key'], $own_id ), ARRAY_A );
	foreach ( (array) $rows as $other ) {
		if ( $same( $other ) ) {
			return (int) $other['id'];
		}
	}
	return 0;
}

/**
 * Whether following a target through enabled exact redirects comes back to a
 * source: the loop a save would otherwise only show at request time.
 *
 * Exact redirects only, keyed by path as matching is, up to ten hops. A regex
 * can still close a loop; the chains check on the Redirects tab finds those.
 *
 * @param string  $target  Target of the redirect being saved.
 * @param string  $key     Its source's match key.
 * @param int     $own_id  Its ID (its stored copy is ignored), or 0.
 * @param array[] $pending Rows not stored yet.
 * @return string The hops, such as `/b/ → /c/`, or '' for no loop.
 */
function leads_back( $target, $key, $own_id, array $pending = array() ) {
	global $wpdb;
	$hops = array();
	$seen = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$to = split_url( $target );
		if ( ! is_own_host( $to['host'] ) ) {
			return '';
		}
		$next_key = match_key( $to['path'] );
		if ( $next_key === $key ) {
			return implode( ' → ', $hops );
		}
		if ( isset( $seen[ $next_key ] ) ) {
			return '';
		}
		$seen[ $next_key ] = true;
		$next              = null;
		foreach ( $pending as $row ) {
			if ( empty( $row['is_regex'] ) && ! empty( $row['enabled'] ) && $row['match_key'] === $next_key && 410 !== (int) $row['status'] ) {
				$next = $row;
				break;
			}
		}
		if ( ! $next && table_ready() ) {
			$next = $wpdb->get_row( $wpdb->prepare( 'SELECT source, target FROM %i WHERE is_regex = 0 AND enabled = 1 AND status <> 410 AND match_key = %s AND id <> %d ORDER BY id LIMIT 1', table( 'redirects' ), $next_key, (int) $own_id ), ARRAY_A );
		}
		if ( ! $next ) {
			return '';
		}
		$hops[] = $next['source'];
		$target = (string) $next['target'];
	}
	return '';
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
	forget_hidden();
	$ok = $wpdb->insert(
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
	forget_hidden();
	return false !== $wpdb->update( table( 'redirects' ), array_merge( $row, array( 'modified_gmt' => current_time( 'mysql', true ) ), $extra ), array( 'id' => (int) $id ) );
}

/**
 * Delete a row (settings screen only; the REST route disables instead).
 *
 * @param int $id Row ID.
 */
function delete_row( $id ) {
	global $wpdb;
	forget_hidden();
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
 * Every row, newest first (or oldest first, the order they are tried in).
 *
 * @param string $order `DESC` or `ASC`.
 * @return array[]
 */
function all( $order = 'DESC' ) {
	global $wpdb;
	if ( ! table_ready() ) {
		return array();
	}
	$sql  = 'ASC' === $order ? 'SELECT * FROM %i ORDER BY id ASC' : 'SELECT * FROM %i ORDER BY id DESC';
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, table( 'redirects' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- one of two fixed strings.
	return array_map( __NAMESPACE__ . '\\shape', (array) $rows );
}

/**
 * Counts for the status route: all, enabled, by origin.
 *
 * @return array{total: int, enabled: int, by_origin: array<string, int>}
 */
function counts() {
	global $wpdb;
	$out = array(
		'total'     => 0,
		'enabled'   => 0,
		'by_origin' => array(),
	);
	if ( ! table_ready() ) {
		return $out;
	}
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT origin, enabled, COUNT(*) AS n FROM %i GROUP BY origin, enabled', table( 'redirects' ) ), ARRAY_A ) as $row ) {
		$n                           = (int) $row['n'];
		$origin                      = '' === $row['origin'] ? 'screen' : $row['origin'];
		$out['total']               += $n;
		$out['enabled']             += $row['enabled'] ? $n : 0;
		$out['by_origin'][ $origin ] = ( $out['by_origin'][ $origin ] ?? 0 ) + $n;
	}
	return $out;
}

/**
 * How the Redirects tab names an origin.
 *
 * @param string $origin Stored origin.
 * @return string
 */
function origin_label( $origin ) {
	$labels = array(
		''        => __( 'Added here', 'tgd-site-kit' ),
		'import'  => __( 'Imported', 'tgd-site-kit' ),
		'connect' => __( 'TGD Connect', 'tgd-site-kit' ),
		'auto'    => __( 'Automatic', 'tgd-site-kit' ),
	);
	return $labels[ (string) $origin ] ?? (string) $origin;
}

/**
 * A row with typed values, for the screen and the REST route.
 *
 * @param array $row Raw row.
 * @return array
 */
function shape( array $row ) {
	return array(
		'id'                 => (int) $row['id'],
		'source'             => (string) $row['source'],
		'is_regex'           => (bool) $row['is_regex'],
		'query_mode'         => (string) $row['query_mode'],
		'match_case'         => (bool) $row['match_case'],
		'match_trailing'     => (bool) $row['match_trailing'],
		'status'             => (int) $row['status'],
		'target'             => (string) $row['target'],
		'enabled'            => (bool) $row['enabled'],
		'replaces_published' => (bool) ( $row['replaces_published'] ?? false ),
		'note'               => (string) $row['note'],
		'origin'             => (string) $row['origin'],
		'hits'               => (int) $row['hits'],
		'last_hit'           => $row['last_hit'] ? mysql_to_rfc3339( $row['last_hit'] ) : null,
		'created_gmt'        => (string) $row['created_gmt'],
		'modified_gmt'       => (string) $row['modified_gmt'],
		'import_hash'        => (string) $row['import_hash'],
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
