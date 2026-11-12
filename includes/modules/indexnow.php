<?php
/**
 * Module: IndexNow.
 *
 * Tells the IndexNow engines (Bing, Yandex, Seznam, Naver and the rest) when
 * a public post or page is published, updated while published, or trashed.
 * The key is made the first time the module boots (a site that already had
 * one keeps it, so the engines keep trusting it), and is served at
 * `/{key}.txt`.
 *
 * - **Never pings from a non-production site**: only when
 *   wp_get_environment_type() is `production` and the home URL's host doesn't
 *   end in .local, .test or .localhost. A local or staging copy of a client
 *   site would otherwise announce its own URLs.
 * - **Never blocks the save**: URLs are collected during the request and
 *   handed to a one-off cron event at shutdown, which sends them in one
 *   request and records the answer.
 * - A short history (URLs, time, response code), at most 100 entries and 90
 *   days, on the settings screen and through the connector's
 *   `GET /tgdsk/v1/indexnow`.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\IndexNow;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const OPTION     = 'tgdsk_indexnow';
const LOG_OPTION = 'tgdsk_indexnow_log';
const ENDPOINT   = 'https://api.indexnow.org/indexnow';
const SEND_HOOK  = 'tgdsk_indexnow_send';
const LOG_MAX    = 100;
const LOG_DAYS   = 90;

\TGD_Site_Kit\register_module(
	'indexnow',
	array(
		'label'       => fn() => __( 'IndexNow', 'tgd-site-kit' ),
		'description' => fn() => __( 'Tells Bing and the other IndexNow engines when a post or page is published, updated or trashed. Never from a local or staging site.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Whether this site may send pings: production, on a public host.
 *
 * The `tgdsk_indexnow_is_production` filter exists for tests that stub the
 * endpoint; a staging site must never use it.
 *
 * @return bool
 */
function is_production() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$ok   = 'production' === wp_get_environment_type() && '' !== $host && ! preg_match( '/(\.local|\.test|\.localhost|^localhost)$/', $host );
	return (bool) apply_filters( 'tgdsk_indexnow_is_production', $ok );
}

/**
 * Settings, with defaults: the key, the post types, where the key came from.
 *
 * @return array{key: string, post_types: string[], source: string}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'key'        => (string) ( $stored['key'] ?? '' ),
		'post_types' => array_values( array_filter( (array) ( $stored['post_types'] ?? array( 'post', 'page' ) ), 'is_string' ) ),
		'source'     => (string) ( $stored['source'] ?? '' ),
	);
}

/**
 * Store the settings.
 *
 * @param array $settings Settings.
 */
function save_settings( array $settings ) {
	update_option( OPTION, $settings, false );
}

/**
 * Whether a key is shaped the way IndexNow requires: 8 to 128 characters,
 * letters, digits and dashes.
 *
 * @param string $key Key.
 * @return bool
 */
function is_valid_key( $key ) {
	return (bool) preg_match( '/^[A-Za-z0-9-]{8,128}$/', (string) $key );
}

/**
 * The first time the module boots: a new key, for posts and pages.
 */
function ensure_key() {
	if ( '' !== settings()['key'] ) {
		return;
	}
	save_settings(
		array(
			'key'        => bin2hex( random_bytes( 16 ) ),
			'post_types' => array( 'post', 'page' ),
			'source'     => 'generated',
		)
	);
}

/**
 * Reasons the module is holding back.
 *
 * @return string[]
 */
function blockers() {
	$lines = array();
	if ( ! is_production() ) {
		$lines[] = __( 'This is not a production site (its environment type, or a .local or .test address), so pings are recorded here but never sent.', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Add the module's hooks.
 */
function boot() {
	ensure_key();
	add_action( 'admin_post_tgdsk_indexnow_save', __NAMESPACE__ . '\\handle_save' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	add_action( 'parse_request', __NAMESPACE__ . '\\serve_key', 0 );
	add_action( 'transition_post_status', __NAMESPACE__ . '\\on_transition', 10, 3 );
	add_action( 'wp_trash_post', __NAMESPACE__ . '\\on_trash' );
	add_action( SEND_HOOK, __NAMESPACE__ . '\\send' );
}

/**
 * The key file's URL.
 *
 * @return string
 */
function key_location() {
	return trailingslashit( home_url() ) . settings()['key'] . '.txt';
}

/**
 * Answer `/{key}.txt` with the key, as plain text.
 *
 * @param \WP $wp The request.
 */
function serve_key( $wp ) {
	$key = settings()['key'];
	if ( '' === $key || $wp->request !== $key . '.txt' ) {
		return;
	}
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	status_header( 200 );
	echo esc_html( $key );
	exit;
}

/**
 * URLs collected during this request, each once.
 *
 * @param string|null $url   URL to add, or null to read.
 * @param bool        $drain Empty the queue after reading it.
 * @return string[]
 */
function queued( $url = null, $drain = false ) {
	static $urls = array();
	if ( null !== $url && '' !== $url && ! in_array( $url, $urls, true ) ) {
		add_action( 'shutdown', __NAMESPACE__ . '\\schedule' );
		$urls[] = $url;
	}
	$out = $urls;
	if ( $drain ) {
		$urls = array();
	}
	return $out;
}

/**
 * Whether a post is one whose URL the engines should hear about.
 *
 * @param \WP_Post $post Post.
 * @return bool
 */
function wanted( $post ) {
	return $post instanceof \WP_Post
		&& ! in_array( (int) $post->ID, \TGD_Site_Kit\Redirects\hidden_objects()['post'], true )
		&& in_array( $post->post_type, settings()['post_types'], true )
		&& is_post_type_viewable( $post->post_type )
		&& ! wp_is_post_revision( $post )
		&& ! wp_is_post_autosave( $post );
}

/**
 * Published, or updated while published: queue its URL.
 *
 * @param string   $new_status New status.
 * @param string   $old_status Old status.
 * @param \WP_Post $post       Post.
 */
function on_transition( $new_status, $old_status, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
	if ( 'publish' === $new_status && wanted( $post ) ) {
		queued( (string) get_permalink( $post ) );
	}
}

/**
 * About to be trashed while published: queue the URL it had.
 *
 * @param int $post_id Post ID.
 */
function on_trash( $post_id ) {
	$post = get_post( $post_id );
	if ( $post && 'publish' === $post->post_status && wanted( $post ) ) {
		queued( (string) get_permalink( $post ) );
	}
}

/**
 * At shutdown: hand this request's URLs to a cron event, or, on a site that
 * may not send, record that they were not sent.
 */
function schedule() {
	$urls = queued( null, true );
	if ( ! $urls ) {
		return;
	}
	if ( ! is_production() ) {
		log_entry( $urls, null, __( 'not sent: not a production site', 'tgd-site-kit' ) );
		return;
	}
	wp_schedule_single_event( time(), SEND_HOOK, array( $urls ) );
}

/**
 * Send URLs to IndexNow (the cron event) and record the answer.
 *
 * @param string[] $urls URLs.
 */
function send( $urls ) {
	$urls = array_values( array_filter( (array) $urls, 'is_string' ) );
	if ( ! $urls ) {
		return;
	}
	if ( ! is_production() ) {
		log_entry( $urls, null, __( 'not sent: not a production site', 'tgd-site-kit' ) );
		return;
	}
	$response = wp_remote_post(
		ENDPOINT,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode(
				array(
					'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
					'key'         => settings()['key'],
					'keyLocation' => key_location(),
					'urlList'     => $urls,
				),
				JSON_UNESCAPED_SLASHES
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		log_entry( $urls, 0, $response->get_error_message() );
		return;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	log_entry( $urls, $code, in_array( $code, array( 200, 202 ), true ) ? '' : wp_strip_all_tags( substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) ) );
}

/**
 * Add one entry to the history, keeping it to LOG_MAX entries and LOG_DAYS.
 *
 * @param string[] $urls URLs.
 * @param int|null $code HTTP status, 0 for a failed request, null for not sent.
 * @param string   $note Why it wasn't sent or what went wrong; '' for none.
 */
function log_entry( array $urls, $code, $note ) {
	$log   = history();
	$log[] = array(
		'time' => time(),
		'urls' => $urls,
		'code' => $code,
		'note' => $note,
	);
	$since = time() - LOG_DAYS * DAY_IN_SECONDS;
	$log   = array_values( array_filter( $log, fn( $e ) => (int) $e['time'] >= $since ) );
	update_option( LOG_OPTION, array_slice( $log, -LOG_MAX ), false );
}

/**
 * The history, oldest first.
 *
 * @return array[]
 */
function history() {
	$log = get_option( LOG_OPTION, array() );
	return is_array( $log ) ? array_values( $log ) : array();
}

/**
 * The state for the settings screen and the REST route.
 *
 * @param int $limit Most recent history entries to include.
 * @return array
 */
function state( $limit = 20 ) {
	$s       = settings();
	$entries = array_reverse( history() );
	return array(
		// Kept for callers that read them; nothing holds the module back any more.
		'active'       => true,
		'held_by'      => array(),
		'production'   => is_production(),
		'key_location' => '' !== $s['key'] ? key_location() : null,
		'key_source'   => $s['source'],
		'post_types'   => $s['post_types'],
		'history'      => array_map(
			fn( $e ) => array(
				'time' => gmdate( 'c', (int) $e['time'] ),
				'urls' => $e['urls'],
				'code' => $e['code'],
				'note' => $e['note'],
			),
			array_slice( $entries, 0, max( 1, (int) $limit ) )
		),
	);
}

/**
 * Notice wording.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'indexnow_saved' => __( 'IndexNow settings saved.', 'tgd-site-kit' ),
	);
}

/**
 * Save the post types.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_indexnow_save' );
	$types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$s     = settings();
	save_settings( array( 'post_types' => array_values( array_intersect( $types, get_post_types( array( 'public' => true ) ) ) ) ) + $s );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'indexnow_saved', 'indexnow' ) );
	exit;
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s     = settings();
	$state = state( 50 );
	?>
	<h2><?php esc_html_e( 'IndexNow', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<p><?php esc_html_e( 'When a post or page of the types below is published, updated while published, or trashed, its URL goes to IndexNow a moment later (never while the editor waits).', 'tgd-site-kit' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Key', 'tgd-site-kit' ); ?></th>
			<td>
				<code><?php echo esc_html( $s['key'] ); ?></code>
				<?php if ( 'import' === $s['source'] ) : ?>
					<span class="description"><?php esc_html_e( '(imported)', 'tgd-site-kit' ); ?></span>
				<?php endif; ?>
				<br><a href="<?php echo esc_url( key_location() ); ?>" target="_blank"><?php echo esc_html( key_location() ); ?></a>
			</td>
		</tr>
	</table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_indexnow_save">
		<?php wp_nonce_field( 'tgdsk_indexnow_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Post types', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
						<?php
						if ( 'attachment' === $type->name ) {
							continue;
						}
						?>
						<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>"<?php checked( in_array( $type->name, $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save', 'tgd-site-kit' ) ); ?>
	</form>

	<h3><?php esc_html_e( 'Recent pings', 'tgd-site-kit' ); ?></h3>
	<?php if ( ! $state['history'] ) : ?>
		<p class="description"><?php esc_html_e( 'None yet.', 'tgd-site-kit' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'When', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'URLs', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Answer', 'tgd-site-kit' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $state['history'] as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $entry['time'] ) ) ); ?></td>
					<td><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', $entry['urls'] ) ), array( 'br' => array() ) ); ?></td>
					<td><?php echo esc_html( trim( ( null === $entry['code'] ? '' : (string) $entry['code'] ) . ' ' . $entry['note'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
