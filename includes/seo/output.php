<?php
/**
 * Printing the SEO tags, and previewing them.
 *
 * Hook placement: the title through `pre_get_document_title` (and `wp_title`, for
 * older themes) at 100; canonical, description and verification tags on
 * `wp_head` at 10 with core's own canonical removed; robots through core's
 * `wp_robots` filter, so there is still exactly one robots tag; Open Graph then
 * Twitter on `wp_head` at 50.
 *
 * Every tag comes out of head_tags(), whether it is being printed for real or
 * shown in the preview, so the preview cannot disagree with the real thing.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

/**
 * Add the output hooks.
 */
function add_output_hooks() {
	add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\filter_title', 100 );
	add_filter( 'wp_title', __NAMESPACE__ . '\\filter_title', 100 );
	remove_action( 'wp_head', 'rel_canonical' );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_head', 10 );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_social', 50 );
	remove_filter( 'wp_robots', 'wp_robots_noindex_search' );
	add_filter( 'wp_robots', __NAMESPACE__ . '\\filter_robots' );
	add_filter( 'feed_links_extra_show_author_feed', __NAMESPACE__ . '\\filter_author_feed_link' );
	if ( ! empty( settings()['general']['suppress-generator'] ) ) {
		remove_action( 'wp_head', 'wp_generator' );
	}
}

/**
 * Add the preview hook.
 */
function add_preview_hooks() {
	add_action( 'wp_head', __NAMESPACE__ . '\\print_preview', 1 );
}

/**
 * The document title, escaped with esc_html(); the incoming title
 * when this page has nothing to say.
 *
 * @param string $title Title so far.
 * @return string
 */
function filter_title( $title ) {
	if ( ! \TGD_Site_Kit\is_front_end_request() ) {
		return $title;
	}
	$entity = current_entity();
	return $entity ? esc_html( wp_strip_all_tags( stripslashes( $entity->title() ) ) ) : $title;
}

/**
 * The robots directives to add, with `index,follow` itself left implied.
 *
 * @return string Comma separated, or ''.
 */
function robots_string() {
	$entity = current_entity();
	$robots = $entity ? $entity->robots() : '';
	if ( 'index,follow' === $robots ) {
		$robots = '';
	}
	if ( str_starts_with( $robots, 'index,follow,' ) ) {
		$robots = substr( $robots, strlen( 'index,follow,' ) );
	}
	return rtrim( $robots, ',' );
}

/**
 * Merge our directives into core's robots tag.
 *
 * Left alone on a site that discourages search engines: core's own noindex
 * is the one that matters there.
 *
 * @param array $robots Directive => value.
 * @return array
 */
function filter_robots( $robots ) {
	if ( ! \TGD_Site_Kit\is_front_end_request() || 1 !== (int) get_option( 'blog_public' ) ) {
		return $robots;
	}
	$ours = robots_string();
	if ( '' === $ours ) {
		return $robots;
	}
	foreach ( explode( ',', $ours ) as $directive ) {
		// `max-snippet:-1` becomes core's `max-snippet => '-1'`, so it replaces
		// core's own value for the same name (max-image-preview) instead of
		// printing beside it.
		$parts = explode( ':', trim( $directive ), 2 );
		if ( isset( $parts[1] ) ) {
			$robots[ $parts[0] ] = $parts[1];
		} else {
			$robots[ $parts[0] ] = true;
		}
	}
	// What says no wins: WooCommerce marks its cart, checkout and account
	// pages noindex, nofollow on this same filter, and one tag must not say
	// both.
	if ( ! empty( $robots['noindex'] ) ) {
		unset( $robots['index'], $robots['max-image-preview'] );
	}
	if ( ! empty( $robots['nofollow'] ) ) {
		unset( $robots['follow'] );
	}
	return $robots;
}

/**
 * Every tag this module prints in the head for the current page, in order.
 *
 * @param string $group `head` (canonical, description, verification) or
 *                      `social` (Open Graph, Twitter).
 * @return string[] Tag markup, one per line.
 */
function head_tags( $group ) {
	$entity = current_entity();
	$lines  = array();

	if ( 'head' === $group ) {
		$canonical = $entity ? $entity->canonical() : '';
		if ( $canonical && ! empty( settings()['general']['suppress-redundant-canonical'] ) ) {
			global $wp;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compares the URL; reads nothing.
			if ( add_query_arg( $_GET, trailingslashit( home_url( $wp->request ) ) ) === $canonical ) {
				$canonical = '';
			}
		}
		if ( $canonical ) {
			$lines[] = '<link rel="canonical" href="' . esc_attr( $canonical ) . '" />';
		}
		$links = $entity ? $entity->pagination_links() : array();
		if ( ! $links && ! empty( settings()['general']['pagination_links'] ) ) {
			$links = pagination_links();
		}
		foreach ( $links as $rel => $href ) {
			$lines[] = '<link rel="' . esc_attr( $rel ) . '" href="' . esc_url( $href ) . '" />';
		}
		$description = $entity ? wp_kses( wp_strip_all_tags( stripslashes( $entity->description() ) ), array() ) : '';
		if ( '' !== $description ) {
			$lines[] = '<meta name="description" content="' . esc_attr( $description ) . '" />';
		}
		foreach ( verification_tags() as $tag ) {
			$lines[] = $tag;
		}
		return $lines;
	}

	$social = settings()['social'];
	if ( ! empty( $social['og-enable'] ) ) {
		if ( ! empty( $social['fb-app-id'] ) ) {
			$lines[] = og_tag( 'fb:app_id', $social['fb-app-id'] );
		}
		if ( $entity && $entity->opengraph_enabled() ) {
			$lines = array_merge( $lines, og_tags( $entity->opengraph_tags() ) );
		}
	}
	if ( ! empty( $social['twitter-card-enable'] ) && $entity && $entity->twitter_enabled() ) {
		$images = $entity->twitter_images();
		$card   = ! empty( $social['twitter-card-type'] ) ? $social['twitter-card-type'] : 'summary_large_image';
		if ( 'summary_large_image' === $card && ! $images ) {
			$card = 'summary';
		}
		$lines[] = twitter_tag( 'card', $card );
		if ( ! empty( $social['twitter_username'] ) ) {
			$lines[] = twitter_tag( 'site', $social['twitter_username'] );
		}
		foreach ( array(
			'title'       => $entity->twitter_title(),
			'description' => $entity->twitter_description(),
		) as $type => $value ) {
			if ( '' !== $value ) {
				$lines[] = twitter_tag( $type, $value );
			}
		}
		if ( $images ) {
			$lines[] = twitter_tag( 'image', (string) array_key_first( $images ) );
		}
	}
	return $lines;
}

/**
 * `rel="prev"` and `rel="next"` for the page being shown (0.6.0, setting
 * `general.pagination_links`, off by default): paginated archives (the blog,
 * terms, authors, dates, post type archives) and posts split with page
 * breaks. Never on search results. The links are built from the archive's
 * own link, never from the request, so a visitor's query string can't reach
 * them.
 *
 * A split post gets none while core's adjacent-post links are hooked (they
 * would be a second set); neither Avada nor Elementor prints pagination
 * links in the head, and Avada removes core's adjacent-post links.
 *
 * @return array{prev?: string, next?: string}
 */
function pagination_links() {
	global $wp_query;
	if ( ! $wp_query instanceof \WP_Query || is_search() || is_404() ) {
		return array();
	}
	if ( is_singular() ) {
		if ( false !== has_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' ) ) {
			return array();
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$pages = substr_count( (string) $post->post_content, '<!--nextpage-->' ) + 1;
		$page  = max( 1, (int) get_query_var( 'page' ) );
		$url   = (string) get_permalink( $post );
		$link  = fn( $n ) => $n < 2 ? $url : ( get_option( 'permalink_structure' ) ? trailingslashit( $url ) . user_trailingslashit( (string) $n, 'single_paged' ) : add_query_arg( 'page', $n, $url ) );
		return neighbour_links( $page, $pages, $link );
	}
	$base = archive_base_url();
	if ( '' === $base ) {
		return array();
	}
	return neighbour_links( max( 1, (int) get_query_var( 'paged' ) ), (int) $wp_query->max_num_pages, fn( $n ) => append_page_number( $base, $n ) );
}

/**
 * The previous and next of page $page out of $pages.
 *
 * @param int      $page  This page, from 1.
 * @param int      $pages Pages there are.
 * @param callable $link  Takes a page number, returns its URL.
 * @return array{prev?: string, next?: string}
 */
function neighbour_links( $page, $pages, callable $link ) {
	$out = array();
	if ( $page > 1 && $page <= $pages ) {
		$out['prev'] = $link( $page - 1 );
	}
	if ( $page < $pages ) {
		$out['next'] = $link( $page + 1 );
	}
	return $out;
}

/**
 * The first page's own link of the archive being shown, or ''.
 *
 * @return string
 */
function archive_base_url() {
	if ( is_home() ) {
		$posts_page = (int) get_option( 'page_for_posts' );
		return 'page' === get_option( 'show_on_front' ) && $posts_page ? (string) get_permalink( $posts_page ) : home_url( '/' );
	}
	if ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );
		return is_string( $link ) ? $link : '';
	}
	if ( is_author() ) {
		return (string) get_author_posts_url( (int) get_queried_object_id() );
	}
	if ( is_post_type_archive() ) {
		$type = get_query_var( 'post_type' );
		$type = is_array( $type ) ? reset( $type ) : $type;
		return (string) get_post_type_archive_link( (string) $type );
	}
	if ( is_day() ) {
		return (string) get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
	}
	if ( is_month() ) {
		return (string) get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
	}
	if ( is_year() ) {
		return (string) get_year_link( (int) get_query_var( 'year' ) );
	}
	return '';
}

/**
 * Flatten the tag array, dropping empty values.
 *
 * @param array $tags Property => value, or nested groups.
 * @return string[]
 */
function og_tags( array $tags ) {
	$lines = array();
	foreach ( $tags as $property => $value ) {
		if ( is_array( $value ) ) {
			$lines = array_merge( $lines, og_tags( $value ) );
		} elseif ( $value && is_scalar( $value ) ) {
			$lines[] = og_tag( $property, $value );
		}
	}
	return $lines;
}

/**
 * One Open Graph tag.
 *
 * @param string $property Property.
 * @param mixed  $value    Content.
 * @return string
 */
function og_tag( $property, $value ) {
	return '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( (string) $value ) . '" />';
}

/**
 * One Twitter tag.
 *
 * @param string $type  Name after `twitter:`.
 * @param string $value Content.
 * @return string
 */
function twitter_tag( $type, $value ) {
	return '<meta name="twitter:' . esc_attr( $type ) . '" content="' . esc_attr( $value ) . '" />';
}

/**
 * Search engine verification tags, and any extra meta tags stored with them.
 *
 * Stored as whole tags, as the search engines hand them out, and filtered
 * down to a
 * bare `<meta>` on the way out. These matter more than they look: a Search
 * Console property verified by meta tag loses verification if this disappears.
 *
 * @return string[]
 */
function verification_tags() {
	$v     = settings()['verification'];
	$lines = array();
	if ( empty( $v['pages'] ) || ( 'home' === $v['pages'] && is_front_page() ) ) {
		foreach ( array( 'google', 'bing' ) as $engine ) {
			if ( ! empty( $v[ $engine ] ) ) {
				$lines[] = $v[ $engine ];
			}
		}
	}
	foreach ( (array) $v['additional'] as $tag ) {
		$lines[] = $tag;
	}
	$allowed = array(
		'meta' => array(
			'name'       => array(),
			'content'    => array(),
			'property'   => array(),
			'http-equiv' => array(),
		),
	);
	return array_values( array_filter( array_map( fn( $tag ) => str_contains( (string) $tag, '<meta' ) ? wp_kses( (string) $tag, $allowed ) : '', $lines ) ) );
}

/**
 * Print canonical, description and verification.
 */
function print_head() {
	if ( ! \TGD_Site_Kit\is_front_end_request() ) {
		return;
	}
	foreach ( head_tags( 'head' ) as $line ) {
		echo $line, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in head_tags().
	}
}

/**
 * Print Open Graph and Twitter.
 */
function print_social() {
	if ( ! \TGD_Site_Kit\is_front_end_request() ) {
		return;
	}
	foreach ( head_tags( 'social' ) as $line ) {
		echo $line, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in head_tags().
	}
}

/**
 * For an administrator visiting with `?tgdsk_seo_preview=1`: an HTML comment
 * listing every tag this module would print on this page.
 *
 * A comment, so it changes nothing a crawler or a browser reads. Read only
 * and owner only, so there is nothing for a nonce to protect.
 */
function print_preview() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, owner only.
	if ( empty( $_GET['tgdsk_seo_preview'] ) || ! current_user_can( OWNER_CAP ) || ! \TGD_Site_Kit\is_front_end_request() ) {
		return;
	}
	$entity = current_entity();
	$lines  = array(
		'TGD Site Kit SEO preview',
		'<title>' . ( $entity ? esc_html( wp_strip_all_tags( stripslashes( $entity->title() ) ) ) : '(theme default)' ) . '</title>',
		'robots additions: ' . ( '' === robots_string() ? '(none)' : robots_string() ),
	);
	$lines  = array_merge( $lines, head_tags( 'head' ), head_tags( 'social' ) );
	echo "<!--\n", esc_html( str_replace( '--', '- -', implode( "\n", $lines ) ) ), "\n-->\n";
}
