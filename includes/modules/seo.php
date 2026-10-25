<?php
/**
 * Module: SEO meta.
 *
 * Titles, meta descriptions, canonical, robots, Open Graph and Twitter cards,
 * per post, per term and as site defaults, with an import from SmartCrawl. The
 * output rules are a port of SmartCrawl's, so that switching a site over
 * changes nothing in its rendered head; see includes/seo/entities.php.
 *
 * **While SmartCrawl is active this module prints nothing.** Two SEO plugins
 * writing head tags is the duplicate-tag problem the switch exists to avoid.
 * With SmartCrawl active the module still imports, shows its fields, and
 * previews what it would print as an HTML comment, so the switch can be
 * checked before it is made.
 *
 * The values it keeps per post and per term are the client's site content, so
 * they survive switching the module off and uninstalling the plugin.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix of every per-post SEO meta key. Underscored, so the keys stay out of
 * the Custom Fields box.
 */
const POST_META_PREFIX = '_tgdsk_';

/**
 * Prefix of every per-term SEO meta key.
 */
const TERM_META_PREFIX = '_tgdsk_';

/**
 * Prefix of the author archive title and description in user meta.
 */
const USER_META_PREFIX = '_tgdsk_';

const SMARTCRAWL_BASENAME = 'wpmu-dev-seo/wpmu-dev-seo.php';

require_once __DIR__ . '/../seo/settings.php';
require_once __DIR__ . '/../seo/resolve.php';
require_once __DIR__ . '/../seo/entities/class-entity.php';
require_once __DIR__ . '/../seo/entities/class-post-entity.php';
require_once __DIR__ . '/../seo/entities/class-posts-page-entity.php';
require_once __DIR__ . '/../seo/entities/class-term-entity.php';
require_once __DIR__ . '/../seo/entities/class-blog-home-entity.php';
require_once __DIR__ . '/../seo/entities/class-post-type-archive-entity.php';
require_once __DIR__ . '/../seo/entities/class-search-entity.php';
require_once __DIR__ . '/../seo/entities/class-not-found-entity.php';
require_once __DIR__ . '/../seo/entities/class-author-entity.php';
require_once __DIR__ . '/../seo/output.php';
require_once __DIR__ . '/../seo/sitemaps.php';
require_once __DIR__ . '/../seo/fields.php';
require_once __DIR__ . '/../seo/import.php';

\TGD_Site_Kit\register_module(
	'seo',
	array(
		'label'       => fn() => __( 'SEO meta', 'tgd-site-kit' ),
		'description' => fn() => __( 'Titles, descriptions, canonical, robots, Open Graph and Twitter tags, the core sitemap, and an import from SmartCrawl. Prints nothing while SmartCrawl is active.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Whether SmartCrawl, free or Pro, is active.
 *
 * Both editions use the same folder and file; the second check catches a copy
 * installed under another folder name.
 *
 * @return bool
 */
function smartcrawl_active() {
	return \TGD_Site_Kit\plugin_active( SMARTCRAWL_BASENAME ) || defined( 'SMARTCRAWL_VERSION' );
}

/**
 * Reasons the module is holding back its output.
 *
 * @return string[]
 */
function blockers() {
	return smartcrawl_active()
		? array( __( 'SmartCrawl is active, so this module prints nothing. Import and preview are available on its tab.', 'tgd-site-kit' ) )
		: array();
}

/**
 * Add the module's hooks.
 */
function boot() {
	add_field_hooks();
	add_preview_hooks();
	add_action( 'admin_post_tgdsk_seo_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_seo_import', __NAMESPACE__ . '\\handle_import' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'tgdsk seo import', __NAMESPACE__ . '\\cli_import' );
	}
	if ( smartcrawl_active() ) {
		return;
	}
	add_output_hooks();
	add_sitemap_hooks();
}

/**
 * Import SmartCrawl's SEO data into TGD Site Kit.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Count what would change, and write nothing.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Flags.
 */
function cli_import( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
	$report = import_smartcrawl( ! empty( $assoc_args['dry-run'] ) );
	\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
}

/**
 * Notice wording for this module's redirects.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'seo_saved'    => __( 'SEO defaults saved.', 'tgd-site-kit' ),
		'seo_imported' => __( 'SmartCrawl import finished. The counts are below.', 'tgd-site-kit' ),
		'seo_dry_run'  => __( 'Dry run finished. Nothing was written; the counts below are what an import would do.', 'tgd-site-kit' ),
	);
}

/**
 * Run the import from the settings screen.
 */
function handle_import() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_seo_import' );
	$dry_run = ! empty( $_POST['dry_run'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$report  = import_smartcrawl( $dry_run );
	if ( $dry_run ) {
		set_transient( 'tgdsk_seo_dry_run', $report, HOUR_IN_SECONDS );
	}
	wp_safe_redirect( \TGD_Site_Kit\settings_url( $dry_run ? 'seo_dry_run' : 'seo_imported', 'seo' ) );
	exit;
}

/**
 * Save the defaults form.
 *
 * Only the keys the form shows are written; everything else in the onpage
 * section (images, social titles per location, whatever an import brought
 * across) is left as it was.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_seo_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$post     = wp_unslash( $_POST );
	$settings = settings();
	$text     = fn( $value ) => sanitize_template( $value );

	$settings['onpage']['preset-separator'] = sanitize_key( $post['preset-separator'] ?? 'pipe' );
	$settings['onpage']['separator']        = $text( $post['separator'] ?? '' );
	$locations                              = isset( $post['loc'] ) && is_array( $post['loc'] ) ? $post['loc'] : array();
	foreach ( array_keys( locations() ) as $loc ) {
		$row                                      = isset( $locations[ $loc ] ) && is_array( $locations[ $loc ] ) ? $locations[ $loc ] : array();
		$settings['onpage'][ "title-{$loc}" ]     = $text( $row['title'] ?? '' );
		$settings['onpage'][ "metadesc-{$loc}" ]  = $text( $row['metadesc'] ?? '' );
		$settings['onpage'][ "og-active-{$loc}" ] = ! empty( $row['og'] );
		$settings['onpage'][ "twitter-active-{$loc}" ]       = ! empty( $row['twitter'] );
		$settings['onpage'][ "meta_robots-noindex-{$loc}" ]  = ! empty( $row['noindex'] );
		$settings['onpage'][ "meta_robots-nofollow-{$loc}" ] = ! empty( $row['nofollow'] );
	}

	$settings['general']['enforce-lengths']              = ! empty( $post['enforce-lengths'] );
	$settings['general']['suppress-generator']           = ! empty( $post['suppress-generator'] );
	$settings['general']['suppress-redundant-canonical'] = ! empty( $post['suppress-redundant-canonical'] );

	$settings['social']['og-enable']           = ! empty( $post['og-enable'] );
	$settings['social']['twitter-card-enable'] = ! empty( $post['twitter-card-enable'] );
	$settings['social']['twitter-card-type']   = 'summary' === ( $post['twitter-card-type'] ?? '' ) ? 'summary' : 'summary_large_image';
	$settings['social']['twitter_username']    = $text( $post['twitter_username'] ?? '' );
	$settings['social']['fb-app-id']           = $text( $post['fb-app-id'] ?? '' );

	foreach ( array( 'google', 'bing' ) as $engine ) {
		$tag                                 = trim( (string) ( $post[ "verify-{$engine}" ] ?? '' ) );
		$settings['verification'][ $engine ] = '' === $tag ? '' : wp_kses(
			$tag,
			array(
				'meta' => array(
					'name'    => array(),
					'content' => array(),
				),
			)
		);
	}

	// The form lists public types only. Exclusions for anything it does not
	// show (an import can bring those) are kept, not dropped by the save.
	$shown_types                               = get_post_types( array( 'public' => true ) );
	$shown_taxes                               = get_taxonomies( array( 'public' => true ) );
	$settings['sitemap']['exclude_post_types'] = array_values(
		array_unique(
			array_merge(
				array_diff( (array) $settings['sitemap']['exclude_post_types'], $shown_types ),
				array_intersect( array_map( 'sanitize_key', (array) ( $post['exclude_post_types'] ?? array() ) ), $shown_types )
			)
		)
	);
	$settings['sitemap']['exclude_taxonomies'] = array_values(
		array_unique(
			array_merge(
				array_diff( (array) $settings['sitemap']['exclude_taxonomies'], $shown_taxes ),
				array_intersect( array_map( 'sanitize_key', (array) ( $post['exclude_taxonomies'] ?? array() ) ), $shown_taxes )
			)
		)
	);
	$settings['sitemap']['include_users']      = ! empty( $post['include_users'] );

	$settings['robots']['custom']       = sanitize_robots_txt( $post['robots_custom'] ?? '' );
	$settings['robots']['sitemap_line'] = ! empty( $post['robots_sitemap_line'] );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	save_settings( $settings );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'seo_saved', 'seo' ) );
	exit;
}

/**
 * Print an import report as a short table.
 *
 * @param array  $report  Report from import_smartcrawl().
 * @param string $heading Heading.
 */
function render_report( array $report, $heading ) {
	?>
	<h4><?php echo esc_html( $heading ); ?> <span class="description">(<?php echo esc_html( wp_date( 'M j, Y g:ia', $report['time'] ) ); ?>)</span></h4>
	<table class="widefat striped" style="max-width:640px">
		<thead><tr><th></th><th><?php esc_html_e( 'Objects', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Written', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Cleared', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Already the same', 'tgd-site-kit' ); ?></th><th><?php esc_html_e( 'Kept local edit', 'tgd-site-kit' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( array( 'posts', 'terms', 'authors', 'settings' ) as $kind ) : ?>
			<?php
			// Reports saved before authors were imported have no authors row.
			$row = $report[ $kind ] ?? null;
			if ( ! $row ) {
				continue;
			}
			?>
			<tr>
				<th scope="row"><?php echo esc_html( ucfirst( $kind ) ); ?></th>
				<td><?php echo esc_html( (string) ( $row['seen'] ?? '-' ) ); ?></td>
				<td><?php echo esc_html( (string) $row['written'] ); ?></td>
				<td><?php echo esc_html( (string) ( $row['deleted'] ?? '-' ) ); ?></td>
				<td><?php echo esc_html( (string) $row['same'] ); ?></td>
				<td><?php echo esc_html( (string) $row['local'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $report['local'] ) : ?>
		<p><?php esc_html_e( 'Kept because they were edited here after the last import:', 'tgd-site-kit' ); ?> <?php echo esc_html( implode( '; ', $report['local'] ) ); ?></p>
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
	$s       = settings();
	$dry     = get_transient( 'tgdsk_seo_dry_run' );
	$last    = get_option( IMPORT_REPORT );
	$presets = array( 'pipe', 'dash', 'dash-l', 'dot', 'dot-l', 'forward-slash', 'tilde', 'greater-than', 'caret-right', 'arrow-right' );
	?>
	<h2><?php esc_html_e( 'SEO meta', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<p>
		<a href="<?php echo esc_url( add_query_arg( 'tgdsk_seo_preview', '1', home_url( '/' ) ) ); ?>" target="_blank"><?php esc_html_e( 'Preview the home page’s tags', 'tgd-site-kit' ); ?></a>
		<?php esc_html_e( '(an HTML comment at the top of the page source; add ?tgdsk_seo_preview=1 to any page for the same).', 'tgd-site-kit' ); ?>
	</p>

	<h3><?php esc_html_e( 'Import from SmartCrawl', 'tgd-site-kit' ); ?></h3>
	<p><?php esc_html_e( 'Copies SmartCrawl’s per-post and per-term values and its site defaults. SmartCrawl’s data is never changed. Safe to run again: a value edited here since the last import is kept.', 'tgd-site-kit' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_seo_import">
		<?php wp_nonce_field( 'tgdsk_seo_import' ); ?>
		<button type="submit" name="dry_run" value="1" class="button"><?php esc_html_e( 'Dry run', 'tgd-site-kit' ); ?></button>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'tgd-site-kit' ); ?></button>
	</form>
	<?php
	if ( is_array( $dry ) ) {
		render_report( $dry, __( 'Last dry run', 'tgd-site-kit' ) );
	}
	if ( is_array( $last ) ) {
		render_report( $last, __( 'Last import', 'tgd-site-kit' ) );
	}
	?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_seo_save">
		<?php wp_nonce_field( 'tgdsk_seo_save' ); ?>

		<h3><?php esc_html_e( 'Site defaults', 'tgd-site-kit' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Templates use SmartCrawl’s macros: %%title%%, %%sitename%%, %%sitedesc%%, %%sep%%, %%excerpt%%, %%term_title%%, %%term_description%% and the rest. Empty uses the built-in default.', 'tgd-site-kit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Separator', 'tgd-site-kit' ); ?></th>
				<td>
					<select name="preset-separator">
						<?php foreach ( $presets as $preset ) : ?>
							<option value="<?php echo esc_attr( $preset ); ?>"<?php selected( (string) onpage( 'preset-separator' ), $preset ); ?>><?php echo esc_html( $preset ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="separator" class="small-text" value="<?php echo esc_attr( (string) onpage( 'separator' ) ); ?>" placeholder="<?php esc_attr_e( 'custom', 'tgd-site-kit' ); ?>">
					<span class="description"><?php echo esc_html( sprintf( /* translators: %s: separator character */ __( 'Now: %s', 'tgd-site-kit' ), separator() ) ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'General', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="enforce-lengths" value="1"<?php checked( $s['general']['enforce-lengths'] ); ?>> <?php echo esc_html( sprintf( /* translators: 1: title length, 2: description length */ __( 'Cut typed titles at %1$d characters and descriptions at %2$d', 'tgd-site-kit' ), title_max_length(), description_max_length() ) ); ?></label><br>
					<label><input type="checkbox" name="suppress-generator" value="1"<?php checked( $s['general']['suppress-generator'] ); ?>> <?php esc_html_e( 'Remove the WordPress generator tag', 'tgd-site-kit' ); ?></label><br>
					<label><input type="checkbox" name="suppress-redundant-canonical" value="1"<?php checked( $s['general']['suppress-redundant-canonical'] ); ?>> <?php esc_html_e( 'Leave out the canonical when it is the URL already being viewed', 'tgd-site-kit' ); ?></label>
				</td>
			</tr>
		</table>

		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Location', 'tgd-site-kit' ); ?></th>
				<th><?php esc_html_e( 'Title template', 'tgd-site-kit' ); ?></th>
				<th><?php esc_html_e( 'Description template', 'tgd-site-kit' ); ?></th>
				<th>OG</th><th>Twitter</th><th>noindex</th><th>nofollow</th>
			</tr></thead>
			<tbody>
			<?php foreach ( locations() as $loc => $label ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?><br><code><?php echo esc_html( $loc ); ?></code></th>
					<td><input type="text" class="large-text code" name="loc[<?php echo esc_attr( $loc ); ?>][title]" value="<?php echo esc_attr( (string) onpage( "title-{$loc}" ) ); ?>"></td>
					<td><input type="text" class="large-text code" name="loc[<?php echo esc_attr( $loc ); ?>][metadesc]" value="<?php echo esc_attr( (string) onpage( "metadesc-{$loc}" ) ); ?>"></td>
					<td><input type="checkbox" name="loc[<?php echo esc_attr( $loc ); ?>][og]" value="1"<?php checked( (bool) onpage( "og-active-{$loc}" ) ); ?>></td>
					<td><input type="checkbox" name="loc[<?php echo esc_attr( $loc ); ?>][twitter]" value="1"<?php checked( (bool) onpage( "twitter-active-{$loc}" ) ); ?>></td>
					<td><input type="checkbox" name="loc[<?php echo esc_attr( $loc ); ?>][noindex]" value="1"<?php checked( (bool) onpage( "meta_robots-noindex-{$loc}" ) ); ?>></td>
					<td><input type="checkbox" name="loc[<?php echo esc_attr( $loc ); ?>][nofollow]" value="1"<?php checked( (bool) onpage( "meta_robots-nofollow-{$loc}" ) ); ?>></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Social', 'tgd-site-kit' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Open Graph', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="og-enable" value="1"<?php checked( $s['social']['og-enable'] ); ?>> <?php esc_html_e( 'On', 'tgd-site-kit' ); ?></label>
					&nbsp; <label><?php esc_html_e( 'Facebook app ID', 'tgd-site-kit' ); ?> <input type="text" name="fb-app-id" value="<?php echo esc_attr( $s['social']['fb-app-id'] ); ?>"></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Twitter cards', 'tgd-site-kit' ); ?></th>
				<td>
					<label><input type="checkbox" name="twitter-card-enable" value="1"<?php checked( $s['social']['twitter-card-enable'] ); ?>> <?php esc_html_e( 'On', 'tgd-site-kit' ); ?></label>
					&nbsp; <select name="twitter-card-type">
						<option value="summary_large_image"<?php selected( $s['social']['twitter-card-type'], 'summary_large_image' ); ?>>summary_large_image</option>
						<option value="summary"<?php selected( $s['social']['twitter-card-type'], 'summary' ); ?>>summary</option>
					</select>
					&nbsp; <label><?php esc_html_e( 'Site handle', 'tgd-site-kit' ); ?> <input type="text" name="twitter_username" value="<?php echo esc_attr( $s['social']['twitter_username'] ); ?>"></label>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Verification', 'tgd-site-kit' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The whole meta tag the search engine gave you. A Search Console property verified this way loses verification if the tag goes missing.', 'tgd-site-kit' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			foreach ( array(
				'google' => 'Google',
				'bing'   => 'Bing',
			) as $engine => $label ) :
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><input type="text" class="large-text code" name="verify-<?php echo esc_attr( $engine ); ?>" value="<?php echo esc_attr( $s['verification'][ $engine ] ); ?>"></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h3><?php esc_html_e( 'Sitemap', 'tgd-site-kit' ); ?></h3>
		<p class="description"><?php esc_html_e( 'WordPress’s own sitemap at /wp-sitemap.xml, with SmartCrawl’s sitemap URLs redirected to it once SmartCrawl is off. Noindexed items are always left out.', 'tgd-site-kit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Leave out post types', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
						<label><input type="checkbox" name="exclude_post_types[]" value="<?php echo esc_attr( $type->name ); ?>"<?php checked( in_array( $type->name, $s['sitemap']['exclude_post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Leave out taxonomies', 'tgd-site-kit' ); ?></th>
				<td>
					<?php foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) : ?>
						<label><input type="checkbox" name="exclude_taxonomies[]" value="<?php echo esc_attr( $taxonomy->name ); ?>"<?php checked( in_array( $taxonomy->name, $s['sitemap']['exclude_taxonomies'], true ) ); ?>> <?php echo esc_html( $taxonomy->labels->name ); ?></label><br>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authors', 'tgd-site-kit' ); ?></th>
				<td><label><input type="checkbox" name="include_users" value="1"<?php checked( $s['sitemap']['include_users'] ); ?>> <?php esc_html_e( 'Include the author sitemap', 'tgd-site-kit' ); ?></label></td>
			</tr>
		</table>

		<h3>robots.txt</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tgdsk-robots"><?php esc_html_e( 'Contents', 'tgd-site-kit' ); ?></label></th>
				<td>
					<textarea id="tgdsk-robots" name="robots_custom" class="large-text code" rows="5"><?php echo esc_textarea( $s['robots']['custom'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Empty serves WordPress’s default. Ignored while the site discourages search engines, and when a robots.txt file exists on disk.', 'tgd-site-kit' ); ?></p>
					<label><input type="checkbox" name="robots_sitemap_line" value="1"<?php checked( $s['robots']['sitemap_line'] ); ?>> <?php esc_html_e( 'Add a Sitemap: line pointing at /wp-sitemap.xml', 'tgd-site-kit' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save SEO defaults', 'tgd-site-kit' ) ); ?>
	</form>
	<?php
}
