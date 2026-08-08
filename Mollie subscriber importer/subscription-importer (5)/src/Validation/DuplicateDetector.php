<?php
/**
 * Detects if a subscription was already imported (duplicate protection).
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Validation;

use SubscriptionImporter\Storage\ImportStateRepository;

/**
 * DuplicateDetector
 */
class DuplicateDetector {

	/** @var ImportStateRepository */
	private $repository;

	public function __construct( ImportStateRepository $repository = null ) {
		$this->repository = $repository ?: new ImportStateRepository();
	}

	/**
	 * Check if record is already in migration map (duplicate).
	 *
	 * @param int $pronamic_subscription_id Pronamic subscription ID from export.
	 * @return bool True if duplicate (should skip).
	 */
	public function is_duplicate( $pronamic_subscription_id ) {
		return $this->repository->is_imported( $pronamic_subscription_id );
	}
}
