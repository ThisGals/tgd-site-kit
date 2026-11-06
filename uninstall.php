<?php
/**
 * Uninstall: delete this plugin's options, and nothing else.
 *
 * The 404 log's table goes too: it is diagnostic data. The redirects table
 * stays, like meta, because a site's redirects are part of its content; a
 * reinstall picks them up again.
 *
 * Post and term meta are deliberately left behind. The SEO titles,
 * descriptions and social settings stored there are the client's site
 * content, not plugin configuration, and deleting a plugin is not a decision
 * to delete a site's SEO. Reinstalling picks them straight back up.
 *
 * @package TGD_Site_Kit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'tgdsk_modules', 'tgdsk_analytics', 'tgdsk_seo', 'tgdsk_seo_imported', 'tgdsk_seo_import_report', 'tgdsk_schema', 'tgdsk_schema_imported', 'tgdsk_redirects', 'tgdsk_not_found', 'tgdsk_breadcrumbs', 'tgdsk_db' ) as $tgdsk_option ) {
	delete_option( $tgdsk_option );
}
foreach ( array( 'tgdsk_analytics_conflict', 'tgdsk_seo_dry_run', 'tgdsk_schema_conflict', 'tgdsk_schema_import_report', 'tgdsk_redirects_import_report', 'tgdsk_not_found_pruned' ) as $tgdsk_transient ) {
	delete_transient( $tgdsk_transient );
}

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tgdsk_not_found' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the kit's own table.
