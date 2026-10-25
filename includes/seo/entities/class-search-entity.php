<?php
/**
 * Search_Entity: search results.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Search results.
 */
class Search_Entity extends Entity {

	/**
	 * The search phrase.
	 *
	 * @var string
	 */
	private $phrase;

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $page_number;

	/**
	 * Constructor.
	 *
	 * @param string $phrase      Search phrase.
	 * @param int    $page_number Page number.
	 */
	public function __construct( $phrase, $page_number = 0 ) {
		$this->phrase      = (string) $phrase;
		$this->page_number = (int) $page_number;
	}

	/**
	 * Settings location.
	 *
	 * @return string
	 */
	protected function location() {
		return 'search';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->option_string_value( 'search', fn( $loc ) => onpage( "title-{$loc}" ), fn() => '%%searchphrase%% %%sep%% %%sitename%%' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->option_string_value( 'search', fn( $loc ) => onpage( "metadesc-{$loc}" ), fn() => '' );
	}

	/**
	 * Robots from the search settings, on every page.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return ( onpage( 'meta_robots-noindex-search' ) ? 'noindex' : 'index' ) . ',' . ( onpage( 'meta_robots-nofollow-search' ) ? 'nofollow' : 'follow' );
	}

	/**
	 * The search link, unless noindexed.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		return $this->is_noindex() ? '' : append_page_number( get_search_link( $this->phrase ), $this->page_number );
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return (bool) onpage( 'og-active-search' );
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return (bool) onpage( 'twitter-active-search' );
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
		return $this->option_string_value( 'search', fn( $loc ) => onpage( "{$network}-{$field}-{$loc}" ), $fallback );
	}

	/**
	 * Search macros.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature.
		return array( '%%searchphrase%%' => $this->phrase );
	}
}
