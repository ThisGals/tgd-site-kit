<?php
/**
 * What each kind of page says about itself: the SEO module's output rules.
 *
 * **A port of SmartCrawl 3.17.1's entity classes**, read from the copy on the
 * a production site on 2026-10-25, because the goal of the switch is
 * a rendered head that does not change. Where SmartCrawl does something odd,
 * this does the same odd thing, and says so. The two that show on real pages:
 *
 * - A generated description is cut to length with " ..." appended, after
 *   whitespace is collapsed, so a cut that lands after a space leaves two
 *   spaces before the dots in `<meta name="description">`. The Open Graph and
 *   Twitter descriptions are the same text run through the whitespace
 *   collapse a second time, so they have one. Both are reproduced.
 * - The `<title>` is escaped with esc_html() before core prints it, and the
 *   Open Graph title is not, so `&` reads `&amp;` in both once printed but by
 *   different routes.
 *
 * Every value is resolved the same way: the object's own value (post meta,
 * term meta), then the site default template for its location, then a built-in
 * fallback template - each run through strip-tags-and-collapse and then the
 * `%%macro%%` expander. See Entity::string_value().
 *
 * Not ported, and so printing nothing of their own: date archives,
 * BuddyPress, WooCommerce shop pages, WPML canonical rewriting, and schema.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The current page's meta description as it is (or would be) printed, from
 * whichever plugin is in charge of it.
 *
 * While SmartCrawl is active its own resolver answers, so this matches the
 * `<meta name="description">` SmartCrawl prints. Otherwise this module's port
 * answers, whether or not the SEO module is switched on: the rules are the
 * same either way, and a module that only wants the text (schema) should not
 * depend on which plugin prints the tag. Callable from `wp` onwards.
 *
 * @return string Plain text, or ''.
 */
function resolved_description() {
	if ( smartcrawl_active() && class_exists( '\SmartCrawl\Endpoint_Resolver' ) ) {
		$entity = \SmartCrawl\Endpoint_Resolver::resolve()->get_queried_entity();
		$text   = is_object( $entity ) && method_exists( $entity, 'get_meta_description' ) ? (string) $entity->get_meta_description() : '';
	} else {
		$entity = current_entity();
		$text   = $entity ? $entity->description() : '';
	}
	return trim( wp_strip_all_tags( stripslashes( $text ) ) );
}

/**
 * The entity for the current request, mirroring SmartCrawl's resolver order.
 *
 * @return Entity|null Null where nothing is ported, so nothing is printed.
 */
function current_entity() {
	static $entity = false;
	if ( false !== $entity ) {
		return $entity;
	}
	global $wp_query;
	$entity = null;
	if ( ! $wp_query instanceof \WP_Query ) {
		return $entity;
	}
	$paged          = (int) $wp_query->get( 'paged', 0 );
	$page_for_posts = (int) get_option( 'page_for_posts' );

	if ( 'page' === get_option( 'show_on_front' ) && $page_for_posts > 0 && $wp_query->get_queried_object_id() === $page_for_posts ) {
		$entity = new Posts_Page_Entity( $paged );
	} elseif ( is_home() && ( 'posts' === get_option( 'show_on_front' ) || 0 === (int) get_option( 'page_on_front' ) ) ) {
		$entity = new Blog_Home_Entity( $paged );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$entity = new Term_Entity( $wp_query->get_queried_object_id(), $paged );
	} elseif ( is_search() ) {
		$entity = new Search_Entity( (string) $wp_query->get( 's' ), $paged );
	} elseif ( is_author() ) {
		$entity = new Author_Entity( get_user_by( 'id', $wp_query->get_queried_object_id() ), $paged );
	} elseif ( is_post_type_archive() ) {
		$entity = new Post_Type_Archive_Entity( $wp_query->get_queried_object(), $paged );
	} elseif ( is_date() || is_archive() ) {
		$entity = null;
	} elseif ( is_404() ) {
		$entity = new Not_Found_Entity();
	} elseif ( is_singular() ) {
		$entity = new Post_Entity( $wp_query->get_queried_object_id(), (int) $wp_query->get( 'page', 0 ), (int) $wp_query->get( 'cpage', 0 ) );
	}
	return $entity;
}

/**
 * Macros every page has.
 *
 * @return array<string, string|callable>
 */
function general_macros() {
	global $wp_query;
	$paged = $wp_query instanceof \WP_Query ? (int) $wp_query->get( 'paged' ) : 0;
	$max   = $wp_query instanceof \WP_Query && isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 1;
	$num   = $paged ? $paged : ( $max > 1 ? 1 : '' );
	/* translators: 1: current page number, 2: total pages */
	$page_of = __( 'Page %1$s of %2$s', 'tgd-site-kit' );
	return array(
		'%%sitename%%'     => get_bloginfo( 'name' ),
		'%%sitedesc%%'     => get_bloginfo( 'description' ),
		'%%page%%'         => $paged ? sprintf( $page_of, $paged, $max ) : '',
		'%%pagetotal%%'    => $max > 1 ? $max : '',
		'%%pagenumber%%'   => $num,
		'%%currenttime%%'  => fn() => date_i18n( get_option( 'time_format' ) ),
		'%%currentdate%%'  => fn() => date_i18n( get_option( 'date_format' ) ),
		'%%currentmonth%%' => fn() => date_i18n( 'F' ),
		'%%currentyear%%'  => fn() => date_i18n( 'Y' ),
		'%%sep%%'          => separator(),
	);
}

/**
 * Trim, strip tags, collapse whitespace; '' for anything not a string.
 *
 * @param mixed $value Candidate.
 * @return string
 */
function clean( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	return collapse_whitespace( wp_strip_all_tags( trim( $value ) ) );
}

/**
 * Turn tabs and newlines into spaces, then runs of spaces into one.
 *
 * @param string $value Text.
 * @return string
 */
function collapse_whitespace( $value ) {
	$value = str_replace( array( "\r", "\n", "\t" ), ' ', $value );
	return trim( (string) preg_replace( '/ +/', ' ', $value ) );
}

/**
 * Cut text to a length, appending " ..." when it was cut.
 *
 * SmartCrawl's smartcrawl_truncate_meta(), regular expressions included:
 * characters, not bytes, and the cut leaves room for the four added.
 *
 * @param string $value Text.
 * @param int    $limit Maximum length.
 * @return string
 */
function truncate( $value, $limit ) {
	if ( ! preg_match( sprintf( '/.{%d,}/um', $limit + 1 ), $value ) ) {
		return $value;
	}
	return preg_replace( sprintf( '/(.{0,%d}).*/um', $limit - 4 ), '$1', $value ) . ' ...';
}

/**
 * The generated description: excerpt or content, shortcodes unwrapped, tags
 * stripped, entities encoded, whitespace collapsed, cut to length.
 *
 * @param string $excerpt Excerpt.
 * @param string $content Content.
 * @return string
 */
function trimmed_excerpt( $excerpt, $content ) {
	$text = $excerpt ? $excerpt : $content;
	$text = remove_shortcodes( $text );
	$text = wp_strip_all_tags( $text );
	$text = esc_attr( $text );
	$text = collapse_whitespace( $text );
	return truncate( $text, description_max_length() );
}

/**
 * Replace shortcodes with what they enclose; drop the ones that enclose nothing.
 *
 * Every `[name` in the text counts as a shortcode, registered or not, which is
 * what lets builder markup like Avada's turn into its text.
 *
 * @param string $content Text.
 * @return string
 */
function remove_shortcodes( $content ) {
	if ( ! str_contains( $content, '[' ) || ! preg_match_all( '/\[([a-zA-Z0-9_-]+)/', $content, $m ) ) {
		return $content;
	}
	$pattern = get_shortcode_regex( array_values( array_unique( $m[1] ) ) );
	return (string) preg_replace_callback(
		"/$pattern/s",
		function ( $found ) {
			if ( count( $found ) < 7 ) {
				return $found[0] ?? '';
			}
			if ( '[' === $found[1] && ']' === $found[6] ) {
				return substr( $found[0], 1, -1 );
			}
			if ( ! empty( $found[5] ) ) {
				return remove_shortcodes( trim( $found[5] ) . ' ' );
			}
			return '';
		},
		$content
	);
}

/**
 * The src of the first `<img>` in some markup, as written.
 *
 * SmartCrawl reads this with simple_html_dom, which hands back the attribute
 * undecoded; so does this.
 *
 * @param string $content Markup.
 * @return string
 */
function first_image_in( $content ) {
	if ( ! $content || ! preg_match( '/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $content, $m ) ) {
		return '';
	}
	return $m[1] . ( $m[2] ?? '' ) . ( $m[3] ?? '' );
}

/**
 * Image IDs or URLs to url => array( url, width, height ).
 *
 * @param array $images Attachment IDs, URLs, or (url, w, h) arrays.
 * @return array
 */
function images_to_urls( array $images ) {
	$out = array();
	foreach ( $images as $image ) {
		if ( is_array( $image ) ) {
			if ( ! empty( $image[0] ) ) {
				$out[ $image[0] ] = $image;
			}
		} elseif ( is_numeric( $image ) ) {
			$src = wp_get_attachment_image_src( (int) $image, 'full' );
			if ( $src && ! empty( $src[0] ) ) {
				$out[ $src[0] ] = $src;
			}
		} elseif ( $image ) {
			$out[ (string) $image ] = array( (string) $image );
		}
	}
	return $out;
}

/**
 * Add `/page/N/` (or `paged=N`) to an archive URL for pages after the first.
 *
 * @param string $url         URL.
 * @param int    $page_number Page number.
 * @return string
 */
function append_page_number( $url, $page_number ) {
	global $wp_rewrite;
	if ( $page_number > 1 ) {
		$url = $wp_rewrite->using_permalinks()
			? trailingslashit( $url ) . sprintf( $wp_rewrite->pagination_base . '/%d/', $page_number )
			: esc_url_raw( add_query_arg( 'paged', $page_number, $url ) );
	}
	return $url;
}

/**
 * Whether a post is laid out with Elementor (its editor, not just a template).
 *
 * @param \WP_Post $post Post.
 * @return bool
 */
function is_elementor_page( $post ) {
	return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
}

/**
 * The content to describe a post by: post_content, or, for a page built with
 * Elementor while Elementor is active, the text of its current layout.
 *
 * Elementor keeps a plain-text copy of a page in post_content, written when
 * the page is saved in its editor. A change that reaches `_elementor_data`
 * any other way (a REST write, a migration, an import) leaves that copy
 * stale, and a description generated from it describes the old page. The
 * text here is the same thing Elementor writes there, built from the layout
 * as it is now: its DB class's get_plain_text_from_data() with dynamic tags
 * removed, as its save_plain_text() does. Building it renders every widget's
 * plain content, so it is cached in a transient against an MD5 of the layout,
 * and rebuilt only when the layout changes.
 *
 * The render runs inside the page's head, before the content. WordPress
 * counts content images as they render and lazy-loads all but the first few,
 * so while it runs the image loading attributes are short-circuited; the
 * text has no use for them, and the page's own images keep theirs.
 *
 * @param \WP_Post $post Post.
 * @return string
 */
function builder_text( $post ) {
	if ( ! is_elementor_page( $post ) || ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->db ) || ! method_exists( \Elementor\Plugin::$instance->db, 'get_plain_text_from_data' ) ) {
		return $post->post_content;
	}
	$raw  = get_post_meta( $post->ID, '_elementor_data', true );
	$raw  = is_string( $raw ) ? $raw : (string) wp_json_encode( $raw );
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return $post->post_content;
	}
	$key    = 'tgdsk_el_text_' . $post->ID;
	$hash   = md5( 'remove:' . $raw );
	$cached = get_transient( $key );
	if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash ) {
		return (string) $cached['text'];
	}
	$text = elementor_plain_text( $data );
	set_transient(
		$key,
		array(
			'hash' => $hash,
			'text' => $text,
		),
		30 * DAY_IN_SECONDS
	);
	return $text;
}

/**
 * Elementor's plain text of a layout, rendered the way its editor save does
 * (dynamic tags removed), without touching the page's image loading counts.
 *
 * @param array $data Decoded `_elementor_data`.
 * @return string
 */
function elementor_plain_text( array $data ) {
	$tags = \Elementor\Plugin::$instance->dynamic_tags ?? null;
	$mode = null;
	if ( $tags && method_exists( $tags, 'get_parsing_mode' ) && method_exists( $tags, 'set_parsing_mode' ) ) {
		$mode = $tags->get_parsing_mode();
		$tags->set_parsing_mode( 'remove' );
	}
	$no_loading_attrs = fn() => array();
	add_filter( 'pre_wp_get_loading_optimization_attributes', $no_loading_attrs, PHP_INT_MAX );
	try {
		return (string) \Elementor\Plugin::$instance->db->get_plain_text_from_data( $data );
	} finally {
		remove_filter( 'pre_wp_get_loading_optimization_attributes', $no_loading_attrs, PHP_INT_MAX );
		if ( null !== $mode ) {
			$tags->set_parsing_mode( $mode );
		}
	}
}
