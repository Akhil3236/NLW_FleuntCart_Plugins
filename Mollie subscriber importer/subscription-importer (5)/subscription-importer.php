<?php
/**
 * Plugin Name: Subscription Importer
 * Description: Safe import of Pronamic Pay subscription export JSON to FluentCart + Mollie. WP-CLI only.
 * Version: 1.0.0
 * Author: Subscription Migration
 * License: GPL-2.0-or-later
 * Text Domain: subscription-importer
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package SubscriptionImporter
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only load when WP-CLI is available.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

define( 'SUBSCRIPTION_IMPORTER_VERSION', '1.0.0' );
define( 'SUBSCRIPTION_IMPORTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUBSCRIPTION_IMPORTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default export file: wp-content/subscriptions-export.json (optioneel overschrijven met --file).
 */
if ( ! defined( 'SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE' ) ) {
	define( 'SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE', ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( dirname( plugin_dir_path( __FILE__ ) ) ) ) . '/subscriptions-export.json' );
}

require_once SUBSCRIPTION_IMPORTER_PLUGIN_DIR . 'src/Support/MollieTestApiKey.php';
require_once SUBSCRIPTION_IMPORTER_PLUGIN_DIR . 'src/Support/MollieHttpOptions.php';

// PSR-4 style autoload for SubscriptionImporter namespace.
spl_autoload_register(
	function ( $class ) {
		$prefix = 'SubscriptionImporter\\';
		$base_dir = SUBSCRIPTION_IMPORTER_PLUGIN_DIR . 'src/';
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}
		$relative_class = substr( $class, $len );
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Ensure database tables exist (run on plugin load when CLI is used).
add_action( 'init', array( 'SubscriptionImporter\\Storage\\DatabaseMigrator', 'maybe_install' ), 1 );

// Register WP-CLI commands.
add_action( 'cli_init', function () {
	WP_CLI::add_command(
		'subs import',
		array( 'SubscriptionImporter\\Cli\\ImportCommand', 'invoke' ),
		array(
			'shortdesc' => 'Import subscriptions from export JSON to FluentCart and Mollie',
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'file',
					'optional'    => false,
					'description' => 'Path to subscriptions export JSON file',
				),
				array(
					'type'        => 'flag',
					'name'        => 'dry-run',
					'optional'    => true,
					'description' => 'Simulate import without creating anything',
				),
				array(
					'type'        => 'assoc',
					'name'        => 'limit',
					'optional'    => true,
					'description' => 'Limit number of subscriptions to process',
				),
				array(
					'type'        => 'assoc',
					'name'        => 'offset',
					'optional'    => true,
					'description' => 'Offset for batch processing',
				),
				array(
					'type'        => 'flag',
					'name'        => 'resume',
					'optional'    => true,
					'description' => 'Skip already imported subscriptions and continue',
				),
			),
		)
	);

	WP_CLI::add_command(
		'subs validate',
		array( 'SubscriptionImporter\\Cli\\ValidateCommand', 'invoke' ),
		array(
			'shortdesc' => 'Validate export file (email, mandate, interval, amount, next payment)',
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'file',
					'optional'    => true,
					'description' => 'Path to export JSON (default: wp-content/subscriptions-export.json)',
				),
			),
		)
	);

	WP_CLI::add_command(
		'subs mollie-config',
		array( 'SubscriptionImporter\\Cli\\MollieConfigCommand', 'invoke' ),
		array(
			'shortdesc' => 'Check whether Mollie test API key is configured for import/simulate',
		)
	);

	WP_CLI::add_command(
		'subs cleanup-mollie-test',
		array( 'SubscriptionImporter\\Cli\\CleanupMollieTestCommand', 'invoke' ),
		array(
			'shortdesc' => 'Delete Mollie test customers from simulate-mollie and clear mapping table',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'dry-run',
					'optional'    => true,
					'description' => 'Show how many customers would be deleted without deleting',
				),
				array(
					'type'        => 'flag',
					'name'        => 'truncate-only',
					'optional'    => true,
					'description' => 'Only clear wp_mollie_test_mapping (instant). Does not call Mollie API.',
				),
			),
		)
	);

	WP_CLI::add_command(
		'subs simulate-mollie',
		array( 'SubscriptionImporter\\Cli\\TestSimulationCommand', 'invoke' ),
		array(
			'shortdesc' => 'Create Mollie test environment clone (test customers and mandates)',
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'file',
					'optional'    => true,
					'description' => 'Path to export JSON (default: wp-content/subscriptions-export.json)',
				),
			),
		)
	);
} );
