<?php
/**
 * Copying SmartCrawl's SEO data into this module.
 *
 * **Where SmartCrawl keeps it**, read from a production SmartCrawl database on
 * 2026-10-25 and from SmartCrawl 3.17.1's code, not assumed:
 *
 * - Per post: post meta `_wds_title`, `_wds_metadesc`, `_wds_canonical`,
 *   `_wds_meta-robots-noindex`, `-nofollow`, `-index`, `-follow`, `-adv`, and
 *   the arrays `_wds_opengraph` and `_wds_twitter` (title, description, images,
 *   disabled).
 * - Per term: **not term meta.** One option, `wds_taxonomy_meta`, keyed
 *   taxonomy → term ID → `wds_title`, `wds_desc`, `wds_canonical`,
 *   `wds_noindex`, `wds_nofollow`, `wds_override_noindex`,
 *   `wds_override_nofollow`, `opengraph`, `twitter`.
 * - Site defaults: `wds_onpage_options` (templates, social switches, robots
 *   per location, separator, lengths), `wds_social_options` (Open Graph and
 *   Twitter switches, card type, handles), `wds_settings_options` (length
 *   enforcement, generator, redundant canonical), `wds_sitemap_options`
 *   (verification tags, sitemap exclusions), `wds-sitemap-ignore_post_ids`,
 *   and `wds-advanced` (robots.txt).
 *
 * **The import copies and never moves.** Nothing of SmartCrawl's is changed or
 * deleted, so SmartCrawl keeps working exactly as before until it is switched
 * off, and switching it back on restores the old state.
 *
 * **It can run any number of times.** Each copied value is remembered, and on
 * the next run a value is only overwritten if it still equals what the last
 * run wrote. One somebody has since edited here is kept and counted as a local
 * edit. So a second run with nothing changed in SmartCrawl changes nothing, and
 * a run after editing in SmartCrawl carries the edit across.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

const IMPORT_STATE_META   = '_tgdsk_seo_imported';
const IMPORT_STATE_OPTION = 'tgdsk_seo_imported';
const IMPORT_REPORT       = 'tgdsk_seo_import_report';

/**
 * SmartCrawl post meta key (after `_wds_`) => our key (after `_tgdsk_`).
 *
 * @return array<string, string>
 */
function post_key_map() {
	return array(
		'title'                => 'title',
		'metadesc'             => 'description',
		'canonical'            => 'canonical',
		'meta-robots-noindex'  => 'noindex',
		'meta-robots-nofollow' => 'nofollow',
		'meta-robots-index'    => 'index',
		'meta-robots-follow'   => 'follow',
		'meta-robots-adv'      => 'robots_adv',
		'opengraph'            => 'opengraph',
		'twitter'              => 'twitter',
	);
}

/**
 * SmartCrawl term meta key => our key.
 *
 * @return array<string, string>
 */
function term_key_map() {
	return array(
		'wds_title'             => 'title',
		'wds_desc'              => 'description',
		'wds_canonical'         => 'canonical',
		'wds_noindex'           => 'noindex',
		'wds_nofollow'          => 'nofollow',
		'wds_override_noindex'  => 'override_noindex',
		'wds_override_nofollow' => 'override_nofollow',
		'opengraph'             => 'opengraph',
		'twitter'               => 'twitter',
	);
}

/**
 * Whether a value counts as unset. SmartCrawl treats every empty() value so.
 *
 * @param mixed $value Value.
 * @return bool
 */
function is_unset( $value ) {
	return empty( $value );
}

/**
 * Decide what to do with one field.
 *
 * @param mixed $current  Our current value.
 * @param mixed $source   SmartCrawl's value.
 * @param mixed $previous What the last import wrote, or null if never.
 * @return string One of `write`, `delete`, `same`, `local`.
 */
function field_action( $current, $source, $previous ) {
	$ours_untouched = is_unset( $current ) || ( null !== $previous && $current === $previous );
	if ( is_unset( $source ) ) {
		return ! is_unset( $current ) && null !== $previous && $current === $previous ? 'delete' : 'same';
	}
	if ( $current === $source ) {
		return 'same';
	}
	return $ours_untouched ? 'write' : 'local';
}

/**
 * Run the import.
 *
 * @param bool $dry_run Count what would change without writing anything.
 * @return array Report: counts per kind, and the list of kept local edits.
 */
function import_smartcrawl( $dry_run = false ) {
	$report = array(
		'dry_run'  => $dry_run,
		'time'     => time(),
		'posts'    => array(
			'seen'    => 0,
			'written' => 0,
			'deleted' => 0,
			'same'    => 0,
			'local'   => 0,
		),
		'terms'    => array(
			'seen'    => 0,
			'written' => 0,
			'deleted' => 0,
			'same'    => 0,
			'local'   => 0,
		),
		'authors'  => array(
			'seen'    => 0,
			'written' => 0,
			'deleted' => 0,
			'same'    => 0,
			'local'   => 0,
		),
		'settings' => array(
			'written' => 0,
			'same'    => 0,
			'local'   => 0,
		),
		'local'    => array(),
	);

	import_posts( $report, $dry_run );
	import_terms( $report, $dry_run );
	import_authors( $report, $dry_run );
	import_settings( $report, $dry_run );

	if ( ! $dry_run ) {
		update_option( IMPORT_REPORT, $report, false );
	}
	return $report;
}

/**
 * Copy author archive titles and descriptions, kept by SmartCrawl in user
 * meta as `wds_title` and `wds_metadesc` (no underscore).
 *
 * @param array $report  Report, by reference.
 * @param bool  $dry_run Write nothing.
 */
function import_authors( array &$report, $dry_run ) {
	$map   = array(
		'wds_title'    => 'title',
		'wds_metadesc' => 'description',
	);
	$users = get_users(
		array(
			'fields'       => 'ID',
			'meta_key'     => array_keys( $map ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off import.
			'meta_compare' => 'EXISTS',
		)
	);
	foreach ( array_map( 'intval', $users ) as $user_id ) {
		++$report['authors']['seen'];
		$state = get_user_meta( $user_id, IMPORT_STATE_META, true );
		$state = is_array( $state ) ? $state : array();
		foreach ( $map as $from => $to ) {
			$source  = get_user_meta( $user_id, $from, true );
			$current = get_user_meta( $user_id, USER_META_PREFIX . $to, true );
			$action  = field_action( $current, $source, $state[ $to ] ?? null );
			tally( $report['authors'], $action );
			if ( 'local' === $action ) {
				$report['local'][] = sprintf( 'user %d: %s', $user_id, $to );
			}
			if ( $dry_run ) {
				continue;
			}
			if ( 'write' === $action ) {
				update_user_meta( $user_id, USER_META_PREFIX . $to, wp_slash( $source ) );
			} elseif ( 'delete' === $action ) {
				delete_user_meta( $user_id, USER_META_PREFIX . $to );
			}
			if ( is_unset( $source ) ) {
				unset( $state[ $to ] );
			} else {
				$state[ $to ] = $source;
			}
		}
		if ( ! $dry_run ) {
			if ( $state ) {
				update_user_meta( $user_id, IMPORT_STATE_META, $state );
			} else {
				delete_user_meta( $user_id, IMPORT_STATE_META );
			}
		}
	}
}

/**
 * Tally one field action into a report section.
 *
 * @param array  $section Report section, by reference.
 * @param string $action  Field action.
 */
function tally( array &$section, $action ) {
	$map = array(
		'write'  => 'written',
		'delete' => 'deleted',
		'same'   => 'same',
		'local'  => 'local',
	);
	++$section[ $map[ $action ] ];
}

/**
 * Copy per-post values, for every post carrying any SmartCrawl key.
 *
 * @param array $report  Report, by reference.
 * @param bool  $dry_run Write nothing.
 */
function import_posts( array &$report, $dry_run ) {
	global $wpdb;
	$keys         = array_map( fn( $k ) => '_wds_' . $k, array_keys( post_key_map() ) );
	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-off read across all posts; placeholders built from a fixed list.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders) ORDER BY post_id", $keys ) );

	foreach ( array_map( 'intval', $ids ) as $post_id ) {
		if ( 'revision' === get_post_type( $post_id ) ) {
			continue;
		}
		++$report['posts']['seen'];
		$state = get_post_meta( $post_id, IMPORT_STATE_META, true );
		$state = is_array( $state ) ? $state : array();
		foreach ( post_key_map() as $from => $to ) {
			$source  = get_post_meta( $post_id, '_wds_' . $from, true );
			$current = get_post_meta( $post_id, POST_META_PREFIX . $to, true );
			$action  = field_action( $current, $source, $state[ $to ] ?? null );
			tally( $report['posts'], $action );
			if ( 'local' === $action ) {
				$report['local'][] = sprintf( 'post %d: %s', $post_id, $to );
			}
			if ( $dry_run ) {
				continue;
			}
			if ( 'write' === $action ) {
				update_post_meta( $post_id, POST_META_PREFIX . $to, wp_slash( $source ) );
			} elseif ( 'delete' === $action ) {
				delete_post_meta( $post_id, POST_META_PREFIX . $to );
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
}

/**
 * Copy per-term values out of SmartCrawl's single taxonomy option.
 *
 * @param array $report  Report, by reference.
 * @param bool  $dry_run Write nothing.
 */
function import_terms( array &$report, $dry_run ) {
	$all = get_option( 'wds_taxonomy_meta' );
	if ( ! is_array( $all ) ) {
		return;
	}
	foreach ( $all as $taxonomy => $terms ) {
		if ( ! is_array( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term_id => $values ) {
			$term = get_term( (int) $term_id, (string) $taxonomy );
			if ( ! $term instanceof \WP_Term || ! is_array( $values ) ) {
				continue;
			}
			++$report['terms']['seen'];
			$state = get_term_meta( $term->term_id, IMPORT_STATE_META, true );
			$state = is_array( $state ) ? $state : array();
			foreach ( term_key_map() as $from => $to ) {
				$source  = $values[ $from ] ?? '';
				$current = get_term_meta( $term->term_id, TERM_META_PREFIX . $to, true );
				$action  = field_action( $current, $source, $state[ $to ] ?? null );
				tally( $report['terms'], $action );
				if ( 'local' === $action ) {
					$report['local'][] = sprintf( 'term %d (%s): %s', $term->term_id, $taxonomy, $to );
				}
				if ( $dry_run ) {
					continue;
				}
				if ( 'write' === $action ) {
					update_term_meta( $term->term_id, TERM_META_PREFIX . $to, wp_slash( $source ) );
				} elseif ( 'delete' === $action ) {
					delete_term_meta( $term->term_id, TERM_META_PREFIX . $to );
				}
				if ( is_unset( $source ) ) {
					unset( $state[ $to ] );
				} else {
					$state[ $to ] = $source;
				}
			}
			if ( ! $dry_run ) {
				if ( $state ) {
					update_term_meta( $term->term_id, IMPORT_STATE_META, $state );
				} else {
					delete_term_meta( $term->term_id, IMPORT_STATE_META );
				}
			}
		}
	}
}

/**
 * SmartCrawl's site settings, mapped into this module's sections.
 *
 * Only keys SmartCrawl actually has are returned, so a missing one leaves our
 * value (or default) alone rather than overwriting it with a guess.
 *
 * @return array<string, array<string, mixed>> Section => key => value.
 */
function smartcrawl_settings() {
	$onpage   = (array) get_option( 'wds_onpage_options', array() );
	$social   = (array) get_option( 'wds_social_options', array() );
	$general  = (array) get_option( 'wds_settings_options', array() );
	$sitemap  = (array) get_option( 'wds_sitemap_options', array() );
	$advanced = (array) get_option( 'wds-advanced', array() );

	$out = array(
		'onpage'       => $onpage,
		'social'       => array_intersect_key( $social, array_flip( array( 'og-enable', 'twitter-card-enable', 'twitter-card-type', 'twitter_username', 'fb-app-id' ) ) ),
		'general'      => array(),
		'verification' => array(),
		'sitemap'      => array(),
		'robots'       => array(),
	);

	foreach ( array(
		'metabox-lax_enforcement'              => 'enforce-lengths',
		'general-suppress-generator'           => 'suppress-generator',
		'general-suppress-redundant_canonical' => 'suppress-redundant-canonical',
	) as $from => $to ) {
		if ( array_key_exists( $from, $general ) ) {
			$out['general'][ $to ] = (bool) $general[ $from ];
		}
	}

	foreach ( array(
		'verification-google-meta' => 'google',
		'verification-bing-meta'   => 'bing',
		'verification-pages'       => 'pages',
	) as $from => $to ) {
		if ( array_key_exists( $from, $sitemap ) ) {
			$out['verification'][ $to ] = (string) $sitemap[ $from ];
		}
	}
	if ( isset( $sitemap['additional-metas'] ) && is_array( $sitemap['additional-metas'] ) ) {
		$out['verification']['additional'] = array_values( $sitemap['additional-metas'] );
	}

	$types = array();
	$taxes = array();
	foreach ( $sitemap as $key => $value ) {
		if ( $value && preg_match( '/^post_types-(.+)-not_in_sitemap$/', $key, $m ) ) {
			$types[] = $m[1];
		} elseif ( $value && preg_match( '/^taxonomies-(.+)-not_in_sitemap$/', $key, $m ) ) {
			$taxes[] = $m[1];
		}
	}
	$out['sitemap']['exclude_post_types'] = $types;
	$out['sitemap']['exclude_taxonomies'] = $taxes;
	$ignored                              = get_option( 'wds-sitemap-ignore_post_ids' );
	$out['sitemap']['exclude_post_ids']   = is_array( $ignored ) ? array_values( array_map( 'intval', $ignored ) ) : array();
	$out['sitemap']['include_users']      = ! empty( $onpage['enable-author-archive'] );

	// SmartCrawl serves robots.txt only when its robots module is on and there
	// is no physical file; its text defaults to allowing everything.
	$robots = isset( $advanced['robots'] ) && is_array( $advanced['robots'] ) ? $advanced['robots'] : array();
	if ( ! empty( $robots['active'] ) && ! file_exists( ABSPATH . 'robots.txt' ) ) {
		$custom                        = isset( $robots['custom_directives'] ) ? trim( (string) $robots['custom_directives'] ) : '';
		$out['robots']['custom']       = '' !== $custom ? $custom : "User-agent: *\nDisallow:";
		$out['robots']['sitemap_line'] = array_key_exists( 'sitemap_directive_disabled', $robots ) ? ! $robots['sitemap_directive_disabled'] : false;
	}

	return $out;
}

/**
 * Copy site settings, key by key, by the same rule as post values.
 *
 * Settings default to real values rather than empty, so "untouched" here means
 * equal to what the last import wrote, or never imported and still at default.
 *
 * @param array $report  Report, by reference.
 * @param bool  $dry_run Write nothing.
 */
function import_settings( array &$report, $dry_run ) {
	$current  = settings();
	$defaults = default_settings();
	$state    = get_option( IMPORT_STATE_OPTION, array() );
	$state    = is_array( $state ) ? $state : array();
	$changed  = false;

	foreach ( smartcrawl_settings() as $section => $values ) {
		foreach ( $values as $key => $source ) {
			$ours     = $current[ $section ][ $key ] ?? null;
			$previous = $state[ $section ][ $key ] ?? null;
			$default  = $defaults[ $section ][ $key ] ?? null;
			if ( $ours === $source ) {
				$action = 'same';
			} elseif ( ( null !== $previous && $ours === $previous ) || ( null === $previous && $ours === $default ) ) {
				$action = 'write';
			} else {
				$action = 'local';
			}
			++$report['settings'][ 'write' === $action ? 'written' : $action ];
			if ( 'local' === $action ) {
				$report['local'][] = sprintf( 'setting %s.%s', $section, $key );
			}
			if ( 'write' === $action ) {
				$current[ $section ][ $key ] = $source;
				$changed                     = true;
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
}
