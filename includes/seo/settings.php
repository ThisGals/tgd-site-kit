<?php
/**
 * SEO module settings: site defaults, stored in one option.
 *
 * The `onpage` section keeps SmartCrawl's own key names and its `%%macro%%`
 * template language, verbatim. That is a deliberate choice, not laziness: an
 * import is then a copy rather than a translation, a value can be checked
 * against SmartCrawl's settings screen by eye, and the output rules ported in
 * entities.php can read exactly the keys they were written against. The keys:
 *
 * - `title-{location}`, `metadesc-{location}`: title and description templates.
 * - `og-active-{location}`, `og-title-…`, `og-description-…`, `og-images-…`,
 *   and the same four with `twitter-`: social tags per location.
 * - `og-disable-first-image-{post type}`, `twitter-disable-first-image-…`.
 * - `meta_robots-noindex-{location}`, `meta_robots-nofollow-{location}`,
 *   `meta_robots-{location}-subsequent_pages`.
 * - `preset-separator`, `separator`, and the custom length settings.
 *
 * A location is a post type, a taxonomy, `home`, `search`, `404`,
 * `pt-archive-{post type}`, `main_blog_archive`, `author` or `date`.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

const OPTION = 'tgdsk_seo';

/**
 * The whole settings array, sections filled in with defaults.
 *
 * Memoised per request; save_settings() drops the copy.
 *
 * @param bool $forget Drop the memoised copy instead of returning it.
 * @return array
 */
function settings( $forget = false ) {
	static $settings = null;
	if ( $forget ) {
		$settings = null;
		return array();
	}
	if ( null === $settings ) {
		$stored   = get_option( OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = array();
		foreach ( default_settings() as $section => $defaults ) {
			$settings[ $section ] = wp_parse_args( isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ? $stored[ $section ] : array(), $defaults );
		}
	}
	return $settings;
}

/**
 * Store the settings and drop the memoised copy.
 *
 * @param array $settings Whole settings array.
 */
function save_settings( array $settings ) {
	update_option( OPTION, $settings, true );
	settings( true );
}

/**
 * Defaults, section by section.
 *
 * `onpage` is empty on purpose: the entity code supplies SmartCrawl's own
 * fallback template for every location, and social tags default to on through
 * onpage() below. A fresh site therefore gets the same tags a fresh SmartCrawl
 * install would.
 *
 * @return array
 */
function default_settings() {
	return array(
		'onpage'       => array(),
		'social'       => array(
			'og-enable'           => true,
			'twitter-card-enable' => true,
			'twitter-card-type'   => 'summary_large_image',
			'twitter_username'    => '',
			'fb-app-id'           => '',
		),
		'general'      => array(
			'enforce-lengths'              => false,
			'suppress-generator'           => false,
			'suppress-redundant-canonical' => false,
		),
		'verification' => array(
			'google'     => '',
			'bing'       => '',
			'pages'      => '',
			'additional' => array(),
		),
		'sitemap'      => array(
			'exclude_post_types' => array(),
			'exclude_taxonomies' => array(),
			'exclude_post_ids'   => array(),
			'include_users'      => false,
		),
		'robots'       => array(
			'custom'       => '',
			'sitemap_line' => true,
		),
	);
}

/**
 * One onpage setting.
 *
 * Social switches that were never set read as on, matching a fresh SmartCrawl
 * install; every other missing key reads as null, as SmartCrawl's
 * get_array_value() does.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function onpage( $key ) {
	$onpage = settings()['onpage'];
	if ( array_key_exists( $key, $onpage ) ) {
		return $onpage[ $key ];
	}
	if ( str_starts_with( $key, 'og-active-' ) || str_starts_with( $key, 'twitter-active-' ) ) {
		return true;
	}
	return null;
}

/**
 * Clean a title, description or template for storage.
 *
 * Not sanitize_text_field(), which deletes anything shaped like a URL-encoded
 * byte: `%%category%%` contains one (`%ca`), so a template saved through it
 * comes back as `%tegory%%`, and so does a typed "%%sitename%%" or "50%off".
 * Tags and line breaks go; percent signs stay. Output is escaped separately.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function sanitize_template( $value ) {
	return collapse_whitespace( wp_strip_all_tags( wp_check_invalid_utf8( (string) $value ) ) );
}

/**
 * Clean robots.txt text: tags stripped, line endings normalised, lines kept.
 *
 * Not sanitize_textarea_field(), which would delete percent-encoded paths,
 * and those are legitimate in a Disallow line.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function sanitize_robots_txt( $value ) {
	$text  = str_replace( "\r", '', wp_strip_all_tags( wp_check_invalid_utf8( (string) $value ), false ) );
	$lines = array_map( 'rtrim', explode( "\n", $text ) );
	return trim( implode( "\n", $lines ) );
}

/**
 * The title separator: the custom one if set, else the preset's character.
 *
 * @return string
 */
function separator() {
	$custom = (string) onpage( 'separator' );
	if ( '' !== $custom ) {
		return $custom;
	}
	$presets = array(
		'dot'           => '·',
		'dot-l'         => '•',
		'dash'          => '-',
		'dash-l'        => '—',
		'pipe'          => '|',
		'forward-slash' => '/',
		'back-slash'    => '\\',
		'tilde'         => '~',
		'greater-than'  => '>',
		'less-than'     => '<',
		'caret-right'   => '›',
		'caret-left'    => '‹',
		'arrow-right'   => '→',
		'arrow-left'    => '←',
	);
	$preset  = (string) onpage( 'preset-separator' );
	return $presets[ $preset ] ?? $presets['pipe'];
}

/**
 * Longest title before truncation, when lengths are enforced.
 *
 * @return int
 */
function title_max_length() {
	$custom = (int) onpage( 'custom_title_max_length' );
	return onpage( 'custom_title_char_lengths' ) && $custom > 0 ? $custom : 65;
}

/**
 * Longest description, used for every generated excerpt and, when lengths are
 * enforced, for typed descriptions too.
 *
 * @return int
 */
function description_max_length() {
	$custom = (int) onpage( 'custom_metadesc_max_length' );
	return onpage( 'custom_metadesc_char_lengths' ) && $custom > 0 ? $custom : 160;
}

/**
 * Whether typed titles and descriptions are cut to length on output.
 *
 * SmartCrawl calls this `metabox-lax_enforcement`, and true means enforce.
 *
 * @return bool
 */
function lengths_enforced() {
	return ! empty( settings()['general']['enforce-lengths'] );
}

/**
 * Locations a site can configure, with a label for the settings screen.
 *
 * @return array<string, string> Location key => label.
 */
function locations() {
	$locations = array(
		'home'   => __( 'Home page (when it lists posts)', 'tgd-site-kit' ),
		'search' => __( 'Search results', 'tgd-site-kit' ),
		'404'    => __( 'Page not found', 'tgd-site-kit' ),
	);
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		$locations[ $type->name ] = $type->labels->singular_name;
		if ( $type->has_archive ) {
			/* translators: %s: post type plural name */
			$locations[ 'pt-archive-' . $type->name ] = sprintf( __( '%s archive', 'tgd-site-kit' ), $type->labels->name );
		}
	}
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
		if ( 'post_format' !== $taxonomy->name ) {
			$locations[ $taxonomy->name ] = $taxonomy->labels->singular_name;
		}
	}
	return $locations;
}
