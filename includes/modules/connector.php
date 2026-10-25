<?php
/**
 * Module: Jarvis connector.
 *
 * A read-only REST surface for the WordPress MCP server. One route so far,
 * `GET /wp-json/tgdsk/v1/status`, answering what is installed, at what version,
 * what has an update waiting and what updates itself.
 *
 * Authentication is whatever WordPress resolves for the request; the MCP server
 * sends an application password, which core turns into a logged-in user before
 * the permission callback runs. The callback then requires OWNER_CAP, so an
 * editor's application password gets a 403 and an anonymous caller a 401.
 *
 * Nothing here writes. Writes arrive later through the MCP server's
 * backup-first pattern, not through this module.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Connector;

use const TGD_Site_Kit\OWNER_CAP;
use const TGD_Site_Kit\VERSION;

defined( 'ABSPATH' ) || exit;

const REST_NAMESPACE = 'tgdsk/v1';

\TGD_Site_Kit\register_module(
	'connector',
	array(
		'label'       => fn() => __( 'Jarvis connector', 'tgd-site-kit' ),
		'description' => fn() => __( 'A read-only REST route Jarvis calls for WordPress, PHP, theme and plugin versions, waiting updates and auto-update settings. Administrators only.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

/**
 * Register the status route.
 */
function register_routes() {
	register_rest_route(
		REST_NAMESPACE,
		'/status',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_status',
			'permission_callback' => __NAMESPACE__ . '\\can_read_status',
		)
	);
}

/**
 * Owner only.
 *
 * Returning a WP_Error rather than false keeps the status code honest: 401 for
 * nobody at all, 403 for somebody who is signed in without the capability.
 *
 * @return true|\WP_Error
 */
function can_read_status() {
	if ( current_user_can( OWNER_CAP ) ) {
		return true;
	}
	return new \WP_Error(
		'tgdsk_forbidden',
		__( 'Only an administrator can read site status.', 'tgd-site-kit' ),
		array( 'status' => is_user_logged_in() ? 403 : 401 )
	);
}

/**
 * Build the status payload.
 *
 * Update availability comes from the update transients WordPress already keeps,
 * not from a fresh check against wordpress.org: this route has to be cheap and
 * must never be the thing that makes an outbound request. `checked_at` says how
 * old that information is, so a caller can tell stale from current.
 *
 * Premium plugins and themes that update through their own vendor (Avada
 * through ThemeFusion, the WPMU DEV suite through its dashboard) report into the
 * same transients when their updater is working, which is the only reason those
 * show up here at all. A vendor updater that is broken or unlicensed looks like
 * "no update", and nothing on this side can tell the difference.
 *
 * @return \WP_REST_Response
 */
function rest_status() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	$plugin_updates = get_site_transient( 'update_plugins' );
	$theme_updates  = get_site_transient( 'update_themes' );
	$core_updates   = get_site_transient( 'update_core' );

	$response = new \WP_REST_Response(
		array(
			'site'      => home_url( '/' ),
			'wordpress' => array(
				'version'          => get_bloginfo( 'version' ),
				'update_available' => core_update_version( $core_updates ),
				'checked_at'       => checked_at( $core_updates ),
			),
			'php'       => array(
				'version' => PHP_VERSION,
			),
			'theme'     => theme_status( wp_get_theme(), $theme_updates ),
			'plugins'   => array(
				'checked_at' => checked_at( $plugin_updates ),
				'installed'  => plugin_statuses( $plugin_updates ),
				'must_use'   => must_use_plugins(),
			),
			'kit'       => array(
				'version' => VERSION,
				'modules' => \TGD_Site_Kit\enabled_modules(),
			),
		)
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * When an update transient was last refreshed, in UTC ISO 8601.
 *
 * @param mixed $transient Value of an update_* site transient.
 * @return string|null Null when WordPress has never checked.
 */
function checked_at( $transient ) {
	if ( is_object( $transient ) && ! empty( $transient->last_checked ) ) {
		return gmdate( 'c', (int) $transient->last_checked );
	}
	return null;
}

/**
 * The newer core version on offer, if any.
 *
 * @param mixed $transient Value of the update_core site transient.
 * @return string|null
 */
function core_update_version( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->updates ) ) {
		return null;
	}
	foreach ( $transient->updates as $offer ) {
		if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
			return (string) $offer->current;
		}
	}
	return null;
}

/**
 * Status of the active theme, and its parent when it is a child theme.
 *
 * @param \WP_Theme $theme     Theme.
 * @param mixed     $transient Value of the update_themes site transient.
 * @return array
 */
function theme_status( \WP_Theme $theme, $transient ) {
	$slug   = $theme->get_stylesheet();
	$update = is_object( $transient ) && isset( $transient->response[ $slug ]['new_version'] )
		? (string) $transient->response[ $slug ]['new_version']
		: null;

	$status = array(
		'name'             => $theme->get( 'Name' ),
		'stylesheet'       => $slug,
		'version'          => $theme->get( 'Version' ),
		'update_available' => $update,
		'auto_update'      => in_array( $slug, (array) get_site_option( 'auto_update_themes', array() ), true ),
		'checked_at'       => checked_at( $transient ),
		'parent'           => null,
	);
	if ( $theme->parent() ) {
		$status['parent'] = theme_status( $theme->parent(), $transient );
	}
	return $status;
}

/**
 * Every installed plugin with its version, state and update position.
 *
 * `auto_update` is the stored per-plugin choice. `auto_update_forced` is what a
 * filter or constant overrides it to (true, false), or null when nothing does,
 * because a site with an auto-update filter in a mu-plugin would otherwise read
 * as having auto-updates off when it does not.
 *
 * @param mixed $transient Value of the update_plugins site transient.
 * @return array[]
 */
function plugin_statuses( $transient ) {
	$auto = (array) get_site_option( 'auto_update_plugins', array() );
	$out  = array();
	foreach ( get_plugins() as $file => $data ) {
		$offer  = is_object( $transient ) && isset( $transient->response[ $file ] ) ? $transient->response[ $file ] : null;
		$item   = (object) array(
			'plugin'      => $file,
			'slug'        => $offer->slug ?? dirname( $file ),
			'new_version' => $offer->new_version ?? '',
		);
		$forced = wp_is_auto_update_forced_for_item( 'plugin', null, $item );

		$out[] = array(
			'file'               => $file,
			'name'               => $data['Name'],
			'version'            => $data['Version'],
			'active'             => is_plugin_active( $file ),
			'network_active'     => is_multisite() && is_plugin_active_for_network( $file ),
			'update_available'   => $offer && ! empty( $offer->new_version ) ? (string) $offer->new_version : null,
			'auto_update'        => in_array( $file, $auto, true ),
			'auto_update_forced' => null === $forced ? null : (bool) $forced,
		);
	}
	return $out;
}

/**
 * Must-use plugins: always active, never in get_plugins(), never auto-updated.
 *
 * @return array[]
 */
function must_use_plugins() {
	$out = array();
	foreach ( get_mu_plugins() as $file => $data ) {
		$out[] = array(
			'file'    => $file,
			'name'    => $data['Name'],
			'version' => $data['Version'],
		);
	}
	return $out;
}
