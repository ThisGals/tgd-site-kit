<?php
/**
 * Sitemaps and robots.txt.
 *
 * The sitemap is WordPress core's, at `/wp-sitemap.xml`. What this file adds:
 *
 * - **Redirects from the common sitemap addresses**, because `/sitemap.xml` is
 *   what is usually submitted to Search Console. `/sitemap.xml` and
 *   `/sitemap_index.xml` go to the index, and `/{type}-sitemap.xml` (and
 *   `-sitemap2.xml` and so on) to the matching core file. 301s, issued before
 *   core's own 404 handling gets a look.
 * - **Exclusions**: post types and taxonomies left out by setting, posts left
 *   out one by one, and anything noindexed - a sitemap listing a page that
 *   asks not to be indexed is a contradiction Search Console reports. The
 *   posts left out are listed on the SEO tab and in each post's SEO box,
 *   where they can be put back (0.4.3).
 * - **No author sitemap**, unless the setting includes it.
 *
 * robots.txt is served from the stored text when there is one. Left empty,
 * core's default stands.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Add the sitemap and robots.txt hooks.
 */
function add_sitemap_hooks() {
	add_filter( 'awb_role_manager_access_capability', __NAMESPACE__ . '\\note_registered_ui', PHP_INT_MAX - 1, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Avada's hook, read only.
	add_action( 'template_redirect', __NAMESPACE__ . '\\redirect_old_sitemaps', 0 );
	add_filter( 'wp_sitemaps_post_types', __NAMESPACE__ . '\\filter_sitemap_post_types' );
	add_filter( 'wp_sitemaps_taxonomies', __NAMESPACE__ . '\\filter_sitemap_taxonomies' );
	add_filter( 'wp_sitemaps_add_provider', __NAMESPACE__ . '\\filter_sitemap_providers', 10, 2 );
	add_filter( 'wp_sitemaps_posts_query_args', __NAMESPACE__ . '\\filter_sitemap_posts_query', 10, 2 );
	add_filter( 'wp_sitemaps_taxonomies_query_args', __NAMESPACE__ . '\\filter_sitemap_terms_query', 10, 2 );
	add_filter( 'robots_txt', __NAMESPACE__ . '\\filter_robots_txt', 100, 2 );
	add_action( 'wp_sitemaps_init', __NAMESPACE__ . '\\register_extra_sitemap' );
}

/**
 * Where a common sitemap address lives in core's scheme, if anywhere.
 *
 * @param string $path Request path without the leading slash.
 * @return string Core path, or '' when the path is not one of them.
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
 * Send the common sitemap addresses to core's.
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
 * Note the admin-screen value Avada registers a type with, before its role
 * manager makes it depend on who is asking.
 *
 * Avada registers Portfolio, FAQs and its sliders with `show_ui` set to
 * `apply_filters( 'awb_role_manager_access_capability', true, $type )`, and
 * its role manager (priority `PHP_INT_MAX`) answers that per user: true for an
 * administrator, false for a logged-out visitor, and false for a REST request
 * signed in with an application password, because that user is only known
 * after types are registered. This runs just before the role manager and
 * keeps the value as it would stand for an administrator (the role manager
 * never changes an administrator's), so the sitemap can be the same for
 * everyone. Added before `init`, where the types are registered.
 *
 * @param mixed       $value       The capability, or a bool at registration.
 * @param string|null $context     Post type or area.
 * @param string      $sub_context Sub-area.
 * @return mixed Unchanged.
 */
function note_registered_ui( $value, $context = null, $sub_context = 'dashboard_access' ) {
	if ( is_bool( $value ) && is_string( $context ) && 'dashboard_access' === $sub_context ) {
		registered_ui( $context, $value );
	}
	return $value;
}

/**
 * The admin-screen value noted for a type at registration, if any.
 *
 * @param string    $name  Post type or taxonomy name.
 * @param bool|null $value Value to note; null to read.
 * @return bool|null
 */
function registered_ui( $name, $value = null ) {
	static $noted = array();
	if ( null !== $value ) {
		$noted[ $name ] = (bool) $value;
	}
	return $noted[ $name ] ?? null;
}

/**
 * Whether a post type or taxonomy has an admin screen, the same whoever is
 * asking: `show_ui`, or the value it was registered with before Avada's role
 * manager made `show_ui` per user.
 *
 * @param \WP_Post_Type|\WP_Taxonomy $item Type or taxonomy.
 * @return bool
 */
function has_admin_screen( $item ) {
	return (bool) $item->show_ui || true === registered_ui( $item->name );
}

/**
 * Leave out the post types excluded by setting, and those with no admin
 * screen.
 *
 * Core maps every public type; the kit maps only those that also have an
 * admin screen, so a theme's internal types stay out. Avada's builder
 * taxonomies (layout sections, library elements) are public with no admin
 * screen, so on an Avada site they stay out.
 *
 * The admin screen is judged by has_admin_screen(), never by `show_ui` at
 * request time: Avada's role manager makes `show_ui` per user, and before
 * 0.4.4 that left Portfolio and FAQs out of the sitemap for logged-out
 * visitors (Google included) while an administrator saw them listed.
 *
 * @param array $types Name => WP_Post_Type.
 * @return array
 */
function filter_sitemap_post_types( $types ) {
	foreach ( (array) settings()['sitemap']['exclude_post_types'] as $name ) {
		unset( $types[ $name ] );
	}
	return array_filter( $types, __NAMESPACE__ . '\\has_admin_screen' );
}

/**
 * Leave out taxonomies by the same two rules.
 *
 * @param array $taxonomies Name => WP_Taxonomy.
 * @return array
 */
function filter_sitemap_taxonomies( $taxonomies ) {
	foreach ( (array) settings()['sitemap']['exclude_taxonomies'] as $name ) {
		unset( $taxonomies[ $name ] );
	}
	return array_filter( $taxonomies, __NAMESPACE__ . '\\has_admin_screen' );
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
	// Published pages a redirect replaces on purpose: nobody can reach them.
	$exclude = array_merge( $exclude, \TGD_Site_Kit\Redirects\hidden_objects()['post'] );
	// Posts whose canonical is another URL, and WooCommerce's private pages.
	$exclude = array_merge( $exclude, canonical_elsewhere_ids( 'post' ), \TGD_Site_Kit\Woo\private_page_ids() );
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
	$hidden               = array_merge( \TGD_Site_Kit\Redirects\hidden_objects()['term'], canonical_elsewhere_ids( 'term' ) );
	if ( $hidden ) {
		$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), $hidden );
	}
	return $args;
}

/**
 * Serve the stored robots.txt, with core's sitemap line when asked for.
 *
 * @param string $output Core's robots.txt so far.
 * @param string $is_public `blog_public`, as a string.
 * @return string
 */
function filter_robots_txt( $output, $is_public ) {
	if ( '0' === (string) $is_public ) {
		return $output;
	}
	$robots = settings()['robots'];
	$custom = trim( (string) $robots['custom'] );
	$shop   = ! empty( $robots['woocommerce'] ) ? \TGD_Site_Kit\Woo\robots_lines() : array();
	if ( '' === $custom ) {
		return $shop ? add_robots_lines( (string) $output, $shop ) : $output;
	}
	$custom = add_robots_lines( $custom, $shop );
	if ( ! empty( $robots['sitemap_line'] ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
		$custom .= "\n\nSitemap: " . esc_url_raw( home_url( '/wp-sitemap.xml' ) );
	}
	return $custom;
}

/**
 * Add Disallow lines to robots.txt text, inside its first group (before the
 * first blank line, so they belong to the `User-agent` above them), leaving
 * out any line already there. Nothing else in the text changes.
 *
 * @param string   $text  robots.txt so far.
 * @param string[] $lines Lines to add.
 * @return string
 */
function add_robots_lines( $text, array $lines ) {
	$have  = array_map( 'trim', preg_split( '/\R/', $text ) );
	$lines = array_values( array_diff( $lines, $have ) );
	if ( ! $lines ) {
		return $text;
	}
	$text  = str_replace( "\r\n", "\n", $text );
	$split = strpos( $text, "\n\n" );
	if ( false === $split ) {
		return rtrim( $text, "\n" ) . "\n" . implode( "\n", $lines ) . ( str_ends_with( $text, "\n" ) ? "\n" : '' );
	}
	return substr( $text, 0, $split ) . "\n" . implode( "\n", $lines ) . substr( $text, $split );
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
 * Posts or terms whose own canonical is a different URL from their own link.
 * Such an item isn't the URL search engines should index, so the sitemap
 * leaves it out (a canonical to itself, or none, keeps it in). Compared
 * without the scheme and the trailing slash.
 *
 * @param string $kind `post` or `term`.
 * @return int[]
 */
function canonical_elsewhere_ids( $kind ) {
	static $memo = array();
	if ( isset( $memo[ $kind ] ) ) {
		return $memo[ $kind ];
	}
	global $wpdb;
	$same = fn( $a, $b ) => untrailingslashit( (string) preg_replace( '#^https?://#i', '', strtolower( trim( (string) $a ) ) ) ) === untrailingslashit( (string) preg_replace( '#^https?://#i', '', strtolower( trim( (string) $b ) ) ) );
	$out  = array();
	if ( 'post' === $kind ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a short list, read once per request.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", POST_META_PREFIX . 'canonical' ), ARRAY_A ) as $row ) {
			$link = get_permalink( (int) $row['post_id'] );
			if ( $link && ! $same( $row['meta_value'], $link ) ) {
				$out[] = (int) $row['post_id'];
			}
		}
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''", TERM_META_PREFIX . 'canonical' ), ARRAY_A ) as $row ) {
			$link = get_term_link( (int) $row['term_id'] );
			if ( is_string( $link ) && ! $same( $row['meta_value'], $link ) ) {
				$out[] = (int) $row['term_id'];
			}
		}
	}
	$memo[ $kind ] = array_values( array_unique( $out ) );
	return $memo[ $kind ];
}

/**
 * Whether the kit's sitemap rules are in force on this request (the module
 * is on).
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
 * - `sitemaps_off`: core's sitemap is off (another plugin can switch it off,
 *   and so does "Discourage search engines"), or has no posts sitemap.
 * - `not_published`: core lists published posts only.
 * - `type_excluded`: its post type is ticked under "Leave out post types".
 * - `type_not_mapped`: its type isn't in core's sitemap for another reason
 *   (not public, no admin screen as registered, or core or a plugin leaves
 *   it out). The kit's own rules give the same answer whoever is asking, so
 *   this describes what a logged-out visitor gets, not only the reader.
 * - `redirected`: a redirect marked "replaces a published page" hides it
 *   (Redirects\hidden_objects()).
 * - `shop_private`: WooCommerce's cart, checkout or account page.
 * - `canonical_elsewhere`: its own canonical is another URL
 *   (canonical_elsewhere_ids()).
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
	if ( in_array( (int) $post->ID, \TGD_Site_Kit\Redirects\hidden_objects()['post'], true ) ) {
		return $out( 'redirected' );
	}
	if ( in_array( (int) $post->ID, \TGD_Site_Kit\Woo\private_page_ids(), true ) ) {
		return $out( 'shop_private' );
	}
	if ( in_array( (int) $post->ID, canonical_elsewhere_ids( 'post' ), true ) ) {
		return $out( 'canonical_elsewhere' );
	}
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
		'sitemaps_off'        => __( 'WordPress’s sitemap is off (another plugin may have switched it off, and “Discourage search engines” does too).', 'tgd-site-kit' ),
		'not_published'       => __( 'Only published posts are listed.', 'tgd-site-kit' ),
		'redirected'          => __( 'A redirect marked “Replaces a published page” sends visitors elsewhere.', 'tgd-site-kit' ),
		'canonical_elsewhere' => __( 'Its canonical is another URL, so that URL is the one to list.', 'tgd-site-kit' ),
		'shop_private'        => __( 'It is WooCommerce’s cart, checkout or account page, which is never indexed.', 'tgd-site-kit' ),
		'type_excluded'       => __( 'Its post type is ticked under “Leave out post types”.', 'tgd-site-kit' ),
		'type_not_mapped'     => __( 'Its post type isn’t in the sitemap: it isn’t public, it was registered with no admin screen, or WordPress or another plugin leaves it out. The kit’s own rules decide this the same way for every visitor, signed in or not.', 'tgd-site-kit' ),
		'post_excluded'       => __( 'It is on the list of posts left out of the sitemap.', 'tgd-site-kit' ),
		'noindex'             => __( 'It is noindexed.', 'tgd-site-kit' ),
		'other'               => __( 'WordPress’s sitemap query leaves it out (another plugin’s rule).', 'tgd-site-kit' ),
	);
	return $texts[ $reason ] ?? (string) $reason;
}

/**
 * One excluded ID, described for the SEO tab.
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
