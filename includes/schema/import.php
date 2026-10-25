<?php
/**
 * Imports into the schema module: a hand-written site schema plugin's
 * per-page fields, and SmartCrawl's schema settings.
 *
 * Both copy and never delete, and both can run any number of times, by the
 * same rule as the SEO import: a value is only overwritten while it still
 * equals what the last import wrote, so an edit made here survives a re-run.
 *
 * **From a cb-schema-style plugin**, per page: `cb_schema_type`, `cb_brand` and
 * `cb_schema_extra`. Its business details are constants in its own file, not
 * data, so they are typed into the settings screen once rather than parsed.
 *
 * **From SmartCrawl**, read from a production SmartCrawl database rather than
 * assumed: `wds_social_options` holds the organisation name, logo and social
 * profile URLs; `wds_schema_options` holds the site logo, organisation
 * description and phone, the About and Contact page IDs, and whether archives
 * and search get schema. SmartCrawl's organisation *type* is not imported:
 * it offers Organization subtypes such as Corporation, and a local shop
 * described as a Corporation loses everything LocalBusiness carries.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Schema;

use function TGD_Site_Kit\SEO\field_action;
use function TGD_Site_Kit\SEO\is_unset;

defined( 'ABSPATH' ) || exit;

const IMPORT_STATE_META   = '_tgdsk_schema_imported';
const IMPORT_STATE_OPTION = 'tgdsk_schema_imported';

/**
 * Per-page keys: source meta key => this module's meta key.
 *
 * @return array<string, string>
 */
function page_key_map() {
	return array(
		'cb_schema_type'  => TYPE_META,
		'cb_brand'        => BRAND_META,
		'cb_schema_extra' => EXTRA_META,
	);
}

/**
 * Copy per-page values from cb-schema's custom fields.
 *
 * @param bool $dry_run Count without writing.
 * @return array Counts: seen, written, deleted, same, local; plus local[].
 */
function import_page_fields( $dry_run = false ) {
	global $wpdb;
	$report       = array(
		'seen'    => 0,
		'written' => 0,
		'deleted' => 0,
		'same'    => 0,
		'local'   => 0,
		'edits'   => array(),
	);
	$keys         = array_keys( page_key_map() );
	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-off read; placeholders built from a fixed list.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) ORDER BY post_id", $keys ) );

	$map = array(
		'write'  => 'written',
		'delete' => 'deleted',
		'same'   => 'same',
		'local'  => 'local',
	);
	foreach ( array_map( 'intval', $ids ) as $post_id ) {
		if ( 'revision' === get_post_type( $post_id ) ) {
			continue;
		}
		++$report['seen'];
		$state = get_post_meta( $post_id, IMPORT_STATE_META, true );
		$state = is_array( $state ) ? $state : array();
		foreach ( page_key_map() as $from => $to ) {
			$source  = get_post_meta( $post_id, $from, true );
			$current = get_post_meta( $post_id, $to, true );
			$action  = field_action( $current, $source, $state[ $to ] ?? null );
			++$report[ $map[ $action ] ];
			if ( 'local' === $action ) {
				$report['edits'][] = "post {$post_id}: {$to}";
			}
			if ( $dry_run ) {
				continue;
			}
			if ( 'write' === $action ) {
				update_post_meta( $post_id, $to, wp_slash( $source ) );
			} elseif ( 'delete' === $action ) {
				delete_post_meta( $post_id, $to );
			}
			if ( is_unset( $source ) ) {
				unset( $state[ $to ] );
			} else {
				$state[ $to ] = $source;
			}
		}
		if ( ! $dry_run ) {
			if ( $state ) {
				update_post_meta( $post_id, IMPORT_STATE_META, $state );
			} else {
				delete_post_meta( $post_id, IMPORT_STATE_META );
			}
		}
	}
	return $report;
}

/**
 * SmartCrawl's schema settings, mapped into this module's sections. Only keys
 * SmartCrawl actually has a value for are returned.
 *
 * @return array<string, array<string, mixed>>
 */
function smartcrawl_schema_settings() {
	$social = (array) get_option( 'wds_social_options', array() );
	$schema = (array) get_option( 'wds_schema_options', array() );
	$out    = array(
		'business' => array(),
		'pages'    => array(),
	);

	$name = trim( (string) ( $social['organization_name'] ?? '' ) );
	if ( '' === $name ) {
		$name = trim( (string) ( $social['sitename'] ?? '' ) );
	}
	if ( '' !== $name ) {
		$out['business']['name'] = $name;
	}
	$logo = $schema['schema_website_logo'] ?? ( $social['organization_logo'] ?? '' );
	if ( ! empty( $logo ) ) {
		$out['business']['logo'] = is_numeric( $logo ) ? (int) $logo : esc_url_raw( (string) $logo );
	}
	foreach ( array(
		'organization_description'  => 'description',
		'organization_phone_number' => 'telephone',
	) as $from => $to ) {
		if ( '' !== trim( (string) ( $schema[ $from ] ?? '' ) ) ) {
			$out['business'][ $to ] = trim( (string) $schema[ $from ] );
		}
	}

	$same_as = array();
	foreach ( array( 'facebook_url', 'instagram_url', 'linkedin_url', 'pinterest_url', 'youtube_url' ) as $key ) {
		if ( ! empty( $social[ $key ] ) ) {
			$same_as[] = esc_url_raw( (string) $social[ $key ] );
		}
	}
	if ( ! empty( $social['twitter_username'] ) ) {
		$same_as[] = 'https://x.com/' . rawurlencode( ltrim( (string) $social['twitter_username'], '@' ) );
	}
	if ( $same_as ) {
		$out['business']['same_as'] = $same_as;
	}

	$types = array();
	foreach ( array(
		'schema_about_page'   => 'AboutPage',
		'schema_contact_page' => 'ContactPage',
	) as $key => $type ) {
		$slug = ! empty( $schema[ $key ] ) ? (string) get_post_field( 'post_name', (int) $schema[ $key ] ) : '';
		if ( '' !== $slug ) {
			$types[ $slug ] = $type;
		}
	}
	if ( $types ) {
		$out['pages']['types_by_slug'] = $types;
	}
	if ( ! empty( $schema['schema_enable_taxonomy_archives'] ) || ! empty( $schema['schema_enable_search'] ) ) {
		$out['pages']['archive_pages'] = true;
	}
	return array_filter( $out );
}

/**
 * Copy SmartCrawl's schema settings, key by key.
 *
 * @param bool $dry_run Count without writing.
 * @return array Counts: written, same, local; plus edits[] and the
 *               organisation type found, which is reported, not imported.
 */
function import_smartcrawl_schema( $dry_run = false ) {
	$current  = settings();
	$defaults = default_settings();
	$state    = get_option( IMPORT_STATE_OPTION, array() );
	$state    = is_array( $state ) ? $state : array();
	$report   = array(
		'written'      => 0,
		'same'         => 0,
		'local'        => 0,
		'edits'        => array(),
		'not_imported' => array(),
	);
	$changed  = false;

	$type = (string) ( get_option( 'wds_schema_options', array() )['organization_type'] ?? '' );
	if ( '' !== $type ) {
		$report['not_imported'][] = "organization_type {$type}";
	}

	foreach ( smartcrawl_schema_settings() as $section => $values ) {
		foreach ( $values as $key => $source ) {
			$ours     = $current[ $section ][ $key ] ?? null;
			$previous = $state[ $section ][ $key ] ?? null;
			$default  = $defaults[ $section ][ $key ] ?? null;
			if ( $ours === $source ) {
				++$report['same'];
			} elseif ( ( null !== $previous && $ours === $previous ) || ( null === $previous && $ours === $default ) ) {
				++$report['written'];
				$current[ $section ][ $key ] = $source;
				$changed                     = true;
			} else {
				++$report['local'];
				$report['edits'][] = "setting {$section}.{$key}";
			}
			$state[ $section ][ $key ] = $source;
		}
	}
	if ( ! $dry_run ) {
		if ( $changed ) {
			save_settings( $current );
		}
		update_option( IMPORT_STATE_OPTION, $state, false );
	}
	return $report;
}
