<?php
/**
 * Redirect imports: from the Redirection plugin and from SmartCrawl.
 *
 * Copies, never deletes, and can run again. Each imported row remembers where
 * it came from (`origin`, such as `redirection:12`) and a hash of what the
 * import wrote (`import_hash`). On a later run:
 *
 * - a row that still equals what was imported is updated to the source's
 *   current state (or left alone when that is the same);
 * - a row edited here since is kept, and listed;
 * - a source redirect that has gone is left in place here, and listed.
 *
 * Anything that can't be carried over exactly is listed with the reason:
 * conditional matches (login state, role, referrer, agent, cookie, IP...),
 * actions other than a redirect or a 410, SmartCrawl's regex redirects (it
 * matches them against the whole URL, host included, where the kit matches
 * the path), geolocation rules, its per-post 301 field and its attachment
 * redirects. And anything validate() refuses: a source that is now published
 * content, a WordPress path, a duplicate.
 *
 * Both plugins' data is read with SQL, because both may be inactive.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use function TGD_Site_Kit\table;

defined( 'ABSPATH' ) || exit;

// Reading other plugins' tables, which have no API while they are inactive.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * A fresh report.
 *
 * @param string $source `redirection` or `smartcrawl`.
 * @param bool   $dry_run Dry run.
 * @return array
 */
function new_report( $source, $dry_run ) {
	return array(
		'source'   => $source,
		'dry_run'  => $dry_run,
		'time'     => time(),
		'seen'     => 0,
		'switched' => 0,
		'written'  => 0,
		'updated'  => 0,
		'same'     => 0,
		'local'    => array(),
		'skipped'  => array(),
		'gone'     => array(),
		'settings' => array(),
	);
}

/**
 * Whether a table exists.
 *
 * @param string $name Full table name.
 * @return bool
 */
function table_exists( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) === $name;
}

/**
 * Carry one source redirect over.
 *
 * @param array  $report  Report, by reference.
 * @param string $origin  Origin key.
 * @param string $label   How to name it in the report.
 * @param array  $input   Fields for validate().
 * @param array  $extra   Columns written on first import only (hits).
 * @param bool   $dry_run Write nothing.
 * @param bool   $pass    Switch an exact redirect whose source has no query
 *                        to `pass` (counted in the report's `switched`).
 */
function carry( array &$report, $origin, $label, array $input, array $extra, $dry_run, $pass = true ) {
	global $wpdb;
	if ( $pass && empty( $input['is_regex'] ) && 'exact' === ( $input['query_mode'] ?? 'exact' ) && ! str_contains( (string) $input['source'], '?' ) ) {
		$input['query_mode'] = 'pass';
		++$report['switched'];
	}
	$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE origin = %s', table( 'redirects' ), $origin ), ARRAY_A );
	$row      = validate( $input, $existing ? (int) $existing['id'] : 0 );
	if ( is_wp_error( $row ) ) {
		$report['skipped'][] = $label . ': ' . $row->get_error_message();
		return;
	}
	$hash = row_hash( $row );
	if ( ! $existing ) {
		++$report['written'];
		if ( ! $dry_run ) {
			insert(
				$row,
				array_merge(
					$extra,
					array(
						'origin'      => $origin,
						'import_hash' => $hash,
					)
				)
			);
		}
		return;
	}
	if ( row_hash( $existing ) !== $existing['import_hash'] ) {
		/* translators: %d: redirect ID */
		$report['local'][] = $label . ': ' . sprintf( __( 'edited here since the last import (redirect %d), kept', 'tgd-site-kit' ), (int) $existing['id'] );
		return;
	}
	if ( $existing['import_hash'] === $hash ) {
		++$report['same'];
		return;
	}
	++$report['updated'];
	if ( ! $dry_run ) {
		update( (int) $existing['id'], $row, array( 'import_hash' => $hash ) );
	}
}

/**
 * List rows imported earlier whose source redirect has gone.
 *
 * @param array    $report  Report, by reference.
 * @param string   $prefix  Origin prefix, such as `redirection:`.
 * @param string[] $origins Origins seen this run.
 */
function note_gone( array &$report, $prefix, array $origins ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, origin, source FROM %i WHERE origin LIKE %s', table( 'redirects' ), $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		if ( ! in_array( $row['origin'], $origins, true ) ) {
			/* translators: 1: redirect ID, 2: source */
			$report['gone'][] = sprintf( __( 'redirect %1$d (%2$s) is no longer in the source plugin; kept here', 'tgd-site-kit' ), (int) $row['id'], $row['source'] );
		}
	}
}

/**
 * Import from the Redirection plugin.
 *
 * Its `url` match with a URL action (301, 302, 307, 308) or an error action
 * of 410 carries over, regex included, with the item's own query, case and
 * trailing-slash flags or, where it has none, the site's defaults. A disabled
 * item, or one in a disabled group, comes over disabled. Hit counts and the
 * last hit come over on the first import.
 *
 * With `$pass` (the default) a redirect whose source has no query and whose
 * query matching is `exact` comes over as `pass`, so a link with ?fbclid=
 * or ?utm_ still redirects and keeps its query; the report counts them.
 *
 * @param bool $dry_run Write nothing.
 * @param bool $pass    Switch plain exact redirects to pass.
 * @return array Report.
 */
function import_redirection( $dry_run = false, $pass = true ) {
	global $wpdb;
	ensure_table();
	$report = new_report( 'redirection', $dry_run );
	$items  = $wpdb->prefix . 'redirection_items';
	if ( ! table_exists( $items ) ) {
		$report['skipped'][] = __( 'Redirection has no table on this site, so there is nothing to import.', 'tgd-site-kit' );
		return $report;
	}
	$options  = get_option( 'redirection_options', array() );
	$options  = is_array( $options ) ? $options : array();
	$defaults = array(
		'flag_query'    => (string) ( $options['flag_query'] ?? 'exact' ),
		'flag_case'     => (bool) ( $options['flag_case'] ?? true ),
		'flag_trailing' => (bool) ( $options['flag_trailing'] ?? true ),
	);
	$groups   = $wpdb->prefix . 'redirection_groups';
	$rows     = table_exists( $groups )
		? $wpdb->get_results( $wpdb->prepare( 'SELECT i.*, g.status AS group_status, g.name AS group_name FROM %i i LEFT JOIN %i g ON g.id = i.group_id ORDER BY i.position ASC, i.id ASC', $items, $groups ), ARRAY_A )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY position ASC, id ASC', $items ), ARRAY_A );
	$origins  = array();

	foreach ( (array) $rows as $item ) {
		++$report['seen'];
		$origin    = 'redirection:' . (int) $item['id'];
		$origins[] = $origin;
		/* translators: 1: item ID, 2: source URL */
		$label = sprintf( __( 'Redirection item %1$d (%2$s)', 'tgd-site-kit' ), (int) $item['id'], $item['url'] );

		if ( 'url' !== $item['match_type'] ) {
			/* translators: %s: Redirection match type */
			$report['skipped'][] = $label . ': ' . sprintf( __( 'matches on “%s”, a condition the kit does not have', 'tgd-site-kit' ), $item['match_type'] );
			continue;
		}
		$code = (int) $item['action_code'];
		if ( 'url' === $item['action_type'] && in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			$target = (string) $item['action_data'];
		} elseif ( 'error' === $item['action_type'] && 410 === $code ) {
			$target = '';
		} else {
			/* translators: 1: action type, 2: status code */
			$report['skipped'][] = $label . ': ' . sprintf( __( 'its action (%1$s, %2$d) is not a redirect or a 410', 'tgd-site-kit' ), $item['action_type'], $code );
			continue;
		}

		$data  = json_decode( (string) $item['match_data'], true );
		$flags = is_array( $data['source'] ?? null ) ? array_merge( $defaults, $data['source'] ) : $defaults;
		$mode  = in_array( (string) $flags['flag_query'], QUERY_MODES, true ) ? (string) $flags['flag_query'] : 'exact';
		$input = array(
			'source'         => (string) $item['url'],
			'target'         => $target,
			'status'         => $code,
			'is_regex'       => (bool) (int) $item['regex'],
			'query_mode'     => $mode,
			'match_case'     => ! $flags['flag_case'],
			'match_trailing' => ! $flags['flag_trailing'],
			'enabled'        => 'enabled' === $item['status'] && ( ! isset( $item['group_status'] ) || 'enabled' === $item['group_status'] ),
			'note'           => (string) ( $item['title'] ?? '' ),
		);
		$extra = array( 'hits' => (int) $item['last_count'] );
		if ( ! empty( $item['last_access'] ) && ! str_starts_with( (string) $item['last_access'], '1970' ) && ! str_starts_with( (string) $item['last_access'], '0000' ) ) {
			$extra['last_hit'] = $item['last_access'];
		}
		carry( $report, $origin, $label, $input, $extra, $dry_run, $pass );
	}
	note_gone( $report, 'redirection:', $origins );

	if ( isset( $options['redirect_cache'] ) ) {
		$hours                             = (int) $options['redirect_cache'];
		$report['settings']['cache_hours'] = $hours < 0 ? 0 : $hours;
		if ( ! $dry_run ) {
			save_settings( array_merge( settings(), array( 'cache_hours' => $hours < 0 ? 0 : $hours ) ) );
		}
	}
	return $report;
}

/**
 * Import SmartCrawl's redirects.
 *
 * Plain redirects carry over: case-insensitive, trailing slash ignored and
 * the query matched exactly, which is how SmartCrawl compares them. A target
 * that is a post is written as that post's URL today.
 *
 * @param bool $dry_run Write nothing.
 * @param bool $pass    Switch plain exact redirects to pass (see import_redirection()).
 * @return array Report.
 */
function import_smartcrawl( $dry_run = false, $pass = true ) {
	global $wpdb;
	ensure_table();
	$report  = new_report( 'smartcrawl', $dry_run );
	$source  = $wpdb->prefix . 'smartcrawl_redirects';
	$origins = array();
	$rows    = table_exists( $source ) ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $source ), ARRAY_A ) : array();

	foreach ( $rows as $item ) {
		++$report['seen'];
		$origin    = 'smartcrawl:' . (int) $item['id'];
		$origins[] = $origin;
		/* translators: 1: item ID, 2: source URL */
		$label   = sprintf( __( 'SmartCrawl redirect %1$d (%2$s)', 'tgd-site-kit' ), (int) $item['id'], $item['source'] );
		$options = json_decode( (string) $item['options'], true );
		if ( 'regex' === $item['path'] || ( is_array( $options ) && in_array( 'regex', $options, true ) ) ) {
			$report['skipped'][] = $label . ': ' . __( 'a regex, which SmartCrawl matches against the whole URL with its host; add it here by hand as a path pattern', 'tgd-site-kit' );
			continue;
		}
		$type = (int) $item['type'];
		if ( ! in_array( $type, STATUSES, true ) ) {
			/* translators: %d: status code */
			$report['skipped'][] = $label . ': ' . sprintf( __( 'status %d is not one the kit sends', 'tgd-site-kit' ), $type );
			continue;
		}
		$destination = json_decode( (string) $item['destination'], true );
		$destination = null === $destination ? (string) $item['destination'] : $destination;
		if ( is_array( $destination ) && ! empty( $destination['id'] ) ) {
			$target = (string) get_permalink( (int) $destination['id'] );
			if ( '' === $target ) {
				$report['skipped'][] = $label . ': ' . __( 'its target post no longer exists', 'tgd-site-kit' );
				continue;
			}
			/* translators: %s: URL */
			$report['local'][] = $label . ': ' . sprintf( __( 'targets a post; written as its URL today, %s, which won’t follow a later change of address', 'tgd-site-kit' ), $target );
		} else {
			$target = is_string( $destination ) ? $destination : (string) ( $destination['url'] ?? '' );
		}
		$rules = json_decode( (string) $item['rules'], true );
		if ( ! empty( $rules ) ) {
			$report['local'][] = $label . ': ' . __( 'has geolocation rules, which were left behind; everyone gets the main target', 'tgd-site-kit' );
		}
		$input = array(
			'source'     => (string) $item['source'],
			'target'     => $target,
			'status'     => $type,
			'is_regex'   => false,
			'query_mode' => 'exact',
			'note'       => (string) $item['title'],
		);
		carry( $report, $origin, $label, $input, array(), $dry_run, $pass );
	}
	note_gone( $report, 'smartcrawl:', $origins );

	$per_post = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value <> %s', $wpdb->postmeta, '_wds_redirect', '' ) );
	if ( $per_post ) {
		/* translators: %d: number of posts */
		$report['skipped'][] = sprintf( _n( '%d post has a 301 in SmartCrawl’s per-post field. That redirects a published post, which the kit refuses: unpublish the post or change its address, then add the redirect here.', '%d posts have a 301 in SmartCrawl’s per-post field. That redirects a published post, which the kit refuses: unpublish the post or change its address, then add the redirect here.', $per_post, 'tgd-site-kit' ), $per_post );
	}
	$advanced = get_option( 'wds-advanced', array() );
	if ( is_array( $advanced ) && ! empty( $advanced['redirects']['attachments'] ) ) {
		$report['skipped'][] = __( 'SmartCrawl sends attachment pages to their files. The kit doesn’t; WordPress 6.4 and later do this themselves (Settings → Media, “attachment pages” off).', 'tgd-site-kit' );
	}
	return $report;
}
