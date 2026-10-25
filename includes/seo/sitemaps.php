<?php
/**
 * Sitemaps and robots.txt, once SmartCrawl is off.
 *
 * WordPress core's sitemap at `/wp-sitemap.xml` takes over from SmartCrawl's.
 * SmartCrawl turns the core one off while it runs, so it comes back on its own
 * the moment SmartCrawl is deactivated; what this file adds is:
 *
 * - **Redirects from SmartCrawl's URLs**, because `/sitemap.xml` is what was
 *   submitted to Search Console. `/sitemap.xml` goes to the index, and
 *   `/{type}-sitemap.xml` (and `-sitemap2.xml` and so on) to the matching core
 *   file. 301s, issued before core's own 404 handling gets a look.
 * - **SmartCrawl's exclusions carried over**: post types and taxonomies it left
 *   out, post IDs it ignored, and anything noindexed - a sitemap listing a page
 *   that asks not to be indexed is a contradiction Search Console reports.
 * - **No author sitemap**, unless the import found author archives enabled.
 *
 * robots.txt is served from the imported text when there is one, because
 * SmartCrawl replaces core's file outright and the point is that it does not
 * change. Left empty, core's default stands.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Add the sitemap and robots.txt hooks. Only called when SmartCrawl is off.
 */
function add_sitemap_hooks() {
	add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_old_sitemaps', 0 );
	add_filter( 'wp_sitemaps_post_types', __NAMESPACE__ . '\\filter_sitemap_post_types' );
	add_filter( 'wp_sitemaps_taxonomies', __NAMESPACE__ . '\\filter_sitemap_taxonomies' );
	add_filter( 'wp_sitemaps_add_provider', __NAMESPACE__ . '\\filter_sitemap_providers', 10, 2 );
	add_filter( 'wp_sitemaps_posts_query_args', __NAMESPACE__ . '\\filter_sitemap_posts_query', 10, 2 );
	add_filter( 'wp_sitemaps_taxonomies_query_args', __NAMESPACE__ . '\\filter_sitemap_terms_query', 10, 2 );
	add_filter( 'robots_txt', __NAMESPACE__ . '\\filter_robots_txt', 100, 2 );
}

/**
 * Where a SmartCrawl sitemap path lives in core's scheme, if anywhere.
 *
 * @param string $path Request path without the leading slash.
 * @return string Core path, or '' when the path is not a SmartCrawl sitemap.
 */
function core_sitemap_path( $path ) {
	if ( 'sitemap.xml' === $path || 'sitemap_index.xml' === $path ) {
		return 'wp-sitemap.xml';
	}
	if ( ! preg_match( '/^([a-z0-9_-]+)-sitemap(\d*)\.xml$/', $path, $m ) ) {
		return '';
	}
	$page = $m[2] ? (int) $m[2] : 1;
	if ( post_type_exists( $m[1] ) ) {
		return sprintf( 'wp-sitemap-posts-%s-%d.xml', $m[1], $page );
	}
	if ( taxonomy_exists( $m[1] ) ) {
		return sprintf( 'wp-sitemap-taxonomies-%s-%d.xml', $m[1], $page );
	}
	return '';
}

/**
 * Send SmartCrawl's sitemap URLs to core's.
 */
function redirect_old_sitemaps() {
	if ( ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path = ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
	$home = ltrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( $home && str_starts_with( $path, $home ) ) {
		$path = substr( $path, strlen( $home ) );
	}
	$target = core_sitemap_path( $path );
	if ( $target ) {
		wp_safe_redirect( home_url( '/' . $target ), 301, 'TGD Site Kit' );
		exit;
	}
}

/**
 * Leave out the post types SmartCrawl left out.
 *
 * Besides the ones excluded by setting, SmartCrawl only ever maps types that
 * have an admin screen (`show_ui`), and core maps every public type. Avada's
 * FAQ items are public with no admin screen of their own, so on an Avada site
 * SmartCrawl's sitemap leaves out indexable FAQ pages that core would
 * have added. Kept as SmartCrawl had it, so the switch adds nothing to what
 * Search Console is told; listing them is a separate decision.
 *
 * @param array $types Name => WP_Post_Type.
 * @return array
 */
function filter_sitemap_post_types( $types ) {
	foreach ( (array) settings()['sitemap']['exclude_post_types'] as $name ) {
		unset( $types[ $name ] );
	}
	return array_filter( $types, fn( $type ) => $type->show_ui );
}

/**
 * Leave out the taxonomies SmartCrawl left out, by the same two rules.
 *
 * @param array $taxonomies Name => WP_Taxonomy.
 * @return array
 */
function filter_sitemap_taxonomies( $taxonomies ) {
	foreach ( (array) settings()['sitemap']['exclude_taxonomies'] as $name ) {
		unset( $taxonomies[ $name ] );
	}
	return array_filter( $taxonomies, fn( $taxonomy ) => $taxonomy->show_ui );
}

/**
 * Drop the author sitemap unless author archives were on.
 *
 * @param mixed  $provider Provider object or false.
 * @param string $name     Provider name.
 * @return mixed
 */
function filter_sitemap_providers( $provider, $name ) {
	return 'users' === $name && empty( settings()['sitemap']['include_users'] ) ? false : $provider;
}

/**
 * Leave out ignored and noindexed posts.
 *
 * A post type noindexed by default contributes only the posts that opted back
 * in; any other type loses only the posts that opted out.
 *
 * @param array  $args      WP_Query arguments.
 * @param string $post_type Post type.
 * @return array
 */
function filter_sitemap_posts_query( $args, $post_type ) {
	$exclude = array_map( 'intval', (array) settings()['sitemap']['exclude_post_ids'] );
	if ( onpage( "meta_robots-noindex-{$post_type}" ) ) {
		$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap pages are cached by crawlers and generated rarely.
			'key'     => POST_META_PREFIX . 'index',
			'compare' => 'EXISTS',
		);
	} else {
		$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above.
			'key'     => POST_META_PREFIX . 'noindex',
			'compare' => 'NOT EXISTS',
		);
	}
	if ( $exclude ) {
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $exclude ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- a handful of IDs.
	}
	return $args;
}

/**
 * Leave out noindexed terms, by the same rule as posts.
 *
 * @param array  $args     get_terms() arguments.
 * @param string $taxonomy Taxonomy.
 * @return array
 */
function filter_sitemap_terms_query( $args, $taxonomy ) {
	$key                  = onpage( "meta_robots-noindex-{$taxonomy}" ) ? 'override_noindex' : 'noindex';
	$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- as above.
		'key'     => TERM_META_PREFIX . $key,
		'compare' => 'override_noindex' === $key ? 'EXISTS' : 'NOT EXISTS',
	);
	return $args;
}

/**
 * Serve the imported robots.txt, with core's sitemap line when asked for.
 *
 * @param string $output Core's robots.txt so far.
 * @param string $is_public `blog_public`, as a string.
 * @return string
 */
function filter_robots_txt( $output, $is_public ) {
	$robots = settings()['robots'];
	$custom = trim( (string) $robots['custom'] );
	if ( '' === $custom || '0' === (string) $is_public ) {
		return $output;
	}
	if ( ! empty( $robots['sitemap_line'] ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
		$custom .= "\n\nSitemap: " . esc_url_raw( home_url( '/wp-sitemap.xml' ) );
	}
	return $custom;
}
