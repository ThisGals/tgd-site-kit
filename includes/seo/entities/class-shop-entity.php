<?php
/**
 * Shop_Entity: WooCommerce's shop page, the product archive.
 *
 * WordPress shows the shop as the `product` post type archive, but the shop
 * has a page of its own, and that page's SEO values are what a site set for
 * it. So each value comes from the shop page first, then the product
 * archive's template, then the built-in fallback.
 *
 * Its later pages canonicalise to themselves (when indexed) and every page
 * links its neighbours with `rel="prev"` and `rel="next"`, through
 * pagination_links(), which other archives can answer too.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's shop page.
 */
class Shop_Entity extends Post_Type_Archive_Entity {

	/**
	 * Constructor. WooCommerce makes the shop page the queried object, so
	 * the type is always `product`, whatever the query answers.
	 *
	 * @param mixed $type        Unused; the queried object.
	 * @param int   $page_number Archive page number.
	 */
	public function __construct( $type, $page_number = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- the parent's signature.
		parent::__construct( get_post_type_object( 'product' ), $page_number );
	}

	/**
	 * A shop is a website page, not an object.
	 *
	 * @return array
	 */
	public function opengraph_tags() {
		$tags            = parent::opengraph_tags();
		$tags['og:type'] = 'website';
		return $tags;
	}

	/**
	 * The shop page's own SEO value, or false when unset.
	 *
	 * @param string $key Key without the `_tgdsk_` prefix.
	 * @return mixed
	 */
	protected function own( $key ) {
		$id = \TGD_Site_Kit\Woo\shop_page_id();
		if ( ! $id ) {
			return false;
		}
		$value = get_post_meta( $id, POST_META_PREFIX . $key, true );
		return empty( $value ) ? false : $value;
	}

	/**
	 * The shop page's own social settings.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function social_meta( $network ) {
		$value = $this->own( 'og' === $network ? 'opengraph' : 'twitter' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The shop page's title, then the archive template.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'title' ),
			fn( $loc ) => onpage( "title-{$loc}" ),
			fn() => '%%pt_plural%% %%sep%% %%sitename%%'
		);
	}

	/**
	 * The shop page's description, then the archive template.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'description' ),
			fn( $loc ) => onpage( "metadesc-{$loc}" ),
			fn() => ''
		);
	}

	/**
	 * The shop page's own noindex or nofollow, else the archive's rules.
	 *
	 * @return string
	 */
	protected function load_robots() {
		$robots = $this->archive_robots( $this->location(), $this->page_number );
		if ( $this->own( 'noindex' ) ) {
			$robots = str_replace( 'index,', 'noindex,', '' !== $robots ? $robots : 'index,follow' );
		}
		if ( $this->own( 'nofollow' ) ) {
			$robots = str_replace( ',follow', ',nofollow', '' !== $robots ? $robots : 'index,follow' );
		}
		return $robots;
	}

	/**
	 * The shop page's own canonical on the first page; otherwise the
	 * archive's (each later page its own).
	 *
	 * @return string
	 */
	protected function load_canonical() {
		$own = $this->own( 'canonical' );
		if ( $own && $this->page_number < 2 && ! $this->is_noindex() ) {
			return (string) $own;
		}
		return parent::load_canonical();
	}

	/**
	 * The shop page's own "disabled", then the archive's switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return parent::load_opengraph_enabled() && empty( $this->social_meta( 'og' )['disabled'] );
	}

	/**
	 * The shop page's own "disabled", then the archive's switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return parent::load_twitter_enabled() && empty( $this->social_meta( 'twitter' )['disabled'] );
	}

	/**
	 * The shop page's own social title or description, then the archive's.
	 *
	 * @param string $network `og` or `twitter`.
	 * @param string $field   `title` or `description`.
	 * @return string
	 */
	protected function load_social_text( $network, $field ) {
		$own = trim( (string) ( $this->social_meta( $network )[ $field ] ?? '' ) );
		return '' !== clean( $own ) ? $this->expand( clean( $own ) ) : parent::load_social_text( $network, $field );
	}

	/**
	 * The shop page's own images, its featured image, then the archive's.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function load_social_images( $network ) {
		$images = array();
		foreach ( (array) ( $this->social_meta( $network )['images'] ?? array() ) as $image ) {
			if ( is_numeric( $image ) && wp_get_attachment_image_src( (int) $image ) ) {
				$images[] = (int) $image;
			} elseif ( is_string( $image ) && esc_url_raw( $image ) ) {
				$images[] = esc_url_raw( $image );
			}
		}
		$id = \TGD_Site_Kit\Woo\shop_page_id();
		if ( ! $images && $id && get_post_thumbnail_id( $id ) ) {
			$images = array( get_post_thumbnail_id( $id ) );
		}
		return $images ? images_to_urls( $images ) : parent::load_social_images( $network );
	}

	/**
	 * The neighbouring pages of the product listing.
	 *
	 * @return array{prev?: string, next?: string}
	 */
	public function pagination_links() {
		global $wp_query;
		if ( ! $this->type || ! $wp_query instanceof \WP_Query ) {
			return array();
		}
		$max  = (int) $wp_query->max_num_pages;
		$page = max( 1, $this->page_number );
		$base = (string) get_post_type_archive_link( $this->type->name );
		$out  = array();
		if ( $page > 1 ) {
			$out['prev'] = append_page_number( $base, $page - 1 );
		}
		if ( $page < $max ) {
			$out['next'] = append_page_number( $base, $page + 1 );
		}
		return $out;
	}

	/**
	 * The archive's macros, and the shop page's title as `%%title%%`.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) {
		$id = \TGD_Site_Kit\Woo\shop_page_id();
		return parent::macros( $subject ) + array(
			'%%title%%' => $id ? get_the_title( $id ) : '',
		);
	}
}
