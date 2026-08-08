<?php
/**
 * Plugin Name:       FluentCart Webhook Retry
 * Plugin URI:        https://nextlevelweb.com/
 * Description:       Captures failed outgoing webhooks from FluentCart (and other plugins) and adds a one-click Retry button to the FluentCart order page and a dedicated admin screen.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NextLevelWeb (Akhil Tuluri)
 * Author URI:        https://nextlevelweb.com/
 * Text Domain:       fluentcart-webhook-retry
 * License:           GPL-2.0-or-later
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'FCWR_VERSION', '1.0.0' );
define( 'FCWR_PLUGIN_FILE', __FILE__ );
define( 'FCWR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCWR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FCWR_TABLE', 'fcwr_webhook_logs' );

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------

require_once FCWR_PLUGIN_DIR . 'includes/class-activator.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-webhook-logger.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-retry-service.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-settings.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-admin.php';
require_once FCWR_PLUGIN_DIR . 'includes/class-plugin.php';

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( 'FCWR_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FCWR_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', static function () {
	FCWR_Plugin::instance()->init();
} );
