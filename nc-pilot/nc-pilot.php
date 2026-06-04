<?php
/**
 * Plugin Name:       NC-Pilot
 * Plugin URI:        https://github.com/nilesh-0608/wp-nc-pilot
 * Description:       Manage your WordPress site by talking to Claude. One-click setup, no terminal, no config files. A self-contained remote MCP server — Claude connects directly to your site over HTTPS.
 * Version:           2.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            NC-Pilot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nc-pilot
 *
 * @package WP_NC_Pilot
 */

// No direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NCPILOT_VERSION', '2.1.0' );
define( 'NCPILOT_PLUGIN_FILE', __FILE__ );
define( 'NCPILOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NCPILOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// REST namespace used by everything this plugin exposes.
define( 'NCPILOT_REST_NS', 'ncpilot/v1' );

// Minutes within which a connector "ping" counts as "still connected".
define( 'NCPILOT_CONNECTED_WINDOW', 10 * MINUTE_IN_SECONDS );

/**
 * Load plugin classes.
 *
 * Plain require_once — no autoloader, no Composer, so the zip installs with
 * zero build tooling (a hard project constraint).
 */
require_once NCPILOT_PLUGIN_DIR . 'includes/class-security.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-status.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-plugins.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-files.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-content.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-media.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-rest-mcp.php';
require_once NCPILOT_PLUGIN_DIR . 'includes/class-settings-page.php';

/**
 * Boot the plugin once WordPress is ready.
 */
function ncpilot_bootstrap() {
	// REST endpoints.
	( new NCPilot_REST_Status() )->register_hooks();
	( new NCPilot_REST_Plugins() )->register_hooks();
	( new NCPilot_REST_Files() )->register_hooks();
	( new NCPilot_REST_Content() )->register_hooks();
	( new NCPilot_REST_Media() )->register_hooks();
	( new NCPilot_REST_MCP() )->register_hooks();

	// Admin UI (only loaded in wp-admin).
	if ( is_admin() ) {
		( new NCPilot_Settings_Page() )->register_hooks();
	}
}
add_action( 'plugins_loaded', 'ncpilot_bootstrap' );

/**
 * Activation: set sane defaults. All risky capabilities OFF by default.
 */
function ncpilot_activate() {
	NCPilot_Security::set_default_options();
}
register_activation_hook( __FILE__, 'ncpilot_activate' );
