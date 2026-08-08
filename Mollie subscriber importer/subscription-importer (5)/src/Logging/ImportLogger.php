<?php
/**
 * Logs import actions to wp-content/uploads/subscription-migration.log
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Logging;

/**
 * ImportLogger
 */
class ImportLogger {

	const LOG_FILE = 'subscription-migration.log';

	/** @var string */
	private $path;

	/** @var bool */
	private $dry_run;

	/**
	 * @param bool $dry_run Whether this is a dry run (no changes).
	 */
	public function __construct( $dry_run = false ) {
		$upload_dir = wp_upload_dir();
		$this->path  = $upload_dir['basedir'] . '/' . self::LOG_FILE;
		$this->dry_run = $dry_run;
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'INFO', $message, $context );
	}

	/**
	 * Log warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'WARNING', $message, $context );
	}

	/**
	 * Log error.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'ERROR', $message, $context );
	}

	/**
	 * Write one line to log file.
	 *
	 * @param string $level   Level (INFO, WARNING, ERROR).
	 * @param string $message Message.
	 * @param array  $context Optional extra data (appended as JSON if not empty).
	 */
	private function log( $level, $message, array $context = array() ) {
		$prefix = $this->dry_run ? '[DRY-RUN] ' : '';
		$line   = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [' . $level . '] ' . $prefix . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}
		$line .= "\n";

		// Ensure directory exists and is writable.
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_writable( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $this->path, $line, FILE_APPEND | LOCK_EX );
		}
	}
}
