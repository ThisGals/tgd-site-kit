<?php
/**
 * WooCommerce support: platform support, like the page builders.
 *
 * Everything here answers only while WooCommerce is active (active()), and
 * every caller checks that first, so a site without it gets nothing new: no
 * hook, no query, no tag.
 *
 * - Products: Open Graph `product` tags (price, currency, availability,
 *   brand, SKU) and the schema module's Product node.
 * - The shop page and its pagination (Shop_Entity in the SEO module).
 * - robots.txt lines for the cart, checkout, account pages and add-to-cart
 *   URLs, read from WooCommerce's own page settings.
 * - The trail to a product: its main product category, as WooCommerce's own
 *   breadcrumbs choose it.
 *
 * Loaded whatever is switched on; it registers nothing.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Woo;

defined( 'ABSPATH' ) || exit;

const BRAND_TAXONOMY = 'product_brand';

/**
 * Whether WooCommerce is active on this request.
 *
 * @return bool
 */
function active() {
	return function_exists( 'WC' ) && function_exists( 'wc_get_product' );
}

/**
 * A product, or null.
 *
 * @param int $post_id Post ID.
 * @return \WC_Product|null
 */
function product( $post_id ) {
	if ( ! active() || 'product' !== get_post_type( $post_id ) ) {
		return null;
	}
	$product = wc_get_product( $post_id );
	return $product instanceof \WC_Product ? $product : null;
}

/**
 * The price a product shows, as a decimal string, or '' when it shows none.
 *
 * A variable product answers its lowest price. A product with no price (a
 * call-for-price product) answers '', and nothing prints a price for it.
 *
 * @param \WC_Product $product Product.
 * @return string
 */
function price( \WC_Product $product ) {
	if ( '' === (string) $product->get_price() ) {
		return '';
	}
	$price = $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' )
		? $product->get_variation_price( 'min', false )
		: $product->get_price();
	if ( '' === (string) $price ) {
		return '';
	}
	return (string) wc_format_decimal( $price, wc_get_price_decimals() );
}

/**
 * The product's brand name from WooCommerce's brand taxonomy, or ''.
 *
 * @param \WC_Product $product Product.
 * @return string
 */
function brand( \WC_Product $product ) {
	if ( ! taxonomy_exists( BRAND_TAXONOMY ) ) {
		return '';
	}
	$terms = get_the_terms( $product->get_id(), BRAND_TAXONOMY );
	return is_array( $terms ) && $terms ? (string) $terms[0]->name : '';
}

/**
 * Open Graph availability, both tags, from the stock status.
 *
 * @param \WC_Product $product Product.
 * @return array{og: string, product: string}|null
 */
function availability( \WC_Product $product ) {
	$map    = array(
		'instock'     => array( 'instock', 'instock' ),
		'outofstock'  => array( 'out of stock', 'out of stock' ),
		'onbackorder' => array( 'backorder', 'available for order' ),
	);
	$status = (string) $product->get_stock_status();
	return isset( $map[ $status ] ) ? array(
		'og'      => $map[ $status ][0],
		'product' => $map[ $status ][1],
	) : null;
}

/**
 * The product tags for a product page's Open Graph, in print order.
 *
 * @param int $post_id Post ID.
 * @return array Property => value.
 */
function opengraph_tags( $post_id ) {
	$product = product( $post_id );
	if ( ! $product ) {
		return array();
	}
	$tags  = array();
	$price = price( $product );
	if ( '' !== $price ) {
		$tags['product:price:amount']   = $price;
		$tags['product:price:currency'] = get_woocommerce_currency();
	}
	$stock = availability( $product );
	if ( $stock ) {
		$tags['og:availability']      = $stock['og'];
		$tags['product:availability'] = $stock['product'];
	}
	$brand = brand( $product );
	if ( '' !== $brand ) {
		$tags['product:brand'] = $brand;
	}
	if ( '' !== (string) $product->get_sku() ) {
		$tags['product:retailer_item_id'] = (string) $product->get_sku();
	}
	return $tags;
}

/**
 * The schema.org availability for an Offer.
 *
 * @param \WC_Product $product Product.
 * @return string
 */
function schema_availability( \WC_Product $product ) {
	$map = array(
		'instock'     => 'https://schema.org/InStock',
		'outofstock'  => 'https://schema.org/OutOfStock',
		'onbackorder' => 'https://schema.org/BackOrder',
	);
	return $map[ (string) $product->get_stock_status() ] ?? '';
}

/**
 * The shop page's ID, or 0.
 *
 * @return int
 */
function shop_page_id() {
	return active() ? max( 0, (int) wc_get_page_id( 'shop' ) ) : 0;
}

/**
 * Whether this request is the shop page (the product archive).
 *
 * @return bool
 */
function is_shop_request() {
	return active() && function_exists( 'is_shop' ) && is_shop();
}

/**
 * Paths of the shop's private pages: cart, checkout, account. Read from
 * WooCommerce's page settings, so a renamed page is still covered.
 *
 * @return string[] Paths with a trailing slash.
 */
function private_paths() {
	if ( ! active() ) {
		return array();
	}
	$paths = array();
	foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
		$id = (int) wc_get_page_id( $page );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = trailingslashit( $path );
			}
		}
	}
	return array_values( array_unique( $paths ) );
}

/**
 * The IDs of the shop's private pages (cart, checkout, account), published.
 *
 * @return int[]
 */
function private_page_ids() {
	if ( ! active() ) {
		return array();
	}
	$ids = array();
	foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
		$id = (int) wc_get_page_id( $page );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * The robots.txt lines for the shop: add-to-cart URLs and the private pages.
 *
 * @return string[]
 */
function robots_lines() {
	if ( ! active() ) {
		return array();
	}
	$lines = array( 'Disallow: /*add-to-cart=*' );
	foreach ( private_paths() as $path ) {
		$lines[] = 'Disallow: ' . $path;
	}
	return $lines;
}

/**
 * Whether the current request is one of the shop's private pages.
 *
 * @return bool
 */
function is_private_page() {
	if ( ! active() ) {
		return false;
	}
	foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
		$id = (int) wc_get_page_id( $page );
		if ( $id > 0 && is_page( $id ) ) {
			return true;
		}
	}
	return false;
}

/**
 * A product's main category, as WooCommerce's breadcrumbs choose it: the
 * deepest-parented of its categories, through the same filter.
 *
 * @param int $post_id Product ID.
 * @return \WP_Term|null
 */
function main_category( $post_id ) {
	if ( ! active() || 'product' !== get_post_type( $post_id ) || ! function_exists( 'wc_get_product_terms' ) ) {
		return null;
	}
	$terms = wc_get_product_terms(
		$post_id,
		'product_cat',
		array(
			'orderby' => 'parent',
			'order'   => 'DESC',
		)
	);
	if ( ! $terms ) {
		return null;
	}
	$main = apply_filters( 'woocommerce_breadcrumb_main_term', $terms[0], $terms ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter, so the trail matches its breadcrumbs.
	return $main instanceof \WP_Term ? $main : null;
}

/**
 * A product category's own image (its thumbnail), as an attachment ID, or 0.
 *
 * @param int $term_id Term ID.
 * @return int
 */
function category_image( $term_id ) {
	$id = (int) get_term_meta( $term_id, 'thumbnail_id', true );
	return $id && wp_get_attachment_image_src( $id, 'full' ) ? $id : 0;
}

/**
 * The schema module's Product node for a product page, or null.
 *
 * @param int    $post_id     Product ID.
 * @param string $url         Page URL.
 * @param string $description Page description.
 * @param string $seller_id   `@id` of the business.
 * @return array|null
 */
function product_node( $post_id, $url, $description, $seller_id ) {
	$product = product( $post_id );
	if ( ! $product ) {
		return null;
	}
	$node = array(
		'@type'            => 'Product',
		'@id'              => $url . '#product',
		'name'             => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'url'              => $url,
		'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
	);
	$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $product->get_short_description() ? $product->get_short_description() : '' ) ) ) );
	$text = '' !== $text ? $text : $description;
	if ( '' !== $text ) {
		$node['description'] = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$images = array();
	foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $image_id ) {
		$src = $image_id ? wp_get_attachment_image_url( (int) $image_id, 'full' ) : false;
		if ( $src ) {
			$images[] = $src;
		}
	}
	if ( $images ) {
		$node['image'] = array_values( array_unique( $images ) );
	}
	if ( '' !== (string) $product->get_sku() ) {
		$node['sku'] = (string) $product->get_sku();
	}
	$brand = brand( $product );
	if ( '' !== $brand ) {
		$node['brand'] = array(
			'@type' => 'Brand',
			'name'  => $brand,
		);
	}
	$offer = array(
		'@type' => 'Offer',
		'url'   => $url,
	);
	$price = price( $product );
	if ( '' !== $price ) {
		$offer['price']         = $price;
		$offer['priceCurrency'] = get_woocommerce_currency();
	}
	$stock = schema_availability( $product );
	if ( '' !== $stock ) {
		$offer['availability'] = $stock;
	}
	$offer['seller'] = array( '@id' => $seller_id );
	$node['offers']  = $offer;
	return $node;
}

/**
 * Whether a decoded JSON-LD block is WooCommerce's own structured data:
 * every node is a type WooCommerce prints for a page (a product, its
 * reviews, the breadcrumb list). Such a block is printed alongside the
 * kit's graph, not counted as another graph.
 *
 * @param mixed $data Decoded block.
 * @return bool
 */
function is_own_structured_data( $data ) {
	if ( ! active() || ! is_array( $data ) ) {
		return false;
	}
	$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : ( array_is_list( $data ) ? $data : array( $data ) );
	if ( ! $nodes ) {
		return false;
	}
	foreach ( $nodes as $node ) {
		$types = is_array( $node ) ? (array) ( $node['@type'] ?? array() ) : array();
		if ( ! $types || array_diff( $types, array( 'Product', 'ProductGroup', 'BreadcrumbList', 'Review' ) ) ) {
			return false;
		}
	}
	return true;
}
