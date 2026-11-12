<?php
/**
 * Plugin Name:       TGD Site Kit
 * Description:       This Gal's Design site kit: TGD Connect, an analytics tag, SEO meta, schema, redirects, a 404 log, breadcrumbs and IndexNow, each switched on per site.
 * Version:           0.5.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            This Gals Design Studio
 * Author URI:        https://thisgals.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tgd-site-kit
 *
 * @package TGD_Site_Kit
 */

namespace TGD_Site_Kit;

defined( 'ABSPATH' ) || exit;

/**
 * This file's own path, for building plugin-relative paths and URLs.
 */
const PLUGIN_FILE = __FILE__;

/**
 * Plugin version. Keep in step with the Version header above; this is the copy
 * PHP can read, and it is what the status route reports.
 */
const VERSION = '0.5.1';

/**
 * Option holding the list of module ids switched on for this site.
 *
 * Every module is off until it appears here. Activating the plugin writes
 * nothing, so a fresh install changes nothing on the front end.
 */
const MODULES_OPTION = 'tgdsk_modules';

/**
 * Capability for everything this plugin exposes: the settings screen, every
 * write, and the TGD Connect status route. Owner only, deliberately; there is no
 * client-facing surface here.
 */
const OWNER_CAP = 'manage_options';

require_once __DIR__ . '/includes/registry.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/upgrade.php';
require_once __DIR__ . '/includes/woocommerce.php';
require_once __DIR__ . '/includes/breadcrumbs/trail.php';
require_once __DIR__ . '/includes/modules/connector.php';
require_once __DIR__ . '/includes/modules/analytics.php';
require_once __DIR__ . '/includes/modules/seo.php';
require_once __DIR__ . '/includes/modules/schema.php';
require_once __DIR__ . '/includes/modules/redirects.php';
require_once __DIR__ . '/includes/modules/not-found.php';
require_once __DIR__ . '/includes/modules/breadcrumbs.php';
require_once __DIR__ . '/includes/modules/indexnow.php';

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot_modules', 20 );
