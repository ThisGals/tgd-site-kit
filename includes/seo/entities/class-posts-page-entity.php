<?php
/**
 * Posts_Page_Entity: the page set as the posts page, while it is listing posts.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The page set as the posts page, while it is listing posts.
 */
class Posts_Page_Entity extends Post_Entity {

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $archive_page;

	/**
	 * Constructor.
	 *
	 * @param int $archive_page Archive page number.
	 */
	public function __construct( $archive_page = 0 ) {
		parent::__construct( (int) get_option( 'page_for_posts' ) );
		$this->archive_page = (int) $archive_page;
	}

	/**
	 * Social tags follow the home location's switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return (bool) onpage( 'og-active-home' );
	}

	/**
	 * Social tags follow the home location's switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return (bool) onpage( 'twitter-active-home' );
	}

	/**
	 * The page's canonical, with the archive page appended.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		return append_page_number( parent::load_canonical(), $this->archive_page );
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
}
