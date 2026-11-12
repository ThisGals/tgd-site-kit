<?php
/**
 * The tag check: is the kit's GA4 tag or container on the home page once,
 * and is anything else there?
 *
 * Fetches the home page as a logged-out visitor and reads the HTML for Google
 * tags: gtag.js loads and `config` calls (GA4, Google tag and Ads IDs), and
 * Tag Manager containers (the loader and the noscript frame). It reports:
 *
 * - the kit's measurement ID or container also loaded by something else (the
 *   page's own duplicate check then takes the kit's copy out, so a visitor
 *   gets one; the other copy is the one to remove or keep deliberately);
 * - any other GA4 tag, Google tag or container;
 * - the kit's own tag missing where it should print (a page cache serving an
 *   old copy, or a filter keeping it off).
 *
 * It runs only when asked: from Tools → Site Health (an async test, through
 * admin-ajax) and from the button on the Analytics tab. Never on a front-end
 * request, and never on a schedule. The last result is kept in an option for
 * the Analytics tab and the connector's status route.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Analytics;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const CHECK_OPTION = 'tgdsk_analytics_tag_check';
const HEALTH_TEST  = 'tgdsk_analytics_tags';

/**
 * Fetch the home page logged out and check it. Stores the result.
 *
 * @return array The result, as last_tag_check() returns it.
 */
function run_tag_check() {
	$url      = home_url( '/' );
	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'cookies'    => array(),
			'headers'    => array( 'Cache-Control' => 'no-cache' ),
			'user-agent' => 'TGD Site Kit tag check',
			// A loopback request, as Site Health's own: a local certificate must not fail it.
			'sslverify'  => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
		)
	);
	$result = array(
		'time'    => time(),
		'url'     => $url,
		'fetched' => false,
		'http'    => 0,
		'error'   => '',
	);
	if ( is_wp_error( $response ) ) {
		$result['error'] = $response->get_error_message();
	} else {
		$result['http']    = (int) wp_remote_retrieve_response_code( $response );
		$result['fetched'] = $result['http'] >= 200 && $result['http'] < 300;
		if ( ! $result['fetched'] ) {
			$result['error'] = sprintf( 'HTTP %d', $result['http'] );
		} else {
			$result += analyze_tags( (string) wp_remote_retrieve_body( $response ), settings() );
		}
	}
	$result['status'] = tag_check_status( $result );
	update_option( CHECK_OPTION, $result, false );
	return $result;
}

/**
 * What a page's HTML carries, against the kit's settings.
 *
 * The kit's own markup is found by its markers and set aside first, so what
 * is left is everything else on the page.
 *
 * @param string $html     Page.
 * @param array  $settings Module settings.
 * @return array{ga4: array, gtm: array, other_tags: string[], other_containers: string[]}
 */
function analyze_tags( $html, array $settings ) {
	$own_ga4 = str_contains( $html, MARK_OPEN );
	$own_gtm = str_contains( $html, GTM_MARK_OPEN );
	$rest    = $html;
	foreach ( array( array( MARK_OPEN, MARK_CLOSE ), array( GTM_MARK_OPEN, GTM_MARK_CLOSE ) ) as $marks ) {
		$rest = (string) preg_replace( '/' . preg_quote( $marks[0], '/' ) . '.*?' . preg_quote( $marks[1], '/' ) . '/s', '', $rest );
	}

	preg_match_all( '#googletagmanager\.com/gtag/js\?id=([A-Z]{1,2}-[A-Z0-9]{4,20})#i', $rest, $loads );
	preg_match_all( '#[\'"]config[\'"]\s*,\s*[\'"]((?:G|GT|AW)-[A-Z0-9]{4,20})[\'"]#i', $rest, $configs );
	$tags = array_values( array_unique( array_map( 'strtoupper', array_merge( $loads[1], $configs[1] ) ) ) );

	preg_match_all( '#googletagmanager\.com/(?:gtm\.js|ns\.html)\?id=(GTM-[A-Z0-9]{4,20})#i', $rest, $frames );
	preg_match_all( '#[\'"](GTM-[A-Z0-9]{4,20})[\'"]\s*\)#i', $rest, $loaders );
	$containers = array_values( array_unique( array_map( 'strtoupper', array_merge( $frames[1], $loaders[1] ) ) ) );

	$id  = (string) $settings['measurement_id'];
	$gtm = (string) $settings['gtm_container'];
	return array(
		'ga4'              => array(
			'id'    => $id,
			'own'   => $own_ga4,
			'other' => '' !== $id && in_array( $id, $tags, true ),
		),
		'gtm'              => array(
			'id'    => $gtm,
			'own'   => $own_gtm,
			'other' => '' !== $gtm && in_array( $gtm, $containers, true ),
		),
		'other_tags'       => array_values( array_diff( $tags, array( $id ) ) ),
		'other_containers' => array_values( array_diff( $containers, array( $gtm ) ) ),
	);
}

/**
 * `good`, `issues` or `failed`.
 *
 * @param array $result Check result.
 * @return string
 */
function tag_check_status( array $result ) {
	if ( empty( $result['fetched'] ) ) {
		return 'failed';
	}
	return tag_check_lines( $result ) ? 'issues' : 'good';
}

/**
 * What the check found, one plain-text line per finding; none when clean.
 *
 * @param array $result Check result.
 * @return string[]
 */
function tag_check_lines( array $result ) {
	if ( empty( $result['fetched'] ) ) {
		return array(
			/* translators: %s: error */
			sprintf( __( 'The home page could not be fetched (%s), so nothing was checked.', 'tgd-site-kit' ), (string) $result['error'] ),
		);
	}
	$lines = array();
	foreach ( array(
		'ga4' => array(
			/* translators: %s: measurement ID */
			'twice'   => __( 'The kit’s measurement ID %s is on the home page more than once.', 'tgd-site-kit' ),
			/* translators: %s: measurement ID */
			'other'   => __( 'Another tag on the home page loads the kit’s measurement ID %s, so the kit leaves its own tag out there. Remove the other tag, or switch the kit’s off.', 'tgd-site-kit' ),
			/* translators: %s: measurement ID */
			'missing' => __( 'The kit’s GA4 tag (%s) isn’t on the home page. A page cache may be serving an older copy, or a filter keeps the tag off.', 'tgd-site-kit' ),
		),
		'gtm' => array(
			/* translators: %s: container ID */
			'twice'   => __( 'The kit’s Tag Manager container %s is on the home page more than once.', 'tgd-site-kit' ),
			/* translators: %s: container ID */
			'other'   => __( 'Something else on the home page loads the kit’s container %s, so the kit leaves its own out there. Remove the other copy, or switch the kit’s off.', 'tgd-site-kit' ),
			/* translators: %s: container ID */
			'missing' => __( 'The kit’s Tag Manager container (%s) isn’t on the home page. A page cache may be serving an older copy, or a filter keeps it off.', 'tgd-site-kit' ),
		),
	) as $kind => $texts ) {
		$row = (array) ( $result[ $kind ] ?? array() );
		if ( '' === (string) ( $row['id'] ?? '' ) ) {
			continue;
		}
		if ( ! empty( $row['other'] ) ) {
			$lines[] = sprintf( ! empty( $row['own'] ) ? $texts['twice'] : $texts['other'], $row['id'] );
		} elseif ( empty( $row['own'] ) ) {
			$lines[] = sprintf( $texts['missing'], $row['id'] );
		}
	}
	if ( ! empty( $result['other_tags'] ) ) {
		/* translators: %s: tag IDs */
		$lines[] = sprintf( __( 'Other Google tags on the home page: %s.', 'tgd-site-kit' ), implode( ', ', (array) $result['other_tags'] ) );
	}
	if ( ! empty( $result['other_containers'] ) ) {
		/* translators: %s: container IDs */
		$lines[] = sprintf( __( 'Other Tag Manager containers on the home page: %s.', 'tgd-site-kit' ), implode( ', ', (array) $result['other_containers'] ) );
	}
	return $lines;
}

/**
 * The last stored result, or null when the check has never run.
 *
 * @return array|null
 */
function last_tag_check() {
	$stored = get_option( CHECK_OPTION );
	return is_array( $stored ) ? $stored : null;
}

/**
 * Register the async Site Health test.
 *
 * @param array $tests Site Health tests.
 * @return array
 */
function add_site_health_test( $tests ) {
	$tests['async'][ HEALTH_TEST ] = array(
		'label'             => __( 'Analytics tags on the home page', 'tgd-site-kit' ),
		'test'              => 'tgdsk-analytics-tags',
		'has_rest'          => false,
		'async_direct_test' => __NAMESPACE__ . '\\site_health_result',
	);
	return $tests;
}

/**
 * The Site Health screen's request for the test (`health-check-` plus the
 * test's name, as core sends async tests without a REST route).
 */
function ajax_site_health_test() {
	check_ajax_referer( 'health-check-site-status' );
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error();
	}
	wp_send_json_success( site_health_result() );
}

/**
 * Run the check and word it the way Site Health shows a test.
 *
 * @return array
 */
function site_health_result() {
	$result = run_tag_check();
	$lines  = tag_check_lines( $result );
	$good   = 'good' === $result['status'];
	return array(
		'label'       => $good
			? __( 'The home page carries the kit’s analytics tags once, and no others', 'tgd-site-kit' )
			: __( 'The analytics tags on the home page need a look', 'tgd-site-kit' ),
		'status'      => $good ? 'good' : 'recommended',
		'badge'       => array(
			'label' => __( 'Analytics', 'tgd-site-kit' ),
			'color' => 'blue',
		),
		'description' => $good
			? '<p>' . esc_html__( 'Checked as a logged-out visitor: one GA4 tag and one container at most, each the kit’s.', 'tgd-site-kit' ) . '</p>'
			: '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>',
		'actions'     => current_user_can( OWNER_CAP )
			? '<p><a href="' . esc_url( \TGD_Site_Kit\settings_url( '', 'analytics' ) ) . '">' . esc_html__( 'Analytics settings', 'tgd-site-kit' ) . '</a></p>'
			: '',
		'test'        => HEALTH_TEST,
	);
}

/**
 * Run the check from the Analytics tab.
 */
function handle_tag_check() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_analytics_tag_check' );
	run_tag_check();
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'analytics_checked', 'analytics' ) );
	exit;
}

/**
 * The tag check's section of the Analytics tab: the last result and a
 * button to run it again.
 */
function render_tag_check() {
	$last = last_tag_check();
	?>
	<h3><?php esc_html_e( 'Tag check', 'tgd-site-kit' ); ?></h3>
	<p><?php esc_html_e( 'Fetches the home page as a logged-out visitor and reports a second copy of the kit’s tag or container, or any other Google tag or container. The same test runs under Tools → Site Health.', 'tgd-site-kit' ); ?></p>
	<?php if ( ! $last ) : ?>
		<p><?php esc_html_e( 'Not run yet.', 'tgd-site-kit' ); ?></p>
	<?php else : ?>
		<?php $lines = tag_check_lines( $last ); ?>
		<div class="notice <?php echo esc_attr( 'good' === $last['status'] ? 'notice-success' : 'notice-warning' ); ?> inline">
			<p><strong>
				<?php
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Last run %s:', 'tgd-site-kit' ), wp_date( 'M j, Y g:ia', (int) $last['time'] ) ) );
				?>
			</strong>
			<?php echo esc_html( $lines ? '' : __( 'clean. The kit’s tags are on the home page once, and nothing else.', 'tgd-site-kit' ) ); ?></p>
			<?php if ( $lines ) : ?>
				<ul style="list-style:disc;margin-left:2em">
					<?php foreach ( $lines as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_analytics_tag_check">
		<?php wp_nonce_field( 'tgdsk_analytics_tag_check' ); ?>
		<?php submit_button( __( 'Run the tag check', 'tgd-site-kit' ), 'secondary' ); ?>
	</form>
	<?php
}
