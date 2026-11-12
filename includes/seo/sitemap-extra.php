<?php
/**
 * Extra sitemap URLs (0.6.0): pages on this site WordPress doesn't know
 * about, listed in a sitemap of their own.
 *
 * The list is `sitemap.extra_urls` in the SEO settings, one absolute URL each,
 * written on the SEO tab or through TGD Connect (`/tgdsk/v1/seo/sitemap-extra`,
 * guarded by the list's version like the other SEO writes). A URL is refused
 * when it is not absolute http(s), is on another host, has a fragment, is a
 * duplicate, or is WordPress content (that belongs in the core sitemap, or is
 * left out of it on purpose).
 *
 * **Each URL is checked when it is saved**, never when the sitemap is read:
 * one loopback request each, no redirects followed. Anything but 200 is
 * refused (a redirect or an error page doesn't belong in a sitemap). When the
 * request itself fails (some hosts block loopback requests) the URL is kept
 * and the save says it couldn't be checked.
 *
 * Served through core's provider API as `wp-sitemap-extra-1.xml`, and listed
 * in `/wp-sitemap.xml` only while the list isn't empty.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The extra URLs, as stored.
 *
 * @return string[]
 */
function extra_urls() {
	return array_values( array_filter( array_map( 'strval', (array) settings()['sitemap']['extra_urls'] ) ) );
}

/**
 * The version of the list a write must name.
 *
 * @param string[] $urls List.
 * @return string
 */
function extra_urls_version( array $urls ) {
	return md5( (string) wp_json_encode( array_values( $urls ) ) );
}

/**
 * Check a list of URLs, as typed.
 *
 * @param string[] $lines   One URL each; blank lines are dropped.
 * @param bool     $request Whether to check each URL with a request.
 * @return array{urls: string[], refused: array<string, string>, unchecked: string[]}
 *         Kept URLs in order, refused URL => why, and URLs that couldn't be fetched.
 */
function check_extra_urls( array $lines, $request = true ) {
	$out  = array(
		'urls'      => array(),
		'refused'   => array(),
		'unchecked' => array(),
	);
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$seen = array();
	foreach ( $lines as $line ) {
		$url = trim( (string) $line );
		if ( '' === $url ) {
			continue;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			$out['refused'][ $url ] = __( 'Not an absolute http(s) URL.', 'tgd-site-kit' );
			continue;
		}
		if ( strtolower( $parts['host'] ) !== $host ) {
			/* translators: %s: this site's host */
			$out['refused'][ $url ] = sprintf( __( 'Not on this site (%s).', 'tgd-site-kit' ), $host );
			continue;
		}
		if ( isset( $parts['fragment'] ) ) {
			$out['refused'][ $url ] = __( 'A sitemap URL can’t have a #fragment.', 'tgd-site-kit' );
			continue;
		}
		$key = strtolower( $parts['host'] ) . untrailingslashit( (string) ( $parts['path'] ?? '/' ) ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( isset( $seen[ $key ] ) ) {
			$out['refused'][ $url ] = __( 'Already in the list.', 'tgd-site-kit' );
			continue;
		}
		$path = '/' . ltrim( rawurldecode( (string) ( $parts['path'] ?? '/' ) ), '/' );
		$item = isset( $parts['query'] ) ? null : \TGD_Site_Kit\Redirects\published_item( $path );
		if ( $item ) {
			/* translators: %s: what is published there */
			$out['refused'][ $url ] = sprintf( __( 'WordPress knows this page (%s): it belongs in the core sitemap, or is left out of it on purpose.', 'tgd-site-kit' ), \TGD_Site_Kit\Redirects\describe_item( $item ) );
			continue;
		}
		if ( $request ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
				)
			);
			if ( is_wp_error( $response ) ) {
				$out['unchecked'][] = $url;
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 !== $code ) {
					/* translators: %d: HTTP status */
					$out['refused'][ $url ] = sprintf( __( 'It answers %d, not 200.', 'tgd-site-kit' ), $code );
					continue;
				}
			}
		}
		$seen[ $key ]  = true;
		$out['urls'][] = $url;
	}
	return $out;
}

/**
 * Store the list.
 *
 * @param string[] $urls Checked URLs.
 */
function store_extra_urls( array $urls ) {
	$s                          = settings();
	$s['sitemap']['extra_urls'] = array_values( $urls );
	save_settings( $s );
}

/**
 * Register the extra sitemap, when there is anything in it.
 *
 * @param \WP_Sitemaps $sitemaps Core's sitemaps.
 */
function register_extra_sitemap( $sitemaps ) {
	if ( extra_urls() && class_exists( '\WP_Sitemaps_Provider' ) ) {
		$sitemaps->registry->add_provider( 'extra', new Extra_Sitemap_Provider() );
	}
}

require_once __DIR__ . '/class-extra-sitemap-provider.php';
