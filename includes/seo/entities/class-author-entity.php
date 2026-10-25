<?php
/**
 * Author_Entity: an author archive.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * An author archive.
 *
 * SmartCrawl noindexes every author archive unless author archives are
 * switched on in its settings (`enable-author-archive`), which on most of these
 * sites they are not: the sites have one author, and the archive repeats the
 * blog. Leaving that out of the port would make those pages indexable on the
 * day of the switch.
 */
class Author_Entity extends Entity {

	/**
	 * The author.
	 *
	 * @var \WP_User|null
	 */
	private $user;

	/**
	 * Archive page number.
	 *
	 * @var int
	 */
	private $page_number;

	/**
	 * Constructor.
	 *
	 * @param \WP_User|false $user        Author.
	 * @param int            $page_number Archive page number.
	 */
	public function __construct( $user, $page_number = 0 ) {
		$this->user        = $user instanceof \WP_User ? $user : null;
		$this->page_number = (int) $page_number;
	}

	/**
	 * Settings location.
	 *
	 * @return string
	 */
	protected function location() {
		return 'author';
	}

	/**
	 * One of the author's own SEO values.
	 *
	 * @param string $key Key without the prefix.
	 * @return string
	 */
	private function own( $key ) {
		return $this->user ? (string) get_user_meta( $this->user->ID, USER_META_PREFIX . $key, true ) : '';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	protected function load_title() {
		return $this->string_value( 'author', fn() => $this->own( 'title' ), fn( $loc ) => onpage( "title-{$loc}" ), fn() => '%%name%% %%sep%% %%sitename%%' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function load_description() {
		return $this->string_value( 'author', fn() => $this->own( 'description' ), fn( $loc ) => onpage( "metadesc-{$loc}" ), fn() => '%%user_description%%' );
	}

	/**
	 * Robots for this page.
	 *
	 * @return string
	 */
	protected function load_robots() {
		return $this->robots_for_page( $this->page_number );
	}

	/**
	 * Robots for a page: always noindex while author archives are off.
	 *
	 * @param int $page_number Page number.
	 * @return string
	 */
	private function robots_for_page( $page_number ) {
		if ( ! onpage( 'enable-author-archive' ) ) {
			return 'noindex,follow';
		}
		return $this->archive_robots( 'author', $page_number );
	}

	/**
	 * Canonical.
	 *
	 * @return string
	 */
	protected function load_canonical() {
		if ( ! $this->user ) {
			return '';
		}
		return $this->archive_canonical( get_author_posts_url( $this->user->ID ), $this->page_number, fn( $page ) => $this->robots_for_page( $page ) );
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_opengraph_enabled() {
		return (bool) onpage( 'og-active-author' );
	}

	/**
	 * Location switch.
	 *
	 * @return bool
	 */
	protected function load_twitter_enabled() {
		return (bool) onpage( 'twitter-active-author' );
	}

	/**
	 * No own social text.
	 *
	 * @param string $network `og` or `twitter`.
	 * @param string $field   `title` or `description`.
	 * @return string
	 */
	protected function load_social_text( $network, $field ) {
		$fallback = 'title' === $field ? fn() => $this->title() : fn() => $this->description();
		return $this->option_string_value( 'author', fn( $loc ) => onpage( "{$network}-{$field}-{$loc}" ), $fallback );
	}

	/**
	 * The author's avatar when one really exists, else the location's
	 * defaults, else the first image in the author's bio.
	 *
	 * Whether a Gravatar exists is asked of gravatar.com once a day per author
	 * and cached, as SmartCrawl does; without that every author archive would
	 * share the grey placeholder.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function load_social_images( $network ) {
		$avatar = $this->avatar_image();
		if ( $avatar ) {
			return $avatar;
		}
		$images = $this->images_from_options( $network, 'author' );
		if ( $images ) {
			return $images;
		}
		if ( ! onpage( "{$network}-disable-first-image-author" ) ) {
			$first = first_image_in( $this->user ? (string) $this->user->description : '' );
			if ( $first ) {
				return array( $first => array( $first ) );
			}
		}
		return array();
	}

	/**
	 * The author's avatar as an image list, or nothing.
	 *
	 * @return array
	 */
	private function avatar_image() {
		if ( ! $this->user ) {
			return array();
		}
		$probe = get_avatar_url( $this->user->ID, array( 'default' => '404' ) );
		if ( ! $probe ) {
			return array();
		}
		if ( ! str_contains( $probe, 'gravatar.com' ) ) {
			$url = get_avatar_url( $this->user->ID );
			return array( $url => array( $url ) );
		}
		$key    = 'tgdsk_gravatar_exists_' . $this->user->ID;
		$exists = get_transient( $key );
		if ( false === $exists ) {
			$response = wp_remote_head( $probe );
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$exists = in_array( $code, array( 200, 302 ), true ) ? 'yes' : 'no';
			set_transient( $key, $exists, DAY_IN_SECONDS );
		}
		if ( 'yes' !== $exists ) {
			return array();
		}
		$url = get_avatar_url( $this->user->ID );
		return array( $url => array( $url ) );
	}

	/**
	 * Author macros.
	 *
	 * @param string $subject Unused.
	 * @return array
	 */
	public function macros( $subject = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature.
		if ( ! $this->user ) {
			return array();
		}
		$user = $this->user;
		return array(
			'%%name%%'             => $user->display_name,
			'%%userid%%'           => $user->ID,
			'%%user_description%%' => $user->description,
			'%%archive-title%%'    => fn() => get_the_archive_title(),
			'%%original-title%%'   => fn() => get_the_author(),
		);
	}
}
