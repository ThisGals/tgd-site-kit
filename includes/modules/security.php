<?php
/**
 * Module: security (0.6.0).
 *
 * Four parts, each switched on separately, all off by default:
 *
 * - **XML-RPC off**: `xmlrpc.php` answers 403 and does nothing for anyone,
 *   pingbacks included; the `X-Pingback` header and the RSD link leave the
 *   head. REST and application passwords are untouched.
 * - **User enumeration**: `?author=N` is a 404 for a logged-out visitor
 *   (instead of a redirect naming the author's slug), `/wp/v2/users` and
 *   `/wp/v2/users/<id>` need a signed-in user (an application password
 *   counts), and core's users sitemap is left out. Author archives at their
 *   own address, the schema module's author and every authenticated route keep
 *   working.
 * - **Security headers** on front-end and login responses (on Apache also from
 *   .htaccess since 0.6.1, see includes/security/server-headers.php):
 *   `X-Content-Type-Options: nosniff`, `Referrer-Policy:
 *   strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN` (the
 *   Customizer and builder previews are same-origin frames, so they keep
 *   working), and `Permissions-Policy` with camera, microphone and
 *   geolocation off. HSTS is a separate opt-in with its own max-age. No
 *   Content-Security-Policy: these sites run builders and third-party embeds,
 *   and a wrong one breaks pages without a sound. A header PHP has already
 *   set is not sent again, and the kit's .htaccess block clears PHP's copy
 *   before setting its own, so each goes out once.
 * - **Login attempt limiting**: failed password logins through the login form
 *   are counted per `REMOTE_ADDR` (never a forwarded header, which the caller
 *   controls), and an address with too many in the window is locked out for a
 *   while. The failure message is the same for a wrong username and a wrong
 *   password. **Application passwords are never counted and never locked**:
 *   they authenticate REST requests (the WordPress MCP server among them),
 *   which this never touches, and neither does a single sign-on that sets the
 *   login cookie itself. State is one transient per address, keyed by a keyed
 *   hash of it, expiring with the window or the lockout: no list of
 *   addresses is kept.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Security;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const OPTION       = 'tgdsk_security';
const LOGIN_PREFIX = 'tgdsk_login_';

require_once dirname( __DIR__ ) . '/security/server-headers.php';

\TGD_Site_Kit\register_module(
	'security',
	array(
		'label'       => fn() => __( 'Security', 'tgd-site-kit' ),
		'description' => fn() => __( 'XML-RPC off, user enumeration closed, security headers, and login attempt limiting, each switched on separately on its tab.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
	)
);

/**
 * Settings, with defaults: every part off.
 *
 * @return array{xmlrpc_off: bool, users_hidden: bool, headers: bool, hsts: bool, hsts_max_age: int, login_limit: bool, login_max: int, login_window: int, login_lockout: int}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$s      = wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'xmlrpc_off'    => false,
			'users_hidden'  => false,
			'headers'       => false,
			'hsts'          => false,
			'hsts_max_age'  => 31536000,
			'login_limit'   => false,
			'login_max'     => 5,
			'login_window'  => 15,
			'login_lockout' => 30,
		)
	);
	foreach ( array( 'xmlrpc_off', 'users_hidden', 'headers', 'hsts', 'login_limit' ) as $key ) {
		$s[ $key ] = (bool) $s[ $key ];
	}
	$s['hsts_max_age']  = max( 0, (int) $s['hsts_max_age'] );
	$s['login_max']     = max( 1, (int) $s['login_max'] );
	$s['login_window']  = max( 1, (int) $s['login_window'] );
	$s['login_lockout'] = max( 1, (int) $s['login_lockout'] );
	return $s;
}

/**
 * Add the hooks of the parts that are on.
 */
function boot() {
	add_action( 'admin_post_tgdsk_security_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_security_clear', __NAMESPACE__ . '\\handle_clear' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	$s = settings();
	if ( $s['xmlrpc_off'] ) {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			refuse_xmlrpc();
		}
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array', PHP_INT_MAX );
		add_filter( 'wp_headers', __NAMESPACE__ . '\\drop_pingback_header' );
		remove_action( 'wp_head', 'rsd_link' );
	}
	if ( $s['users_hidden'] ) {
		add_filter( 'request', __NAMESPACE__ . '\\refuse_author_query' );
		add_filter( 'rest_pre_dispatch', __NAMESPACE__ . '\\refuse_users_route', 10, 3 );
		add_filter( 'wp_sitemaps_add_provider', __NAMESPACE__ . '\\drop_users_sitemap', 20, 2 );
	}
	if ( $s['headers'] || $s['hsts'] ) {
		add_action( 'send_headers', __NAMESPACE__ . '\\send_security_headers' );
		add_action( 'login_init', __NAMESPACE__ . '\\send_security_headers' );
	}
	if ( $s['login_limit'] ) {
		add_filter( 'authenticate', __NAMESPACE__ . '\\check_login', 99, 3 );
		add_action( 'wp_login_failed', __NAMESPACE__ . '\\count_failure', 10, 2 );
		add_action( 'wp_login', __NAMESPACE__ . '\\forget_failures' );
	}
}

/**
 * Answer an XML-RPC request with 403 and stop.
 */
function refuse_xmlrpc() {
	status_header( 403 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo 'XML-RPC is turned off on this site.';
	exit;
}

/**
 * Drop the X-Pingback header.
 *
 * @param array $headers Headers.
 * @return array
 */
function drop_pingback_header( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
}

/**
 * `?author=N` from a logged-out visitor: a 404 instead of the redirect to the
 * author's slug. An archive at its own address (`/author/slug/`) is a
 * different query var and keeps working.
 *
 * @param array $vars Query vars.
 * @return array
 */
function refuse_author_query( $vars ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks that the query names an author.
	if ( ! is_admin() && ! is_user_logged_in() && isset( $vars['author'] ) && isset( $_GET['author'] ) ) {
		return array( 'error' => '404' );
	}
	return $vars;
}

/**
 * The users routes need a signed-in user.
 *
 * @param mixed            $result  Response so far.
 * @param \WP_REST_Server  $server  Server.
 * @param \WP_REST_Request $request Request.
 * @return mixed
 */
function refuse_users_route( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
	if ( null === $result && ! is_user_logged_in() && preg_match( '#^/wp/v2/users(?:/|$)#', (string) $request->get_route() ) ) {
		return new \WP_Error( 'rest_not_logged_in', __( 'Sign in to list users.', 'tgd-site-kit' ), array( 'status' => 401 ) );
	}
	return $result;
}

/**
 * Leave core's users sitemap out.
 *
 * @param mixed  $provider Provider or false.
 * @param string $name     Provider name.
 * @return mixed
 */
function drop_users_sitemap( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}

/**
 * The headers this module sends, with their values.
 *
 * @param array $s Settings.
 * @return array<string, string>
 */
function headers_to_send( array $s ) {
	$out = array();
	if ( $s['headers'] ) {
		$out['X-Content-Type-Options'] = 'nosniff';
		$out['Referrer-Policy']        = 'strict-origin-when-cross-origin';
		$out['X-Frame-Options']        = 'SAMEORIGIN';
		$out['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=()';
	}
	if ( $s['hsts'] && is_ssl() && $s['hsts_max_age'] > 0 ) {
		$out['Strict-Transport-Security'] = 'max-age=' . $s['hsts_max_age'];
	}
	return $out;
}

/**
 * Send the headers, leaving out any PHP has already set.
 */
function send_security_headers() {
	if ( headers_sent() || is_header_check_request() ) {
		return;
	}
	$already = array();
	foreach ( headers_list() as $line ) {
		$already[ strtolower( trim( (string) strtok( $line, ':' ) ) ) ] = true;
	}
	foreach ( headers_to_send( settings() ) as $name => $value ) {
		if ( ! isset( $already[ strtolower( $name ) ] ) ) {
			header( $name . ': ' . $value );
		}
	}
}

/**
 * The visitor's address, as the connection gives it. Never a forwarded
 * header: those come from the caller.
 *
 * @return string '' when there is none.
 */
function client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * The transient key for an address: a keyed hash, so no address is stored.
 *
 * @param string $ip Address.
 * @return string
 */
function login_key( $ip ) {
	return LOGIN_PREFIX . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Whether this request is a password login the limit applies to: not REST
 * (application passwords), not XML-RPC.
 *
 * @return bool
 */
function is_form_login() {
	return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) && ! wp_is_json_request();
}

/**
 * An address's login state, or null.
 *
 * @param string $ip Address.
 * @return array{count: int, first: int, locked_until: int}|null
 */
function login_state( $ip ) {
	$state = '' !== $ip ? get_transient( login_key( $ip ) ) : false;
	return is_array( $state ) ? $state : null;
}

/**
 * Refuse a login from a locked address, and word every username or password
 * failure the same way.
 *
 * @param mixed  $user     User, error or null so far.
 * @param string $username Username.
 * @param string $password Password.
 * @return mixed
 */
function check_login( $user, $username, $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
	if ( ! is_form_login() || '' === (string) $username ) {
		return $user;
	}
	$state = login_state( client_ip() );
	if ( $state && $state['locked_until'] > time() ) {
		$minutes = (int) ceil( ( $state['locked_until'] - time() ) / MINUTE_IN_SECONDS );
		/* translators: %d: minutes */
		return new \WP_Error( 'tgdsk_login_locked', sprintf( _n( '<strong>Error:</strong> Too many failed logins from this address. Try again in %d minute.', '<strong>Error:</strong> Too many failed logins from this address. Try again in %d minutes.', $minutes, 'tgd-site-kit' ), $minutes ) );
	}
	if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
		return new \WP_Error( 'tgdsk_login_failed', __( '<strong>Error:</strong> The username or password is incorrect.', 'tgd-site-kit' ) );
	}
	return $user;
}

/**
 * Count a failed password login from the login form.
 *
 * @param string    $username Username.
 * @param \WP_Error $error    Why.
 */
function count_failure( $username, $error = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
	$ip = client_ip();
	if ( '' === $ip || ! is_form_login() || ! $error instanceof \WP_Error || ! in_array( 'tgdsk_login_failed', $error->get_error_codes(), true ) ) {
		return;
	}
	$s     = settings();
	$now   = time();
	$state = login_state( $ip );
	if ( ! $state || $state['first'] + $s['login_window'] * MINUTE_IN_SECONDS < $now ) {
		$state = array(
			'count'        => 0,
			'first'        => $now,
			'locked_until' => 0,
		);
	}
	++$state['count'];
	if ( $state['count'] >= $s['login_max'] ) {
		$state['locked_until'] = $now + $s['login_lockout'] * MINUTE_IN_SECONDS;
	}
	$expires = $state['locked_until'] ? $state['locked_until'] - $now : $state['first'] + $s['login_window'] * MINUTE_IN_SECONDS - $now;
	set_transient( login_key( $ip ), $state, max( 1, $expires ) );
}

/**
 * A successful login starts the count again.
 */
function forget_failures() {
	$ip = client_ip();
	if ( '' !== $ip ) {
		delete_transient( login_key( $ip ) );
	}
}

/**
 * Current lockouts, by key: only the hash is known, never the address.
 * Read from the options table, so a site with a persistent object cache
 * (which keeps transients elsewhere) shows none.
 *
 * @return array<string, array> Key => state.
 */
function lockouts() {
	global $wpdb;
	$out = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients by prefix, on the module's tab only.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . LOGIN_PREFIX ) . '%' ) ) as $name ) {
		$key   = substr( $name, strlen( '_transient_' ) );
		$state = get_transient( $key );
		if ( is_array( $state ) && $state['locked_until'] > time() ) {
			$out[ $key ] = $state;
		}
	}
	return $out;
}

/**
 * Save the settings form.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_security_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$num = fn( $key, $fallback ) => isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $fallback;
	update_option(
		OPTION,
		array(
			'xmlrpc_off'    => ! empty( $_POST['xmlrpc_off'] ),
			'users_hidden'  => ! empty( $_POST['users_hidden'] ),
			'headers'       => ! empty( $_POST['headers'] ),
			'hsts'          => ! empty( $_POST['hsts'] ),
			'hsts_max_age'  => $num( 'hsts_max_age', 31536000 ),
			'login_limit'   => ! empty( $_POST['login_limit'] ),
			'login_max'     => max( 1, $num( 'login_max', 5 ) ),
			'login_window'  => max( 1, $num( 'login_window', 15 ) ),
			'login_lockout' => max( 1, $num( 'login_lockout', 30 ) ),
		),
		true
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	sync_server_headers_now();
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'security_saved', 'security' ) );
	exit;
}

/**
 * Clear one lockout, or all of them.
 */
function handle_clear() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_security_clear' );
	$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	foreach ( array_keys( lockouts() ) as $locked ) {
		if ( '' === $key || $key === $locked ) {
			delete_transient( $locked );
		}
	}
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'security_cleared', 'security' ) );
	exit;
}

/**
 * Notice wording.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'security_saved'   => __( 'Security settings saved.', 'tgd-site-kit' ),
		'security_cleared' => __( 'Lockout cleared.', 'tgd-site-kit' ),
		'security_checked' => __( 'Headers checked.', 'tgd-site-kit' ),
	);
}

/**
 * The module's tab.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s    = settings();
	$box  = fn( $key, $label ) => printf( '<label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>', esc_attr( $key ), checked( $s[ $key ], true, false ), esc_html( $label ) );
	$lock = lockouts();
	?>
	<h2><?php esc_html_e( 'Security', 'tgd-site-kit' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Each part is off until it is ticked. None of them touches REST requests signed in with an application password.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_security_save">
		<?php wp_nonce_field( 'tgdsk_security_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'XML-RPC', 'tgd-site-kit' ); ?></th>
				<td><?php $box( 'xmlrpc_off', __( 'Turn XML-RPC off: xmlrpc.php answers 403 for everyone, pingbacks included, and the pingback header and RSD link leave the head', 'tgd-site-kit' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'User enumeration', 'tgd-site-kit' ); ?></th>
				<td><?php $box( 'users_hidden', __( '?author=N is a 404 for visitors, the users REST routes need a signed-in user, and the users sitemap is left out', 'tgd-site-kit' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Security headers', 'tgd-site-kit' ); ?></th>
				<td>
					<?php $box( 'headers', __( 'Send X-Content-Type-Options, Referrer-Policy, X-Frame-Options (same origin) and a Permissions-Policy that turns off camera, microphone and geolocation', 'tgd-site-kit' ) ); ?>
					<p class="description"><?php esc_html_e( 'On Apache these also go into .htaccess, between the kit’s own markers, so pages a cache serves without WordPress carry them too; saving rewrites or removes that block, and nothing else in the file changes. Each header goes out once. No Content-Security-Policy is sent; a wrong one breaks builder pages and embeds without warning.', 'tgd-site-kit' ); ?></p>
					<?php render_server_headers_status(); ?>
					<p><?php $box( 'hsts', __( 'Also send Strict-Transport-Security (HSTS) on HTTPS', 'tgd-site-kit' ) ); ?>
					<label><?php esc_html_e( 'max-age', 'tgd-site-kit' ); ?> <input type="number" min="0" name="hsts_max_age" class="regular-text" value="<?php echo esc_attr( (string) $s['hsts_max_age'] ); ?>"> <?php esc_html_e( 'seconds', 'tgd-site-kit' ); ?></label></p>
					<p class="description"><strong><?php esc_html_e( 'HSTS is a promise browsers keep:', 'tgd-site-kit' ); ?></strong> <?php esc_html_e( 'for the whole max-age, every browser that has seen it refuses this domain over plain HTTP, even if the certificate lapses or the site moves to a host without HTTPS. Turning it off here doesn’t undo it for them. Start with a short max-age (300) and raise it once HTTPS is certain.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Login attempts', 'tgd-site-kit' ); ?></th>
				<td>
					<?php $box( 'login_limit', __( 'Lock an address out after too many failed password logins', 'tgd-site-kit' ) ); ?><br>
					<label><input type="number" min="1" name="login_max" class="small-text" value="<?php echo esc_attr( (string) $s['login_max'] ); ?>"> <?php esc_html_e( 'failures in', 'tgd-site-kit' ); ?></label>
					<label><input type="number" min="1" name="login_window" class="small-text" value="<?php echo esc_attr( (string) $s['login_window'] ); ?>"> <?php esc_html_e( 'minutes lock the address for', 'tgd-site-kit' ); ?></label>
					<label><input type="number" min="1" name="login_lockout" class="small-text" value="<?php echo esc_attr( (string) $s['login_lockout'] ); ?>"> <?php esc_html_e( 'minutes', 'tgd-site-kit' ); ?></label>
					<p class="description"><?php esc_html_e( 'Counted by the connection’s own address, never a forwarded one. The login error no longer says whether the username exists. Application passwords (REST) are never counted or locked out, and neither is XML-RPC: turn XML-RPC off to close that door too.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save security settings', 'tgd-site-kit' ) ); ?>
	</form>
	<?php render_server_headers_check_form(); ?>

	<h3><?php esc_html_e( 'Current lockouts', 'tgd-site-kit' ); ?></h3>
	<?php if ( ! $lock ) : ?>
		<p><?php esc_html_e( 'None.', 'tgd-site-kit' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:720px">
			<thead><tr><th><?php esc_html_e( 'Address (hashed)', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Failures', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Locked until', 'tgd-site-kit' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $lock as $key => $state ) : ?>
				<tr>
					<td><code><?php echo esc_html( substr( $key, strlen( LOGIN_PREFIX ), 10 ) ); ?>…</code></td>
					<td><?php echo esc_html( (string) $state['count'] ); ?></td>
					<td><?php echo esc_html( wp_date( 'M j, Y g:ia', (int) $state['locked_until'] ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tgdsk_security_clear">
							<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
							<?php wp_nonce_field( 'tgdsk_security_clear' ); ?>
							<button type="submit" class="button-link"><?php esc_html_e( 'Clear', 'tgd-site-kit' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tgdsk_security_clear">
			<?php wp_nonce_field( 'tgdsk_security_clear' ); ?>
			<p><button type="submit" class="button"><?php esc_html_e( 'Clear every lockout', 'tgd-site-kit' ); ?></button></p>
		</form>
	<?php endif; ?>
	<?php
}
