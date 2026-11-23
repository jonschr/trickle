<?php
/**
 * Nonblocking peer source-lock exception.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-csm-network-error.php';

/**
 * Defers a request while a peer is busy.
 */
final class CSM_Busy_Error extends CSM_Network_Error {
	/**
	 * Minimum delay before another attempt, in seconds.
	 *
	 * @var int
	 */
	public int $retry_after = 10;
}
