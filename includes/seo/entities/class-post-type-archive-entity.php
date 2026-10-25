<?php
/**
 * Post_Type_Archive_Entity: a post type archive.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * A post type archive.
 */
class Post_Type_Archive_Entity extends Entity {

	/**
	 * The post type.
	 *
	 * @var \WP_Post_Type|null
	 */
	private $type;

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $page_number;

	/**
	 * Constructor.
	 *
	 * @param \WP_Post_Type|null $type        Post type.
	 * @param int                $page_number Archive page number.
	 */
	public function __construct( $type, $page_number = 0 ) {
		$this->type        = $type instanceof \WP_Post_Type ? $type : null;
		$this->page_number = (int) $page_number;
	}

	/**
	 * Settings location.
	 *
	 * @return string
	 */
	protected function location() {
		return $this->type ? 'pt-archive-' . $this->type->name : '';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->option_string_value( $this->location(), fn( $loc ) => onpage( "title-{$loc}" ), fn() => '%%pt_plural%% %%sep%% %%sitename%%' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->option_string_value( $this->location(), fn( $loc ) => onpage( "metadesc-{$loc}" ), fn() => '' );
	}

	/**
	 * Robots.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return $this->archive_robots( $this->location(), $this->page_number );
	}

	/**
	 * Canonical.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		if ( ! $this->type ) {
			return '';
		}
		return $this->archive_canonical(
			(string) get_post_type_archive_link( $this->type->name ),
			$this->page_number,
			fn( $page ) => $this->archive_robots( $this->location(), $page )
		);
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return (bool) onpage( 'og-active-' . $this->location() );
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return (bool) onpage( 'twitter-active-' . $this->location() );
	}

	/**
	 * No own value.
	 *
	 * @param string $network `og` or `twitter`.
	 * @param string $field   `title` or `description`.
	 * @return string
	 */
	protected function load_social_text( $network, $field ) {
		$fallback = 'title' === $field ? fn() => $this->title() : fn() => $this->description();
		return $this->option_string_value( $this->location(), fn( $loc ) => onpage( "{$network}-{$field}-{$loc}" ), $fallback );
	}

	/**
	 * Archive macros.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature.
		$type = $this->type;
		return array(
			'%%pt_plural%%'      => $type ? $type->labels->name : '',
			'%%pt_single%%'      => $type ? $type->labels->singular_name : '',
			'%%archive-title%%'  => fn() => get_the_archive_title(),
			'%%original-title%%' => fn() => post_type_archive_title( '', false ),
		);
	}
}
