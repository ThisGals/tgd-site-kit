<?php
/**
 * Updates from the plugin's public GitHub releases.
 *
 * WordPress asks each plugin's own updater nothing; it asks wordpress.org. A
 * plugin that is not on wordpress.org adds itself to the answer, which is what
 * this file does, so the kit shows up in Dashboard → Updates, in the plugin
 * list, and in WordPress's own auto-update toggle like any other plugin.
 *
 * - **One unauthenticated request, cached for 12 hours.** The latest release
 *   comes from the GitHub API with no token, on any site, ever. A failure is
 *   cached for an hour, so a site that cannot reach GitHub, or that GitHub is
 *   rate limiting, asks again later rather than on every admin page.
 * - **Fails quietly.** No answer, a bad answer, a draft, a prerelease, or a
 *   release without both assets means no update is offered. No notice, no
 *   error, and the plugin carries on as installed.
 * - **Every download is checked against the release's SHA-256 file** before
 *   WordPress unpacks it, and a mismatch is refused with a clear error. The
 *   repository is public, so this is what stops a swapped asset installing.
 *   It does not defend against someone who can publish a release (they can
 *   publish a matching checksum too); it defends against everything short of
 *   that, including a tampered asset or a corrupted download.
 * - **Never updates a development checkout.** A copy with a `.git` folder is
 *   somebody's working tree, and WordPress's upgrader deletes the old plugin
 *   folder before installing the new one. Where that folder is a link to a
 *   repository, it would delete the repository.
 *
 * Releases are made with tools/build-release.php; see README.md, Releasing.
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit\Updater;

use const TGD_Site_Kit\PLUGIN_FILE;
use const TGD_Site_Kit\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Where the latest release is read from. Filterable through
 * `tgdsk_update_feed`, which is how it is tested without GitHub.
 */
const FEED_URL = 'https://api.github.com/repos/thisgals/tgd-site-kit/releases/latest';

const SLUG         = 'tgd-site-kit';
const ZIP_ASSET    = 'tgd-site-kit.zip';
const SHA_ASSET    = 'tgd-site-kit.zip.sha256';
const CACHE_KEY    = 'tgdsk_update_release';
const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
const FAILURE_TTL  = HOUR_IN_SECONDS;
const REQUEST_ARGS = array(
	'timeout' => 10,
	'headers' => array( 'Accept' => 'application/vnd.github+json' ),
);

add_filter( 'pre_set_site_transient_update_plugins', __NAMESPACE__ . '\\add_update' );
add_filter( 'plugins_api', __NAMESPACE__ . '\\plugin_information', 20, 3 );
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\verified_download', 999, 4 );

/**
 * The plugin's basename, `tgd-site-kit/tgd-site-kit.php` on a normal install.
 *
 * @return string
 */
function kit_basename() {
	return plugin_basename( PLUGIN_FILE );
}

/**
 * Whether this copy is a development checkout that must not be updated.
 *
 * @return bool
 */
function is_checkout() {
	return (bool) apply_filters( 'tgdsk_is_checkout', is_dir( dirname( PLUGIN_FILE ) . '/.git' ) );
}

/**
 * The latest usable release, or null.
 *
 * @return array|null {version, zip, sha, url, notes, published} or null.
 */
function latest_release() {
	$cached = get_site_transient( CACHE_KEY );
	if ( is_array( $cached ) ) {
		return $cached['release'];
	}
	$release = fetch_release();
	set_site_transient( CACHE_KEY, array( 'release' => $release ), $release ? CACHE_TTL : FAILURE_TTL );
	return $release;
}

/**
 * Ask the feed for the latest release and reduce it to what is needed.
 *
 * @return array|null
 */
function fetch_release() {
	$feed     = (string) apply_filters( 'tgdsk_update_feed', FEED_URL );
	$response = wp_remote_get( $feed, REQUEST_ARGS );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	return parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
}

/**
 * Reduce a GitHub release object to the fields the updater uses.
 *
 * @param mixed $data Decoded release JSON.
 * @return array|null Null for anything not a published, final release with both assets.
 */
function parse_release( $data ) {
	if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) || empty( $data['tag_name'] ) ) {
		return null;
	}
	$version = ltrim( (string) $data['tag_name'], 'vV' );
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
		return null;
	}
	$assets = array();
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		if ( isset( $asset['name'], $asset['browser_download_url'] ) ) {
			$assets[ $asset['name'] ] = esc_url_raw( (string) $asset['browser_download_url'] );
		}
	}
	if ( empty( $assets[ ZIP_ASSET ] ) || empty( $assets[ SHA_ASSET ] ) ) {
		return null;
	}
	return array(
		'version'   => $version,
		'zip'       => $assets[ ZIP_ASSET ],
		'sha'       => $assets[ SHA_ASSET ],
		'url'       => esc_url_raw( (string) ( $data['html_url'] ?? '' ) ),
		'notes'     => (string) ( $data['body'] ?? '' ),
		'published' => (string) ( $data['published_at'] ?? '' ),
	);
}

/**
 * The update entry WordPress expects for one plugin.
 *
 * @param array $release Latest release.
 * @return object
 */
function update_entry( array $release ) {
	return (object) array(
		'id'           => 'github.com/thisgals/tgd-site-kit',
		'slug'         => SLUG,
		'plugin'       => kit_basename(),
		'new_version'  => $release['version'],
		'url'          => $release['url'],
		'package'      => $release['zip'],
		'requires_php' => '8.1',
		'icons'        => array(),
		'banners'      => array(),
	);
}

/**
 * Offer the latest release when it is newer than this copy.
 *
 * A plugin that is current is listed under `no_update`, which is what makes
 * WordPress show the auto-update toggle for it at all.
 *
 * @param mixed $transient The update_plugins transient being saved.
 * @return mixed
 */
function add_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) || is_checkout() ) {
		return $transient;
	}
	$release = latest_release();
	if ( ! $release ) {
		return $transient;
	}
	$entry = update_entry( $release );
	if ( version_compare( $release['version'], VERSION, '>' ) ) {
		$transient->response[ kit_basename() ] = $entry;
		unset( $transient->no_update[ kit_basename() ] );
	} else {
		$entry->new_version                     = VERSION;
		$entry->package                         = '';
		$transient->no_update[ kit_basename() ] = $entry;
		unset( $transient->response[ kit_basename() ] );
	}
	return $transient;
}

/**
 * The "View details" box for this plugin.
 *
 * @param mixed  $result Result so far.
 * @param string $action plugins_api action.
 * @param object $args   Request arguments.
 * @return mixed
 */
function plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! isset( $args->slug ) || SLUG !== $args->slug ) {
		return $result;
	}
	$release = latest_release();
	if ( ! $release ) {
		return $result;
	}
	return (object) array(
		'name'          => 'TGD Site Kit',
		'slug'          => SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="https://thisgals.com">This Gals Design Studio</a>',
		'homepage'      => $release['url'],
		'requires'      => '6.5',
		'requires_php'  => '8.1',
		'last_updated'  => $release['published'],
		'download_link' => $release['zip'],
		'sections'      => array(
			'description' => esc_html__( 'Modules switched on per site: a status route, the GA4 tag, SEO meta and more.', 'tgd-site-kit' ),
			'changelog'   => wpautop( esc_html( $release['notes'] ) ),
		),
	);
}

/**
 * Download this plugin's package, and refuse it unless it matches its SHA-256.
 *
 * Runs for an update of this plugin, or for the release's own package URL;
 * every other download, a manual zip upload included, is left alone. On a
 * match it returns the file, which WordPress then unpacks exactly as it would
 * have its own download.
 *
 * Hooked late (999), so a file some other filter already fetched is checked
 * too rather than waved through. That is not hypothetical: WP-CLI caches
 * plugin zips by slug and version, and in testing it went on serving a
 * tampered zip from its cache after the fixture had been put right. This
 * check refused it every time.
 *
 * An update of this plugin when the release cannot be read is refused rather
 * than installed unchecked.
 *
 * @param mixed        $reply      False to let WordPress download, or a result.
 * @param string       $package    Package URL.
 * @param \WP_Upgrader $upgrader   Upgrader.
 * @param array        $hook_extra Context; carries `plugin` on a plugin update.
 * @return mixed File path, WP_Error, or the incoming reply.
 */
function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- filter signature.
	$release = latest_release();
	$ours    = ( $hook_extra['plugin'] ?? '' ) === kit_basename() || ( $release && $package === $release['zip'] );
	if ( ! $ours || is_wp_error( $reply ) ) {
		return $reply;
	}
	if ( ! $release || $package !== $release['zip'] ) {
		return new \WP_Error( 'tgdsk_package', __( 'TGD Site Kit refused this update: the package is not the one its latest release lists, or the release could not be read.', 'tgd-site-kit' ) );
	}

	$expected = expected_hash( $release['sha'] );
	if ( '' === $expected ) {
		return new \WP_Error( 'tgdsk_checksum_missing', __( 'TGD Site Kit refused this update: its SHA-256 file could not be read, so the download cannot be checked.', 'tgd-site-kit' ) );
	}

	if ( is_string( $reply ) && is_file( $reply ) ) {
		$file = $reply;
	} else {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
	}
	if ( ! hash_equals( $expected, (string) hash_file( 'sha256', $file ) ) ) {
		wp_delete_file( $file );
		return new \WP_Error( 'tgdsk_checksum_mismatch', __( 'TGD Site Kit refused this update: the downloaded zip does not match the SHA-256 published with the release. Nothing was installed.', 'tgd-site-kit' ) );
	}
	return $file;
}

/**
 * The 64-character hash at the start of a release's SHA-256 file.
 *
 * Accepts `sha256sum` output (`<hash>  tgd-site-kit.zip`) and a bare hash.
 *
 * @param string $url SHA-256 file URL.
 * @return string Lowercase hash, or '' when it cannot be read.
 */
function expected_hash( $url ) {
	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	return preg_match( '/^\s*([a-f0-9]{64})\b/i', wp_remote_retrieve_body( $response ), $m ) ? strtolower( $m[1] ) : '';
}
