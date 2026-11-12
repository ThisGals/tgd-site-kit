<?php
/**
 * Module: redirects.
 *
 * One place for a site's 301s: exact and regex sources, 301, 302, 307, 308
 * and 410, query handling per redirect, hit counts and the last hit. A
 * redirect may replace a published page when it says so. Automatic redirects
 * when a published address changes, a list of chains and loops with a fix,
 * and CSV import and export. See includes/redirects/.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Redirects;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../redirects/store.php';
require_once __DIR__ . '/../redirects/match.php';
require_once __DIR__ . '/../redirects/chains.php';
require_once __DIR__ . '/../redirects/auto.php';
require_once __DIR__ . '/../redirects/csv.php';

\TGD_Site_Kit\register_module(
	'redirects',
	array(
		'label'       => fn() => __( 'Redirects', 'tgd-site-kit' ),
		'description' => fn() => __( 'Exact and regex redirects (301, 302, 307, 308, 410) with hit counts, automatic redirects when a published address changes, chain fixing, and CSV import and export.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	ensure_table();
	add_action( 'admin_post_tgdsk_redirect_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_redirect_delete', __NAMESPACE__ . '\\handle_delete' );
	add_action( 'admin_post_tgdsk_redirects_settings', __NAMESPACE__ . '\\handle_settings' );
	add_action( 'admin_post_tgdsk_redirects_chains', __NAMESPACE__ . '\\handle_chains' );
	add_action( 'admin_post_tgdsk_redirects_export', __NAMESPACE__ . '\\handle_export' );
	add_action( 'admin_post_tgdsk_redirects_csv', __NAMESPACE__ . '\\handle_csv' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'tgdsk redirects export', __NAMESPACE__ . '\\cli_export' );
		\WP_CLI::add_command( 'tgdsk redirects import', __NAMESPACE__ . '\\cli_import' );
		\WP_CLI::add_command( 'tgdsk redirects chains', __NAMESPACE__ . '\\cli_chains' );
	}
	add_match_hooks();
	add_auto_hooks();
}

/**
 * Write every redirect as CSV.
 *
 * ## OPTIONS
 *
 * [<file>]
 * : Where to write it. Without one, the CSV goes to standard output.
 *
 * @param array $args Positional arguments.
 */
function cli_export( $args ) {
	$csv = export_csv();
	if ( ! empty( $args[0] ) ) {
		file_put_contents( $args[0], $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a CLI command writing the file it was given.
		\WP_CLI::success( sprintf( '%d bytes written to %s', strlen( $csv ), $args[0] ) );
		return;
	}
	echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV to standard output.
}

/**
 * Import redirects from a CSV file.
 *
 * ## OPTIONS
 *
 * <file>
 * : The CSV file.
 *
 * [--dry-run]
 * : Report what would be added, skipped and refused, and write nothing.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Flags.
 */
function cli_import( $args, $assoc_args ) {
	$content = (string) file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
	\WP_CLI::line( wp_json_encode( import_csv( $content, ! empty( $assoc_args['dry-run'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/**
 * List redirect chains and loops, or fix the chains.
 *
 * ## OPTIONS
 *
 * [--fix]
 * : Point every chain straight at its end.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Flags.
 */
function cli_chains( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
	$out = ! empty( $assoc_args['fix'] ) ? fix_all_chains() : find_chains();
	\WP_CLI::line( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
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
		'redirects_chains'  => __( 'Chains fixed. What changed is below.', 'tgd-site-kit' ),
		'redirects_csv'     => __( 'CSV read. The report is below.', 'tgd-site-kit' ),
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
		'source'             => (string) ( $post['source'] ?? '' ),
		'target'             => (string) ( $post['target'] ?? '' ),
		'status'             => (int) ( $post['status'] ?? 301 ),
		'is_regex'           => ! empty( $post['is_regex'] ),
		'query_mode'         => sanitize_key( (string) ( $post['query_mode'] ?? 'pass' ) ),
		'match_case'         => ! empty( $post['match_case'] ),
		'match_trailing'     => ! empty( $post['match_trailing'] ),
		'enabled'            => ! empty( $post['enabled'] ),
		'replaces_published' => ! empty( $post['replaces_published'] ),
		'note'               => (string) ( $post['note'] ?? '' ),
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
		$id = insert( $row );
	}
	forget_skipped( $id );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirect_saved', 'redirects' ) );
	exit;
}

/**
 * Forget a request-time skip once the redirect has been saved again (with
 * the flag, or with another source).
 *
 * @param int $id Redirect ID.
 */
function forget_skipped( $id ) {
	$skipped = get_option( SKIPPED_OPTION, array() );
	if ( is_array( $skipped ) && isset( $skipped[ $id ] ) ) {
		unset( $skipped[ $id ] );
		update_option( SKIPPED_OPTION, $skipped, false );
	}
}

/**
 * Delete a redirect from the screen.
 */
function handle_delete() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirect_delete' );
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	delete_row( $id );
	forget_skipped( $id );
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
			'auto'        => ! empty( $_POST['auto'] ),
		)
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirects_options', 'redirects' ) );
	exit;
}

/**
 * Fix one chain, or all of them.
 */
function handle_chains() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirects_chains' );
	$start  = isset( $_POST['start'] ) ? absint( $_POST['start'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$result = $start ? fix_chain( $start ) : fix_all_chains();
	set_transient( 'tgdsk_redirects_chain_fix_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirects_chains', 'redirects' ) );
	exit;
}

/**
 * Send every redirect as a CSV download.
 */
function handle_export() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirects_export' );
	$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="redirects-' . $host . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
	echo export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV download.
	exit;
}

/**
 * Read an uploaded CSV: a dry run, or the import of the file the last dry
 * run read (kept for this user, so it needn't be uploaded twice).
 */
function handle_csv() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_redirects_csv' );
	$key = 'tgdsk_redirects_csv_' . get_current_user_id();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$import = ! empty( $_POST['import'] );
	$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	if ( $import ) {
		$kept = get_transient( $key );
		if ( ! is_array( $kept ) || md5( (string) $kept['content'] ) !== $token ) {
			$report = array( 'error' => __( 'The file from the dry run has expired. Upload it again for a new dry run.', 'tgd-site-kit' ) );
		} else {
			$report = import_csv( (string) $kept['content'], false ) + array( 'name' => $kept['name'] );
			delete_transient( $key );
		}
	} else {
		$file = $_FILES['csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- an upload, read as text and validated row by row.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$report = array( 'error' => __( 'No file was uploaded.', 'tgd-site-kit' ) );
		} else {
			$content = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded file.
			$name    = sanitize_file_name( (string) $file['name'] );
			$report  = import_csv( $content, true ) + array(
				'name'  => $name,
				'token' => md5( $content ),
			);
			set_transient(
				$key,
				array(
					'content' => $content,
					'name'    => $name,
				),
				HOUR_IN_SECONDS
			);
		}
	}
	set_transient( $key . '_report', $report, HOUR_IN_SECONDS );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'redirects_csv', 'redirects' ) );
	exit;
}

/**
 * Print a CSV report.
 *
 * @param array $report From import_csv(), with the file name.
 */
function render_csv_report( array $report ) {
	if ( '' !== (string) ( $report['error'] ?? '' ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $report['error'] ) . '</p></div>';
		return;
	}
	$labels = array(
		'add'    => $report['dry_run'] ? __( 'Would add', 'tgd-site-kit' ) : __( 'Added', 'tgd-site-kit' ),
		'skip'   => __( 'Skipped', 'tgd-site-kit' ),
		'refuse' => __( 'Refused', 'tgd-site-kit' ),
	);
	?>
	<h4>
		<?php
		/* translators: 1: file name, 2: date */
		echo esc_html( sprintf( $report['dry_run'] ? __( 'Dry run of %1$s (%2$s)', 'tgd-site-kit' ) : __( 'Import of %1$s (%2$s)', 'tgd-site-kit' ), $report['name'] ?? '', wp_date( 'M j, Y g:ia', (int) $report['time'] ) ) );
		?>
	</h4>
	<p>
		<?php
		/* translators: 1: added, 2: skipped, 3: refused */
		echo esc_html( sprintf( $report['dry_run'] ? __( '%1$d would be added, %2$d skipped, %3$d refused. Nothing has been written yet.', 'tgd-site-kit' ) : __( '%1$d added, %2$d skipped, %3$d refused.', 'tgd-site-kit' ), $report['added'], $report['skipped'], $report['refused'] ) );
		if ( $report['unknown'] ) {
			/* translators: %s: column names */
			echo ' ', esc_html( sprintf( __( 'Columns not used: %s.', 'tgd-site-kit' ), implode( ', ', $report['unknown'] ) ) );
		}
		?>
	</p>
	<table class="widefat striped" style="max-width:1100px">
		<thead><tr>
			<th><?php esc_html_e( 'Line', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Source', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Target', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Result', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Why', 'tgd-site-kit' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $report['rows'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( (string) $row['line'] ); ?></td>
				<td><code><?php echo esc_html( $row['source'] ); ?></code></td>
				<td><code><?php echo esc_html( $row['target'] ); ?></code></td>
				<td><?php echo esc_html( $labels[ $row['action'] ] ?? $row['action'] ); ?></td>
				<td><?php echo esc_html( $row['reason'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $report['dry_run'] && $report['added'] && ! empty( $report['token'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tgdsk_redirects_csv">
			<input type="hidden" name="import" value="1">
			<input type="hidden" name="token" value="<?php echo esc_attr( $report['token'] ); ?>">
			<?php wp_nonce_field( 'tgdsk_redirects_csv' ); ?>
			<?php
			/* translators: %d: number of redirects */
			submit_button( sprintf( _n( 'Import the %d redirect above', 'Import the %d redirects above', $report['added'], 'tgd-site-kit' ), $report['added'] ) );
			?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * What the listing says about the published content a redirect covers: what
 * a flagged one hides, or why an unflagged one isn't being sent.
 *
 * @param array $row     Shaped row.
 * @param array $skipped Request-time skips, id => {path, time}.
 * @return string Plain text, or ''.
 */
function hides_line( array $row, array $skipped ) {
	$hides = hidden_by( $row );
	$names = array_map( __NAMESPACE__ . '\\describe_item', array_slice( array_values( $hides ), 0, 5 ) );
	$more  = count( $hides ) > 5 ? ' …' : '';
	if ( $row['replaces_published'] ) {
		if ( ! $hides ) {
			return __( 'Replaces a published page, but none is at this address now.', 'tgd-site-kit' );
		}
		/* translators: 1: number hidden, 2: the items */
		return sprintf( _n( 'Hides %1$d published item: %2$s', 'Hides %1$d published items: %2$s', count( $hides ), 'tgd-site-kit' ), count( $hides ), implode( ', ', $names ) . $more );
	}
	if ( $hides ) {
		/* translators: %s: the items */
		return sprintf( __( 'Not sent where the address is published now: %s. Tick “Replaces a published page” to send it anyway, or delete it.', 'tgd-site-kit' ), implode( ', ', $names ) . $more );
	}
	if ( isset( $skipped[ $row['id'] ] ) ) {
		/* translators: 1: path, 2: date */
		return sprintf( __( 'Skipped at %1$s on %2$s, where something was published at the time.', 'tgd-site-kit' ), $skipped[ $row['id'] ]['path'], wp_date( 'M j, Y', (int) $skipped[ $row['id'] ]['time'] ) );
	}
	return '';
}

/**
 * The chains and loops section.
 */
function render_chains() {
	$found = find_chains();
	$fixed = get_transient( 'tgdsk_redirects_chain_fix_' . get_current_user_id() );
	if ( is_array( $fixed ) ) {
		delete_transient( 'tgdsk_redirects_chain_fix_' . get_current_user_id() );
	}
	$arrow = fn( array $chain ) => implode( ' → ', $chain['hops'] ) . ' → ' . ( 'target' === $chain['ends'] ? $chain['end'] : ( 'gone' === $chain['ends'] ? '410' : $chain['hops'][0] ) );
	?>
	<h3><?php esc_html_e( 'Chains and loops', 'tgd-site-kit' ); ?></h3>
	<?php if ( is_array( $fixed ) ) : ?>
		<div class="notice notice-success inline"><p>
			<?php
			/* translators: %s: redirect IDs */
			echo esc_html( $fixed['fixed'] ? sprintf( __( 'Pointed straight at the end: redirects %s.', 'tgd-site-kit' ), implode( ', ', $fixed['fixed'] ) ) : __( 'Nothing needed changing.', 'tgd-site-kit' ) );
			?>
		</p>
		<?php foreach ( $fixed['refused'] as $line ) : ?>
			<p><?php echo esc_html( $line ); ?></p>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( 'A redirect whose target is itself redirected sends visitors through extra hops. Pointing it straight at the end changes only the redirects in that chain.', 'tgd-site-kit' ); ?>
		<?php
		if ( $found['left_out'] ) {
			/* translators: %d: number of regex redirects */
			echo ' ', esc_html( sprintf( _n( '%d regex redirect uses a backreference ($1) in its target, so where it leads depends on the request; it is left out here.', '%d regex redirects use a backreference ($1) in their targets, so where they lead depends on the request; they are left out here.', $found['left_out'], 'tgd-site-kit' ), $found['left_out'] ) );
		}
		?>
	</p>
	<?php if ( ! $found['chains'] && ! $found['loops'] ) : ?>
		<p><?php esc_html_e( 'No chains or loops.', 'tgd-site-kit' ); ?></p>
		<?php
		return;
	endif;
	?>
	<table class="widefat striped" style="max-width:1100px">
		<thead><tr><th><?php esc_html_e( 'Redirects', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Path', 'tgd-site-kit' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $found['loops'] as $loop ) : ?>
			<tr>
				<td><?php echo esc_html( implode( ', ', $loop['ids'] ) ); ?></td>
				<td><code><?php echo esc_html( $arrow( $loop ) ); ?></code><br><strong><?php esc_html_e( 'A loop: visitors never arrive. Edit or switch off one of these.', 'tgd-site-kit' ); ?></strong></td>
				<td></td>
			</tr>
		<?php endforeach; ?>
		<?php foreach ( $found['chains'] as $chain ) : ?>
			<tr>
				<td><?php echo esc_html( implode( ', ', $chain['ids'] ) ); ?></td>
				<td><code><?php echo esc_html( $arrow( $chain ) ); ?></code>
					<?php if ( 'gone' === $chain['ends'] ) : ?>
						<br><span class="description"><?php esc_html_e( 'Ends at a 410. Decide whether the first redirect should be a 410 too.', 'tgd-site-kit' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( 'target' === $chain['ends'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tgdsk_redirects_chains">
							<input type="hidden" name="start" value="<?php echo esc_attr( (string) $chain['start'] ); ?>">
							<?php wp_nonce_field( 'tgdsk_redirects_chains' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Point straight at the end', 'tgd-site-kit' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( array_filter( $found['chains'], fn( $c ) => 'target' === $c['ends'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tgdsk_redirects_chains">
			<?php wp_nonce_field( 'tgdsk_redirects_chains' ); ?>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Fix every chain', 'tgd-site-kit' ); ?></button></p>
		</form>
	<?php endif; ?>
	<?php
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
	$report  = get_transient( 'tgdsk_redirects_csv_' . get_current_user_id() . '_report' );
	$skipped = get_option( SKIPPED_OPTION, array() );
	$skipped = is_array( $skipped ) ? $skipped : array();
	$s       = settings();
	$rows    = all();
	$field   = fn( $key, $fallback = '' ) => $editing[ $key ] ?? $fallback;
	?>
	<h2><?php esc_html_e( 'Redirects', 'tgd-site-kit' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Never redirected: the dashboard, login, the REST API, XML-RPC and cron. An address that is published content is redirected only by a redirect that says it replaces a published page. Attachment pages don’t count as published content.', 'tgd-site-kit' ); ?></p>

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
					<label><input type="checkbox" name="is_regex" value="1"<?php checked( (bool) $field( 'is_regex', false ) ); ?>> <?php esc_html_e( 'Regular expression (matched against the path and query; the target may use $1)', 'tgd-site-kit' ); ?></label><br>
					<label><input type="checkbox" name="replaces_published" value="1"<?php checked( (bool) $field( 'replaces_published', false ) ); ?>> <?php esc_html_e( 'Replaces a published page', 'tgd-site-kit' ); ?></label>
					<p class="description"><?php esc_html_e( 'Tick this to redirect an address where something is published, on purpose: the page stays published, and visitors are sent to the target instead. The list below names what each such redirect hides.', 'tgd-site-kit' ); ?></p>
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
						<option value="pass"<?php selected( $field( 'query_mode', 'pass' ), 'pass' ); ?>><?php esc_html_e( 'Ignore the query and pass it on to the target', 'tgd-site-kit' ); ?></option>
						<option value="ignore"<?php selected( $field( 'query_mode', 'pass' ), 'ignore' ); ?>><?php esc_html_e( 'Ignore the query', 'tgd-site-kit' ); ?></option>
						<option value="exact"<?php selected( $field( 'query_mode', 'pass' ), 'exact' ); ?>><?php esc_html_e( 'Query must match exactly', 'tgd-site-kit' ); ?></option>
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
			<?php $hides = hides_line( $row, $skipped ); ?>
			<tr<?php echo $row['enabled'] ? '' : ' style="opacity:.6"'; ?>>
				<td>
					<code><?php echo esc_html( $row['source'] ); ?></code><?php echo $row['is_regex'] ? ' <em>regex</em>' : ''; ?><?php echo $row['enabled'] ? '' : ' <em>' . esc_html__( 'off', 'tgd-site-kit' ) . '</em>'; ?>
					<?php if ( '' !== $hides ) : ?>
						<br><span class="description"<?php echo $row['replaces_published'] ? '' : ' style="color:#b32d2e"'; ?>><?php echo esc_html( $hides ); ?></span>
					<?php endif; ?>
					<?php echo '' !== $row['note'] ? '<br><span class="description">' . esc_html( $row['note'] ) . '</span>' : ''; ?>
				</td>
				<td><code><?php echo esc_html( '' !== $row['target'] ? $row['target'] : '-' ); ?></code></td>
				<td><?php echo esc_html( (string) $row['status'] ); ?></td>
				<td><?php echo esc_html( (string) $row['hits'] ); ?></td>
				<td><?php echo esc_html( $row['last_hit'] ? wp_date( 'M j, Y', strtotime( $row['last_hit'] ) ) : '-' ); ?></td>
				<td><?php echo esc_html( origin_label( $row['origin'] ) ); ?></td>
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

	<?php render_chains(); ?>

	<h3><?php esc_html_e( 'CSV', 'tgd-site-kit' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:2em">
		<input type="hidden" name="action" value="tgdsk_redirects_export">
		<?php wp_nonce_field( 'tgdsk_redirects_export' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Export every redirect', 'tgd-site-kit' ); ?></button>
	</form>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
		<input type="hidden" name="action" value="tgdsk_redirects_csv">
		<?php wp_nonce_field( 'tgdsk_redirects_csv' ); ?>
		<input type="file" name="csv" accept=".csv,text/csv">
		<button type="submit" class="button"><?php esc_html_e( 'Dry run', 'tgd-site-kit' ); ?></button>
	</form>
	<p class="description"><?php esc_html_e( 'The import needs a header row and matches columns by name: source and target (or from and to, old and new URL), code, match (exact or regex), query (exact, ignore or pass), case_sensitive, trailing_slash, replaces_published, enabled, note. The dry run shows each row it would add, skip or refuse, and why; nothing is written until you import from there. Every row is checked as a redirect added here is, and a duplicate keeps the first.', 'tgd-site-kit' ); ?></p>
	<?php
	if ( is_array( $report ) ) {
		render_csv_report( $report );
	}
	?>

	<h3><?php esc_html_e( 'Settings', 'tgd-site-kit' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_redirects_settings">
		<?php wp_nonce_field( 'tgdsk_redirects_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic redirects', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="auto" value="1"<?php checked( $s['auto'] ); ?>> <?php esc_html_e( 'When a published post, page or term’s address changes, redirect the old address to the new one', 'tgd-site-kit' ); ?></label>
					<p class="description"><?php esc_html_e( 'Child pages and child terms whose address changes with it get one too, and a redirect that pointed at the old address is pointed at the new one, so a rename never leaves a chain. Listed as “Automatic”.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
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
