<?php
/**
 * Uninstall: delete this plugin's options, and nothing else.
 *
 * Post and term meta are deliberately left behind. The SEO titles,
 * descriptions and social settings stored there are the client's site
 * content, not plugin configuration, and deleting a plugin is not a decision
 * to delete a site's SEO. Reinstalling picks them straight back up.
 *
 * @package TGD_Site_Kit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'tgdsk_modules', 'tgdsk_analytics', 'tgdsk_seo', 'tgdsk_seo_imported', 'tgdsk_seo_import_report', 'tgdsk_schema', 'tgdsk_schema_imported' ) as $tgdsk_option ) {
	delete_option( $tgdsk_option );
}
foreach ( array( 'tgdsk_analytics_conflict', 'tgdsk_seo_dry_run', 'tgdsk_schema_conflict', 'tgdsk_schema_import_report' ) as $tgdsk_transient ) {
	delete_transient( $tgdsk_transient );
}
