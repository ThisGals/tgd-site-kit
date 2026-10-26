<?php
/**
 * Schema module settings: the business, page types and services, per site.
 *
 * Everything a hand-written site schema plugin hard-codes lives here instead,
 * so one plugin serves every site. The settings screen edits the list fields
 * as one entry per line (see the parse_* functions), which keeps the form
 * plain HTML and the stored shape plain arrays.
 *
 * Optional fields left blank are omitted from the graph, never printed empty.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Schema;

defined( 'ABSPATH' ) || exit;

const OPTION = 'tgdsk_schema';

/**
 * Per-post meta keys.
 */
const TYPE_META  = '_tgdsk_schema_type';
const BRAND_META = '_tgdsk_schema_brand';
const EXTRA_META = '_tgdsk_schema_extra';
const PAGE_META  = '_tgdsk_schema_page';

/**
 * Page types a page may be given, by default, by slug or by its own setting.
 */
const PAGE_TYPES = array( 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'FAQPage', 'ItemPage', 'CheckoutPage', 'ProfilePage' );

/**
 * The whole settings array with defaults filled in. Memoised; save drops it.
 *
 * @param bool $forget Drop the memoised copy.
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
			$settings[ $section ] = is_array( $defaults )
				? wp_parse_args( isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ? $stored[ $section ] : array(), $defaults )
				: ( $stored[ $section ] ?? $defaults );
		}
	}
	return $settings;
}

/**
 * Store the settings.
 *
 * @param array $settings Whole settings array.
 */
function save_settings( array $settings ) {
	update_option( OPTION, $settings, true );
	settings( true );
}

/**
 * Defaults. Nothing about any particular business.
 *
 * @return array
 */
function default_settings() {
	return array(
		'business' => array(
			'type'        => 'LocalBusiness',
			'anchor'      => 'business',
			'name'        => '',
			'description' => '',
			'logo'        => '',
			'logo_width'  => 0,
			'logo_height' => 0,
			'photo'       => '',
			'telephone'   => '',
			'street'      => '',
			'locality'    => '',
			'region'      => '',
			'postcode'    => '',
			'country'     => '',
			'latitude'    => '',
			'longitude'   => '',
			'map_url'     => '',
			'hours'       => array(),
			'areas'       => array(),
			'same_as'     => array(),
			'extra'       => '',
		),
		'website'  => array(
			'name'     => '',
			'language' => '',
		),
		'pages'    => array(
			'types_by_slug' => array(),
			'about_slugs'   => array(),
			'breadcrumbs'   => true,
			'archive_pages' => false,
			'service_slugs' => array(),
		),
		'services' => array(),
	);
}

/**
 * The business name, falling back to the site title.
 *
 * @return string
 */
function business_name() {
	$name = trim( (string) settings()['business']['name'] );
	return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * The language tag, falling back to the site's.
 *
 * @return string
 */
function language() {
	$lang = trim( (string) settings()['website']['language'] );
	return '' !== $lang ? $lang : get_bloginfo( 'language' );
}

/**
 * Whether a schema.org type name is one this module will print.
 *
 * Letters and digits only, starting with a capital: that is every schema.org
 * type, and it keeps arbitrary text out of `@type`.
 *
 * @param string $type Type name.
 * @return bool
 */
function is_type_name( $type ) {
	return (bool) preg_match( '/^[A-Z][A-Za-z0-9]{1,60}$/', (string) $type );
}

/**
 * Raw JSON as a single object, or null when it is blank, invalid, or a list.
 *
 * @param string $raw JSON text.
 * @return array|null
 */
function json_object( $raw ) {
	$decoded = json_decode( trim( (string) $raw ), true );
	return is_array( $decoded ) && $decoded && ! array_is_list( $decoded ) ? $decoded : null;
}

/**
 * Extra properties typed as a JSON object, ready to merge into a node the kit
 * builds. `@id` and `@type` are the kit's, so they are taken out, as is
 * `@context`.
 *
 * @param string $raw JSON text.
 * @return array
 */
function extra_properties( $raw ) {
	$props = json_object( $raw );
	if ( null === $props ) {
		return array();
	}
	unset( $props['@id'], $props['@type'], $props['@context'] );
	return $props;
}

/**
 * Split a textarea into trimmed, non-empty lines.
 *
 * @param string $text Text.
 * @return string[]
 */
function lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $text ) ) ) );
}

/**
 * Opening hours: `Monday, Tuesday | 10:00 | 17:00` per line.
 *
 * @param string $text Textarea.
 * @return array[] Each {days: string[], opens, closes}.
 */
function parse_hours( $text ) {
	$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
	$out  = array();
	foreach ( lines( $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( 3 !== count( $parts ) || ! preg_match( '/^\d{2}:\d{2}$/', $parts[1] ) || ! preg_match( '/^\d{2}:\d{2}$/', $parts[2] ) ) {
			continue;
		}
		$picked = array_values( array_intersect( array_map( 'ucfirst', array_map( 'strtolower', array_map( 'trim', explode( ',', $parts[0] ) ) ) ), $days ) );
		if ( $picked ) {
			$out[] = array(
				'days'   => $picked,
				'opens'  => $parts[1],
				'closes' => $parts[2],
			);
		}
	}
	return $out;
}

/**
 * Areas served: `Springfield | Illinois` (a city in a state) or a bare name.
 *
 * A bare name with `type:` in front sets the type, so
 * `AdministrativeArea: Sangamon County, Illinois` works too.
 *
 * @param string $text Textarea.
 * @return array[] Each {type, name, in}.
 */
function parse_areas( $text ) {
	$out = array();
	foreach ( lines( $text ) as $line ) {
		$type = 'City';
		if ( preg_match( '/^([A-Z][A-Za-z]+):\s*(.+)$/', $line, $m ) && is_type_name( $m[1] ) ) {
			$type = $m[1];
			$line = $m[2];
		}
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$out[] = array(
			'type' => $type,
			'name' => sanitize_text_field( $parts[0] ),
			'in'   => sanitize_text_field( $parts[1] ?? '' ),
		);
	}
	return $out;
}

/**
 * Page types by slug: `about = AboutPage` per line.
 *
 * @param string $text Textarea.
 * @return array<string, string>
 */
function parse_types_by_slug( $text ) {
	$out = array();
	foreach ( lines( $text ) as $line ) {
		$parts = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( 2 === count( $parts ) && in_array( $parts[1], PAGE_TYPES, true ) ) {
			$out[ sanitize_title( $parts[0] ) ] = $parts[1];
		}
	}
	return $out;
}

/**
 * Services: `anchor | Name | Description` per line.
 *
 * @param string $text Textarea.
 * @return array[] Each {anchor, name, description}.
 */
function parse_services( $text ) {
	$out = array();
	foreach ( lines( $text ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			continue;
		}
		$out[] = array(
			'anchor'      => sanitize_title( '' !== $parts[0] ? $parts[0] : $parts[1] ),
			'name'        => sanitize_text_field( $parts[1] ),
			'description' => sanitize_text_field( $parts[2] ?? '' ),
		);
	}
	return $out;
}

/**
 * URLs, one per line; anything that is not an http(s) URL is dropped.
 *
 * @param string $text Textarea.
 * @return string[]
 */
function parse_urls( $text ) {
	return array_values( array_filter( array_map( fn( $u ) => preg_match( '#^https?://\S+$#i', $u ) ? esc_url_raw( $u ) : '', lines( $text ) ) ) );
}

/**
 * Slugs, one per line or comma separated.
 *
 * @param string $text Text.
 * @return string[]
 */
function parse_slugs( $text ) {
	return array_values( array_unique( array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) $text ) ) ) ) );
}
