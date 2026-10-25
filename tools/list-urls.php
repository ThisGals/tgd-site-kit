<?php
/**
 * Print every published, publicly viewable URL on the site, one per line.
 *
 * Run with WP-CLI from the WordPress root:
 *
 *     wp eval-file wp-content/plugins/tgd-site-kit/tools/list-urls.php > urls.txt
 *
 * "Published URL" here means what a crawler can reach and Search Console can
 * index: the front page, the posts page, every published post of a public post
 * type, and every public taxonomy term that has posts. Attachments are left out
 * because core redirects attachment pages to the file on current installs, and
 * search and 404 pages are left out because nobody publishes them.
 *
 * Read only. It queries and prints; it writes nothing.
 *
 * @package TGD_Site_Kit
 */

$urls = array( home_url( '/' ) );

$posts_page = (int) get_option( 'page_for_posts' );
if ( $posts_page ) {
	$urls[] = get_permalink( $posts_page );
}

$types = get_post_types( array( 'public' => true ), 'names' );
unset( $types['attachment'] );

$ids = get_posts(
	array(
		'post_type'      => array_values( $types ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'has_password'   => false,
	)
);
foreach ( $ids as $id ) {
	$urls[] = get_permalink( $id );
}

foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
	if ( 'post_format' === $taxonomy ) {
		continue;
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		)
	);
	if ( is_wp_error( $terms ) ) {
		continue;
	}
	foreach ( $terms as $term ) {
		$link = get_term_link( $term );
		if ( ! is_wp_error( $link ) ) {
			$urls[] = $link;
		}
	}
}

echo implode( "\n", array_values( array_unique( $urls ) ) ), "\n";
