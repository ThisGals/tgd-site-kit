<?php
/**
 * Entity: shared resolution rules. One instance per page, memoising as it goes.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Shared resolution rules. One instance per page, memoising as it goes.
 */
abstract class Entity {

	/**
	 * Resolved values, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $memo = array();

	/**
	 * Resolve a value once.
	 *
	 * @param string   $key  Memo key.
	 * @param callable $load Loader.
	 * @return mixed
	 */
	protected function memo( $key, callable $load ) {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $load();
		}
		return $this->memo[ $key ];
	}

	/**
	 * The title, before core escapes and prints it.
	 *
	 * @return string
	 */
	public function title() {
		$value = (string) $this->memo( 'title', fn() => $this->load_title() );
		if ( lengths_enforced() && '' !== $value ) {
			$value = truncate( $value, title_max_length() );
		}
		return $value;
	}

	/**
	 * The meta description.
	 *
	 * @return string
	 */
	public function description() {
		$value = (string) $this->memo( 'description', fn() => $this->load_description() );
		if ( lengths_enforced() && '' !== $value ) {
			$value = truncate( $value, description_max_length() );
		}
		return $value;
	}

	/**
	 * Robots directives, comma separated, `index,follow` included.
	 *
	 * @return string
	 */
	public function robots() {
		return (string) $this->memo( 'robots', fn() => $this->load_robots() );
	}

	/**
	 * Canonical URL, or '' for none.
	 *
	 * @return string
	 */
	public function canonical() {
		return (string) $this->memo( 'canonical', fn() => $this->load_canonical() );
	}

	/**
	 * Whether the robots directives include noindex.
	 *
	 * @return bool
	 */
	public function is_noindex() {
		return str_contains( $this->robots(), 'noindex' );
	}

	/**
	 * Whether Open Graph tags are printed for this page.
	 *
	 * @return bool
	 */
	public function opengraph_enabled() {
		return (bool) $this->memo( 'og_enabled', fn() => $this->load_opengraph_enabled() );
	}

	/**
	 * Open Graph title.
	 *
	 * @return string
	 */
	public function opengraph_title() {
		return (string) $this->memo( 'og_title', fn() => $this->load_social_text( 'og', 'title' ) );
	}

	/**
	 * Open Graph description.
	 *
	 * @return string
	 */
	public function opengraph_description() {
		return (string) $this->memo( 'og_description', fn() => $this->load_social_text( 'og', 'description' ) );
	}

	/**
	 * Open Graph images, keyed by URL: url => array( url, width?, height? ).
	 *
	 * @return array
	 */
	public function opengraph_images() {
		return (array) $this->memo( 'og_images', fn() => $this->load_social_images( 'og' ) );
	}

	/**
	 * Whether Twitter card tags are printed for this page.
	 *
	 * @return bool
	 */
	public function twitter_enabled() {
		return (bool) $this->memo( 'tw_enabled', fn() => $this->load_twitter_enabled() );
	}

	/**
	 * Twitter title.
	 *
	 * @return string
	 */
	public function twitter_title() {
		return (string) $this->memo( 'tw_title', fn() => $this->load_social_text( 'twitter', 'title' ) );
	}

	/**
	 * Twitter description.
	 *
	 * @return string
	 */
	public function twitter_description() {
		return (string) $this->memo( 'tw_description', fn() => $this->load_social_text( 'twitter', 'description' ) );
	}

	/**
	 * Twitter images, same shape as opengraph_images().
	 *
	 * @return array
	 */
	public function twitter_images() {
		return (array) $this->memo( 'tw_images', fn() => $this->load_social_images( 'twitter' ) );
	}

	/**
	 * Open Graph tags as SmartCrawl builds them: scalars, then one nested array
	 * per image. Empty values are dropped by the printer, not here.
	 *
	 * @return array
	 */
	public function opengraph_tags() {
		$tags = array(
			'og:type'        => 'object',
			'og:url'         => $this->canonical(),
			'og:title'       => $this->opengraph_title(),
			'og:description' => $this->opengraph_description(),
		);
		return $this->add_image_tags( $tags );
	}

	/**
	 * Append one tag group per distinct image.
	 *
	 * An image known only by URL gets its dimensions from the attachment that
	 * URL belongs to, when there is one.
	 *
	 * @param array $tags Tags so far.
	 * @return array
	 */
	private function add_image_tags( array $tags ) {
		$seen = array();
		foreach ( $this->opengraph_images() as $image ) {
			$url    = $image[0] ?? '';
			$width  = $image[1] ?? 0;
			$height = $image[2] ?? 0;
			if ( ( ! $width || ! $height ) && $url ) {
				$id  = attachment_url_to_postid( trim( $url ) );
				$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
				if ( $src ) {
					$width  = $src[1];
					$height = $src[2];
				}
			}
			if ( in_array( $url, $seen, true ) ) {
				continue;
			}
			$group = array();
			if ( $url ) {
				$group['og:image'] = $url;
				if ( str_starts_with( $url, 'https://' ) ) {
					$group['og:image:secure_url'] = $url;
				}
			}
			if ( $width ) {
				$group['og:image:width'] = $width;
			}
			if ( $height ) {
				$group['og:image:height'] = $height;
			}
			if ( $group ) {
				$tags[] = $group;
			}
		}
		return $tags;
	}

	/**
	 * Load the title. Subclasses decide the sources.
	 *
	 * @return string
	 */
	abstract protected function load_title();

	/**
	 * Load the description.
	 *
	 * @return string
	 */
	abstract protected function load_description();

	/**
	 * Load the robots string.
	 *
	 * @return string
	 */
	abstract protected function load_robots();

	/**
	 * Load the canonical URL.
	 *
	 * @return string
	 */
	abstract protected function load_canonical();

	/**
	 * Whether Open Graph is on for this page.
	 *
	 * @return bool
	 */
	abstract protected function load_opengraph_enabled();

	/**
	 * Whether Twitter cards are on for this page.
	 *
	 * @return bool
	 */
	abstract protected function load_twitter_enabled();

	/**
	 * Social title or description: own value, then the location template, then
	 * the meta title or description.
	 *
	 * @param string $network `og` or `twitter`.
	 * @param string $field   `title` or `description`.
	 * @return string
	 */
	protected function load_social_text( $network, $field ) {
		$fallback = 'title' === $field ? fn() => $this->title() : fn() => $this->description();
		$own      = fn() => $this->social_meta( $network )[ $field ] ?? '';
		$location = $this->location();
		return $this->string_value(
			$location,
			$own,
			fn( $loc ) => onpage( "{$network}-{$field}-{$loc}" ),
			$fallback
		);
	}

	/**
	 * Social images from the location's site defaults. Archives use only these.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function load_social_images( $network ) {
		return $this->images_from_options( $network, $this->location() );
	}

	/**
	 * The object's own social settings: title, description, images, disabled.
	 *
	 * @param string $network `og` or `twitter`.
	 * @return array
	 */
	protected function social_meta( $network ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- overridden by objects that have their own.
		return array();
	}

	/**
	 * The settings location this page reads its defaults from.
	 *
	 * @return string
	 */
	abstract protected function location();

	/**
	 * Macros this page supplies, beyond the general ones.
	 *
	 * @param string $subject The template being expanded, for dynamic macros.
	 * @return array<string, callable|string>
	 */
	abstract public function macros( $subject = '' );

	/**
	 * Resolve a text value: own value, then location default, then fallback.
	 *
	 * SmartCrawl's load_string_value(). Each candidate is cleaned first and
	 * skipped if that leaves nothing; the first survivor is macro-expanded.
	 *
	 * @param string   $location     Location key. Empty means no value at all.
	 * @param callable $own          Returns the object's own value.
	 * @param callable $from_options Takes the location, returns its template.
	 * @param callable $fallback     Returns the built-in fallback.
	 * @return string
	 */
	protected function string_value( $location, callable $own, callable $from_options, callable $fallback ) {
		if ( ! $location ) {
			return '';
		}
		$value = clean( $own() );
		if ( '' !== $value ) {
			return $this->expand( $value );
		}
		return $this->option_string_value( $location, $from_options, $fallback );
	}

	/**
	 * Resolve a text value with no per-object source.
	 *
	 * @param string   $location     Location key.
	 * @param callable $from_options Takes the location, returns its template.
	 * @param callable $fallback     Returns the built-in fallback.
	 * @return string
	 */
	protected function option_string_value( $location, callable $from_options, callable $fallback ) {
		if ( ! $location ) {
			return '';
		}
		foreach ( array( $from_options( $location ), $fallback() ) as $candidate ) {
			$candidate = clean( $candidate );
			if ( '' !== $candidate ) {
				return $this->expand( $candidate );
			}
		}
		return '';
	}

	/**
	 * Expand `%%macro%%` placeholders, then delete any left unrecognised.
	 *
	 * Replacement values have tags stripped and are not whitespace-collapsed,
	 * which is where the double space in a cut excerpt survives.
	 *
	 * @param string $subject Template.
	 * @return string
	 */
	public function expand( $subject ) {
		if ( ! str_contains( $subject, '%%' ) ) {
			return $subject;
		}
		$macros = array_merge( general_macros(), $this->macros( $subject ) );
		foreach ( $macros as $macro => $replacement ) {
			if ( ! str_contains( $subject, $macro ) ) {
				continue;
			}
			$value = is_callable( $replacement ) ? call_user_func( $replacement ) : $replacement;
			// A bare `<` is the less-than separator, and strip_tags() would eat it.
			if ( '<' !== $value ) {
				$value = is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
			}
			$subject = str_replace( $macro, $value, $subject );
		}
		return preg_replace( '/%%[a-zA-Z0-9!@#$^&*_+-=:;~]+%%/', '', $subject );
	}

	/**
	 * Images configured for a location, keyed by URL.
	 *
	 * @param string $network  `og` or `twitter`.
	 * @param string $location Location key.
	 * @return array
	 */
	protected function images_from_options( $network, $location ) {
		$images = onpage( "{$network}-images-{$location}" );
		return is_array( $images ) && $images ? images_to_urls( $images ) : array();
	}

	/**
	 * Archive robots: the location's noindex and nofollow settings, or nothing
	 * on page one when the location only marks later pages.
	 *
	 * @param string $location    Location key.
	 * @param int    $page_number Archive page number, 0 for the first.
	 * @return string
	 */
	protected function archive_robots( $location, $page_number ) {
		if ( onpage( "meta_robots-{$location}-subsequent_pages" ) && $page_number < 2 ) {
			return '';
		}
		$index  = onpage( "meta_robots-noindex-{$location}" ) ? 'noindex' : 'index';
		$follow = onpage( "meta_robots-nofollow-{$location}" ) ? 'nofollow' : 'follow';
		return "{$index},{$follow}";
	}

	/**
	 * Archive canonical: this page if it is indexed, else page one if that is.
	 *
	 * @param string   $url         Archive's first-page URL.
	 * @param int      $page_number Archive page number.
	 * @param callable $robots_for  Takes a page number, returns its robots.
	 * @return string
	 */
	protected function archive_canonical( $url, $page_number, callable $robots_for ) {
		if ( ! $this->is_noindex() ) {
			return append_page_number( $url, $page_number );
		}
		return str_contains( $robots_for( 1 ), 'noindex' ) ? '' : $url;
	}

	/**
	 * Expand `%%ct_desc_{tax}%%`, `%%ct_{tax}%%` and `%%cf_{meta key}%%`.
	 *
	 * @param string   $subject   Template.
	 * @param callable $get_terms Takes a taxonomy, returns WP_Term[].
	 * @param callable $get_meta  Takes a meta key, returns its value.
	 * @return array<string, callable>
	 */
	protected function dynamic_macros( $subject, callable $get_terms, callable $get_meta ) {
		$out = array();
		foreach ( array(
			'ct_desc_' => 'description',
			'ct_'      => 'name',
		) as $prefix => $field ) {
			if ( preg_match_all( "/(%%{$prefix}[a-zA-Z0-9!@#$^&*_+-=:;~]+%%)/", $subject, $m ) ) {
				foreach ( array_unique( $m[1] ) as $placeholder ) {
					$taxonomy            = str_replace( array( "%%{$prefix}", '%%' ), '', $placeholder );
					$out[ $placeholder ] = function () use ( $get_terms, $field, $taxonomy ) {
						if ( ! get_taxonomy( $taxonomy ) ) {
							return '';
						}
						$terms = $get_terms( $taxonomy );
						if ( ! $terms ) {
							return '';
						}
						return wp_strip_all_tags( (string) get_term_field( $field, array_shift( $terms ), $taxonomy ) );
					};
				}
				$subject = str_replace( array_keys( $out ), '', $subject );
			}
		}
		if ( preg_match_all( '/(%%cf_[a-zA-Z0-9!@#$^&*_+-=:;~]+%%)/', $subject, $m ) ) {
			foreach ( array_unique( $m[1] ) as $placeholder ) {
				$key                 = str_replace( array( '%%cf_', '%%' ), '', $placeholder );
				$out[ $placeholder ] = function () use ( $get_meta, $key ) {
					$value = $get_meta( $key );
					return $value && is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '';
				};
			}
		}
		return $out;
	}
}
