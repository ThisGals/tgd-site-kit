<?php
/**
 * Per-post and per-term SEO fields in wp-admin.
 *
 * A meta box on every public post type and a block of fields on every public
 * taxonomy's edit screen. Owner only, as the rest of the kit is: the box is not
 * shown to anyone without OWNER_CAP, and the save handlers check both that and
 * the object-level capability, plus a nonce, before writing.
 *
 * A field left empty deletes its key rather than storing an empty string, so
 * "unset" means one thing in the database and the import can tell it apart
 * from a value somebody typed.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

use const TGD_Site_Kit\OWNER_CAP;

defined( 'ABSPATH' ) || exit;

const FIELDS_NONCE = 'tgdsk_seo_fields';

/**
 * Add the field hooks.
 */
function add_field_hooks() {
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\add_meta_box' );
	add_action( 'save_post', __NAMESPACE__ . '\\save_post_fields', 10, 2 );
	add_action( 'init', __NAMESPACE__ . '\\add_term_field_hooks', 99 );
}

/**
 * Text fields shared by posts and terms: form name => meta key suffix.
 *
 * @return array<string, string>
 */
function text_fields() {
	return array(
		'title'       => 'title',
		'description' => 'description',
		'canonical'   => 'canonical',
	);
}

/**
 * Add the meta box to every public post type but attachments.
 */
function add_meta_box() {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$types = get_post_types( array( 'public' => true ) );
	unset( $types['attachment'] );
	\add_meta_box( 'tgdsk-seo', __( 'SEO (TGD Site Kit)', 'tgd-site-kit' ), __NAMESPACE__ . '\\render_meta_box', array_values( $types ), 'normal', 'low' );
}

/**
 * Render the post meta box.
 *
 * @param \WP_Post $post Post.
 */
function render_meta_box( $post ) {
	$get = fn( $key ) => get_post_meta( $post->ID, POST_META_PREFIX . $key, true );
	wp_nonce_field( FIELDS_NONCE, FIELDS_NONCE );
	render_status_line( 'publish' === $post->post_status ? get_permalink( $post ) : '' );
	render_common_fields( $get );
	?>
	<p>
		<label><input type="checkbox" name="tgdsk_seo[noindex]" value="1"<?php checked( (bool) $get( 'noindex' ) ); ?>> <?php esc_html_e( 'noindex', 'tgd-site-kit' ); ?></label>
		&nbsp; <label><input type="checkbox" name="tgdsk_seo[nofollow]" value="1"<?php checked( (bool) $get( 'nofollow' ) ); ?>> <?php esc_html_e( 'nofollow', 'tgd-site-kit' ); ?></label>
		&nbsp; <label><input type="checkbox" name="tgdsk_seo[index]" value="1"<?php checked( (bool) $get( 'index' ) ); ?>> <?php esc_html_e( 'index anyway (when this post type is noindexed by default)', 'tgd-site-kit' ); ?></label>
		&nbsp; <label><input type="checkbox" name="tgdsk_seo[follow]" value="1"<?php checked( (bool) $get( 'follow' ) ); ?>> <?php esc_html_e( 'follow anyway', 'tgd-site-kit' ); ?></label>
	</p>
	<p>
		<label for="tgdsk-seo-adv"><?php esc_html_e( 'Other robots directives (comma separated)', 'tgd-site-kit' ); ?></label><br>
		<input type="text" id="tgdsk-seo-adv" name="tgdsk_seo[robots_adv]" class="large-text code" value="<?php echo esc_attr( (string) $get( 'robots_adv' ) ); ?>">
	</p>
	<?php
}

/**
 * Title, description, canonical and both social blocks: the fields posts and
 * terms have in common.
 *
 * @param callable $get Takes a key suffix, returns the stored value.
 */
function render_common_fields( callable $get ) {
	$labels = array(
		'title'       => __( 'Title', 'tgd-site-kit' ),
		'description' => __( 'Meta description', 'tgd-site-kit' ),
		'canonical'   => __( 'Canonical URL', 'tgd-site-kit' ),
	);
	foreach ( text_fields() as $name => $key ) {
		?>
		<p>
			<label for="tgdsk-seo-<?php echo esc_attr( $name ); ?>"><strong><?php echo esc_html( $labels[ $name ] ); ?></strong></label><br>
			<?php if ( 'description' === $name ) : ?>
				<textarea id="tgdsk-seo-<?php echo esc_attr( $name ); ?>" name="tgdsk_seo[<?php echo esc_attr( $name ); ?>]" class="large-text" rows="2"><?php echo esc_textarea( (string) $get( $key ) ); ?></textarea>
			<?php else : ?>
				<input type="text" id="tgdsk-seo-<?php echo esc_attr( $name ); ?>" name="tgdsk_seo[<?php echo esc_attr( $name ); ?>]" class="large-text" value="<?php echo esc_attr( (string) $get( $key ) ); ?>">
			<?php endif; ?>
		</p>
		<?php
	}
	foreach ( array(
		'opengraph' => __( 'Open Graph', 'tgd-site-kit' ),
		'twitter'   => __( 'Twitter card', 'tgd-site-kit' ),
	) as $network => $label ) {
		$value = $get( $network );
		$value = is_array( $value ) ? $value : array();
		?>
		<fieldset style="margin:12px 0">
			<legend><strong><?php echo esc_html( $label ); ?></strong></legend>
			<label><input type="checkbox" name="tgdsk_seo[<?php echo esc_attr( $network ); ?>][disabled]" value="1"<?php checked( ! empty( $value['disabled'] ) ); ?>> <?php esc_html_e( 'Off for this item', 'tgd-site-kit' ); ?></label><br>
			<input type="text" name="tgdsk_seo[<?php echo esc_attr( $network ); ?>][title]" class="large-text" placeholder="<?php esc_attr_e( 'Title (defaults to the title above)', 'tgd-site-kit' ); ?>" value="<?php echo esc_attr( (string) ( $value['title'] ?? '' ) ); ?>"><br>
			<input type="text" name="tgdsk_seo[<?php echo esc_attr( $network ); ?>][description]" class="large-text" placeholder="<?php esc_attr_e( 'Description (defaults to the description above)', 'tgd-site-kit' ); ?>" value="<?php echo esc_attr( (string) ( $value['description'] ?? '' ) ); ?>"><br>
			<input type="text" name="tgdsk_seo[<?php echo esc_attr( $network ); ?>][images]" class="regular-text code" placeholder="<?php esc_attr_e( 'Image attachment IDs or URLs, comma separated', 'tgd-site-kit' ); ?>" value="<?php echo esc_attr( implode( ',', array_map( 'strval', (array) ( $value['images'] ?? array() ) ) ) ); ?>">
		</fieldset>
		<?php
	}
}

/**
 * The line at the top of the box: who is printing tags, and a preview link.
 *
 * @param string $url Front-end URL of the object, or '' if it has none yet.
 */
function render_status_line( $url ) {
	echo '<p class="description">';
	if ( smartcrawl_active() ) {
		esc_html_e( 'SmartCrawl is active, so SmartCrawl’s tags are the ones on the page. These values take over when SmartCrawl is switched off.', 'tgd-site-kit' );
	} else {
		esc_html_e( 'Empty fields fall back to the site defaults under Settings → TGD Site Kit → SEO.', 'tgd-site-kit' );
	}
	if ( $url ) {
		echo ' <a href="' . esc_url( add_query_arg( 'tgdsk_seo_preview', '1', $url ) ) . '" target="_blank">' . esc_html__( 'Preview tags (view the page source)', 'tgd-site-kit' ) . '</a>';
	}
	echo '</p>';
}

/**
 * Read the submitted fields into key => value, empty meaning delete.
 *
 * @param array $raw  Unslashed `tgdsk_seo` array from the request.
 * @param array $keys Checkbox keys to read beyond the shared ones.
 * @return array<string, mixed>
 */
function read_submitted_fields( array $raw, array $keys ) {
	$out = array();
	foreach ( text_fields() as $name => $key ) {
		$value       = isset( $raw[ $name ] ) ? (string) $raw[ $name ] : '';
		$out[ $key ] = 'canonical' === $name ? esc_url_raw( trim( $value ) ) : sanitize_template( $value );
	}
	foreach ( $keys as $key ) {
		$out[ $key ] = empty( $raw[ $key ] ) ? '' : '1';
	}
	if ( array_key_exists( 'robots_adv', $raw ) ) {
		$directives        = array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw['robots_adv'] ) ) );
		$out['robots_adv'] = implode( ',', $directives );
	}
	foreach ( array( 'opengraph', 'twitter' ) as $network ) {
		$block  = isset( $raw[ $network ] ) && is_array( $raw[ $network ] ) ? $raw[ $network ] : array();
		$images = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $block['images'] ?? '' ) ) ) ) as $image ) {
			$images[] = ctype_digit( $image ) ? (int) $image : esc_url_raw( $image );
		}
		$value           = array_filter(
			array(
				'title'       => sanitize_template( $block['title'] ?? '' ),
				'description' => sanitize_template( $block['description'] ?? '' ),
				'images'      => array_values( array_filter( $images ) ),
				'disabled'    => ! empty( $block['disabled'] ),
			)
		);
		$out[ $network ] = $value ? $value : '';
	}
	return $out;
}

/**
 * Save the post meta box.
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
	$raw = isset( $_POST['tgdsk_seo'] ) && is_array( $_POST['tgdsk_seo'] ) ? wp_unslash( $_POST['tgdsk_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in read_submitted_fields().
	foreach ( read_submitted_fields( $raw, array( 'noindex', 'nofollow', 'index', 'follow' ) ) as $key => $value ) {
		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, POST_META_PREFIX . $key );
		} else {
			update_post_meta( $post_id, POST_META_PREFIX . $key, $value );
		}
	}
}

/**
 * Hook the term fields onto every public taxonomy, once they are registered.
 */
function add_term_field_hooks() {
	foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
		add_action( "{$taxonomy}_edit_form_fields", __NAMESPACE__ . '\\render_term_fields', 99 );
		add_action( "edited_{$taxonomy}", __NAMESPACE__ . '\\save_term_fields' );
	}
}

/**
 * Render the term fields, as one table row so they sit in the edit form.
 *
 * @param \WP_Term $term Term.
 */
function render_term_fields( $term ) {
	if ( ! current_user_can( OWNER_CAP ) ) {
		return;
	}
	$get  = fn( $key ) => get_term_meta( $term->term_id, TERM_META_PREFIX . $key, true );
	$link = get_term_link( $term );
	?>
	<tr class="form-field">
		<th scope="row"><?php esc_html_e( 'SEO (TGD Site Kit)', 'tgd-site-kit' ); ?></th>
		<td>
			<?php wp_nonce_field( FIELDS_NONCE, FIELDS_NONCE ); ?>
			<?php render_status_line( is_wp_error( $link ) ? '' : $link ); ?>
			<?php render_common_fields( $get ); ?>
			<p>
				<label><input type="checkbox" name="tgdsk_seo[noindex]" value="1"<?php checked( (bool) $get( 'noindex' ) ); ?>> noindex</label>
				&nbsp; <label><input type="checkbox" name="tgdsk_seo[nofollow]" value="1"<?php checked( (bool) $get( 'nofollow' ) ); ?>> nofollow</label>
				&nbsp; <label><input type="checkbox" name="tgdsk_seo[override_noindex]" value="1"<?php checked( (bool) $get( 'override_noindex' ) ); ?>> <?php esc_html_e( 'index anyway (when this taxonomy is noindexed by default)', 'tgd-site-kit' ); ?></label>
				&nbsp; <label><input type="checkbox" name="tgdsk_seo[override_nofollow]" value="1"<?php checked( (bool) $get( 'override_nofollow' ) ); ?>> <?php esc_html_e( 'follow anyway', 'tgd-site-kit' ); ?></label>
			</p>
		</td>
	</tr>
	<?php
}

/**
 * Save the term fields.
 *
 * @param int $term_id Term ID.
 */
function save_term_fields( $term_id ) {
	if ( ! isset( $_POST[ FIELDS_NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ FIELDS_NONCE ] ) ), FIELDS_NONCE ) ) {
		return;
	}
	if ( ! current_user_can( OWNER_CAP ) || ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$raw = isset( $_POST['tgdsk_seo'] ) && is_array( $_POST['tgdsk_seo'] ) ? wp_unslash( $_POST['tgdsk_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized in read_submitted_fields().
	foreach ( read_submitted_fields( $raw, array( 'noindex', 'nofollow', 'override_noindex', 'override_nofollow' ) ) as $key => $value ) {
		if ( '' === $value || array() === $value ) {
			delete_term_meta( $term_id, TERM_META_PREFIX . $key );
		} else {
			update_term_meta( $term_id, TERM_META_PREFIX . $key, $value );
		}
	}
}
