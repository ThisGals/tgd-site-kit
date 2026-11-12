<?php
/**
 * Uninstall: delete this plugin's options, and nothing else.
 *
 * The 404 log's table goes too: it is diagnostic data. The redirects table
 * stays, like meta, because a site's redirects are part of its content; a
 * reinstall picks them up again.
 *
 * The importers' per-object state meta (gone since 0.5.0) is removed if an
 * upgrade never did. Post and term meta are otherwise deliberately left
 * behind. The SEO titles,
 * descriptions and social settings stored there are the client's site
 * content, not plugin configuration, and deleting a plugin is not a decision
 * to delete a site's SEO. Reinstalling picks them straight back up.
 *
 * @package TGD_Site_Kit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The security headers' block in .htaccess (deactivation already took it out; this is for a block it couldn't). Only the block and the line break before it.
if ( ! is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tgdsk_htaccess = get_home_path() . '.htaccess';
	if ( file_exists( $tgdsk_htaccess ) && wp_is_writable( $tgdsk_htaccess ) ) {
		$tgdsk_text = (string) file_get_contents( $tgdsk_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		$tgdsk_new  = (string) preg_replace( '/(?:\r?\n)?^# BEGIN TGD Site Kit security headers\r?$.*?^# END TGD Site Kit security headers(?=\r?$)/ms', '', $tgdsk_text, 1 );
		if ( $tgdsk_new !== $tgdsk_text ) {
			file_put_contents( $tgdsk_htaccess, $tgdsk_new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one local file.
		}
	}
}

// The import options are gone since 0.5.0 but may still be on a site that never ran the upgrade.
foreach ( array( 'tgdsk_modules', 'tgdsk_version', 'tgdsk_analytics', 'tgdsk_analytics_tag_check', 'tgdsk_seo', 'tgdsk_seo_imported', 'tgdsk_seo_import_report', 'tgdsk_schema', 'tgdsk_schema_imported', 'tgdsk_redirects', 'tgdsk_redirects_skipped', 'tgdsk_not_found', 'tgdsk_breadcrumbs', 'tgdsk_db', 'tgdsk_indexnow', 'tgdsk_indexnow_log', 'tgdsk_security', 'tgdsk_security_server', 'tgdsk_security_htaccess_backup' ) as $tgdsk_option ) {
	delete_option( $tgdsk_option );
}
foreach ( array( 'tgdsk_analytics_conflict', 'tgdsk_analytics_gtm_conflict', 'tgdsk_seo_dry_run', 'tgdsk_schema_conflict', 'tgdsk_schema_import_report', 'tgdsk_redirects_import_report', 'tgdsk_not_found_pruned' ) as $tgdsk_transient ) {
	delete_transient( $tgdsk_transient );
}

global $wpdb;
foreach ( array( 'post', 'term', 'user' ) as $tgdsk_meta_type ) {
	delete_metadata( $tgdsk_meta_type, 0, '_tgdsk_seo_imported', '', true );
}
delete_metadata( 'post', 0, '_tgdsk_schema_imported', '', true );

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tgdsk_not_found' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the kit's own table.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_tgdsk_el_text_' ) . '%', $wpdb->esc_like( '_transient_timeout_tgdsk_el_text_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cached Elementor text, one transient per page.
