<?php
/**
 * Module: redirects.
 *
 * One place for a site's 301s: exact and regex sources, 301, 302, 307, 308
 * and 410, query handling per redirect, hit counts and the last hit. Imports
 * from Redirection and from SmartCrawl. See includes/redirects/.
 *
 * **Sends nothing while Redirection is active, or while SmartCrawl's
 * redirects are switched on**: two plugins answering the same URL is how a
 * redirect chain or a loop starts. Its screen, imports and the Jarvis route
 * still work, so the redirects can be set up and checked before the switch.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../redirects/store.php';
require_once __DIR__ . '/../redirects/match.php';
require_once __DIR__ . '/../redirects/import.php';

const REDIRECTION_BASENAME = 'redirection/redirection.php';

\TGD_Site_Kit\register_module(
	'redirects',
	array(
		'label'       => fn() => __( 'Redirects', 'tgd-site-kit' ),
		'description' => fn() => __( 'Exact and regex redirects (301, 302, 307, 308, 410) with hit counts, and imports from Redirection and SmartCrawl. Sends nothing while Redirection is active or SmartCrawl’s redirects are on.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Whether Redirection is active.
 *
 * @return bool
 */
function redirection_active() {
	return \TGD_Site_Kit\plugin_active( REDIRECTION_BASENAME ) || defined( 'REDIRECTION_VERSION' );
}

/**
 * Whether SmartCrawl is active with its redirects switched on.
 *
 * @return bool
 */
function smartcrawl_redirects_active() {
	if ( ! \TGD_Site_Kit\SEO\smartcrawl_active() ) {
		return false;
	}
	$advanced = get_option( 'wds-advanced', array() );
	return is_array( $advanced ) && ! empty( $advanced['redirects']['active'] );
}

/**
 * Whether anything stops this module sending redirects (string-free, for boot).
 *
 * @return bool
 */
function is_blocked() {
	return redirection_active() || smartcrawl_redirects_active();
}

/**
 * Reasons the module is holding back.
 *
 * @return string[]
 */
function blockers() {
	$lines = array();
	if ( redirection_active() ) {
		$lines[] = __( 'Redirection is active, so this module sends no redirects. Import its redirects here, check them, then deactivate Redirection.', 'tgd-site-kit' );
	}
	if ( smartcrawl_redirects_active() ) {
		$lines[] = __( 'SmartCrawl’s redirects are switched on, so this module sends no redirects. Import them here, then switch them off in SmartCrawl (Advanced Tools → URL Redirection).', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Add the module's hooks.
 */
function boot() {
	ensure_table();
	add_action( 'admin_post_tgdsk_redirect_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_redirect_delete', __NAMESPACE__ . '\\handle_delete' );
	add_action( 'admin_post_tgdsk_redirects_settings', __NAMESPACE__ . '\\handle_settings' );
	add_action( 'admin_post_tgdsk_redirects_import', __NAMESPACE__ . '\\handle_import' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'tgdsk redirects import', __NAMESPACE__ . '\\cli_import' );
	}
	if ( is_blocked() ) {
		return;
	}
	add_match_hooks();
}

/**
 * Import redirects into TGD Site Kit.
 *
 * ## OPTIONS
 *
 * <source>
 * : `redirection` or `smartcrawl`.
 *
 * [--dry-run]
 * : Count what would change, and write nothing.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Flags.
 */
function cli_import( $args, $assoc_args ) {
	$dry    = ! empty( $assoc_args['dry-run'] );
	$report = 'smartcrawl' === ( $args[0] ?? '' ) ? import_smartcrawl( $dry ) : import_redirection( $dry );
	\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Notice wording.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'redirect_saved'    => __( 'Redirect saved.', 'tgd-site-kit' ),
		'redirect_deleted'  => __( 'Redirect deleted.', 'tgd-site-kit' ),
		'redirect_refused'  => __( 'The redirect was not saved. The reason is below.', 'tgd-site-kit' ),
		'redirects_options' => __( 'Redirect settings saved.', 'tgd-site-kit' ),
		'redirects_import'  => __( 'Import finished. The report is below.', 'tgd-site-kit' ),
	);
}

/**
 * Fields of the add/edit form, from the request.
 *
 * @param array $post Unslashed $_POST.
 * @return array
 */
function form_input( array $post ) {
	return array(
		'source'         => (string) ( $post['source'] ?? '' ),
		'target'         => (string) ( $post['target'] ?? '' ),
		'status'         => (int) ( $post['status'] ?? 301 ),
		'is_regex'       => ! empty( $post['is_regex'] ),
		'query_mode'     => sanitize_key( (string) ( $post['query_mode'] ?? 'exact' ) ),
		'match_case'     => ! empty( $post['match_case'] ),
		'match_trailing' => ! empty( $post['match_trailing'] ),
		'enabled'        => ! empty( $post['enabled'] ),
		'note'           => (string) ( $post['note'] ?? '' ),
	);
}

/**
 * Save a redirect from the form.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirect_save' );
	$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in check_owner_write(); validate() checks every field.
	$id    = (int) ( $post['id'] ?? 0 );
	$input = form_input( (array) $post );
	$row   = validate( $input, $id );
	if ( is_wp_error( $row ) ) {
		set_transient( 'tgdsk_redirect_refused_' . get_current_user_id(), array( 'message' => $row->get_error_message() ) + $input, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'edit', $id, \TGD_Site_Kit\settings_url( 'redirect_refused', 'redirects' ) ) );
		exit;
	}
	if ( $id && get( $id ) ) {
		update( $id, $row );
	} else {
		insert( $row );
	}
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirect_saved', 'redirects' ) );
	exit;
}

/**
 * Delete a redirect from the screen.
 */
function handle_delete() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirect_delete' );
	delete_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirect_deleted', 'redirects' ) );
	exit;
}

/**
 * Save the module settings.
 */
function handle_settings() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirects_settings' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	save_settings(
		array(
			'cache_hours' => isset( $_POST['cache_hours'] ) ? absint( $_POST['cache_hours'] ) : 0,
			'track_hits'  => ! empty( $_POST['track_hits'] ),
		)
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirects_options', 'redirects' ) );
	exit;
}

/**
 * Run an import from the screen.
 */
function handle_import() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirects_import' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	$dry    = ! empty( $_POST['dry_run'] );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$report = 'smartcrawl' === $source ? import_smartcrawl( $dry ) : import_redirection( $dry );
	set_transient( 'tgdsk_redirects_import_report', $report, HOUR_IN_SECONDS );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirects_import', 'redirects' ) );
	exit;
}

/**
 * Print an import report.
 *
 * @param array $report Report.
 */
function render_report( array $report ) {
	$name = 'smartcrawl' === $report['source'] ? 'SmartCrawl' : 'Redirection';
	?>
	<h4>
		<?php
		/* translators: %s: plugin name */
		echo esc_html( sprintf( $report['dry_run'] ? __( 'Dry run from %s', 'tgd-site-kit' ) : __( 'Import from %s', 'tgd-site-kit' ), $name ) );
		?>
		<span class="description">(<?php echo esc_html( wp_date( 'M j, Y g:ia', $report['time'] ) ); ?>)</span>
	</h4>
	<p>
		<?php
		/* translators: 1: seen, 2: new, 3: updated, 4: unchanged */
		echo esc_html( sprintf( __( '%1$d seen: %2$d new, %3$d updated, %4$d already the same.', 'tgd-site-kit' ), $report['seen'], $report['written'], $report['updated'], $report['same'] ) );
		?>
	</p>
	<?php
	foreach ( array(
		'skipped' => __( 'Not carried over', 'tgd-site-kit' ),
		'local'   => __( 'Worth a look', 'tgd-site-kit' ),
		'gone'    => __( 'No longer at the source', 'tgd-site-kit' ),
	) as $key => $heading ) {
		if ( $report[ $key ] ) {
			echo '<p><strong>' . esc_html( $heading ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $report[ $key ] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
	}
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	// Read only: which row to edit.
	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$refused = get_transient( 'tgdsk_redirect_refused_' . get_current_user_id() );
	if ( is_array( $refused ) ) {
		delete_transient( 'tgdsk_redirect_refused_' . get_current_user_id() );
	}
	$editing = is_array( $refused ) ? $refused : ( $edit_id ? get( $edit_id ) : null );
	$editing = is_array( $editing ) ? $editing : array();
	$report  = get_transient( 'tgdsk_redirects_import_report' );
	$s       = settings();
	$rows    = all();
	$field   = fn( $key, $fallback = '' ) => $editing[ $key ] ?? $fallback;
	?>
	<h2><?php esc_html_e( 'Redirects', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<p class="description"><?php esc_html_e( 'Never redirected: the dashboard, login, the REST API, XML-RPC, cron, and any address that is published content (a redirect there would hide a live page).', 'tgd-site-kit' ); ?></p>

	<?php if ( is_array( $refused ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $refused['message'] ); ?></p></div>
	<?php endif; ?>

	<h3><?php echo $edit_id ? esc_html__( 'Edit redirect', 'tgd-site-kit' ) : esc_html__( 'Add a redirect', 'tgd-site-kit' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_redirect_save">
		<input type="hidden" name="id" value="<?php echo esc_attr( (string) $edit_id ); ?>">
		<?php wp_nonce_field( 'tgdsk_redirect_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-redirect-source"><?php esc_html_e( 'Source', 'tgd-site-kit' ); ?></label></th>
				<td>
					<input type="text" id="tgdsk-redirect-source" name="source" class="large-text code" value="<?php echo esc_attr( (string) $field( 'source' ) ); ?>" placeholder="/old-page/">
					<label><input type="checkbox" name="is_regex" value="1"<?php checked( (bool) $field( 'is_regex', false ) ); ?>> <?php esc_html_e( 'Regular expression (matched against the path and query; the target may use $1)', 'tgd-site-kit' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-redirect-target"><?php esc_html_e( 'Target', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-redirect-target" name="target" class="large-text code" value="<?php echo esc_attr( (string) $field( 'target' ) ); ?>" placeholder="/new-page/"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'tgd-site-kit' ); ?></th>
				<td>
					<select name="status">
						<?php foreach ( STATUSES as $code ) : ?>
							<option value="<?php echo esc_attr( (string) $code ); ?>"<?php selected( (int) $field( 'status', 301 ), $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( '410 needs no target: the page answers “gone”.', 'tgd-site-kit' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Matching', 'tgd-site-kit' ); ?></th>
				<td>
					<select name="query_mode">
						<option value="exact"<?php selected( $field( 'query_mode', 'exact' ), 'exact' ); ?>><?php esc_html_e( 'Query must match exactly', 'tgd-site-kit' ); ?></option>
						<option value="ignore"<?php selected( $field( 'query_mode' ), 'ignore' ); ?>><?php esc_html_e( 'Ignore the query', 'tgd-site-kit' ); ?></option>
						<option value="pass"<?php selected( $field( 'query_mode' ), 'pass' ); ?>><?php esc_html_e( 'Ignore the query and pass it on to the target', 'tgd-site-kit' ); ?></option>
					</select><br>
					<label><input type="checkbox" name="match_case" value="1"<?php checked( (bool) $field( 'match_case', false ) ); ?>> <?php esc_html_e( 'Case-sensitive', 'tgd-site-kit' ); ?></label>
					&nbsp; <label><input type="checkbox" name="match_trailing" value="1"<?php checked( (bool) $field( 'match_trailing', false ) ); ?>> <?php esc_html_e( 'Trailing slash must match', 'tgd-site-kit' ); ?></label>
					&nbsp; <label><input type="checkbox" name="enabled" value="1"<?php checked( (bool) $field( 'enabled', true ) ); ?>> <?php esc_html_e( 'On', 'tgd-site-kit' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-redirect-note"><?php esc_html_e( 'Note', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-redirect-note" name="note" class="regular-text" value="<?php echo esc_attr( (string) $field( 'note' ) ); ?>"></td>
			</tr>
		</table>
		<?php submit_button( $edit_id ? __( 'Save redirect', 'tgd-site-kit' ) : __( 'Add redirect', 'tgd-site-kit' ) ); ?>
	</form>

	<h3>
		<?php
		/* translators: %d: number of redirects */
		echo esc_html( sprintf( __( 'Redirects (%d)', 'tgd-site-kit' ), count( $rows ) ) );
		?>
	</h3>
	<table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Source', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Target', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Status', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Hits', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Last hit', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'From', 'tgd-site-kit' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr<?php echo $row['enabled'] ? '' : ' style="opacity:.6"'; ?>>
				<td><code><?php echo esc_html( $row['source'] ); ?></code><?php echo $row['is_regex'] ? ' <em>regex</em>' : ''; ?><?php echo $row['enabled'] ? '' : ' <em>' . esc_html__( 'off', 'tgd-site-kit' ) . '</em>'; ?><?php echo '' !== $row['note'] ? '<br><span class="description">' . esc_html( $row['note'] ) . '</span>' : ''; ?></td>
				<td><code><?php echo esc_html( '' !== $row['target'] ? $row['target'] : '-' ); ?></code></td>
				<td><?php echo esc_html( (string) $row['status'] ); ?></td>
				<td><?php echo esc_html( (string) $row['hits'] ); ?></td>
				<td><?php echo esc_html( $row['last_hit'] ? wp_date( 'M j, Y', strtotime( $row['last_hit'] ) ) : '-' ); ?></td>
				<td><?php echo esc_html( '' !== $row['origin'] ? $row['origin'] : '-' ); ?></td>
				<td>
					<a href="<?php echo esc_url( add_query_arg( 'edit', $row['id'], \TGD_Site_Kit\settings_url( '', 'redirects' ) ) ); ?>"><?php esc_html_e( 'Edit', 'tgd-site-kit' ); ?></a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="tgdsk_redirect_delete">
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
						<?php wp_nonce_field( 'tgdsk_redirect_delete' ); ?>
						<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'tgd-site-kit' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Import', 'tgd-site-kit' ); ?></h3>
	<p><?php esc_html_e( 'Copies redirects from Redirection or SmartCrawl. Their data is never changed. Safe to run again: a redirect edited here since the last import is kept, and anything that can’t be carried over is listed.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_redirects_import">
		<?php wp_nonce_field( 'tgdsk_redirects_import' ); ?>
		<select name="source">
			<option value="redirection">Redirection</option>
			<option value="smartcrawl">SmartCrawl</option>
		</select>
		<button type="submit" name="dry_run" value="1" class="button"><?php esc_html_e( 'Dry run', 'tgd-site-kit' ); ?></button>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'tgd-site-kit' ); ?></button>
	</form>
	<?php
	if ( is_array( $report ) ) {
		render_report( $report );
	}
	?>

	<h3><?php esc_html_e( 'Settings', 'tgd-site-kit' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_redirects_settings">
		<?php wp_nonce_field( 'tgdsk_redirects_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-redirect-cache"><?php esc_html_e( 'Browser cache for 301 and 308', 'tgd-site-kit' ); ?></label></th>
				<td><input type="number" min="0" id="tgdsk-redirect-cache" name="cache_hours" class="small-text" value="<?php echo esc_attr( (string) $s['cache_hours'] ); ?>"> <?php esc_html_e( 'hours (0 for none)', 'tgd-site-kit' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Hits', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="track_hits" value="1"<?php checked( $s['track_hits'] ); ?>> <?php esc_html_e( 'Count hits and the last hit', 'tgd-site-kit' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'tgd-site-kit' ) ); ?>
	</form>
	<?php
}
