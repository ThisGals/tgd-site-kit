<?php
/**
 * Module: breadcrumbs.
 *
 * A shortcode, `[tgdsk_breadcrumbs]`, and a block, TGD Site Kit Breadcrumbs,
 * that print the trail from includes/breadcrumbs/trail.php: the same items,
 * names and URLs the schema module's BreadcrumbList carries, so what a
 * visitor sees and what search engines read can't disagree.
 *
 * Theme-agnostic: nothing hooks a theme. Put the shortcode in an Avada text or
 * code element, an Elementor Shortcode widget, a widget area or a template;
 * the block goes anywhere blocks do. Markup is a `<nav>` with an ordered list
 * and `aria-current` on the page itself, plus a few lines of CSS printed once.
 *
 * Settings: the home label (used by the schema graph too), the separator, and
 * whether the current page shows. The shortcode takes `separator` and `current`
 * to override those in one place. The home label is sitewide only, because
 * the schema graph uses it too.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Breadcrumbs;

use const TGD_Site_Kit\OWNER_CAP;
use const TGD_Site_Kit\PLUGIN_FILE;
use const TGD_Site_Kit\VERSION;

defined( 'ABSPATH' ) || exit;

\TGD_Site_Kit\register_module(
	'breadcrumbs',
	array(
		'label'       => fn() => __( 'Breadcrumbs', 'tgd-site-kit' ),
		'description' => fn() => __( 'A [tgdsk_breadcrumbs] shortcode and a block printing the same trail as the schema module’s breadcrumbs. Works in any theme or builder.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	add_action( 'init', __NAMESPACE__ . '\\register' );
	add_action( 'admin_post_tgdsk_breadcrumbs_save', __NAMESPACE__ . '\\handle_save' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
}

/**
 * Register the shortcode and the block.
 */
function register() {
	add_shortcode( 'tgdsk_breadcrumbs', __NAMESPACE__ . '\\shortcode' );
	wp_register_script(
		'tgdsk-breadcrumbs-block',
		plugins_url( 'assets/breadcrumbs-block.js', PLUGIN_FILE ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-components', 'wp-i18n' ),
		VERSION,
		true
	);
	register_block_type(
		'tgd-site-kit/breadcrumbs',
		array(
			'api_version'     => 3,
			'title'           => __( 'Breadcrumbs (TGD Site Kit)', 'tgd-site-kit' ),
			'category'        => 'theme',
			'icon'            => 'arrow-right-alt2',
			'editor_script'   => 'tgdsk-breadcrumbs-block',
			'attributes'      => array(
				'separator' => array(
					'type'    => 'string',
					'default' => '',
				),
				'current'   => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'supports'        => array(
				'html'       => false,
				'typography' => array( 'fontSize' => true ),
				'spacing'    => array( 'margin' => true ),
			),
			'render_callback' => __NAMESPACE__ . '\\render_block',
		)
	);
}

/**
 * The shortcode.
 *
 * @param array|string $atts Attributes: separator, current (yes/no).
 * @return string
 */
function shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'separator' => '',
			'current'   => '',
		),
		is_array( $atts ) ? $atts : array(),
		'tgdsk_breadcrumbs'
	);
	return markup( $atts );
}

/**
 * The block, rendered on the server.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_block( $attributes ) {
	$html = markup( (array) $attributes );
	if ( '' === $html ) {
		return '';
	}
	return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
}

/**
 * The trail as HTML, or '' when the page has none.
 *
 * In the block editor (a REST render with no page in view) it shows a sample
 * trail so the block can be placed and styled.
 *
 * @param array $atts separator, current.
 * @return string
 */
function markup( array $atts ) {
	$s       = settings();
	$sep     = '' !== (string) ( $atts['separator'] ?? '' ) ? (string) $atts['separator'] : $s['separator'];
	$current = '' !== (string) ( $atts['current'] ?? '' ) ? ! in_array( strtolower( (string) $atts['current'] ), array( 'no', 'false', '0', 'off' ), true ) : $s['current'];
	$items   = current_trail();
	if ( ! $items && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$items = array(
			array(
				'name' => $s['home'],
				'url'  => home_url( '/' ),
			),
			array(
				'name' => __( 'Parent page', 'tgd-site-kit' ),
				'url'  => home_url( '/' ),
			),
			array(
				'name' => __( 'This page', 'tgd-site-kit' ),
				'url'  => home_url( '/' ),
			),
		);
	}
	if ( ! $items ) {
		return '';
	}
	$last = count( $items ) - 1;
	if ( ! $current ) {
		unset( $items[ $last ] );
		--$last;
	}
	$out = '';
	foreach ( array_values( $items ) as $i => $item ) {
		$is_page = $current && $i === $last;
		$out    .= '<li class="tgdsk-breadcrumbs__item">';
		$out    .= $is_page
			? '<span aria-current="page">' . esc_html( $item['name'] ) . '</span>'
			: '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
		if ( $i < $last ) {
			$out .= '<span class="tgdsk-breadcrumbs__sep" aria-hidden="true">' . esc_html( $sep ) . '</span>';
		}
		$out .= '</li>';
	}
	return style_once() . '<nav class="tgdsk-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'tgd-site-kit' ) . '"><ol class="tgdsk-breadcrumbs__list">' . $out . '</ol></nav>';
}

/**
 * The trail's CSS, the first time a trail prints on a page.
 *
 * @return string
 */
function style_once() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style id="tgdsk-breadcrumbs-css">.tgdsk-breadcrumbs__list{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;align-items:baseline}.tgdsk-breadcrumbs__item{margin:0;padding:0}.tgdsk-breadcrumbs__sep{margin:0 .5em}</style>';
}

/**
 * Notice wording.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array( 'breadcrumbs_saved' => __( 'Breadcrumb settings saved.', 'tgd-site-kit' ) );
}

/**
 * Save the settings.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_breadcrumbs_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	update_option(
		OPTION,
		array(
			'home'      => isset( $_POST['home'] ) ? sanitize_text_field( wp_unslash( $_POST['home'] ) ) : '',
			'separator' => isset( $_POST['separator'] ) ? sanitize_text_field( wp_unslash( $_POST['separator'] ) ) : '',
			'current'   => ! empty( $_POST['current'] ),
		),
		false
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'breadcrumbs_saved', 'breadcrumbs' ) );
	exit;
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$stored = get_option( OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$s      = settings();
	?>
	<h2><?php esc_html_e( 'Breadcrumbs', 'tgd-site-kit' ); ?></h2>
	<p><?php esc_html_e( 'Add [tgdsk_breadcrumbs] where the trail should show (an Avada text or code element, an Elementor Shortcode widget, a widget), or the Breadcrumbs (TGD Site Kit) block. It prints the same trail as the schema breadcrumbs: home, parent pages, the page. Term and post type archives get a trail too; the front page, search and 404 pages get none.', 'tgd-site-kit' ); ?></p>
	<p class="description"><?php esc_html_e( 'Per use: [tgdsk_breadcrumbs separator="/" current="no"].', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_breadcrumbs_save">
		<?php wp_nonce_field( 'tgdsk_breadcrumbs_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-bc-home"><?php esc_html_e( 'Home label', 'tgd-site-kit' ); ?></label></th>
				<td>
					<input type="text" id="tgdsk-bc-home" name="home" value="<?php echo esc_attr( (string) ( $stored['home'] ?? '' ) ); ?>" placeholder="Home">
					<p class="description"><?php esc_html_e( 'Also the first item’s name in the schema graph’s breadcrumbs, so the two stay the same.', 'tgd-site-kit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tgdsk-bc-sep"><?php esc_html_e( 'Separator', 'tgd-site-kit' ); ?></label></th>
				<td><input type="text" id="tgdsk-bc-sep" name="separator" class="small-text" value="<?php echo esc_attr( (string) ( $stored['separator'] ?? '' ) ); ?>" placeholder="›"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Current page', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="current" value="1"<?php checked( $s['current'] ); ?>> <?php esc_html_e( 'Show the page itself at the end of the trail', 'tgd-site-kit' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'tgd-site-kit' ) ); ?>
	</form>
	<?php
}
