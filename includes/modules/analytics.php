<?php
/**
 * Module: analytics tag.
 *
 * Puts the GA4 tag in the head, replacing what Beehive does on the sites that
 * run it. Switching a site from Beehive to this is a `measurement` change for
 * the work log, so the whole point of this file is that GA receives exactly
 * what it received before.
 *
 * **What Beehive does, read from Beehive Analytics 3.5.3 and checked against
 * the public HTML of a production site running Beehive Pro, 2026-10-25:**
 *
 * - Prints on `wp_head` at the default priority, and on `admin_head` only when
 *   its "track admin pages" setting is on (off by default).
 * - Skips previews and AJAX. Nothing else is skipped by default.
 * - **Logged-in users are tracked, administrators included**, unless their role
 *   is in the "exclude roles" setting, which is empty by default. A network
 *   admin also counts as the pseudo-role `super_admin` for that test.
 * - Loads gtag.js with its own data layer, `beehiveDataLayer`, and its own
 *   command function, `beehive_ga`, rather than core gtag's `dataLayer` and
 *   `gtag`. Kept here verbatim: GA receives nothing different either way, but
 *   anything on a site that pushes events through `beehive_ga()` keeps working,
 *   and a separate layer still cannot collide with Site Kit's or GTM's.
 * - Sends `config` with exactly two parameters, `anonymize_ip` and
 *   `allow_google_signals`, each true or false from its settings. Both are
 *   false by default.
 *
 * Those per-site settings are not visible from outside, so the settings screen
 * imports them from Beehive's own option on the site, while Beehive is still
 * installed. That is the switch procedure: import, check, deactivate Beehive.
 *
 * **A Tag Manager container (0.2.4)**, as Beehive's Google Tag Manager
 * module prints it, read from Beehive Analytics 3.5.3 and checked against
 * the public HTML of a production site, 2026-10-26: `var dataLayer = [{…}];` with the data layer
 * variables switched on, then Google's loader, in an inline script after the
 * head scripts (Beehive's handle depends on jQuery, so jQuery is enqueued
 * too), and the `<noscript>` frame on `wp_body_open`. No role exclusion and no
 * preview test: Beehive's container has neither. Beehive's Forminator and
 * Hustle event pushes are not reproduced; the import lists them if they were on.
 *
 * Another plugin can keep both off its own pages with the
 * `tgdsk_analytics_print` filter (see should_print()).
 *
 * **Never double-tag.** Nothing is printed while Beehive or Site Kit by Google
 * is active, and on every front-end page the finished HTML is checked for
 * another tag carrying the same measurement ID — a code box in the theme, a
 * pasted snippet, another plugin. If one is there, this module's tag is taken
 * back out and the page and time are recorded for the settings screen. The
 * same goes for the Tag Manager container. A notice clears itself the next
 * time the page it names renders without the other tag, and on save; it would
 * otherwise expire a week after it was set.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Analytics;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const OPTION          = 'tgdsk_analytics';
const CONFLICT_OPTION = 'tgdsk_analytics_conflict';
const DATA_LAYER      = 'beehiveDataLayer';
const COMMAND         = 'beehive_ga';
const MARK_OPEN       = '<!-- tgdsk-analytics -->';
const MARK_CLOSE      = '<!-- /tgdsk-analytics -->';

const GTM_CONFLICT_OPTION = 'tgdsk_analytics_gtm_conflict';
const GTM_MARK_OPEN       = '<!-- tgdsk-gtm -->';
const GTM_MARK_CLOSE      = '<!-- /tgdsk-gtm -->';
// Beehive's name for the container's data layer on a single site.
const GTM_DATA_LAYER = 'dataLayer';

/**
 * Beehive's data layer variables, in the order it pushes them, each with the
 * name it is pushed under when none is stored. Post variables are pushed on
 * singular pages only; visitor variables other than the login status only for
 * a signed-in visitor. Beehive lets each name be changed.
 *
 * Beehive's settings screen shows the visitor variables as `logged_in_status`,
 * `logged_in_user_id` and so on, but it pushes the bare key unless those names
 * were saved into its option (it reads the option without its defaults;
 * checked on Beehive Analytics 3.5.3, 2026-10-26). So the bare key is the
 * default here, and the import copies whatever names Beehive actually stored.
 */
const GTM_POST_VARS    = array(
	'post_id'          => 'post_id',
	'post_title'       => 'post_title',
	'post_type'        => 'post_type',
	'post_date'        => 'post_date',
	'post_author'      => 'post_author',
	'post_author_name' => 'post_author_name',
	'post_categories'  => 'post_categories',
	'post_tags'        => 'post_tags',
);
const GTM_VISITOR_VARS = array(
	'login_status'       => 'login_status',
	'user_role'          => 'user_role',
	'user_id'            => 'user_id',
	'user_name'          => 'user_name',
	'user_email'         => 'user_email',
	'user_creation_date' => 'user_creation_date',
);

/**
 * What Beehive's container can also send that this module does not: form,
 * poll, quiz and lead events pushed from Forminator and Hustle.
 */
const GTM_UNSUPPORTED = array( 'forminator_forms', 'forminator_polls', 'forminator_quizzes', 'hustle_leads' );

\TGD_Site_Kit\register_module(
	'analytics',
	array(
		'label'       => fn() => __( 'Analytics tag', 'tgd-site-kit' ),
		'description' => fn() => __( 'Prints the GA4 tag and a Tag Manager container the way Beehive does. Prints nothing while Beehive or Site Kit by Google is active, and leaves out either one on a page that already has it.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	add_action( 'admin_post_tgdsk_analytics_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_analytics_import', __NAMESPACE__ . '\\handle_import' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );

	if ( is_blocked() ) {
		return;
	}
	add_action( 'template_redirect', __NAMESPACE__ . '\\start_duplicate_check', 1 );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_tag' );
	add_action( 'admin_head', __NAMESPACE__ . '\\print_admin_tag' );
	// The container where Beehive puts it: after the head scripts (Beehive's is an inline script on a
	// handle that depends on jQuery), and first thing after <body> opens.
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gtm_dependency' );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_gtm_head', 9 );
	add_action( 'wp_body_open', __NAMESPACE__ . '\\print_gtm_noscript', -9999 );
}

/**
 * Whether the tags print on this request at all. Another plugin can keep
 * them off its own pages (a staff app, a kiosk) by returning false from the
 * `tgdsk_analytics_print` filter, for example
 * `add_filter( 'tgdsk_analytics_print', fn( $print ) => $print && ! my_staff_page() );`.
 * Checked before the GA4 tag and the Tag Manager container, on the front end
 * and on admin pages.
 *
 * @return bool
 */
function should_print() {
	return (bool) apply_filters( 'tgdsk_analytics_print', true );
}

/**
 * The module's settings, with defaults matching Beehive's.
 *
 * `gtm_variables` lists the data layer variables switched on (keys of
 * GTM_POST_VARS and GTM_VISITOR_VARS), `gtm_names` any name changed from
 * Beehive's default, and `gtm_custom` fixed name and value pairs.
 *
 * @return array{measurement_id:string, anonymize_ip:bool, allow_google_signals:bool, exclude_roles:string[], track_admin:bool, gtm_container:string, gtm_variables:string[], gtm_names:array<string,string>, gtm_custom:array[]}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	return wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'measurement_id'       => '',
			'anonymize_ip'         => false,
			'allow_google_signals' => false,
			'exclude_roles'        => array(),
			'track_admin'          => false,
			'gtm_container'        => '',
			'gtm_variables'        => array(),
			'gtm_names'            => array(),
			'gtm_custom'           => array(),
		)
	);
}

/**
 * A Tag Manager container ID, or '' if the input is not one.
 *
 * @param string $value Raw input.
 * @return string
 */
function sanitize_container_id( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	return preg_match( '/^GTM-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
}

/**
 * A data layer variable name: what Beehive allows, without spaces.
 *
 * @param string $value Raw input.
 * @return string
 */
function sanitize_variable_name( $value ) {
	return (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $value );
}

/**
 * The name a data layer variable is pushed under.
 *
 * @param array  $settings Module settings.
 * @param string $key      Variable key.
 * @return string
 */
function variable_name( array $settings, $key ) {
	$name = sanitize_variable_name( $settings['gtm_names'][ $key ] ?? '' );
	return '' !== $name ? $name : ( GTM_POST_VARS[ $key ] ?? GTM_VISITOR_VARS[ $key ] ?? $key );
}

/**
 * A GA4 measurement ID, or '' if the input is not one.
 *
 * @param string $value Raw input.
 * @return string
 */
function sanitize_measurement_id( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	return preg_match( '/^G-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
}

/**
 * Whether anything stops this module printing its tag on this site.
 *
 * The same tests as blockers(), without the wording: boot() runs on
 * plugins_loaded, before translations may load, on every request.
 *
 * @return bool
 */
function is_blocked() {
	$s = settings();
	return \TGD_Site_Kit\plugin_active_with_prefix( 'beehive' )
		|| \TGD_Site_Kit\plugin_active( 'google-site-kit/google-site-kit.php' )
		|| ( '' === $s['measurement_id'] && '' === $s['gtm_container'] );
}

/**
 * Reasons this module is holding back its tag on this site, as plain text.
 *
 * For the settings screen only; boot() asks is_blocked().
 *
 * @return string[]
 */
function blockers() {
	$lines   = array();
	$beehive = \TGD_Site_Kit\plugin_active_with_prefix( 'beehive' );
	if ( $beehive ) {
		$lines[] = sprintf(
			/* translators: %s: plugin basename */
			__( 'Beehive is active (%s), so this module prints nothing. Import its settings below, then deactivate Beehive.', 'tgd-site-kit' ),
			$beehive
		);
	}
	if ( \TGD_Site_Kit\plugin_active( 'google-site-kit/google-site-kit.php' ) ) {
		$lines[] = __( 'Site Kit by Google is active and can place its own GA4 tag and Tag Manager container, so this module prints nothing.', 'tgd-site-kit' );
	}
	$s = settings();
	if ( '' === $s['measurement_id'] && '' === $s['gtm_container'] ) {
		$lines[] = __( 'Neither a measurement ID nor a Tag Manager container is set, so there is nothing to print.', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Whether the current visitor is tracked: Beehive's role exclusion.
 *
 * @param array $settings Module settings.
 * @return bool
 */
function visitor_is_tracked( array $settings ) {
	if ( ! is_user_logged_in() || empty( $settings['exclude_roles'] ) ) {
		return true;
	}
	$roles = (array) wp_get_current_user()->roles;
	if ( current_user_can( 'manage_network' ) ) {
		$roles[] = 'super_admin';
	}
	return ! array_intersect( (array) $settings['exclude_roles'], $roles );
}

/**
 * Print the tag in the front-end head.
 */
function print_tag() {
	if ( is_preview() || wp_doing_ajax() ) {
		return;
	}
	$settings = settings();
	if ( '' === $settings['measurement_id'] || ! visitor_is_tracked( $settings ) || ! should_print() ) {
		return;
	}
	echo tag_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in tag_markup().
}

/**
 * Print the tag on admin pages, only when Beehive's admin tracking was on.
 */
function print_admin_tag() {
	if ( ! empty( settings()['track_admin'] ) ) {
		print_tag();
	}
}

/**
 * The tag, Beehive's markup with Beehive's names.
 *
 * Whitespace follows Beehive's template closely but not to the byte, because
 * whitespace inside a script block means nothing to a browser or to GA. The
 * markers around it are what lets the duplicate check take it back out.
 *
 * @param array $settings Module settings.
 * @return string
 */
function tag_markup( array $settings ) {
	$id     = esc_attr( $settings['measurement_id'] );
	$layer  = DATA_LAYER;
	$cmd    = COMMAND;
	$anon   = $settings['anonymize_ip'] ? 'true' : 'false';
	$signal = $settings['allow_google_signals'] ? 'true' : 'false';
	// Beehive writes the `&` bare; the browser requests the same URL either way.
	$src = 'https://www.googletagmanager.com/gtag/js?id=' . $id . '&l=' . $layer;

	return MARK_OPEN . "\n"
		. '<script async src="' . $src . '"></script>' . "\n" // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the tag has to be exactly this markup, in the head, unconditionally.
		. "<script>\n"
		. "\twindow.{$layer} = window.{$layer} || [];\n"
		. "\tfunction {$cmd}() {{$layer}.push(arguments);}\n"
		. "\t{$cmd}('js', new Date())\n"
		. "\t{$cmd}('config', '{$id}', {\n"
		. "\t\t'anonymize_ip': {$anon},\n"
		. "\t\t'allow_google_signals': {$signal},\n"
		. "\t})\n"
		. "</script>\n"
		. MARK_CLOSE . "\n";
}

/**
 * Whether the container prints on this front-end request. Beehive's has no
 * role exclusion and no preview test, so neither has this; the GA4 tag's
 * "do not track these roles" is GA4 only, as in Beehive.
 *
 * @return bool
 */
function gtm_wanted() {
	return '' !== settings()['gtm_container'] && should_print();
}

/**
 * Beehive's container script is an inline script on a dummy handle that
 * depends on jQuery, so on Beehive every page with the container loads
 * jQuery. Kept, so a page doesn't lose jQuery when Beehive goes.
 */
function enqueue_gtm_dependency() {
	if ( gtm_wanted() ) {
		wp_enqueue_script( 'jquery' );
	}
}

/**
 * The data layer Beehive starts the container with on this page.
 *
 * Post variables on singular pages only, from the global post as Beehive
 * reads it; visitor variables (the login status for everyone, the rest for a
 * signed-in visitor); then the fixed custom pairs, which win on a clash.
 *
 * @param array $s Module settings.
 * @return array
 */
function data_layer_vars( array $s ) {
	global $post;
	$on   = array_flip( (array) $s['gtm_variables'] );
	$vars = array();
	if ( is_singular() && $post instanceof \WP_Post ) {
		$values = array(
			'post_id'          => fn() => $post->ID,
			'post_title'       => fn() => $post->post_title,
			'post_type'        => fn() => $post->post_type,
			'post_date'        => fn() => get_the_date( 'Y-m-d' ),
			'post_author'      => fn() => $post->post_author,
			'post_author_name' => fn() => ( get_userdata( (int) $post->post_author ) ? get_userdata( (int) $post->post_author )->display_name : null ),
			'post_categories'  => fn() => wp_list_pluck( (array) get_the_category(), 'slug' ),
			'post_tags'        => fn() => wp_list_pluck( (array) get_the_tags(), 'slug' ),
		);
		foreach ( $values as $key => $value ) {
			if ( ! isset( $on[ $key ] ) ) {
				continue;
			}
			$v = $value();
			// Beehive leaves categories and tags out when the post has none.
			if ( in_array( $key, array( 'post_categories', 'post_tags' ), true ) && ! $v ) {
				continue;
			}
			$vars[ variable_name( $s, $key ) ] = $v;
		}
	}
	if ( isset( $on['login_status'] ) ) {
		$vars[ variable_name( $s, 'login_status' ) ] = is_user_logged_in() ? 'logged-in' : 'logged-out';
	}
	if ( is_user_logged_in() ) {
		$user   = wp_get_current_user();
		$values = array(
			'user_id'            => fn() => get_current_user_id(),
			'user_role'          => fn() => empty( $user->roles[0] ) ? array() : $user->roles[0],
			'user_name'          => fn() => $user->user_login,
			'user_email'         => fn() => $user->user_email,
			'user_creation_date' => fn() => $user->user_registered,
		);
		foreach ( $values as $key => $value ) {
			if ( isset( $on[ $key ] ) ) {
				$vars[ variable_name( $s, $key ) ] = $value();
			}
		}
	}
	foreach ( (array) $s['gtm_custom'] as $item ) {
		if ( ! empty( $item['name'] ) && ! empty( $item['value'] ) ) {
			$vars[ $item['name'] ] = $item['value'];
		}
	}
	return $vars;
}

/**
 * The container's head script: the data layer, then Google's loader, as
 * Beehive prints them (the loader keeps Beehive's line breaks and tabs).
 *
 * @param array $s Module settings.
 * @return string
 */
function gtm_head_markup( array $s ) {
	$vars    = data_layer_vars( $s );
	$layer   = GTM_DATA_LAYER;
	$id      = esc_html( $s['gtm_container'] );
	$script  = $vars
		? 'var ' . $layer . ' = [' . wp_json_encode( $vars, JSON_UNESCAPED_UNICODE ) . '];'
		: 'var ' . $layer . ' = [];';
	$script .= "\n(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
			new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
			j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
			'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
			})(window,document,'script','" . $layer . "','" . $id . "');";
	// `</` can't end the script early: wp_json_encode leaves `/` escaped as `\/`.
	return GTM_MARK_OPEN . "\n<script id=\"tgdsk-gtm-js\">\n" . $script . "\n</script>\n" . GTM_MARK_CLOSE . "\n";
}

/**
 * Print the container's head script.
 */
function print_gtm_head() {
	if ( gtm_wanted() ) {
		echo gtm_head_markup( settings() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the container ID is escaped and the data layer is JSON.
	}
}

/**
 * Print the container's `<noscript>` frame as `<body>` opens, as Beehive
 * does on `wp_body_open`. A theme that never fires `wp_body_open` gets no
 * frame, with Beehive and here alike; the head script still loads the
 * container for every visitor with JavaScript.
 */
function print_gtm_noscript() {
	if ( ! gtm_wanted() ) {
		return;
	}
	$id     = esc_attr( settings()['gtm_container'] );
	$markup = GTM_MARK_OPEN . "\n"
		. "\t\t<noscript>\n"
		. "\t\t\t<iframe src=\"https://www.googletagmanager.com/ns.html?id={$id}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe>\n"
		. "\t\t</noscript>\n"
		. GTM_MARK_CLOSE . "\n";
	echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markers are constants; the ID is escaped above.
}

/**
 * Buffer the page so the finished HTML can be checked for a second tag.
 *
 * Front-end HTML requests only. The buffer callback does a handful of string
 * searches and returns the page unchanged unless it finds a duplicate.
 */
function start_duplicate_check() {
	if ( ! \TGD_Site_Kit\is_front_end_request() || is_robots() || is_embed() ) {
		return;
	}
	ob_start( __NAMESPACE__ . '\\remove_tag_if_duplicated' );
}

/**
 * Take this module's tag back out of a page that already carries the same ID.
 *
 * Looks for the ID in a gtag.js URL or a `config` call anywhere in the page
 * outside our own markers, so a tag pasted into a footer box counts as much as
 * one in the head. A GTM container that fires GA4 from inside Google's own
 * script is invisible to this, because the ID never appears in the page.
 *
 * The container is checked the same way, on its own: see
 * remove_container_if_duplicated().
 *
 * @param string $html Finished page.
 * @return string
 */
function remove_tag_if_duplicated( $html ) {
	return remove_container_if_duplicated( remove_ga4_if_duplicated( $html ) );
}

/**
 * Take this module's container (head script and `<noscript>` frame) back out
 * of a page that already loads the same container from somewhere else: the
 * container ID anywhere outside our markers counts, since every way of
 * loading one names it in the page.
 *
 * @param string $html Finished page.
 * @return string
 */
function remove_container_if_duplicated( $html ) {
	if ( ! str_contains( $html, GTM_MARK_OPEN ) ) {
		return $html;
	}
	$pattern = '/' . preg_quote( GTM_MARK_OPEN, '/' ) . '.*?' . preg_quote( GTM_MARK_CLOSE, '/' ) . '\n?/s';
	$rest    = (string) preg_replace( $pattern, '', $html );
	$id      = preg_quote( settings()['gtm_container'], '/' );
	if ( '' === $id || ! preg_match( '/.{0,40}\b' . $id . '\b.{0,20}/i', $rest, $match ) ) {
		clear_conflict_if_here( GTM_CONFLICT_OPTION );
		return $html;
	}
	if ( ! get_transient( GTM_CONFLICT_OPTION ) ) {
		set_transient(
			GTM_CONFLICT_OPTION,
			array(
				'url'   => request_url(),
				'match' => trim( $match[0] ),
				'time'  => time(),
			),
			WEEK_IN_SECONDS
		);
	}
	return $rest;
}

/**
 * The GA4 half of remove_tag_if_duplicated(). A clean page clears the
 * notice when it is the page the notice names (0.2.5).
 *
 * @param string $html Finished page.
 * @return string
 */
function remove_ga4_if_duplicated( $html ) {
	$start = strpos( $html, MARK_OPEN );
	if ( false === $start ) {
		return $html;
	}
	$end = strpos( $html, MARK_CLOSE, $start );
	if ( false === $end ) {
		return $html;
	}
	$end  += strlen( MARK_CLOSE ) + 1;
	$rest  = substr( $html, 0, $start ) . substr( $html, $end );
	$id    = preg_quote( settings()['measurement_id'], '/' );
	$found = preg_match( '/gtag\/js\?id=' . $id . '\b|[\'"]config[\'"]\s*,\s*[\'"]' . $id . '[\'"]/i', $rest, $match );
	if ( ! $found ) {
		clear_conflict_if_here( CONFLICT_OPTION );
		return $html;
	}

	if ( ! get_transient( CONFLICT_OPTION ) ) {
		set_transient(
			CONFLICT_OPTION,
			array(
				'url'   => request_url(),
				'match' => $match[0],
				'time'  => time(),
			),
			WEEK_IN_SECONDS
		);
	}
	return $rest;
}

/**
 * This request's URL, as a conflict notice records the page it names.
 *
 * @return string
 */
function request_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return home_url( $uri );
}

/**
 * Clear a conflict notice when the page it names has just rendered without
 * the other tag. Called only on a clean page, and costs one get_transient()
 * there; a notice naming some other page is left for that page to clear.
 *
 * @param string $option CONFLICT_OPTION or GTM_CONFLICT_OPTION.
 */
function clear_conflict_if_here( $option ) {
	$conflict = get_transient( $option );
	if ( is_array( $conflict ) && ( $conflict['url'] ?? '' ) === request_url() ) {
		delete_transient( $option );
	}
}

/**
 * Import tracking settings from Beehive's own option on this site.
 *
 * Beehive keeps everything in `beehive_settings`, grouped. The measurement ID
 * follows Beehive's own rule: an ID collected automatically from a connected
 * Google account wins over the typed one when automatic tracking is on.
 * Copies only; Beehive's option is not touched.
 *
 * @return array|null The imported settings, or null when Beehive has none.
 */
function import_from_beehive() {
	$beehive = get_option( 'beehive_settings' );
	if ( ! is_array( $beehive ) ) {
		return null;
	}
	$id   = $beehive['tracking']['measurement'] ?? '';
	$auto = $beehive['google']['auto_track_ga4'] ?? true;
	if ( $auto && ! empty( $beehive['misc']['auto_track_ga4'] ) ) {
		$id = $beehive['misc']['auto_track_ga4'];
	}
	$imported = array(
		'measurement_id'       => sanitize_measurement_id( $id ),
		'anonymize_ip'         => ! empty( $beehive['general']['anonymize'] ),
		'allow_google_signals' => ! empty( $beehive['general']['advertising'] ),
		'exclude_roles'        => array_values( array_map( 'sanitize_key', (array) ( $beehive['tracking']['exclude_roles'] ?? array() ) ) ),
		'track_admin'          => ! empty( $beehive['general']['track_admin'] ),
	) + import_gtm( (array) ( $beehive['gtm'] ?? array() ) );
	update_option( OPTION, $imported, true );
	return $imported;
}

/**
 * Beehive's Tag Manager settings as this module stores them: the container
 * when Beehive has it switched on, the variables it has switched on, any name
 * changed from Beehive's default, and the custom pairs Beehive would print.
 * Form and lead events this module doesn't send are listed under
 * `gtm_not_imported` for the settings screen.
 *
 * @param array $gtm Beehive's `gtm` group.
 * @return array
 */
function import_gtm( array $gtm ) {
	$enabled = array_map( 'strval', (array) ( $gtm['enabled'] ?? array() ) );
	$known   = GTM_POST_VARS + GTM_VISITOR_VARS;
	$names   = array();
	foreach ( array( 'variables', 'visitors' ) as $group ) {
		foreach ( (array) ( $gtm[ $group ] ?? array() ) as $key => $name ) {
			$name = sanitize_variable_name( $name );
			if ( isset( $known[ $key ] ) && '' !== $name && $name !== $known[ $key ] ) {
				$names[ $key ] = $name;
			}
		}
	}
	$custom = array();
	foreach ( (array) ( $gtm['custom'] ?? array() ) as $item ) {
		$name = sanitize_variable_name( str_replace( ' ', '', (string) ( $item['name'] ?? '' ) ) );
		if ( '' !== $name && ! empty( $item['value'] ) ) {
			$custom[] = array(
				'name'  => $name,
				'value' => sanitize_text_field( (string) $item['value'] ),
			);
		}
	}
	return array(
		'gtm_container'    => ! empty( $gtm['active'] ) ? sanitize_container_id( $gtm['container'] ?? '' ) : '',
		'gtm_variables'    => array_values( array_intersect( array_keys( $known ), $enabled ) ),
		'gtm_names'        => $names,
		'gtm_custom'       => $custom,
		'gtm_not_imported' => array_values( array_intersect( GTM_UNSUPPORTED, $enabled ) ),
	);
}

/**
 * Save the settings form.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_analytics_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$roles = isset( $_POST['exclude_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['exclude_roles'] ) ) : array();
	$raw   = isset( $_POST['measurement_id'] ) ? sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) : '';
	$id    = sanitize_measurement_id( $raw );
	$raw_c = isset( $_POST['gtm_container'] ) ? sanitize_text_field( wp_unslash( $_POST['gtm_container'] ) ) : '';
	$cont  = sanitize_container_id( $raw_c );
	$known = GTM_POST_VARS + GTM_VISITOR_VARS;
	$vars  = isset( $_POST['gtm_variables'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['gtm_variables'] ) ) : array();
	$names = array();
	foreach ( $known as $key => $default ) {
		$name = isset( $_POST['gtm_names'][ $key ] ) ? sanitize_variable_name( sanitize_text_field( wp_unslash( $_POST['gtm_names'][ $key ] ) ) ) : '';
		if ( '' !== $name && $name !== $default ) {
			$names[ $key ] = $name;
		}
	}
	$custom = array();
	$lines  = isset( $_POST['gtm_custom'] ) ? preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['gtm_custom'] ) ) ) : array();
	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '=', $line, 2 ) );
		$name  = sanitize_variable_name( $parts[0] );
		if ( 2 === count( $parts ) && '' !== $name && '' !== $parts[1] ) {
			$custom[] = array(
				'name'  => $name,
				'value' => $parts[1],
			);
		}
	}
	$before = settings();
	update_option(
		OPTION,
		array(
			'measurement_id'       => $id,
			'anonymize_ip'         => ! empty( $_POST['anonymize_ip'] ),
			'allow_google_signals' => ! empty( $_POST['allow_google_signals'] ),
			'exclude_roles'        => array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) ),
			'track_admin'          => ! empty( $_POST['track_admin'] ),
			'gtm_container'        => $cont,
			'gtm_variables'        => array_values( array_intersect( array_keys( $known ), $vars ) ),
			'gtm_names'            => $names,
			'gtm_custom'           => $custom,
			'gtm_not_imported'     => (array) ( $before['gtm_not_imported'] ?? array() ),
		),
		true
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	delete_transient( CONFLICT_OPTION );
	delete_transient( GTM_CONFLICT_OPTION );
	$notice = ( '' !== $raw && '' === $id ) ? 'analytics_bad_id' : ( ( '' !== $raw_c && '' === $cont ) ? 'analytics_bad_gtm' : 'analytics_saved' );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( $notice, 'analytics' ) );
	exit;
}

/**
 * Run the Beehive import from the settings screen.
 */
function handle_import() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_analytics_import' );
	$result = import_from_beehive();
	wp_safe_redirect( \TGD_Site_Kit\settings_url( null === $result ? 'analytics_no_beehive' : 'analytics_imported', 'analytics' ) );
	exit;
}

/**
 * Notice wording for this module's redirects.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'analytics_saved'      => __( 'Analytics settings saved.', 'tgd-site-kit' ),
		'analytics_bad_id'     => __( 'That is not a GA4 measurement ID (G- followed by letters and digits), so the ID was cleared.', 'tgd-site-kit' ),
		'analytics_bad_gtm'    => __( 'That is not a Tag Manager container ID (GTM- followed by letters and digits), so the container was cleared.', 'tgd-site-kit' ),
		'analytics_imported'   => __( 'Imported from Beehive. Check the values below before deactivating Beehive.', 'tgd-site-kit' ),
		'analytics_no_beehive' => __( 'Beehive has no saved settings on this site, so nothing was imported.', 'tgd-site-kit' ),
	);
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s            = settings();
	$conflict     = get_transient( CONFLICT_OPTION );
	$gtm_conflict = get_transient( GTM_CONFLICT_OPTION );
	$var_labels   = array(
		'post_id'            => __( 'Post ID', 'tgd-site-kit' ),
		'post_title'         => __( 'Post title', 'tgd-site-kit' ),
		'post_type'          => __( 'Post type', 'tgd-site-kit' ),
		'post_date'          => __( 'Post date (Y-m-d)', 'tgd-site-kit' ),
		'post_author'        => __( 'Post author ID', 'tgd-site-kit' ),
		'post_author_name'   => __( 'Post author name', 'tgd-site-kit' ),
		'post_categories'    => __( 'Post category slugs', 'tgd-site-kit' ),
		'post_tags'          => __( 'Post tag slugs', 'tgd-site-kit' ),
		'login_status'       => __( 'Signed in or not', 'tgd-site-kit' ),
		'user_role'          => __( 'Signed-in user’s role', 'tgd-site-kit' ),
		'user_id'            => __( 'Signed-in user’s ID', 'tgd-site-kit' ),
		'user_name'          => __( 'Signed-in user’s username', 'tgd-site-kit' ),
		'user_email'         => __( 'Signed-in user’s email address', 'tgd-site-kit' ),
		'user_creation_date' => __( 'Signed-in user’s registration date', 'tgd-site-kit' ),
	);
	?>
	<h2><?php esc_html_e( 'Analytics tag', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<?php if ( is_array( $conflict ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			printf(
				/* translators: 1: page URL, 2: matched text, 3: date */
				esc_html__( 'Another tag for this measurement ID was found on %1$s (%2$s) on %3$s, so this module removed its own tag from that page. It does this on every page where it finds one, and the notice clears the next time that page loads without the other tag.', 'tgd-site-kit' ),
				esc_html( $conflict['url'] ),
				'<code>' . esc_html( $conflict['match'] ) . '</code>',
				esc_html( wp_date( 'M j, Y g:ia', $conflict['time'] ) )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( is_array( $gtm_conflict ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			printf(
				/* translators: 1: page URL, 2: matched text, 3: date */
				esc_html__( 'This Tag Manager container is already loaded on %1$s (%2$s), found %3$s, so this module removed its own container from that page. It does this on every page where it finds one, and the notice clears the next time that page loads without the other container.', 'tgd-site-kit' ),
				esc_html( $gtm_conflict['url'] ),
				'<code>' . esc_html( $gtm_conflict['match'] ) . '</code>',
				esc_html( wp_date( 'M j, Y g:ia', $gtm_conflict['time'] ) )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( ! empty( $s['gtm_not_imported'] ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			printf(
				/* translators: %s: Beehive setting keys */
				esc_html__( 'Beehive also sends form or lead events to the container (%s). This module does not, so tags in the container that wait for those events will stop firing once Beehive is off.', 'tgd-site-kit' ),
				'<code>' . esc_html( implode( ', ', $s['gtm_not_imported'] ) ) . '</code>'
			);
			?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_analytics_save">
		<?php wp_nonce_field( 'tgdsk_analytics_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-ga-id"><?php esc_html_e( 'Measurement ID', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-ga-id" name="measurement_id" class="regular-text code" value="<?php echo esc_attr( $s['measurement_id'] ); ?>" placeholder="G-XXXXXXXXXX"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'gtag config', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="anonymize_ip" value="1"<?php checked( $s['anonymize_ip'] ); ?>> <code>anonymize_ip</code></label><br>
					<label><input type="checkbox" name="allow_google_signals" value="1"<?php checked( $s['allow_google_signals'] ); ?>> <code>allow_google_signals</code></label>
					<p class="description"><?php esc_html_e( 'Sent as true or false on every page, as Beehive sends them.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Do not track these roles', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
						<label><input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr( $role ); ?>"<?php checked( in_array( $role, $s['exclude_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Beehive tracks every signed-in user, administrators included, unless their role is ticked here. Visitors who are not signed in are always tracked.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Admin pages', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="track_admin" value="1"<?php checked( $s['track_admin'] ); ?>> <?php esc_html_e( 'Also tag wp-admin pages (Beehive’s “track admin pages”; off by default)', 'tgd-site-kit' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-gtm-id"><?php esc_html_e( 'Tag Manager container ID', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-gtm-id" name="gtm_container" class="regular-text code" value="<?php echo esc_attr( $s['gtm_container'] ); ?>" placeholder="GTM-XXXXXXX">
					<p class="description"><?php esc_html_e( 'Printed as Beehive prints it: the data layer and the container in the head, and the noscript frame as the page body opens. On every front-end page, for every visitor, signed in or not (the roles above apply to the GA4 tag only). Either this or the measurement ID may be set alone.', 'tgd-site-kit' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data layer variables', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( GTM_POST_VARS + GTM_VISITOR_VARS as $key => $default ) : ?>
						<label><input type="checkbox" name="gtm_variables[]" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, (array) $s['gtm_variables'], true ) ); ?>> <?php echo esc_html( $var_labels[ $key ] ); ?></label>
						<?php esc_html_e( 'as', 'tgd-site-kit' ); ?> <input type="text" name="gtm_names[<?php echo esc_attr( $key ); ?>]" class="code" value="<?php echo esc_attr( variable_name( $s, $key ) ); ?>" aria-label="<?php echo esc_attr( $var_labels[ $key ] ); ?>"><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Beehive’s variables, under the names Beehive gives them unless changed. The post variables are set on single posts and pages; the user variables other than the sign-in status only for someone signed in. The container’s tags may rely on these, so import them from Beehive rather than guessing.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-gtm-custom"><?php esc_html_e( 'Custom variables', 'tgd-site-kit' ); ?></label></th>
				<td><textarea id="tgdsk-gtm-custom" name="gtm_custom" class="large-text code" rows="3"><?php echo esc_textarea( implode( "\n", array_map( fn( $c ) => $c['name'] . ' = ' . $c['value'], (array) $s['gtm_custom'] ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line: name = value. The same on every page.', 'tgd-site-kit' ); ?></p></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save analytics settings', 'tgd-site-kit' ) ); ?>
	</form>

	<h3><?php esc_html_e( 'Import from Beehive', 'tgd-site-kit' ); ?></h3>
	<p><?php esc_html_e( 'Copies the measurement ID, the two config flags, the excluded roles, admin tracking, and the Tag Manager container with its data layer variables from Beehive’s settings on this site, replacing the values above. Beehive’s settings are not changed.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_analytics_import">
		<?php wp_nonce_field( 'tgdsk_analytics_import' ); ?>
		<?php submit_button( __( 'Import from Beehive', 'tgd-site-kit' ), 'secondary' ); ?>
	</form>
	<?php
}
