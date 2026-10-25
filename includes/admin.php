<?php
/**
 * The settings screen: Settings → TGD Site Kit.
 *
 * Owner only. One form switches modules on and off; each enabled module can add
 * its own section below it, and posts its own forms to admin-post.php with its
 * own nonce. Every write checks OWNER_CAP and a nonce before touching anything.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit;

defined( 'ABSPATH' ) || exit;

const SETTINGS_SLUG = 'tgd-site-kit';

add_action( 'admin_menu', __NAMESPACE__ . '\\add_settings_page' );
add_action( 'admin_post_tgdsk_modules', __NAMESPACE__ . '\\handle_modules_form' );

/**
 * Register the settings page.
 */
function add_settings_page() {
	add_options_page(
		__( 'TGD Site Kit', 'tgd-site-kit' ),
		__( 'TGD Site Kit', 'tgd-site-kit' ),
		OWNER_CAP,
		SETTINGS_SLUG,
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/**
 * URL of the settings page, optionally with a notice code for the redirect.
 *
 * @param string $notice Notice code, or ''.
 * @param string $tab    Module id whose section to show, or '' for the list.
 * @return string
 */
function settings_url( $notice = '', $tab = '' ) {
	$args = array( 'page' => SETTINGS_SLUG );
	if ( $tab ) {
		$args['tab'] = $tab;
	}
	if ( $notice ) {
		$args['tgdsk_notice'] = $notice;
	}
	return add_query_arg( $args, admin_url( 'options-general.php' ) );
}

/**
 * Refuse a write unless the caller is the owner and the nonce is good.
 *
 * Dies rather than redirecting: a failed check here is a forged or stale
 * request, and there is nothing useful to send it back to.
 *
 * @param string $action Nonce action.
 */
function check_owner_write( $action ) {
	if ( ! current_user_can( OWNER_CAP ) ) {
		wp_die( esc_html__( 'You do not have permission to change TGD Site Kit settings.', 'tgd-site-kit' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $action );
}

/**
 * Save which modules are on.
 */
function handle_modules_form() {
	check_owner_write( 'tgdsk_modules' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$ids = isset( $_POST['tgdsk_modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['tgdsk_modules'] ) ) : array();
	set_enabled_modules( $ids );
	wp_safe_redirect( settings_url( 'modules_saved' ) );
	exit;
}

/**
 * Notice text for a redirect code. Modules add theirs through the filter.
 *
 * @param string $code Notice code from the query string.
 * @return string Plain text, or '' for an unknown code.
 */
function notice_text( $code ) {
	$notices = apply_filters(
		'tgdsk_settings_notices',
		array(
			'modules_saved' => __( 'Modules saved.', 'tgd-site-kit' ),
		)
	);
	return $notices[ $code ] ?? '';
}

/**
 * Render the settings page.
 */
function render_settings_page() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	// Read only: which section to show and which notice to print.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$notice = isset( $_GET['tgdsk_notice'] ) ? notice_text( sanitize_key( wp_unslash( $_GET['tgdsk_notice'] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$enabled = enabled_modules();
	if ( $tab && ( ! in_array( $tab, $enabled, true ) || ! is_callable( modules()[ $tab ]['settings'] ) ) ) {
		$tab = '';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'TGD Site Kit', 'tgd-site-kit' ); ?></h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<nav class="nav-tab-wrapper">
			<a href="<?php echo esc_url( settings_url() ); ?>" class="nav-tab<?php echo '' === $tab ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Modules', 'tgd-site-kit' ); ?></a>
			<?php foreach ( $enabled as $id ) : ?>
				<?php if ( is_callable( modules()[ $id ]['settings'] ) ) : ?>
					<a href="<?php echo esc_url( settings_url( '', $id ) ); ?>" class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( module_text( $id, 'label' ) ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>

		<?php if ( '' === $tab ) : ?>
			<?php render_modules_form( $enabled ); ?>
		<?php else : ?>
			<?php call_user_func( modules()[ $tab ]['settings'] ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The module switches, with any reason an enabled module is holding back.
 *
 * @param string[] $enabled Enabled module ids.
 */
function render_modules_form( array $enabled ) {
	?>
	<p><?php esc_html_e( 'Every module is off until it is switched on here. Switching one off stops its output and keeps its settings and data.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_modules">
		<?php wp_nonce_field( 'tgdsk_modules' ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( modules() as $id => $module ) : ?>
				<?php $on = in_array( $id, $enabled, true ); ?>
				<tr>
					<th scope="row"><?php echo esc_html( module_text( $id, 'label' ) ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="tgdsk_modules[]" value="<?php echo esc_attr( $id ); ?>"<?php checked( $on ); ?>>
							<?php esc_html_e( 'On', 'tgd-site-kit' ); ?>
						</label>
						<p class="description"><?php echo esc_html( module_text( $id, 'description' ) ); ?></p>
						<?php
						if ( $on && is_callable( $module['notices'] ) ) {
							foreach ( (array) call_user_func( $module['notices'] ) as $line ) {
								echo '<p class="notice notice-warning inline" style="padding:6px 10px">' . esc_html( $line ) . '</p>';
							}
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button( __( 'Save modules', 'tgd-site-kit' ) ); ?>
	</form>
	<?php
}
