<?php
/**
 * The breadcrumb trail: one function both the schema module's BreadcrumbList
 * and the visible breadcrumbs read, so the two can't disagree.
 *
 * Loaded whatever is switched on. It registers nothing; it only answers
 * "what is the trail for this post (or this archive)".
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Breadcrumbs;

defined( 'ABSPATH' ) || exit;

const OPTION = 'tgdsk_breadcrumbs';

/**
 * Breadcrumb settings, with defaults.
 *
 * `home` is the first item's name in the visible trail and in the schema
 * graph. `separator` and `current` are display only: the structured data
 * always ends on the page itself, which is what Google expects.
 *
 * @return array{home: string, separator: string, current: bool}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$out    = wp_parse_args(
		$stored,
		array(
			'home'      => '',
			'separator' => '',
			'current'   => true,
		)
	);
	return array(
		'home'      => '' !== trim( (string) $out['home'] ) ? (string) $out['home'] : 'Home',
		'separator' => '' !== (string) $out['separator'] ? (string) $out['separator'] : '›',
		'current'   => (bool) $out['current'],
	);
}

/**
 * Title as plain text, the way the schema graph writes names.
 *
 * @param string $title Title, possibly texturised and escaped.
 * @return string
 */
function plain( $title ) {
	return html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * The trail to a post: home, its ancestors from the top, then the post.
 *
 * @param int $id Post ID.
 * @return array[] Each {name, url}.
 */
function post_trail( $id ) {
	$items = array(
		array(
			'name' => settings()['home'],
			'url'  => trailingslashit( home_url() ),
		),
	);
	foreach ( array_reverse( get_post_ancestors( $id ) ) as $ancestor ) {
		$items[] = array(
			'name' => plain( get_the_title( $ancestor ) ),
			'url'  => (string) get_permalink( $ancestor ),
		);
	}
	$items[] = array(
		'name' => plain( get_the_title( $id ) ),
		'url'  => (string) get_permalink( $id ),
	);
	return $items;
}

/**
 * The trail to a term archive: home, parent terms from the top, the term.
 *
 * @param \WP_Term $term Term.
 * @return array[] Each {name, url}.
 */
function term_trail( \WP_Term $term ) {
	$items = array(
		array(
			'name' => settings()['home'],
			'url'  => trailingslashit( home_url() ),
		),
	);
	$chain = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
	foreach ( array_merge( $chain, array( $term->term_id ) ) as $term_id ) {
		$link = get_term_link( (int) $term_id, $term->taxonomy );
		$item = get_term( (int) $term_id, $term->taxonomy );
		if ( is_wp_error( $link ) || ! $item instanceof \WP_Term ) {
			continue;
		}
		$items[] = array(
			'name' => plain( $item->name ),
			'url'  => $link,
		);
	}
	return $items;
}

/**
 * The trail for the current request, or none.
 *
 * Singular pages get exactly the items the schema module's BreadcrumbList
 * lists. Term and post type archives get a trail too (the schema module
 * prints no BreadcrumbList there, so nothing can disagree). The front page,
 * search, 404 and date archives get nothing.
 *
 * @return array[] Each {name, url}; empty for none.
 */
function current_trail() {
	if ( is_front_page() ) {
		return array();
	}
	if ( is_singular() ) {
		$id = get_queried_object_id();
		return $id ? post_trail( $id ) : array();
	}
	$object = get_queried_object();
	if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof \WP_Term ) {
		return term_trail( $object );
	}
	if ( is_post_type_archive() && $object instanceof \WP_Post_Type ) {
		$link = get_post_type_archive_link( $object->name );
		if ( $link ) {
			return array(
				array(
					'name' => settings()['home'],
					'url'  => trailingslashit( home_url() ),
				),
				array(
					'name' => plain( $object->labels->name ),
					'url'  => $link,
				),
			);
		}
	}
	return array();
}
