<?php
/**
 * Extra_Sitemap_Provider: the extra sitemap URLs as a core sitemap provider
 * (see sitemap-extra.php).
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_Sitemaps_Provider' ) ) {
	return;
}

/**
 * The extra URLs as a core sitemap provider.
 */
class Extra_Sitemap_Provider extends \WP_Sitemaps_Provider {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'extra';
		$this->object_type = 'extra';
	}

	/**
	 * One page of URLs.
	 *
	 * @param int    $page_num       Page, from 1.
	 * @param string $object_subtype Unused.
	 * @return array[]
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core's signature.
		$per = wp_sitemaps_get_max_urls( $this->object_type );
		return array_map( fn( $url ) => array( 'loc' => $url ), array_slice( extra_urls(), ( max( 1, (int) $page_num ) - 1 ) * $per, $per ) );
	}

	/**
	 * Pages of URLs.
	 *
	 * @param string $object_subtype Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- core's signature.
		return (int) ceil( count( extra_urls() ) / wp_sitemaps_get_max_urls( $this->object_type ) );
	}
}
