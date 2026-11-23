<?php
/**
 * Explicit transfer cancellation exception.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signals explicit cancellation at a durable work boundary.
 */
final class CSM_Cancelled_Error extends RuntimeException {}
