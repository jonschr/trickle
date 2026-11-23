<?php
/**
 * Peer transport failure and retry metadata.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Carries transport retry timing and exhaustion state.
 */
class CSM_Network_Error extends RuntimeException {
	/**
	 * Minimum delay before another attempt, in seconds.
	 *
	 * @var int
	 */
	public int $retry_after = 0;
	/**
	 * Whether automatic retries must stop.
	 *
	 * @var bool
	 */
	public bool $exhausted = false;
}
