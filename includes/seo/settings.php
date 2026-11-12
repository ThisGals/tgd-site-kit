<?php
/**
 * SEO module settings: site defaults, stored in one option.
 *
 * The `onpage` section's key names and its `%%macro%%` template language are
 * stored data on every site that runs the module, so they stay exactly as
 * they are; the entity classes read these keys. The keys:
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
 * `onpage` is empty on purpose: the entity code supplies a fallback template
 * for every location, and social tags default to on through onpage() below.
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
 * Social switches that were never set read as on; every other missing key
 * reads as null.
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
 * Robots directives a post's "other directives" field may carry, beyond the
 * noindex and nofollow switches: the ones Google and Bing document. Names
 * only; the `name:value` forms are checked in parse_robots_directives().
 */
const ROBOTS_DIRECTIVES = array( 'noarchive', 'nocache', 'nosnippet', 'noimageindex', 'notranslate', 'indexifembedded', 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' );

/**
 * Check a comma-separated list of robots directives and return it exactly as
 * it will be stored: lowercase names, values as typed, `, ` collapsed to `,`.
 *
 * Allowed: the names in ROBOTS_DIRECTIVES; `max-snippet:N` and
 * `max-video-preview:N` with N a whole number from -1; `max-image-preview:`
 * none, standard or large; `unavailable_after:` an ISO 8601 date or date and
 * time (Google also reads RFC 822 and 850 dates, but those contain commas,
 * which separate directives here). Anything else is refused, never altered:
 * the field used to run through sanitize_key(), which turned
 * `max-snippet:-1` into `max-snippet-1` without a word.
 *
 * @param mixed $value Text, or a list of directives.
 * @return string|\WP_Error Normalised text ('' for none), or why it was refused.
 */
function parse_robots_directives( $value ) {
	$items = is_array( $value ) ? $value : explode( ',', (string) $value );
	$out   = array();
	foreach ( $items as $item ) {
		if ( ! is_string( $item ) ) {
			return new \WP_Error( 'tgdsk_robots', __( 'Each robots directive must be text.', 'tgd-site-kit' ) );
		}
		$item = trim( $item );
		if ( '' === $item ) {
			continue;
		}
		$parts = explode( ':', $item, 2 );
		$name  = strtolower( trim( $parts[0] ) );
		$arg   = isset( $parts[1] ) ? trim( $parts[1] ) : null;
		$ok    = in_array( $name, ROBOTS_DIRECTIVES, true );
		if ( $ok ) {
			switch ( $name ) {
				case 'max-snippet':
				case 'max-video-preview':
					$ok = null !== $arg && (bool) preg_match( '/^(-1|\d{1,6})$/', $arg );
					break;
				case 'max-image-preview':
					$ok = null !== $arg && in_array( $arg, array( 'none', 'standard', 'large' ), true );
					break;
				case 'unavailable_after':
					$ok = null !== $arg && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', $arg );
					break;
				default:
					$ok = null === $arg;
			}
		}
		if ( ! $ok ) {
			/* translators: %s: the directive as typed */
			return new \WP_Error( 'tgdsk_robots', sprintf( __( '“%s” is not a robots directive the kit prints. Use noarchive, nocache, nosnippet, noimageindex, notranslate, indexifembedded, max-snippet:N, max-image-preview:none|standard|large, max-video-preview:N or unavailable_after:YYYY-MM-DD.', 'tgd-site-kit' ), $item ) );
		}
		$out[] = null === $arg ? $name : $name . ':' . $arg;
	}
	return implode( ',', array_values( array_unique( $out ) ) );
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
 * Stored as `enforce-lengths`; true means enforce.
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
