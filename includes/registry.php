<?php
/**
 * The module registry: what modules exist, which are on, and booting them.
 *
 * A module is one file under includes/modules/ that calls register_module()
 * when it is loaded. Loading a module file registers nothing with WordPress
 * except that declaration. Its hooks are added by its boot callback, and that
 * runs only when the module is switched on for this site.
 *
 * Switching a module off stops its output on the next request and leaves its
 * data where it is. Nothing here deletes anything.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit;

defined( 'ABSPATH' ) || exit;

/**
 * The registry itself, by reference so register_module() can add to it.
 *
 * @return array<string, array> Module id => its declaration.
 */
function &registry() {
	static $modules = array();
	return $modules;
}

/**
 * Declare a module.
 *
 * The declaration's keys:
 *
 * - `label`: name on the settings screen.
 * - `description`: one sentence on what switching it on does.
 * - `boot`: adds the module's hooks. Called once, on plugins_loaded, only
 *   when the module is on.
 * - `settings`: optional. Renders the module's own tab of the settings
 *   screen, shown only when it is on.
 * - `notices`: optional. Returns plain-text lines explaining why an enabled
 *   module is holding back its output on this site.
 *
 * @param string $id   Short slug, stored in MODULES_OPTION. Never rename one
 *                     that has shipped: the stored list would stop matching.
 * @param array  $args The declaration.
 */
function register_module( $id, array $args ) {
	$modules        = &registry();
	$modules[ $id ] = wp_parse_args(
		$args,
		array(
			'label'       => $id,
			'description' => '',
			'boot'        => null,
			'settings'    => null,
			'notices'     => null,
		)
	);
}

/**
 * A module's label or description, translated.
 *
 * Declarations hold these as callables, because modules are declared as the
 * plugin file loads and translating then trips core's "loaded too early"
 * notice on every request.
 *
 * @param string $id  Module id.
 * @param string $key `label` or `description`.
 * @return string
 */
function module_text( $id, $key ) {
	$value = modules()[ $id ][ $key ] ?? '';
	return (string) ( is_callable( $value ) ? call_user_func( $value ) : $value );
}

/**
 * Every declared module.
 *
 * @return array<string, array>
 */
function modules() {
	return registry();
}

/**
 * Ids of the modules switched on, limited to modules that still exist.
 *
 * @return string[]
 */
function enabled_modules() {
	$stored = get_option( MODULES_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}
	return array_values( array_intersect( $stored, array_keys( modules() ) ) );
}

/**
 * Whether one module is switched on.
 *
 * @param string $id Module id.
 * @return bool
 */
function is_enabled( $id ) {
	return in_array( $id, enabled_modules(), true );
}

/**
 * Store the set of enabled modules.
 *
 * Unknown ids are dropped, so a forged request cannot plant an id that a later
 * version might give meaning to.
 *
 * @param string[] $ids Module ids.
 */
function set_enabled_modules( array $ids ) {
	$ids = array_values( array_intersect( array_map( 'sanitize_key', $ids ), array_keys( modules() ) ) );
	update_option( MODULES_OPTION, $ids, true );
}

/**
 * Run the boot callback of every enabled module.
 *
 * Priority 20 on plugins_loaded, so every other plugin has loaded (and added
 * its own hooks) before a module adds its own.
 */
function boot_modules() {
	foreach ( enabled_modules() as $id ) {
		$boot = modules()[ $id ]['boot'];
		if ( is_callable( $boot ) ) {
			call_user_func( $boot );
		}
	}
}

/**
 * Whether this request is one where front-end head output belongs.
 *
 * Not the dashboard, AJAX, cron, feeds, REST or XML-RPC, so the SEO,
 * analytics and schema modules agree about which requests get tags at all.
 *
 * @return bool
 */
function is_front_end_request() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
		return false;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return ! str_contains( $uri, 'wp-json' );
}
