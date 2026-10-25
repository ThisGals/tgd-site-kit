<?php
/**
 * Post_Entity: a single post, page or custom post type entry.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * A single post, page or custom post type entry.
 */
class Post_Entity extends Entity {

	/**
	 * The post.
	 *
	 * @var \WP_Post|null
	 */
	protected $post;

	/**
	 * Page of a paginated post (`<!--nextpage-->`), 0 for the first.
	 *
	 * @var int
	 */
	protected $page_number;

	/**
	 * Comment page, 0 when not on one. Comment pages are noindexed.
	 *
	 * @var int
	 */
	protected $comments_page;

	/**
	 * Constructor.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $page_number   Post page.
	 * @param int $comments_page Comment page.
	 */
	public function __construct( $post_id, $page_number = 0, $comments_page = 0 ) {
		$this->post          = get_post( $post_id );
		$this->page_number   = (int) $page_number;
		$this->comments_page = (int) $comments_page;
	}

	/**
	 * The post's type; a revision answers for its parent.
	 *
	 * @return string
	 */
	protected function location() {
		if ( ! $this->post ) {
			return '';
		}
		if ( 'revision' === $this->post->post_type && $this->post->post_parent ) {
			return (string) get_post_type( $this->post->post_parent );
		}
		return $this->post->post_type;
	}

	/**
	 * One of this post's own SEO values, or false when unset.
	 *
	 * @param string $key Key without the `_tgdsk_` prefix.
	 * @return mixed
	 */
	protected function own( $key ) {
		if ( ! $this->post ) {
			return false;
		}
		$value = get_post_meta( $this->post->ID, POST_META_PREFIX . $key, true );
		return empty( $value ) ? false : $value;
	}

	/**
	 * Raw excerpt, or the password notice.
	 *
	 * @return string
	 */
	public function excerpt() {
		if ( $this->post && post_password_required( $this->post ) ) {
			return __( 'This content is password protected.', 'tgd-site-kit' );
		}
		return $this->post ? $this->post->post_excerpt : '';
	}

	/**
	 * Raw content, or the password notice.
	 *
	 * @return string
	 */
	public function content() {
		if ( $this->post && post_password_required( $this->post ) ) {
			return __( 'This content is password protected.', 'tgd-site-kit' );
		}
		return $this->post ? $this->post->post_content : '';
	}

	/**
	 * Generated description: the excerpt, or the content, cut to length.
	 *
	 * @return string
	 */
	public function trimmed_excerpt() {
		return $this->memo( 'trimmed_excerpt', fn() => trimmed_excerpt( $this->excerpt(), $this->content() ) );
	}

	/**
	 * Title template chain.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'title' ),
			fn( $loc ) => onpage( "title-{$loc}" ),
			fn() => '%%title%% %%sep%% %%sitename%%'
		);
	}

	/**
	 * Description template chain.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->string_value(
			$this->location(),
			fn() => $this->own( 'description' ),
			fn( $loc ) => onpage( "metadesc-{$loc}" ),
			fn() => $this->trimmed_excerpt()
		);
	}

	/**
	 * `index|noindex,follow|nofollow[,advanced]`.
	 *
	 * The post's own flag answers the question the post type default leaves
	 * open: on a type noindexed by default a post can opt back in with `index`,
	 * and otherwise opt out with `noindex`.
	 *
	 * @return string
	 */
	protected function load_robots() {
		if ( ! $this->post ) {
			return '';
		}
		$type = $this->location();
		if ( $this->comments_page ) {
			$noindex = true;
		} elseif ( onpage( "meta_robots-noindex-{$type}" ) ) {
			$noindex = ! $this->own( 'index' );
		} else {
			$noindex = (bool) $this->own( 'noindex' );
		}
		$nofollow = onpage( "meta_robots-nofollow-{$type}" ) ? ! $this->own( 'follow' ) : (bool) $this->own( 'nofollow' );

		$robots = array( $noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow' );
		$adv    = $this->own( 'robots_adv' );
		if ( $adv && 'none' !== $adv ) {
			$robots[] = $adv;
		}
		return implode( ',', $robots );
	}

	/**
	 * The post's own canonical, else its permalink (with its page number).
	 * None at all when the post is noindexed.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		if ( ! $this->post || $this->is_noindex() ) {
			return '';
		}
		$own = $this->own( 'canonical' );
		if ( $own ) {
			return (string) $own;
		}
		$url = (string) get_permalink( $this->post );
		if ( $this->page_number > 1 ) {
			$url = get_option( 'permalink_structure' )
				? trailingslashit( $url ) . user_trailingslashit( (string) $this->page_number, 'single_paged' )
				: add_query_arg( 'page', $this->page_number, $url );
		}
		return $url;
	}

	/**
	 * Location switch, then the post's own "disabled".
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return $this->post && onpage( 'og-active-' . $this->location() ) && empty( $this->social_meta( 'og' )['disabled'] );
	}

	/**
	 * Location switch, then the post's own "disabled".
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return $this->post && onpage( 'twitter-active-' . $this->location() ) && empty( $this->social_meta( 'twitter' )['disabled'] );
	}

	/**
	 * The post's own social settings.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function social_meta( $network ) {
		$value = $this->own( 'og' === $network ? 'opengraph' : 'twitter' );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Own images, then the featured image, then the post type's default
	 * images, then the first `<img>` in the content.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function load_social_images( $network ) {
		if ( ! $this->post || post_password_required( $this->post ) ) {
			return array();
		}
		$images = array();
		foreach ( (array) ( $this->social_meta( $network )['images'] ?? array() ) as $image ) {
			if ( is_numeric( $image ) && wp_get_attachment_image_src( (int) $image ) ) {
				$images[] = (int) $image;
			} elseif ( is_string( $image ) && esc_url_raw( $image ) ) {
				$images[] = esc_url_raw( $image );
			}
		}
		if ( ! $images && get_post_thumbnail_id( $this->post ) ) {
			$images = array( get_post_thumbnail_id( $this->post ) );
		}
		if ( ! $images ) {
			$images = onpage( "{$network}-images-" . $this->location() );
		}
		if ( $images ) {
			return images_to_urls( (array) $images );
		}
		if ( ! onpage( "{$network}-disable-first-image-" . $this->location() ) ) {
			$first = first_image_in( $this->content() );
			if ( $first ) {
				return array( $first => array( $first ) );
			}
		}
		return array();
	}

	/**
	 * Pages and the front page are websites; everything else is an article.
	 *
	 * @return array
	 */
	public function opengraph_tags() {
		if ( ! $this->post ) {
			return array();
		}
		$tags = parent::opengraph_tags();
		if ( 'page' === $this->location() || ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $this->post->ID ) ) {
			$tags['og:type'] = 'website';
		} else {
			$tags['og:type']                = 'article';
			$tags['article:published_time'] = mysql2date( 'Y-m-d\TH:i:s', $this->post->post_date );
			$tags['article:author']         = $this->author_name();
		}
		return $tags;
	}

	/**
	 * Author display name.
	 *
	 * @return string
	 */
	public function author_name() {
		$user = $this->post ? get_userdata( (int) $this->post->post_author ) : false;
		return $user ? $user->display_name : '';
	}

	/**
	 * Post macros.
	 *
	 * @param string $subject Template, for dynamic macros.
	 * @return array
	 */
	public function macros( $subject = '' ) {
		if ( ! $this->post ) {
			return array();
		}
		$post = $this->post;
		$user = fn() => get_userdata( (int) $post->post_author );
		return array_merge(
			array(
				'%%date%%'             => fn() => mysql2date( get_option( 'date_format' ), $post->post_date ),
				'%%excerpt%%'          => fn() => $this->trimmed_excerpt(),
				'%%excerpt_only%%'     => fn() => $this->excerpt(),
				'%%id%%'               => $post->ID,
				'%%modified%%'         => $post->post_modified,
				'%%name%%'             => fn() => $this->author_name(),
				'%%title%%'            => $post->post_title,
				'%%userid%%'           => (int) $post->post_author,
				'%%user_description%%' => fn() => $user() ? $user()->description : '',
				'%%caption%%'          => fn() => $this->excerpt(),
				'%%category%%'         => fn() => get_the_category_list( ', ', '', $post->ID ),
			),
			$this->dynamic_macros(
				$subject,
				function ( $taxonomy ) use ( $post ) {
					$terms = get_the_terms( $post->ID, $taxonomy );
					return $terms && ! is_wp_error( $terms ) ? $terms : array();
				},
				fn( $key ) => get_post_meta( $post->ID, $key, true )
			)
		);
	}
}
