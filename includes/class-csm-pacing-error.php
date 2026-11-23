<?php
/**
 * Shared host request scheduling exception.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-network-error.php';

/**
 * Defers a request without bypassing the shared host schedule.
 */
final class CSM_Pacing_Error extends CSM_Network_Error {
	/**
	 * Minimum delay before another attempt, in seconds.
	 *
	 * @var int
	 */
	public int $retry_after = 5;
	/**
	 * Whether automatic retries must stop.
	 *
	 * @var bool
	 */
	public bool $exhausted = true;
}
