<?php
/**
 * Analytics extras, each off by default: Google's consent mode default, and
 * phone and email tap events. Switching either on is a measurement change for
 * the site.
 *
 * **Consent default** (`consent_default`): before the kit's GA4 tag and Tag
 * Manager container, `consent` `default` with ad_storage, ad_user_data,
 * ad_personalization and analytics_storage denied, `wait_for_update` in
 * milliseconds, and the regions it applies to (blank: everywhere). Printed
 * into each data layer the kit's tags read (the GA4 tag's own and the
 * container's), on `wp_head` before them, so the default is the first thing
 * either sees. The kit only sets the default; a site's cookie banner sends
 * the update. With it on, the container's head script pushes its variables
 * onto the data layer instead of starting a new one, which would drop the
 * default.
 *
 * **Tap events** (`tap_events`): one delegated click listener, inline after
 * the tags, sends an event for every tap on a `tel:` or `mailto:` link: to the
 * GA4 tag where it prints for the visitor, and onto the container's data
 * layer where the container prints. The event names are settings, so a site
 * keeps the names it already uses. Parameters: `link` (the number or address,
 * without the scheme or any `?subject=`) and `page_path`. Nothing else. The
 * listener never stops or delays the link.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Analytics;

defined( 'ABSPATH' ) || exit;

const CONSENT_MARK_OPEN  = '<!-- tgdsk-consent -->';
const CONSENT_MARK_CLOSE = '<!-- /tgdsk-consent -->';
const TAP_DEFAULTS       = array(
	'phone' => 'phone_tap',
	'email' => 'email_tap',
);

/**
 * An event name as GA4 accepts it: a letter, then letters, digits and
 * underscores, 40 at most. '' when the input can't be one.
 *
 * @param string $value Raw input.
 * @return string
 */
function sanitize_event_name( $value ) {
	$value = trim( (string) $value );
	return preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $value ) ? $value : '';
}

/**
 * Consent regions: ISO 3166 country codes or country-region codes (US,
 * US-CA), one per line or separated by commas.
 *
 * @param string $text Raw input.
 * @return string[]
 */
function parse_regions( $text ) {
	$out = array();
	foreach ( preg_split( '/[\s,]+/', strtoupper( (string) $text ) ) as $code ) {
		if ( preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $code ) ) {
			$out[] = $code;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Whether the GA4 tag prints for this visitor on this request.
 *
 * @param array $s Module settings.
 * @return bool
 */
function ga4_printing( array $s ) {
	return '' !== $s['measurement_id'] && ! is_preview() && ! wp_doing_ajax() && visitor_is_tracked( $s ) && should_print();
}

/**
 * The consent default script, or '' when it isn't on or nothing prints.
 *
 * @param array $s Module settings.
 * @return string
 */
function consent_markup( array $s ) {
	$ga4 = ga4_printing( $s );
	$gtm = gtm_wanted();
	if ( empty( $s['consent_default'] ) || ( ! $ga4 && ! $gtm ) ) {
		return '';
	}
	$default = array(
		'ad_storage'         => 'denied',
		'ad_user_data'       => 'denied',
		'ad_personalization' => 'denied',
		'analytics_storage'  => 'denied',
		'wait_for_update'    => max( 0, (int) $s['consent_wait'] ),
	);
	if ( $s['consent_regions'] ) {
		$default['region'] = array_values( (array) $s['consent_regions'] );
	}
	$json  = wp_json_encode( $default );
	$lines = array();
	foreach ( array_filter(
		array(
			DATA_LAYER     => $ga4,
			GTM_DATA_LAYER => $gtm,
		)
	) as $layer => $on ) {
		$lines[] = "window.{$layer} = window.{$layer} || [];\n(function(){{$layer}.push(arguments);})('consent', 'default', {$json});";
	}
	return CONSENT_MARK_OPEN . "\n<script id=\"tgdsk-consent-default\">\n" . implode( "\n", $lines ) . "\n</script>\n" . CONSENT_MARK_CLOSE . "\n";
}

/**
 * Print the consent default, before the tags.
 */
function print_consent_default() {
	echo consent_markup( settings() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from validated settings and constant names.
}

/**
 * Print the consent default on admin pages, when admin pages are tagged.
 */
function print_admin_consent_default() {
	if ( ! empty( settings()['track_admin'] ) ) {
		print_consent_default();
	}
}

/**
 * The tap event listener, or '' when it isn't on or nothing prints.
 *
 * @param array $s Module settings.
 * @return string
 */
function tap_markup( array $s ) {
	$ga4 = ga4_printing( $s );
	$gtm = gtm_wanted();
	if ( empty( $s['tap_events'] ) || ( ! $ga4 && ! $gtm ) ) {
		return '';
	}
	$names = wp_json_encode(
		array(
			'tel'    => sanitize_event_name( $s['tap_phone_event'] ) ? $s['tap_phone_event'] : TAP_DEFAULTS['phone'],
			'mailto' => sanitize_event_name( $s['tap_email_event'] ) ? $s['tap_email_event'] : TAP_DEFAULTS['email'],
		)
	);
	$ga    = $ga4 ? 'true' : 'false';
	$gl    = $gtm ? 'true' : 'false';
	$cmd   = COMMAND;
	$layer = GTM_DATA_LAYER;
	return "<script id=\"tgdsk-tap-events\">\n"
		. "(function(){if(window.tgdskTapEvents){return;}window.tgdskTapEvents=1;var names={$names},ga={$ga},gtm={$gl};\n"
		. "document.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('a[href]'):null;if(!a){return;}\n"
		. "var h=a.getAttribute('href')||'',m=/^\\s*(tel|mailto):/i.exec(h);if(!m){return;}var v=h.slice(m[0].length).split('?')[0];\n"
		. "try{v=decodeURIComponent(v);}catch(x){}var p={link:v.trim(),page_path:location.pathname},n=names[m[1].toLowerCase()];\n"
		. "if(ga&&typeof window.{$cmd}==='function'){window.{$cmd}('event',n,p);}\n"
		. "if(gtm){window.{$layer}=window.{$layer}||[];window.{$layer}.push({event:n,link:p.link,page_path:p.page_path});}\n"
		. "},true);})();\n</script>\n";
}

/**
 * Print the tap event listener, after the tags.
 */
function print_tap_events() {
	echo tap_markup( settings() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from validated settings and constant names.
}

/**
 * Add the extras' hooks (from boot(), only when the module prints).
 */
function add_extras_hooks() {
	add_action( 'wp_head', __NAMESPACE__ . '\\print_consent_default', -100 );
	add_action( 'admin_head', __NAMESPACE__ . '\\print_admin_consent_default', -100 );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_tap_events', 11 );
}
