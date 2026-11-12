<?php
/**
 * Module: analytics tag.
 *
 * Puts the GA4 tag in the head, and a Tag Manager container, each from its
 * settings. Changing what this prints is a `measurement` change for the work
 * log, so the markup is fixed and every setting is explicit.
 *
 * **The GA4 tag:**
 *
 * - Printed on `wp_head` at the default priority, and on `admin_head` only when
 *   "track admin pages" is on (off by default).
 * - Previews and AJAX are skipped. Nothing else is skipped by default.
 * - **Logged-in users are tracked, administrators included**, unless their role
 *   is in "do not track these roles", which is empty by default. A network
 *   admin also counts as the pseudo-role `super_admin` for that test.
 * - gtag.js loads with its own data layer, `tgdskDataLayer`, and its own
 *   command function, `tgdsk_ga`, rather than gtag's `dataLayer` and `gtag`,
 *   so it cannot collide with a Tag Manager container's layer.
 * - `config` carries exactly two parameters, `anonymize_ip` and
 *   `allow_google_signals`, each true or false from the settings. Both are
 *   false by default.
 *
 * **The Tag Manager container (0.2.4):** `var dataLayer = [{…}];` with the
 * data layer variables switched on, then Google's loader, in an inline script
 * after the head scripts (on a handle that depends on jQuery, so jQuery is
 * enqueued too), and the `<noscript>` frame on `wp_body_open`. No role
 * exclusion and no preview test.
 *
 * Another plugin can keep both off its own pages with the
 * `tgdsk_analytics_print` filter (see should_print()).
 *
 * **Never double-tag.** On every front-end page the finished HTML is checked
 * for another tag carrying the same measurement ID or container. If one is
 * there, this module's own is taken back out and the page and time are
 * recorded for the settings screen; the notice clears the next time that page
 * renders without the other tag, and on save. This needs an output buffer:
 * the other tag can come from anywhere on the page (a theme or builder code
 * field, a pasted snippet in a footer widget, a page's content), and nothing
 * offers a hook that sees the whole page. The buffer is the narrowest that
 * works: front-end HTML only, a few string searches, the page returned
 * unchanged unless a duplicate is found.
 *
 * **The tag check** (includes/analytics/tag-check.php), a Site Health test
 * also run from the Analytics tab, fetches the home page as a logged-out
 * visitor and reports a second copy of the kit's ID or container, or any
 * other GA4 tag or container. It never runs on a front-end request.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Analytics;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../analytics/tag-check.php';
require_once __DIR__ . '/../analytics/extras.php';

const OPTION          = 'tgdsk_analytics';
const CONFLICT_OPTION = 'tgdsk_analytics_conflict';
const DATA_LAYER      = 'tgdskDataLayer';
const COMMAND         = 'tgdsk_ga';
const MARK_OPEN       = '<!-- tgdsk-analytics -->';
const MARK_CLOSE      = '<!-- /tgdsk-analytics -->';

const GTM_CONFLICT_OPTION = 'tgdsk_analytics_gtm_conflict';
const GTM_MARK_OPEN       = '<!-- tgdsk-gtm -->';
const GTM_MARK_CLOSE      = '<!-- /tgdsk-gtm -->';
// Google's own name for the container's data layer.
const GTM_DATA_LAYER = 'dataLayer';

/**
 * The data layer variables, in the order they are pushed, each with the name
 * it is pushed under when none is stored. Post variables are pushed on
 * singular pages only; visitor variables other than the login status only for
 * a signed-in visitor. Each name can be changed on the settings screen.
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

\TGD_Site_Kit\register_module(
	'analytics',
	array(
		'label'       => fn() => __( 'Analytics tag', 'tgd-site-kit' ),
		'description' => fn() => __( 'Prints the GA4 tag and a Tag Manager container, and leaves out either one on a page that already has it. A Site Health test reports any other tag on the home page.', 'tgd-site-kit' ),
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
	add_action( 'admin_post_tgdsk_analytics_tag_check', __NAMESPACE__ . '\\handle_tag_check' );
	add_action( 'wp_ajax_health-check-tgdsk-analytics-tags', __NAMESPACE__ . '\\ajax_site_health_test' );
	add_filter( 'site_status_tests', __NAMESPACE__ . '\\add_site_health_test' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );

	if ( is_blocked() ) {
		return;
	}
	add_action( 'template_redirect', __NAMESPACE__ . '\\start_duplicate_check', 1 );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_tag' );
	add_action( 'admin_head', __NAMESPACE__ . '\\print_admin_tag' );
	// The container after the head scripts (an inline script on a handle that depends on jQuery),
	// and its noscript frame first thing after <body> opens.
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_gtm_dependency' );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_gtm_head', 9 );
	add_action( 'wp_body_open', __NAMESPACE__ . '\\print_gtm_noscript', -9999 );
	add_extras_hooks();
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
 * The module's settings, with defaults.
 *
 * `gtm_variables` lists the data layer variables switched on (keys of
 * GTM_POST_VARS and GTM_VISITOR_VARS), `gtm_names` any name changed from
 * the default, and `gtm_custom` fixed name and value pairs.
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
			// 0.6.0, each off by default; see includes/analytics/extras.php.
			'consent_default'      => false,
			'consent_wait'         => 500,
			'consent_regions'      => array(),
			'tap_events'           => false,
			'tap_phone_event'      => TAP_DEFAULTS['phone'],
			'tap_email_event'      => TAP_DEFAULTS['email'],
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
 * A data layer variable name: letters, digits, `_`, `.` and `-`.
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
	return '' === $s['measurement_id'] && '' === $s['gtm_container'];
}

/**
 * Reasons this module is holding back its tag on this site, as plain text.
 *
 * For the settings screen only; boot() asks is_blocked().
 *
 * @return string[]
 */
function blockers() {
	$lines = array();
	$s     = settings();
	if ( '' === $s['measurement_id'] && '' === $s['gtm_container'] ) {
		$lines[] = __( 'Neither a measurement ID nor a Tag Manager container is set, so there is nothing to print.', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Whether the current visitor is tracked: the role exclusion.
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
 * Print the tag on admin pages, only when admin tracking is on.
 */
function print_admin_tag() {
	if ( ! empty( settings()['track_admin'] ) ) {
		print_tag();
	}
}

/**
 * The tag.
 *
 * The markers around it are what lets the duplicate check take it back out.
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
	// The `&` is written bare; the browser requests the same URL either way.
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
 * Whether the container prints on this front-end request. It has no role
 * exclusion and no preview test; the GA4 tag's "do not track these roles" is
 * GA4 only.
 *
 * @return bool
 */
function gtm_wanted() {
	return '' !== settings()['gtm_container'] && should_print();
}

/**
 * The container script is printed on a page that has jQuery enqueued: sites
 * that moved to the kit had jQuery on every page with the container, and
 * scripts in a container may rely on it.
 */
function enqueue_gtm_dependency() {
	if ( gtm_wanted() ) {
		wp_enqueue_script( 'jquery' );
	}
}

/**
 * The data layer the container starts with on this page.
 *
 * Post variables on singular pages only, from the global post; visitor variables (the login status for everyone, the rest for a
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
			// Categories and tags are left out when the post has none.
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
 * The container's head script: the data layer, then Google's loader.
 *
 * @param array $s Module settings.
 * @return string
 */
function gtm_head_markup( array $s ) {
	$vars  = data_layer_vars( $s );
	$layer = GTM_DATA_LAYER;
	$id    = esc_html( $s['gtm_container'] );
	if ( ! empty( $s['consent_default'] ) ) {
		// The consent default is already on this layer; starting a new one would drop it.
		$script = 'window.' . $layer . ' = window.' . $layer . ' || [];' . ( $vars ? "\n" . $layer . '.push(' . wp_json_encode( $vars, JSON_UNESCAPED_UNICODE ) . ');' : '' );
	} else {
		$script = $vars
			? 'var ' . $layer . ' = [' . wp_json_encode( $vars, JSON_UNESCAPED_UNICODE ) . '];'
			: 'var ' . $layer . ' = [];';
	}
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
 * Print the container's `<noscript>` frame as `<body>` opens, on
 * `wp_body_open`. A theme that never fires `wp_body_open` gets no frame; the
 * head script still loads the container for every visitor with JavaScript.
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
			'consent_default'      => ! empty( $_POST['consent_default'] ),
			'consent_wait'         => isset( $_POST['consent_wait'] ) ? min( 60000, absint( $_POST['consent_wait'] ) ) : 500,
			'consent_regions'      => parse_regions( isset( $_POST['consent_regions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['consent_regions'] ) ) : '' ),
			'tap_events'           => ! empty( $_POST['tap_events'] ),
			'tap_phone_event'      => ( fn( $n ) => '' !== $n ? $n : TAP_DEFAULTS['phone'] )( sanitize_event_name( isset( $_POST['tap_phone_event'] ) ? sanitize_text_field( wp_unslash( $_POST['tap_phone_event'] ) ) : '' ) ),
			'tap_email_event'      => ( fn( $n ) => '' !== $n ? $n : TAP_DEFAULTS['email'] )( sanitize_event_name( isset( $_POST['tap_email_event'] ) ? sanitize_text_field( wp_unslash( $_POST['tap_email_event'] ) ) : '' ) ),
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
 * Notice wording for this module's redirects.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'analytics_saved'   => __( 'Analytics settings saved.', 'tgd-site-kit' ),
		'analytics_bad_id'  => __( 'That is not a GA4 measurement ID (G- followed by letters and digits), so the ID was cleared.', 'tgd-site-kit' ),
		'analytics_bad_gtm' => __( 'That is not a Tag Manager container ID (GTM- followed by letters and digits), so the container was cleared.', 'tgd-site-kit' ),
		'analytics_checked' => __( 'Tag check finished. The result is below.', 'tgd-site-kit' ),
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
	<?php if ( ! empty( $s['tap_events'] ) ) : ?>
		<div class="notice notice-info inline"><p>
			<?php
			/* translators: 1: phone event name, 2: email event name */
			echo esc_html( sprintf( __( 'Tap events are on: phone taps send %1$s and email taps send %2$s.', 'tgd-site-kit' ), $s['tap_phone_event'], $s['tap_email_event'] ) );
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( ! empty( $s['consent_default'] ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Consent mode is on: the default (denied) prints before the tags.', 'tgd-site-kit' ); ?></p></div>
	<?php endif; ?>
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
					<p class="description"><?php esc_html_e( 'Sent as true or false on every page.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Do not track these roles', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
						<label><input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr( $role ); ?>"<?php checked( in_array( $role, $s['exclude_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Every signed-in user is tracked, administrators included, unless their role is ticked here. Visitors who are not signed in are always tracked.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Admin pages', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="track_admin" value="1"<?php checked( $s['track_admin'] ); ?>> <?php esc_html_e( 'Also tag wp-admin pages (off by default)', 'tgd-site-kit' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-gtm-id"><?php esc_html_e( 'Tag Manager container ID', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-gtm-id" name="gtm_container" class="regular-text code" value="<?php echo esc_attr( $s['gtm_container'] ); ?>" placeholder="GTM-XXXXXXX">
					<p class="description"><?php esc_html_e( 'The data layer and the container in the head, and the noscript frame as the page body opens. On every front-end page, for every visitor, signed in or not (the roles above apply to the GA4 tag only). Either this or the measurement ID may be set alone.', 'tgd-site-kit' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data layer variables', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( GTM_POST_VARS + GTM_VISITOR_VARS as $key => $default ) : ?>
						<label><input type="checkbox" name="gtm_variables[]" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( $key, (array) $s['gtm_variables'], true ) ); ?>> <?php echo esc_html( $var_labels[ $key ] ); ?></label>
						<?php esc_html_e( 'as', 'tgd-site-kit' ); ?> <input type="text" name="gtm_names[<?php echo esc_attr( $key ); ?>]" class="code" value="<?php echo esc_attr( variable_name( $s, $key ) ); ?>" aria-label="<?php echo esc_attr( $var_labels[ $key ] ); ?>"><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Pushed under the names shown. The post variables are set on single posts and pages; the user variables other than the sign-in status only for someone signed in. The container’s tags may rely on these names, so change one only together with the container.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-gtm-custom"><?php esc_html_e( 'Custom variables', 'tgd-site-kit' ); ?></label></th>
				<td><textarea id="tgdsk-gtm-custom" name="gtm_custom" class="large-text code" rows="3"><?php echo esc_textarea( implode( "\n", array_map( fn( $c ) => $c['name'] . ' = ' . $c['value'], (array) $s['gtm_custom'] ) ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line: name = value. The same on every page.', 'tgd-site-kit' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Phone and email taps', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="tap_events" value="1"<?php checked( ! empty( $s['tap_events'] ) ); ?>> <?php esc_html_e( 'Send an event for each tap on a phone (tel:) or email (mailto:) link', 'tgd-site-kit' ); ?></label><br>
					<label><?php esc_html_e( 'Phone event name', 'tgd-site-kit' ); ?> <input type="text" name="tap_phone_event" class="code" value="<?php echo esc_attr( $s['tap_phone_event'] ); ?>"></label>
					&nbsp; <label><?php esc_html_e( 'Email event name', 'tgd-site-kit' ); ?> <input type="text" name="tap_email_event" class="code" value="<?php echo esc_attr( $s['tap_email_event'] ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Set the names the site already uses before switching this on. Sent to the GA4 tag and, with a container, pushed to its data layer, with the number or address (link) and the page path (page_path) only. A container that also forwards these to the same GA4 property would count each tap twice. Switching this on is a measurement change.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent mode', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="consent_default" value="1"<?php checked( ! empty( $s['consent_default'] ) ); ?>> <?php esc_html_e( 'Print Google’s consent default before the tags: ad and analytics storage denied until the cookie banner updates it', 'tgd-site-kit' ); ?></label><br>
					<label><?php esc_html_e( 'Wait for the update', 'tgd-site-kit' ); ?> <input type="number" min="0" max="60000" name="consent_wait" class="small-text" value="<?php echo esc_attr( (string) $s['consent_wait'] ); ?>"> ms</label>
					<p><label for="tgdsk-consent-regions"><?php esc_html_e( 'Regions (blank: everywhere)', 'tgd-site-kit' ); ?></label><br>
					<textarea id="tgdsk-consent-regions" name="consent_regions" class="code" rows="2" cols="40" placeholder="GB, DE, US-CA"><?php echo esc_textarea( implode( ', ', (array) $s['consent_regions'] ) ); ?></textarea></p>
					<p class="description"><?php esc_html_e( 'The kit only sets the default. The site’s cookie banner must send the consent update, or nothing is stored for anyone in these regions. Switching this on is a measurement change.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save analytics settings', 'tgd-site-kit' ) ); ?>
	</form>

	<?php render_tag_check(); ?>
	<?php
}
