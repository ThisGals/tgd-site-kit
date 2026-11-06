<?php
/**
 * Module: 404 log.
 *
 * Records front-end 404s so Jarvis can turn real broken links into redirect
 * proposals: the path, the last referrer, a count, first and last seen. Never
 * an IP address or a user agent. Bot noise (login and admin probes, `.php`
 * scans, XML-RPC, dotfiles, backup and config files) is not recorded.
 *
 * Storage is capped: at most `max_rows` paths (the least recently seen go
 * first) and nothing older than `max_days`, pruned at most once a day.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Not_Found;

use function TGD_Site_Kit\table;
use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

// The kit's own table, with no WordPress API over it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

const TABLE_VERSION = 1;
const OPTION        = 'tgdsk_not_found';
const PRUNE_FLAG    = 'tgdsk_not_found_pruned';

\TGD_Site_Kit\register_module(
	'not_found',
	array(
		'label'       => fn() => __( '404 log', 'tgd-site-kit' ),
		'description' => fn() => __( 'Records front-end 404s (path, last referrer, count, first and last seen; no IP addresses or user agents) so broken links can become redirects. Bot probes are ignored and storage is capped.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
	)
);

/**
 * Settings, with defaults.
 *
 * @return array{max_rows: int, max_days: int}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'max_rows' => max( 100, (int) ( $stored['max_rows'] ?? 2000 ) ),
		'max_days' => max( 7, (int) ( $stored['max_days'] ?? 90 ) ),
	);
}

/**
 * Create the table.
 */
function ensure_table() {
	\TGD_Site_Kit\ensure_table(
		'not_found',
		'id bigint(20) unsigned NOT NULL auto_increment,
path_hash char(32) NOT NULL,
path varchar(500) NOT NULL default \'\',
referrer varchar(500) NOT NULL default \'\',
hits bigint(20) unsigned NOT NULL default 0,
first_seen datetime NOT NULL default \'0000-00-00 00:00:00\',
last_seen datetime NOT NULL default \'0000-00-00 00:00:00\',
PRIMARY KEY  (id),
UNIQUE KEY path_hash (path_hash),
KEY last_seen (last_seen),
KEY hits (hits)',
		TABLE_VERSION
	);
}

/**
 * Whether the table exists.
 *
 * @return bool
 */
function table_ready() {
	$versions = get_option( \TGD_Site_Kit\DB_OPTION, array() );
	return is_array( $versions ) && (int) ( $versions['not_found'] ?? 0 ) >= TABLE_VERSION;
}

/**
 * Add the module's hooks.
 */
function boot() {
	ensure_table();
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_record', 99 );
	add_action( 'admin_post_tgdsk_not_found_settings', __NAMESPACE__ . '\\handle_settings' );
	add_action( 'admin_post_tgdsk_not_found_clear', __NAMESPACE__ . '\\handle_clear' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
}

/**
 * Whether a path is bot noise rather than a link someone followed.
 *
 * @param string $path Decoded, lowercased path.
 * @return bool
 */
function is_noise( $path ) {
	$patterns = array(
		'#\.(php\d?|phtml|asp|aspx|jsp|cgi|pl|env|ini|sql|bak|old|orig|swp|log|tar|gz|tgz|zip|rar|7z|yml|yaml|config|conf|git|svn|ds_store)(/|$)#',
		'#(^|/)\.[^/]+#',
		'#(^|/)(wp-admin|wp-login|wp-includes|xmlrpc|wp-config|cgi-bin|phpmyadmin|pma|vendor|node_modules|\.well-known/acme)(/|\.|$)#',
		'#(^|/)wp-content/(plugins|themes|uploads/[^/]+\.php)#',
		'#(^|/)(admin|administrator|login|user/login|owa|ecp|autodiscover|actuator|solr|boaform|hnap1|setup\.cgi)(/|$)#',
		'#(^|/)wp-json/?#',
	);
	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $path ) ) {
			return true;
		}
	}
	return false;
}

/**
 * A referrer worth keeping: its scheme, host and path, never its query (that
 * is where personal data travels), and nothing from this same path.
 *
 * @param string $raw  Referrer header.
 * @param string $path This request's path.
 * @return string
 */
function clean_referrer( $raw, $path ) {
	$parts = wp_parse_url( (string) $raw );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
		return '';
	}
	$out = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( $parts['path'] ?? '/' );
	if ( \TGD_Site_Kit\Redirects\bare_host( $parts['host'] ) === \TGD_Site_Kit\Redirects\bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) && rawurldecode( $parts['path'] ?? '/' ) === $path ) {
		return '';
	}
	return esc_url_raw( mb_substr( $out, 0, 500 ) );
}

/**
 * Record this request if it is a front-end 404 worth keeping.
 */
function maybe_record() {
	// A 410 from the redirects module is a page gone on purpose, not a broken link.
	if ( ! is_404() || \TGD_Site_Kit\Redirects\gone() || ! \TGD_Site_Kit\is_front_end_request() || is_robots() ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed to a path only.
	$path = \TGD_Site_Kit\Redirects\split_url( (string) $uri )['path'];
	if ( strlen( $path ) > 500 || is_noise( strtolower( $path ) ) ) {
		return;
	}
	$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? clean_referrer( wp_unslash( $_SERVER['HTTP_REFERER'] ), $path ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in clean_referrer().
	record( $path, $referrer );
}

/**
 * Add one hit for a path.
 *
 * @param string $path     Decoded path.
 * @param string $referrer Cleaned referrer, '' for none.
 */
function record( $path, $referrer ) {
	global $wpdb;
	$now = current_time( 'mysql', true );
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO %i (path_hash, path, referrer, hits, first_seen, last_seen) VALUES (%s, %s, %s, 1, %s, %s)
			ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) = \'\', referrer, VALUES(referrer))',
			table( 'not_found' ),
			md5( $path ),
			$path,
			$referrer,
			$now,
			$now
		)
	);
	if ( ! get_transient( PRUNE_FLAG ) ) {
		set_transient( PRUNE_FLAG, 1, DAY_IN_SECONDS );
		prune();
	}
}

/**
 * Drop rows past the age limit, then the least recently seen past the cap.
 */
function prune() {
	global $wpdb;
	$s = settings();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', table( 'not_found' ), gmdate( 'Y-m-d H:i:s', time() - $s['max_days'] * DAY_IN_SECONDS ) ) );
	$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM %i ORDER BY last_seen DESC LIMIT 1 OFFSET %d', table( 'not_found' ), $s['max_rows'] - 1 ) );
	if ( $cutoff ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', table( 'not_found' ), $cutoff ) );
	}
}

/**
 * The most-hit paths, with whether a redirect already covers each.
 *
 * @param int    $limit Rows.
 * @param string $since Only rows seen since this UTC date-time, or ''.
 * @return array{total: int, rows: array[]}
 */
function top( $limit = 50, $since = '' ) {
	global $wpdb;
	if ( ! table_ready() ) {
		return array(
			'total' => 0,
			'rows'  => array(),
		);
	}
	$limit = min( 500, max( 1, (int) $limit ) );
	$since = '' !== $since ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $since ) ) : '1970-01-01 00:00:00';
	$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT path, referrer, hits, first_seen, last_seen FROM %i WHERE last_seen >= %s ORDER BY hits DESC, last_seen DESC LIMIT %d', table( 'not_found' ), $since, $limit ), ARRAY_A );
	$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_seen >= %s', table( 'not_found' ), $since ) );
	$have  = \TGD_Site_Kit\Redirects\ids_for_paths( array_column( $rows, 'path' ) );
	$out   = array();
	foreach ( $rows as $row ) {
		$out[] = array(
			'path'       => $row['path'],
			'referrer'   => '' !== $row['referrer'] ? $row['referrer'] : null,
			'hits'       => (int) $row['hits'],
			'first_seen' => mysql_to_rfc3339( $row['first_seen'] ),
			'last_seen'  => mysql_to_rfc3339( $row['last_seen'] ),
			'redirect'   => $have[ \TGD_Site_Kit\Redirects\match_key( $row['path'] ) ] ?? null,
		);
	}
	return array(
		'total' => $total,
		'rows'  => $out,
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
		'not_found_saved'   => __( '404 log settings saved.', 'tgd-site-kit' ),
		'not_found_cleared' => __( '404 log cleared.', 'tgd-site-kit' ),
	);
}

/**
 * Save the settings.
 */
function handle_settings() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_not_found_settings' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	update_option(
		OPTION,
		array(
			'max_rows' => isset( $_POST['max_rows'] ) ? absint( $_POST['max_rows'] ) : 2000,
			'max_days' => isset( $_POST['max_days'] ) ? absint( $_POST['max_days'] ) : 90,
		),
		false
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	delete_transient( PRUNE_FLAG );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'not_found_saved', 'not_found' ) );
	exit;
}

/**
 * Empty the log.
 */
function handle_clear() {
	global $wpdb;
	\TGD_Site_Kit\check_owner_write( 'tgdsk_not_found_clear' );
	$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', table( 'not_found' ) ) );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'not_found_cleared', 'not_found' ) );
	exit;
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s   = settings();
	$top = top( 100 );
	?>
	<h2><?php esc_html_e( '404 log', 'tgd-site-kit' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Front-end 404s by path, most hits first. Bot probes are not recorded, and neither are IP addresses or user agents. Jarvis reads this to propose redirects.', 'tgd-site-kit' ); ?></p>
	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Path', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Hits', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Last referrer', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'First seen', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Last seen', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Redirect', 'tgd-site-kit' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $top['rows'] as $row ) : ?>
			<tr>
				<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
				<td><?php echo esc_html( (string) $row['hits'] ); ?></td>
				<td><?php echo esc_html( (string) $row['referrer'] ); ?></td>
				<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $row['first_seen'] ) ) ); ?></td>
				<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $row['last_seen'] ) ) ); ?></td>
				<td><?php echo $row['redirect'] ? esc_html( '#' . $row['redirect'] ) : '-'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<?php
		/* translators: %d: number of paths */
		echo esc_html( sprintf( __( '%d paths recorded.', 'tgd-site-kit' ), $top['total'] ) );
		?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_not_found_settings">
		<?php wp_nonce_field( 'tgdsk_not_found_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Keep', 'tgd-site-kit' ); ?></th>
				<td>
					<?php esc_html_e( 'at most', 'tgd-site-kit' ); ?> <input type="number" min="100" name="max_rows" class="small-text" value="<?php echo esc_attr( (string) $s['max_rows'] ); ?>"> <?php esc_html_e( 'paths, none unseen for more than', 'tgd-site-kit' ); ?>
					<input type="number" min="7" name="max_days" class="small-text" value="<?php echo esc_attr( (string) $s['max_days'] ); ?>"> <?php esc_html_e( 'days', 'tgd-site-kit' ); ?>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'tgd-site-kit' ) ); ?>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_not_found_clear">
		<?php wp_nonce_field( 'tgdsk_not_found_clear' ); ?>
		<?php submit_button( __( 'Clear the log', 'tgd-site-kit' ), 'delete', 'submit', false ); ?>
	</form>
	<?php
}
