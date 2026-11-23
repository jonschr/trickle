<?php
/**
 * Bounded-work continuation exception.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;

/**
 * Yields bounded work so another request can resume its checkpoint.
 */
final class CSM_Yield extends RuntimeException {}
