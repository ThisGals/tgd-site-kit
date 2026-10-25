<?php
/**
 * Module: schema.
 *
 * One JSON-LD `@graph` per page: the business, the website, a typed page with
 * breadcrumbs, services on the pages that carry them, and raw extra nodes per
 * page. See includes/schema/graph.php for the shape and the duplicate checks.
 *
 * **Prints nothing while another graph is in charge**: SmartCrawl with its
 * schema switched on, a must-use plugin that prints JSON-LD, or JSON-LD pasted
 * into Avada's head or body code fields. The settings screen says which. The
 * page-level check in graph.php catches the rest (a page builder block, a
 * footer snippet) page by page.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Schema;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../schema/settings.php';
require_once __DIR__ . '/../schema/graph.php';
require_once __DIR__ . '/../schema/import.php';

const FIELDS_NONCE = 'tgdsk_schema_fields';

\TGD_Site_Kit\register_module(
	'schema',
	array(
		'label'       => fn() => __( 'Schema', 'tgd-site-kit' ),
		'description' => fn() => __( 'One JSON-LD graph per page: the business, the website, the page, breadcrumbs and services. Prints nothing while SmartCrawl’s schema or another JSON-LD graph is in charge.', 'tgd-site-kit' ),
		'boot'        => __NAMESPACE__ . '\\boot',
		'settings'    => __NAMESPACE__ . '\\render_settings',
		'notices'     => __NAMESPACE__ . '\\blockers',
	)
);

/**
 * Whether SmartCrawl is active with its schema output on.
 *
 * @return bool
 */
function smartcrawl_schema_active() {
	$social = get_option( 'wds_social_options', array() );
	return \TGD_Site_Kit\SEO\smartcrawl_active() && empty( $social['disable-schema'] );
}

/**
 * Must-use plugins whose source prints JSON-LD.
 *
 * Read from disk at most once a day per set of files: the list is keyed on
 * each file's name and modification time, so adding or editing one re-reads.
 *
 * @return string[] File names.
 */
function json_ld_mu_plugins() {
	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		return array();
	}
	$files = glob( WPMU_PLUGIN_DIR . '/*.php' );
	$files = is_array( $files ) ? $files : array();
	$key   = 'tgdsk_schema_mu_' . md5( implode( '|', array_map( fn( $f ) => $f . filemtime( $f ), $files ) ) );
	$found = get_transient( $key );
	if ( ! is_array( $found ) ) {
		$found = array();
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			if ( str_contains( $source, 'ld+json' ) ) {
				$found[] = basename( $file );
			}
		}
		set_transient( $key, $found, DAY_IN_SECONDS );
	}
	return $found;
}

/**
 * Whether Avada's custom code fields carry JSON-LD.
 *
 * @return bool
 */
function avada_code_has_json_ld() {
	$options = get_option( 'fusion_options', array() );
	foreach ( array( 'space_head', 'space_body' ) as $field ) {
		if ( is_array( $options ) && str_contains( (string) ( $options[ $field ] ?? '' ), 'ld+json' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether anything stops this module printing, without wording (boot runs
 * before translations may load).
 *
 * @return bool
 */
function is_blocked() {
	return smartcrawl_schema_active() || json_ld_mu_plugins() || avada_code_has_json_ld();
}

/**
 * Reasons the module is holding back, for the settings screen.
 *
 * @return string[]
 */
function blockers() {
	$lines = array();
	if ( smartcrawl_schema_active() ) {
		$lines[] = __( 'SmartCrawl is active with its schema switched on, so this module prints nothing. Switch SmartCrawl’s schema off (or SmartCrawl itself) to hand over.', 'tgd-site-kit' );
	}
	foreach ( json_ld_mu_plugins() as $file ) {
		/* translators: %s: must-use plugin file name */
		$lines[] = sprintf( __( 'The must-use plugin %s prints JSON-LD, so this module prints nothing. Remove it once its settings are copied here.', 'tgd-site-kit' ), $file );
	}
	if ( avada_code_has_json_ld() ) {
		$lines[] = __( 'Avada’s custom code fields (Theme Options → Advanced → Code Fields) contain JSON-LD, so this module prints nothing.', 'tgd-site-kit' );
	}
	return $lines;
}

/**
 * Add the module's hooks.
 */
function boot() {
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\add_meta_box' );
	add_action( 'save_post', __NAMESPACE__ . '\\save_post_fields', 10, 2 );
	add_action( 'admin_post_tgdsk_schema_save', __NAMESPACE__ . '\\handle_save' );
	add_action( 'admin_post_tgdsk_schema_import', __NAMESPACE__ . '\\handle_import' );
	add_filter( 'tgdsk_settings_notices', __NAMESPACE__ . '\\notices' );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'tgdsk schema import', __NAMESPACE__ . '\\cli_import' );
	}
	if ( is_blocked() ) {
		return;
	}
	add_action( 'wp_head', __NAMESPACE__ . '\\print_graph', 5 );
	add_action( 'template_redirect', __NAMESPACE__ . '\\start_page_check', 1 );
}

/**
 * Import schema data into TGD Site Kit.
 *
 * ## OPTIONS
 *
 * <source>
 * : `pages` for cb-schema's per-page fields, `smartcrawl` for SmartCrawl's schema settings.
 *
 * [--dry-run]
 * : Count what would change, and write nothing.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Flags.
 */
function cli_import( $args, $assoc_args ) {
	$dry    = ! empty( $assoc_args['dry-run'] );
	$report = 'smartcrawl' === ( $args[0] ?? '' ) ? import_smartcrawl_schema( $dry ) : import_page_fields( $dry );
	\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
}

/**
 * Notice wording.
 *
 * @param array $notices Code => text.
 * @return array
 */
function notices( $notices ) {
	return $notices + array(
		'schema_saved'    => __( 'Schema settings saved.', 'tgd-site-kit' ),
		'schema_imported' => __( 'Import finished.', 'tgd-site-kit' ),
	);
}

/**
 * Run an import from the settings screen.
 */
function handle_import() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_schema_import' );
	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_owner_write().
	$report = 'smartcrawl' === $source ? import_smartcrawl_schema() : import_page_fields();
	set_transient( 'tgdsk_schema_import_report', array( 'source' => $source ) + $report, HOUR_IN_SECONDS );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'schema_imported', 'schema' ) );
	exit;
}

/**
 * Save the settings form.
 */
function handle_save() {
	\TGD_Site_Kit\check_owner_write( 'tgdsk_schema_save' );
	$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in check_owner_write(); each field sanitized below.
	$s    = settings();
	$text = fn( $key ) => sanitize_text_field( (string) ( $post[ $key ] ?? '' ) );
	$b    = &$s['business'];

	$type      = $text( 'type' );
	$b['type'] = is_type_name( $type ) ? $type : 'LocalBusiness';
	foreach ( array( 'anchor', 'name', 'telephone', 'street', 'locality', 'region', 'postcode', 'country' ) as $key ) {
		$b[ $key ] = $text( $key );
	}
	$b['anchor']      = sanitize_title( $b['anchor'] );
	$b['description'] = sanitize_textarea_field( (string) ( $post['description'] ?? '' ) );
	$logo             = trim( $text( 'logo' ) );
	$b['logo']        = ctype_digit( $logo ) ? (int) $logo : esc_url_raw( $logo );
	$b['logo_width']  = absint( $post['logo_width'] ?? 0 );
	$b['logo_height'] = absint( $post['logo_height'] ?? 0 );
	$b['photo']       = esc_url_raw( trim( $text( 'photo' ) ) );
	$b['map_url']     = esc_url_raw( trim( $text( 'map_url' ) ) );
	foreach ( array( 'latitude', 'longitude' ) as $key ) {
		$value     = trim( $text( $key ) );
		$b[ $key ] = is_numeric( $value ) ? $value : '';
	}
	$b['hours']   = parse_hours( (string) ( $post['hours'] ?? '' ) );
	$b['areas']   = parse_areas( (string) ( $post['areas'] ?? '' ) );
	$b['same_as'] = parse_urls( (string) ( $post['same_as'] ?? '' ) );
	unset( $b );

	$s['website']['name']     = $text( 'website_name' );
	$s['website']['language'] = $text( 'language' );

	$s['pages']['types_by_slug'] = parse_types_by_slug( (string) ( $post['types_by_slug'] ?? '' ) );
	$s['pages']['about_slugs']   = parse_slugs( (string) ( $post['about_slugs'] ?? '' ) );
	$s['pages']['service_slugs'] = parse_slugs( (string) ( $post['service_slugs'] ?? '' ) );
	$s['pages']['breadcrumbs']   = ! empty( $post['breadcrumbs'] );
	$s['pages']['archive_pages'] = ! empty( $post['archive_pages'] );
	$s['services']               = parse_services( (string) ( $post['services'] ?? '' ) );

	save_settings( $s );
	wp_safe_redirect( \TGD_Site_Kit\settings_url( 'schema_saved', 'schema' ) );
	exit;
}

/**
 * The line-per-entry text a list setting is edited as.
 *
 * @param string $kind  hours, areas, types, services, urls or slugs.
 * @param array  $value Stored value.
 * @return string
 */
function as_lines( $kind, $value ) {
	$value = (array) $value;
	switch ( $kind ) {
		case 'hours':
			return implode( "\n", array_map( fn( $h ) => implode( ', ', $h['days'] ) . ' | ' . $h['opens'] . ' | ' . $h['closes'], $value ) );
		case 'areas':
			return implode( "\n", array_map( fn( $a ) => ( 'City' !== $a['type'] ? $a['type'] . ': ' : '' ) . $a['name'] . ( '' !== $a['in'] ? ' | ' . $a['in'] : '' ), $value ) );
		case 'types':
			return implode( "\n", array_map( fn( $slug, $type ) => "{$slug} = {$type}", array_keys( $value ), $value ) );
		case 'services':
			return implode( "\n", array_map( fn( $sv ) => $sv['anchor'] . ' | ' . $sv['name'] . ' | ' . $sv['description'], $value ) );
		default:
			return implode( "\n", $value );
	}
}

/**
 * The settings screen.
 */
function render_settings() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$s        = settings();
	$b        = $s['business'];
	$conflict = get_transient( CONFLICT_OPTION );
	$report   = get_transient( 'tgdsk_schema_import_report' );
	$field    = function ( $name, $label, $value, $hint = '' ) {
		printf(
			'<tr><th scope="row"><label for="tgdsk-schema-%1$s">%2$s</label></th><td><input type="text" id="tgdsk-schema-%1$s" name="%1$s" class="regular-text" value="%3$s">%4$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			$hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : ''
		);
	};
	$area     = function ( $name, $label, $value, $hint ) {
		printf(
			'<tr><th scope="row"><label for="tgdsk-schema-%1$s">%2$s</label></th><td><textarea id="tgdsk-schema-%1$s" name="%1$s" class="large-text code" rows="4">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) $value ),
			esc_html( $hint )
		);
	};
	?>
	<h2><?php esc_html_e( 'Schema', 'tgd-site-kit' ); ?></h2>
	<?php foreach ( blockers() as $line ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $line ); ?></p></div>
	<?php endforeach; ?>
	<?php if ( is_array( $conflict ) ) : ?>
		<div class="notice notice-error inline"><p>
			<?php
			printf(
				/* translators: 1: page URL, 2: date */
				esc_html__( 'Another JSON-LD graph or business entity was found on %1$s on %2$s, so this module took its own graph off that page. It does this on every page where it finds one.', 'tgd-site-kit' ),
				esc_html( $conflict['url'] ),
				esc_html( wp_date( 'M j, Y g:ia', $conflict['time'] ) )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( is_array( $report ) ) : ?>
		<div class="notice notice-info inline"><p>
			<?php
			printf(
				/* translators: 1: source, 2: written count, 3: unchanged count, 4: kept local edits */
				esc_html__( 'Last import (%1$s): %2$d written, %3$d already the same, %4$d kept because they were edited here.', 'tgd-site-kit' ),
				esc_html( (string) $report['source'] ),
				(int) $report['written'],
				(int) $report['same'],
				(int) $report['local']
			);
			?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
		<input type="hidden" name="action" value="tgdsk_schema_import">
		<?php wp_nonce_field( 'tgdsk_schema_import' ); ?>
		<button type="submit" name="source" value="pages" class="button"><?php esc_html_e( 'Import per-page fields (cb_schema_type, cb_brand, cb_schema_extra)', 'tgd-site-kit' ); ?></button>
		<button type="submit" name="source" value="smartcrawl" class="button"><?php esc_html_e( 'Import SmartCrawl’s schema settings', 'tgd-site-kit' ); ?></button>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tgdsk_schema_save">
		<?php wp_nonce_field( 'tgdsk_schema_save' ); ?>
		<h3><?php esc_html_e( 'The business', 'tgd-site-kit' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Optional fields left blank are left out of the graph.', 'tgd-site-kit' ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			$field( 'type', __( 'schema.org type', 'tgd-site-kit' ), $b['type'], __( 'LocalBusiness or a subtype, such as Bakery or AutoRepair.', 'tgd-site-kit' ) );
			$field( 'name', __( 'Name', 'tgd-site-kit' ), $b['name'], __( 'Blank uses the site title.', 'tgd-site-kit' ) );
			$field( 'anchor', __( 'Entity anchor', 'tgd-site-kit' ), $b['anchor'], __( 'The #fragment of its @id. Keep whatever the site used before, so links to it keep working.', 'tgd-site-kit' ) );
			?>
			<tr><th scope="row"><label for="tgdsk-schema-description"><?php esc_html_e( 'Description', 'tgd-site-kit' ); ?></label></th><td><textarea id="tgdsk-schema-description" name="description" class="large-text" rows="3"><?php echo esc_textarea( $b['description'] ); ?></textarea></td></tr>
			<?php
			$field( 'logo', __( 'Logo', 'tgd-site-kit' ), $b['logo'], __( 'An attachment ID (size is read from it) or a URL.', 'tgd-site-kit' ) );
			$field( 'logo_width', __( 'Logo width', 'tgd-site-kit' ), $b['logo_width'] ? $b['logo_width'] : '', __( 'Only for a logo given as a URL.', 'tgd-site-kit' ) );
			$field( 'logo_height', __( 'Logo height', 'tgd-site-kit' ), $b['logo_height'] ? $b['logo_height'] : '' );
			$field( 'photo', __( 'Photo URL', 'tgd-site-kit' ), $b['photo'] );
			$field( 'telephone', __( 'Phone', 'tgd-site-kit' ), $b['telephone'], __( 'International form, such as +1-212-555-0100.', 'tgd-site-kit' ) );
			$field( 'street', __( 'Street address', 'tgd-site-kit' ), $b['street'] );
			$field( 'locality', __( 'City', 'tgd-site-kit' ), $b['locality'] );
			$field( 'region', __( 'State or region', 'tgd-site-kit' ), $b['region'] );
			$field( 'postcode', __( 'ZIP or postcode', 'tgd-site-kit' ), $b['postcode'] );
			$field( 'country', __( 'Country code', 'tgd-site-kit' ), $b['country'], __( 'Two letters, such as US.', 'tgd-site-kit' ) );
			$field( 'latitude', __( 'Latitude', 'tgd-site-kit' ), $b['latitude'] );
			$field( 'longitude', __( 'Longitude', 'tgd-site-kit' ), $b['longitude'] );
			$field( 'map_url', __( 'Map link', 'tgd-site-kit' ), $b['map_url'] );
			$area( 'hours', __( 'Opening hours', 'tgd-site-kit' ), as_lines( 'hours', $b['hours'] ), __( 'One line per set of days: Monday, Tuesday | 10:00 | 17:00', 'tgd-site-kit' ) );
			$area( 'areas', __( 'Areas served', 'tgd-site-kit' ), as_lines( 'areas', $b['areas'] ), __( 'One per line: Springfield | Illinois for a city in a state, or AdministrativeArea: Sangamon County, Illinois.', 'tgd-site-kit' ) );
			$area( 'same_as', __( 'Profiles (sameAs)', 'tgd-site-kit' ), as_lines( 'urls', $b['same_as'] ), __( 'One URL per line: Google Business Profile, Facebook, Instagram, Yelp.', 'tgd-site-kit' ) );
			?>
		</table>

		<h3><?php esc_html_e( 'Website and pages', 'tgd-site-kit' ); ?></h3>
		<table class="form-table" role="presentation">
			<?php
			$field( 'website_name', __( 'Website name', 'tgd-site-kit' ), $s['website']['name'], __( 'Blank uses the business name. Also the home page’s page name.', 'tgd-site-kit' ) );
			$field( 'language', __( 'Language', 'tgd-site-kit' ), $s['website']['language'], __( 'Blank uses the site language.', 'tgd-site-kit' ) );
			$area( 'types_by_slug', __( 'Page types by slug', 'tgd-site-kit' ), as_lines( 'types', $s['pages']['types_by_slug'] ), __( 'One per line: about = AboutPage. Allowed: ', 'tgd-site-kit' ) . implode( ', ', PAGE_TYPES ) );
			$area( 'about_slugs', __( 'Pages about the business', 'tgd-site-kit' ), as_lines( 'slugs', $s['pages']['about_slugs'] ), __( 'Slugs whose page node says it is about the business. The home page always is.', 'tgd-site-kit' ) );
			$area( 'service_slugs', __( 'Pages that carry the services', 'tgd-site-kit' ), as_lines( 'slugs', $s['pages']['service_slugs'] ), __( 'Slugs, one per line.', 'tgd-site-kit' ) );
			$area( 'services', __( 'Services', 'tgd-site-kit' ), as_lines( 'services', $s['services'] ), __( 'One per line: anchor | Name | Description', 'tgd-site-kit' ) );
			?>
			<tr><th scope="row"><?php esc_html_e( 'Also', 'tgd-site-kit' ); ?></th><td>
				<label><input type="checkbox" name="breadcrumbs" value="1"<?php checked( ! empty( $s['pages']['breadcrumbs'] ) ); ?>> <?php esc_html_e( 'Breadcrumbs on every page but the home page (left out on a page that already prints its own)', 'tgd-site-kit' ); ?></label><br>
				<label><input type="checkbox" name="archive_pages" value="1"<?php checked( ! empty( $s['pages']['archive_pages'] ) ); ?>> <?php esc_html_e( 'A CollectionPage for archives and a SearchResultsPage for search', 'tgd-site-kit' ); ?></label>
			</td></tr>
		</table>
		<?php submit_button( __( 'Save schema settings', 'tgd-site-kit' ) ); ?>
	</form>
	<?php
}

/**
 * Add the per-page box to every public post type but attachments.
 */
function add_meta_box() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );
	\add_meta_box( 'tgdsk-schema', __( 'Schema (TGD Site Kit)', 'tgd-site-kit' ), __NAMESPACE__ . '\\render_meta_box', array_values( $types ), 'normal', 'low' );
}

/**
 * Render the per-page box.
 *
 * @param \WP_Post $post Post.
 */
function render_meta_box( $post ) {
	$type  = (string) get_post_meta( $post->ID, TYPE_META, true );
	$extra = (string) get_post_meta( $post->ID, EXTRA_META, true );
	wp_nonce_field( FIELDS_NONCE, FIELDS_NONCE );
	?>
	<p>
		<label for="tgdsk-schema-type"><strong><?php esc_html_e( 'Page type', 'tgd-site-kit' ); ?></strong></label>
		<select id="tgdsk-schema-type" name="tgdsk_schema_type">
			<option value=""><?php esc_html_e( 'Default for this page', 'tgd-site-kit' ); ?></option>
			<?php foreach ( PAGE_TYPES as $option ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>"<?php selected( $type, $option ); ?>><?php echo esc_html( $option ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p>
		<label for="tgdsk-schema-brand"><strong><?php esc_html_e( 'Brand', 'tgd-site-kit' ); ?></strong></label><br>
		<input type="text" id="tgdsk-schema-brand" name="tgdsk_schema_brand" class="regular-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, BRAND_META, true ) ); ?>">
		<span class="description"><?php esc_html_e( 'Makes the page a CollectionPage about that brand.', 'tgd-site-kit' ); ?></span>
	</p>
	<p>
		<label for="tgdsk-schema-extra"><strong><?php esc_html_e( 'Extra nodes (JSON)', 'tgd-site-kit' ); ?></strong></label><br>
		<textarea id="tgdsk-schema-extra" name="tgdsk_schema_extra" class="large-text code" rows="4"><?php echo esc_textarea( $extra ); ?></textarea>
		<span class="description">
			<?php
			esc_html_e( 'One object or an array of them, such as an Event. Added to this page’s graph.', 'tgd-site-kit' );
			if ( '' !== $extra && ! is_array( json_decode( $extra, true ) ) ) {
				echo ' <strong>' . esc_html__( 'This is not valid JSON, so it is being left out of the page.', 'tgd-site-kit' ) . '</strong>';
			}
			?>
		</span>
	</p>
	<?php
}

/**
 * Save the per-page box. The extra JSON is stored as typed: a typo is shown
 * in the box and skipped on the page, never rewritten.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post.
 */
function save_post_fields( $post_id, $post ) {
	if ( ! isset( $_POST[ FIELDS_NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ FIELDS_NONCE ] ) ), FIELDS_NONCE ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'revision' === $post->post_type ) {
		return;
	}
	if ( ! current_user_can( OWNER_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$type  = isset( $_POST['tgdsk_schema_type'] ) ? sanitize_text_field( wp_unslash( $_POST['tgdsk_schema_type'] ) ) : '';
	$brand = isset( $_POST['tgdsk_schema_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['tgdsk_schema_brand'] ) ) : '';
	// JSON, stored as typed; it is only ever printed through json_decode() and wp_json_encode().
	$extra  = isset( $_POST['tgdsk_schema_extra'] ) ? trim( wp_check_invalid_utf8( wp_unslash( $_POST['tgdsk_schema_extra'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$values = array(
		TYPE_META  => in_array( $type, PAGE_TYPES, true ) ? $type : '',
		BRAND_META => $brand,
		EXTRA_META => $extra,
	);
	foreach ( $values as $key => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}
}
