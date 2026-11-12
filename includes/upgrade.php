<?php
/**
 * Upgrading stored data between versions.
 *
 * Runs on `plugins_loaded` (priority 5, before any module boots) whenever
 * the stored `tgdsk_version` is behind VERSION, so it covers every way a new
 * version arrives: the updater, a manual upload (which runs no activation
 * hook) and a copy dropped in place. Each step is gated on the version it
 * belongs to and safe to run twice. With nothing to do it costs one
 * autoloaded option read.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit;

defined( 'ABSPATH' ) || exit;

const VERSION_OPTION = 'tgdsk_version';

add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_upgrade', 5 );

/**
 * Run the steps between the stored version and this one.
 */
function maybe_upgrade() {
	$stored = (string) get_option( VERSION_OPTION, '0' );
	if ( version_compare( $stored, VERSION, '>=' ) ) {
		return;
	}
	if ( version_compare( $stored, '0.5.0', '<' ) ) {
		upgrade_to_0_5_0();
	}
	update_option( VERSION_OPTION, VERSION, true );
}

/**
 * 0.5.0: the importers and what they kept are gone, the connector is TGD
 * Connect, and redirects can replace a published page.
 *
 * - Redirects: the table gains `replaces_published` (all existing rows 0,
 *   so each behaves as before), and stored origins that named where they
 *   came from become neutral: an importer's `<source>:<id>` becomes
 *   `import`, and the connector route's old name becomes `connect`. Nothing
 *   else in a row changes.
 * - The importers' own bookkeeping goes: their state and report options,
 *   their transients, and the per-post, per-term and per-user state meta.
 *   The values they imported (SEO meta, schema settings, redirects) are the
 *   kit's own data and stay exactly as they are.
 * - IndexNow: a key whose source named the plugin it was taken from reads
 *   `import`. The key itself is unchanged.
 * - Analytics: the list of events the old import couldn't carry goes.
 */
function upgrade_to_0_5_0() {
	global $wpdb;

	$versions = get_option( DB_OPTION, array() );
	if ( is_array( $versions ) && ! empty( $versions['redirects'] ) ) {
		Redirects\ensure_table();
		$redirects = table( 'redirects' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the kit's own table.
		// The importers stored `<source>:<id>`; no other origin has a colon.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET origin = 'import' WHERE origin LIKE %s", $redirects, '%:%' ) );
		// The only other origin before 0.5.0 was the connector route's own name.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET origin = 'connect' WHERE origin NOT IN ( '', 'import', 'connect', 'auto' )", $redirects ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	foreach ( array( 'tgdsk_seo_imported', 'tgdsk_seo_import_report', 'tgdsk_schema_imported' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'tgdsk_seo_dry_run', 'tgdsk_schema_import_report', 'tgdsk_redirects_import_report' ) as $transient ) {
		delete_transient( $transient );
	}
	delete_metadata( 'post', 0, '_tgdsk_seo_imported', '', true );
	delete_metadata( 'post', 0, '_tgdsk_schema_imported', '', true );
	delete_metadata( 'term', 0, '_tgdsk_seo_imported', '', true );
	delete_metadata( 'user', 0, '_tgdsk_seo_imported', '', true );

	$indexnow = get_option( 'tgdsk_indexnow' );
	if ( is_array( $indexnow ) && isset( $indexnow['source'] ) && ! in_array( $indexnow['source'], array( '', 'generated', 'import' ), true ) ) {
		$indexnow['source'] = 'import';
		update_option( 'tgdsk_indexnow', $indexnow, false );
	}

	$analytics = get_option( 'tgdsk_analytics' );
	if ( is_array( $analytics ) && array_key_exists( 'gtm_not_imported', $analytics ) ) {
		unset( $analytics['gtm_not_imported'] );
		update_option( 'tgdsk_analytics', $analytics, true );
	}
}
