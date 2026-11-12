<?php
/**
 * Security headers from the web server (0.6.1).
 *
 * 0.6.0 sent the security headers from PHP only, so a page a cache serves
 * straight from disk (no PHP) went out without them. On Apache the same
 * headers now go into the site's root `.htaccess` as well, in one block
 * between the kit's own markers, and Apache sends them on every response:
 * cached pages, static files, errors, logins.
 *
 * - **Exactly what the tab says.** The block holds the headers the Security
 *   tab is set to send, and nothing when they are off. It is rewritten
 *   whenever the settings or the module list change (through any write path:
 *   the forms, TGD Connect, WP-CLI) and removed when the headers are off, the
 *   module is off, or the plugin is deactivated or uninstalled.
 * - **Each header once.** For every header the block first unsets the copy in
 *   Apache's normal table (where PHP's `header()` and core's own
 *   `X-Frame-Options` on the login page land), then sets it in the `always`
 *   table, which goes out on every response, errors included. So a response
 *   carries one copy whether PHP sent one or not. PHP keeps sending the
 *   headers as in 0.6.0: on a host where the block does nothing, WordPress's
 *   responses still carry them.
 * - **`<IfModule mod_headers.c>`** around it, so a server without mod_headers
 *   skips it instead of failing.
 * - **Checked.** After a write, one loopback request to the home page, with
 *   PHP told (by a one-time token) not to send its own copies, shows what the
 *   server alone sends. The result is kept and shown, and a check that can't
 *   be made says so.
 * - **Nothing outside the block is touched.** The block is added and removed
 *   here byte for byte, rather than through core's `insert_with_markers()`,
 *   which rewrites every line of the file (line endings included) on each
 *   call. The markers are core's format, so core and other tools recognise
 *   the block.
 * - **Not written** when the server isn't Apache, the file can't be written,
 *   or the site is part of a multisite network (the root `.htaccess` is
 *   shared by every site in it). The tab says why, and for nginx shows the
 *   lines for the host to add.
 * - **A copy of `.htaccess`** from before the kit's first write is kept in
 *   the database (option BACKUP_OPTION, not autoloaded), never in a file
 *   visitors could fetch.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Security;

defined( 'ABSPATH' ) || exit;

const MARKER        = 'TGD Site Kit security headers';
const SERVER_OPTION = 'tgdsk_security_server';
const BACKUP_OPTION = 'tgdsk_security_htaccess_backup';
const CHECK_VAR     = 'tgdsk_header_check';
const CHECK_TOKEN   = 'tgdsk_header_check_token';

/**
 * The root `.htaccess`, where core keeps its own block.
 *
 * @return string
 */
function htaccess_path() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	return get_home_path() . '.htaccess';
}

/**
 * The headers the server should send now: none unless the module is on.
 * HSTS only for an HTTPS site (browsers ignore it on plain HTTP anyway).
 *
 * @return array<string, string>
 */
function server_headers_wanted() {
	if ( ! \TGD_Site_Kit\is_enabled( 'security' ) ) {
		return array();
	}
	$s   = settings();
	$out = array();
	if ( $s['headers'] ) {
		$out['X-Content-Type-Options'] = 'nosniff';
		$out['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		$out['X-Frame-Options']        = 'SAMEORIGIN';
		$out['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=()';
	}
	if ( $s['hsts'] && $s['hsts_max_age'] > 0 && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
		$out['Strict-Transport-Security'] = 'max-age=' . $s['hsts_max_age'];
	}
	return $out;
}

/**
 * Why the block can't be written here, or '' when it can.
 *
 * @return string `not_apache`, `multisite` or `not_writable`.
 */
function server_block_blocker() {
	if ( is_multisite() ) {
		return 'multisite';
	}
	$server = server_software();
	if ( '' === $server ) {
		// WP-CLI and cron runners have no server: go by what the site's own requests showed.
		$server = (string) server_state()['server'];
	}
	if ( '' === $server ) {
		return 'unknown_server';
	}
	if ( 'apache' !== $server ) {
		return 'not_apache';
	}
	$path = htaccess_path();
	if ( file_exists( $path ) ? ! wp_is_writable( $path ) : ! wp_is_writable( dirname( $path ) ) ) {
		return 'not_writable';
	}
	return '';
}

/**
 * The server this request came through: `apache` (core's `$is_apache`,
 * LiteSpeed included), `other`, or '' when there is none (WP-CLI).
 *
 * @return string
 */
function server_software() {
	if ( empty( $_SERVER['SERVER_SOFTWARE'] ) || 'cli' === PHP_SAPI ) {
		return '';
	}
	return empty( $GLOBALS['is_apache'] ) ? 'other' : 'apache';
}

/**
 * The block's lines, markers included.
 *
 * @param array<string, string> $headers Name => value.
 * @return string[]
 */
function server_block_lines( array $headers ) {
	$lines = array(
		'# BEGIN ' . MARKER,
		'# Written by TGD Site Kit from its Security tab, which rewrites or removes these lines.',
		'<IfModule mod_headers.c>',
	);
	foreach ( $headers as $name => $value ) {
		$lines[] = "\tHeader unset {$name}";
		$lines[] = "\tHeader always set {$name} \"{$value}\"";
	}
	$lines[] = '</IfModule>';
	$lines[] = '# END ' . MARKER;
	return $lines;
}

/**
 * Where the kit's block sits in a file's text.
 *
 * @param string $text File contents.
 * @return array{0: int, 1: int, 2: int}|null Start including the line break before it, start of the BEGIN line, end of the END line; or null.
 */
function find_server_block( $text ) {
	if ( ! preg_match( '/^# BEGIN ' . preg_quote( MARKER, '/' ) . '\r?$/m', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$start = $m[0][1];
	if ( ! preg_match( '/^# END ' . preg_quote( MARKER, '/' ) . '(?=\r?$)/m', $text, $e, PREG_OFFSET_CAPTURE, $start ) ) {
		return null;
	}
	$end   = $e[0][1] + strlen( $e[0][0] );
	$outer = $start;
	if ( $start >= 2 && "\r\n" === substr( $text, $start - 2, 2 ) ) {
		$outer -= 2;
	} elseif ( $start >= 1 && "\n" === $text[ $start - 1 ] ) {
		--$outer;
	}
	return array( $outer, $start, $end );
}

/**
 * A file's text with the kit's block put in (replacing one already there,
 * else added at the end after one line break), or taken out (with the line
 * break before it). Every other byte is kept.
 *
 * @param string        $text  File contents.
 * @param string[]|null $lines Block lines, or null to take the block out.
 * @return string
 */
function with_server_block( $text, $lines ) {
	$eol   = str_contains( $text, "\r\n" ) ? "\r\n" : "\n";
	$where = find_server_block( $text );
	if ( $where ) {
		$after = substr( $text, $where[2] );
		if ( null === $lines && 0 === $where[0] ) {
			// The block opened the file: drop the line break after it instead.
			$after = preg_replace( '/^\r?\n/', '', $after );
		}
		return null === $lines
			? substr( $text, 0, $where[0] ) . $after
			: substr( $text, 0, $where[1] ) . implode( $eol, $lines ) . substr( $text, $where[2] );
	}
	if ( null === $lines ) {
		return $text;
	}
	return ( '' === $text ? '' : $text . $eol ) . implode( $eol, $lines );
}

/**
 * Change the file under a lock.
 *
 * @param string        $path  File.
 * @param string[]|null $lines Block lines, or null to take the block out.
 * @return bool|string True when written or nothing needed changing, else an error.
 */
function write_server_block( $path, $lines ) {
	$exists = file_exists( $path );
	if ( ! $exists && null === $lines ) {
		return true;
	}
	// phpcs:disable WordPress.WP.AlternativeFunctions -- one locked read-modify-write of one file; WP_Filesystem can't lock.
	$fp = @fopen( $path, $exists ? 'r+' : 'x+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
	if ( ! $fp ) {
		return __( '.htaccess could not be opened for writing.', 'tgd-site-kit' );
	}
	flock( $fp, LOCK_EX );
	$text = (string) stream_get_contents( $fp );
	$new  = with_server_block( $text, $lines );
	$ok   = true;
	if ( $new !== $text ) {
		if ( ! get_option( BACKUP_OPTION ) ) {
			add_option(
				BACKUP_OPTION,
				array(
					'time'     => time(),
					'existed'  => $exists,
					'contents' => $text,
				),
				'',
				false
			);
		}
		$ok = rewind( $fp ) && ftruncate( $fp, 0 ) && false !== fwrite( $fp, $new ) && fflush( $fp );
	}
	flock( $fp, LOCK_UN );
	fclose( $fp );
	// phpcs:enable WordPress.WP.AlternativeFunctions
	if ( ! $exists && '' === $new ) {
		wp_delete_file( $path );
	}
	return $ok ? true : __( '.htaccess could not be written.', 'tgd-site-kit' );
}

/**
 * Whether the file holds the kit's block now.
 *
 * @return bool
 */
function server_block_present() {
	$path = htaccess_path();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	return file_exists( $path ) && null !== find_server_block( (string) file_get_contents( $path ) );
}

/**
 * The stored state: what was written, and the last check.
 *
 * @return array
 */
function server_state() {
	$s = get_option( SERVER_OPTION, array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'server'   => '', // `apache` or `other`, from the last sync made by a web request.
			'wanted'   => array(), // What the settings asked for at the last sync.
			'in_block' => array(), // What the block in .htaccess holds.
			'block'    => false,
			'reason'   => '',
			'error'    => '',
			'written'  => 0,
			'check'    => null,
		)
	);
}

/**
 * The headers a block holds, read back from its lines.
 *
 * @param string $text File contents.
 * @return array<string, string>
 */
function headers_in_block( $text ) {
	$where = find_server_block( $text );
	$out   = array();
	if ( $where && preg_match_all( '/^\s*Header always set (\S+) "([^"]*)"/m', substr( $text, $where[1], $where[2] - $where[1] ), $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $one ) {
			$out[ $one[1] ] = $one[2];
		}
	}
	return $out;
}

/**
 * Bring the file in line with the settings, and check when asked.
 *
 * @param bool $check Make the loopback check after writing a block.
 * @return array The new state.
 */
function sync_server_headers( $check = true ) {
	$wanted = server_headers_wanted();
	$old    = server_state();
	$reason = server_block_blocker();
	$path   = htaccess_path();
	$error  = '';

	if ( '' === $reason ) {
		$result = write_server_block( $path, $wanted ? server_block_lines( $wanted ) : null );
		$error  = true === $result ? '' : $result;
	} elseif ( ! $wanted && file_exists( $path ) && wp_is_writable( $path ) ) {
		// Taking the block out is always right, whatever the server.
		$result = write_server_block( $path, null );
		$error  = true === $result ? '' : $result;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	$text     = file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	$present  = null !== find_server_block( $text );
	$in_block = $present ? headers_in_block( $text ) : array();
	$changed  = $old['in_block'] !== $in_block || $old['block'] !== $present;
	$server   = server_software();

	$state = array(
		'server'   => '' !== $server ? $server : (string) $old['server'],
		'wanted'   => $wanted,
		'in_block' => $in_block,
		'block'    => $present,
		'reason'   => $reason,
		'error'    => $error,
		'written'  => $changed ? time() : (int) $old['written'],
		'check'    => $changed || ! $present ? null : $old['check'],
	);
	if ( ! $wanted && ! $present ) {
		// Nothing asked for and nothing in the file: keep nothing either.
		delete_option( SERVER_OPTION );
		return $state;
	}
	if ( $present && $in_block && $check ) {
		$state['check'] = check_server_headers( $in_block );
	}
	update_option( SERVER_OPTION, $state, false );
	return $state;
}

/**
 * Ask the home page, with PHP's own copies held back, which headers the
 * server sends.
 *
 * @param array<string, string> $wanted Name => value.
 * @return array{time: int, result: string, detail: string, seen: array<string, int>}
 */
function check_server_headers( array $wanted ) {
	$token = wp_generate_password( 32, false );
	set_transient( CHECK_TOKEN, $token, MINUTE_IN_SECONDS );
	$response = wp_remote_get(
		add_query_arg( CHECK_VAR, $token, home_url( '/' ) ),
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	delete_transient( CHECK_TOKEN );
	$out = array(
		'time'   => time(),
		'result' => 'failed',
		'detail' => '',
		'seen'   => array(),
	);
	if ( is_wp_error( $response ) ) {
		$out['detail'] = $response->get_error_message();
		return $out;
	}
	$object  = isset( $response['http_response'] ) && is_object( $response['http_response'] ) ? $response['http_response']->get_response_object() : null;
	$wrong   = array();
	$missing = array();
	foreach ( $wanted as $name => $value ) {
		$values = $object ? (array) $object->headers->getValues( $name ) : (array) wp_remote_retrieve_header( $response, $name );
		$values = array_values( array_filter( array_map( 'trim', $values ), 'strlen' ) );

		$out['seen'][ $name ] = count( $values );
		if ( ! $values ) {
			$missing[] = $name;
		} elseif ( array( $value ) !== $values ) {
			$wrong[] = $name;
		}
	}
	if ( ! $wrong && ! $missing ) {
		$out['result'] = 'ok';
	} elseif ( count( $missing ) === count( $wanted ) ) {
		$out['result'] = 'none';
	} else {
		$out['result'] = 'partial';
		$out['detail'] = implode( ', ', array_merge( $missing, $wrong ) );
	}
	$out['code'] = (int) wp_remote_retrieve_response_code( $response );
	return $out;
}

/**
 * Whether this request is the kit's own check, which PHP must not answer
 * with its copies of the headers.
 *
 * @return bool
 */
function is_header_check_request() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with a one-time token.
	$given = isset( $_GET[ CHECK_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ CHECK_VAR ] ) ) : '';
	if ( '' === $given ) {
		return false;
	}
	$token = get_transient( CHECK_TOKEN );
	return is_string( $token ) && '' !== $token && hash_equals( $token, $given );
}

/**
 * Sync once, at the end of a request that changed the settings or the
 * module list.
 */
function queue_server_sync() {
	if ( ! has_action( 'shutdown', __NAMESPACE__ . '\\run_queued_server_sync' ) ) {
		add_action( 'shutdown', __NAMESPACE__ . '\\run_queued_server_sync' );
	}
}

/**
 * The queued sync, unless the request already made one.
 */
function run_queued_server_sync() {
	if ( ! did_action( 'tgdsk_security_server_synced' ) ) {
		sync_server_headers( true );
	}
}

/**
 * A sync made for this request (so the queued one is skipped).
 *
 * @return array
 */
function sync_server_headers_now() {
	$state = sync_server_headers( true );
	do_action( 'tgdsk_security_server_synced' );
	return $state;
}

/**
 * Safety net on admin pages: a block that no longer matches the settings is
 * rewritten (for instance after options were changed straight in the
 * database). Cheap: compares stored state, reads no file.
 */
function reconcile_server_headers() {
	if ( ! get_option( SERVER_OPTION ) ) {
		return; // Nothing written yet: the first write is a save on the tab.
	}
	$state  = server_state();
	$wanted = server_headers_wanted();
	if ( $state['wanted'] !== $wanted || ( '' === $state['reason'] && $state['in_block'] !== $wanted ) ) {
		queue_server_sync();
	}
}

/**
 * Take the block out, whatever the settings say: deactivation and uninstall.
 */
function remove_server_block() {
	if ( is_multisite() ) {
		return;
	}
	write_server_block( htaccess_path(), null );
	delete_option( SERVER_OPTION );
}

/**
 * The lines an nginx host would add for the same headers. `fastcgi_hide_header`
 * drops PHP's copy so each goes out once.
 *
 * @param array<string, string> $headers Name => value.
 * @return string
 */
function nginx_lines( array $headers ) {
	$out = array();
	foreach ( $headers as $name => $value ) {
		$out[] = "fastcgi_hide_header {$name};";
		$out[] = "add_header {$name} \"{$value}\" always;";
	}
	return implode( "\n", $out );
}

/**
 * Where the headers come from, for the tab and the status route.
 *
 * @return array{source: string, message: string, block: bool, reason: string, checked_at: string|null, check: string|null, problem: bool}
 */
function server_headers_summary() {
	$state  = server_state();
	$wanted = server_headers_wanted();
	$check  = $state['check'];
	$when   = $check ? wp_date( 'M j, Y g:ia', (int) $check['time'] ) : '';
	$out    = array(
		'source'     => 'wordpress',
		'message'    => '',
		'block'      => (bool) $state['block'],
		'reason'     => (string) $state['reason'],
		'checked_at' => $check ? gmdate( 'c', (int) $check['time'] ) : null,
		'check'      => $check ? (string) $check['result'] : null,
		'problem'    => false,
	);
	if ( ! $wanted ) {
		$out['source'] = 'off';
		if ( $state['block'] ) {
			$out['problem'] = true;
			$out['message'] = __( 'The security headers are off, but the kit’s block is still in .htaccess and couldn’t be removed (the file isn’t writable). Remove the lines between “# BEGIN TGD Site Kit security headers” and “# END TGD Site Kit security headers” by hand.', 'tgd-site-kit' );
		}
		return $out;
	}
	$reasons = array(
		'not_apache'     => __( 'the server isn’t Apache, so there is no .htaccess to write. Pages a cache serves without WordPress go out without them; the lines below are for the host to add.', 'tgd-site-kit' ),
		'multisite'      => __( 'this site is part of a multisite network, whose .htaccess is shared by every site in it.', 'tgd-site-kit' ),
		'not_writable'   => __( '.htaccess isn’t writable. Pages a cache serves without WordPress go out without them.', 'tgd-site-kit' ),
		'unknown_server' => __( 'the settings were changed outside the web server (WP-CLI), so the kit can’t tell yet whether it is Apache. Save the settings on this tab to write the block.', 'tgd-site-kit' ),
	);
	if ( ! $state['block'] ) {
		$out['problem'] = true;
		$why            = $reasons[ $state['reason'] ] ?? ( '' !== $state['error'] ? $state['error'] : __( 'the block hasn’t been written yet. Save the settings to write it.', 'tgd-site-kit' ) );
		/* translators: %s: why */
		$out['message'] = sprintf( __( 'Sent by WordPress only: %s', 'tgd-site-kit' ), $why );
		return $out;
	}
	if ( $state['in_block'] !== $wanted ) {
		$out['problem'] = true;
		$out['message'] = __( 'The kit’s block in .htaccess doesn’t match these settings and couldn’t be rewritten', 'tgd-site-kit' ) . ( '' !== $state['error'] ? ': ' . $state['error'] : ( 'not_writable' === $state['reason'] ? __( ': .htaccess isn’t writable.', 'tgd-site-kit' ) : '.' ) );
		return $out;
	}
	if ( $check && 'ok' === $check['result'] ) {
		$out['source'] = 'server';
		/* translators: %s: date and time */
		$out['message'] = sprintf( __( 'Sent by the server (.htaccess), last checked %s.', 'tgd-site-kit' ), $when );
		return $out;
	}
	$out['problem'] = true;
	if ( ! $check ) {
		$out['message'] = __( 'The block is in .htaccess but hasn’t been checked. Use “Check again”.', 'tgd-site-kit' );
	} elseif ( 'failed' === $check['result'] ) {
		/* translators: 1: date and time, 2: error */
		$out['message'] = sprintf( __( 'The block is in .htaccess, but the check at %1$s couldn’t be made (%2$s); some hosts block a site’s requests to itself. Check a cached page’s response headers yourself. WordPress sends them on its own responses either way.', 'tgd-site-kit' ), $when, $check['detail'] );
	} elseif ( 'none' === $check['result'] ) {
		/* translators: %s: date and time */
		$out['message'] = sprintf( __( 'Sent by WordPress only: the block is in .htaccess, but the server sent none of the headers when checked at %s (mod_headers is probably off). Pages a cache serves without WordPress go out without them.', 'tgd-site-kit' ), $when );
	} else {
		/* translators: 1: date and time, 2: header names */
		$out['message'] = sprintf( __( 'The block is in .htaccess, but when checked at %1$s these were missing, different or sent more than once: %2$s.', 'tgd-site-kit' ), $when, $check['detail'] );
	}
	return $out;
}

/**
 * The tab's lines on where the headers come from. Printed inside the
 * settings form, so the button belongs to the separate check form printed
 * after it (render_server_headers_check_form()).
 */
function render_server_headers_status() {
	$summary = server_headers_summary();
	$state   = server_state();
	$backup  = get_option( BACKUP_OPTION );
	if ( 'off' === $summary['source'] && ! $summary['problem'] ) {
		return;
	}
	printf(
		'<div class="notice inline %1$s"><p>%2$s</p></div>',
		esc_attr( $summary['problem'] ? 'notice-warning' : 'notice-success' ),
		esc_html( $summary['message'] )
	);
	if ( $state['block'] ) {
		printf( '<p><button type="submit" class="button" form="tgdsk-header-check">%s</button></p>', esc_html__( 'Check again', 'tgd-site-kit' ) );
	}
	if ( 'not_apache' === $state['reason'] && server_headers_wanted() ) {
		printf(
			'<p class="description">%1$s</p><pre style="max-width:720px;white-space:pre-wrap">%2$s</pre>',
			esc_html__( 'For nginx, the host adds these to the site’s server block (the fastcgi_hide_header lines keep WordPress’s own copies from going out twice):', 'tgd-site-kit' ),
			esc_html( nginx_lines( server_headers_wanted() ) )
		);
	}
	if ( is_array( $backup ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: date and time, 2: option name */
					__( 'A copy of .htaccess from before the kit first wrote to it (%1$s) is kept in the database, in the option %2$s.', 'tgd-site-kit' ),
					wp_date( 'M j, Y g:ia', (int) $backup['time'] ),
					BACKUP_OPTION
				)
			)
		);
	}
}

/**
 * The check form, outside the settings form.
 */
function render_server_headers_check_form() {
	if ( ! server_state()['block'] ) {
		return;
	}
	?>
	<form id="tgdsk-header-check" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_security_check">
		<?php wp_nonce_field( 'tgdsk_security_check' ); ?>
	</form>
	<?php
}

/**
 * Check again, from the tab.
 */
function handle_check() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_security_check' );
	sync_server_headers_now();
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'security_checked', 'security' ) );
	exit;
}

foreach ( array( OPTION, \TGD_Site_Kit\MODULES_OPTION ) as $tgdsk_option ) {
	add_action( "update_option_{$tgdsk_option}", __NAMESPACE__ . '\\queue_server_sync' );
	add_action( "add_option_{$tgdsk_option}", __NAMESPACE__ . '\\queue_server_sync' );
	add_action( "delete_option_{$tgdsk_option}", __NAMESPACE__ . '\\queue_server_sync' );
}
unset( $tgdsk_option );
add_action( 'admin_init', __NAMESPACE__ . '\\reconcile_server_headers' );
add_action( 'admin_post_tgdsk_security_check', __NAMESPACE__ . '\\handle_check' );
