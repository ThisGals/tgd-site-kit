<?php
/**
 * The kit's own database tables: created the first time a module that needs
 * one boots, never on activation.
 *
 * Activating the plugin writes nothing, so a table appears only once its
 * module is switched on. `tgdsk_db` remembers which table is at which schema
 * version, so the check on every request is one autoloaded option read.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit;

defined( 'ABSPATH' ) || exit;

const DB_OPTION = 'tgdsk_db';

/**
 * A kit table's full name.
 *
 * @param string $name Short name, such as `redirects`.
 * @return string
 */
function table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'tgdsk_' . $name;
}

/**
 * Create or upgrade a table when its stored version is behind.
 *
 * @param string $name    Short name.
 * @param string $columns Column and key definitions, dbDelta style (two
 *                        spaces after PRIMARY KEY).
 * @param int    $version Schema version of $columns.
 */
function ensure_table( $name, $columns, $version ) {
	$versions = get_option( DB_OPTION, array() );
	$versions = is_array( $versions ) ? $versions : array();
	if ( (int) ( $versions[ $name ] ?? 0 ) >= $version ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . table( $name ) . " (\n{$columns}\n) " . $wpdb->get_charset_collate() . ';' );
	$versions[ $name ] = $version;
	update_option( DB_OPTION, $versions, true );
}
