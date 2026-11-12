<?php
/**
 * Redirects as CSV: export every redirect, import a file with a dry run first.
 *
 * **Export** is UTF-8 with a byte order mark (so a spreadsheet opens it as
 * UTF-8), CRLF line ends, one header row, one row per redirect in ID order
 * (the order regex redirects are tried in). Columns: source, target, code,
 * match (exact or regex), query (exact, ignore or pass), case_sensitive,
 * trailing_slash, replaces_published, enabled (yes or no), origin, note. A
 * cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return gets a
 * leading `'`, so a spreadsheet shows it as text instead of running it as a
 * formula; the import takes that quote off again.
 *
 * **Import** needs a header row; columns are matched by name, case and
 * spacing aside, and the common names other tools use for the same thing are
 * accepted (from / old URL for the source, to / destination for the target,
 * status / type for the code, and so on). A BOM, CRLF, quoted fields with
 * commas or line breaks, and a semicolon or tab delimiter are all fine. Every
 * row goes through validate(), as a redirect added by hand does, with the
 * rows accepted earlier in the same file counted as if stored, so the dry run
 * answers exactly as the import will: a loop, a published source without
 * `replaces_published`, a protected path are refused; a duplicate (of a
 * stored redirect or an earlier row) is skipped, the first one kept.
 *
 * Rows keep their `origin` when the file has a known one (so the kit's own
 * export round-trips exactly); otherwise they are `import`.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

defined( 'ABSPATH' ) || exit;

// php://temp streams, for fgetcsv() and fputcsv(); no file is opened.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

const CSV_COLUMNS  = array( 'source', 'target', 'code', 'match', 'query', 'case_sensitive', 'trailing_slash', 'replaces_published', 'enabled', 'origin', 'note' );
const CSV_MAX_ROWS = 5000;

/**
 * Header names accepted for each column, normalized (lowercase, runs of
 * spaces, `_` and `-` as one space).
 *
 * @return array<string, string[]>
 */
function csv_aliases() {
	return array(
		'source'             => array( 'source', 'from', 'old', 'old url', 'old path', 'old link', 'source url', 'source path', 'url', 'redirect from', 'original url', 'request url', 'path' ),
		'target'             => array( 'target', 'to', 'new', 'new url', 'new path', 'new link', 'target url', 'target path', 'destination', 'destination url', 'redirect to', 'redirect url', 'location' ),
		'code'               => array( 'code', 'status', 'status code', 'http code', 'http status', 'response code', 'redirect type', 'redirect code', 'type' ),
		'match'              => array( 'match', 'match type', 'matching', 'regex', 'is regex', 'regular expression' ),
		'query'              => array( 'query', 'query handling', 'query mode', 'query string', 'query parameters', 'parameters' ),
		'case_sensitive'     => array( 'case sensitive', 'match case', 'case' ),
		'trailing_slash'     => array( 'trailing slash', 'match trailing', 'match trailing slash' ),
		'replaces_published' => array( 'replaces published', 'replaces published page', 'replaces a published page' ),
		'enabled'            => array( 'enabled', 'active', 'on' ),
		'origin'             => array( 'origin' ),
		'note'               => array( 'note', 'notes', 'title', 'description', 'comment', 'comments' ),
	);
}

/**
 * A header cell, normalized.
 *
 * @param string $name Header cell.
 * @return string
 */
function csv_header_key( $name ) {
	return trim( (string) preg_replace( '/[\s_\-]+/', ' ', strtolower( trim( (string) $name ) ) ) );
}

/**
 * A cell made safe to open in a spreadsheet.
 *
 * @param string $value Cell.
 * @return string
 */
function csv_escape_cell( $value ) {
	$value = (string) $value;
	return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
}

/**
 * A cell as it was before csv_escape_cell().
 *
 * @param string $value Cell.
 * @return string
 */
function csv_unescape_cell( $value ) {
	$value = (string) $value;
	return strlen( $value ) > 1 && "'" === $value[0] && str_contains( "=+-@\t\r", $value[1] ) ? substr( $value, 1 ) : $value;
}

/**
 * Every redirect as CSV.
 *
 * @return string
 */
function export_csv() {
	$out = fopen( 'php://temp', 'w+' );
	fputcsv( $out, CSV_COLUMNS, ',', '"', '', "\r\n" );
	$yes = fn( $flag ) => $flag ? 'yes' : 'no';
	foreach ( all( 'ASC' ) as $row ) {
		$cells = array(
			$row['source'],
			$row['target'],
			(string) $row['status'],
			$row['is_regex'] ? 'regex' : 'exact',
			$row['query_mode'],
			$yes( $row['match_case'] ),
			$yes( $row['match_trailing'] ),
			$yes( $row['replaces_published'] ),
			$yes( $row['enabled'] ),
			$row['origin'],
			$row['note'],
		);
		fputcsv( $out, array_map( __NAMESPACE__ . '\\csv_escape_cell', $cells ), ',', '"', '', "\r\n" );
	}
	rewind( $out );
	$csv = (string) stream_get_contents( $out );
	fclose( $out );
	return "\xEF\xBB\xBF" . $csv;
}

/**
 * A yes/no cell as a bool, or null when it says neither.
 *
 * @param string $value Cell.
 * @return bool|null
 */
function csv_bool( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( in_array( $value, array( 'yes', 'y', 'true', '1', 'on', 'enabled', 'active', 'x' ), true ) ) {
		return true;
	}
	if ( in_array( $value, array( 'no', 'n', 'false', '0', 'off', 'disabled', 'inactive', '' ), true ) ) {
		return false;
	}
	return null;
}

/**
 * Parse CSV text into a header map and rows.
 *
 * @param string $content File contents.
 * @return array{columns: array<string, int>, unknown: string[], rows: array<int, array>}|\WP_Error
 *         rows are line number => cells.
 */
function csv_parse( $content ) {
	$content = (string) $content;
	if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) {
		$content = substr( $content, 3 );
	}
	if ( '' === trim( $content ) ) {
		return new \WP_Error( 'tgdsk_csv_empty', __( 'The file is empty.', 'tgd-site-kit' ) );
	}
	if ( ! wp_check_invalid_utf8( $content ) ) {
		return new \WP_Error( 'tgdsk_csv_encoding', __( 'The file is not UTF-8. Save it from the spreadsheet as “CSV UTF-8” and try again.', 'tgd-site-kit' ) );
	}
	$first     = strtok( $content, "\n" );
	$delimiter = ',';
	foreach ( array( ';', "\t" ) as $other ) {
		if ( substr_count( (string) $first, $other ) > substr_count( (string) $first, $delimiter ) ) {
			$delimiter = $other;
		}
	}
	$in = fopen( 'php://temp', 'w+' );
	fwrite( $in, $content );
	rewind( $in );
	$header = fgetcsv( $in, 0, $delimiter, '"', '' );
	$header = is_array( $header ) ? $header : array();

	$aliases = csv_aliases();
	$columns = array();
	$unknown = array();
	foreach ( $header as $i => $name ) {
		$key   = csv_header_key( $name );
		$found = '';
		foreach ( $aliases as $column => $names ) {
			if ( in_array( $key, $names, true ) ) {
				$found = $column;
				break;
			}
		}
		if ( '' === $found || isset( $columns[ $found ] ) ) {
			if ( '' !== $key ) {
				$unknown[] = (string) $name;
			}
			continue;
		}
		$columns[ $found ] = (int) $i;
	}
	if ( ! isset( $columns['source'] ) ) {
		fclose( $in );
		return new \WP_Error( 'tgdsk_csv_header', __( 'The first row must be a header naming the columns, with one for the source (source, from or old URL).', 'tgd-site-kit' ) );
	}

	$rows = array();
	$line = 1;
	while ( false !== ( $cells = fgetcsv( $in, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		++$line;
		if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
			continue;
		}
		$rows[ $line ] = array_map( fn( $c ) => csv_unescape_cell( (string) $c ), $cells );
		if ( count( $rows ) > CSV_MAX_ROWS ) {
			fclose( $in );
			/* translators: %d: row limit */
			return new \WP_Error( 'tgdsk_csv_size', sprintf( __( 'The file has more than %d rows. Split it and import each part.', 'tgd-site-kit' ), CSV_MAX_ROWS ) );
		}
	}
	fclose( $in );
	return array(
		'columns' => $columns,
		'unknown' => $unknown,
		'rows'    => $rows,
	);
}

/**
 * One CSV row as validate() input, or why it can't be one.
 *
 * @param array $cells   Row cells.
 * @param array $columns Column => index.
 * @return array|\WP_Error
 */
function csv_row_input( array $cells, array $columns ) {
	$cell  = fn( $name ) => isset( $columns[ $name ] ) ? trim( (string) ( $cells[ $columns[ $name ] ] ?? '' ) ) : null;
	$fail  = fn( $message ) => new \WP_Error( 'tgdsk_csv_row', $message );
	$input = array(
		'source' => (string) $cell( 'source' ),
		'target' => (string) $cell( 'target' ),
		'note'   => (string) $cell( 'note' ),
	);
	foreach ( array( 'source', 'target' ) as $name ) {
		// No address starts like a spreadsheet formula; a cell that does is
		// one, whatever made the file.
		if ( '' !== $input[ $name ] && str_contains( '=+-@', $input[ $name ][0] ) ) {
			/* translators: 1: column, 2: cell */
			return $fail( sprintf( __( 'The %1$s “%2$s” looks like a spreadsheet formula, not an address.', 'tgd-site-kit' ), $name, $input[ $name ] ) );
		}
	}

	$code    = $cell( 'code' );
	$enabled = $cell( 'enabled' );
	if ( null !== $code && '' !== $code && ! preg_match( '/^\d/', $code ) ) {
		// A "status" column that says enabled or disabled, not a code.
		if ( null === csv_bool( $code ) ) {
			/* translators: %s: cell */
			return $fail( sprintf( __( 'The code “%s” is not a number.', 'tgd-site-kit' ), $code ) );
		}
		$enabled = null === $enabled ? $code : $enabled;
		$code    = '';
	}
	$input['status'] = null === $code || '' === $code ? 301 : (int) $code;

	$match = $cell( 'match' );
	if ( null !== $match && '' !== $match ) {
		$lower = strtolower( $match );
		if ( in_array( $lower, array( 'regex', 'regexp', 're', 'regular expression', 'pattern' ), true ) ) {
			$input['is_regex'] = true;
		} elseif ( in_array( $lower, array( 'exact', 'plain', 'url', 'path', 'page' ), true ) ) {
			$input['is_regex'] = false;
		} elseif ( null !== csv_bool( $match ) ) {
			$input['is_regex'] = csv_bool( $match );
		} else {
			/* translators: %s: cell */
			return $fail( sprintf( __( 'The match “%s” is neither exact nor regex.', 'tgd-site-kit' ), $match ) );
		}
	}

	$query = $cell( 'query' );
	if ( null !== $query && '' !== $query ) {
		$lower = strtolower( $query );
		$modes = array(
			'exact'        => 'exact',
			'exact match'  => 'exact',
			'match'        => 'exact',
			'ignore'       => 'ignore',
			'ignored'      => 'ignore',
			'pass'         => 'pass',
			'pass on'      => 'pass',
			'pass through' => 'pass',
			'passthrough'  => 'pass',
			'pass query'   => 'pass',
		);
		if ( ! isset( $modes[ $lower ] ) ) {
			/* translators: %s: cell */
			return $fail( sprintf( __( 'The query handling “%s” is not exact, ignore or pass.', 'tgd-site-kit' ), $query ) );
		}
		$input['query_mode'] = $modes[ $lower ];
	}

	foreach ( array(
		'case_sensitive'     => 'match_case',
		'trailing_slash'     => 'match_trailing',
		'replaces_published' => 'replaces_published',
		'enabled'            => 'enabled',
	) as $column => $field ) {
		$value = 'enabled' === $column ? $enabled : $cell( $column );
		if ( null === $value || ( '' === $value && 'enabled' === $column ) ) {
			continue;
		}
		$bool = csv_bool( $value );
		if ( null === $bool ) {
			/* translators: 1: column, 2: cell */
			return $fail( sprintf( __( '%1$s “%2$s” is neither yes nor no.', 'tgd-site-kit' ), $column, $value ) );
		}
		$input[ $field ] = $bool;
	}
	return $input;
}

/**
 * Import CSV text, or report what an import would do.
 *
 * @param string $content File contents.
 * @param bool   $dry_run Report only.
 * @return array{dry_run: bool, time: int, error: string, unknown: string[], added: int, skipped: int, refused: int, rows: array[]}
 *         Each row: {line, action: add, skip or refuse, source, target, reason}.
 */
function import_csv( $content, $dry_run = true ) {
	$report = array(
		'dry_run' => (bool) $dry_run,
		'time'    => time(),
		'error'   => '',
		'unknown' => array(),
		'added'   => 0,
		'skipped' => 0,
		'refused' => 0,
		'rows'    => array(),
	);
	$parsed = csv_parse( $content );
	if ( is_wp_error( $parsed ) ) {
		$report['error'] = $parsed->get_error_message();
		return $report;
	}
	$report['unknown'] = $parsed['unknown'];
	$has_origin        = isset( $parsed['columns']['origin'] );
	$pending           = array();
	foreach ( $parsed['rows'] as $line => $cells ) {
		$input = csv_row_input( $cells, $parsed['columns'] );
		$entry = array(
			'line'   => (int) $line,
			'source' => is_array( $input ) ? $input['source'] : (string) ( $cells[ $parsed['columns']['source'] ] ?? '' ),
			'target' => is_array( $input ) ? $input['target'] : '',
		);
		$row   = is_wp_error( $input ) ? $input : validate( $input, 0, $dry_run ? $pending : array() );
		if ( is_wp_error( $row ) ) {
			$skip            = 'tgdsk_redirect_duplicate' === $row->get_error_code();
			$entry['action'] = $skip ? 'skip' : 'refuse';
			$entry['reason'] = $row->get_error_message();
			++$report[ $skip ? 'skipped' : 'refused' ];
			$report['rows'][] = $entry;
			continue;
		}
		$origin = 'import';
		if ( $has_origin ) {
			$given  = strtolower( trim( (string) ( $cells[ $parsed['columns']['origin'] ] ?? '' ) ) );
			$origin = in_array( $given, ORIGINS, true ) ? $given : 'import';
		}
		if ( $dry_run ) {
			$pending[] = $row;
		} elseif ( ! insert( $row, array( 'origin' => $origin ) ) ) {
			$entry['action'] = 'refuse';
			$entry['reason'] = __( 'The redirect could not be stored.', 'tgd-site-kit' );
			++$report['refused'];
			$report['rows'][] = $entry;
			continue;
		}
		$entry['action'] = 'add';
		$entry['reason'] = '';
		++$report['added'];
		$report['rows'][] = $entry;
	}
	return $report;
}
