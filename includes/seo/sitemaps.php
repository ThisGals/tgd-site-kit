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
 *   The ignored post IDs are listed on the SEO tab and in each post's SEO box,
 *   where they can be put back (0.4.3; before that nothing showed them).
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

/**
 * The post IDs left out of the sitemap by setting, as integers, once each.
 *
 * @return int[]
 */
function excluded_post_ids() {
	return array_values( array_unique( array_filter( array_map( 'intval', (array) settings()['sitemap']['exclude_post_ids'] ) ) ) );
}

/**
 * Whether a post is left out of the sitemap by setting.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function post_sitemap_excluded( $post_id ) {
	return in_array( (int) $post_id, excluded_post_ids(), true );
}

/**
 * Store a new list of excluded post IDs, touching nothing else in the stored
 * option: not the other sitemap keys and not the other sections, which stay
 * exactly as they were (missing ones are not filled in with defaults).
 *
 * @param int[] $ids Post IDs.
 */
function store_excluded_post_ids( array $ids ) {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	if ( ! isset( $stored['sitemap'] ) || ! is_array( $stored['sitemap'] ) ) {
		$stored['sitemap'] = array();
	}
	$stored['sitemap']['exclude_post_ids'] = array_values( array_map( 'intval', $ids ) );
	update_option( OPTION, $stored, true );
	settings( true );
}

/**
 * Put one post into, or take it out of, the excluded list.
 *
 * @param int  $post_id Post ID.
 * @param bool $exclude True to leave it out of the sitemap.
 * @return bool Whether anything changed.
 */
function set_post_sitemap_excluded( $post_id, $exclude ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || post_sitemap_excluded( $post_id ) === (bool) $exclude ) {
		return false;
	}
	$ids = array_map( 'intval', (array) settings()['sitemap']['exclude_post_ids'] );
	$ids = $exclude ? array_merge( $ids, array( $post_id ) ) : array_filter( $ids, fn( $id ) => $id !== $post_id );
	store_excluded_post_ids( $ids );
	return true;
}

/**
 * Take several IDs out of the excluded list; the rest stay in their order.
 *
 * @param int[] $remove Post IDs.
 * @return int How many were taken out.
 */
function remove_excluded_post_ids( array $remove ) {
	$remove = array_map( 'intval', $remove );
	$ids    = array_map( 'intval', (array) settings()['sitemap']['exclude_post_ids'] );
	$keep   = array_filter( $ids, fn( $id ) => ! in_array( $id, $remove, true ) );
	if ( count( $keep ) === count( $ids ) ) {
		return 0;
	}
	store_excluded_post_ids( $keep );
	return count( array_unique( array_diff( $ids, $keep ) ) );
}

/**
 * Whether the kit's sitemap rules are in force on this request (the module
 * is on and SmartCrawl is off).
 *
 * @return bool
 */
function sitemap_rules_active() {
	return false !== has_filter( 'wp_sitemaps_post_types', __NAMESPACE__ . '\\filter_sitemap_post_types' );
}

/**
 * Whether the kit's noindex rule leaves a post out, as the sitemap query in
 * filter_sitemap_posts_query() applies it.
 *
 * @param \WP_Post $post Post.
 * @return bool
 */
function sitemap_noindexed( \WP_Post $post ) {
	return onpage( "meta_robots-noindex-{$post->post_type}" )
		? ! metadata_exists( 'post', $post->ID, POST_META_PREFIX . 'index' )
		: metadata_exists( 'post', $post->ID, POST_META_PREFIX . 'noindex' );
}

/**
 * Whether a post is in WordPress's core sitemap now, and if not, why.
 *
 * Asked of core itself: its posts provider's mapped types and the same query
 * arguments its sitemap pages run, every plugin's filters included, narrowed
 * to this one post. The reason then names which rule left it out:
 *
 * - `sitemaps_off`: core's sitemap is off (SmartCrawl switches it off while
 *   active; so does "Discourage search engines"), or has no posts sitemap.
 * - `not_published`: core lists published posts only.
 * - `type_excluded`: its post type is ticked under "Leave out post types".
 * - `type_not_mapped`: its type isn't in core's sitemap for another reason
 *   (not public, no `show_ui`, or core or a plugin leaves it out).
 * - `post_excluded`: the post itself is left out (the kit's list, or another
 *   plugin's `post__not_in`).
 * - `noindex`: the kit's noindex rule.
 * - `other`: core's query leaves it out for a reason none of the above names.
 *
 * @param \WP_Post $post Post.
 * @return array{in_sitemap: bool, reason: string|null}
 */
function post_sitemap_status( \WP_Post $post ) {
	$out    = fn( $reason ) => array(
		'in_sitemap' => null === $reason,
		'reason'     => $reason,
	);
	$server = wp_sitemaps_get_server();
	if ( ! $server->sitemaps_enabled() ) {
		return $out( 'sitemaps_off' );
	}
	$provider = $server->registry->get_provider( 'posts' );
	if ( ! $provider instanceof \WP_Sitemaps_Provider ) {
		return $out( 'sitemaps_off' );
	}
	if ( 'publish' !== $post->post_status ) {
		return $out( 'not_published' );
	}
	$rules = sitemap_rules_active();
	if ( ! isset( $provider->get_object_subtypes()[ $post->post_type ] ) ) {
		return $out( $rules && in_array( $post->post_type, (array) settings()['sitemap']['exclude_post_types'], true ) ? 'type_excluded' : 'type_not_mapped' );
	}
	// The arguments core's WP_Sitemaps_Posts::get_posts_query_args() builds.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hook, applied as core applies it.
	$args = apply_filters(
		'wp_sitemaps_posts_query_args',
		array(
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'post_type'              => $post->post_type,
			'posts_per_page'         => wp_sitemaps_get_max_urls( 'post' ),
			'post_status'            => array( 'publish' ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'ignore_sticky_posts'    => true,
		),
		$post->post_type
	);
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	if ( in_array( $post->ID, array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) ), true ) ) {
		return $out( 'post_excluded' );
	}
	// WP_Query ignores post__not_in once post__in is set, so it is checked
	// above and dropped here.
	unset( $args['post__not_in'], $args['paged'] );
	$args['post__in']       = array( $post->ID );
	$args['posts_per_page'] = 1;
	$args['fields']         = 'ids';
	$query                  = new \WP_Query( $args );
	if ( $query->posts ) {
		return $out( null );
	}
	return $out( $rules && sitemap_noindexed( $post ) ? 'noindex' : 'other' );
}

/**
 * A sitemap reason in words, for the SEO box.
 *
 * @param string $reason Reason code from post_sitemap_status().
 * @return string
 */
function sitemap_reason_text( $reason ) {
	$texts = array(
		'sitemaps_off'    => __( 'WordPress’s sitemap is off (SmartCrawl switches it off while active, and so does “Discourage search engines”).', 'tgd-site-kit' ),
		'not_published'   => __( 'Only published posts are listed.', 'tgd-site-kit' ),
		'type_excluded'   => __( 'Its post type is ticked under “Leave out post types”.', 'tgd-site-kit' ),
		'type_not_mapped' => __( 'Its post type isn’t in the sitemap (not public, no admin screen, or left out by WordPress or a plugin).', 'tgd-site-kit' ),
		'post_excluded'   => __( 'It is on the list of posts left out of the sitemap.', 'tgd-site-kit' ),
		'noindex'         => __( 'It is noindexed.', 'tgd-site-kit' ),
		'other'           => __( 'WordPress’s sitemap query leaves it out (another plugin’s rule).', 'tgd-site-kit' ),
	);
	return $texts[ $reason ] ?? (string) $reason;
}

/**
 * One excluded ID, described for the SEO tab and the import report.
 *
 * @param int $post_id Post ID.
 * @return array{id: int, missing: bool, title: string, type: string, status: string}
 */
function describe_excluded_post( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return array(
			'id'      => (int) $post_id,
			'missing' => true,
			'title'   => '',
			'type'    => '',
			'status'  => '',
		);
	}
	return array(
		'id'      => $post->ID,
		'missing' => false,
		'title'   => $post->post_title,
		'type'    => $post->post_type,
		'status'  => $post->post_status,
	);
}
