<?php
/**
 * Plugin Name:       FluentCart Weight Shipping (NL)
 * Description:       Verzendtarief op basis van totaalgewicht (Etch-bridge gewicht / FluentCart meta). Overschrijft FluentCart zone-tarieven voor fysieke orders.
 * Version:           1.0.0
 * Author:            Site
 * License:           GPL-2.0-or-later
 * Text Domain:       fluent-cart-weight-shipping
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package FluentCartWeightShipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCWS_VERSION', '1.0.0' );
define( 'FCWS_PLUGIN_FILE', __FILE__ );
define( 'FCWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once FCWS_PLUGIN_DIR . 'includes/class-fcws-shipping.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( '\FluentCart\App\Helpers\AddressHelper' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'FluentCart Weight Shipping vereist de plugin FluentCart.', 'fluent-cart-weight-shipping' );
					echo '</p></div>';
				}
			);
			return;
		}
		\FCWS\Shipping::init();
	},
	20
);
