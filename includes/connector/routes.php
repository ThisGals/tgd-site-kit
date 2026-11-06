<?php
/**
 * The connector's read and write routes beyond /status.
 *
 * - `GET|POST /tgdsk/v1/seo/post/<id>` and `/seo/term/<id>`: one object's SEO
 *   values (and, for posts, the schema module's per-page fields).
 * - `GET|POST /tgdsk/v1/redirects`, `/redirects/<id>`: while the redirects
 *   module is on.
 * - `GET /tgdsk/v1/not-found`: the 404 log's top paths, while that module is on.
 * - `GET /tgdsk/v1/indexnow`: IndexNow's state and recent pings, while that
 *   module is on.
 *
 * Administrators only, like /status. Every write is guarded: an SEO write
 * names the `version` it read (or the value each field had), a redirect
 * update names the `modified_gmt` it read, and anything changed since is
 * refused with 409 and nothing written. Every value is validated the way the
 * screens validate it. A write answers with what is stored now, read back,
 * and for SEO what the page will print, so the caller can check without a
 * second request.
 *
 * Nothing here writes post content, users, plugins or settings, and nothing
 * deletes: a redirect is switched off, not removed.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Connector;

use TGD_Site_Kit\SEO;
use TGD_Site_Kit\Schema;
use TGD_Site_Kit\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Register the routes. Called from register_routes().
 */
function register_more_routes() {
	$admin = __NAMESPACE__ . '\\can_read_status';
	foreach ( array( 'post', 'term' ) as $kind ) {
		register_rest_route(
			REST_NAMESPACE,
			'/seo/' . $kind . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => rest_seo_get( $kind, $request ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( $request ) => rest_seo_update( $kind, $request ),
					'permission_callback' => $admin,
				),
			)
		);
	}
	if ( \TGD_Site_Kit\is_enabled( 'redirects' ) ) {
		register_rest_route(
			REST_NAMESPACE,
			'/redirects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => __NAMESPACE__ . '\\rest_redirects_list',
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => __NAMESPACE__ . '\\rest_redirect_create',
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			REST_NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => __NAMESPACE__ . '\\rest_redirect_get',
					'permission_callback' => $admin,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => __NAMESPACE__ . '\\rest_redirect_update',
					'permission_callback' => $admin,
				),
			)
		);
	}
	if ( \TGD_Site_Kit\is_enabled( 'indexnow' ) ) {
		register_rest_route(
			REST_NAMESPACE,
			'/indexnow',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_indexnow',
				'permission_callback' => $admin,
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
	}
	if ( \TGD_Site_Kit\is_enabled( 'not_found' ) ) {
		register_rest_route(
			REST_NAMESPACE,
			'/not-found',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\rest_not_found',
				'permission_callback' => $admin,
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 500,
					),
					'since' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}
}

/**
 * A REST error.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $status  HTTP status.
 * @param array  $data    More data.
 * @return \WP_Error
 */
function error( $code, $message, $status, array $data = array() ) {
	return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
}

/**
 * Field definitions for an object: API name => [meta key, kind].
 *
 * Kinds: `text` (titles and descriptions, sanitize_template()), `url`,
 * `flag`, `directives`, `social`, `enum:<list>`, `json_object`, `json`.
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  The post or term.
 * @return array<string, array{0: string, 1: string}>
 */
function seo_fields( $kind, $obj ) {
	$p      = SEO\POST_META_PREFIX;
	$fields = array(
		'title'       => array( $p . 'title', 'text' ),
		'description' => array( $p . 'description', 'text' ),
		'canonical'   => array( $p . 'canonical', 'url' ),
		'noindex'     => array( $p . 'noindex', 'flag' ),
		'nofollow'    => array( $p . 'nofollow', 'flag' ),
	);
	if ( 'post' === $kind ) {
		$fields += array(
			'index'          => array( $p . 'index', 'flag' ),
			'follow'         => array( $p . 'follow', 'flag' ),
			'robots_adv'     => array( $p . 'robots_adv', 'directives' ),
			'opengraph'      => array( $p . 'opengraph', 'social' ),
			'twitter'        => array( $p . 'twitter', 'social' ),
			'schema_type'    => array( Schema\TYPE_META, 'enum:' . implode( ',', Schema\PAGE_TYPES ) ),
			'schema_brand'   => array( Schema\BRAND_META, 'plain' ),
			'schema_page'    => array( Schema\PAGE_META, 'json_object' ),
			'schema_extra'   => array( Schema\EXTRA_META, 'json' ),
			'schema_article' => array( Schema\ARTICLE_META, 'enum:' . implode( ',', array_merge( Schema\ARTICLE_TYPES, array( 'None' ) ) ) ),
		);
		if ( 'post' !== $obj->post_type ) {
			unset( $fields['schema_article'] );
		}
	} else {
		$fields += array(
			'override_noindex'  => array( $p . 'override_noindex', 'flag' ),
			'override_nofollow' => array( $p . 'override_nofollow', 'flag' ),
			'opengraph'         => array( $p . 'opengraph', 'social' ),
			'twitter'           => array( $p . 'twitter', 'social' ),
		);
	}
	return $fields;
}

/**
 * The post or term a route names, if the kit's fields apply to it.
 *
 * @param string $kind `post` or `term`.
 * @param int    $id   ID.
 * @return \WP_Post|\WP_Term|\WP_Error
 */
function seo_object( $kind, $id ) {
	if ( 'post' === $kind ) {
		$post  = get_post( $id );
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		if ( ! $post || ! isset( $types[ $post->post_type ] ) || in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return error( 'tgdsk_not_found', __( 'No public post, page or entry with that ID.', 'tgd-site-kit' ), 404 );
		}
		return $post;
	}
	$term = get_term( $id );
	if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, get_taxonomies( array( 'public' => true ) ), true ) ) {
		return error( 'tgdsk_not_found', __( 'No term in a public taxonomy with that ID.', 'tgd-site-kit' ), 404 );
	}
	return $term;
}

/**
 * One stored value, shaped for the API: strings or null, flags as booleans,
 * social blocks as objects or null, JSON fields as the text stored.
 *
 * @param string $kind     `post` or `term`.
 * @param int    $id       Object ID.
 * @param string $meta_key Meta key.
 * @param string $type     Field kind.
 * @return mixed
 */
function stored_value( $kind, $id, $meta_key, $type ) {
	$value = 'post' === $kind ? get_post_meta( $id, $meta_key, true ) : get_term_meta( $id, $meta_key, true );
	if ( 'flag' === $type ) {
		return ! empty( $value );
	}
	if ( 'social' === $type ) {
		return is_array( $value ) && $value ? $value : null;
	}
	return '' === $value || null === $value || false === $value ? null : (string) $value;
}

/**
 * Every stored value for an object, and its version.
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  Post or term.
 * @return array{values: array, version: string}
 */
function seo_stored( $kind, $obj ) {
	$id     = 'post' === $kind ? $obj->ID : $obj->term_id;
	$values = array();
	foreach ( seo_fields( $kind, $obj ) as $name => list( $key, $type ) ) {
		$values[ $name ] = stored_value( $kind, $id, $key, $type );
	}
	return array(
		'values'  => $values,
		'version' => md5( (string) wp_json_encode( $values ) ),
	);
}

/**
 * What the page prints for an object now, from the SEO module's resolver.
 *
 * `printed_by` says whose tags are on the page: the kit's (SEO module on,
 * SmartCrawl off), SmartCrawl's (whose output the kit's resolver reproduces
 * once its settings are imported), or nobody's.
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  Post or term.
 * @return array
 */
function seo_renders( $kind, $obj ) {
	if ( 'post' === $kind ) {
		$entity = (int) get_option( 'page_for_posts' ) === $obj->ID ? new SEO\Posts_Page_Entity() : new SEO\Post_Entity( $obj->ID );
		$link   = 'publish' === $obj->post_status ? get_permalink( $obj ) : null;
	} else {
		$entity = new SEO\Term_Entity( $obj->term_id );
		$link   = get_term_link( $obj );
		$link   = is_wp_error( $link ) ? null : $link;
	}
	$clean = fn( $text ) => trim( wp_strip_all_tags( stripslashes( (string) $text ) ) );
	if ( SEO\smartcrawl_active() ) {
		$by = 'smartcrawl';
	} elseif ( \TGD_Site_Kit\is_enabled( 'seo' ) ) {
		$by = 'kit';
	} else {
		$by = 'none';
	}
	$images = fn( array $found ) => array_values( array_map( fn( $image ) => (string) ( $image[0] ?? '' ), $found ) );
	return array(
		'printed_by'         => $by,
		'link'               => $link,
		'title'              => $clean( $entity->title() ),
		'description'        => $clean( $entity->description() ),
		'canonical'          => $entity->canonical(),
		'robots'             => $entity->robots(),
		'opengraph'          => array(
			'enabled'     => $entity->opengraph_enabled(),
			'title'       => $clean( $entity->opengraph_title() ),
			'description' => $clean( $entity->opengraph_description() ),
			'images'      => $images( $entity->opengraph_images() ),
		),
		'twitter'            => array(
			'enabled'     => $entity->twitter_enabled(),
			'title'       => $clean( $entity->twitter_title() ),
			'description' => $clean( $entity->twitter_description() ),
			'images'      => $images( $entity->twitter_images() ),
		),
		'schema_printed'     => \TGD_Site_Kit\is_enabled( 'schema' ) && ! Schema\is_blocked(),
		'description_source' => description_source( $kind, $obj ),
		'elementor'          => 'post' === $kind && SEO\is_elementor_page( $obj ),
		'notes'              => render_notes( $kind, $obj ),
	);
}

/**
 * Where the description comes from: the object's own (`own`), a site
 * template that doesn't just say %%excerpt%% (`template`), or text generated
 * from the content (`generated`).
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  Post or term.
 * @return string
 */
function description_source( $kind, $obj ) {
	$own = 'post' === $kind ? get_post_meta( $obj->ID, SEO\POST_META_PREFIX . 'description', true ) : get_term_meta( $obj->term_id, SEO\TERM_META_PREFIX . 'description', true );
	if ( '' !== trim( (string) $own ) ) {
		return 'own';
	}
	$template = (string) SEO\onpage( 'metadesc-' . ( 'post' === $kind ? $obj->post_type : $obj->taxonomy ) );
	return '' === trim( $template ) || str_contains( $template, '%%excerpt%%' ) ? 'generated' : 'template';
}

/**
 * Page cache plugins whose copy of a page has to be purged before a change
 * to its head shows. Elementor is not one: its element cache holds widget
 * output, and the kit prints the head on every request.
 *
 * @return string[] Plugin names.
 */
function page_caches() {
	$known = array(
		'wp-hummingbird/wp-hummingbird.php'   => 'Hummingbird',
		'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'wp-rocket/wp-rocket.php'             => 'WP Rocket',
		'sg-cachepress/sg-cachepress.php'     => 'SiteGround Speed Optimizer',
		'breeze/breeze.php'                   => 'Breeze',
		'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
	);
	$out   = array();
	foreach ( $known as $basename => $name ) {
		if ( \TGD_Site_Kit\plugin_active( $basename ) ) {
			$out[] = $name;
		}
	}
	return $out;
}

/**
 * Plain-language notes for whoever reads `renders`.
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  Post or term.
 * @return string[]
 */
function render_notes( $kind, $obj ) {
	$notes = array();
	if ( 'post' === $kind && SEO\is_elementor_page( $obj ) ) {
		$notes[] = 'generated' === description_source( $kind, $obj )
			? __( 'Built with Elementor and with no description of its own: the description is generated from the page’s current layout. Set one to say what the page is for.', 'tgd-site-kit' )
			: __( 'Built with Elementor. A generated description or first image would come from the page’s current layout, not from Elementor’s saved text copy.', 'tgd-site-kit' );
	}
	$caches = page_caches();
	if ( $caches ) {
		/* translators: %s: plugin names */
		$notes[] = sprintf( __( 'A page cache is active (%s): purge this page there before checking the live head. Elementor’s own caches don’t hold the head.', 'tgd-site-kit' ), implode( ', ', $caches ) );
	}
	return $notes;
}

/**
 * The answer for an object: stored values, version, what renders.
 *
 * @param string $kind `post` or `term`.
 * @param mixed  $obj  Post or term.
 * @param array  $more Extra keys.
 * @return \WP_REST_Response
 */
function seo_response( $kind, $obj, array $more = array() ) {
	$stored   = seo_stored( $kind, $obj );
	$response = new \WP_REST_Response(
		array_merge(
			array(
				'kind'    => $kind,
				'id'      => 'post' === $kind ? $obj->ID : $obj->term_id,
				'type'    => 'post' === $kind ? $obj->post_type : $obj->taxonomy,
				'name'    => 'post' === $kind ? $obj->post_title : $obj->name,
				'status'  => 'post' === $kind ? $obj->post_status : null,
				'version' => $stored['version'],
				'values'  => $stored['values'],
				'renders' => seo_renders( $kind, $obj ),
			),
			$more
		)
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * GET one object's SEO values.
 *
 * @param string           $kind    `post` or `term`.
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_seo_get( $kind, \WP_REST_Request $request ) {
	$obj = seo_object( $kind, (int) $request['id'] );
	return is_wp_error( $obj ) ? $obj : seo_response( $kind, $obj );
}

/**
 * Check and clean one incoming value. Null, '' and false mean "remove".
 *
 * @param string $name Field.
 * @param string $type Field kind.
 * @param mixed  $raw  Value sent.
 * @return array{0: mixed}|\WP_Error The value to store ('' to delete), wrapped.
 */
function clean_value( $name, $type, $raw ) {
	/* translators: %s: field name */
	$bad = fn( $why ) => error( 'tgdsk_invalid_value', sprintf( __( '%s: ', 'tgd-site-kit' ), $name ) . $why, 400, array( 'field' => $name ) );
	if ( null === $raw || '' === $raw || false === $raw ) {
		return array( '' );
	}
	switch ( true ) {
		case 'text' === $type:
			if ( ! is_string( $raw ) ) {
				return $bad( __( 'must be a string', 'tgd-site-kit' ) );
			}
			return array( SEO\sanitize_template( $raw ) );
		case 'plain' === $type:
			if ( ! is_string( $raw ) ) {
				return $bad( __( 'must be a string', 'tgd-site-kit' ) );
			}
			return array( sanitize_text_field( $raw ) );
		case 'url' === $type:
			if ( ! is_string( $raw ) || ! preg_match( '#^https?://[^\s/]+#i', trim( $raw ) ) ) {
				return $bad( __( 'must be a full http(s) URL', 'tgd-site-kit' ) );
			}
			return array( esc_url_raw( trim( $raw ) ) );
		case 'flag' === $type:
			if ( ! is_bool( $raw ) && ! in_array( $raw, array( 0, 1, '0', '1' ), true ) ) {
				return $bad( __( 'must be true or false', 'tgd-site-kit' ) );
			}
			return array( $raw ? '1' : '' );
		case 'directives' === $type:
			// The same check as the SEO box: stored as entered, or refused.
			$directives = SEO\parse_robots_directives( $raw );
			if ( is_wp_error( $directives ) ) {
				return $bad( $directives->get_error_message() );
			}
			return array( $directives );
		case 'social' === $type:
			if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), array( 'title', 'description', 'images', 'disabled' ) ) ) {
				return $bad( __( 'must be an object with title, description, images and disabled only', 'tgd-site-kit' ) );
			}
			$images = array();
			foreach ( (array) ( $raw['images'] ?? array() ) as $image ) {
				if ( is_int( $image ) || ( is_string( $image ) && ctype_digit( $image ) ) ) {
					$images[] = (int) $image;
				} elseif ( is_string( $image ) && preg_match( '#^https?://#i', $image ) ) {
					$images[] = esc_url_raw( $image );
				} else {
					return $bad( __( 'images must be attachment IDs or http(s) URLs', 'tgd-site-kit' ) );
				}
			}
			$value = array_filter(
				array(
					'title'       => SEO\sanitize_template( $raw['title'] ?? '' ),
					'description' => SEO\sanitize_template( $raw['description'] ?? '' ),
					'images'      => array_values( array_filter( $images ) ),
					'disabled'    => ! empty( $raw['disabled'] ),
				)
			);
			return array( $value ? $value : '' );
		case str_starts_with( $type, 'enum:' ):
			$allowed = explode( ',', substr( $type, 5 ) );
			if ( ! in_array( $raw, $allowed, true ) ) {
				/* translators: %s: allowed values */
				return $bad( sprintf( __( 'must be one of %s', 'tgd-site-kit' ), implode( ', ', $allowed ) ) );
			}
			return array( $raw );
		case 'json_object' === $type:
		case 'json' === $type:
			$text    = is_string( $raw ) ? trim( $raw ) : (string) wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$decoded = json_decode( $text, true );
			if ( 'json_object' === $type && null === Schema\json_object( $text ) ) {
				return $bad( __( 'must be one JSON object', 'tgd-site-kit' ) );
			}
			if ( 'json' === $type && ! is_array( $decoded ) ) {
				return $bad( __( 'must be a JSON object or an array of objects', 'tgd-site-kit' ) );
			}
			return array( wp_check_invalid_utf8( $text ) );
	}
	return $bad( __( 'unknown field', 'tgd-site-kit' ) );
}

/**
 * POST new SEO values for one object.
 *
 * Body: `values` (field => value; null removes it), and either
 * `expected_version` (the version a GET returned) or `expected` (field =>
 * the value it had when read, for each field being written).
 *
 * @param string           $kind    `post` or `term`.
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_seo_update( $kind, \WP_REST_Request $request ) {
	$obj = seo_object( $kind, (int) $request['id'] );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	$body   = (array) $request->get_json_params();
	$values = $body['values'] ?? null;
	if ( ! is_array( $values ) || ! $values || array_is_list( $values ) ) {
		return error( 'tgdsk_no_values', __( 'Send `values`: an object of field => new value.', 'tgd-site-kit' ), 400 );
	}
	$fields  = seo_fields( $kind, $obj );
	$unknown = array_diff( array_keys( $values ), array_keys( $fields ) );
	if ( $unknown ) {
		/* translators: 1: unknown fields, 2: allowed fields */
		return error( 'tgdsk_unknown_field', sprintf( __( 'Unknown fields: %1$s. Allowed here: %2$s.', 'tgd-site-kit' ), implode( ', ', $unknown ), implode( ', ', array_keys( $fields ) ) ), 400 );
	}

	$stored = seo_stored( $kind, $obj );
	if ( isset( $body['expected_version'] ) ) {
		if ( (string) $body['expected_version'] !== $stored['version'] ) {
			return error( 'tgdsk_changed_since_read', __( 'These SEO values changed since they were read. Nothing was written; read them again.', 'tgd-site-kit' ), 409, array( 'version' => $stored['version'] ) );
		}
	} elseif ( isset( $body['expected'] ) && is_array( $body['expected'] ) ) {
		foreach ( array_keys( $values ) as $name ) {
			if ( ! array_key_exists( $name, $body['expected'] ) ) {
				/* translators: %s: field name */
				return error( 'tgdsk_guard_missing', sprintf( __( '`expected` must give the current value of every field being written; %s is missing.', 'tgd-site-kit' ), $name ), 400 );
			}
			if ( $body['expected'][ $name ] !== $stored['values'][ $name ] ) {
				/* translators: %s: field name */
				return error( 'tgdsk_changed_since_read', sprintf( __( '%s changed since it was read. Nothing was written; read it again.', 'tgd-site-kit' ), $name ), 409, array( 'field' => $name ) );
			}
		}
	} else {
		return error( 'tgdsk_guard_missing', __( 'Send `expected_version` from a GET of this object (or `expected` with each field’s current value), so a change made since is not overwritten.', 'tgd-site-kit' ), 400 );
	}

	// Validate everything before writing anything.
	$clean = array();
	foreach ( $values as $name => $raw ) {
		$value = clean_value( $name, $fields[ $name ][1], $raw );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$clean[ $name ] = $value[0];
	}

	$id = 'post' === $kind ? $obj->ID : $obj->term_id;
	foreach ( $clean as $name => $value ) {
		$key = $fields[ $name ][0];
		if ( '' === $value || array() === $value ) {
			'post' === $kind ? delete_post_meta( $id, $key ) : delete_term_meta( $id, $key );
		} elseif ( 'post' === $kind ) {
			// The meta API unslashes what it stores; slash first so a
			// backslash in a title or in JSON reads back as sent.
			update_post_meta( $id, $key, wp_slash( $value ) );
		} else {
			update_term_meta( $id, $key, wp_slash( $value ) );
		}
	}

	$after    = seo_stored( $kind, $obj );
	$adjusted = array();
	foreach ( $values as $name => $raw ) {
		$type = $fields[ $name ][1];
		$sent = null === $raw || '' === $raw || false === $raw ? ( 'flag' === $type ? false : null ) : $raw;
		if ( 'flag' === $type ) {
			$sent = (bool) $sent;
		} elseif ( in_array( $type, array( 'json', 'json_object' ), true ) && null !== $sent ) {
			$sent = is_string( $sent ) ? trim( $sent ) : (string) wp_json_encode( $sent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( 'directives' === $type && is_array( $sent ) ) {
			$sent = implode( ',', $sent );
		}
		if ( $sent !== $after['values'][ $name ] ) {
			$adjusted[] = $name;
		}
	}
	return seo_response(
		$kind,
		$obj,
		array(
			'written'  => array_keys( $clean ),
			'adjusted' => $adjusted,
		)
	);
}

/**
 * Whether redirects are being sent, and if not, why.
 *
 * @return array{active: bool, held_by: string[]}
 */
function redirects_state() {
	$held = array();
	if ( Redirects\redirection_active() ) {
		$held[] = 'redirection';
	}
	if ( Redirects\smartcrawl_redirects_active() ) {
		$held[] = 'smartcrawl';
	}
	return array(
		'active'  => ! $held,
		'held_by' => $held,
	);
}

/**
 * GET every redirect.
 *
 * @return \WP_REST_Response
 */
function rest_redirects_list() {
	$response = new \WP_REST_Response( redirects_state() + array( 'redirects' => Redirects\all() ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * GET one redirect.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_redirect_get( \WP_REST_Request $request ) {
	$row = Redirects\get( (int) $request['id'] );
	if ( ! $row ) {
		return error( 'tgdsk_not_found', __( 'No redirect with that ID.', 'tgd-site-kit' ), 404 );
	}
	$response = new \WP_REST_Response( redirects_state() + array( 'redirect' => $row ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Redirect fields from a request body.
 *
 * @param array $body Body.
 * @param array $base Current values, for an update.
 * @return array|\WP_Error
 */
function redirect_input( array $body, array $base = array() ) {
	$allowed = array( 'source', 'target', 'status', 'is_regex', 'query_mode', 'match_case', 'match_trailing', 'enabled', 'note' );
	$unknown = array_diff( array_keys( $body ), array_merge( $allowed, array( 'expected_modified_gmt' ) ) );
	if ( $unknown ) {
		/* translators: 1: unknown fields, 2: allowed fields */
		return error( 'tgdsk_unknown_field', sprintf( __( 'Unknown fields: %1$s. Allowed: %2$s.', 'tgd-site-kit' ), implode( ', ', $unknown ), implode( ', ', $allowed ) ), 400 );
	}
	return array_merge( array_intersect_key( $base, array_flip( $allowed ) ), array_intersect_key( $body, array_flip( $allowed ) ) );
}

/**
 * POST a new redirect.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_redirect_create( \WP_REST_Request $request ) {
	$input = redirect_input( (array) $request->get_json_params() );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$row = Redirects\validate( $input );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$id = Redirects\insert( $row, array( 'origin' => 'jarvis' ) );
	if ( ! $id ) {
		return error( 'tgdsk_write_failed', __( 'The redirect could not be stored.', 'tgd-site-kit' ), 500 );
	}
	$response = new \WP_REST_Response( redirects_state() + array( 'redirect' => Redirects\get( $id ) ), 201 );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * POST changes to a redirect. Needs `expected_modified_gmt` from a read.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_redirect_update( \WP_REST_Request $request ) {
	$id      = (int) $request['id'];
	$current = Redirects\get( $id );
	if ( ! $current ) {
		return error( 'tgdsk_not_found', __( 'No redirect with that ID.', 'tgd-site-kit' ), 404 );
	}
	$body = (array) $request->get_json_params();
	if ( empty( $body['expected_modified_gmt'] ) ) {
		return error( 'tgdsk_guard_missing', __( 'Send `expected_modified_gmt` from a read of this redirect, so a change made since is not overwritten.', 'tgd-site-kit' ), 400 );
	}
	if ( (string) $body['expected_modified_gmt'] !== $current['modified_gmt'] ) {
		return error( 'tgdsk_changed_since_read', __( 'This redirect changed since it was read. Nothing was written; read it again.', 'tgd-site-kit' ), 409, array( 'modified_gmt' => $current['modified_gmt'] ) );
	}
	$input = redirect_input( $body, $current );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$row = Redirects\validate( $input, $id );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	// The same second as the read would leave modified_gmt unchanged, and a
	// stale guard would then pass; wait it out.
	if ( current_time( 'mysql', true ) === $current['modified_gmt'] ) {
		sleep( 1 );
	}
	Redirects\update( $id, $row );
	$response = new \WP_REST_Response( redirects_state() + array( 'redirect' => Redirects\get( $id ) ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * GET the 404 log's top paths.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function rest_not_found( \WP_REST_Request $request ) {
	$response = new \WP_REST_Response( \TGD_Site_Kit\Not_Found\top( (int) $request['limit'], (string) $request['since'] ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * GET the IndexNow module's state and recent pings.
 *
 * @param \WP_REST_Request $request Request.
 * @return \WP_REST_Response
 */
function rest_indexnow( \WP_REST_Request $request ) {
	$response = new \WP_REST_Response( \TGD_Site_Kit\IndexNow\state( (int) $request['limit'] ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
