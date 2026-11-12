<?php
/**
 * Module: SEO meta.
 *
 * Titles, meta descriptions, canonical, robots, Open Graph and Twitter cards,
 * per post, per term and as site defaults; see includes/seo/entities/ for the
 * rules each kind of page follows. `?tgdsk_seo_preview=1` shows an
 * administrator what the module prints as an HTML comment.
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

require_once __DIR__ . '/../seo/settings.php';
require_once __DIR__ . '/../seo/resolve.php';
require_once __DIR__ . '/../seo/entities/class-entity.php';
require_once __DIR__ . '/../seo/entities/class-post-entity.php';
require_once __DIR__ . '/../seo/entities/class-posts-page-entity.php';
require_once __DIR__ . '/../seo/entities/class-term-entity.php';
require_once __DIR__ . '/../seo/entities/class-blog-home-entity.php';
require_once __DIR__ . '/../seo/entities/class-post-type-archive-entity.php';
require_once __DIR__ . '/../seo/entities/class-shop-entity.php';
require_once __DIR__ . '/../seo/entities/class-search-entity.php';
require_once __DIR__ . '/../seo/entities/class-not-found-entity.php';
require_once __DIR__ . '/../seo/entities/class-author-entity.php';
require_once __DIR__ . '/../seo/output.php';
require_once __DIR__ . '/../seo/sitemaps.php';
require_once __DIR__ . '/../seo/fields.php';

\TGD_Site_Kit\register_module(
	'seo',
	array(
		'label'       => fn() => __( 'SEO meta', 'tgd-site-kit' ),
		'description' => fn() => __( 'Titles, descriptions, canonical, robots, Open Graph and Twitter tags, WordPress’s sitemap and robots.txt.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
	)
);

/**
 * Add the module's hooks.
 */
function boot() {
	add_field_hooks();
	add_preview_hooks();
	add_action( 'admin_post_tgdsk_seo_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_seo_sitemap_posts', __NAMESPACE__ . '\\handle_sitemap_posts_save' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	add_output_hooks();
	add_sitemap_hooks();
}

/**
 * Notice wording for this module's redirects.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'seo_saved'               => __( 'SEO defaults saved.', 'tgd-site-kit' ),
		'seo_sitemap_posts_saved' => __( 'Posts left out of the sitemap updated.', 'tgd-site-kit' ),
	);
}

/**
 * Save the defaults form.
 *
 * Only the keys the form shows are written; everything else in the onpage
 * section (images, social titles per location) is left as it was.
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
	// show (a type no longer registered, say) are kept, not dropped by the save.
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
	if ( \TGD_Site_Kit\Woo\active() ) {
		$settings['robots']['woocommerce'] = ! empty( $post['robots_woocommerce'] );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	save_settings( $settings );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'seo_saved', 'seo' ) );
	exit;
}

/**
 * The module's section of the settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s       = settings();
	$presets = array( 'pipe', 'dash', 'dash-l', 'dot', 'dot-l', 'forward-slash', 'tilde', 'greater-than', 'caret-right', 'arrow-right' );
	?>
	<h2><?php esc_html_e( 'SEO meta', 'tgd-site-kit' ); ?></h2>
	<p>
		<a href="<?php echo esc_url( add_query_arg( 'tgdsk_seo_preview', '1', home_url( '/' ) ) ); ?>" target="_blank"><?php esc_html_e( 'Preview the home page’s tags', 'tgd-site-kit' ); ?></a>
		<?php esc_html_e( '(an HTML comment at the top of the page source; add ?tgdsk_seo_preview=1 to any page for the same).', 'tgd-site-kit' ); ?>
	</p>


	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_seo_save">
		<?php wp_nonce_field( 'tgdsk_seo_save' ); ?>

		<h3><?php esc_html_e( 'Site defaults', 'tgd-site-kit' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Templates use macros: %%title%%, %%sitename%%, %%sitedesc%%, %%sep%%, %%excerpt%%, %%term_title%%, %%term_description%% and the rest. Empty uses the built-in default.', 'tgd-site-kit' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'WordPress’s own sitemap at /wp-sitemap.xml, with /sitemap.xml and the other common sitemap addresses redirected to it. Noindexed items are always left out.', 'tgd-site-kit' ); ?></p>
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
			<tr>
				<th scope="row"><?php esc_html_e( 'Posts left out', 'tgd-site-kit' ); ?></th>
				<td><?php render_excluded_posts(); ?></td>
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
					<?php if ( \TGD_Site_Kit\Woo\active() ) : ?>
						<br><label><input type="checkbox" name="robots_woocommerce" value="1"<?php checked( ! empty( $s['robots']['woocommerce'] ) ); ?>> <?php esc_html_e( 'Keep crawlers out of the cart, checkout and account pages and add-to-cart links', 'tgd-site-kit' ); ?></label>
						<span class="description"><?php echo esc_html( implode( '  ', \TGD_Site_Kit\Woo\robots_lines() ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save SEO defaults', 'tgd-site-kit' ) ); ?>
	</form>
	<?php // The excluded posts list has its own form, so saving it touches nothing else; its fields above name it. ?>
	<form id="tgdsk-seo-sitemap-posts" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_seo_sitemap_posts">
		<?php wp_nonce_field( 'tgdsk_seo_sitemap_posts' ); ?>
	</form>
	<?php
}

/**
 * The posts left out of the sitemap, each with a box to put it back.
 *
 * Its inputs belong to the separate #tgdsk-seo-sitemap-posts form (HTML's
 * `form` attribute), so they sit under Sitemap without being part of the
 * defaults form, and neither save touches what the other shows.
 */
function render_excluded_posts() {
	$ids = excluded_post_ids();
	if ( ! $ids ) {
		echo '<p>' . esc_html__( 'No posts are left out one by one.', 'tgd-site-kit' ) . '</p>';
		return;
	}
	$excluded_types = (array) settings()['sitemap']['exclude_post_types'];
	?>
	<p class="description"><?php esc_html_e( 'Left out of the sitemap one by one (each post’s SEO box can add one). Tick a post to put it back.', 'tgd-site-kit' ); ?></p>
	<table class="widefat striped" style="max-width:820px">
		<thead><tr>
			<th><?php esc_html_e( 'Title', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Type', 'tgd-site-kit' ); ?></th>
			<th><?php esc_html_e( 'Status', 'tgd-site-kit' ); ?></th>
			<th>ID</th>
			<th><?php esc_html_e( 'Put back', 'tgd-site-kit' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $ids as $id ) : ?>
			<?php
			$row  = describe_excluded_post( $id );
			$type = $row['missing'] ? null : get_post_type_object( $row['type'] );
			$edit = $row['missing'] ? '' : (string) get_edit_post_link( $id, 'raw' );
			?>
			<tr>
				<td>
					<input type="hidden" name="listed[]" value="<?php echo esc_attr( (string) $id ); ?>" form="tgdsk-seo-sitemap-posts">
					<?php if ( $row['missing'] ) : ?>
						<em><?php esc_html_e( 'Missing: no post has this ID any more', 'tgd-site-kit' ); ?></em>
					<?php elseif ( $edit ) : ?>
						<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'tgd-site-kit' ) ); ?></a>
					<?php else : ?>
						<?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'tgd-site-kit' ) ); ?>
					<?php endif; ?>
				</td>
				<td>
					<?php echo esc_html( $row['missing'] ? '-' : ( $type ? $type->labels->singular_name : $row['type'] ) ); ?>
					<?php if ( ! $row['missing'] && in_array( $row['type'], $excluded_types, true ) ) : ?>
						<br><span class="description"><?php esc_html_e( 'The whole type is left out too.', 'tgd-site-kit' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $row['missing'] ? '-' : $row['status'] ); ?></td>
				<td><?php echo esc_html( (string) $id ); ?></td>
				<td><label><input type="checkbox" name="restore[]" value="<?php echo esc_attr( (string) $id ); ?>" form="tgdsk-seo-sitemap-posts"> <?php echo esc_html( $row['missing'] ? __( 'Remove', 'tgd-site-kit' ) : __( 'Put back', 'tgd-site-kit' ) ); ?></label></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><button type="submit" class="button" form="tgdsk-seo-sitemap-posts"><?php esc_html_e( 'Update posts left out', 'tgd-site-kit' ); ?></button></p>
	<?php
}

/**
 * Save the posts-left-out list: take out exactly the ticked IDs among those
 * the screen listed. IDs added since the screen loaded stay; nothing else in
 * the settings is written.
 */
function handle_sitemap_posts_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_seo_sitemap_posts' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$listed  = array_map( 'intval', (array) ( $_POST['listed'] ?? array() ) );
	$restore = array_map( 'intval', (array) ( $_POST['restore'] ?? array() ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	remove_excluded_post_ids( array_intersect( $restore, $listed ) );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'seo_sitemap_posts_saved', 'seo' ) );
	exit;
}
