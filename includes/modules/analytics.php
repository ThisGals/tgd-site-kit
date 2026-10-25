<?php
/**
 * Module: analytics tag.
 *
 * Puts the GA4 tag in the head, replacing what Beehive does on the sites that
 * run it. Switching a site from Beehive to this is a `measurement` change for
 * the work log, so the whole point of this file is that GA receives exactly
 * what it received before.
 *
 * **What Beehive does, read from Beehive Analytics 3.5.3 and checked against
 * the public HTML of a production site running Beehive Pro, 2026-10-25:**
 *
 * - Prints on `wp_head` at the default priority, and on `admin_head` only when
 *   its "track admin pages" setting is on (off by default).
 * - Skips previews and AJAX. Nothing else is skipped by default.
 * - **Logged-in users are tracked, administrators included**, unless their role
 *   is in the "exclude roles" setting, which is empty by default. A network
 *   admin also counts as the pseudo-role `super_admin` for that test.
 * - Loads gtag.js with its own data layer, `beehiveDataLayer`, and its own
 *   command function, `beehive_ga`, rather than core gtag's `dataLayer` and
 *   `gtag`. Kept here verbatim: GA receives nothing different either way, but
 *   anything on a site that pushes events through `beehive_ga()` keeps working,
 *   and a separate layer still cannot collide with Site Kit's or GTM's.
 * - Sends `config` with exactly two parameters, `anonymize_ip` and
 *   `allow_google_signals`, each true or false from its settings. Both are
 *   false by default.
 *
 * Those per-site settings are not visible from outside, so the settings screen
 * imports them from Beehive's own option on the site, while Beehive is still
 * installed. That is the switch procedure: import, check, deactivate Beehive.
 *
 * **Never double-tag.** Nothing is printed while Beehive or Site Kit by Google
 * is active, and on every front-end page the finished HTML is checked for
 * another tag carrying the same measurement ID — a code box in the theme, a
 * pasted snippet, another plugin. If one is there, this module's tag is taken
 * back out and the page and time are recorded for the settings screen.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Analytics;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const OPTION          = 'tgdsk_analytics';
const CONFLICT_OPTION = 'tgdsk_analytics_conflict';
const DATA_LAYER      = 'beehiveDataLayer';
const COMMAND         = 'beehive_ga';
const MARK_OPEN       = '<!-- tgdsk-analytics -->';
const MARK_CLOSE      = '<!-- /tgdsk-analytics -->';

\TGD_Site_Kit\register_module(
	'analytics',
	array(
		'label'       => fn() => __( 'Analytics tag', 'tgd-site-kit' ),
		'description' => fn() => __( 'Prints the GA4 tag in the head, the way Beehive does. Prints nothing while Beehive, Site Kit by Google or another tag for the same ID is present.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	add_action( 'admin_post_tgdsk_analytics_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_analytics_import', __NAMESPACE__ . '\\handle_import' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );

	if ( is_blocked() ) {
		return;
	}
	add_action( 'template_redirect', __NAMESPACE__ . '\\start_duplicate_check', 1 );
	add_action( 'wp_head', __NAMESPACE__ . '\\print_tag' );
	add_action( 'admin_head', __NAMESPACE__ . '\\print_admin_tag' );
}

/**
 * The module's settings, with defaults matching Beehive's.
 *
 * @return array{measurement_id:string, anonymize_ip:bool, allow_google_signals:bool, exclude_roles:string[], track_admin:bool}
 */
function settings() {
	$stored = get_option( OPTION, array() );
	return wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'measurement_id'       => '',
			'anonymize_ip'         => false,
			'allow_google_signals' => false,
			'exclude_roles'        => array(),
			'track_admin'          => false,
		)
	);
}

/**
 * A GA4 measurement ID, or '' if the input is not one.
 *
 * @param string $value Raw input.
 * @return string
 */
function sanitize_measurement_id( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	return preg_match( '/^G-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
}

/**
 * Whether anything stops this module printing its tag on this site.
 *
 * The same tests as blockers(), without the wording: boot() runs on
 * plugins_loaded, before translations may load, on every request.
 *
 * @return bool
 */
function is_blocked() {
	return \TGD_Site_Kit\plugin_active_with_prefix( 'beehive' )
		|| \TGD_Site_Kit\plugin_active( 'google-site-kit/google-site-kit.php' )
		|| '' === settings()['measurement_id'];
}

/**
 * Reasons this module is holding back its tag on this site, as plain text.
 *
 * For the settings screen only; boot() asks is_blocked().
 *
 * @return string[]
 */
function blockers() {
	$lines   = array();
	$beehive = \TGD_Site_Kit\plugin_active_with_prefix( 'beehive' );
	if ( $beehive ) {
		$lines[] = sprintf(
			/* translators: %s: plugin basename */
			__( 'Beehive is active (%s), so this module prints nothing. Import its settings below, then deactivate Beehive.', 'tgd-site-kit' ),
			$beehive
		);
	}
	if ( \TGD_Site_Kit\plugin_active( 'google-site-kit/google-site-kit.php' ) ) {
		$lines[] = __( 'Site Kit by Google is active and can place its own GA4 tag, so this module prints nothing.', 'tgd-site-kit' );
	}
	if ( '' === settings()['measurement_id'] ) {
		$lines[] = __( 'No measurement ID is set, so there is nothing to print.', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Whether the current visitor is tracked: Beehive's role exclusion.
 *
 * @param array $settings Module settings.
 * @return bool
 */
function visitor_is_tracked( array $settings ) {
	if ( ! is_user_logged_in() || empty( $settings['exclude_roles'] ) ) {
		return true;
	}
	$roles = (array) wp_get_current_user()->roles;
	if ( current_user_can( 'manage_network' ) ) {
		$roles[] = 'super_admin';
	}
	return ! array_intersect( (array) $settings['exclude_roles'], $roles );
}

/**
 * Print the tag in the front-end head.
 */
function print_tag() {
	if ( is_preview() || wp_doing_ajax() ) {
		return;
	}
	$settings = settings();
	if ( '' === $settings['measurement_id'] || ! visitor_is_tracked( $settings ) ) {
		return;
	}
	echo tag_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in tag_markup().
}

/**
 * Print the tag on admin pages, only when Beehive's admin tracking was on.
 */
function print_admin_tag() {
	if ( ! empty( settings()['track_admin'] ) ) {
		print_tag();
	}
}

/**
 * The tag, Beehive's markup with Beehive's names.
 *
 * Whitespace follows Beehive's template closely but not to the byte, because
 * whitespace inside a script block means nothing to a browser or to GA. The
 * markers around it are what lets the duplicate check take it back out.
 *
 * @param array $settings Module settings.
 * @return string
 */
function tag_markup( array $settings ) {
	$id     = esc_attr( $settings['measurement_id'] );
	$layer  = DATA_LAYER;
	$cmd    = COMMAND;
	$anon   = $settings['anonymize_ip'] ? 'true' : 'false';
	$signal = $settings['allow_google_signals'] ? 'true' : 'false';
	// Beehive writes the `&` bare; the browser requests the same URL either way.
	$src = 'https://www.googletagmanager.com/gtag/js?id=' . $id . '&l=' . $layer;

	return MARK_OPEN . "\n"
		. '<script async src="' . $src . '"></script>' . "\n" // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the tag has to be exactly this markup, in the head, unconditionally.
		. "<script>\n"
		. "\twindow.{$layer} = window.{$layer} || [];\n"
		. "\tfunction {$cmd}() {{$layer}.push(arguments);}\n"
		. "\t{$cmd}('js', new Date())\n"
		. "\t{$cmd}('config', '{$id}', {\n"
		. "\t\t'anonymize_ip': {$anon},\n"
		. "\t\t'allow_google_signals': {$signal},\n"
		. "\t})\n"
		. "</script>\n"
		. MARK_CLOSE . "\n";
}

/**
 * Buffer the page so the finished HTML can be checked for a second tag.
 *
 * Front-end HTML requests only. The buffer callback does a handful of string
 * searches and returns the page unchanged unless it finds a duplicate.
 */
function start_duplicate_check() {
	if ( ! \TGD_Site_Kit\is_front_end_request() || is_robots() || is_embed() ) {
		return;
	}
	ob_start( __NAMESPACE__ . '\\remove_tag_if_duplicated' );
}

/**
 * Take this module's tag back out of a page that already carries the same ID.
 *
 * Looks for the ID in a gtag.js URL or a `config` call anywhere in the page
 * outside our own markers, so a tag pasted into a footer box counts as much as
 * one in the head. A GTM container that fires GA4 from inside Google's own
 * script is invisible to this, because the ID never appears in the page.
 *
 * @param string $html Finished page.
 * @return string
 */
function remove_tag_if_duplicated( $html ) {
	$start = strpos( $html, MARK_OPEN );
	if ( false === $start ) {
		return $html;
	}
	$end = strpos( $html, MARK_CLOSE, $start );
	if ( false === $end ) {
		return $html;
	}
	$end  += strlen( MARK_CLOSE ) + 1;
	$rest  = substr( $html, 0, $start ) . substr( $html, $end );
	$id    = preg_quote( settings()['measurement_id'], '/' );
	$found = preg_match( '/gtag\/js\?id=' . $id . '\b|[\'"]config[\'"]\s*,\s*[\'"]' . $id . '[\'"]/i', $rest, $match );
	if ( ! $found ) {
		return $html;
	}

	if ( ! get_transient( CONFLICT_OPTION ) ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		set_transient(
			CONFLICT_OPTION,
			array(
				'url'   => home_url( $uri ),
				'match' => $match[0],
				'time'  => time(),
			),
			WEEK_IN_SECONDS
		);
	}
	return $rest;
}

/**
 * Import tracking settings from Beehive's own option on this site.
 *
 * Beehive keeps everything in `beehive_settings`, grouped. The measurement ID
 * follows Beehive's own rule: an ID collected automatically from a connected
 * Google account wins over the typed one when automatic tracking is on.
 * Copies only; Beehive's option is not touched.
 *
 * @return array|null The imported settings, or null when Beehive has none.
 */
function import_from_beehive() {
	$beehive = get_option( 'beehive_settings' );
	if ( ! is_array( $beehive ) ) {
		return null;
	}
	$id   = $beehive['tracking']['measurement'] ?? '';
	$auto = $beehive['google']['auto_track_ga4'] ?? true;
	if ( $auto && ! empty( $beehive['misc']['auto_track_ga4'] ) ) {
		$id = $beehive['misc']['auto_track_ga4'];
	}
	$imported = array(
		'measurement_id'       => sanitize_measurement_id( $id ),
		'anonymize_ip'         => ! empty( $beehive['general']['anonymize'] ),
		'allow_google_signals' => ! empty( $beehive['general']['advertising'] ),
		'exclude_roles'        => array_values( array_map( 'sanitize_key', (array) ( $beehive['tracking']['exclude_roles'] ?? array() ) ) ),
		'track_admin'          => ! empty( $beehive['general']['track_admin'] ),
	);
	update_option( OPTION, $imported, true );
	return $imported;
}

/**
 * Save the settings form.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_analytics_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$roles = isset( $_POST['exclude_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['exclude_roles'] ) ) : array();
	$raw   = isset( $_POST['measurement_id'] ) ? sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) : '';
	$id    = sanitize_measurement_id( $raw );
	update_option(
		OPTION,
		array(
			'measurement_id'       => $id,
			'anonymize_ip'         => ! empty( $_POST['anonymize_ip'] ),
			'allow_google_signals' => ! empty( $_POST['allow_google_signals'] ),
			'exclude_roles'        => array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) ),
			'track_admin'          => ! empty( $_POST['track_admin'] ),
		),
		true
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	delete_transient( CONFLICT_OPTION );
	$notice = ( '' !== $raw && '' === $id ) ? 'analytics_bad_id' : 'analytics_saved';
	wp_safe_redirect( \TGD_Site_Kit\settings_url( $notice, 'analytics' ) );
	exit;
}

/**
 * Run the Beehive import from the settings screen.
 */
function handle_import() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_analytics_import' );
	$result = import_from_beehive();
	wp_safe_redirect( \TGD_Site_Kit\settings_url( null === $result ? 'analytics_no_beehive' : 'analytics_imported', 'analytics' ) );
	exit;
}

/**
 * Notice wording for this module's redirects.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'analytics_saved'      => __( 'Analytics settings saved.', 'tgd-site-kit' ),
		'analytics_bad_id'     => __( 'That is not a GA4 measurement ID (G- followed by letters and digits), so the ID was cleared.', 'tgd-site-kit' ),
		'analytics_imported'   => __( 'Imported from Beehive. Check the values below before deactivating Beehive.', 'tgd-site-kit' ),
		'analytics_no_beehive' => __( 'Beehive has no saved settings on this site, so nothing was imported.', 'tgd-site-kit' ),
	);
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s        = settings();
	$conflict = get_transient( CONFLICT_OPTION );
	?>
	<h2><?php esc_html_e( 'Analytics tag', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<?php if ( is_array( $conflict ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			printf(
				/* translators: 1: page URL, 2: matched text, 3: date */
				esc_html__( 'Another tag for this measurement ID was found on %1$s (%2$s) on %3$s, so this module removed its own tag from that page. It does this on every page where it finds one.', 'tgd-site-kit' ),
				esc_html( $conflict['url'] ),
				'<code>' . esc_html( $conflict['match'] ) . '</code>',
				esc_html( wp_date( 'M j, Y g:ia', $conflict['time'] ) )
			);
			?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_analytics_save">
		<?php wp_nonce_field( 'tgdsk_analytics_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-ga-id"><?php esc_html_e( 'Measurement ID', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-ga-id" name="measurement_id" class="regular-text code" value="<?php echo esc_attr( $s['measurement_id'] ); ?>" placeholder="G-XXXXXXXXXX"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'gtag config', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="anonymize_ip" value="1"<?php checked( $s['anonymize_ip'] ); ?>> <code>anonymize_ip</code></label><br>
					<label><input type="checkbox" name="allow_google_signals" value="1"<?php checked( $s['allow_google_signals'] ); ?>> <code>allow_google_signals</code></label>
					<p class="description"><?php esc_html_e( 'Sent as true or false on every page, as Beehive sends them.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Do not track these roles', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
						<label><input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr( $role ); ?>"<?php checked( in_array( $role, $s['exclude_roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Beehive tracks every signed-in user, administrators included, unless their role is ticked here. Visitors who are not signed in are always tracked.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Admin pages', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="track_admin" value="1"<?php checked( $s['track_admin'] ); ?>> <?php esc_html_e( 'Also tag wp-admin pages (Beehive’s “track admin pages”; off by default)', 'tgd-site-kit' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save analytics settings', 'tgd-site-kit' ) ); ?>
	</form>

	<h3><?php esc_html_e( 'Import from Beehive', 'tgd-site-kit' ); ?></h3>
	<p><?php esc_html_e( 'Copies the measurement ID, the two config flags, the excluded roles and admin tracking from Beehive’s settings on this site, replacing the values above. Beehive’s settings are not changed.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_analytics_import">
		<?php wp_nonce_field( 'tgdsk_analytics_import' ); ?>
		<?php submit_button( __( 'Import from Beehive', 'tgd-site-kit' ), 'secondary' ); ?>
	</form>
	<?php
}
