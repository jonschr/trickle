<?php
/**
 * Stable entry point for already-installed recovery loaders.
 *
 * @package Trickle
 */

defined( 'ABSPATH' ) || exit;
// MU loaders run before plugin boot can refresh their stored include paths.
require_once __DIR__ . '/class-csm-access.php';
