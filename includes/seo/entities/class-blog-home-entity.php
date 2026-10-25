<?php
/**
 * Blog_Home_Entity: the home page when it lists posts.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The home page when it lists posts.
 */
class Blog_Home_Entity extends Entity {

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $page_number;

	/**
	 * Constructor.
	 *
	 * @param int $page_number Archive page number.
	 */
	public function __construct( $page_number = 0 ) {
		$this->page_number = (int) $page_number;
	}

	/**
	 * Settings location.
	 *
	 * @return string
	 */
	protected function location() {
		return 'home';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->option_string_value( 'home', fn( $loc ) => onpage( "title-{$loc}" ), fn() => '%%sitename%%' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->option_string_value( 'home', fn( $loc ) => onpage( "metadesc-{$loc}" ), fn() => '%%sitedesc%%' );
	}

	/**
	 * Robots, from the main blog archive settings.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return $this->archive_robots( 'main_blog_archive', $this->page_number );
	}

	/**
	 * Canonical.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		return $this->archive_canonical(
			trailingslashit( get_bloginfo( 'url' ) ),
			$this->page_number,
			fn( $page ) => $this->archive_robots( 'main_blog_archive', $page )
		);
	}

	/**
	 * Home switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return (bool) onpage( 'og-active-home' );
	}

	/**
	 * Home switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return (bool) onpage( 'twitter-active-home' );
	}

	/**
	 * No own value, so social text skips straight to the location template.
	 *
	 * @param string $network `og` or `twitter`.
	 * @param string $field   `title` or `description`.
	 * @return string
	 */
	protected function load_social_text( $network, $field ) {
		$fallback = 'title' === $field ? fn() => $this->title() : fn() => $this->description();
		return $this->option_string_value( 'home', fn( $loc ) => onpage( "{$network}-{$field}-{$loc}" ), $fallback );
	}

	/**
	 * Always a website.
	 *
	 * @return array
	 */
	public function opengraph_tags() {
		$tags            = parent::opengraph_tags();
		$tags['og:type'] = 'website';
		return $tags;
	}

	/**
	 * General macros only.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature.
		return array();
	}
}
