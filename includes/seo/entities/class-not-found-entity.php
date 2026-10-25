<?php
/**
 * Not_Found_Entity: a 404. A title and an optional description; nothing else.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * A 404. A title and an optional description; nothing else.
 */
class Not_Found_Entity extends Entity {

	/**
	 * Settings location.
	 *
	 * @return string
	 */
	protected function location() {
		return '404';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->option_string_value( '404', fn( $loc ) => onpage( "title-{$loc}" ), fn() => 'Page not found %%sep%% %%sitename%%' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->option_string_value( '404', fn( $loc ) => onpage( "metadesc-{$loc}" ), fn() => '' );
	}

	/**
	 * None.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return '';
	}

	/**
	 * None.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		return '';
	}

	/**
	 * Off.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return false;
	}

	/**
	 * Off.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return false;
	}

	/**
	 * None.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature.
		return array();
	}
}
