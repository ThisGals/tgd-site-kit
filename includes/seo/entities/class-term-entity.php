<?php
/**
 * Term_Entity: a category, tag or custom taxonomy term archive.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * A category, tag or custom taxonomy term archive.
 */
class Term_Entity extends Entity {

	/**
	 * The term.
	 *
	 * @var \WP_Term|null
	 */
	private $term;

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $page_number;

	/**
	 * Constructor.
	 *
	 * @param int $term_id     Term ID.
	 * @param int $page_number Archive page number.
	 */
	public function __construct( $term_id, $page_number = 0 ) {
		$term              = get_term( (int) $term_id );
		$this->term        = $term instanceof \WP_Term ? $term : null;
		$this->page_number = (int) $page_number;
	}

	/**
	 * The term's taxonomy.
	 *
	 * @return string
	 */
	protected function location() {
		return $this->term ? $this->term->taxonomy : '';
	}

	/**
	 * One of this term's own SEO values, raw.
	 *
	 * @param string $key Key without the prefix.
	 * @return mixed
	 */
	protected function own( $key ) {
		return $this->term ? get_term_meta( $this->term->term_id, TERM_META_PREFIX . $key, true ) : '';
	}

	/**
	 * Title template chain.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'title' ),
			fn( $loc ) => onpage( "title-{$loc}" ),
			fn() => '%%term_title%% %%sep%% %%sitename%%'
		);
	}

	/**
	 * Description template chain, ending at the term's own description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'description' ),
			fn( $loc ) => onpage( "metadesc-{$loc}" ),
			fn() => $this->term ? $this->term->description : ''
		);
	}

	/**
	 * Robots for this archive page.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return $this->robots_for_page( $this->page_number );
	}

	/**
	 * Robots for a given page of this archive.
	 *
	 * @param int $page_number Page number.
	 * @return string
	 */
	public function robots_for_page( $page_number ) {
		if ( ! $this->term ) {
			return '';
		}
		$tax = $this->term->taxonomy;
		if ( onpage( "meta_robots-{$tax}-subsequent_pages" ) && $page_number < 2 ) {
			return '';
		}
		$noindex  = onpage( "meta_robots-noindex-{$tax}" ) ? ! $this->own( 'override_noindex' ) : (bool) $this->own( 'noindex' );
		$nofollow = onpage( "meta_robots-nofollow-{$tax}" ) ? ! $this->own( 'override_nofollow' ) : (bool) $this->own( 'nofollow' );
		return ( $noindex ? 'noindex' : 'index' ) . ',' . ( $nofollow ? 'nofollow' : 'follow' );
	}

	/**
	 * The term's own canonical, else its archive URL.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		if ( ! $this->term ) {
			return '';
		}
		$own = $this->own( 'canonical' );
		if ( $own ) {
			return (string) $own;
		}
		$link = get_term_link( $this->term );
		if ( is_wp_error( $link ) ) {
			return '';
		}
		return $this->archive_canonical( $link, $this->page_number, fn( $page ) => $this->robots_for_page( $page ) );
	}

	/**
	 * Location switch, then the term's own "disabled".
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return $this->term && onpage( 'og-active-' . $this->location() ) && empty( $this->social_meta( 'og' )['disabled'] );
	}

	/**
	 * Location switch, then the term's own "disabled".
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return $this->term && onpage( 'twitter-active-' . $this->location() ) && empty( $this->social_meta( 'twitter' )['disabled'] );
	}

	/**
	 * The term's own social settings.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function social_meta( $network ) {
		$value = $this->own( 'og' === $network ? 'opengraph' : 'twitter' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Own attachment images, else the taxonomy's defaults. No content images.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function load_social_images( $network ) {
		if ( ! $this->term ) {
			return array();
		}
		$own = array_filter(
			(array) ( $this->social_meta( $network )['images'] ?? array() ),
			fn( $image ) => (bool) wp_get_attachment_image_src( (int) $image )
		);
		return $own ? images_to_urls( $own ) : $this->images_from_options( $network, $this->location() );
	}

	/**
	 * Empty for a missing term; otherwise the base tags (type `object`).
	 *
	 * @return array
	 */
	public function opengraph_tags() {
		return $this->term ? parent::opengraph_tags() : array();
	}

	/**
	 * Term macros.
	 *
	 * @param string $subject Template, for dynamic macros.
	 * @return array
	 */
	public function macros( $subject = '' ) {
		if ( ! $this->term ) {
			return array();
		}
		$term   = $this->term;
		$macros = array(
			'%%id%%'               => $term->term_id,
			'%%term_title%%'       => $term->name,
			'%%term_description%%' => $term->description,
			'%%archive-title%%'    => fn() => get_the_archive_title(),
			'%%original-title%%'   => $term->name,
		);
		if ( 'category' === $term->taxonomy ) {
			$macros['%%category%%']             = $term->name;
			$macros['%%category_description%%'] = $term->description;
		} elseif ( 'post_tag' === $term->taxonomy ) {
			$macros['%%tag%%']             = $term->name;
			$macros['%%tag_description%%'] = $term->description;
		}
		return array_merge(
			$macros,
			$this->dynamic_macros(
				$subject,
				fn( $taxonomy ) => $taxonomy === $term->taxonomy ? array( $term ) : array(),
				fn( $key ) => get_term_meta( $term->term_id, $key, true )
			)
		);
	}
}
