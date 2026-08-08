<?php
/**
 * Settings accessor.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Settings {

	const OPTION_KEY = 'fcwr_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Get all settings with defaults applied.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$defaults = array(
				'watch_urls'         => '',
				'log_successes'      => 0,
				'max_retries'        => 10,
				'retry_window_sec'   => 60,
				'retries_per_window' => 5,
				'auto_purge_days'    => 30,
			);

			$stored      = get_option( self::OPTION_KEY, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
		}

		return self::$cache;
	}

	/**
	 * Update settings.
	 *
	 * @param array $values
	 */
	public static function update( array $values ) {
		$current = self::get();
		$merged  = array_merge( $current, $values );
		update_option( self::OPTION_KEY, $merged );
		self::$cache = $merged;
	}
}
