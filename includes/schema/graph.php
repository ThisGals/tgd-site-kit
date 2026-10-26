<?php
/**
 * Building and printing the page's one JSON-LD graph.
 *
 * The shape is a site schema plugin's, generalised: the business entity,
 * `WebSite`, a typed `WebPage` with breadcrumbs on the front page and every
 * singular page, an article and its author on a post, `Service` nodes on the
 * pages that carry them, and raw extra nodes per page. Extra properties typed as JSON are merged into the business
 * node (sitewide) and into a page's own node (per page), for what the fields
 * don't cover. Every value comes from settings() or from the post; nothing
 * here knows about a particular business.
 *
 * **Never two graphs.** Two checks, because neither alone is enough:
 *
 * - Before anything is hooked: SmartCrawl with its schema switched on, a
 *   must-use plugin that prints JSON-LD, or JSON-LD in Avada's head and body
 *   code fields. See blockers().
 * - On the finished page: if any other JSON-LD block is a whole graph or
 *   describes a business, this module's block is taken back out and the page
 *   is recorded for the settings screen. A theme or page builder's own
 *   `BreadcrumbList` (Avada prints one with its breadcrumbs element) makes this
 *   module drop only its own breadcrumb, so the page has one.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Schema;

defined( 'ABSPATH' ) || exit;

const MARK_OPEN       = '<!-- tgdsk-schema -->';
const MARK_CLOSE      = '<!-- /tgdsk-schema -->';
const CONFLICT_OPTION = 'tgdsk_schema_conflict';
const ARTICLE_PATTERN = '/^(Article|BlogPosting|NewsArticle)$/';

/**
 * The graph printed on this request, kept so the page check can rebuild it.
 *
 * @param array|null $set Graph to remember, or null to read.
 * @return array
 */
function printed_graph( $set = null ) {
	static $graph = array();
	if ( null !== $set ) {
		$graph = $set;
	}
	return $graph;
}

/**
 * The site's front URL with a trailing slash; every `@id` is built on it.
 *
 * @return string
 */
function home() {
	return trailingslashit( home_url() );
}

/**
 * The business entity's `@id`.
 *
 * @return string
 */
function business_id() {
	$anchor = sanitize_title( (string) settings()['business']['anchor'] );
	return home() . '#' . ( '' !== $anchor ? $anchor : 'business' );
}

/**
 * Areas served as schema.org places.
 *
 * @return array[]
 */
function areas() {
	$out = array();
	foreach ( (array) settings()['business']['areas'] as $area ) {
		$place = array(
			'@type' => is_type_name( $area['type'] ?? '' ) ? $area['type'] : 'City',
			'name'  => (string) ( $area['name'] ?? '' ),
		);
		if ( ! empty( $area['in'] ) ) {
			$place['containedInPlace'] = array(
				'@type' => 'State',
				'name'  => (string) $area['in'],
			);
		}
		if ( '' !== $place['name'] ) {
			$out[] = $place;
		}
	}
	return $out;
}

/**
 * The logo as url, width and height, from an attachment ID or a URL.
 *
 * @return array{url:string, width:int, height:int}|null
 */
function logo() {
	$b    = settings()['business'];
	$logo = $b['logo'];
	if ( is_numeric( $logo ) && (int) $logo > 0 ) {
		$src = wp_get_attachment_image_src( (int) $logo, 'full' );
		return $src ? array(
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		) : null;
	}
	if ( is_string( $logo ) && '' !== trim( $logo ) ) {
		return array(
			'url'    => trim( $logo ),
			'width'  => (int) $b['logo_width'],
			'height' => (int) $b['logo_height'],
		);
	}
	return null;
}

/**
 * The business entity, blank optional fields left out.
 *
 * @return array
 */
function business_node() {
	$b    = settings()['business'];
	$home = home();
	$node = array(
		'@type' => is_type_name( $b['type'] ) ? $b['type'] : 'LocalBusiness',
		'@id'   => business_id(),
		'name'  => business_name(),
		'url'   => $home,
	);
	if ( '' !== trim( $b['description'] ) ) {
		$node['description'] = trim( $b['description'] );
	}

	$logo   = logo();
	$images = array();
	if ( '' !== trim( $b['photo'] ) ) {
		$images[] = trim( $b['photo'] );
	}
	if ( $logo ) {
		$image = array(
			'@type'      => 'ImageObject',
			'@id'        => $home . '#logo',
			'url'        => $logo['url'],
			'contentUrl' => $logo['url'],
		);
		if ( $logo['width'] && $logo['height'] ) {
			$image['width']  = $logo['width'];
			$image['height'] = $logo['height'];
		}
		$image['caption'] = business_name();
		$node['logo']     = $image;
		$images[]         = array( '@id' => $home . '#logo' );
	}
	if ( $images ) {
		$node['image'] = $images;
	}
	if ( '' !== trim( $b['telephone'] ) ) {
		$node['telephone'] = trim( $b['telephone'] );
	}

	$address = array_filter(
		array(
			'streetAddress'   => trim( $b['street'] ),
			'addressLocality' => trim( $b['locality'] ),
			'addressRegion'   => trim( $b['region'] ),
			'postalCode'      => trim( $b['postcode'] ),
			'addressCountry'  => trim( $b['country'] ),
		),
		'strlen'
	);
	if ( $address ) {
		$node['address'] = array( '@type' => 'PostalAddress' ) + $address;
	}

	$hours = array();
	foreach ( (array) $b['hours'] as $row ) {
		$hours[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => array_values( (array) $row['days'] ),
			'opens'     => $row['opens'],
			'closes'    => $row['closes'],
		);
	}
	if ( $hours ) {
		$node['openingHoursSpecification'] = $hours;
	}
	if ( areas() ) {
		$node['areaServed'] = areas();
	}
	if ( $b['same_as'] ) {
		$node['sameAs'] = array_values( (array) $b['same_as'] );
	}
	if ( '' !== trim( (string) $b['latitude'] ) && '' !== trim( (string) $b['longitude'] ) ) {
		$node['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $b['latitude'],
			'longitude' => (float) $b['longitude'],
		);
	}
	if ( '' !== trim( $b['map_url'] ) ) {
		$node['hasMap'] = trim( $b['map_url'] );
	}
	return array_merge( $node, extra_properties( $b['extra'] ) );
}

/**
 * The `WebSite` node.
 *
 * @return array
 */
function website_node() {
	$name = trim( (string) settings()['website']['name'] );
	return array(
		'@type'      => 'WebSite',
		'@id'        => home() . '#website',
		'url'        => home(),
		'name'       => '' !== $name ? $name : business_name(),
		'publisher'  => array( '@id' => business_id() ),
		'inLanguage' => language(),
	);
}

/**
 * The page's type: its own setting, else a brand makes it a collection, else
 * the default for its slug, else `WebPage`.
 *
 * @param int    $id   Post ID.
 * @param string $slug Post slug.
 * @return string
 */
function page_type( $id, $slug ) {
	$defaults = (array) settings()['pages']['types_by_slug'];
	$type     = $defaults[ $slug ] ?? 'WebPage';
	if ( '' !== page_brand( $id ) ) {
		$type = 'CollectionPage';
	}
	$own = $id ? trim( (string) get_post_meta( $id, TYPE_META, true ) ) : '';
	return in_array( $own, PAGE_TYPES, true ) ? $own : $type;
}

/**
 * The brand a page is about, or ''.
 *
 * @param int $id Post ID.
 * @return string
 */
function page_brand( $id ) {
	return $id ? trim( (string) get_post_meta( $id, BRAND_META, true ) ) : '';
}

/**
 * The page's description: whatever the page's meta description resolves to,
 * from SmartCrawl while it is in charge and from the SEO module otherwise.
 *
 * A generated description comes back HTML-escaped (`&amp;`), as it is printed
 * in the meta tag; JSON-LD carries plain text, so entities are decoded here.
 *
 * @return string
 */
function page_description() {
	return html_entity_decode( \TGD_Site_Kit\SEO\resolved_description(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * A title as plain text. get_the_title() and the archive title come back
 * texturised and HTML-escaped (`Services &#038; Pricing`); JSON-LD carries the
 * characters themselves.
 *
 * @param string $title Title.
 * @return string
 */
function plain_text( $title ) {
	return html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Extra nodes stored on a page as raw JSON. Bad JSON is skipped silently, so a
 * typo never breaks the page; a node without `@type` is dropped.
 *
 * @param int $id Post ID.
 * @return array[]
 */
function extra_nodes( $id ) {
	$raw = $id ? (string) get_post_meta( $id, EXTRA_META, true ) : '';
	if ( '' === $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	$nodes = isset( $decoded['@type'] ) ? array( $decoded ) : $decoded;
	$out   = array();
	foreach ( $nodes as $node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) ) {
			unset( $node['@context'] );
			$out[] = $node;
		}
	}
	return $out;
}

/**
 * A post's article type: its own setting, else the Posts setting. `None`
 * means no article node.
 *
 * @param int $id Post ID.
 * @return string
 */
function article_type( $id ) {
	$own = trim( (string) get_post_meta( $id, ARTICLE_META, true ) );
	if ( 'None' === $own || in_array( $own, ARTICLE_TYPES, true ) ) {
		return $own;
	}
	$type = (string) settings()['posts']['article_type'];
	return in_array( $type, ARTICLE_TYPES, true ) ? $type : 'None';
}

/**
 * A headline: the title as plain text, cut at a word to the 110 characters
 * Google reads. Never the title tag, which carries the site name.
 *
 * @param string $title Post title.
 * @return string
 */
function headline( $title ) {
	$title = trim( (string) preg_replace( '/\s+/u', ' ', plain_text( $title ) ) );
	if ( mb_strlen( $title ) <= 110 ) {
		return $title;
	}
	// One character more than fits, so a space just past the limit counts as a word end.
	$cut   = mb_substr( $title, 0, 111 );
	$space = mb_strrpos( $cut, ' ' );
	$cut   = false !== $space && $space > 55 ? mb_substr( $cut, 0, $space ) : mb_substr( $title, 0, 110 );
	return (string) preg_replace( '/[\s,;:\-\x{2013}\x{2014}]+$/u', '', $cut );
}

/**
 * A post author's `@id`, the same on every post by them.
 *
 * @param int $user_id User ID.
 * @return string
 */
function person_id( $user_id ) {
	return home() . '#person-' . (int) $user_id;
}

/**
 * Whether author archives are pages search engines may index, as the SEO
 * plugin in charge has them. SmartCrawl (and this kit's SEO module, which
 * reads the same settings) noindexes them unless they are switched on. With
 * neither in charge there is no way to know, so the answer is no.
 *
 * @return bool
 */
function author_archives_indexed() {
	if ( \TGD_Site_Kit\SEO\smartcrawl_active() ) {
		$onpage = get_option( 'wds_onpage_options', array() );
	} elseif ( \TGD_Site_Kit\is_enabled( 'seo' ) ) {
		$onpage = \TGD_Site_Kit\SEO\settings()['onpage'];
	} else {
		return false;
	}
	return is_array( $onpage ) && ! empty( $onpage['enable-author-archive'] ) && empty( $onpage['meta_robots-noindex-author'] );
}

/**
 * A post author as a `Person`. The archive link only where the archive is
 * indexed; the bio when there is one.
 *
 * @param int $user_id User ID.
 * @return array|null
 */
function person_node( $user_id ) {
	$user = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user || '' === trim( $user->display_name ) ) {
		return null;
	}
	$node = array(
		'@type' => 'Person',
		'@id'   => person_id( $user->ID ),
		'name'  => plain_text( $user->display_name ),
	);
	if ( author_archives_indexed() ) {
		$node['url'] = get_author_posts_url( $user->ID );
	}
	$bio = trim( (string) preg_replace( '/\s+/u', ' ', plain_text( (string) get_the_author_meta( 'description', $user->ID ) ) ) );
	if ( '' !== $bio ) {
		$node['description'] = $bio;
	}
	return $node;
}

/**
 * Whether a post gets this module's article: its type isn't `None`, and its
 * Extra nodes don't already hold an article of their own.
 *
 * @param int $id Post ID.
 * @return bool
 */
function article_wanted( $id ) {
	if ( 'None' === article_type( $id ) ) {
		return false;
	}
	foreach ( extra_nodes( $id ) as $node ) {
		if ( has_type( $node, ARTICLE_PATTERN ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Avada Builder's Meta element prints a `NewsArticle` of its own for the post
 * while Avada's rich snippets are on: headline, dates and author, with no
 * `@id`. Where this module prints the post's article, Avada's is switched off
 * for that post, so the page describes it once. Anywhere else (a `None` post,
 * a related-post card) Avada's stays.
 *
 * @param bool $render Whether Avada prints it.
 * @return bool
 */
function avada_article( $render ) {
	$id = get_queried_object_id();
	return $render && ! ( is_singular( 'post' ) && get_the_ID() === $id && article_wanted( $id ) );
}

/**
 * A post's article node and its author, tied to the post's page node.
 *
 * Nothing when the post's type is `None`, or when its Extra nodes already
 * hold an article of their own. The modified date is never earlier than the
 * published one: a scheduled post edited before it went out has an older
 * modified time, and both then print the publish time.
 *
 * @param int    $id          Post ID.
 * @param string $url         Post permalink.
 * @param string $description The page node's description.
 * @return array[]
 */
function article_nodes( $id, $url, $description ) {
	if ( ! article_wanted( $id ) ) {
		return array();
	}

	$page = array( '@id' => $url . '#webpage' );
	$node = array(
		'@type'            => article_type( $id ),
		'@id'              => $url . '#article',
		'isPartOf'         => $page,
		'mainEntityOfPage' => $page,
		'headline'         => headline( get_the_title( $id ) ),
	);

	$excerpt = has_excerpt( $id ) ? trim( (string) preg_replace( '/\s+/u', ' ', plain_text( get_post_field( 'post_excerpt', $id ) ) ) ) : '';
	$summary = '' !== $excerpt ? $excerpt : $description;
	if ( '' !== $summary && $node['headline'] !== $summary && plain_text( get_the_title( $id ) ) !== $summary ) {
		$node['description'] = $summary;
	}

	$published = (int) get_post_time( 'U', true, $id );
	if ( $published > 0 ) {
		$node['datePublished'] = get_post_time( 'c', true, $id );
		$node['dateModified']  = (int) get_post_modified_time( 'U', true, $id ) > $published ? get_post_modified_time( 'c', true, $id ) : $node['datePublished'];
	}

	$person = person_node( (int) get_post_field( 'post_author', $id ) );
	if ( $person ) {
		$node['author'] = array( '@id' => $person['@id'] );
	}
	$node['publisher'] = array( '@id' => business_id() );

	$thumb = (int) get_post_thumbnail_id( $id );
	$src   = $thumb ? wp_get_attachment_image_src( $thumb, 'full' ) : false;
	if ( $src ) {
		$image = array(
			'@type' => 'ImageObject',
			'url'   => $src[0],
		);
		if ( $src[1] && $src[2] ) {
			$image['width']  = (int) $src[1];
			$image['height'] = (int) $src[2];
		}
		$caption = trim( plain_text( (string) wp_get_attachment_caption( $thumb ) ) );
		if ( '' !== $caption ) {
			$image['caption'] = $caption;
		}
		$node['image'] = $image;
	}
	$node['inLanguage'] = language();

	own_article( $node['@id'] );
	return $person ? array( $node, $person ) : array( $node );
}

/**
 * The `@id` of the article this module built on this request, kept so the page
 * check can take out its own article and leave a page's Extra nodes alone.
 *
 * @param string|null $set `@id` to remember, or null to read.
 * @return string
 */
function own_article( $set = null ) {
	static $id = '';
	if ( null !== $set ) {
		$id = $set;
	}
	return $id;
}

/**
 * The graph for the current request.
 *
 * @return array[]
 */
function build_graph() {
	$home  = home();
	$graph = array( business_node(), website_node() );
	$pages = settings()['pages'];

	if ( is_front_page() || is_singular() ) {
		$id       = get_queried_object_id();
		$is_front = is_front_page();
		$url      = $is_front ? $home : get_permalink( $id );
		$slug     = $id ? (string) get_post_field( 'post_name', $id ) : '';
		$brand    = page_brand( $id );

		$page        = array(
			'@type'      => page_type( $id, $slug ),
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $is_front ? website_node()['name'] : plain_text( get_the_title( $id ) ),
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => language(),
		);
		$description = page_description();
		if ( '' !== $description ) {
			$page['description'] = $description;
		}
		if ( $is_front || in_array( $slug, (array) $pages['about_slugs'], true ) ) {
			$page['about'] = array( '@id' => business_id() );
		}
		if ( '' !== $brand ) {
			$page['about'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}
		if ( $id && has_post_thumbnail( $id ) ) {
			$page['primaryImageOfPage'] = array(
				'@type' => 'ImageObject',
				'url'   => get_the_post_thumbnail_url( $id, 'full' ),
			);
		}

		if ( ! empty( $pages['breadcrumbs'] ) && ! $is_front && $id ) {
			$items = array(
				array(
					'name' => 'Home',
					'item' => $home,
				),
			);
			foreach ( array_reverse( get_post_ancestors( $id ) ) as $ancestor ) {
				$items[] = array(
					'name' => plain_text( get_the_title( $ancestor ) ),
					'item' => get_permalink( $ancestor ),
				);
			}
			$items[] = array(
				'name' => $page['name'],
				'item' => $url,
			);
			$list    = array();
			foreach ( $items as $i => $item ) {
				$list[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $item['name'],
					'item'     => $item['item'],
				);
			}
			$graph[]            = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $list,
			);
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}

		$graph[] = array_merge( $page, extra_properties( $id ? (string) get_post_meta( $id, PAGE_META, true ) : '' ) );

		if ( $id && is_singular( 'post' ) ) {
			$graph = array_merge( $graph, article_nodes( $id, $url, $description ) );
		}

		if ( in_array( $slug, (array) $pages['service_slugs'], true ) ) {
			foreach ( (array) settings()['services'] as $service ) {
				$node = array(
					'@type'       => 'Service',
					'@id'         => $home . '#service-' . $service['anchor'],
					'name'        => $service['name'],
					'serviceType' => $service['name'],
				);
				if ( '' !== $service['description'] ) {
					$node['description'] = $service['description'];
				}
				$node['provider'] = array( '@id' => business_id() );
				if ( areas() ) {
					$node['areaServed'] = areas();
				}
				$node['url'] = $url;
				$graph[]     = $node;
			}
		}

		$graph = array_merge( $graph, extra_nodes( $id ) );
	} elseif ( ! empty( $pages['archive_pages'] ) && ( is_category() || is_tag() || is_tax() || is_post_type_archive() ) ) {
		$graph[] = archive_node();
	}
	return $graph;
}

/**
 * A `CollectionPage` for an archive, listing the items on the page.
 *
 * @return array
 */
function archive_node() {
	global $wp_query;
	$url   = (string) get_pagenum_link( max( 1, (int) get_query_var( 'paged' ) ) );
	$items = array();
	foreach ( (array) $wp_query->posts as $i => $post ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'url'      => get_permalink( $post ),
		);
	}
	$node = array(
		'@type'      => 'CollectionPage',
		'@id'        => $url . '#webpage',
		'url'        => $url,
		'name'       => plain_text( get_the_archive_title() ),
		'isPartOf'   => array( '@id' => home() . '#website' ),
		'inLanguage' => language(),
	);
	if ( $items ) {
		$node['mainEntity'] = array(
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		);
	}
	return $node;
}

/**
 * The graph as a script block, between the markers the page check looks for.
 *
 * @param array $graph Graph nodes.
 * @return string
 */
function graph_markup( array $graph ) {
	$json = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
	);
	return "\n" . MARK_OPEN . "\n<script type=\"application/ld+json\">" . $json . "</script>\n" . MARK_CLOSE . "\n";
}

/**
 * Print the graph in the head. Nothing on a 404 or a search page: there is
 * no page to describe, and a search URL carries what the visitor typed.
 */
function print_graph() {
	if ( ! \TGD_Site_Kit\is_front_end_request() || is_404() || is_search() ) {
		return;
	}
	$graph = build_graph();
	printed_graph( $graph );
	echo graph_markup( $graph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG.
}

/**
 * Buffer front-end pages so the finished HTML can be checked.
 */
function start_page_check() {
	if ( ! \TGD_Site_Kit\is_front_end_request() || is_robots() || is_embed() ) {
		return;
	}
	ob_start( __NAMESPACE__ . '\\check_page' );
}

/**
 * Whether a decoded JSON-LD block is a competing site graph: a whole `@graph`,
 * or any node describing a business or organisation.
 *
 * @param mixed $data Decoded block.
 * @return bool
 */
function is_competing( $data ) {
	if ( ! is_array( $data ) ) {
		return false;
	}
	if ( isset( $data['@graph'] ) ) {
		return true;
	}
	foreach ( array_is_list( $data ) ? $data : array( $data ) as $node ) {
		foreach ( (array) ( $node['@type'] ?? array() ) as $type ) {
			if ( preg_match( '/(Organization|Business|Store|Corporation|LocalBusiness)$/', (string) $type ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Whether a decoded JSON-LD block has a node whose type matches a pattern.
 *
 * @param mixed  $data    Decoded block.
 * @param string $pattern Regular expression for one type name.
 * @return bool
 */
function has_type( $data, $pattern ) {
	if ( ! is_array( $data ) ) {
		return false;
	}
	$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : ( array_is_list( $data ) ? $data : array( $data ) );
	foreach ( $nodes as $node ) {
		foreach ( (array) ( is_array( $node ) ? ( $node['@type'] ?? array() ) : array() ) as $type ) {
			if ( preg_match( $pattern, (string) $type ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Take this module's graph out of a page that already has one; drop only its
 * breadcrumb, its page node or a post's article, when another block on the
 * page already describes that.
 *
 * The page-node case is Avada's FAQ element, which prints its own
 * `WebPage`/`FAQPage` for the page. A second, unlinked page node for the same
 * URL describes the page twice, so this module's goes, and a post's article
 * points at the page by its URL instead.
 *
 * The conflict notice names the page it was found on. It is cleared when that
 * page renders without one, and when the settings are saved.
 *
 * @param string $html Finished page.
 * @return string
 */
function check_page( $html ) {
	$start = strpos( $html, MARK_OPEN );
	$end   = false === $start ? false : strpos( $html, MARK_CLOSE, $start );
	if ( false === $start || false === $end ) {
		return $html;
	}
	$end += strlen( MARK_CLOSE ) + 1;
	--$start;
	$rest = substr( $html, 0, $start ) . substr( $html, $end );
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$here = home_url( $uri );

	$competing  = false;
	$breadcrumb = false;
	$page       = false;
	$article    = false;
	if ( preg_match_all( '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $rest, $m ) ) {
		foreach ( $m[1] as $raw ) {
			$data       = json_decode( trim( $raw ), true );
			$competing  = $competing || is_competing( $data );
			$breadcrumb = $breadcrumb || has_type( $data, '/^BreadcrumbList$/' );
			$page       = $page || has_type( $data, '/Page$/' );
			$article    = $article || has_type( $data, ARTICLE_PATTERN );
		}
	}

	$conflict = get_transient( CONFLICT_OPTION );
	if ( $competing ) {
		if ( ! $conflict ) {
			set_transient(
				CONFLICT_OPTION,
				array(
					'url'  => $here,
					'time' => time(),
				),
				WEEK_IN_SECONDS
			);
		}
		return $rest;
	}
	if ( is_array( $conflict ) && ( $conflict['url'] ?? '' ) === $here ) {
		delete_transient( CONFLICT_OPTION );
	}
	$article = $article && '' !== own_article();
	if ( ! $breadcrumb && ! $page && ! $article ) {
		return $html;
	}
	$graph   = array();
	$dropped = array();
	foreach ( printed_graph() as $node ) {
		$type    = (string) ( $node['@type'] ?? '' );
		$is_page = str_ends_with( $type, 'Page' ) && isset( $node['isPartOf'] );
		if ( $page && $is_page ) {
			$dropped[ (string) ( $node['@id'] ?? '' ) ] = (string) ( $node['url'] ?? '' );
			continue;
		}
		if ( ( $breadcrumb && 'BreadcrumbList' === $type )
			|| ( $article && own_article() === ( $node['@id'] ?? '' ) )
			|| ( $article && 'Person' === $type && str_starts_with( (string) ( $node['@id'] ?? '' ), home() . '#person-' ) ) ) {
			continue;
		}
		if ( $breadcrumb ) {
			unset( $node['breadcrumb'] );
		}
		$graph[] = $node;
	}
	foreach ( $graph as &$node ) {
		$of = (string) ( $node['mainEntityOfPage']['@id'] ?? '' );
		if ( isset( $dropped[ $of ] ) ) {
			$node['mainEntityOfPage'] = $dropped[ $of ];
			unset( $node['isPartOf'] );
		}
	}
	unset( $node );
	return substr( $html, 0, $start ) . graph_markup( $graph ) . substr( $html, $end );
}
