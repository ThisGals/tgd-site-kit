<?php
/**
 * Redirects at request time.
 *
 * Runs on `init`, as Redirection does, so a matched request never reaches the
 * main query. Exact redirects are tried before regex ones, each in ID order;
 * the first that matches wins.
 *
 * Matching, per redirect (Redirection's defaults are case-insensitive,
 * trailing slash ignored, query exact, and the import keeps each item's own):
 *
 * - path: decoded, compared case-insensitively unless `match_case`, with a
 *   trailing slash ignored unless `match_trailing`;
 * - query `exact`: the request's parameters must be the source's, no more and
 *   no fewer (no query on either side matches); `ignore`: the query is not
 *   looked at and is dropped; `pass`: not looked at, and added to the target;
 * - regex: the pattern runs against the decoded path plus `?query`, and the
 *   target may use `$1` and the rest.
 *
 * The Location header is the target as stored, so a relative target stays
 * relative, which is what Redirection sends.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * The 410 to send once the query has run, when one matched.
 *
 * @param bool|null $set True to mark, null to read.
 * @return bool
 */
function gone( $set = null ) {
	static $gone = false;
	if ( null !== $set ) {
		$gone = (bool) $set;
	}
	return $gone;
}

/**
 * Add the request-time hooks.
 */
function add_match_hooks() {
	add_action( 'init', __NAMESPACE__ . '\\maybe_redirect', 10 );
}

/**
 * The current request's path and query, or null when it is not one to match.
 *
 * @return array{path: string, query: string}|null
 */
function current_request() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return null;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return null;
	}
	// Only parsed here; every use below compares or rebuilds it.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' === $uri ) {
		return null;
	}
	$parts = split_url( (string) $uri );
	if ( is_protected( $parts['path'], $parts['query'] ) ) {
		return null;
	}
	return array(
		'path'  => $parts['path'],
		'query' => $parts['query'],
	);
}

/**
 * A query string as a sorted parameter array, for comparing.
 *
 * @param string $query Query string.
 * @param bool   $lower Lowercase it first.
 * @return array
 */
function query_params( $query, $lower ) {
	$params = array();
	parse_str( $lower ? strtolower( (string) $query ) : (string) $query, $params );
	ksort( $params );
	return $params;
}

/**
 * Where an exact redirect sends this request, or null when it doesn't match.
 *
 * @param array $row     Raw row.
 * @param array $request {path, query}.
 * @return string|null Target ('' for a 410).
 */
function exact_target( array $row, array $request ) {
	$source = split_url( $row['source'] );
	$a      = $request['path'];
	$b      = $source['path'];
	if ( ! $row['match_trailing'] ) {
		$a = '/' === $a ? $a : untrailingslashit( $a );
		$b = '/' === $b ? $b : untrailingslashit( $b );
	}
	if ( $row['match_case'] ? $a !== $b : strtolower( $a ) !== strtolower( $b ) ) {
		return null;
	}
	if ( 'exact' === $row['query_mode'] && query_params( $request['query'], ! $row['match_case'] ) !== query_params( $source['query'], ! $row['match_case'] ) ) {
		return null;
	}
	return with_query( (string) $row['target'], $row, $request );
}

/**
 * Where a regex redirect sends this request, or null when it doesn't match.
 *
 * @param array $row     Raw row.
 * @param array $request {path, query}.
 * @return string|null
 */
function regex_target( array $row, array $request ) {
	$subject = $request['path'] . ( '' !== $request['query'] ? '?' . $request['query'] : '' );
	$pattern = regex_pattern( $row['source'], (bool) $row['match_case'] );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern saved before a PCRE change must not break the page.
	if ( ! @preg_match( $pattern, $subject ) ) {
		return null;
	}
	if ( '' === (string) $row['target'] ) {
		return '';
	}
	// Redirection's replace: the whole request through preg_replace(), so a
	// pattern that doesn't cover all of it keeps the rest. Imported regexes
	// must answer with the same Location they did there.
	$target = (string) preg_replace( $pattern, (string) $row['target'], $subject );
	return with_query( str_replace( ' ', '%20', $target ), $row, $request );
}

/**
 * Add the request's query to a `pass` redirect's target.
 *
 * @param string $target  Target.
 * @param array  $row     Raw row.
 * @param array  $request {path, query}.
 * @return string
 */
function with_query( $target, array $row, array $request ) {
	if ( 'pass' !== $row['query_mode'] || '' === $request['query'] || '' === $target ) {
		return $target;
	}
	return $target . ( str_contains( $target, '?' ) ? '&' : '?' ) . $request['query'];
}

/**
 * Find the first redirect for this request and send it.
 */
function maybe_redirect() {
	$request = current_request();
	if ( null === $request ) {
		return;
	}
	foreach ( candidates( $request['path'] ) as $row ) {
		$target = $row['is_regex'] ? regex_target( $row, $request ) : exact_target( $row, $request );
		if ( null === $target ) {
			continue;
		}
		if ( settings()['track_hits'] ) {
			record_hit( (int) $row['id'] );
		}
		$status = (int) $row['status'];
		if ( 410 === $status ) {
			gone( true );
			add_action( 'template_redirect', __NAMESPACE__ . '\\send_gone', 0 );
			return;
		}
		send_redirect( $target, $status );
		return;
	}
}

/**
 * Send a redirect and stop.
 *
 * @param string $target Location.
 * @param int    $status 301, 302, 307 or 308.
 */
function send_redirect( $target, $status ) {
	$hours = settings()['cache_hours'];
	if ( in_array( $status, array( 301, 308 ), true ) && $hours > 0 ) {
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s T', time() + $hours * HOUR_IN_SECONDS ) );
		header( 'Cache-Control: max-age=' . ( $hours * HOUR_IN_SECONDS ) );
	} else {
		nocache_headers();
	}
	// Not wp_safe_redirect(): a redirect to another site is allowed, and the
	// target was checked when it was saved.
	wp_redirect( $target, $status, 'TGD Site Kit' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}

/**
 * A 410: the theme's not-found page with status 410 and no caching.
 */
function send_gone() {
	global $wp_query;
	if ( ! gone() ) {
		return;
	}
	$wp_query->set_404();
	status_header( 410 );
	nocache_headers();
	header( 'X-Redirect-By: TGD Site Kit' );
}
